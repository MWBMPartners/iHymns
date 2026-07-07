// SessionController.swift
// IHAuth
//
// ELI5: The one place that knows "is the user signed in right now, and with
// what token?" — every screen that cares (e.g. "show the sign-in button vs.
// the profile menu") watches this instead of checking the Keychain itself.
//
// DETAILED: Implements the `SessionController actor (state as AsyncStream)`
// piece named in strategy §1.2's IHAuth summary. An `AsyncStream` (rather
// than, say, `Combine`'s `CurrentValueSubject`) is the deliberate choice
// repo-wide for Apple-native state broadcasting — strategy §1.3: "`@Observable`
// MainActor VMs, no Combine" — `AsyncStream` composes naturally with
// `async`/`await` and Swift 6 structured concurrency without pulling in a
// second reactive framework alongside `async`/`await` itself.
//
// #1398 UPDATE — Phase 0 stopped short of a real network round trip: this
// actor wired only the LOCAL half of sign-in/out against the `TokenStoring`
// abstraction, with a doc comment explicitly deferring the `auth_login`/
// revocation network calls to "a Phase-1 caller." That caller is THIS file,
// now: `SessionController` takes an `APIClient` dependency (IHAuth already
// declares this target dependency in `Package.swift`) and owns the FULL
// sign-in/sign-out flow end-to-end, including the load-bearing revocation
// ORDER strategy §3.2 specifies: "sign-out = server-revoke THEN delete
// synchronizable item — else iCloud resurrects a revoked token." If the
// local Keychain item were deleted first and the revoke call then failed or
// raced, an iCloud-Keychain-synced copy on another of the user's devices
// could still authenticate with the very token this call meant to kill —
// revoking server-side FIRST closes that window regardless of what happens
// to the local copy afterwards.
import Foundation
import IHAPI

/// The current authentication state of the app.
///
/// ELI5: Either "signed out" or "signed in, and here's the token."
public enum SessionState: Sendable, Equatable {
    case signedOut
    case signedIn(token: String)
}

/// Owns the single source of truth for sign-in state and broadcasts every
/// change to any number of observers.
///
/// ELI5: Ask it "am I signed in?", tell it "sign in with this username and
/// password" or "sign out," and it keeps every part of the app that's
/// watching in sync.
public actor SessionController {
    private let tokenStore: any TokenStoring

    /// The shared network client — used to call `auth_login`/`auth_logout`
    /// AND kept in sync via `updateBearerToken(_:)` so every OTHER call this
    /// same client makes (catalogue reads, future authenticated writes)
    /// carries the right token the moment this actor knows about one.
    private let apiClient: APIClient

    /// The live authentication state. Mutated only by this actor's own
    /// methods below, so every read is guaranteed to be the true current
    /// state — no torn reads across concurrent callers (Swift 6 strict
    /// concurrency, strategy §1.3).
    public private(set) var state: SessionState = .signedOut

    /// The continuation side of `stateUpdates`, used internally to publish
    /// every transition.
    private let continuation: AsyncStream<SessionState>.Continuation

    /// A live feed of every state change, starting from whatever the state
    /// was at subscription time.
    ///
    /// ELI5: A SwiftUI view can `for await newState in stateUpdates { ... }`
    /// and always see the latest sign-in/sign-out as it happens.
    ///
    /// DETAILED: Built via `AsyncStream.makeStream(of:)` (Swift 5.9+) rather
    /// than the older continuation-capturing-`var` pattern, which needs a
    /// pre-initialization local variable trick — `makeStream` returns the
    /// stream/continuation pair directly, so both stored properties can be
    /// set in one straight-line `init` with no such indirection. See
    /// https://developer.apple.com/documentation/swift/asyncstream/makestream(of:bufferingpolicy:).
    ///
    /// Declared `nonisolated` so callers outside the actor can start
    /// iterating (`for await ... in controller.stateUpdates`) without an
    /// `await` just to read the stream reference itself — safe because the
    /// stream/continuation pair is fixed at `init` and never reassigned;
    /// only the *values flowing through* it are actor-synchronized (via
    /// `setState`, which does run isolated).
    nonisolated public let stateUpdates: AsyncStream<SessionState>

    public init(tokenStore: any TokenStoring, apiClient: APIClient) {
        self.tokenStore = tokenStore
        self.apiClient = apiClient
        let (stream, continuation) = AsyncStream.makeStream(of: SessionState.self)
        self.stateUpdates = stream
        self.continuation = continuation
    }

    /// Re-hydrates `state` from whatever token (if any) is already in
    /// storage — called once at app launch, before the first screen
    /// renders, so a returning signed-in user never sees a sign-in prompt
    /// flash.
    ///
    /// ELI5: "Check the safe — were we already signed in?"
    public func restoreFromStorage() async throws {
        let token = try await tokenStore.load()
        if let token {
            await apiClient.updateBearerToken(token)
        }
        setState(token.map(SessionState.signedIn) ?? .signedOut)
    }

    /// Signs in with a username/password pair: exchanges them for a bearer
    /// token via `?action=auth_login`, then persists/publishes it exactly
    /// like `signIn(token:)` below.
    ///
    /// ELI5: "Here's my username and password — check them with the server
    /// and log me in."
    ///
    /// - Throws: Whatever `APIClient.authLogin(username:password:)` throws
    ///   (`APIError.unauthorized` for wrong credentials, `.accountLocked`
    ///   for a locked-out account, `.offline`/`.maintenance` for a
    ///   transient failure, ...) — this method does NOT swallow or
    ///   reinterpret any of it; the caller (a future sign-in screen) maps
    ///   these to user-facing copy the same way `AppRootViewModel` maps
    ///   catalogue-read failures (#1399).
    public func signIn(username: String, password: String) async throws {
        let session = try await apiClient.authLogin(username: username, password: password)
        try await signIn(token: session.token)
    }

    /// Persists `token` and transitions to `.signedIn`.
    ///
    /// ELI5: "We just got a login token — remember it and tell everyone
    /// we're signed in now."
    ///
    /// DETAILED: The lower-level entry point `signIn(username:password:)`
    /// above delegates to — kept public in its own right because a future
    /// token-issuing flow that ISN'T `auth_login` (e.g. strategy §1.6's
    /// planned `auth_apple`/Sign in with Apple, which "mints a standard
    /// 30-day tblApiTokens token — response identical to auth_login") can
    /// call straight into this once it already holds a token, without
    /// re-deriving one from a username/password round trip it never had.
    public func signIn(token: String) async throws {
        try await tokenStore.save(token)
        await apiClient.updateBearerToken(token)
        setState(.signedIn(token: token))
    }

    /// Clears the stored token and transitions to `.signedOut` — but ONLY
    /// after the server has revoked it first.
    ///
    /// ELI5: "Tell the server to kill this login pass; once it confirms,
    /// forget our own copy and tell everyone we're signed out."
    ///
    /// DETAILED: **Ordering is load-bearing** (strategy §3.2): this calls
    /// `apiClient.authLogout()` and awaits it BEFORE ever touching
    /// `tokenStore`/`apiClient.updateBearerToken(nil)`. A `.unauthorized`
    /// failure from that call means the token was already invalid
    /// server-side (expired, or already revoked from another device) — that
    /// counts as "nothing left to revoke," so sign-out still completes
    /// locally. Any OTHER failure (`.offline`, `.maintenance`,
    /// `.rateLimited`, `.server`, `.decoding`) is NOT swallowed: it
    /// propagates out of this method, and — critically — the local token is
    /// left untouched. Deleting it anyway would defeat the entire point of
    /// "revoke first": the token would still be valid server-side (and thus
    /// on any iCloud-Keychain-synced copy on another device) while THIS
    /// device's UI claims to be signed out.
    ///
    /// A no-op (never calls the network at all) when already `.signedOut` —
    /// both because there is nothing to revoke or delete, and because
    /// calling `authLogout()` with no bearer token set would trip
    /// `APIClient.makeURLRequest`'s "an authenticated endpoint needs a
    /// token" precondition, a programmer-error guard that a normal,
    /// idempotent `signOut()` call should never be able to reach.
    public func signOut() async throws {
        guard case .signedIn = state else { return }

        do {
            try await apiClient.authLogout()
        } catch APIError.unauthorized {
            // Already invalid/revoked server-side — treat as "the server
            // half is already done" and fall through to clear our local
            // copy too, rather than stranding the user signed-in-locally
            // with a token that can never work again.
        }

        try await tokenStore.delete()
        await apiClient.updateBearerToken(nil)
        setState(.signedOut)
    }

    /// Updates `state` and publishes it to every `stateUpdates` subscriber.
    private func setState(_ newState: SessionState) {
        state = newState
        continuation.yield(newState)
    }
}

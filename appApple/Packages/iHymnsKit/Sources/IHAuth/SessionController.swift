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
// This Phase-0 skeleton wires the plumbing (state transitions + broadcast)
// against the `TokenStoring` abstraction from this same module; the actual
// `auth_login`/SIWA network calls land in Phase 1 (strategy §3.4: "IHAuth —
// Keychain vault + auth_login + revocation ordering").
import Foundation

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
/// ELI5: Ask it "am I signed in?", tell it "sign in with this token" or
/// "sign out," and it keeps every part of the app that's watching in sync.
public actor SessionController {
    private let tokenStore: any TokenStoring

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

    public init(tokenStore: any TokenStoring) {
        self.tokenStore = tokenStore
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
        setState(token.map(SessionState.signedIn) ?? .signedOut)
    }

    /// Persists `token` and transitions to `.signedIn`.
    ///
    /// ELI5: "We just got a login token back from the server — remember it
    /// and tell everyone we're signed in now."
    public func signIn(token: String) async throws {
        try await tokenStore.save(token)
        setState(.signedIn(token: token))
    }

    /// Clears the stored token and transitions to `.signedOut`.
    ///
    /// ELI5: "Forget the token and tell everyone we're signed out."
    ///
    /// DETAILED: **Caller contract** (strategy §3.2): the server-side token
    /// revocation call MUST have already succeeded before this method is
    /// invoked. If the local Keychain item is deleted first but the
    /// revocation call then fails or races, an iCloud-Keychain-synced copy
    /// on another of the user's devices can "resurrect" the very token this
    /// call meant to kill. `SessionController` intentionally does NOT make
    /// the revocation network call itself (that's IHAPI's job, composed by
    /// a Phase-1 caller) — it only owns the *local* half of sign-out, kept
    /// separate so the ordering above is enforced by whoever composes the
    /// two, not hidden inside a single do-everything method.
    public func signOut() async throws {
        try await tokenStore.delete()
        setState(.signedOut)
    }

    /// Updates `state` and publishes it to every `stateUpdates` subscriber.
    private func setState(_ newState: SessionState) {
        state = newState
        continuation.yield(newState)
    }
}

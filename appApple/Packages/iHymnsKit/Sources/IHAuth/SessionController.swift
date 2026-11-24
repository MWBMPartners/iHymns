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
//
// #1505 UPDATE (`.claude/live-observability-strategy.md` §2.2) — every
// signed-out↔signed-in TRANSITION (never a same-state re-publish — "log
// transitions, not states") writes one `IHLog.auth` `.notice`, via the
// single `setState(_:userId:)` chokepoint every public entry point below
// funnels through. `userId` is `.private`; the token/username are NEVER
// logged, at any level (strategy §4). `restoreFromStorage()`'s 401
// self-heal (an INVOLUNTARY sign-out) additionally logs an `.error`.
//
// PR 3b (I2c, #2180) UPDATE — one sign-in per device. Built with a
// `DeviceSignInSupport`, this controller gives each device its OWN sign-in:
// it can swap the older apps' shared sign-in for one (`auth_device_enrol`), use
// an automatic sign-in key from iCloud Keychain on a brand-new device
// (`auth_enrol_key_redeem`), remembers "signed out here" so a signed-out device
// never signs itself back in, and can sign out everywhere. That logic lives in
// `SessionController+DeviceSignIn.swift`; this file keeps the core. Built
// WITHOUT support (`deviceSignIn: nil`, the default), it behaves exactly as it
// did before: one token store, no swapping, no extra calls. Design:
// `.claude/analysis/per-device-sign-in-design.md`.
import Foundation
import IHAPI
import IHLog

/// The current authentication state of the app.
///
/// ELI5: Either "signed out" or "signed in, and here's the token."
public enum SessionState: Sendable, Equatable {
    case signedOut
    case signedIn(token: String)

    /// Convenience for the many call sites (native login/account UI task)
    /// that only care about the yes/no question, not the token itself —
    /// e.g. gating a favourite-toggle button or the toolbar's account icon.
    ///
    /// ELI5: "Am I signed in, yes or no?"
    public var isSignedIn: Bool {
        if case .signedIn = self { true } else { false }
    }
}

/// Owns the single source of truth for sign-in state and broadcasts every
/// change to any number of observers.
///
/// ELI5: Ask it "am I signed in?", tell it "sign in with this username and
/// password" or "sign out," and it keeps every part of the app that's
/// watching in sync.
public actor SessionController {
    /// Where the signed-in token lives. With device sign-in support this is the
    /// this-device-only store holding THIS device's own token; the older apps'
    /// shared token is never kept here (it is only read, by the reader in
    /// `deviceSignIn`). `internal` (not `private`) so the sibling extension
    /// file can use it.
    let tokenStore: any TokenStoring

    /// The shared network client — used to call `auth_login`/`auth_logout`
    /// AND kept in sync via `updateBearerToken(_:)` so every OTHER call this
    /// same client makes (catalogue reads, future authenticated writes)
    /// carries the right token the moment this actor knows about one.
    let apiClient: APIClient

    /// Everything needed to give this device its own sign-in, or `nil` for the
    /// plain older behaviour (see this file's PR 3b note).
    let deviceSignIn: DeviceSignInSupport?

    /// The site's "one sign-in per device" switch as the app last saw it
    /// (`app_status.deviceSignin`), handed in by `restoreFromStorage`. `false`
    /// until told, which keeps every swap and key call switched off.
    var deviceSignInEnabled = false

    /// True while the current token is the older apps' SHARED sign-in held in
    /// memory only (the "legacy mode" of a swap that could not happen). It is
    /// never copied into any store, and signing out of it makes no server call
    /// (revoking it would sign out every other device).
    var usingLegacyToken = false

    /// Guards `restoreFromStorage`: one swap at a time inside the actor.
    var isRestoring = false

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

    public init(tokenStore: any TokenStoring, apiClient: APIClient, deviceSignIn: DeviceSignInSupport? = nil) {
        self.tokenStore = tokenStore
        self.apiClient = apiClient
        self.deviceSignIn = deviceSignIn
        let (stream, continuation) = AsyncStream.makeStream(of: SessionState.self)
        self.stateUpdates = stream
        self.continuation = continuation
    }

    // `restoreFromStorage(deviceSignInEnabled:)` lives in
    // `SessionController+DeviceSignIn.swift` (PR 3b, I2c): it grew the
    // device-token / key / shared-sign-in decision tree, and keeping it beside
    // that code keeps this file under the repo's LOC budget.

    /// Signs in with a username/password pair: exchanges them for a bearer
    /// token via `?action=auth_login`, then persists/publishes it exactly
    /// like `signIn(token:)` below.
    ///
    /// ELI5: "Here's my username and password — check them with the server
    /// and log me in."
    ///
    /// - Parameter captchaToken: The solved CAPTCHA token, when the `login`
    ///   form is gated (#947/#340 native scaffold,
    ///   `.claude/captcha-native-and-outage-plan.md` §2.3-4) — `nil` by
    ///   default, the caller's (`LoginView`'s) responsibility to supply per
    ///   PLAN §2.5's "per-submit token acquisition" contract: tokens are
    ///   single-use (consumed at the provider's first `siteverify` call
    ///   regardless of whether THIS request goes on to succeed, e.g. a
    ///   correct token but a wrong password), so a RETRY must never reuse
    ///   the token from a previous attempt — that is the caller's job, not
    ///   this method's, which is a thin, stateless pass-through.
    /// - Throws: Whatever `APIClient.authLogin(username:password:captchaToken:)`
    ///   throws (`APIError.unauthorized` for wrong credentials,
    ///   `.accountLocked` for a locked-out account, `.captchaRequired` for a
    ///   missing/invalid/already-spent token on a gated install,
    ///   `.offline`/`.maintenance` for a transient failure, ...) — this
    ///   method does NOT swallow or reinterpret any of it; the caller (a
    ///   future sign-in screen) maps these to user-facing copy the same way
    ///   `AppRootViewModel` maps catalogue-read failures (#1399).
    public func signIn(username: String, password: String, captchaToken: String? = nil) async throws {
        // PR 3b (I2c): carry this install's details so the server gives this device its own row.
        let device = await currentDeviceDetails()
        let session = try await apiClient.authLogin(username: username, password: password, captchaToken: captchaToken, device: device)
        // Private `userId:` overload (#1505) — this call site already
        // knows who just signed in, for the `.notice` transition log.
        try await signIn(token: session.token, userId: session.user.id)
    }

    /// Signs in with an email + 6-digit code pair (the code emailed by a
    /// prior `?action=auth_email_login_request` call — `AppRootViewModel
    /// .requestEmailLoginCode(email:)`): exchanges them for a bearer token
    /// via `?action=auth_email_login_verify`, then persists/publishes it
    /// exactly like `signIn(username:password:)` above.
    ///
    /// ELI5: "Here's the code you emailed me — check it and log me in."
    ///
    /// - Throws: Whatever `APIClient.authEmailLoginVerify(email:code:)`
    ///   throws (`.unauthorized` for an invalid/expired code,
    ///   `.rateLimited` after 10 failed attempts in 15 minutes — the
    ///   brute-force guard `api.php`'s `case 'auth_email_login_verify'`
    ///   applies to the code-entry mode specifically, `.offline`/
    ///   `.maintenance` for a transient failure, ...) — same "don't swallow
    ///   or reinterpret" contract as `signIn(username:password:)`.
    public func signIn(email: String, code: String) async throws {
        let device = await currentDeviceDetails()
        let session = try await apiClient.authEmailLoginVerify(email: email, code: code, device: device)
        // See `signIn(username:password:)`'s matching comment above.
        try await signIn(token: session.token, userId: session.user.id)
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
        // No caller-known userId here (a bare token, no `AuthUser` — e.g. a
        // future SIWA flow, or today's tests). The `.notice` transition log
        // (#1505) still fires; it just omits the userId field.
        try await signIn(token: token, userId: nil)
    }

    /// The actual sign-in implementation `signIn(token:)` above, and the
    /// username/password + email/code flows earlier in this file, all
    /// funnel through — the ONE place a bearer token is persisted and
    /// `state` transitions to `.signedIn`. `userId`: passed through to
    /// `setState(_:userId:)`'s `.notice` log (`.private`, #1505); `nil`
    /// when unknown, never a reason to skip the log entirely.
    private func signIn(token: String, userId: Int?) async throws {
        try await tokenStore.save(token)
        await apiClient.updateBearerToken(token)
        usingLegacyToken = false
        setState(.signedIn(token: token), userId: userId)
        // PR 3b (I2c): a person signing in by hand clears "signed out here", and
        // (if automatic sign-in is on) this device offers its key to the others.
        await afterManualSignIn()
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

        // PR 3b (I2c): signing out of the older apps' SHARED sign-in makes NO
        // server call (revoking it would sign out every other Apple device)
        // and never touches the shared item: this device just forgets it.
        if usingLegacyToken {
            await markSignedOutHere()
            usingLegacyToken = false
            await apiClient.updateBearerToken(nil)
            setState(.signedOut)
            return
        }

        do {
            try await apiClient.authLogout()
        } catch APIError.unauthorized {
            // Already invalid/revoked server-side — treat as "the server half is
            // already done" and fall through to clear our local copy too.
        }

        // Order matters (PR 3b): remember "signed out here" BEFORE deleting the
        // token, so a crash in between can never leave a device that signs
        // itself back in on the next launch.
        await markSignedOutHere()
        try await tokenStore.delete()
        // The server cancels the person's automatic sign-in keys when a device's
        // own sign-in ends, so this device's copy of the key is dead: drop it.
        await deleteKeyCopy()
        await apiClient.updateBearerToken(nil)
        setState(.signedOut)
    }

    /// Permanently deletes the CURRENTLY signed-in account server-side
    /// (#1477, App Review §5.1.1(v)) — `reauth` carries the SECOND,
    /// independent proof-of-identity the server's `?action=account_delete`
    /// handler requires (a current password OR a fresh email login code;
    /// see `AccountDeleteReauth`'s own doc comment). Only on success does
    /// this clear the local token and publish `.signedOut` — the EXACT SAME
    /// local teardown `signOut()` performs, since a deleted account must
    /// leave no more of a trace on this device than a signed-out one does.
    ///
    /// ELI5: "Prove it's really you, then permanently delete your account —
    /// once the server confirms, forget everything we remembered locally."
    ///
    /// DETAILED: Unlike `signOut()`, a thrown error here is NEVER treated as
    /// "already done." `signOut()`'s `.unauthorized` special-case means "the
    /// TOKEN was already dead" — nothing left to revoke, so local cleanup
    /// still proceeds. Here, `.unauthorized` means the RE-AUTH CREDENTIAL
    /// (the password/email code in `reauth`) was wrong or expired — the
    /// account still exists, the bearer token is still perfectly valid, and
    /// clearing local state now would incorrectly sign the user out of an
    /// account they never actually deleted. So EVERY failure — `.unauthorized`
    /// (bad re-auth), `.rateLimited` (too many attempts), the 409 "transfer
    /// Global Admin first" guard, or a transient `.offline`/`.maintenance`/
    /// `.server`/`.decoding` — leaves the local token, and `state`, untouched,
    /// so the caller can safely present the same re-auth step again.
    ///
    /// Also unlike `signOut()`, this has no "already signed out, no-op"
    /// guard: calling this while `state` isn't `.signedIn` would otherwise
    /// trip `APIClient.makeURLRequest`'s "an authenticated endpoint needs a
    /// token" precondition (a programmer-error guard, same reasoning
    /// `signOut()`'s own doc comment gives) — but a silent no-op here would
    /// be actively misleading (unlike sign-out, "delete my account" has no
    /// sensible idempotent-no-op reading when there's no account to
    /// delete), so this throws `.unauthorized` instead, which the caller
    /// already knows how to surface.
    ///
    /// - Throws: Whatever `APIClient.accountDelete(reauth:)` throws (see
    ///   that method's own doc comment), or `.unauthorized` if called while
    ///   not signed in.
    public func deleteAccount(reauth: AccountDeleteReauth) async throws {
        guard case .signedIn = state else { throw APIError.unauthorized }

        try await apiClient.accountDelete(reauth: reauth)

        // PR 3b: nothing of this account may sign back in here (flag first, then
        // the token, then the key). The shared item is left alone: the server
        // already deleted that token with the account.
        await markSignedOutHere()
        if !usingLegacyToken { try await tokenStore.delete() }
        await deleteKeyCopy()
        usingLegacyToken = false
        await apiClient.updateBearerToken(nil)
        setState(.signedOut)
    }

    /// Updates `state`, publishes it to every `stateUpdates` subscriber, and
    /// — ONLY when signed-in-ness actually flips — writes one `IHLog.auth`
    /// `.notice` (#1505, "log transitions, not states").
    ///
    /// ELI5: "We're now in this new state — tell everyone watching, and (if
    /// this is actually a change) write it down."
    ///
    /// DETAILED: Every public entry point in this actor funnels through
    /// here, so this is the ONE place the transition log lives. Comparing
    /// `state.isSignedIn` (the OLD value, captured before it's overwritten)
    /// against `newState.isSignedIn` suppresses a same-state republish
    /// (e.g. `restoreFromStorage()`'s "no stored token" path, already
    /// `.signedOut`) from producing a `.notice` — that would log a STATE,
    /// not a transition. `userId`: `.private`, logged only on a
    /// signed-out→signed-in transition, when known (`nil` otherwise, per
    /// `signIn(token:userId:)`'s doc comment); ignored on the way out
    /// (`.signedIn` never stored one to look back up here).
    func setState(_ newState: SessionState, userId: Int? = nil) {
        let wasSignedIn = state.isSignedIn
        state = newState
        continuation.yield(newState)

        guard wasSignedIn != newState.isSignedIn else { return }

        if newState.isSignedIn {
            if let userId {
                IHLog.auth.notice("session: signed out -> signed in (userId: \(userId, privacy: .private))")
            } else {
                IHLog.auth.notice("session: signed out -> signed in")
            }
        } else {
            IHLog.auth.notice("session: signed in -> signed out")
        }
    }
}

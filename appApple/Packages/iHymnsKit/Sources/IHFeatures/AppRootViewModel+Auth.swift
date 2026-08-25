// AppRootViewModel+Auth.swift
// IHFeatures
//
// ELI5: Everything a login/account screen needs — "sign in with username +
// password," "email me a code," "sign in with that code," "sign out," and
// "who am I signed in as" — reached through `AppRootViewModel`, the same
// "every screen talks to the engines through the one root view model"
// pattern `songDetail(id:)`/`songbooks()`/... already establish.
//
// DETAILED: Native login/account UI task (completing #1398's noted gap).
// `AppRootViewModel` itself stays the SINGLE place a `SessionController`/
// `APIClient` pair lives per app run — `LoginView`/`AccountView` never hold
// either directly, mirroring how `CatalogueListView` never holds an
// `APIClient` of its own (`AppRootViewModel.swift`'s own header). Every
// sign-in ENTRY POINT below (`signIn(username:password:)`,
// `signIn(email:code:)`, and `restoreSessionIfNeeded()` in the primary file)
// calls `syncAfterSignIn()` directly, right after its own
// `sessionController` call succeeds — see `AppRootViewModel.swift`'s
// `observeSessionState()` doc comment for why that sync is deliberately NOT
// wired through the passive `stateUpdates` stream instead (it would have
// made several ALREADY-GREEN tests that drive `SessionController` directly
// start issuing real, unmocked network requests).
import Foundation
import IHAPI
import IHModels

extension AppRootViewModel {
    /// Signs in with a username/password pair, then runs the same
    /// post-sign-in sync every OTHER successful sign-in path runs (see
    /// `syncAfterSignIn()` below).
    ///
    /// ELI5: "Here's my username and password — log me in, then go fetch
    /// who I am and reconcile my favourites."
    ///
    /// - Parameter captchaToken: The solved CAPTCHA token, when
    ///   `captchaRequired(for: CaptchaConfig.loginFormKey)` is true
    ///   (#947/#340 native scaffold) — `nil` default; `LoginView` is the
    ///   ONE caller that ever supplies one, sourced from the provider its
    ///   `captchaSection(form:)` embeds.
    /// - Throws: Whatever `SessionController.signIn(username:password:captchaToken:)`
    ///   throws (`APIError.unauthorized` for wrong credentials,
    ///   `.rateLimited` after too many failed attempts, `.captchaRequired`
    ///   for a missing/invalid/already-spent token, `.offline`/
    ///   `.maintenance` for a transient failure, ...) — `LoginView` maps
    ///   these to user-facing copy via `APIError.userFacingMessage`, the
    ///   same convention every other load path in this package uses.
    public func signIn(username: String, password: String, captchaToken: String? = nil) async throws {
        try await sessionController.signIn(username: username, password: password, captchaToken: captchaToken)
        await syncAfterSignIn()
    }

    /// Signs in with an email + 6-digit code pair (from a prior
    /// `requestEmailLoginCode(email:)` call), then runs the same
    /// post-sign-in sync as `signIn(username:password:)` above.
    ///
    /// ELI5: "Here's the code you emailed me — log me in, then sync."
    ///
    /// - Throws: Whatever `SessionController.signIn(email:code:)` throws —
    ///   see that method's own doc comment.
    public func signIn(email: String, code: String) async throws {
        try await sessionController.signIn(email: email, code: code)
        await syncAfterSignIn()
    }

    /// Requests a magic-link + 6-digit email login code for `email` — the
    /// FIRST step of `LoginView`'s "sign in with email code" flow, before
    /// `signIn(email:code:)` above. A thin pass-through straight to
    /// `apiClient` (not `sessionController`): sending the code doesn't
    /// change any sign-in STATE at all, so it doesn't belong on
    /// `SessionController`, which strategy §1.6/`SessionController.swift`'s
    /// own header scope to "owns the single source of truth for sign-in
    /// state."
    ///
    /// ELI5: "Email me a one-time login code."
    ///
    /// - Parameter captchaToken: The solved CAPTCHA token, when
    ///   `captchaRequired(for: CaptchaConfig.emailLoginFormKey)` is true —
    ///   same "`LoginView` is the one caller that ever supplies one"
    ///   contract as `signIn(username:password:captchaToken:)` above.
    /// - Returns: The server's user-facing confirmation message — see
    ///   `APIClient.authEmailLoginRequest(email:captchaToken:)`'s own doc
    ///   comment for why this is always the SAME reassuring copy, even for
    ///   an unknown email.
    public func requestEmailLoginCode(email: String, captchaToken: String? = nil) async throws -> String {
        try await apiClient.authEmailLoginRequest(email: email, captchaToken: captchaToken)
    }

    /// Signs out — revokes the token server-side, forgets it locally
    /// (`SessionController.signOut()`'s load-bearing ordering — see that
    /// method's own doc comment), AND forgets every locally-cached
    /// favourite plus the offline pending-op queue.
    ///
    /// ELI5: "Log me out, and forget everything I favourited on this device
    /// — a different account might sign in next."
    ///
    /// - Throws: Whatever `SessionController.signOut()` throws — a genuine
    ///   revoke failure (`.offline`/`.maintenance`/`.server`/`.decoding`)
    ///   leaves the session (and therefore the local favourites cache)
    ///   UNTOUCHED, matching that method's own "never clear local state the
    ///   server hasn't confirmed" contract.
    public func signOut() async throws {
        try await sessionController.signOut()
        await resetLocalUserState()
    }

    /// Permanently deletes the signed-in account (#1477, App Review
    /// §5.1.1(v)) — `AccountDeleteView`'s destructive, re-auth-gated flow.
    /// On success, performs the EXACT SAME local teardown `signOut()` does
    /// (`resetLocalUserState()` below) — a deleted account must leave no
    /// more of a trace on this device than a signed-out one does.
    ///
    /// ELI5: "Prove it's you, then permanently delete your account — and
    /// forget everything cached on this device, exactly like signing out."
    ///
    /// - Throws: Whatever `SessionController.deleteAccount(reauth:)` throws
    ///   — `.unauthorized` (wrong/expired re-auth credential), `.rateLimited`
    ///   (too many attempts), a `.server(status: 409, _)` (this account is
    ///   the last Global Admin — transfer that role first), or a transient
    ///   failure. `AccountDeleteView` maps each to specific copy via
    ///   `APIError.accountDeleteUserFacingMessage`. Every one of these
    ///   leaves local state UNTOUCHED (see that method's own doc comment),
    ///   so `resetLocalUserState()` below only ever runs after a genuine
    ///   200.
    public func deleteAccount(reauth: AccountDeleteReauth) async throws {
        try await sessionController.deleteAccount(reauth: reauth)
        await resetLocalUserState()
    }

    /// Clears every piece of LOCAL, per-account state this device caches —
    /// shared by `signOut()` and `deleteAccount(reauth:)` above, since both
    /// need the IDENTICAL "a different (or no) account might use this
    /// device next" teardown once the server has confirmed the account is
    /// signed out / deleted.
    ///
    /// DETAILED: Clearing `favorites`/the on-disk favourite cache (and the
    /// #181 setlists equivalent) is a deliberate privacy choice
    /// (`OfflineStore.clearFavorites()`/`clearSetlists()`'s own doc
    /// comments): a shared device where a second account signs in
    /// afterwards must never show the FIRST account's data.
    /// `hasLoadedFavoritesFromCache`/`hasLoadedSetlistsFromCache` reset to
    /// `false` too, so the NEXT `loadFavoritesIfNeeded()`/
    /// `loadSetlistsIfNeeded()` call (the next sign-in's `syncAfterSignIn()`)
    /// re-reads the now-empty cache rather than silently no-op'ing on its
    /// stale "already loaded" guard.
    private func resetLocalUserState() async {
        currentUser = nil
        favorites = []
        hasLoadedFavoritesFromCache = false
        try? await offlineStore.clearFavorites()
        setlists = []
        hasLoadedSetlistsFromCache = false
        try? await offlineStore.clearSetlists()
    }

    /// Refreshes `currentUser` from `?action=auth_me` — `AccountView`'s
    /// identity display calls this on appearance; `syncAfterSignIn()` below
    /// also calls it after every fresh sign-in/restore.
    ///
    /// ELI5: "Go check who I'm actually signed in as, right now."
    ///
    /// DETAILED: Best-effort — a failure (offline, maintenance, ...) leaves
    /// `currentUser` exactly as it was rather than blanking a perfectly good
    /// previously-known identity just because THIS particular refresh
    /// attempt couldn't reach the server; `AccountView` already shows
    /// whatever it has.
    public func refreshCurrentUser() async {
        currentUser = try? await apiClient.authMe()
    }

    /// Runs after every successful sign-in (fresh username/password,
    /// fresh email-code, OR a `restoreSessionIfNeeded()` that found a still
    /// -valid Keychain token): refreshes the signed-in identity, loads the
    /// LOCAL favourites cache (instant, offline-first), drains any queued
    /// offline favourite actions, THEN pulls the server's authoritative
    /// favourites list.
    ///
    /// ELI5: "Just signed in — go find out who I am, and make sure my
    /// favourites are all caught up with the server."
    ///
    /// DETAILED: `flushPendingFavoriteOps()` runs BEFORE
    /// `refreshFavoritesFromServer()` deliberately — the latter REPLACES the
    /// local cache with the server's authoritative list
    /// (`OfflineStore.replaceFavorites(with:)`), which would otherwise wipe
    /// out a favourite the user toggled while offline moments ago but that
    /// hasn't reached the server yet. Flushing first means, by the time the
    /// authoritative pull happens, the server already agrees with whatever
    /// was queued (or the flush itself failed — still offline — in which
    /// case the SUBSEQUENT `refreshFavoritesFromServer()` fails too, for the
    /// identical reason, and the local cache is correctly left untouched
    /// either way).
    func syncAfterSignIn() async {
        await refreshCurrentUser()
        await loadFavoritesIfNeeded()
        await flushPendingFavoriteOps()
        await refreshFavoritesFromServer()
        // #181 setlists half — same flush-before-authoritative-pull ordering
        // as the favourites sequence above, for the identical reason (a
        // setlist edited moments ago offline must not be wiped by the
        // server's still-stale authoritative list arriving first).
        await loadSetlistsIfNeeded()
        await flushPendingSetlistOps()
        await refreshSetlistsFromServer()
    }

    /// Re-hydrates sign-in state from the Keychain, but only once per app
    /// launch — safe to call from `RootContainerView`'s top-level `.task {}`
    /// on every re-appearance (completing #1398's noted gap: nothing
    /// previously called `SessionController.restoreFromStorage()` at all, so
    /// a returning signed-in user always saw a signed-out app until they
    /// signed in again).
    ///
    /// ELI5: "Were we already signed in? Check once, right at launch — and
    /// if so, sync."
    ///
    /// DETAILED: Deliberately swallows any thrown error from
    /// `restoreFromStorage()` itself — its own doc comment guarantees it
    /// already fails OPEN internally (a transient network problem at launch
    /// still publishes `.signedIn`), so the only way this call throws is a
    /// genuine Keychain read failure, treated the same as "no stored token":
    /// `sessionState` stays `.signedOut`, a normal sign-in prompt shows.
    /// Reads the AUTHORITATIVE actor state directly afterwards
    /// (`await sessionController.state`, via `isSignedInNow()` below) rather
    /// than the `sessionState` MainActor mirror — that mirror is updated by
    /// a SEPARATE, concurrently-running `Task`
    /// (`AppRootViewModel.observeSessionState()`), with no ordering
    /// guarantee it has caught up the instant `restoreFromStorage()` above
    /// returns; querying the actor property directly sidesteps that race.
    public func restoreSessionIfNeeded() async {
        guard !hasAttemptedSessionRestore else { return }
        hasAttemptedSessionRestore = true
        // #947/#340 native scaffold — the app's first `app_status` read,
        // piggy-backed on this method's own one-shot guard rather than a
        // second guard property (`AppRootViewModel+Captcha.swift`'s own
        // header explains why); failure is swallowed there, so this can
        // never itself fail or block the sign-in restore below.
        await loadAppStatus()
        try? await sessionController.restoreFromStorage()
        if await isSignedInNow() {
            await syncAfterSignIn()
        }
    }

    /// Queries the AUTHORITATIVE sign-in state directly from
    /// `sessionController` — NOT the `sessionState` MainActor mirror, which
    /// can lag behind by one scheduling hop (see `restoreSessionIfNeeded()`
    /// above). `AppRootViewModel+Favorites.swift`'s `toggleFavorite(...)`/
    /// `refreshFavoritesFromServer()`/`flushPendingFavoriteOps()` all call
    /// this too, for the identical reason: each can run RIGHT AFTER a
    /// `sessionController` call in the same synchronous sequence
    /// (`syncAfterSignIn()`, called immediately after `sessionController
    /// .signIn(...)` returns) — a window where the mirror genuinely hasn't
    /// caught up yet.
    ///
    /// ELI5: "Ask the actual source of truth, not the maybe-slightly-stale
    /// copy" — for the handful of call sites where that distinction matters.
    ///
    /// DETAILED: Everywhere ELSE in this package (a SwiftUI view's `body`,
    /// e.g. `SongDetailToolbar`'s `isSignedIn` parameter, `AccountView`/
    /// `FavoritesView`'s signed-out states) the `sessionState` mirror
    /// remains exactly the right tool — main-thread-readable with no
    /// `await`, and never actually stale in a STEADY-STATE read (one not
    /// immediately chained after a sign-in/out call).
    func isSignedInNow() async -> Bool {
        await sessionController.state.isSignedIn
    }

    /// Starts a `Task` that mirrors every `SessionState` change published by
    /// `sessionController` onto `sessionState` — moved here from
    /// `AppRootViewModel.swift` (#1429 PR-16 LOC-budget tripwire); called
    /// once from `AppRootViewModel.init` exactly as before the move.
    ///
    /// ELI5: Keeps our main-thread copy of "signed in or not" always up to
    /// date.
    ///
    /// DETAILED: Created as a plain (non-detached) `Task` from within the
    /// `@MainActor` initializer, so — per Swift's structured-concurrency
    /// isolation-inheritance rule — the task's body itself runs
    /// MainActor-isolated, meaning `self.sessionState = state` below needs
    /// no extra `await MainActor.run { ... }` hop. Captures `self` WEAKLY:
    /// `sessionController.stateUpdates` never terminates on its own, so a
    /// strong capture here would keep this task (and therefore this whole
    /// view model) alive forever — a classic retain cycle. With a weak
    /// capture, once `self` is deallocated the loop simply exits on its
    /// next iteration instead of pinning it in memory.
    ///
    /// DELIBERATELY side-effect-free beyond that mirror (native login/
    /// account UI + favourites task): the "on sign-in, refresh my profile +
    /// reconcile favourites with the server; on sign-out, forget them
    /// locally" behaviour lives in THIS file's `signIn(...)`/
    /// `signInWithEmailCode(...)`/`signOut()`/`restoreSessionIfNeeded()`
    /// themselves, called DIRECTLY right after each one's own
    /// `sessionController` call succeeds — NOT wired through this passive
    /// stream. Two reasons: (1) every existing `SessionController`-level
    /// test (`SessionControllerTests`) and several `AppRootViewModelTests`
    /// drive `sessionController.signIn(token:)` directly, bypassing
    /// `AppRootViewModel` entirely — hanging a real network call
    /// (`apiClient.favorites()`/`authMe()`) off THIS loop would have made
    /// those pre-existing, already-green tests start issuing real,
    /// unmocked network requests the moment they touched sign-in state, a
    /// regression this task must not introduce; (2) calling the sync
    /// directly from each entry point is strictly more deterministic and
    /// easier to unit test than racing it against this loop's own
    /// `AsyncStream` delivery timing.
    func observeSessionState() {
        sessionObservationTask = Task { [weak self, sessionController] in
            for await state in sessionController.stateUpdates {
                guard let self, !Task.isCancelled else { break }
                self.sessionState = state
                // PR-10: force-end hosting on sign-out (no-op unless hosting).
                if case .signedOut = state { Task { await self.liveFollowEngine.endHostingForSignOut() } }
            }
        }
    }
}

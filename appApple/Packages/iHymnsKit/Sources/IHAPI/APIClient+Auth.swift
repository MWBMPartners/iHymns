// APIClient+Auth.swift
// IHAPI
//
// ELI5: The "phone calls" for signing in, signing out, checking who's signed
// in, and emailing yourself a one-time login code — layered on top of the
// SAME `APIClient` actor every catalogue read already uses, just split into
// their own file.
//
// DETAILED: Split out of `APIClient.swift` (rather than growing that file
// further, which was already approaching the repo's 400-line LOC-budget
// tripwire, `Scripts/loc-budget.sh`) — a same-target file split, which is
// exactly what `performOnce`'s `private` → `internal` relaxation in
// `APIClient.swift` (#1398) exists to enable (`private` is file-scoped in
// Swift; a same-MODULE extension in a different file needs at least
// `internal` visibility on the member it calls).
//
// Every POST below (`authLogin`/`authLogout`/`authEmailLoginRequest`/
// `authEmailLoginVerify`/`accountDelete`) uses `performOnce` — NOT the
// retrying `performIdempotentGET` — because strategy §1.5 is explicit:
// "never auto-retry non-idempotent POSTs." Signing in twice because of a
// client-side retry could double-log a failed-attempt counter server-side;
// signing out twice is harmless but still not idempotent-safe to blindly
// retry against an unknown failure mode. `authMe()` IS a bodyless,
// idempotent GET, so it goes through `performIdempotentGET` like every other
// read.
import Foundation
import IHModels

extension APIClient {
    /// `?action=auth_login` — exchanges a username/password pair for a
    /// 30-day bearer token + the signed-in user's profile.
    ///
    /// ELI5: "Here's my username and password — log me in."
    ///
    /// DETAILED: Called by `IHAuth.SessionController.signIn(username:password:)`
    /// (#1398), which then persists the returned token to the Keychain and
    /// calls `updateBearerToken(_:)` on this same client so every
    /// subsequent authenticated call carries it.
    public func authLogin(username: String, password: String) async throws -> AuthSession {
        let endpoint = try Endpoint.authLogin(username: username, password: password)
        let data = try await performOnce(endpoint)
        return try Self.decodeAuthSession(from: data)
    }

    /// `?action=auth_logout` — revokes the CURRENTLY-SET bearer token
    /// (`bearerToken`, updated via `updateBearerToken(_:)`) server-side.
    ///
    /// ELI5: "Forget my login pass — nobody should be able to use it again."
    ///
    /// DETAILED: Callers (`IHAuth.SessionController.signOut()`) MUST await
    /// this succeeding — or classify a thrown `.unauthorized` as "already
    /// revoked, nothing left to do" — BEFORE deleting any local copy of the
    /// token. Strategy §3.2's revocation ordering: "sign-out = server-revoke
    /// THEN delete synchronizable item — else iCloud resurrects a revoked
    /// token." This method only performs the server half; the ordering
    /// itself is enforced by `SessionController`, not here.
    public func authLogout() async throws {
        _ = try await performOnce(.authLogout)
    }

    /// `?action=auth_me` — the currently-authenticated user's profile.
    ///
    /// ELI5: "Confirm who I'm signed in as."
    ///
    /// DETAILED: Called by `IHAuth.SessionController.restoreFromStorage()`
    /// (completing #1398's "the caller who validates a Keychain token before
    /// trusting it" gap) and `IHFeatures.AppRootViewModel`'s
    /// `refreshCurrentUser()` (`AccountView`'s identity display). Throws
    /// `.unauthorized` for a dead/expired/revoked token — callers treat that
    /// as authoritative proof the token is no longer good, distinct from a
    /// transient `.offline`/`.maintenance`/`.server` failure that says
    /// nothing about the token itself.
    public func authMe() async throws -> AuthUser {
        let data = try await performIdempotentGET(.authMe)
        return try Self.decodeAuthUser(from: data)
    }

    /// `?action=auth_email_login_request` — emails `email` a magic link + a
    /// 6-digit code, valid 10 minutes.
    ///
    /// ELI5: "Email me a one-time login code."
    ///
    /// - Returns: The server's user-facing confirmation message (e.g. "A
    ///   login code has been sent to your email address. It expires in 10
    ///   minutes.") — always returned on a 2xx response, even for an unknown
    ///   email (anti-enumeration design, this file's header /
    ///   `AuthEndpoints.swift`'s header), so `LoginView` shows the SAME
    ///   reassuring copy either way rather than confirming/denying whether
    ///   an account exists for that address.
    public func authEmailLoginRequest(email: String) async throws -> String {
        let endpoint = try Endpoint.authEmailLoginRequest(email: email)
        let data = try await performOnce(endpoint)
        return try Self.decodeEmailLoginRequestMessage(from: data)
    }

    /// `?action=auth_email_login_verify` (email + 6-digit code mode) —
    /// exchanges the code `authEmailLoginRequest(email:)` sent for a 30-day
    /// bearer token, same shape `authLogin(username:password:)` returns.
    ///
    /// ELI5: "Here's the code you emailed me — log me in."
    public func authEmailLoginVerify(email: String, code: String) async throws -> AuthSession {
        let endpoint = try Endpoint.authEmailLoginVerify(email: email, code: code)
        let data = try await performOnce(endpoint)
        return try Self.decodeAuthSession(from: data)
    }

    /// `?action=account_delete` — POSTs `reauth`, and, once the server
    /// verifies it, permanently deletes the CURRENTLY-authenticated account
    /// (#1477, App Review §5.1.1(v)).
    ///
    /// ELI5: "Here's proof it's really me — delete my account for good."
    ///
    /// DETAILED: Called by `IHAuth.SessionController.deleteAccount(reauth:)`,
    /// which — mirroring `authLogout()`'s own doc comment's "server confirms
    /// first" ordering — only clears the local token/session state AFTER
    /// this call has already returned successfully. Unlike `authLogout()`,
    /// a thrown `.unauthorized` here does NOT mean "already done": it means
    /// the RE-AUTH credential itself (the password/email code, not the
    /// bearer token) was wrong or expired, so the account still exists and
    /// the caller must not treat the failure as anything but "let the user
    /// retry."
    ///
    /// - Throws: `.unauthorized` for a wrong/expired re-auth credential,
    ///   `.rateLimited` after too many attempts (`api.php`'s dedicated
    ///   `account_delete` rate-limit, 10 attempts/15 minutes), a
    ///   `.server(status: 409, _)` when this account is the last remaining
    ///   Global Admin (must transfer that role first), or any of the usual
    ///   transient cases (`.offline`/`.maintenance`/`.decoding`).
    public func accountDelete(reauth: AccountDeleteReauth) async throws {
        let endpoint = try Endpoint.accountDelete(reauth: reauth)
        _ = try await performOnce(endpoint)
    }
}

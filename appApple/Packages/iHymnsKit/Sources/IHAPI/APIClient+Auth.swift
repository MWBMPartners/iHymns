// APIClient+Auth.swift
// IHAPI
//
// ELI5: The two "phone calls" for signing in and signing out — layered on
// top of the SAME `APIClient` actor every catalogue read already uses, just
// split into their own file.
//
// DETAILED: Split out of `APIClient.swift` (rather than growing that file
// further, which was already approaching the repo's 400-line LOC-budget
// tripwire, `Scripts/loc-budget.sh`) — a same-target file split, which is
// exactly what `performOnce`'s `private` → `internal` relaxation in
// `APIClient.swift` (#1398) exists to enable (`private` is file-scoped in
// Swift; a same-MODULE extension in a different file needs at least
// `internal` visibility on the member it calls).
//
// Both calls below use `performOnce` — NOT the retrying `performIdempotentGET`
// — because strategy §1.5 is explicit: "never auto-retry non-idempotent
// POSTs." Signing in twice because of a client-side retry could double-log
// a failed-attempt counter server-side; signing out twice is harmless but
// still not idempotent-safe to blindly retry against an unknown failure
// mode.
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
}

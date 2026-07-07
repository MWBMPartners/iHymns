// AuthEndpoints.swift
// IHAPI
//
// ELI5: The recipe cards for "sign in" and "sign out" — the `Auth` group of
// the typed endpoint catalogue strategy §1.5 promises alongside `Catalog`
// (see `CatalogEndpoints.swift`).
//
// DETAILED: The first two POST endpoints this catalogue needs (#1398) —
// every #1397 catalogue read was a bodyless GET. `auth_login` needs a
// JSON-encoded body (`{"username", "password"}`, verified against
// `appWeb/public_html/api.php`'s `case 'auth_login'` handler); `auth_logout`
// needs no body but DOES need `requiresAuth: true` so `APIClient` attaches
// the `Authorization: Bearer <token>` header identifying WHICH token to
// revoke server-side.
import Foundation
import IHModels

extension Endpoint {
    /// `?action=auth_login` — POST username/password, returns a 30-day
    /// bearer token + the signed-in user's public profile (`AuthSession`).
    ///
    /// - Throws: Only if `JSONEncoder` itself somehow fails encoding two
    ///   plain `String` fields (practically unreachable — no dates, no
    ///   non-conforming floats — but `Endpoint`'s factory surfaces `throws`
    ///   rather than force-trying, matching this package's general "no
    ///   silent force-unwraps of anything except developer-authored
    ///   literals" posture).
    static func authLogin(username: String, password: String) throws -> Endpoint {
        let body = try JSONEncoder().encode(AuthLoginRequestBody(username: username, password: password))
        return Endpoint(action: "auth_login", httpMethod: "POST", httpBody: body)
    }

    /// `?action=auth_logout` — revokes the CURRENTLY-ATTACHED bearer token
    /// server-side. `requiresAuth: true` is what makes `APIClient` attach
    /// that token as the `Authorization` header in the first place; the
    /// server identifies (and deletes) the exact `tblApiTokens` row from it.
    static let authLogout = Endpoint(action: "auth_logout", requiresAuth: true, httpMethod: "POST")
}

/// The exact wire shape `auth_login`'s JSON body expects — a private
/// implementation detail of `Endpoint.authLogin(username:password:)` above,
/// never constructed anywhere else.
private struct AuthLoginRequestBody: Encodable {
    let username: String
    let password: String
}

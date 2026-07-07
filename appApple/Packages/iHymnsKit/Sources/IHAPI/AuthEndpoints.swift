// AuthEndpoints.swift
// IHAPI
//
// ELI5: The recipe cards for "sign in," "sign out," "who am I," and "email
// me a login code" — the `Auth` group of the typed endpoint catalogue
// strategy §1.5 promises alongside `Catalog` (see `CatalogEndpoints.swift`).
//
// DETAILED: The first two POST endpoints this catalogue needed (#1398) were
// `auth_login`/`auth_logout` — every #1397 catalogue read was a bodyless
// GET. This file (native login/account UI task, completing #1398's noted
// gap) adds three more, all verified against the LIVE
// `appWeb/public_html/api.php` handlers (not just `api-docs.yaml`, which
// agrees) on 2026-07-07:
//   - `auth_me` (GET, `requiresAuth: true`) — `case 'auth_me'`, used both by
//     `SessionController.restoreFromStorage()` (validating a Keychain token
//     is still live before trusting it) and `AppRootViewModel`'s signed-in
//     identity display (`AccountView`).
//   - `auth_email_login_request` (POST, no auth) — `case
//     'auth_email_login_request'`, sends a magic link + 6-digit code to an
//     email address. Always 200s (even for an unknown email, to prevent
//     enumeration — `api.php`'s own comment).
//   - `auth_email_login_verify` (POST, no auth) — `case
//     'auth_email_login_verify'`, exchanges EITHER a magic-link token OR an
//     email+code pair for a bearer token, same `AuthResponse` shape as
//     `auth_login`. This client only ever sends the email+code form (see
//     `Endpoint.authEmailLoginVerify(email:code:)`'s own doc comment for
//     why the magic-link token form, while modelled here for completeness,
//     has no UI entry point yet).
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

    /// `?action=auth_me` — the currently-authenticated user's profile.
    /// `requiresAuth: true`; a bad/expired/revoked token 401s.
    ///
    /// ELI5: "Who am I signed in as, right now?"
    static let authMe = Endpoint(action: "auth_me", requiresAuth: true)

    /// `?action=auth_email_login_request` — POST an email address; the
    /// server sends a magic link + 6-digit code (always 200s, to prevent
    /// email enumeration — see this file's header). No auth required (the
    /// whole point is signing in without one yet).
    ///
    /// - Throws: Only if `JSONEncoder` fails (see `authLogin` above).
    static func authEmailLoginRequest(email: String) throws -> Endpoint {
        let body = try JSONEncoder().encode(AuthEmailLoginRequestBody(email: email))
        return Endpoint(action: "auth_email_login_request", httpMethod: "POST", httpBody: body)
    }

    /// `?action=auth_email_login_verify` (email + 6-digit code mode) —
    /// exchanges the code emailed by `authEmailLoginRequest(email:)` for a
    /// bearer token, same `AuthResponse` shape `auth_login` returns. No auth
    /// required.
    ///
    /// - Throws: Only if `JSONEncoder` fails (see `authLogin` above).
    static func authEmailLoginVerify(email: String, code: String) throws -> Endpoint {
        let body = try JSONEncoder().encode(AuthEmailLoginVerifyRequestBody(email: email, code: code, token: nil))
        return Endpoint(action: "auth_email_login_verify", httpMethod: "POST", httpBody: body)
    }

    /// `?action=auth_email_login_verify` (magic-link token mode) — modelled
    /// here for completeness/future use, but **not wired to any UI yet**:
    /// consuming a magic-link tap requires this app to actually receive the
    /// `https://ihymns.app/login?token=…` Universal Link and hand it to this
    /// call, which needs strategy §1.7's `DeepLinkRouter`/Associated Domains
    /// wiring — real, but genuinely out of scope for this task (no deep-link
    /// handler exists anywhere in `IHAppSupport`/the app shells yet, and
    /// faking a "paste your magic link token" text field would be exactly
    /// the kind of faked surface this task's brief says not to build). The
    /// email+code path above (`authEmailLoginVerify(email:code:)`) is fully
    /// wired and IS `LoginView`'s "sign in with email code" option.
    ///
    /// - Throws: Only if `JSONEncoder` fails (see `authLogin` above).
    static func authEmailLoginVerify(token: String) throws -> Endpoint {
        let body = try JSONEncoder().encode(AuthEmailLoginVerifyRequestBody(email: nil, code: nil, token: token))
        return Endpoint(action: "auth_email_login_verify", httpMethod: "POST", httpBody: body)
    }
}

/// The exact wire shape `auth_login`'s JSON body expects — a private
/// implementation detail of `Endpoint.authLogin(username:password:)` above,
/// never constructed anywhere else.
private struct AuthLoginRequestBody: Encodable {
    let username: String
    let password: String
}

/// The exact wire shape `auth_email_login_request`'s JSON body expects
/// (`{"email": "…"}`).
private struct AuthEmailLoginRequestBody: Encodable {
    let email: String
}

/// The exact wire shape `auth_email_login_verify`'s JSON body expects — one
/// of `{token}` or `{email, code}`, per that endpoint's two verification
/// modes (this file's header). `Optional` fields simply encode as an absent
/// key (`JSONEncoder`'s default), never a literal `null`, matching
/// `api.php`'s own `trim($body['token'] ?? '')`-style "absent key is fine"
/// reads.
private struct AuthEmailLoginVerifyRequestBody: Encodable {
    let email: String?
    let code: String?
    let token: String?
}

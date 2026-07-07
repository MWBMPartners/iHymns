// AuthSession.swift
// IHModels
//
// ELI5: What the server hands back the moment you sign in successfully —
// the login pass itself (the "token"), plus who you are.
//
// DETAILED: Mirrors `AuthResponse` in `appWeb/public_html/api-docs.yaml`
// and the literal `sendJson([...])` call in `api.php`'s `case 'auth_login'`
// handler (#1398): `{"token": "<64-hex>", "user": {"id", "username",
// "display_name", "role", "avatar_service"}}`. This is ALSO the exact shape
// strategy §1.6's future `auth_apple` (Sign in with Apple) endpoint is
// designed to return ("mint a standard 30-day tblApiTokens token — response
// identical to auth_login") — so this one DTO already covers that Phase-1
// addition too; no second "SIWA session" type will be needed.
import Foundation

/// The response body of a successful `auth_login` call (and, later,
/// `auth_apple`): a 30-day bearer token plus the signed-in user's public
/// profile.
///
/// ELI5: "Here's your login pass, and here's who you are."
public struct AuthSession: Sendable, Hashable, Codable {
    /// 64-character hex bearer token, valid 30 days from issuance — the
    /// exact value `IHAuth.SessionController` persists to the Keychain and
    /// `APIClient.updateBearerToken(_:)` attaches to every subsequent
    /// authenticated request.
    public let token: String

    /// The signed-in user's public profile, as returned inline with the
    /// token (no separate `auth_me` round-trip needed right after login).
    public let user: AuthUser

    public init(token: String, user: AuthUser) {
        self.token = token
        self.user = user
    }
}

/// The signed-in user's public profile, as embedded in `AuthSession`.
///
/// ELI5: Your account's public name tag — who you are, and what you're
/// allowed to do.
///
/// DETAILED: A deliberately SMALLER shape than `api-docs.yaml`'s full
/// `User`/`UserWithEmail` schemas — this file models only the fields
/// `auth_login`'s handler actually emits today (`id`/`username`/
/// `display_name`/`role`/`avatar_service`, the last being the #616 avatar-
/// service field the YAML doc doesn't list at all). A richer profile DTO
/// (email, preferences, ...) is a separate Phase-1 concern fed by
/// `?action=auth_me`, not this login-response shape.
public struct AuthUser: Sendable, Hashable, Codable, Identifiable {
    public let id: Int
    public let username: String
    public let displayName: String

    /// `"user" | "editor" | "admin" | "global_admin"` — open `String`
    /// (never an `enum`) for the same reason every other open-vocabulary
    /// field in this package is a `String`: a new server-side role must
    /// never sink decoding of an otherwise-valid login response.
    public let role: String

    /// The user's chosen avatar provider (#616), or `nil` when unset/on a
    /// pre-#616 deployment that doesn't emit the key at all.
    public let avatarService: String?

    public init(id: Int, username: String, displayName: String, role: String, avatarService: String? = nil) {
        self.id = id
        self.username = username
        self.displayName = displayName
        self.role = role
        self.avatarService = avatarService
    }

    private enum CodingKeys: String, CodingKey {
        case id
        case username
        case displayName = "display_name"
        case role
        case avatarService = "avatar_service"
    }
}

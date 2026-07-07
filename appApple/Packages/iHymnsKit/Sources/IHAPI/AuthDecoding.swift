// AuthDecoding.swift
// IHAPI
//
// ELI5: Turns the raw bytes `auth_login`/`auth_email_login_verify` send back
// into the `AuthSession` Swift value the rest of the app uses, `auth_me`'s
// bytes into a bare `AuthUser`, and `auth_email_login_request`'s bytes into
// its confirmation message.
//
// DETAILED: Mirrors `SongsIndexDecoding.swift`'s pattern (a `nonisolated
// static` decode function on `APIClient`, independently unit-testable with
// zero networking) — but UNLIKE every catalogue read, `auth_login`'s
// response body IS the bare `AuthSession` shape directly
// (`{"token": ..., "user": {...}}`), with no wrapping envelope key the way
// `songs_index`/`song_detail`/`songbooks` wrap theirs in `{"songs": [...]}`
// / `{"song": {...}}` / `{"songbooks": [...]}`. `auth_email_login_verify`
// returns the IDENTICAL `AuthResponse` shape (`api.php`'s `case
// 'auth_email_login_verify'` ends with `sendJson($loginResult)`, and
// `_completeEmailLoginTxn`'s `$loginResult` is `{token, user: {id, username,
// display_name, email, role}}`) — decodable with the SAME `AuthSession`
// type: the extra `email` key and missing `avatar_service` key both decode
// fine against `AuthUser` (unknown keys are ignored by `Decodable`;
// `avatarService` is already `Optional`).
import Foundation
import IHModels

extension APIClient {
    /// Decodes an `auth_login`/`auth_email_login_verify` response body into
    /// an `AuthSession`.
    ///
    /// - Parameter data: The raw HTTP response body — the bare
    ///   `{"token": "...", "user": {...}}` object itself.
    /// - Throws: `APIError.decoding` on any shape mismatch — a single
    ///   sign-in response failing to decode is always genuine contract
    ///   drift, never a "one bad row among thousands" situation the way
    ///   `decodeSongsIndex` tolerates.
    nonisolated public static func decodeAuthSession(from data: Data) throws -> AuthSession {
        do {
            return try JSONDecoder().decode(AuthSession.self, from: data)
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes an `auth_me` response body into an `AuthUser`.
    ///
    /// - Parameter data: The raw HTTP response body — `{"user": {...}}`.
    nonisolated public static func decodeAuthUser(from data: Data) throws -> AuthUser {
        struct Envelope: Decodable {
            let user: AuthUser
        }
        do {
            return try JSONDecoder().decode(Envelope.self, from: data).user
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes an `auth_email_login_request` response body into its
    /// user-facing confirmation message.
    ///
    /// - Parameter data: The raw HTTP response body — `{"ok": true,
    ///   "message": "…"}`. `ok` is always `true` on a 2xx response (the
    ///   endpoint 200s even for an unknown email, per its own
    ///   anti-enumeration design — `AuthEndpoints.swift`'s header), so only
    ///   `message` is worth surfacing; a non-2xx status never reaches this
    ///   decode step at all (`APIClient.classify` already turned it into a
    ///   thrown `APIError` before this would be called).
    nonisolated public static func decodeEmailLoginRequestMessage(from data: Data) throws -> String {
        struct Envelope: Decodable {
            let message: String
        }
        do {
            return try JSONDecoder().decode(Envelope.self, from: data).message
        } catch {
            throw APIError.decoding
        }
    }
}

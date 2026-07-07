// AuthDecoding.swift
// IHAPI
//
// ELI5: Turns the raw bytes `auth_login` sends back into the `AuthSession`
// Swift value the rest of the app uses.
//
// DETAILED: Mirrors `SongsIndexDecoding.swift`'s pattern (a `nonisolated
// static` decode function on `APIClient`, independently unit-testable with
// zero networking) — but UNLIKE every catalogue read, `auth_login`'s
// response body IS the bare `AuthSession` shape directly
// (`{"token": ..., "user": {...}}`), with no wrapping envelope key the way
// `songs_index`/`song_detail`/`songbooks` wrap theirs in `{"songs": [...]}`
// / `{"song": {...}}` / `{"songbooks": [...]}`.
import Foundation
import IHModels

extension APIClient {
    /// Decodes an `auth_login` response body into an `AuthSession`.
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
}

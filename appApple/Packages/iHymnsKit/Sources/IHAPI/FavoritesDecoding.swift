// FavoritesDecoding.swift
// IHAPI
//
// ELI5: Turns the raw bytes `favorites`/`favorites_sync` send back into the
// `[FavoriteEntry]` Swift value the rest of the app uses. Both endpoints
// share the exact same response envelope, so they share this one decoder.
//
// DETAILED: Mirrors `AuthDecoding.swift`'s pattern (a `nonisolated static`
// decode function on `APIClient`, independently unit-testable with zero
// networking). Verified against `api.php`'s `sendJson(['favorites' =>
// $favorites])` in both the `case 'favorites'` and `case 'favorites_sync'`
// handlers (2026-07-07) — both wrap the array in the SAME `{"favorites":
// [...]}` envelope, so `decodeFavorites(from:)` below serves both
// `APIClient.favorites()` and `APIClient.favoritesSync(mode:favorites:)`.
import Foundation
import IHModels

extension APIClient {
    /// Decodes a `favorites` GET or `favorites_sync` POST response body into
    /// `[FavoriteEntry]`.
    ///
    /// - Parameter data: The raw HTTP response body — `{"favorites": [...]}`.
    /// - Throws: `APIError.decoding` on any shape mismatch. NOT lossy (unlike
    ///   `decodeSongsIndex`'s per-row tolerance) — a user's favourites list
    ///   is small (server-capped at 500) and every row's `id` was itself a
    ///   validated `SongID` server-side before being stored, so a decode
    ///   failure here means genuine contract drift, not "one bad row among
    ///   thousands."
    nonisolated public static func decodeFavorites(from data: Data) throws -> [FavoriteEntry] {
        struct Envelope: Decodable {
            let favorites: [FavoriteEntry]
        }
        do {
            return try JSONDecoder().decode(Envelope.self, from: data).favorites
        } catch {
            throw APIError.decoding
        }
    }
}

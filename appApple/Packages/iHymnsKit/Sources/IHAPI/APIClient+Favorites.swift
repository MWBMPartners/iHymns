// APIClient+Favorites.swift
// IHAPI
//
// ELI5: The three "phone calls" for server-synced favourites — get them all,
// sync a batch, remove one — layered on top of the SAME `APIClient` actor
// every other call uses, just split into their own file (same reasoning as
// `APIClient+Auth.swift`/`APIClient+Discovery.swift`).
//
// DETAILED: `favorites()` is an idempotent GET, so it goes through the
// retrying `performIdempotentGET` every catalogue read uses. `favoritesSync`/
// `favoritesRemove` are POSTs, so — matching `APIClient+Auth.swift`'s
// explicit "never auto-retry non-idempotent POSTs" policy (strategy §1.5) —
// they go through the non-retrying `performOnce` instead, exactly like
// `authLogin`/`authLogout`. A CALLER that wants retry-on-failure semantics
// for a favourite toggle gets it from the offline pending-op queue
// (`IHFeatures.AppRootViewModel+Favorites.swift`), not from this layer
// silently re-sending a POST it has no idempotency-key protection for.
import Foundation
import IHModels

extension APIClient {
    /// `?action=favorites` — every favourite the signed-in user has.
    ///
    /// ELI5: "What have I favourited?"
    public func favorites() async throws -> [FavoriteEntry] {
        let data = try await performIdempotentGET(.favorites)
        return try Self.decodeFavorites(from: data)
    }

    /// `?action=favorites_sync` — reconciles `favorites` with the server
    /// under `mode`, returning the server's resulting authoritative list.
    ///
    /// ELI5: "Here's what I've favourited on this device — merge that in and
    /// tell me the full, up-to-date list."
    public func favoritesSync(mode: FavoritesSyncMode, favorites: [FavoriteEntry]) async throws -> [FavoriteEntry] {
        let endpoint = try Endpoint.favoritesSync(mode: mode, favorites: favorites)
        let data = try await performOnce(endpoint)
        return try Self.decodeFavorites(from: data)
    }

    /// `?action=favorites_remove` — removes one song from the signed-in
    /// user's favourites.
    ///
    /// ELI5: "Un-favourite this one song."
    ///
    /// DETAILED: The response body is just `{"ok": true}`
    /// (`SuccessResponse`) — nothing this client needs back, so unlike every
    /// other call in this file the result is discarded; a non-2xx status is
    /// still surfaced as a thrown `APIError` by `performOnce` exactly as
    /// everywhere else.
    public func favoritesRemove(songId: SongID) async throws {
        let endpoint = try Endpoint.favoritesRemove(songId: songId)
        _ = try await performOnce(endpoint)
    }
}

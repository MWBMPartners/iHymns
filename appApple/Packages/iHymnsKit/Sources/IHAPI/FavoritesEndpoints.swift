// FavoritesEndpoints.swift
// IHAPI
//
// ELI5: The recipe cards for "what are my favourites," "save these
// favourites," and "un-favourite this one song" — the three server-synced
// favourites calls (WS-G #1019).
//
// DETAILED: Verified field-for-field against the LIVE handlers, not just
// `api-docs.yaml` (which agrees) — `appWeb/public_html/api.php`'s
// `case 'favorites'` (GET), `case 'favorites_sync'` (POST), `case
// 'favorites_remove'` (POST), read 2026-07-07. All three `requiresAuth:
// true` (`security: bearerAuth` in the YAML; every handler calls
// `getAuthenticatedUser()` and 401s without it). `favorites_sync` accepts
// EITHER a bare-string-id array or a `{id, tags}` object array server-side —
// this client always sends the OBJECT form (`FavoritesSyncRequestBody`
// below), which is a strict superset: an object entry with `tags: []` under
// `mode=merge` (the only mode this client's #181 slice ever sends) unions
// with `[]`, i.e. leaves any existing server-side tags for that song
// untouched — behaviourally identical to sending a bare string, with none of
// the "which shape did I mean" ambiguity a `oneOf` would add to this file.
import Foundation
import IHModels

extension Endpoint {
    /// `?action=favorites` — every favourite the signed-in user has, as
    /// `{id, tags}` objects. Requires auth.
    public static let favorites = Endpoint(action: "favorites", requiresAuth: true)

    /// `?action=favorites_sync` — reconciles a batch of LOCALLY known
    /// favourites with the server. Requires auth.
    ///
    /// DETAILED: `mode: .merge` (WS-G #1019's "first-login backfill" — union,
    /// nothing deleted) is what THIS client sends for every #181 use: adding
    /// one favourite (a one-element batch) and reconciling the offline queue
    /// (see `AppRootViewModel+Favorites.swift`). `mode: .replace` (delete
    /// anything absent from the payload) exists on this factory for
    /// completeness/future use — e.g. a future "push my whole local list,
    /// authoritatively" resync — but no #181 call site uses it, since a
    /// per-toggle sync should never risk deleting a DIFFERENT favourite this
    /// exact call doesn't happen to mention.
    ///
    /// - Throws: Only if `JSONEncoder` itself fails (same practically
    ///   -unreachable case `Endpoint.authLogin(username:password:)` already
    ///   documents — plain `String`/`Bool`/array fields, no dates).
    public static func favoritesSync(mode: FavoritesSyncMode, favorites: [FavoriteEntry]) throws -> Endpoint {
        let body = try JSONEncoder().encode(FavoritesSyncRequestBody(mode: mode.rawValue, favorites: favorites))
        return Endpoint(action: "favorites_sync", requiresAuth: true, httpMethod: "POST", httpBody: body)
    }

    /// `?action=favorites_remove` — removes ONE song from the signed-in
    /// user's favourites. Requires auth.
    ///
    /// - Throws: Only if `JSONEncoder` fails (see `favoritesSync` above).
    public static func favoritesRemove(songId: SongID) throws -> Endpoint {
        let body = try JSONEncoder().encode(FavoritesRemoveRequestBody(songId: songId.rawValue))
        return Endpoint(action: "favorites_remove", requiresAuth: true, httpMethod: "POST", httpBody: body)
    }
}

/// `favorites_sync`'s `mode` field — see that endpoint's doc comment for
/// which one this client actually sends.
public enum FavoritesSyncMode: String, Sendable {
    case merge
    case replace
}

/// The exact wire shape `favorites_sync`'s JSON body expects — a private
/// implementation detail of `Endpoint.favoritesSync(mode:favorites:)` above.
private struct FavoritesSyncRequestBody: Encodable {
    let mode: String
    let favorites: [FavoriteEntry]
}

/// The exact wire shape `favorites_remove`'s JSON body expects (`{"song_id":
/// "MP-1008"}`) — a private implementation detail of
/// `Endpoint.favoritesRemove(songId:)` above.
private struct FavoritesRemoveRequestBody: Encodable {
    let songId: String

    private enum CodingKeys: String, CodingKey {
        case songId = "song_id"
    }
}

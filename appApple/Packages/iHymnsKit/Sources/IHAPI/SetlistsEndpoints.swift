// SetlistsEndpoints.swift
// IHAPI
//
// ELI5: The recipe cards for the four setlist calls — "give me all my
// setlists," "sync a batch," "make (or update) a public share link," and
// "look up a share link" — mirroring `FavoritesEndpoints.swift`'s exact
// shape for the first two, plus two new ones for sharing.
//
// DETAILED: Verified field-for-field against the LIVE
// `appWeb/public_html/api.php` handlers (`case 'user_setlists'` (GET) /
// `case 'user_setlists_sync'` (POST) / `case 'setlist_share'` (POST) /
// `case 'setlist_get'` (GET), read 2026-07-07) — see `IHModels/Setlist.swift`
// and `IHModels/SharedSetlist.swift` for the full payload-shape reasoning.
//
// `requiresAuth` per endpoint (this is a DELIBERATE per-app-flow choice, not
// a blind mirror of the server's own `security:` gate — see each factory's
// own doc comment):
//   - `userSetlists` / `setlistsSync` — the server itself REQUIRES a bearer
//     token (`getAuthenticatedUser()` 401s without one); `requiresAuth: true`
//     here matches that exactly.
//   - `shareSetlist` — the server does NOT strictly require auth (anonymous
//     web visitors can share too), but THIS app always sends the token
//     anyway: only an authenticated request whose `setlistId` the caller
//     provably owns gets the LIVE-share behaviour the owner's brief asks
//     for ("owner edits reflect to link-holders") — an anonymous share would
//     silently degrade to a frozen snapshot instead. Since every Setlists
//     screen in this app is already sign-in-gated (mirroring Favourites),
//     requiring auth here costs nothing and buys the live-link guarantee.
//   - `sharedSetlist` (`setlist_get`) — the server has NO auth check at all
//     for this one (`api.php`'s handler never calls
//     `getAuthenticatedUser()`) — it's how ANYONE who receives a share link,
//     signed in or not, opens it. `requiresAuth: false` here matches the
//     real contract, not the other three's pattern.
import Foundation
import IHModels

extension Endpoint {
    /// `?action=user_setlists` — every setlist the signed-in user owns.
    ///
    /// ELI5: "What are my setlists?"
    public static let userSetlists = Endpoint(action: "user_setlists", requiresAuth: true)

    /// `?action=user_setlists_sync` — reconciles `setlists` with the server
    /// under `mode` (see `SetlistSyncMode`'s own doc comment).
    ///
    /// ELI5: "Here's the state of my setlists on this device — reconcile
    /// that with the server."
    ///
    /// - Throws: Only if `JSONEncoder` itself fails (same practically
    ///   -unreachable case `Endpoint.favoritesSync(mode:favorites:)`
    ///   documents — plain `String`/`Int`/array fields, no `Date`).
    public static func setlistsSync(mode: SetlistSyncMode, setlists: [Setlist]) throws -> Endpoint {
        let body = try JSONEncoder().encode(SetlistsSyncRequestBody(mode: mode.rawValue, setlists: setlists))
        return Endpoint(action: "user_setlists_sync", requiresAuth: true, httpMethod: "POST", httpBody: body)
    }

    /// `?action=setlist_share` — creates a NEW share (when `existingShareId`
    /// is `nil`) or updates an existing one (when it isn't), and — because
    /// this app always calls it authenticated with `setlist.id` set — links
    /// it LIVE to `setlist` (see this file's header).
    ///
    /// ELI5: "Publish (or refresh) a public link for this setlist."
    ///
    /// - Parameters:
    ///   - setlist: The setlist to share. `setlist.id` is sent as the
    ///     request's `setlistId` field — the live-link source `api.php`
    ///     proves ownership of via the caller's bearer token.
    ///   - ownerId: A client-generated UUID string for the wire's REQUIRED
    ///     (even for an authenticated live share) legacy `owner` field — see
    ///     `APIClient.shareSetlist(_:existingShareId:)`'s own doc comment for
    ///     why a FRESH one is safe to mint on every call for an
    ///     authenticated caller.
    ///   - existingShareId: The share id to UPDATE, if this is a re-share
    ///     of an already-shared setlist (keeps the same public URL alive
    ///     rather than minting a second one every time the owner edits it).
    /// - Throws: Only if `JSONEncoder` fails (see `setlistsSync` above).
    public static func shareSetlist(
        _ setlist: Setlist,
        ownerId: String,
        existingShareId: String? = nil
    ) throws -> Endpoint {
        let songs = setlist.songs.map { $0.songId.rawValue }
        var arrangements: [String: [Int]] = [:]
        for entry in setlist.songs {
            if let arrangement = entry.arrangement, !arrangement.isEmpty {
                arrangements[entry.songId.rawValue] = arrangement
            }
        }
        let body = try JSONEncoder().encode(
            ShareSetlistRequestBody(
                name: setlist.name,
                songs: songs,
                owner: ownerId,
                id: existingShareId,
                arrangements: arrangements.isEmpty ? nil : arrangements,
                setlistId: setlist.id
            )
        )
        return Endpoint(action: "setlist_share", requiresAuth: true, httpMethod: "POST", httpBody: body)
    }

    /// `?action=setlist_get&id=…` — looks up a share by its 8-character hex
    /// id. No auth (see this file's header).
    ///
    /// ELI5: "Open this shared setlist link."
    public static func sharedSetlist(shareId: String) -> Endpoint {
        Endpoint(action: "setlist_get", queryItems: [(name: "id", value: shareId)], requiresAuth: false)
    }
}

/// `user_setlists_sync`'s `mode` field — mirrors `FavoritesSyncMode`'s
/// shape, but with the OPPOSITE default meaning at the call-site level:
/// unlike favourites (which only ever sends `.merge`, because a dedicated
/// `favorites_remove` endpoint handles deletion), setlists have NO
/// per-item delete endpoint — deleting a setlist is only possible by
/// syncing the FULL remaining list under `.replace` (see
/// `AppRootViewModel+Setlists.swift`'s `pushSetlistsToServer()`).
public enum SetlistSyncMode: String, Sendable {
    case merge
    case replace
}

/// The exact wire shape `user_setlists_sync`'s JSON body expects — a
/// private implementation detail of `Endpoint.setlistsSync(mode:setlists:)`.
private struct SetlistsSyncRequestBody: Encodable {
    let mode: String
    let setlists: [Setlist]
}

/// The exact wire shape `setlist_share`'s JSON body expects — a private
/// implementation detail of `Endpoint.shareSetlist(_:ownerId:existingShareId:)`.
private struct ShareSetlistRequestBody: Encodable {
    let name: String
    let songs: [String]
    let owner: String
    let id: String?
    let arrangements: [String: [Int]]?
    let setlistId: String?
}

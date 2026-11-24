// SetlistsDecoding.swift
// IHAPI
//
// ELI5: Turns the raw bytes the setlist calls send back into the
// `[Setlist]`/`ShareSetlistResult`/`SharedSetlist` Swift values the rest of
// the app uses.
//
// DETAILED: Mirrors `FavoritesDecoding.swift`'s pattern (a `nonisolated
// static` decode function on `APIClient`, independently unit-testable with
// zero networking). Verified against `api.php`'s `sendJson(['setlists' =>
// $setlists])` (both the `case 'user_setlists'` and `case
// 'user_setlists_sync'` handlers wrap their array in the SAME `{"setlists":
// [...]}` envelope, exactly like favourites' shared envelope), plus the
// bare-object `setlist_share`/`setlist_get` response bodies (2026-07-07).
import Foundation
import IHModels

extension APIClient {
    /// Decodes a `user_setlists` GET or `user_setlists_sync` POST response
    /// body into `[Setlist]`.
    ///
    /// - Parameter data: The raw HTTP response body — `{"setlists": [...]}`.
    /// - Throws: `APIError.decoding` on any shape mismatch. NOT lossy (unlike
    ///   `decodeSongsIndex`'s per-row tolerance) — a user's setlist count is
    ///   server-capped at 50, and every row was itself server-validated
    ///   before being stored, so a decode failure here means genuine
    ///   contract drift, not "one bad row among thousands" (same reasoning
    ///   `decodeFavorites` documents).
    nonisolated public static func decodeSetlists(from data: Data) throws -> [Setlist] {
        struct Envelope: Decodable {
            let setlists: [Setlist]
        }
        do {
            return try JSONDecoder().decode(Envelope.self, from: data).setlists
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a `user_setlists_sync` POST response body into a
    /// `SetlistSyncResult` — the setlists plus the protocol-2 extras
    /// (`syncedAt`, `tombstones`, `conflicts`).
    ///
    /// ELI5: "Read the server's answer to a sync, including the time stamp
    /// to quote next time and any deletions or refusals."
    ///
    /// - Parameter data: The raw HTTP response body.
    /// - Throws: `APIError.decoding` when `setlists` is missing or malformed.
    ///   The three extras are optional: an older server, or a paused site's
    ///   read-only answer (`syncedAt: null`), still decodes.
    nonisolated public static func decodeSetlistSyncResult(from data: Data) throws -> SetlistSyncResult {
        struct Envelope: Decodable {
            let setlists: [Setlist]
            let syncedAt: String?
            let tombstones: [SetlistTombstone]?
            let conflicts: [SetlistSyncConflict]?
        }
        do {
            let envelope = try JSONDecoder().decode(Envelope.self, from: data)
            return SetlistSyncResult(
                setlists: envelope.setlists,
                syncedAt: envelope.syncedAt,
                tombstones: envelope.tombstones ?? [],
                conflicts: envelope.conflicts ?? []
            )
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a `setlist_share` response body — `{"id": "...", "url": "..."}`.
    nonisolated public static func decodeShareSetlistResult(from data: Data) throws -> ShareSetlistResult {
        do {
            return try JSONDecoder().decode(ShareSetlistResult.self, from: data)
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a `setlist_get` response body into a `SharedSetlist`.
    nonisolated public static func decodeSharedSetlist(from data: Data) throws -> SharedSetlist {
        do {
            return try JSONDecoder().decode(SharedSetlist.self, from: data)
        } catch {
            throw APIError.decoding
        }
    }
}

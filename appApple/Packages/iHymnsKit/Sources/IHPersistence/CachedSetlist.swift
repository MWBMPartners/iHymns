// CachedSetlist.swift
// IHPersistence
//
// ELI5: The "shape" a setlist takes when it's stored in the on-device
// offline database, plus the shape of one QUEUED change ("this setlist needs
// upserting/deleting on the server") waiting to be told to the server once
// the device is back online — the setlist half of #181's offline queue,
// mirroring `CachedFavorite.swift`'s exact pattern for favourites.
//
// DETAILED: Same "adapter at the seam" reasoning as `CachedFavorite`/
// `CachedSongSummary`: `IHModels` stays GRDB-dependency-free (strategy
// §1.2), so this GRDB-facing row type lives here instead, with explicit
// `to`/`from` conversions. `songs` ([SetlistSongEntry]) has no native SQLite
// array/object column type, so it's stored as a JSON-encoded TEXT column
// (`songsJson`) — the same "JSON text column for a small growable list"
// approach `CachedFavorite.tagsJson` already takes, and the web backend's
// own `tblUserSetlists.SongsJson` column takes too.
//
// `localUpdatedAt` (unlike anything on the domain `Setlist` type itself) is
// a PURELY LOCAL bookkeeping column: the server's own `updatedAt` is a raw,
// possibly-`nil`, possibly-stale-format string (`Setlist.swift`'s header),
// unsuitable for ordering a freshly-created-but-not-yet-synced local
// setlist to the TOP of `SetlistsView`'s list. Every local mutation
// (`OfflineStore.upsertSetlist`) stamps this with `Date()`, giving a
// reliable "most recently touched on THIS device" sort key — mirroring
// `CachedFavorite.addedAt`'s identical role for favourites' most-recent-first
// ordering.
import Foundation
import GRDB
import IHModels

/// One row of the on-device `setlist` table.
///
/// ELI5: "Here's one of your setlists — its name, its songs, and when you
/// last touched it on this device."
struct CachedSetlist: Codable, FetchableRecord, PersistableRecord, Sendable, Equatable {
    static let databaseTableName = "setlist"

    /// Primary key — the client-generated `Setlist.id`, so re-upserting an
    /// already-cached setlist naturally replaces rather than duplicating
    /// (mirrors `CachedFavorite.songId`'s identical role).
    var setlistId: String
    var name: String
    var songsJson: String
    var createdAt: String?
    var updatedAt: String?
    var localUpdatedAt: Date

    init(_ setlist: Setlist, localUpdatedAt: Date = Date()) {
        setlistId = setlist.id
        name = setlist.name
        songsJson = Self.encodeSongs(setlist.songs)
        createdAt = setlist.createdAt
        updatedAt = setlist.updatedAt
        self.localUpdatedAt = localUpdatedAt
    }

    /// Converts this database row back into a domain `Setlist`.
    ///
    /// - Returns: `nil` only if `songsJson` somehow fails to decode at all
    ///   (never observed — this package is the only writer of this column) —
    ///   the same defensive "a single corrupt row never takes down the whole
    ///   cache" posture `CachedFavorite.toFavoriteSong()` documents. An
    ///   individual malformed SONG entry within an otherwise-valid array is
    ///   handled one level down by `decodeSongs(_:)`'s own tolerance.
    func toSetlist() -> Setlist? {
        Setlist(id: setlistId, name: name, songs: Self.decodeSongs(songsJson), createdAt: createdAt, updatedAt: updatedAt)
    }

    /// Shared JSON encode used by both this record and `PendingSetlistOp`
    /// below — one place so the two tables' `songsJson` columns can never
    /// silently drift into two different encodings (mirrors
    /// `CachedFavorite.encodeTags`/`decodeTags`'s identical reasoning).
    static func encodeSongs(_ songs: [SetlistSongEntry]) -> String {
        guard let data = try? JSONEncoder().encode(songs), let json = String(data: data, encoding: .utf8) else {
            return "[]"
        }
        return json
    }

    /// The inverse of `encodeSongs(_:)` — malformed/legacy JSON degrades to
    /// `[]` (an empty-but-valid setlist) rather than throwing, matching
    /// `CachedFavorite.decodeTags`'s "cosmetic corruption never blocks the
    /// whole read" stance. `SetlistSongEntry` decoding is itself strict
    /// (`Codable`'s synthesized `init(from:)`), so a genuinely malformed
    /// ARRAY decodes to `[]` here rather than a partially-populated one —
    /// acceptable for a purely local cache mirror that the next successful
    /// server sync will overwrite anyway.
    static func decodeSongs(_ json: String) -> [SetlistSongEntry] {
        guard let data = json.data(using: .utf8), let songs = try? JSONDecoder().decode([SetlistSongEntry].self, from: data) else {
            return []
        }
        return songs
    }
}

/// One QUEUED setlist change waiting to be told to the server — the
/// `setlist_pending_op` table.
///
/// ELI5: "I created/edited/deleted this setlist while offline (or the
/// request just failed) — remember that something changed, and catch the
/// server up the next time we're back online."
///
/// DETAILED: Primary-keyed by `setlistId` (not an autoincrement id), same
/// "only the LATEST intent per entity matters" reasoning `PendingFavoriteOp`
/// documents — editing the same setlist five times while offline queues
/// (via upsert-by-primary-key) exactly ONE replay, not five.
///
/// UNLIKE `PendingFavoriteOp`, though, replaying this queue does NOT dispatch
/// per-row to a per-op server endpoint — the real API has no
/// `setlist_delete`/`setlist_upsert` single-item call, only the bulk
/// `user_setlists_sync`, and only its `.replace` mode can express a
/// deletion at all (an absent setlist IS the deletion signal). So
/// `AppRootViewModel.flushPendingSetlistOps()` (IHFeatures) treats the mere
/// PRESENCE of any row here as "a sync is owed," and replays by pushing the
/// device's full current `setlists` array (which already reflects every
/// queued upsert AND delete, since those were applied to the local cache
/// optimistically the moment they happened) via ONE `mode: .replace` call —
/// success drains the WHOLE queue at once, not row-by-row. `operation` is
/// still recorded per row (useful for tests/observability, and keeps this
/// table symmetrical with `PendingFavoriteOp` per this task's "mirror the
/// favourites offline-queue pattern" brief) even though replay doesn't
/// branch on it. `public` — `IHFeatures.AppRootViewModel+Setlists.swift`
/// constructs/reads these directly, same cross-module visibility reasoning
/// `PendingFavoriteOp` documents.
public struct PendingSetlistOp: Codable, FetchableRecord, PersistableRecord, Sendable, Equatable {
    public static let databaseTableName = "setlist_pending_op"

    public var setlistId: String
    var operation: String
    var name: String
    var songsJson: String
    var createdAt: String?
    var updatedAt: String?
    var queuedAt: Date

    public init(setlistId: String, operation: PendingSetlistOperationKind, snapshot: Setlist?, queuedAt: Date = Date()) {
        self.setlistId = setlistId
        self.operation = operation.rawValue
        self.name = snapshot?.name ?? ""
        self.songsJson = CachedSetlist.encodeSongs(snapshot?.songs ?? [])
        self.createdAt = snapshot?.createdAt
        self.updatedAt = snapshot?.updatedAt
        self.queuedAt = queuedAt
    }

    /// The typed form of `operation` — `nil` only for a value from a FUTURE
    /// app version's vocabulary this build doesn't know about yet (same
    /// defensive stance `PendingFavoriteOp.kind` documents).
    public var kind: PendingSetlistOperationKind? {
        PendingSetlistOperationKind(rawValue: operation)
    }
}

/// What a queued setlist change means — recorded for symmetry/observability
/// even though replay itself is a single bulk push (see `PendingSetlistOp`'s
/// header). `public` for the same cross-module reason `PendingSetlistOp` is.
public enum PendingSetlistOperationKind: String, Sendable {
    case upsert
    case delete
}

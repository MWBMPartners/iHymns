// CachedFavorite.swift
// IHPersistence
//
// ELI5: The "shape" a favourited song takes when it's stored in the
// on-device offline database, plus the shape of one QUEUED action ("add
// this" / "remove this") waiting to be told to the server once the device
// is back online — the #181 favourites offline queue.
//
// DETAILED: Mirrors `CachedSongSummary.swift`'s exact "adapter at the seam"
// pattern (a GRDB-facing row type, separate from the `IHModels.FavoriteSong`
// domain type, with explicit `to`/`from` conversions) for the identical
// reason: `IHModels` stays GRDB-dependency-free (strategy §1.2). `tags`
// ([String]) has no native SQLite array column type, so both records store
// it as a JSON-encoded TEXT column (`tagsJson`) — the same "JSON text
// column for a growable small array" approach the web backend's own
// `tblUserFavorites.Tags` column already takes (`.claude/CLAUDE.md`'s
// migration rules are a web/MySQL convention, not binding here, but the
// underlying reasoning — a per-favourite tag LIST doesn't need its own
// join table for a handful of short strings — applies just as well to this
// on-device SQLite file).
import Foundation
import GRDB
import IHModels

/// One row of the on-device `favorite` table — a favourited song, cached
/// with just enough display metadata to render a `FavoritesView` row
/// without a network round trip.
///
/// ELI5: "You favourited this song — here's its title, songbook, and tags,
/// saved locally."
struct CachedFavorite: Codable, FetchableRecord, PersistableRecord, Sendable, Equatable {
    static let databaseTableName = "favorite"

    /// The full `<letters>-<digits>` SongID string — primary key, so
    /// re-favouriting an already-cached song naturally upserts rather than
    /// duplicating (mirrors `CachedSongSummary.songId`'s identical role).
    var songId: String
    var title: String
    var songbookAbbreviation: String
    var number: Int?
    var tagsJson: String
    var addedAt: Date

    init(_ favorite: FavoriteSong) {
        songId = favorite.songId.rawValue
        title = favorite.title
        songbookAbbreviation = favorite.songbookAbbreviation
        number = favorite.number
        tagsJson = Self.encodeTags(favorite.tags)
        addedAt = favorite.addedAt
    }

    /// Converts this database row back into a domain `FavoriteSong`.
    ///
    /// - Returns: `nil` if `songId` somehow failed to re-parse — the same
    ///   defensive "a single corrupt row never takes down the whole cache"
    ///   posture `CachedSongSummary.toSongSummary()` already documents.
    func toFavoriteSong() -> FavoriteSong? {
        guard let parsedID = SongID(rawValue: songId) else { return nil }
        return FavoriteSong(
            songId: parsedID,
            title: title,
            songbookAbbreviation: songbookAbbreviation,
            number: number,
            tags: Self.decodeTags(tagsJson),
            addedAt: addedAt
        )
    }

    /// Shared JSON encode used by both this record and `PendingFavoriteOp`
    /// below — kept as one place so the two tables' `tagsJson` columns can
    /// never silently drift into two different encodings.
    static func encodeTags(_ tags: [String]) -> String {
        guard let data = try? JSONEncoder().encode(tags), let json = String(data: data, encoding: .utf8) else {
            return "[]"
        }
        return json
    }

    /// The inverse of `encodeTags(_:)` — malformed/legacy JSON degrades to
    /// `[]` rather than throwing, since a favourite's tag list is
    /// cosmetic (never load-bearing for "is this song favourited at all").
    static func decodeTags(_ json: String) -> [String] {
        guard let data = json.data(using: .utf8), let tags = try? JSONDecoder().decode([String].self, from: data) else {
            return []
        }
        return tags
    }
}

/// One QUEUED favourite-toggle action waiting to be told to the server —
/// the `favorite_pending_op` table, strategy §1.5's planned "pending-sync
/// queue" schema piece, first given a concrete shape here for favourites.
///
/// ELI5: "I favourited/un-favourited this song while offline (or the
/// request just failed) — remember what I meant to do, and tell the server
/// the next time we're back online."
///
/// DETAILED: Primary-keyed by `songId` (NOT an autoincrement id) — a
/// deliberate simplification: at most ONE pending action can ever matter
/// per song. If the user taps the heart, goes offline before the sync
/// lands, taps it again (now meaning the OPPOSITE action), and then a third
/// time — only the LAST intent is worth replaying; queuing all three and
/// replaying them in order would do extra, pointless network round trips to
/// arrive at the exact same end state a single "replace the pending op for
/// this song" write already gets to. `AppRootViewModel.toggleFavorite`
/// writes via `INSERT OR REPLACE` semantics (`PersistableRecord`'s default
/// `save(_:)`), so a second toggle for the same song simply overwrites the
/// first queued op rather than appending a second one.
/// `public` (unlike `CachedFavorite` above, which never crosses a module
/// boundary) — `IHFeatures.AppRootViewModel+Favorites.swift`'s
/// `flushPendingFavoriteOps()` constructs/reads these directly, so this
/// type (and the handful of members it actually touches: the custom
/// `init`, `songId`, `kind`, `favoriteToReplay()`) must be visible outside
/// this module. Every OTHER stored property stays `internal` — nothing
/// outside `IHPersistence` needs `operation`/`title`/`songbookAbbreviation`/
/// `number`/`tagsJson`/`createdAt` directly, and GRDB's `Codable`-derived
/// `FetchableRecord`/`PersistableRecord` synthesis works fine against
/// internal stored properties (synthesis happens within this module,
/// regardless of individual property access levels).
public struct PendingFavoriteOp: Codable, FetchableRecord, PersistableRecord, Sendable, Equatable {
    public static let databaseTableName = "favorite_pending_op"

    public var songId: String

    /// `PendingFavoriteOperationKind.rawValue` — stored as `TEXT`, not an
    /// `INTEGER`/native enum column, matching this package's "open
    /// vocabulary stays a string" posture (mirrors `.claude/CLAUDE.md` rule
    /// #20's web-side ENUM-vs-VARCHAR reasoning, applied here even though
    /// this is a two-case, unlikely-to-grow set — consistency over a
    /// premature enum-column optimisation).
    var operation: String

    /// Display metadata to replay an `.add` — irrelevant (but still stored,
    /// simplest schema) for a `.remove`, which only needs `songId`.
    var title: String
    var songbookAbbreviation: String
    var number: Int?
    var tagsJson: String
    var createdAt: Date

    public init(songId: SongID, operation: PendingFavoriteOperationKind, favorite: FavoriteSong?, createdAt: Date = Date()) {
        self.songId = songId.rawValue
        self.operation = operation.rawValue
        self.title = favorite?.title ?? ""
        self.songbookAbbreviation = favorite?.songbookAbbreviation ?? ""
        self.number = favorite?.number
        self.tagsJson = CachedFavorite.encodeTags(favorite?.tags ?? [])
        self.createdAt = createdAt
    }

    /// Reconstructs the `FavoriteSong` this queued `.add` should replay with
    /// — `nil` for a `.remove` (nothing to add) or a malformed `songId`.
    public func favoriteToReplay() -> FavoriteSong? {
        guard kind == .add, let parsedID = SongID(rawValue: songId) else { return nil }
        return FavoriteSong(
            songId: parsedID,
            title: title,
            songbookAbbreviation: songbookAbbreviation,
            number: number,
            tags: CachedFavorite.decodeTags(tagsJson),
            addedAt: createdAt
        )
    }

    /// The typed form of `operation` — `nil` only if this row somehow holds
    /// a value from a FUTURE app version's vocabulary this build doesn't
    /// know about yet; callers treat that defensively as "nothing to do"
    /// rather than crashing (see `OfflineStore.flushPendingFavoriteOps` in
    /// the primary file, which skips — never force-unwraps — such a row).
    public var kind: PendingFavoriteOperationKind? {
        PendingFavoriteOperationKind(rawValue: operation)
    }
}

/// What a queued favourite action means to replay. `public` for the same
/// cross-module reason `PendingFavoriteOp` above is.
public enum PendingFavoriteOperationKind: String, Sendable {
    case add
    case remove
}

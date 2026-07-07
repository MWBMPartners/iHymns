// CachedSongDetail.swift
// IHPersistence
//
// ELI5: The "shape" a WHOLE song (not just its business-card summary) takes
// when it's saved on the device for offline reading — lyrics and all — plus
// a small "receipt" shape (`SavedSongInfo`) the Storage & Offline screen
// lists without having to unpack every song's full lyrics just to show a
// title and a size.
//
// DETAILED: #187 (offline support + data management). Mirrors
// `CachedSongSummary.swift`'s exact "adapter at the seam" pattern — a
// GRDB-facing row type kept separate from the `IHModels.SongDetail` domain
// type, so `IHModels` stays GRDB-dependency-free (strategy §1.2:
// "IHModels — zero deps"). Unlike `CachedSongSummary`/`CachedFavorite`
// (which explode a handful of fields into their own columns), this type
// stores the ENTIRE `SongDetail` as one encoded JSON blob (`detailData`) —
// `SongDetail` already has ~28 properties and is already `Codable`
// (`SongDetail.swift`'s own header), so re-declaring every one of them as a
// SQL column here would be pure repetition with zero query benefit: nothing
// in this app ever needs to `WHERE` on a saved song's copyright text or its
// writers list, it only ever needs "give me the WHOLE song back, by id" —
// exactly what a single blob column serves. `title`/`songbookAbbreviation`
// stay as their OWN columns anyway, purely so the data-management list (see
// `SavedSongInfo` below) can be built from a lightweight SQL projection
// without decoding every row's full JSON blob first.
import Foundation
import GRDB
import IHModels

/// A single row of the on-device `saved_song` table (#187) — one song a
/// user explicitly chose to keep readable offline, full lyrics included.
///
/// ELI5: A saved song, packed up ready to store on the device.
struct CachedSongDetail: Codable, FetchableRecord, PersistableRecord, Sendable, Equatable {
    /// GRDB's `PersistableRecord` wants a stable table name; without this
    /// override it would infer `"cachedSongDetail"` from the Swift type
    /// name, matching `CachedSongSummary`'s identical override.
    static let databaseTableName = "saved_song"

    /// The full `<letters>-<digits>` SongID string — primary key, so
    /// saving an already-saved song naturally upserts rather than
    /// duplicating (mirrors `CachedSongSummary.songId`'s identical role).
    var songId: String

    /// Denormalised from the encoded `detailData` purely so the
    /// data-management list can read title/songbook without decoding every
    /// row's full JSON — see this file's header.
    var title: String
    var songbookAbbreviation: String

    /// The complete `SongDetail`, JSON-encoded — see this file's header for
    /// why this is one blob rather than ~28 exploded columns.
    var detailData: Data

    /// When THIS DEVICE saved (or last re-saved, on a repeat save) the
    /// song — drives `allSavedSongs()`'s most-recently-saved-first order,
    /// mirroring `CachedSetlist.localUpdatedAt`'s identical "local
    /// bookkeeping column" role.
    var savedAt: Date

    /// Builds a database row from a domain `SongDetail`.
    ///
    /// - Throws: Only if `JSONEncoder` somehow fails on an already-decoded,
    ///   already-`Codable`-conformant `SongDetail` value — practically
    ///   unreachable, but `saveSongForOffline(_:)` propagates it rather than
    ///   silently swallowing a genuine encode failure.
    init(_ detail: SongDetail, savedAt: Date = Date()) throws {
        songId = detail.songId.rawValue
        title = detail.title
        songbookAbbreviation = detail.songbookAbbreviation
        detailData = try JSONEncoder().encode(detail)
        self.savedAt = savedAt
    }

    /// Converts this row's `detailData` blob back into a domain
    /// `SongDetail`.
    ///
    /// - Returns: `nil` if the blob somehow fails to decode (a future app
    ///   version changing `SongDetail`'s shape in a non-additive way, or
    ///   genuine on-disk corruption) — the same defensive "a single corrupt
    ///   row never takes down the whole cache" posture
    ///   `CachedSongSummary.toSongSummary()` already documents. The caller
    ///   (`OfflineStore.savedSongDetail(_:)`) treats this exactly like "not
    ///   saved at all" rather than throwing.
    func toSongDetail() -> SongDetail? {
        try? JSONDecoder().decode(SongDetail.self, from: detailData)
    }
}

/// A lightweight "receipt" for one offline-saved song — everything the
/// Storage & Offline data-management screen (#187) needs to list a saved
/// song and let the user remove it, WITHOUT decoding that song's full
/// lyrics JSON just to render one list row.
///
/// ELI5: "You saved this song, on this date, and it takes up about this
/// much space" — the label on the outside of the box, not the box's
/// contents.
///
/// DETAILED: `public` (unlike `CachedSongDetail` above, which never crosses
/// the `IHPersistence` module boundary) — `IHFeatures.OfflineStorageViewModel`
/// reads these directly, the same cross-module-visibility precedent
/// `PendingFavoriteOp`/`PendingSetlistOp` already establish in
/// `CachedFavorite.swift`/`CachedSetlist.swift`. `Identifiable` via
/// `songId` so SwiftUI's `List`/`ForEach` can key rows on it directly.
public struct SavedSongInfo: Sendable, Hashable, Identifiable {
    public var id: SongID { songId }

    public let songId: SongID
    public let title: String
    public let songbookAbbreviation: String
    public let savedAt: Date

    /// The encoded `SongDetail` blob's size in bytes — computed server
    /// -side (in SQL, via `LENGTH(detailData)`, see
    /// `OfflineStore.allSavedSongs()`) so listing every saved song never
    /// requires loading every song's full lyrics JSON into memory just to
    /// measure it.
    public let sizeBytes: Int

    public init(songId: SongID, title: String, songbookAbbreviation: String, savedAt: Date, sizeBytes: Int) {
        self.songId = songId
        self.title = title
        self.songbookAbbreviation = songbookAbbreviation
        self.savedAt = savedAt
        self.sizeBytes = sizeBytes
    }
}

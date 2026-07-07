// OfflineStore+SavedSongs.swift
// IHPersistence
//
// ELI5: Everything about "save this whole song so I can read it with no
// internet, forget it again, and tell me what I've already saved and how
// much room it's using" — the #187 offline-support-and-data-management
// half, split into its own file for the same reason
// `OfflineStore+Setlists.swift` already documents: `actor` extensions in a
// separate file stay isolated exactly like methods declared in the primary
// file, and this keeps `OfflineStore.swift` itself from re-growing past the
// repo's LOC-budget tripwire.
//
// DETAILED: Mirrors `OfflineStore.swift`'s existing favourites methods'
// shape (`allFavorites`→`allSavedSongs`, `upsertFavorite`→`saveSongForOffline`,
// `removeFavorite`→`removeSavedSong`, `clearFavorites`→`removeAllSavedSongs`)
// with two genuinely NEW pieces this family needs that favourites/setlists
// don't: `isSongSaved(_:)` (a cheap existence check `SongDetailToolbar`'s
// save/saved toggle polls on every song-screen visit, so it deliberately
// never decodes a row's full lyrics JSON just to answer "yes/no") and
// `totalSavedSongBytes()` (the Storage & Offline screen's headline number).
// Both — plus `allSavedSongs()` below — lean on SQL doing the size/existence
// arithmetic (`COUNT`/`LENGTH`/`SUM`) rather than fetching full
// `CachedSongDetail` rows into Swift memory just to measure them; see
// `CachedSongDetail.swift`'s header for why the full record is one BLOB
// column in the first place.
import Foundation
import GRDB
import IHModels

extension OfflineStore {
    // MARK: - Saved songs (#187)

    /// Saves (or re-saves, upserting) `detail`'s FULL record for offline
    /// reading — `SongDetailToolbar`'s "Save for Offline" action calls this
    /// via `AppRootViewModel.saveSongForOffline(_:)` once the song has
    /// already loaded successfully over the network.
    ///
    /// ELI5: "Keep a full copy of this song on the device."
    ///
    /// - Parameter savedAt: Defaults to `Date()`; overridable purely so
    ///   tests can prove `allSavedSongs()`'s most-recently-saved-first
    ///   ordering deterministically, mirroring
    ///   `upsertSetlist(_:localUpdatedAt:)`'s identical injected-clock
    ///   reasoning.
    public func saveSongForOffline(_ detail: SongDetail, savedAt: Date = Date()) async throws {
        let row = try CachedSongDetail(detail, savedAt: savedAt)
        try await dbQueue.write { database in
            try row.insert(database, onConflict: .replace)
        }
    }

    /// Removes one saved song by id — a no-op (not an error) if it was
    /// never saved at all, matching `removeFavorite(_:)`'s identical
    /// idempotent-delete posture.
    ///
    /// ELI5: "Forget the offline copy of this song."
    public func removeSavedSong(_ songId: SongID) async throws {
        _ = try await dbQueue.write { database in
            try CachedSongDetail.deleteOne(database, key: songId.rawValue)
        }
    }

    /// Whether `songId` currently has a saved offline copy — a cheap
    /// `COUNT`-only existence check (never decodes/loads the row's lyrics
    /// blob) so `SongDetailViewModel` can poll this on every song-screen
    /// visit without measurable cost.
    ///
    /// ELI5: "Have I already saved this song?"
    public func isSongSaved(_ songId: SongID) async throws -> Bool {
        try await dbQueue.read { database in
            try CachedSongDetail.filter(key: songId.rawValue).fetchCount(database) > 0
        }
    }

    /// Fetches one saved song's FULL record, if it has one — the offline
    /// read-fallback `SongDetailViewModel.loadPrimaryDetail()` reaches for
    /// when the network fetch itself fails with `.offline`/`.maintenance`.
    ///
    /// ELI5: "Give me back the full copy of this song I saved earlier."
    ///
    /// - Returns: `nil` when the song was never saved, OR its stored blob
    ///   somehow fails to decode (`CachedSongDetail.toSongDetail()`'s own
    ///   defensive posture) — either way, the caller treats this exactly
    ///   like "no offline copy available."
    public func savedSongDetail(_ songId: SongID) async throws -> SongDetail? {
        let row = try await dbQueue.read { database in
            try CachedSongDetail.fetchOne(database, key: songId.rawValue)
        }
        return row?.toSongDetail()
    }

    /// Every saved song, most-recently-saved first — the Storage & Offline
    /// data-management screen's list, and `SavedSongInfo.sizeBytes`'s
    /// source. Built from a lightweight SQL projection
    /// (`SELECT ... LENGTH(detailData) AS sizeBytes ...`) rather than
    /// `CachedSongDetail.fetchAll(database)` — see this file's header for
    /// why: decoding every saved song's full lyrics JSON just to render a
    /// title/songbook/size list row would be pure waste.
    ///
    /// ELI5: "What have I saved for offline reading?" — answered without
    /// re-reading every song's actual lyrics.
    public func allSavedSongs() async throws -> [SavedSongInfo] {
        let rows = try await dbQueue.read { database in
            try SavedSongProjection.fetchAll(
                database,
                sql: """
                    SELECT songId, title, songbookAbbreviation, savedAt, LENGTH(detailData) AS sizeBytes
                    FROM saved_song
                    ORDER BY savedAt DESC
                    """
            )
        }
        return rows.compactMap { $0.toSavedSongInfo() }
    }

    /// How many songs are currently saved for offline reading — a plain
    /// `COUNT(*)`, same "never load the blobs just to count them" posture
    /// as `isSongSaved(_:)` above.
    public func savedSongCount() async throws -> Int {
        try await dbQueue.read { database in
            try CachedSongDetail.fetchCount(database)
        }
    }

    /// The total on-disk size, in bytes, of every saved song's encoded
    /// lyrics blob — the Storage & Offline screen's headline "Total Size"
    /// figure. A single `SUM(LENGTH(...))` aggregate query, not a Swift-side
    /// reduce over fetched rows — same reasoning as `allSavedSongs()` above.
    ///
    /// ELI5: "How much space do my saved songs take up?"
    public func totalSavedSongBytes() async throws -> Int {
        try await dbQueue.read { database in
            try Int.fetchOne(database, sql: "SELECT COALESCE(SUM(LENGTH(detailData)), 0) FROM saved_song") ?? 0
        }
    }

    /// Deletes every saved song in one shot — "Remove All Downloads"'
    /// confirmed action on the Storage & Offline screen.
    ///
    /// ELI5: "Forget every offline copy I've saved."
    public func removeAllSavedSongs() async throws {
        _ = try await dbQueue.write { database in
            try CachedSongDetail.deleteAll(database)
        }
    }
}

/// A `saved_song` row projected down to exactly what `allSavedSongs()`
/// needs — deliberately NOT `CachedSongDetail` itself, so the `LENGTH(...)`
/// -aliased `sizeBytes` column can decode straight into a plain `Int`
/// without ever pulling the real `detailData` BLOB bytes across the
/// database boundary. GRDB's `FetchableRecord` gains a default `Row`-based
/// decoder for any `Decodable` conformer (this type doesn't need
/// `PersistableRecord` — it's read-only, sourced from a hand-written SQL
/// `SELECT`, never written back).
private struct SavedSongProjection: FetchableRecord, Decodable {
    let songId: String
    let title: String
    let songbookAbbreviation: String
    let savedAt: Date
    let sizeBytes: Int

    /// Converts this projection row into the public `SavedSongInfo` domain
    /// shape `OfflineStore.allSavedSongs()` returns.
    ///
    /// - Returns: `nil` if `songId` somehow failed to re-parse — the same
    ///   defensive "a single corrupt row never takes down the whole list"
    ///   posture `CachedSongSummary.toSongSummary()` documents.
    func toSavedSongInfo() -> SavedSongInfo? {
        guard let parsedID = SongID(rawValue: songId) else { return nil }
        return SavedSongInfo(
            songId: parsedID,
            title: title,
            songbookAbbreviation: songbookAbbreviation,
            savedAt: savedAt,
            sizeBytes: sizeBytes
        )
    }
}

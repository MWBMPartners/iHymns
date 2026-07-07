// CachedSongSummary.swift
// IHPersistence
//
// ELI5: The "shape" one row takes when a song's summary is stored in the
// on-device offline database, instead of just living in memory.
//
// DETAILED: Deliberately a SEPARATE type from `IHModels.SongSummary` rather
// than making `SongSummary` itself conform to GRDB's `FetchableRecord`/
// `PersistableRecord`. This keeps IHModels dependency-free (strategy §1.2:
// "IHModels — zero deps") — GRDB is an implementation detail of the
// offline-cache layer, not a concern the domain model layer should know
// about. `CachedSongSummary` mirrors `IHModels.SongID`/`SongSummary`'s
// fields in a flat, SQL-column-friendly shape and provides explicit
// `to`/`from` conversions at the IHPersistence boundary — the same
// "adapter at the seam" idea as `SongID`'s Codable boundary in IHModels.
import Foundation
import GRDB
import IHModels

/// A single row of the on-device `song_summary` cache table (strategy
/// §1.5's `song_index` schema entry — named `song_summary` here to match
/// the `SongSummary` domain type it mirrors).
///
/// ELI5: One song's "business card," but shaped for the local database
/// instead of for JSON.
struct CachedSongSummary: Codable, FetchableRecord, PersistableRecord, Sendable, Equatable {
    /// GRDB's `PersistableRecord` wants a stable table name; without this
    /// override it would infer `"cachedSongSummary"` from the Swift type
    /// name, which is harder to read in raw SQL / migrations.
    static let databaseTableName = "song_summary"

    /// The full `<letters>-<digits>` SongID string (e.g. `"MP-1008"`) —
    /// stored as the primary key so `INSERT OR REPLACE` naturally
    /// deduplicates by song identity.
    var songId: String
    var title: String
    var songbookAbbreviation: String
    var displayNumber: String

    /// Builds a database row from a domain `SongSummary`.
    ///
    /// ELI5: Turns the "business card" into a database row ready to save.
    ///
    /// DETAILED: Persists `SongSummary.displayNumber` (the ready-to-render
    /// `String`, `""` when there is no number) rather than the raw
    /// `number: Int?` — keeps this table's column shape/migration
    /// (`OfflineStore.makeMigrator()`'s `v1CreateSongSummary`) unchanged
    /// across the #1396 DTO update. `toSongSummary()` below round-trips
    /// `""` back to `nil` via `Int("")` failing, so no information is lost.
    /// The songbook-name/language/hasAudio/hasSheetMusic/publicId fields
    /// #1396 added to `SongSummary` aren't cached here yet — extending this
    /// table's schema is tracked as its own follow-up (IHPersistence is out
    /// of scope for #1396/#1397), so `toSongSummary()` reconstructs them
    /// via `SongSummary.init`'s defaults for now.
    init(_ summary: SongSummary) {
        self.songId = summary.songId.rawValue
        self.title = summary.title
        self.songbookAbbreviation = summary.songbookAbbreviation
        self.displayNumber = summary.displayNumber
    }

    /// Converts this database row back into a domain `SongSummary`.
    ///
    /// ELI5: Turns the database row back into the "business card" the rest
    /// of the app understands.
    ///
    /// - Returns: `nil` if `songId` somehow failed to parse (should never
    ///   happen for a row this same module wrote, but `SongID.init?` is
    ///   failable, so this stays honest about that rather than
    ///   force-unwrapping).
    func toSongSummary() -> SongSummary? {
        guard let parsedID = SongID(rawValue: songId) else { return nil }
        return SongSummary(
            songId: parsedID,
            title: title,
            songbookAbbreviation: songbookAbbreviation,
            number: Int(displayNumber)
        )
    }
}

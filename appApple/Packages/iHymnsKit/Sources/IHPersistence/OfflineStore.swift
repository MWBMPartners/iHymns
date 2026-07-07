// OfflineStore.swift
// IHPersistence
//
// ELI5: The app's own little offline notebook — it remembers song
// summaries on the device so search/browsing still works with no internet,
// and so re-launching the app doesn't need to re-download everything.
//
// DETAILED: This is the Phase-0 skeleton of the GRDB-backed offline cache
// described in strategy §1.5: "Offline cache = GRDB (pinned)... real
// migrations; Swift 6 Sendable; all six platforms." An `actor` wrapping a
// GRDB `DatabaseQueue` (single-connection, serializes all access —
// `DatabasePool`, GRDB's multi-reader/single-writer variant, is the
// Phase-1 upgrade once concurrent read load justifies it; strategy §1.3
// specifically calls out "`actor OfflineStore` over GRDB `DatabasePool`" as
// the eventual shape). Every table this cache will ever need (song index +
// FTS5, saved songs, favourites/setlist mirrors, pending-sync queue —
// strategy §1.5's full schema list) grows as ADDITIVE, versioned
// migrations registered in `makeMigrator()` — never a destructive
// `DROP`/`recreate`, mirroring the web backend's own migration discipline
// (`.claude/CLAUDE.md` rule #19/#20) even though this is a client-local
// SQLite file, not the shared MySQL database.
//
// See GRDB's own migrations guide:
// https://swiftpackageindex.com/groue/grdb.swift/documentation/grdb/migrations.
import Foundation
import GRDB
import IHModels

/// The on-device offline cache, backed by a single SQLite database file (or
/// an in-memory database for tests/previews).
///
/// ELI5: Ask it to remember some songs, or ask it what it already
/// remembers.
public actor OfflineStore {
    private let dbQueue: DatabaseQueue

    /// Opens (creating if necessary) the offline database at `path`, or an
    /// ephemeral in-memory database when `path` is `nil` (used by unit
    /// tests and SwiftUI previews so nothing touches disk).
    ///
    /// - Parameter path: Absolute file path for the SQLite database, or
    ///   `nil` for an in-memory database.
    public init(path: String? = nil) throws {
        if let path {
            dbQueue = try DatabaseQueue(path: path)
        } else {
            dbQueue = try DatabaseQueue()
        }
        try Self.makeMigrator().migrate(dbQueue)
    }

    /// Registers every schema migration this cache has ever had, in order.
    ///
    /// ELI5: The recipe for building (or upgrading) the notebook's pages.
    ///
    /// DETAILED: GRDB's `DatabaseMigrator` runs each registered migration
    /// AT MOST once (tracked in its own bookkeeping table), in registration
    /// order, and only the migrations a given database hasn't already
    /// applied — the exact "idempotent, additive, ordered" discipline
    /// strategy §1.5 asks for. `v1CreateSongSummary` is the only migration
    /// that exists at Phase 0; the FTS5 virtual table + saved-songs/
    /// favourites/pending-sync-queue tables land alongside the real offline
    /// -save feature in Phase 1 (strategy §3.4, "#187 offline —
    /// GRDB+FTS5 + bulk download + staleness").
    private static func makeMigrator() -> DatabaseMigrator {
        var migrator = DatabaseMigrator()

        migrator.registerMigration("v1CreateSongSummary") { database in
            try database.create(table: "song_summary") { table in
                table.primaryKey("songId", .text)
                table.column("title", .text).notNull()
                table.column("songbookAbbreviation", .text).notNull()
                table.column("displayNumber", .text).notNull()
            }
        }

        return migrator
    }

    /// Inserts or replaces a batch of song summaries — the offline mirror
    /// of a `songs_index` API page (strategy §1.5: never a whole-corpus
    /// materialisation; callers page/batch this the same way the network
    /// layer does).
    ///
    /// ELI5: "Remember these songs."
    public func upsert(_ summaries: [SongSummary]) async throws {
        try await dbQueue.write { database in
            for summary in summaries {
                try CachedSongSummary(summary).insert(database, onConflict: .replace)
            }
        }
    }

    /// Returns every cached song summary, ordered by songbook then display
    /// number.
    ///
    /// ELI5: "What songs do we already have saved?"
    ///
    /// DETAILED: `compactMap` silently drops any row whose `songId` somehow
    /// fails to re-parse (see `CachedSongSummary.toSongSummary()`) rather
    /// than throwing — a defensive stance appropriate for a *cache*: a
    /// single corrupt row should never take down the whole offline
    /// experience, and the resync path this feeds (Phase 1) will simply
    /// re-fetch and overwrite it from the network.
    public func allSongSummaries() async throws -> [SongSummary] {
        let rows = try await dbQueue.read { database in
            try CachedSongSummary
                .order(Column("songbookAbbreviation"), Column("displayNumber"))
                .fetchAll(database)
        }
        return rows.compactMap { $0.toSongSummary() }
    }
}

// OfflineStore.swift
// IHPersistence
//
// ELI5: The app's own little offline notebook — it remembers song
// summaries on the device so search/browsing still works with no internet,
// and so re-launching the app doesn't need to re-download everything. It
// also now remembers your favourites (#181) and a small queue of
// favourite-toggle actions that couldn't reach the server yet.
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
// `v2CreateFavorites` (native login/account UI + favourites task, #181) is
// the first concrete instance of strategy §1.5's planned "favourites/setlist
// mirrors, pending-sync queue" schema pieces — see `CachedFavorite.swift`'s
// header for the two tables' shapes and why `favorite_pending_op` is
// primary-keyed by `songId` rather than an autoincrement id.
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

        migrator.registerMigration("v2CreateFavorites") { database in
            try database.create(table: "favorite") { table in
                table.primaryKey("songId", .text)
                table.column("title", .text).notNull()
                table.column("songbookAbbreviation", .text).notNull()
                table.column("number", .integer)
                table.column("tagsJson", .text).notNull()
                table.column("addedAt", .datetime).notNull()
            }
            try database.create(table: "favorite_pending_op") { table in
                table.primaryKey("songId", .text)
                table.column("operation", .text).notNull()
                table.column("title", .text).notNull()
                table.column("songbookAbbreviation", .text).notNull()
                table.column("number", .integer)
                table.column("tagsJson", .text).notNull()
                table.column("createdAt", .datetime).notNull()
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

    // MARK: - Favourites (#181)

    /// Every cached favourite, most-recently-added first — `FavoritesView`'s
    /// offline-first data source (never blocks on the network, unlike
    /// `catalogueLoadState`'s server-only `songsIndex()` fetch).
    ///
    /// ELI5: "What have I favourited?" — answered from the device, instantly.
    public func allFavorites() async throws -> [FavoriteSong] {
        let rows = try await dbQueue.read { database in
            try CachedFavorite.order(Column("addedAt").desc).fetchAll(database)
        }
        return rows.compactMap { $0.toFavoriteSong() }
    }

    /// Inserts or replaces one favourite (upsert by `songId`) — used both by
    /// an optimistic `toggleFavorite` add and by reconciling a server pull.
    public func upsertFavorite(_ favorite: FavoriteSong) async throws {
        try await dbQueue.write { database in
            try CachedFavorite(favorite).insert(database, onConflict: .replace)
        }
    }

    /// Removes one favourite by id — a no-op (not an error) if it isn't
    /// cached at all, matching `KeychainTokenStore.delete()`'s "already
    /// absent = successfully removed" idempotent-delete posture.
    public func removeFavorite(_ songId: SongID) async throws {
        _ = try await dbQueue.write { database in
            try CachedFavorite.deleteOne(database, key: songId.rawValue)
        }
    }

    /// Reconciles the local favourite cache with an AUTHORITATIVE server
    /// list (`?action=favorites`/`?action=favorites_sync`'s response) —
    /// deletes any cached favourite absent from `authoritative`, and upserts
    /// every entry `authoritative` DOES carry, PRESERVING each song's
    /// already-known local title/songbook/number when this device already
    /// had a richer cached row for it (a server pull only ever carries
    /// `{id, tags}` — see `FavoriteSong`'s own header) rather than
    /// clobbering good metadata with the placeholder `""`/`nil` a brand-new
    /// entry would otherwise get.
    ///
    /// ELI5: "The server just told us the REAL, complete favourites list —
    /// match our notebook to it exactly, but don't forget the titles we
    /// already knew."
    public func replaceFavorites(with authoritative: [FavoriteEntry]) async throws {
        try await dbQueue.write { database in
            let existing = try CachedFavorite.fetchAll(database)
            var knownMetadata: [String: CachedFavorite] = [:]
            for row in existing { knownMetadata[row.songId] = row }

            try CachedFavorite.deleteAll(database)
            for entry in authoritative {
                let known = knownMetadata[entry.songId.rawValue]
                let merged = FavoriteSong(
                    songId: entry.songId,
                    title: known?.title ?? "",
                    songbookAbbreviation: known?.songbookAbbreviation ?? "",
                    number: known?.number,
                    tags: entry.tags,
                    addedAt: known?.addedAt ?? Date()
                )
                try CachedFavorite(merged).insert(database)
            }
        }
    }

    /// Deletes every cached favourite AND any still-queued pending ops —
    /// called on sign-out (`AppRootViewModel+Auth.swift`) so a second
    /// account signing in on a shared device never sees the previous
    /// account's favourites, and no stale queued action survives to be
    /// replayed against the wrong account's token.
    ///
    /// ELI5: "Forget every favourite and every pending change — a new
    /// person is signing in."
    public func clearFavorites() async throws {
        try await dbQueue.write { database in
            try CachedFavorite.deleteAll(database)
            try PendingFavoriteOp.deleteAll(database)
        }
    }

    // MARK: - Favourites offline queue (#181)

    /// Queues (or replaces any already-queued action for the SAME song —
    /// see `PendingFavoriteOp`'s own header) an add/remove to replay once
    /// the immediate network attempt fails.
    ///
    /// ELI5: "Remember to tell the server about this, later."
    public func enqueuePendingFavoriteOp(_ pendingOp: PendingFavoriteOp) async throws {
        try await dbQueue.write { database in
            try pendingOp.insert(database, onConflict: .replace)
        }
    }

    /// Every currently-queued favourite action, oldest first (replay order
    /// matters if a device queues multiple DIFFERENT songs' toggles while
    /// offline — each song has at most one queued op, per
    /// `PendingFavoriteOp`'s primary-key-by-`songId` design, but the QUEUE
    /// itself can hold many songs at once).
    public func pendingFavoriteOps() async throws -> [PendingFavoriteOp] {
        try await dbQueue.read { database in
            try PendingFavoriteOp.order(Column("createdAt")).fetchAll(database)
        }
    }

    /// Removes one queued action once it has been successfully replayed
    /// against the server.
    public func dequeuePendingFavoriteOp(songId: SongID) async throws {
        _ = try await dbQueue.write { database in
            try PendingFavoriteOp.deleteOne(database, key: songId.rawValue)
        }
    }
}

// OfflineStore.swift
// IHPersistence
//
// ELI5: The app's own little offline notebook — it remembers song
// summaries on the device so search/browsing still works with no internet,
// and so re-launching the app doesn't need to re-download everything. It
// also now remembers your favourites (#181), a small queue of
// favourite-toggle actions that couldn't reach the server yet, and — since
// #187 — the FULL text of any song you've explicitly saved for offline
// reading.
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
// `v3CreateSetlists` (#181's setlists half) adds the SECOND instance of that
// same "mirror + pending-sync queue" shape, for setlists — see
// `CachedSetlist.swift`'s header for the two tables' shapes, and for why
// `setlist_pending_op`'s replay strategy differs from
// `favorite_pending_op`'s (the real API has no per-item setlist
// delete/upsert endpoint, only a bulk sync).
//
// `v4CreateSavedSongs` (#187, offline support + data management) is
// strategy §1.5's "saved songs" schema piece, finally given a concrete
// shape: unlike `song_summary` (the SLIM index every song contributes a row
// to automatically), `saved_song` holds the FULL `SongDetail` — lyrics,
// credits, everything — for only the songs the user explicitly chose to
// save, so those songs remain fully READABLE with no connection at all, not
// just listable. See `CachedSongDetail.swift`'s header for the row shape and
// why the full record is stored as an encoded JSON blob rather than
// exploded into columns.
//
// `v5CreateCachedMedia` (#1440, offline media caching) extends the #187
// offline story to a saved song's ATTACHED FILES — audio recordings,
// sheet-music PDFs, MIDI, MusicXML — deliberately NOT stored the same way
// `saved_song` stores lyrics (a JSON blob column): those files can run to
// several MB each (a recording especially), and SQLite blob columns are the
// wrong tool for that — every write/vacuum/backup of the whole database
// would drag the largest cached recording along with it, and neither
// `AVPlayer` (streaming playback) nor `PDFKit`'s `PDFView` can consume a
// blob without first re-materialising it to a temp file anyway. Instead,
// `cached_media` is a lightweight INDEX row (`CachedMediaFile.swift`)
// pointing at bytes written directly to a file under
// `OfflineStore.mediaCacheDirectory` (a device-local directory this actor
// now also owns) — mirroring the exact same "audio-on-filesystem, index in
// the database" split the WEB backend already uses server-side
// (`SongMediaStorage.php`, referenced by this feature's own GitHub issue).
// See `OfflineStore+MediaCache.swift`'s header for the full read/write API
// this table backs.
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
    /// `internal` (not `private`) — `private` is FILE-scoped in Swift, which
    /// would block `OfflineStore+Setlists.swift` (a separate file, same
    /// module/type) from reaching it. Mirrors `APIClient.performOnce`/
    /// `performIdempotentGET`'s identical "relaxed to `internal` so a
    /// same-target file split can compile" reasoning (`APIClient.swift`'s
    /// own doc comment) — this property still can't be touched from OUTSIDE
    /// `IHPersistence` (`internal` is module-, not file-scoped, but is not
    /// `public`), so `IHFeatures`/callers elsewhere are unaffected.
    let dbQueue: DatabaseQueue

    /// Where `cached_media` (#1440) writes each downloaded media file's
    /// actual bytes — see this file's header (`v5CreateCachedMedia`) for why
    /// media lives on the filesystem rather than in a SQLite blob column.
    /// `internal` (not `private`), same file-scoping reason `dbQueue`
    /// documents above — `OfflineStore+MediaCache.swift` is a separate file,
    /// same type/module.
    let mediaCacheDirectory: URL

    /// Opens (creating if necessary) the offline database at `path`, or an
    /// ephemeral in-memory database when `path` is `nil` (used by unit
    /// tests and SwiftUI previews so nothing touches disk).
    ///
    /// - Parameters:
    ///   - path: Absolute file path for the SQLite database, or `nil` for
    ///     an in-memory database.
    ///   - mediaCacheDirectory: Where cached media FILES (not the SQLite
    ///     index rows) get written (#1440) — `AppRootViewModel+Live.swift`'s
    ///     live factory passes a stable on-disk directory (mirroring
    ///     `offlineStorePath()`'s own "iHymns" Application Support
    ///     subfolder); `nil` (every existing call site, including every
    ///     pre-#1440 test) gets a fresh, uniquely-named directory under the
    ///     system temp root instead, so those call sites need no changes at
    ///     all and never collide with one another or with a real device
    ///     cache. Created eagerly (`withIntermediateDirectories: true`) so
    ///     every later `OfflineStore+MediaCache.swift` write can assume it
    ///     already exists.
    public init(path: String? = nil, mediaCacheDirectory: URL? = nil) throws {
        if let path {
            dbQueue = try DatabaseQueue(path: path)
        } else {
            dbQueue = try DatabaseQueue()
        }
        let resolvedMediaCacheDirectory = mediaCacheDirectory
            ?? FileManager.default.temporaryDirectory.appendingPathComponent(
                "ihymns-media-cache-\(UUID().uuidString)", isDirectory: true
            )
        try FileManager.default.createDirectory(at: resolvedMediaCacheDirectory, withIntermediateDirectories: true)
        self.mediaCacheDirectory = resolvedMediaCacheDirectory
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

        migrator.registerMigration("v3CreateSetlists") { database in
            try database.create(table: "setlist") { table in
                table.primaryKey("setlistId", .text)
                table.column("name", .text).notNull()
                table.column("songsJson", .text).notNull()
                table.column("createdAt", .text)
                table.column("updatedAt", .text)
                table.column("localUpdatedAt", .datetime).notNull()
            }
            try database.create(table: "setlist_pending_op") { table in
                table.primaryKey("setlistId", .text)
                table.column("operation", .text).notNull()
                table.column("name", .text).notNull()
                table.column("songsJson", .text).notNull()
                table.column("createdAt", .text)
                table.column("updatedAt", .text)
                table.column("queuedAt", .datetime).notNull()
            }
        }

        migrator.registerMigration("v4CreateSavedSongs") { database in
            try database.create(table: "saved_song") { table in
                table.primaryKey("songId", .text)
                table.column("title", .text).notNull()
                table.column("songbookAbbreviation", .text).notNull()
                table.column("detailData", .blob).notNull()
                table.column("savedAt", .datetime).notNull()
            }
        }

        migrator.registerMigration("v5CreateCachedMedia", migrate: Self.createCachedMediaTable)

        return migrator
    }

    /// The `v5CreateCachedMedia` migration body (#1440) — split out of
    /// `makeMigrator()` itself purely to keep that function under the
    /// repo's `function_body_length` lint ceiling now that a fifth
    /// migration has landed; behaviourally identical to being written
    /// inline, same as every other `registerMigration(_:migrate:)` call
    /// above.
    private static func createCachedMediaTable(_ database: Database) throws {
        try database.create(table: "cached_media") { table in
            // Composite primary key (rather than a surrogate autoincrement
            // id) — this file's header explains WHY media caches by
            // `(songId, mediaAssetId)`; GRDB's `TableDefinition
            // .primaryKey(_:)` overload accepting an array of column names
            // is exactly this "no single column is unique on its own, the
            // PAIR is" shape.
            table.column("songId", .text).notNull()
            table.column("mediaAssetId", .integer).notNull()
            // `SongMediaAsset.Kind` (`"audio" | "sheet-music" | "midi" |
            // "musicxml"`, `SongDetail+Media.swift`) — denormalised here
            // (same reasoning `CachedSongDetail.title`/
            // `.songbookAbbreviation` document) purely so a future "what
            // kind of file is this?" query never needs to cross-reference
            // `saved_song`'s own JSON blob.
            table.column("kind", .text).notNull()
            table.column("fileName", .text).notNull()
            table.column("mimeType", .text).notNull()
            table.column("sizeBytes", .integer).notNull()
            // Stored RELATIVE to `mediaCacheDirectory`, never an absolute
            // path — an absolute path baked in today could point at a
            // sandbox container path that changes across an app reinstall/
            // update (Apple's own documented behaviour: a container's
            // absolute path is not guaranteed stable), which would
            // silently orphan every cached file. Resolving
            // relative-to-`mediaCacheDirectory` at READ time instead means
            // a container-path change is transparent.
            table.column("relativePath", .text).notNull()
            table.column("cachedAt", .datetime).notNull()
            table.primaryKey(["songId", "mediaAssetId"])
        }
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

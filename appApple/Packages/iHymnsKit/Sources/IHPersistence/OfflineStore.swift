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
// `v6AddMediaCacheLastValidated` (#1450, cached-media staleness) adds ONE
// additive, nullable column — `cached_media.lastValidatedAt` — the
// bookkeeping `MediaCacheRevalidationPolicy` (`IHModels`) needs to decide
// "is it even time to re-check this asset yet?" without re-querying the
// server on every single song-screen visit. NULL for every row a
// pre-#1450 app version already wrote (SQLite `ALTER TABLE ADD COLUMN`
// can't backfill a NOT-NULL default from another column's value in one
// statement) — `CachedMediaFile.toCachedMediaInfo()` treats a NULL as
// "last validated when it was cached" (`lastValidatedAt ?? cachedAt`), so
// an old row degrades to "due for a re-check" rather than crashing or
// needing a data migration of its own. See
// `OfflineStore+MediaCache.swift`'s header for the read/write API this
// column backs, and `MediaCacheRevalidationPolicy.swift`'s header for the
// full "why sizeBytes + a TTL, not ETag/Last-Modified" reasoning.
//
// `v7AddMediaCacheEtag` (#1460, the client half of #1452's backend
// ETag/Last-Modified + 304 support on `/song-media/<id>`) adds TWO more
// additive, nullable columns — `cached_media.etag` / `.lastModified` — so
// `MediaCacheRevalidator` (`IHFeatures`) can send a real conditional GET
// (`If-None-Match`) instead of only comparing `sizeBytes`, which is blind to
// a same-size content replacement (documented gap in
// `MediaCacheRevalidationPolicy.swift`'s own header). NULL for every row
// cached before this migration (and for every row an ETag-less server
// response would still leave NULL) — `CachedMediaFile
// .toCachedMediaInfo()`'s own comment explains why a NULL `etag` means
// "fall back to the #1450 sizeBytes+TTL heuristic for this asset," never a
// crash or a forced re-download.
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

        migrator.registerMigration("v6AddMediaCacheLastValidated", migrate: Self.addMediaCacheLastValidatedColumn)

        migrator.registerMigration("v7AddMediaCacheEtag", migrate: Self.addMediaCacheEtagColumns)

        return migrator
    }

    /// The `v7AddMediaCacheEtag` migration body (#1460) — split out for the
    /// same `function_body_length` lint-ceiling reason `createCachedMediaTable`/
    /// `addMediaCacheLastValidatedColumn` above already document.
    private static func addMediaCacheEtagColumns(_ database: Database) throws {
        try database.alter(table: "cached_media") { table in
            // Both nullable — see this file's header for why NULL is the
            // correct, expected, non-error state for a pre-#1460 row.
            table.add(column: "etag", .text)
            table.add(column: "lastModified", .text)
        }
    }

    /// The `v6AddMediaCacheLastValidated` migration body (#1450) — split out
    /// of `makeMigrator()` for the exact same `function_body_length`
    /// lint-ceiling reason `createCachedMediaTable` below already documents
    /// for `v5CreateCachedMedia`.
    private static func addMediaCacheLastValidatedColumn(_ database: Database) throws {
        try database.alter(table: "cached_media") { table in
            // Nullable (no `.notNull()`) — see this file's header for why a
            // NOT-NULL-with-backfill isn't possible in one `ALTER TABLE ADD
            // COLUMN` statement, and how a NULL is handled at the read
            // boundary instead.
            table.add(column: "lastValidatedAt", .datetime)
        }
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

    // Favourites (#181) — `allFavorites()`/`upsertFavorite(_:)`/
    // `removeFavorite(_:)`/`replaceFavorites(with:)`/`clearFavorites()` +
    // the offline queue (`enqueuePendingFavoriteOp(_:)`/`pendingFavoriteOps()`/
    // `dequeuePendingFavoriteOp(songId:)`) live in
    // `OfflineStore+Favorites.swift` — moved out (#1450) purely to keep
    // THIS file under the repo's LOC-budget tripwire now that the `v6`
    // migration has landed; a pure file move, not a behavioural edit, the
    // same reasoning `OfflineStore+SavedSongs.swift`/`+MediaCache.swift`'s
    // own earlier extractions already document.
}

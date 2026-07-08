// OfflineStore+MediaCache.swift
// IHPersistence
//
// ELI5: Everything about "download this song's audio/PDF/MIDI/MusicXML file
// so it works with no internet too" — the #1440 offline-media half, split
// into its own file for the same reason `OfflineStore+SavedSongs.swift`
// already documents: an `actor` extension in a separate file stays isolated
// exactly like a method declared in the primary file, keeping
// `OfflineStore.swift` itself from re-growing past the repo's LOC-budget
// tripwire.
//
// DETAILED: #1440 (offline media caching). Unlike `saved_song`'s single
// JSON-blob column (`CachedSongDetail.swift`), a cached media file is real
// BYTES written to a real file under `mediaCacheDirectory` — this file's
// job is keeping that file and its `cached_media` index row in lockstep:
// every write here both writes the file AND upserts its row (never one
// without the other), and every delete removes both (best-effort on the
// file side — `try?`, matching `removeSavedSong(_:)`'s "already absent =
// successfully removed" idempotent-delete posture elsewhere in this file's
// sibling).
//
// `Data`-in, `Data`-out at this layer — this file never issues a network
// request itself (mirrors `saveSongForOffline(_:)`'s equivalent boundary:
// `IHFeatures.SongDetailViewModel` is the one that fetches bytes, over an
// INJECTABLE fetcher for testability, then hands the result here to persist
// — see that file's `cacheMediaForOffline(_:)` for the actual download
// trigger, wired to the existing "Save for Offline" toggle per this
// feature's own GitHub issue's "Rough approach").
//
// #1450 UPDATE (cached-media staleness) — `cacheMedia(songId:asset:data:)`
// now stamps BOTH `cachedAt` and `lastValidatedAt` to the same "now" on
// every write (a fresh download IS, by definition, freshly validated), and
// `markCachedMediaValidated(songId:mediaAssetId:at:)` is a new, narrower
// write that bumps ONLY `lastValidatedAt` — for the `.stillFresh` case
// `IHFeatures.MediaCacheRevalidator` reaches when the server's metadata
// still matches what's cached, so re-confirming freshness never has to
// re-write the file or `cachedAt` just to record that the check happened.
import Foundation
import GRDB
import IHModels

extension OfflineStore {
    // MARK: - Media cache (#1440)

    /// Writes `data` to a file under `mediaCacheDirectory` and upserts the
    /// matching `cached_media` index row — the ONE place both halves of
    /// "cache this media file" happen together.
    ///
    /// ELI5: "Here are the file's bytes — save them, and remember you did."
    ///
    /// - Returns: The absolute local file `URL` the bytes were written to —
    ///   immediately usable by `AVPlayer`/`PDFDocument(data:)`'s local-file
    ///   equivalents/`ShareLink`, exactly like a fresh network fetch's
    ///   result would be.
    @discardableResult
    public func cacheMedia(songId: SongID, asset: SongMediaAsset, data: Data) async throws -> URL {
        // `<songId>/<mediaAssetId>-<sanitizedFileName>` — namespaced by
        // song first so `removeAllCachedMedia(forSong:)` below can drop an
        // entire song's cached files by removing one subdirectory, and by
        // `mediaAssetId` second so two different songs' curators both
        // naming a file `"sheet.pdf"` (the exact collision
        // `MediaDownloadViewModel.download(url:suggestedFileName:)`'s own
        // header calls out) can never collide on disk.
        let relativePath = "\(songId.rawValue)/\(asset.id)-\(MediaFileNaming.sanitizedFileName(asset.fileName))"
        let fileURL = mediaCacheDirectory.appendingPathComponent(relativePath)
        try FileManager.default.createDirectory(at: fileURL.deletingLastPathComponent(), withIntermediateDirectories: true)
        try data.write(to: fileURL, options: .atomic)

        // One shared timestamp for both columns — a freshly (re-)downloaded
        // file is, by definition, validated as of this exact moment (#1450).
        let now = Date()
        let row = CachedMediaFile(
            songId: songId.rawValue,
            mediaAssetId: asset.id,
            kind: asset.kind,
            fileName: asset.fileName,
            mimeType: asset.mimeType,
            sizeBytes: data.count,
            relativePath: relativePath,
            cachedAt: now,
            lastValidatedAt: now
        )
        try await dbQueue.write { database in
            try row.insert(database, onConflict: .replace)
        }
        return fileURL
    }

    /// Bumps ONLY `lastValidatedAt` for one already-cached asset — the
    /// `.stillFresh` half of #1450's revalidation story
    /// (`MediaCacheRevalidationPolicy`): the server's metadata was
    /// re-checked and still matches what's on disk, so there's nothing to
    /// re-download, just a record that the check happened (so the NEXT
    /// re-check's "is it even time yet?" TTL window starts counting from
    /// now, not from whenever this file was first cached). A harmless no-op
    /// if the asset isn't cached at all (e.g. it was removed between the
    /// check starting and this write landing) — same idempotent-write
    /// posture `cacheMedia(songId:asset:data:)`'s own `onConflict: .replace`
    /// upsert takes.
    ///
    /// ELI5: "We just double-checked this downloaded file — it's still
    /// good, so note that we checked."
    public func markCachedMediaValidated(songId: SongID, mediaAssetId: Int, at date: Date = Date()) async throws {
        _ = try await dbQueue.write { database in
            try database.execute(
                sql: "UPDATE cached_media SET lastValidatedAt = ? WHERE songId = ? AND mediaAssetId = ?",
                arguments: [date, songId.rawValue, mediaAssetId]
            )
        }
    }

    /// The absolute local file `URL` for one cached asset, if this device
    /// actually has it cached AND the file is still really there —
    /// `SongMediaSection`'s "prefer the cached copy" seam
    /// (`mediaURL(for:)`) calls this before falling back to the network
    /// stream URL.
    ///
    /// ELI5: "Do we already have this file saved? If so, where?"
    ///
    /// DETAILED: Verifies `FileManager.default.fileExists` rather than
    /// trusting the database row alone — a defensive check against the
    /// (rare but real) case of the OS reclaiming on-disk storage out from
    /// under the app, or a future manual "clear cache" affordance that only
    /// touched the filesystem. Best-effort like every other read in this
    /// pair of extension files: a lookup failure degrades to `nil` (=
    /// "stream it fresh instead"), never a thrown error a transport control
    /// would have to handle specially.
    public func cachedMediaURL(songId: SongID, mediaAssetId: Int) async -> URL? {
        // Explicit `do/catch` (rather than `try? await dbQueue.read { ... }`)
        // deliberately avoids the classic Swift `T??` double-optional trap
        // that expression would otherwise produce here (a throwing read
        // whose own success value is ALREADY optional) — this reads
        // unambiguously as "failure or 'not cached' both mean nil" without
        // a second nested `guard let`.
        let row: CachedMediaFile?
        do {
            row = try await dbQueue.read { database in
                try CachedMediaFile
                    .filter(Column("songId") == songId.rawValue && Column("mediaAssetId") == mediaAssetId)
                    .fetchOne(database)
            }
        } catch {
            return nil
        }
        guard let row else { return nil }
        let fileURL = mediaCacheDirectory.appendingPathComponent(row.relativePath)
        return FileManager.default.fileExists(atPath: fileURL.path) ? fileURL : nil
    }

    /// Every media file cached for one song — `OfflineStorageViewModel`
    /// could use this for a future per-song breakdown; today mainly exercised
    /// by tests proving `cacheMedia(songId:asset:data:)` actually indexed
    /// what it wrote.
    ///
    /// ELI5: "What files have we saved for this one song?"
    public func cachedMedia(forSong songId: SongID) async throws -> [CachedMediaInfo] {
        let rows = try await dbQueue.read { database in
            try CachedMediaFile.filter(Column("songId") == songId.rawValue).fetchAll(database)
        }
        return rows.compactMap { $0.toCachedMediaInfo() }
    }

    /// Removes one cached file (its row AND its bytes) — a no-op (not an
    /// error) if it was never cached, matching `removeSavedSong(_:)`'s
    /// identical idempotent-delete posture.
    ///
    /// ELI5: "Forget this one downloaded file."
    public func removeCachedMedia(songId: SongID, mediaAssetId: Int) async throws {
        let row = try await dbQueue.read { database in
            try CachedMediaFile
                .filter(Column("songId") == songId.rawValue && Column("mediaAssetId") == mediaAssetId)
                .fetchOne(database)
        }
        _ = try await dbQueue.write { database in
            try CachedMediaFile
                .filter(Column("songId") == songId.rawValue && Column("mediaAssetId") == mediaAssetId)
                .deleteAll(database)
        }
        if let row {
            try? FileManager.default.removeItem(at: mediaCacheDirectory.appendingPathComponent(row.relativePath))
        }
    }

    /// Removes EVERY cached file for one song — called alongside
    /// `removeSavedSong(_:)` when a user un-saves a song
    /// (`SongDetailViewModel.toggleOfflineSave()`), so turning off "Save for
    /// Offline" also cleans up whatever media was cached alongside it,
    /// rather than leaving orphaned files with no surviving `saved_song` row
    /// to associate them with.
    ///
    /// ELI5: "Forget every downloaded file for this song."
    public func removeAllCachedMedia(forSong songId: SongID) async throws {
        let rows = try await dbQueue.read { database in
            try CachedMediaFile.filter(Column("songId") == songId.rawValue).fetchAll(database)
        }
        guard !rows.isEmpty else { return }
        _ = try await dbQueue.write { database in
            try CachedMediaFile.filter(Column("songId") == songId.rawValue).deleteAll(database)
        }
        // Removing the whole `<songId>/` subdirectory in one call is both
        // simpler and more thorough than removing each row's own file
        // individually — it also sweeps up any file a PAST version of this
        // cache wrote but whose index row somehow went missing.
        try? FileManager.default.removeItem(at: mediaCacheDirectory.appendingPathComponent(songId.rawValue, isDirectory: true))
    }

    /// The total on-disk size, in bytes, of EVERY cached media file across
    /// every song — `OfflineStorageViewModel`'s "Media Downloads" figure,
    /// alongside `totalSavedSongBytes()`'s own "Total Size" (lyrics-only)
    /// number. Same `SUM(...)`-in-SQL posture `totalSavedSongBytes()`
    /// documents — never a Swift-side reduce over fetched rows.
    ///
    /// ELI5: "How much space do my downloaded files take up?"
    public func totalCachedMediaBytes() async throws -> Int {
        try await dbQueue.read { database in
            try Int.fetchOne(database, sql: "SELECT COALESCE(SUM(sizeBytes), 0) FROM cached_media") ?? 0
        }
    }

    /// Deletes EVERY cached media file (every song's) in one shot —
    /// `OfflineStorageView`'s "Remove All Downloads" action now clears both
    /// this AND `removeAllSavedSongs()` (composed at the
    /// `OfflineStorageViewModel` call site, not here — this actor stays
    /// scoped to exactly the `cached_media` table, mirroring how
    /// `removeAllSavedSongs()` stays scoped to `saved_song`).
    ///
    /// ELI5: "Forget every downloaded file, for every song."
    public func removeAllCachedMedia() async throws {
        _ = try await dbQueue.write { database in
            try CachedMediaFile.deleteAll(database)
        }
        // Recreating the (now-empty) directory immediately, rather than
        // leaving it deleted, matches `init(path:mediaCacheDirectory:)`'s
        // own invariant that `mediaCacheDirectory` always exists once this
        // actor has finished initializing — a later `cacheMedia(...)` call
        // in the SAME app run must never have to re-create the root itself.
        try? FileManager.default.removeItem(at: mediaCacheDirectory)
        try? FileManager.default.createDirectory(at: mediaCacheDirectory, withIntermediateDirectories: true)
    }
}

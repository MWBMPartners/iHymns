// OfflineStore+Favorites.swift
// IHPersistence
//
// ELI5: Everything about "remember which songs I've favourited, and a small
// to-do list of favourite/un-favourite taps that couldn't reach the server
// yet" — the #181 favourites half, split into its own file for the same
// reason `OfflineStore+Setlists.swift`/`+SavedSongs.swift`/`+MediaCache.swift`
// already document: an `actor` extension in a separate file stays isolated
// exactly like a method declared in the primary file, keeping
// `OfflineStore.swift` itself from re-growing past the repo's LOC-budget
// tripwire.
//
// DETAILED: #1450 (cached-media staleness) pushed `OfflineStore.swift` over
// the repo's `Scripts/loc-budget.sh` ceiling once its `v6` migration +
// header landed — these methods moved out UNCHANGED (a pure file split, not
// a behavioural edit) for that reason alone, mirroring how #187/#1440's own
// growth already prompted `OfflineStore+SavedSongs.swift`/
// `+MediaCache.swift` to split out of this same file earlier. See
// `OfflineStore.swift`'s own header (`v2CreateFavorites`) for the two
// tables' shapes (`favorite` + `favorite_pending_op`) and why
// `favorite_pending_op` is primary-keyed by `songId` rather than an
// autoincrement id.
import Foundation
import GRDB
import IHModels

extension OfflineStore {
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

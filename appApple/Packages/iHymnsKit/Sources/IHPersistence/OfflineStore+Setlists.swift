// OfflineStore+Setlists.swift
// IHPersistence
//
// ELI5: Everything about "remember my setlists on this device, and remember
// which ones still need telling to the server" — the setlist half of #181,
// split into its own file purely so `OfflineStore.swift` itself (which
// already owns the migrations + the song-summary/favourites methods) doesn't
// keep growing. `actor` extensions in a separate file stay isolated exactly
// like methods declared in the primary file — no new stored properties are
// added here (Swift extensions can't add any), just more methods operating
// on the SAME `dbQueue` the primary file already opened.
//
// DETAILED: Mirrors `OfflineStore.swift`'s existing favourites methods
// method-for-method (`allFavorites`→`allSetlists`,
// `upsertFavorite`→`upsertSetlist`, `removeFavorite`→`deleteSetlistLocally`,
// `replaceFavorites`→`replaceSetlists`, `clearFavorites`→`clearSetlists`,
// the three pending-op methods) — see `CachedSetlist.swift`'s header for the
// one meaningful behavioural difference (`setlist_pending_op`'s replay
// strategy).
import Foundation
import GRDB
import IHModels

extension OfflineStore {
    // MARK: - Setlists (#181)

    /// Every cached setlist, most-recently-touched-on-this-device first
    /// (`CachedSetlist.localUpdatedAt` — see that type's header for why this
    /// is a LOCAL bookkeeping column, not the server's own `updatedAt`).
    ///
    /// ELI5: "What are my setlists?" — answered from the device, instantly.
    public func allSetlists() async throws -> [Setlist] {
        let rows = try await dbQueue.read { database in
            try CachedSetlist.order(Column("localUpdatedAt").desc).fetchAll(database)
        }
        return rows.compactMap { $0.toSetlist() }
    }

    /// Inserts or replaces one setlist (upsert by `setlistId`), stamping
    /// `localUpdatedAt` — used both by every local mutation (create/rename/
    /// reorder/add-song/remove-song) and by reconciling a server pull.
    ///
    /// - Parameter localUpdatedAt: Defaults to `Date()` (the real "right
    ///   now" every production call site wants); overridable purely so
    ///   `OfflineStoreTests` can prove `allSetlists()`'s most-recently
    ///   -touched-first ordering deterministically, without a real wall
    ///   -clock sleep between two upserts (this package's own "injected
    ///   clock, never a wall-clock sleep" test discipline — strategy §3.3).
    public func upsertSetlist(_ setlist: Setlist, localUpdatedAt: Date = Date()) async throws {
        try await dbQueue.write { database in
            try CachedSetlist(setlist, localUpdatedAt: localUpdatedAt).insert(database, onConflict: .replace)
        }
    }

    /// Removes one setlist by id — a no-op (not an error) if it isn't
    /// cached at all, matching `removeFavorite(_:)`'s identical idempotent
    /// -delete posture. Named `...Locally` (unlike `removeFavorite`, which
    /// has no server-call sibling in `OfflineStore` to disambiguate from)
    /// purely to read unambiguously alongside `AppRootViewModel.deleteSetlist(_:)`,
    /// which also has server-sync consequences this method itself knows
    /// nothing about.
    public func deleteSetlistLocally(_ setlistId: String) async throws {
        _ = try await dbQueue.write { database in
            try CachedSetlist.deleteOne(database, key: setlistId)
        }
    }

    /// Reconciles the local setlist cache with an AUTHORITATIVE server list
    /// (`user_setlists`/`user_setlists_sync`'s response) — deletes any
    /// cached setlist absent from `authoritative`, upserts every entry it
    /// DOES carry. Unlike `replaceFavorites(with:)`, there is no
    /// locally-known-but-server-omitted metadata worth preserving here — a
    /// `Setlist` from the server already carries everything (name + full
    /// song details), so this is a straight replace, not a metadata-merge.
    ///
    /// ELI5: "The server just told us the REAL, complete list of setlists —
    /// match our notebook to it exactly."
    public func replaceSetlists(with authoritative: [Setlist]) async throws {
        try await dbQueue.write { database in
            try CachedSetlist.deleteAll(database)
            for setlist in authoritative {
                try CachedSetlist(setlist).insert(database)
            }
        }
    }

    /// Deletes every cached setlist AND any still-queued pending ops —
    /// called on sign-out, same reasoning `clearFavorites()` documents: a
    /// second account signing in on a shared device must never see the
    /// first account's setlists, and no stale queued action survives to
    /// replay against the wrong account's token.
    public func clearSetlists() async throws {
        try await dbQueue.write { database in
            try CachedSetlist.deleteAll(database)
            try PendingSetlistOp.deleteAll(database)
        }
    }

    // MARK: - Setlists offline queue (#181)

    /// Queues (or replaces any already-queued action for the SAME setlist —
    /// see `PendingSetlistOp`'s own header) a create/edit/delete to replay
    /// once the device is reachable again.
    public func enqueuePendingSetlistOp(_ pendingOp: PendingSetlistOp) async throws {
        try await dbQueue.write { database in
            try pendingOp.insert(database, onConflict: .replace)
        }
    }

    /// Every currently-queued setlist change, oldest first.
    public func pendingSetlistOps() async throws -> [PendingSetlistOp] {
        try await dbQueue.read { database in
            try PendingSetlistOp.order(Column("queuedAt")).fetchAll(database)
        }
    }

    /// Removes one queued change once it has been successfully replayed.
    public func dequeuePendingSetlistOp(setlistId: String) async throws {
        _ = try await dbQueue.write { database in
            try PendingSetlistOp.deleteOne(database, key: setlistId)
        }
    }

    /// Empties the ENTIRE pending-setlist queue in one shot — called after
    /// a successful bulk `.replace` sync (`AppRootViewModel
    /// .flushPendingSetlistOps()`), which — per `CachedSetlist.swift`'s
    /// header — satisfies every queued row at once, unlike favourites'
    /// per-row `dequeuePendingFavoriteOp(songId:)` replay.
    public func clearAllPendingSetlistOps() async throws {
        _ = try await dbQueue.write { database in
            try PendingSetlistOp.deleteAll(database)
        }
    }
}

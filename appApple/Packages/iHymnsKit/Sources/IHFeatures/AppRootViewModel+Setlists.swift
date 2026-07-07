// AppRootViewModel+Setlists.swift
// IHFeatures
//
// ELI5: Everything about "what are my setlists, and how do I create/rename/
// delete one, add or remove or reorder its songs, and share it" — the #181
// setlists half, reached through `AppRootViewModel` exactly like every
// other engine operation (mirrors `AppRootViewModel+Favorites.swift`'s own
// shape, but see the header there vs. here for the one load-bearing
// difference explained below).
//
// DETAILED: `setlists` (declared in the primary file — extensions can't add
// stored properties) is the OFFLINE-FIRST source of truth every screen
// reads, populated from `IHPersistence.OfflineStore`'s local cache FIRST
// (`loadSetlistsIfNeeded()`), refined afterwards by a best-effort server
// sync — the exact same shape `favorites` already uses.
//
// WHERE THIS FILE DIVERGES FROM FAVOURITES: favourites has a dedicated
// per-song `favorites_sync` (merge) / `favorites_remove` endpoint pair, so
// `toggleFavorite` can sync ONE song's change in isolation. Setlists have NO
// such per-item endpoint — the real API (`user_setlists_sync`) only accepts
// a BULK array, and only its `.replace` mode can express a deletion (an
// absent setlist IS the deletion signal — `Endpoint.setlistsSync`'s own
// header). So EVERY mutation below funnels through the shared
// `pushCurrentSetlistsToServer()`/`persistAndSync(touchedSetlistId:operation:)`
// pair: apply the optimistic local edit to `setlists`, persist it, then
// attempt to push the device's WHOLE current `setlists` array (which already
// reflects every applied local edit, queued or not) under `mode: .replace` —
// on success that one call satisfies every previously-queued change too, not
// just this one, so the ENTIRE pending queue clears in one shot rather than
// row-by-row (see `CachedSetlist.swift`'s `PendingSetlistOp` header for the
// full reasoning).
import Foundation
import IHAPI
import IHModels
import IHPersistence

extension AppRootViewModel {
    /// Loads `setlists` from the local offline cache, but only once per app
    /// run — same one-shot-guard shape as `loadFavoritesIfNeeded()`.
    ///
    /// ELI5: "What are my setlists?" — read from the device.
    public func loadSetlistsIfNeeded() async {
        guard !hasLoadedSetlistsFromCache else { return }
        hasLoadedSetlistsFromCache = true
        setlists = (try? await offlineStore.allSetlists()) ?? []
    }

    /// Pulls the server's AUTHORITATIVE setlists list and reconciles the
    /// local cache to match it — a no-op while signed out. Flushes any
    /// still-queued offline edits FIRST (unlike `refreshFavoritesFromServer()`,
    /// which doesn't need to — favourites' merge-only sync can never
    /// destroy a pending local add/remove, but a setlist GET pull here
    /// genuinely COULD stomp on a not-yet-synced local edit if this ran
    /// without flushing first, since there is no merge equivalent for an
    /// arbitrary setlist-content change).
    ///
    /// ELI5: "Ask the server for the real, complete setlists list — but
    /// first make sure it already knows about anything I changed offline."
    public func refreshSetlistsFromServer() async {
        guard await isSignedInNow() else { return }
        await flushPendingSetlistOps()
        guard let authoritative = try? await apiClient.userSetlists() else { return }
        try? await offlineStore.replaceSetlists(with: authoritative)
        setlists = (try? await offlineStore.allSetlists()) ?? setlists
    }

    /// Creates a new, empty setlist named `name` (a blank/whitespace-only
    /// name falls back to `"Untitled"`, matching `api.php`'s own
    /// `user_setlists_sync` server-side default), inserts it at the front of
    /// `setlists`, and syncs.
    ///
    /// ELI5: "Start a new setlist."
    ///
    /// - Returns: The newly-created `Setlist` — callers that immediately
    ///   want to add a song to it (`AddToSetlistSheet`'s "New Setlist" row)
    ///   use the returned `id` rather than re-deriving it.
    @discardableResult
    public func createSetlist(name: String) async -> Setlist {
        let trimmed = name.trimmingCharacters(in: .whitespacesAndNewlines)
        let setlist = Setlist(id: Setlist.makeId(), name: trimmed.isEmpty ? "Untitled" : trimmed)
        setlists.insert(setlist, at: 0)
        await persistAndSync(touchedSetlistId: setlist.id, operation: .upsert)
        return setlist
    }

    /// Renames `setlistId` to `newName` — a no-op if the setlist isn't
    /// locally known, or if `newName` is blank/whitespace-only (renaming to
    /// nothing makes no sense; `SetlistsView`'s rename UI should itself
    /// disable its confirm action on an empty field, this is the defensive
    /// backstop).
    ///
    /// ELI5: "Give this setlist a new name."
    public func renameSetlist(_ setlistId: String, to newName: String) async {
        guard let index = setlists.firstIndex(where: { $0.id == setlistId }) else { return }
        let trimmed = newName.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return }
        setlists[index].name = trimmed
        await persistAndSync(touchedSetlistId: setlistId, operation: .upsert)
    }

    /// Deletes `setlistId` — a no-op if it isn't locally known.
    ///
    /// ELI5: "Get rid of this setlist."
    public func deleteSetlist(_ setlistId: String) async {
        guard setlists.contains(where: { $0.id == setlistId }) else { return }
        setlists.removeAll { $0.id == setlistId }
        await persistAndSync(touchedSetlistId: setlistId, operation: .delete)
    }

    /// Adds `entry` to `setlistId`, at the end — a no-op if the setlist
    /// isn't locally known, or if the song is already IN that setlist (no
    /// duplicate entries; `AddToSetlistSheet`'s checkmark already signals
    /// this to the user before they'd even tap it, this is the defensive
    /// backstop).
    ///
    /// ELI5: "Add this song to that setlist." — `SongDetailToolbar`'s "Add
    /// to Setlist" affordance and `SetlistCatalogueBrowserView`'s tap-to-add
    /// both call this.
    public func addSong(_ entry: SetlistSongEntry, to setlistId: String) async {
        guard let index = setlists.firstIndex(where: { $0.id == setlistId }) else { return }
        guard !setlists[index].contains(entry.songId) else { return }
        setlists[index].songs.append(entry)
        await persistAndSync(touchedSetlistId: setlistId, operation: .upsert)
    }

    /// Removes every song matching `songId` from `setlistId` — a no-op if
    /// the setlist isn't locally known or the song isn't in it.
    ///
    /// ELI5: "Take this song out of that setlist." — `SetlistDetailView`'s
    /// swipe-to-remove calls this.
    public func removeSong(songId: SongID, from setlistId: String) async {
        guard let index = setlists.firstIndex(where: { $0.id == setlistId }) else { return }
        setlists[index].songs.removeAll { $0.songId == songId }
        await persistAndSync(touchedSetlistId: setlistId, operation: .upsert)
    }

    /// Reorders `setlistId`'s songs — `SetlistDetailView`'s `List.onMove`
    /// handler calls this directly with the offsets/destination SwiftUI
    /// hands it, so this signature mirrors `Array.move(fromOffsets:toOffset:)`
    /// exactly rather than translating to some other shape first.
    ///
    /// ELI5: "Drag this song to a new spot in the setlist."
    public func moveSongs(in setlistId: String, fromOffsets: IndexSet, toOffset: Int) async {
        guard let index = setlists.firstIndex(where: { $0.id == setlistId }) else { return }
        setlists[index].songs.move(fromOffsets: fromOffsets, toOffset: toOffset)
        await persistAndSync(touchedSetlistId: setlistId, operation: .upsert)
    }

    /// Publishes (or refreshes, if `existingShareId` is supplied) a public
    /// LIVE-linked share of `setlistId` — `SetlistDetailView`'s Share action
    /// calls this, then presents the result's `canonicalURL` via
    /// `ShareLink` (the owner's brief: "the app just shares the canonical
    /// link").
    ///
    /// ELI5: "Publish a public link to this setlist."
    ///
    /// - Throws: `SetlistNotFoundLocally` if `setlistId` isn't in `setlists`
    ///   (defensive — `SetlistDetailView` only ever calls this for a setlist
    ///   it's currently displaying, so this should be unreachable in
    ///   practice), or whatever `APIClient.shareSetlist(_:existingShareId:)`
    ///   itself throws (`.offline`/`.maintenance`/... — `SetlistDetailView`
    ///   maps these to user-facing copy via `APIError.userFacingMessage`,
    ///   the same convention every other network call in this package uses).
    public func shareSetlist(_ setlistId: String, existingShareId: String? = nil) async throws -> ShareSetlistResult {
        guard let setlist = setlists.first(where: { $0.id == setlistId }) else {
            throw SetlistNotFoundLocally(setlistId: setlistId)
        }
        return try await apiClient.shareSetlist(setlist, existingShareId: existingShareId)
    }

    /// Looks up a shared setlist by its public share id — a thin
    /// pass-through to `APIClient`, mirroring `songDetail(id:)`'s identical
    /// pattern in `AppRootViewModel+Catalog.swift`. Not yet wired to a
    /// dedicated "view someone else's shared setlist" screen (a future
    /// Universal Link handler's job, per this task's "note assumptions"
    /// scoping) — exposed here so the API call itself is reachable/testable
    /// today.
    ///
    /// ELI5: "Open a setlist someone shared with me."
    public func sharedSetlist(shareId: String) async throws -> SharedSetlist {
        try await apiClient.sharedSetlist(shareId: shareId)
    }

    /// Drains the entire `setlist_pending_op` queue, if there's anything in
    /// it — a no-op while signed out. Unlike
    /// `flushPendingFavoriteOps()` (which replays each queued row against
    /// its own dedicated endpoint), this just delegates to
    /// `pushCurrentSetlistsToServer()`: one successful bulk `.replace` call
    /// satisfies every queued row at once (see this file's header).
    ///
    /// ELI5: "Tell the server about every setlist change I made offline."
    public func flushPendingSetlistOps() async {
        guard await isSignedInNow() else { return }
        guard let ops = try? await offlineStore.pendingSetlistOps(), !ops.isEmpty else { return }
        await pushCurrentSetlistsToServer()
    }

    /// Shared tail of every mutation method above: persists `setlistId`'s
    /// new local state (or its removal), then attempts to push the FULL
    /// current `setlists` array to the server — queuing a `PendingSetlistOp`
    /// for just this setlist if that push fails (offline, maintenance, ...).
    ///
    /// ELI5: "I just changed this setlist locally — save that, then tell the
    /// server everything about all my setlists (or remember to, later)."
    private func persistAndSync(touchedSetlistId: String, operation: PendingSetlistOperationKind) async {
        let snapshot = setlists.first { $0.id == touchedSetlistId }
        if operation == .upsert, let snapshot {
            try? await offlineStore.upsertSetlist(snapshot)
        } else {
            try? await offlineStore.deleteSetlistLocally(touchedSetlistId)
        }

        let succeeded = await pushCurrentSetlistsToServer()
        if !succeeded {
            let pendingOp = PendingSetlistOp(setlistId: touchedSetlistId, operation: operation, snapshot: snapshot)
            try? await offlineStore.enqueuePendingSetlistOp(pendingOp)
        }
    }

    /// Attempts to push the device's CURRENT full `setlists` array to the
    /// server under `mode: .replace` (the client's list IS authoritative —
    /// see `Endpoint.setlistsSync`'s header on why `.replace`, not `.merge`,
    /// is the only mode that can express a deletion). On success, reconciles
    /// the local cache to the server's own returned list AND clears the
    /// ENTIRE pending queue (this one call satisfies every row in it, per
    /// this file's header). A no-op (returns `false`) while signed out.
    ///
    /// - Returns: Whether the push succeeded.
    @discardableResult
    private func pushCurrentSetlistsToServer() async -> Bool {
        guard await isSignedInNow() else { return false }
        guard let authoritative = try? await apiClient.syncSetlists(setlists, mode: .replace) else { return false }
        try? await offlineStore.replaceSetlists(with: authoritative)
        setlists = (try? await offlineStore.allSetlists()) ?? authoritative
        try? await offlineStore.clearAllPendingSetlistOps()
        return true
    }
}

/// Thrown by `shareSetlist(_:existingShareId:)` when asked to share a
/// setlist id this device has no local record of — see that method's own
/// doc comment for why this should be practically unreachable.
struct SetlistNotFoundLocally: Error, CustomStringConvertible {
    let setlistId: String
    var description: String { "No local setlist with id \"\(setlistId)\"." }
}

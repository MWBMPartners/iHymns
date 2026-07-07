// AppRootViewModel+Favorites.swift
// IHFeatures
//
// ELI5: Everything about "which songs have I favourited, and how do I
// add/remove one" — the #181 favourites half of this task, reached through
// `AppRootViewModel` exactly like every other engine operation.
//
// DETAILED: The core of #181. Design, in order:
//   - `favorites` (declared in the primary file — extensions can't add
//     stored properties) is the OFFLINE-FIRST source of truth every screen
//     reads. It's populated from `IHPersistence.OfflineStore`'s local cache
//     FIRST (`loadFavoritesIfNeeded()`, near-instant, works with zero
//     network), and only ever refined afterwards by a best-effort server
//     sync — never the other way around, so a `SongDetailView`'s heart icon
//     or `FavoritesView`'s list never flashes empty while waiting on the
//     network.
//   - `toggleFavorite(...)` is fully OPTIMISTIC: it mutates `favorites` and
//     the on-disk cache FIRST, synchronously from the caller's perspective,
//     THEN attempts the server call. A network failure at that point
//     doesn't roll anything back — it queues a `PendingFavoriteOp`
//     (`IHPersistence.CachedFavorite.swift`) instead, so the toggle still
//     "worked" from the user's perspective (the heart stays filled/
//     unfilled, survives relaunch) and gets told to the server the next
//     time `flushPendingFavoriteOps()` runs with connectivity.
//   - Every method here requires `sessionState.isSignedIn` before touching
//     the network (the `favorites`/`favorites_sync`/`favorites_remove`
//     endpoints are ALL `security: bearerAuth` — `FavoritesEndpoints.swift`'s
//     header) — `SongDetailToolbar`'s heart button is itself `.disabled`
//     when signed out (this task's "gate auth-only affordances on
//     `SessionController.isSignedIn`" instruction), so in practice
//     `toggleFavorite` should never even be REACHABLE while signed out, but
//     this file's own guards are the defensive backstop regardless of which
//     UI path calls in.
import Foundation
import IHAPI
import IHModels
import IHPersistence

extension AppRootViewModel {
    /// Whether `songId` is currently favourited, per the LOCAL cache
    /// mirror (`favorites`) — the check `SongDetailToolbar`'s heart icon and
    /// `SongDetailViewModel.toggleFavorite()` both use.
    ///
    /// ELI5: "Have I favourited this song?"
    public func isFavorite(_ songId: SongID) -> Bool {
        favorites.contains { $0.songId == songId }
    }

    /// Loads `favorites` from the local offline cache, but only once per
    /// app run — safe to call from ANY screen's `.task {}` on every
    /// re-appearance (mirrors `loadCatalogueIfNeeded()`'s identical guard
    /// shape). Near-instant (a local SQLite read via
    /// `IHPersistence.OfflineStore`), so this is deliberately NOT a
    /// `LoadState`-gated network-style load — there is no meaningful
    /// "loading" moment worth showing a spinner for.
    ///
    /// ELI5: "What have I favourited?" — read from the device.
    public func loadFavoritesIfNeeded() async {
        guard !hasLoadedFavoritesFromCache else { return }
        hasLoadedFavoritesFromCache = true
        favorites = (try? await offlineStore.allFavorites()) ?? []
    }

    /// Pulls the server's AUTHORITATIVE favourites list and reconciles the
    /// local cache to match it (`OfflineStore.replaceFavorites(with:)`),
    /// then refreshes the `favorites` mirror from that reconciled cache.
    ///
    /// ELI5: "Ask the server for the real, complete favourites list, and
    /// make my local notebook match it exactly."
    ///
    /// DETAILED: A no-op while signed out (nothing to sync — and calling it
    /// anyway would trip `APIClient`'s "authenticated endpoint needs a
    /// token" precondition, the same reasoning `SessionController.signOut()`
    /// already documents for its own signed-out no-op). Best-effort on
    /// failure: `favorites` is left exactly as the local cache already had
    /// it — a `.offline`/`.maintenance`/`.server` hiccup here must never
    /// blank out favourites the user can see perfectly well offline.
    public func refreshFavoritesFromServer() async {
        guard await isSignedInNow() else { return }
        guard let entries = try? await apiClient.favorites() else { return }
        try? await offlineStore.replaceFavorites(with: entries)
        favorites = (try? await offlineStore.allFavorites()) ?? favorites
    }

    /// Adds or removes `songId` from favourites — optimistic, offline-safe
    /// (see this file's header). A no-op while signed out.
    ///
    /// ELI5: "Tap the heart" — add it if it isn't favourited yet, remove it
    /// if it already is; works instantly even offline, and tells the server
    /// as soon as it can.
    ///
    /// - Parameters:
    ///   - songId: The song to toggle.
    ///   - title: Display title — only used when ADDING (a remove only
    ///     needs the id); `SongDetailViewModel.toggleFavorite()` supplies
    ///     these from its already-loaded `SongDetail`.
    ///   - songbookAbbreviation: The owning songbook's abbreviation.
    ///   - number: The song's number, if any.
    public func toggleFavorite(songId: SongID, title: String, songbookAbbreviation: String, number: Int?) async {
        guard await isSignedInNow() else { return }

        if isFavorite(songId) {
            await removeFavoriteOptimistically(songId)
        } else {
            await addFavoriteOptimistically(
                songId: songId,
                title: title,
                songbookAbbreviation: songbookAbbreviation,
                number: number
            )
        }
    }

    /// Drains every queued `PendingFavoriteOp`, replaying each against the
    /// server — called after every `syncAfterSignIn()` and available for a
    /// future manual "retry now" affordance. A no-op while signed out.
    ///
    /// ELI5: "Tell the server about every favourite change I made while
    /// offline."
    ///
    /// DETAILED: Ops that STILL fail (still offline, or a genuine server
    /// rejection) stay queued for the next flush attempt — this method
    /// never drops a queued op just because one attempt to replay it
    /// failed. A row whose `songId`/`kind` somehow doesn't parse (see
    /// `PendingFavoriteOp.kind`'s own doc comment — practically
    /// unreachable, since this package only ever writes valid values) is
    /// skipped rather than crashing; it's inert dead weight, not a
    /// correctness hazard.
    public func flushPendingFavoriteOps() async {
        guard await isSignedInNow() else { return }
        guard let ops = try? await offlineStore.pendingFavoriteOps() else { return }

        for pendingOp in ops {
            guard let songId = SongID(rawValue: pendingOp.songId), let kind = pendingOp.kind else { continue }
            do {
                switch kind {
                case .add:
                    let favorite = pendingOp.favoriteToReplay() ?? FavoriteSong(songId: songId)
                    _ = try await apiClient.favoritesSync(mode: .merge, favorites: [favorite.asEntry])
                case .remove:
                    try await apiClient.favoritesRemove(songId: songId)
                }
                try? await offlineStore.dequeuePendingFavoriteOp(songId: songId)
            } catch {
                // Still failing — leave it queued for the NEXT flush
                // attempt rather than losing the user's intent.
                continue
            }
        }
    }

    /// The "remove" half of `toggleFavorite(...)` — optimistic local
    /// mutation, then an immediate server attempt, falling back to the
    /// offline queue on failure.
    private func removeFavoriteOptimistically(_ songId: SongID) async {
        favorites.removeAll { $0.songId == songId }
        try? await offlineStore.removeFavorite(songId)

        do {
            try await apiClient.favoritesRemove(songId: songId)
            try? await offlineStore.dequeuePendingFavoriteOp(songId: songId)
        } catch {
            let pendingOp = PendingFavoriteOp(songId: songId, operation: .remove, favorite: nil)
            try? await offlineStore.enqueuePendingFavoriteOp(pendingOp)
        }
    }

    /// The "add" half of `toggleFavorite(...)` — same optimistic-then-sync
    /// -then-queue-on-failure shape as `removeFavoriteOptimistically`
    /// above. Preserves any tags this device already knew about the song
    /// (re-favouriting after a removal, or refining a placeholder server-
    /// only entry) rather than discarding them.
    private func addFavoriteOptimistically(
        songId: SongID,
        title: String,
        songbookAbbreviation: String,
        number: Int?
    ) async {
        let existingTags = favorites.first { $0.songId == songId }?.tags ?? []
        let favorite = FavoriteSong(
            songId: songId,
            title: title,
            songbookAbbreviation: songbookAbbreviation,
            number: number,
            tags: existingTags,
            addedAt: Date()
        )
        favorites.insert(favorite, at: 0)
        try? await offlineStore.upsertFavorite(favorite)

        do {
            _ = try await apiClient.favoritesSync(mode: .merge, favorites: [favorite.asEntry])
            try? await offlineStore.dequeuePendingFavoriteOp(songId: songId)
        } catch {
            let pendingOp = PendingFavoriteOp(songId: songId, operation: .add, favorite: favorite)
            try? await offlineStore.enqueuePendingFavoriteOp(pendingOp)
        }
    }
}

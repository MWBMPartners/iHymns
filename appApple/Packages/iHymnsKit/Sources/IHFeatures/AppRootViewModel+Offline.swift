// AppRootViewModel+Offline.swift
// IHFeatures
//
// ELI5: Everything about "save this whole song so I can read it with no
// internet, and let me manage what I've saved" — the #187 offline-support
// half, reached through `AppRootViewModel` exactly like every other engine
// operation (mirrors `AppRootViewModel+Favorites.swift`/`+Setlists.swift`'s
// own shape).
//
// DETAILED: Unlike favourites/setlists, saved songs have NO server
// counterpart at all — there is no `saved_songs_sync` endpoint, no pending
// -op queue, no sign-in requirement. `SongDetailToolbar`'s "Save for
// Offline" button is deliberately never `.disabled` while signed out
// (unlike its favourite/setlist siblings), so every method here is a purely
// LOCAL, always-available `OfflineStore` pass-through — the exact same thin
// "expose the operation, not the engine" shape
// `AppRootViewModel.cachedSongSummaries()` already establishes in the
// primary file, just with a whole family of methods instead of one. Kept in
// its OWN extension file (rather than added to the primary file) purely
// because a Swift extension can't add new stored properties — every state
// this feature needs (`SongDetailViewModel.isSavedOffline`/
// `isServingCachedCopy`, `OfflineStorageViewModel.savedSongs`/`totalBytes`)
// already lives on ITS OWN focused view model, so `AppRootViewModel.swift`
// itself needs zero new stored properties for this feature at all.
import Foundation
import IHModels
import IHPersistence

extension AppRootViewModel {
    /// Saves `detail`'s full record for offline reading — a thin
    /// `OfflineStore` pass-through, called by `SongDetailViewModel
    /// .toggleOfflineSave()` once a song has successfully loaded.
    ///
    /// ELI5: "Keep a full copy of this song on the device."
    public func saveSongForOffline(_ detail: SongDetail) async throws {
        try await offlineStore.saveSongForOffline(detail)
    }

    /// Removes `songId`'s saved offline copy, if it has one.
    ///
    /// ELI5: "Forget the offline copy of this song."
    public func removeSavedSong(_ songId: SongID) async throws {
        try await offlineStore.removeSavedSong(songId)
    }

    /// Whether `songId` currently has a saved offline copy —
    /// `SongDetailViewModel`'s toolbar-toggle state. Best-effort: a store
    /// read failure degrades to "not saved" rather than throwing, matching
    /// `isFavorite(_:)`'s equally low-stakes "if in doubt, answer the
    /// un-special-cased default" posture.
    ///
    /// ELI5: "Have I already saved this song?"
    public func isSongSavedOffline(_ songId: SongID) async -> Bool {
        (try? await offlineStore.isSongSaved(songId)) ?? false
    }

    /// Fetches `songId`'s saved offline copy, if any — the offline
    /// read-fallback `SongDetailViewModel.loadPrimaryDetail()` reaches for
    /// when the network fetch itself fails with `.offline`/`.maintenance`.
    ///
    /// ELI5: "Give me back the full copy of this song I saved earlier, if I
    /// have one."
    public func savedSongDetail(_ songId: SongID) async -> SongDetail? {
        try? await offlineStore.savedSongDetail(songId)
    }

    /// Every saved song, most-recently-saved first — `OfflineStorageViewModel`'s
    /// data source for the Storage & Offline screen's list.
    ///
    /// ELI5: "What have I saved for offline reading?"
    public func allSavedSongs() async throws -> [SavedSongInfo] {
        try await offlineStore.allSavedSongs()
    }

    /// The total on-disk size, in bytes, of every saved song —
    /// `OfflineStorageViewModel`'s headline "Total Size" figure.
    public func totalSavedSongBytes() async throws -> Int {
        try await offlineStore.totalSavedSongBytes()
    }

    /// How many songs currently have a saved offline copy — a cheap
    /// `COUNT`-only pass-through (#1446's "Your Activity" dashboard's
    /// "Saved Offline" stat tile), rather than fetching every
    /// `SavedSongInfo` row via `allSavedSongs()` just to count them.
    ///
    /// ELI5: "How many songs have I saved for offline reading?"
    public func savedSongCount() async throws -> Int {
        try await offlineStore.savedSongCount()
    }

    /// Deletes every saved song in one shot — the Storage & Offline screen's
    /// confirmed "Remove All Downloads" action.
    ///
    /// ELI5: "Forget every offline copy I've saved."
    public func removeAllSavedSongs() async throws {
        try await offlineStore.removeAllSavedSongs()
    }
}

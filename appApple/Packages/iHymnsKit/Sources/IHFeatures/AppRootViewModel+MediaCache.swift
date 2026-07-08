// AppRootViewModel+MediaCache.swift
// IHFeatures
//
// ELI5: Everything about "download this song's audio/PDF/MIDI/MusicXML file
// so it works offline too" — the #1440 offline-media half, reached through
// `AppRootViewModel` exactly like every other engine operation (mirrors
// `AppRootViewModel+Offline.swift`'s own #187 shape one file over).
//
// DETAILED: Kept in its OWN extension file (rather than folded into
// `+Offline.swift`, which already covers `saved_song`) purely because this
// is a genuinely separate `OfflineStore` table/concern (`cached_media`, see
// `OfflineStore+MediaCache.swift`'s header) — same "one focused file per
// concern" reasoning `+Media.swift`/`+Offline.swift`/`+Favorites.swift`
// already establish, not a case of "a Swift extension can't add stored
// properties" (neither reason is unique to this file, both already apply
// package-wide). Every method here is a thin, `try?`-softened pass-through
// to `offlineStore` — the exact same "expose the operation, not the engine"
// shape `AppRootViewModel+Offline.swift`'s own header documents.
import Foundation
import IHModels
import IHPersistence

extension AppRootViewModel {
    /// The absolute local file `URL` for one cached media asset, if this
    /// device has it — `SongMediaSection`'s "prefer the cached copy" seam
    /// calls this before falling back to `mediaURL(forStreamPath:)`'s
    /// network stream URL.
    ///
    /// ELI5: "Do we already have this file downloaded? If so, where?"
    public func cachedMediaURL(songId: SongID, mediaAssetId: Int) async -> URL? {
        await offlineStore.cachedMediaURL(songId: songId, mediaAssetId: mediaAssetId)
    }

    /// Downloads and permanently caches one media asset's bytes —
    /// `SongDetailViewModel.toggleOfflineSave()` calls this once per
    /// `detail.media` asset right after a successful "Save for Offline,"
    /// per this feature's own GitHub issue's "Rough approach."
    ///
    /// ELI5: "Here are this file's bytes — keep them on the device."
    @discardableResult
    public func cacheMediaForOffline(songId: SongID, asset: SongMediaAsset, data: Data) async throws -> URL {
        try await offlineStore.cacheMedia(songId: songId, asset: asset, data: data)
    }

    /// Removes every media file cached for one song — called alongside
    /// `removeSavedSong(_:)` when a user un-saves a song, so turning off
    /// "Save for Offline" also cleans up its cached media rather than
    /// leaving orphaned files behind.
    ///
    /// ELI5: "Forget every downloaded file for this song."
    public func removeAllCachedMedia(forSong songId: SongID) async throws {
        try await offlineStore.removeAllCachedMedia(forSong: songId)
    }

    /// The total on-disk size, in bytes, of every cached media file across
    /// every song — `OfflineStorageViewModel`'s "Media Downloads" figure.
    public func totalCachedMediaBytes() async throws -> Int {
        try await offlineStore.totalCachedMediaBytes()
    }

    /// Deletes every cached media file for every song in one shot — composed
    /// with `removeAllSavedSongs()` by `OfflineStorageViewModel.removeAll()`
    /// for the Storage & Offline screen's confirmed "Remove All Downloads"
    /// action.
    ///
    /// ELI5: "Forget every downloaded file, for every song."
    public func removeAllCachedMedia() async throws {
        try await offlineStore.removeAllCachedMedia()
    }
}

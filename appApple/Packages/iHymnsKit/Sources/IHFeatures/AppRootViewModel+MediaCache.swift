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
//
// #1439 UPDATE (songbook "Save All for Offline") — `cacheAllMedia(for:
// fetcher:)` below is a NEW shared entry point, extracted from what used to
// be `SongDetailViewModel.cacheMediaForOffline(_:)`'s own private per-asset
// loop. Two independent callers now need the EXACT same "download every one
// of a song's attached media files, best-effort, one asset's failure never
// aborting the rest" behaviour: the per-song "Save for Offline" toggle
// (#1440, still `SongDetailViewModel.toggleOfflineSave()`) and the new
// songbook-level bulk action (`SongbookBulkSaveViewModel`, #1439). Per the
// repo's modularity rule ("when logic exists in more than one place,
// extract it into a shared module"), that loop now lives HERE — the one
// place both `IHFeatures` call sites already reach for every other
// media-cache operation — rather than being copy-pasted into the new bulk
// view model, or the bulk view model reaching into `SongDetailViewModel`'s
// private implementation detail.
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
    ///
    /// `etag`/`lastModified` (#1460) are ADDITIVE, DEFAULTED (`nil`)
    /// pass-throughs to `OfflineStore.cacheMedia(...)` — every pre-#1460
    /// call site (the plain "Save for Offline" download path) is unaffected;
    /// `MediaCacheRevalidator`'s conditional-GET refetch path is the one
    /// caller that actually supplies them.
    @discardableResult
    public func cacheMediaForOffline(songId: SongID, asset: SongMediaAsset, data: Data, etag: String? = nil, lastModified: String? = nil) async throws -> URL {
        try await offlineStore.cacheMedia(songId: songId, asset: asset, data: data, etag: etag, lastModified: lastModified)
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

    /// Downloads and permanently caches EVERY one of `detail.media`'s
    /// attached files (audio/sheet-music/MIDI/MusicXML) — the ONE shared
    /// implementation both `SongDetailViewModel.toggleOfflineSave()` (#1440)
    /// and `SongbookBulkSaveViewModel` (#1439) call into; see this file's
    /// header for why the loop lives here instead of being duplicated.
    ///
    /// ELI5: "Grab every recording/sheet-music/MIDI/MusicXML file attached
    /// to this song so it all works offline too."
    ///
    /// DETAILED: Each asset is downloaded independently and BEST-EFFORT — a
    /// large audio file failing to download over a slow/flaky connection
    /// must never (a) undo whatever song-text save already committed, or
    /// (b) block the OTHER, possibly-smaller attached files (a MIDI file is
    /// typically a few KB) from caching successfully. `fetcher` is injected
    /// (never a hard-coded `URLSession.shared.data(from:)` call here) so
    /// EVERY caller — the real app AND every test — controls how bytes get
    /// fetched, mirroring `SongDetailViewModel`'s own `mediaFetcher`
    /// property one layer up.
    public func cacheAllMedia(
        for detail: SongDetail,
        fetcher: @Sendable (URL) async throws -> Data
    ) async {
        for asset in detail.media {
            guard let url = mediaURL(forStreamPath: asset.streamUrl) else { continue }
            do {
                let data = try await fetcher(url)
                guard !data.isEmpty else { continue }
                try await cacheMediaForOffline(songId: detail.songId, asset: asset, data: data)
            } catch {
                continue
            }
        }
    }

    /// The total on-disk size, in bytes, of every cached media file across
    /// every song — `OfflineStorageViewModel`'s "Media Downloads" figure.
    public func totalCachedMediaBytes() async throws -> Int {
        try await offlineStore.totalCachedMediaBytes()
    }

    /// Every media file cached for one song — `MediaCacheRevalidator`'s
    /// (#1450) starting point: nothing to revalidate for a song with no
    /// cached media at all. Best-effort: a store read failure degrades to
    /// "nothing cached" rather than throwing, matching `isSongSavedOffline(_:)`'s
    /// identical low-stakes posture in `AppRootViewModel+Offline.swift`.
    ///
    /// ELI5: "What files have we saved for this one song?"
    public func cachedMedia(forSong songId: SongID) async -> [CachedMediaInfo] {
        (try? await offlineStore.cachedMedia(forSong: songId)) ?? []
    }

    /// Bumps one cached asset's `lastValidatedAt` without re-downloading it
    /// — `MediaCacheRevalidator`'s (#1450) `.stillFresh` outcome. Best-effort
    /// like every other write in this file: a failure here just means the
    /// next revalidation check runs a little sooner than strictly
    /// necessary, never a user-visible error.
    ///
    /// ELI5: "Note that we just double-checked this file and it's still
    /// good."
    public func markCachedMediaValidated(songId: SongID, mediaAssetId: Int, at date: Date = Date()) async {
        try? await offlineStore.markCachedMediaValidated(songId: songId, mediaAssetId: mediaAssetId, at: date)
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

// MediaCacheRevalidator.swift
// IHFeatures
//
// ELI5: Whenever a song's page finishes loading fresh from the internet, this
// quietly checks any files you've already downloaded for that song (its
// recording, sheet music, ...) against what the server says about them RIGHT
// NOW — and if the server's copy looks different, re-downloads it in the
// background so you're not stuck with an outdated file forever.
//
// DETAILED: #1450 (cached-media staleness). The actual "should we do
// anything?" decision is pure logic in `IHModels.MediaCacheRevalidationPolicy`
// (see that file's header for the full "why sizeBytes + a TTL" reasoning) —
// this type is the I/O glue around it: reading what's currently cached,
// calling the policy per asset, then either bumping a bookkeeping timestamp
// or re-downloading and replacing a stale file, all via `AppRootViewModel`'s
// existing pass-throughs (never a second cache/save path — the ONE write
// happens through `AppRootViewModel.cacheMediaForOffline(songId:asset:data:)`,
// the SAME call `AppRootViewModel.cacheAllMedia(for:fetcher:)` already uses
// for a brand-new download).
//
// TRIGGER — called from `SongDetailViewModel.loadPrimaryDetail()`'s FRESH
// (non-cached-fallback) success path, per this issue's own suggested "Rough
// approach": "whenever `SongDetailViewModel.load()` succeeds." This is
// deliberately the ONLY trigger — there is no background timer, no
// app-launch sweep, no push-driven check. Three consequences worth being
// explicit about:
//   1. SAFE OFFLINE BY CONSTRUCTION — this type is never even invoked
//      unless a REAL, fresh `song_detail` network response just arrived
//      (`SongDetailViewModel`'s offline-fallback branch, which serves a
//      SAVED copy when the network fails, never reaches this call at all).
//      There is no path from "device is offline" to "a cached file gets
//      touched."
//   2. A cached file only gets re-validated when its OWN song is actually
//      opened again — a song saved once and never revisited keeps its
//      original cached copy indefinitely, which is an accepted trade-off
//      (mirrors `SongDetailViewModel.isServingCachedCopy`'s existing
//      lyrics-text staleness gap, per this issue's own "Why it matters"
//      section) rather than a background scan of the whole offline library.
//   3. A FAILED re-download (network hiccup mid-check) leaves the existing
//      cached file completely untouched — `AppRootViewModel.cacheMediaForOffline`'s
//      write only ever happens after new bytes are fully in hand; nothing
//      here ever deletes-then-fails.
import Foundation
import IHModels

/// Re-checks (and, if needed, re-downloads) a song's already-cached media
/// against the metadata a fresh `song_detail` fetch just returned.
///
/// ELI5: "Now that we've got the latest info about this song, make sure any
/// files we already downloaded for it are still up to date."
///
/// `@MainActor` (not actor-isolation-agnostic) purely because every method
/// here reads/writes through `AppRootViewModel`, itself `@MainActor` —
/// matching that, rather than making every call site `await` across an
/// actor boundary for no real concurrency benefit, mirrors how
/// `SongbookBulkSaveViewModel`/every other `IHFeatures` orchestration type
/// in this package is also pinned to the main actor.
@MainActor
enum MediaCacheRevalidator {
    /// Revalidates every asset THIS song already has cached — a no-op (no
    /// I/O at all beyond the initial `cachedMedia(forSong:)` read) for a
    /// song with nothing cached, which is the common case for most song
    /// opens.
    ///
    /// - Parameters:
    ///   - detail: The just-fetched, FRESH `SongDetail` — `detail.media`'s
    ///     `sizeBytes` per asset is this check's whole "does the server's
    ///     metadata still match?" signal (see
    ///     `MediaCacheRevalidationPolicy`'s header for why no extra network
    ///     request is needed to get it).
    ///   - fetcher: The SAME injectable byte-fetcher `SongDetailViewModel`
    ///     already threads through `cacheMediaForOffline(_:)` — real
    ///     `URLSession` in production, a canned closure in tests.
    ///   - rootViewModel: Reached for every read/write, exactly like every
    ///     other `IHFeatures` orchestration type in this package.
    static func revalidate(
        detail: SongDetail,
        fetcher: @Sendable (URL) async throws -> Data,
        rootViewModel: AppRootViewModel
    ) async {
        let cachedAssets = await rootViewModel.cachedMedia(forSong: detail.songId)
        guard !cachedAssets.isEmpty else { return }

        let assetsByID = Dictionary(uniqueKeysWithValues: detail.media.map { ($0.id, $0) })
        for cached in cachedAssets {
            // An asset the server no longer lists at all (removed, not just
            // replaced) has nothing to compare against — leaving the cached
            // copy exactly as-is is the same "never delete just because we
            // can't confirm" safety posture this file's header describes,
            // just triggered by "missing" rather than "unreachable."
            guard let asset = assetsByID[cached.mediaAssetId] else { continue }

            switch MediaCacheRevalidationPolicy.decide(
                cachedSizeBytes: cached.sizeBytes,
                serverSizeBytes: asset.sizeBytes,
                lastValidatedAt: cached.lastValidatedAt
            ) {
            case .skipTooRecent:
                continue

            case .stillFresh:
                await rootViewModel.markCachedMediaValidated(songId: detail.songId, mediaAssetId: asset.id)

            case .staleNeedsRefetch:
                await refetch(asset: asset, songId: detail.songId, fetcher: fetcher, rootViewModel: rootViewModel)
            }
        }
    }

    /// Attempts one asset's re-download — best-effort, mirroring
    /// `AppRootViewModel.cacheAllMedia(for:fetcher:)`'s identical
    /// "one asset's failure never blocks/undoes anything else" posture: on
    /// any failure this simply returns, leaving the existing cached file
    /// (and its `lastValidatedAt`) exactly as they were, so the NEXT
    /// successful song open naturally retries.
    private static func refetch(
        asset: SongMediaAsset,
        songId: SongID,
        fetcher: @Sendable (URL) async throws -> Data,
        rootViewModel: AppRootViewModel
    ) async {
        guard let url = rootViewModel.mediaURL(forStreamPath: asset.streamUrl) else { return }
        do {
            let data = try await fetcher(url)
            guard !data.isEmpty else { return }
            try await rootViewModel.cacheMediaForOffline(songId: songId, asset: asset, data: data)
        } catch {
            // Deliberately silent — see this file's header, point 3.
        }
    }
}

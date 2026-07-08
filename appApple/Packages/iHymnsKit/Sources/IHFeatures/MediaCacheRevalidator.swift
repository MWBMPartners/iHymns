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
//
// #1460 UPDATE — the client half of #1452's backend ETag/Last-Modified +
// 304 support. Per-asset branching (`revalidate(detail:fetcher:
// conditionalFetcher:rootViewModel:)`): an asset that has already picked up
// a stored `etag` (`CachedMediaInfo.etag`) goes through
// `revalidateWithETag(...)` — a REAL conditional GET
// (`conditionalFetcher`, `If-None-Match: <etag>`), gated by the SAME
// `MediaCacheRevalidationPolicy.isDueForRecheck(...)` TTL the sizeBytes path
// always used, and a real `304`/`200` answer decided via
// `MediaCacheRevalidationPolicy.decideConditional(outcome:)`. An asset with
// no stored `etag` yet (every row cached before this migration, or a fresh
// "Save for Offline" download that hasn't been revalidated even once) falls
// straight through to the ORIGINAL, UNCHANGED `decide(...)` sizeBytes+TTL
// heuristic below — per this feature's own "keep the heuristic as the
// fallback" instruction. Either path's successful replacement flows through
// the SAME `AppRootViewModel.cacheMediaForOffline(songId:asset:data:etag:
// lastModified:)` write, capturing whatever `ETag`/`Last-Modified` the
// response carried so the NEXT revalidation for that asset gets to use the
// (strictly stronger) conditional-GET path too.
import Foundation
import IHModels
import IHPersistence

/// What a real conditional media GET's `MediaConditionalFetcher` came back
/// with (#1460) — either the server confirmed the cached bytes are still
/// current (a real `304`, no body) or handed back a fresh copy (`200`) plus
/// that response's own caching headers, ready to store alongside the new
/// bytes.
///
/// `public` (like `MediaConditionalFetcher`/`MediaCacheRevalidator` below)
/// purely because `SongDetailViewModel`'s own `public init` default-argument
/// expression must reference only symbols at least as visible as itself —
/// none of these three are meant to be called from OUTSIDE this package;
/// `SongDetailViewModel` is `IHFeatures`' only real caller either way.
public enum MediaConditionalFetchResult: Sendable {
    case notModified
    case replaced(data: Data, etag: String?, lastModified: String?)
}

/// Fetches one URL conditionally (#1460) — `ifNoneMatch`, when non-`nil`, is
/// sent as the request's `If-None-Match` header; `nil` performs a plain,
/// unconditional GET (the shape a fresh, never-before-cached asset would
/// need, though today `MediaCacheRevalidator` only ever calls this with a
/// non-`nil` `ifNoneMatch` — see `revalidateWithETag(...)`'s own guard).
public typealias MediaConditionalFetcher = @Sendable (URL, _ ifNoneMatch: String?) async throws -> MediaConditionalFetchResult

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
public enum MediaCacheRevalidator {
    /// The real, production `MediaConditionalFetcher` (#1460) — builds a
    /// `URLRequest` with `If-None-Match` set when `ifNoneMatch` is supplied,
    /// and reads back a real `HTTPURLResponse`: a `304` maps to
    /// `.notModified` (no body to parse); any other status is treated as a
    /// fresh `200`-shaped body, capturing the response's OWN `ETag`/
    /// `Last-Modified` headers (never re-using the request's `ifNoneMatch`
    /// value — the server's fresh answer is the only thing worth storing).
    /// Tests inject a canned closure instead, mirroring `SongDetailViewModel
    /// .mediaFetcher`'s identical injectable-fetcher pattern.
    public static let defaultConditionalFetcher: MediaConditionalFetcher = { url, ifNoneMatch in
        var request = URLRequest(url: url)
        if let ifNoneMatch {
            request.setValue(ifNoneMatch, forHTTPHeaderField: "If-None-Match")
        }
        let (data, response) = try await URLSession.shared.data(for: request)
        guard let httpResponse = response as? HTTPURLResponse else {
            return .replaced(data: data, etag: nil, lastModified: nil)
        }
        if httpResponse.statusCode == 304 {
            return .notModified
        }
        return .replaced(
            data: data,
            etag: httpResponse.value(forHTTPHeaderField: "ETag"),
            lastModified: httpResponse.value(forHTTPHeaderField: "Last-Modified")
        )
    }

    /// Revalidates every asset THIS song already has cached — a no-op (no
    /// I/O at all beyond the initial `cachedMedia(forSong:)` read) for a
    /// song with nothing cached, which is the common case for most song
    /// opens.
    ///
    /// - Parameters:
    ///   - detail: The just-fetched, FRESH `SongDetail` — `detail.media`'s
    ///     `sizeBytes` per asset is the sizeBytes-fallback path's whole
    ///     "does the server's metadata still match?" signal (see
    ///     `MediaCacheRevalidationPolicy`'s header for why no extra network
    ///     request is needed to get it).
    ///   - fetcher: The SAME injectable byte-fetcher `SongDetailViewModel`
    ///     already threads through `cacheMediaForOffline(_:)` — used ONLY
    ///     for the sizeBytes-fallback path (an asset with no stored `etag`
    ///     yet); real `URLSession` in production, a canned closure in tests.
    ///   - conditionalFetcher: #1460's real conditional-GET fetcher, used
    ///     ONLY for an asset that already has a stored `etag`. Defaults to
    ///     `defaultConditionalFetcher`; tests inject a canned closure.
    ///   - rootViewModel: Reached for every read/write, exactly like every
    ///     other `IHFeatures` orchestration type in this package.
    static func revalidate(
        detail: SongDetail,
        fetcher: @Sendable (URL) async throws -> Data,
        conditionalFetcher: MediaConditionalFetcher = MediaCacheRevalidator.defaultConditionalFetcher,
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

            // #1460 — an asset that has already picked up a stored `etag`
            // uses the STRICTLY STRONGER real conditional-GET signal
            // instead of the sizeBytes heuristic; everything else falls
            // through to the ORIGINAL, unchanged sizeBytes+TTL path below.
            if cached.etag != nil {
                await revalidateWithETag(cached: cached, asset: asset, conditionalFetcher: conditionalFetcher, rootViewModel: rootViewModel)
                continue
            }

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

    /// #1460 — the conditional-GET path for an asset that already has a
    /// stored `etag`. Applies the SAME `isDueForRecheck` TTL gate the
    /// sizeBytes path's `decide(...)` applies internally (a conditional GET
    /// is still a real request, not free), then sends it and acts on the
    /// server's real 304/200 answer via `decideConditional(outcome:)`. Any
    /// thrown error (network failure, `URLSession` hiccup) is deliberately
    /// silent — the SAME "leave the existing cached file untouched" safety
    /// posture `refetch(...)` documents above. `songId`/`etag` are read off
    /// `cached` (rather than taking their own parameters) purely to stay
    /// under the repo's `function_parameter_count` lint ceiling — every
    /// caller already has `cached` in hand either way.
    private static func revalidateWithETag(
        cached: CachedMediaInfo,
        asset: SongMediaAsset,
        conditionalFetcher: MediaConditionalFetcher,
        rootViewModel: AppRootViewModel
    ) async {
        guard let etag = cached.etag else { return }
        let songId = cached.songId
        guard MediaCacheRevalidationPolicy.isDueForRecheck(lastValidatedAt: cached.lastValidatedAt) else { return }
        guard let url = rootViewModel.mediaURL(forStreamPath: asset.streamUrl) else { return }

        do {
            switch try await conditionalFetcher(url, etag) {
            case .notModified:
                // Routed through the shared policy mapping (rather than
                // acting directly on `.notModified`) purely so ONE function
                // owns "what does a conditional-GET outcome mean," matching
                // `decide(...)`'s own decision-owning role for the sizeBytes
                // path — always `.stillFresh` for this case today.
                if MediaCacheRevalidationPolicy.decideConditional(outcome: .notModified) == .stillFresh {
                    await rootViewModel.markCachedMediaValidated(songId: songId, mediaAssetId: asset.id)
                }

            case .replaced(let data, let freshEtag, let freshLastModified):
                guard !data.isEmpty else { return }
                if MediaCacheRevalidationPolicy.decideConditional(outcome: .replaced) == .staleNeedsRefetch {
                    try await rootViewModel.cacheMediaForOffline(
                        songId: songId, asset: asset, data: data, etag: freshEtag, lastModified: freshLastModified
                    )
                }
            }
        } catch {
            // Deliberately silent — see this file's header, point 3, and
            // `refetch(...)`'s identical posture above.
        }
    }
}

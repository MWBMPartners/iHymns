// MediaCacheRevalidatorTests.swift
// IHFeaturesTests
//
// ELI5: Proves the actual end-to-end wiring for #1450 — that opening a song
// fresh from the network re-checks its cached media, leaves a recently
// -checked file alone, replaces a genuinely stale one, and — the important
// safety property — NEVER touches a cached file at all when the load falls
// back to the offline saved copy instead of a real network response.
//
// DETAILED: Mirrors `SongDetailViewModelMediaCacheTests.swift`'s exact
// harness shape (`makeViewModel()`, `MockTransportLock.shared.withLock`,
// `MockURLProtocol.requestHandler`, a mutated `song_detail` fixture). Each
// test pre-seeds `rootViewModel.offlineStore` directly (`cacheMedia`, then
// `markCachedMediaValidated(at:)` to backdate `lastValidatedAt` PAST
// `MediaCacheRevalidationPolicy.defaultStaleAfter`, the same TTL
// `MediaCacheRevalidator` calls with no override) so each scenario can be
// driven deterministically without waiting a real 24 hours.
import Foundation
import Testing
import IHAPITestSupport
import IHTestFixtures
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

@Suite("MediaCacheRevalidator (#1450)", .serialized)
struct MediaCacheRevalidatorTests {

    private static func response(status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: URL(string: "https://dev.ihymns.app/api")!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    private actor InMemoryTokenStore: TokenStoring {
        private var token: String?
        func save(_ token: String) async throws { self.token = token }
        func load() async throws -> String? { token }
        func delete() async throws { token = nil }
    }

    @MainActor
    private func makeViewModel() throws -> AppRootViewModel {
        let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), retryBaseDelaySeconds: 0.001)
        let sessionController = SessionController(tokenStore: InMemoryTokenStore(), apiClient: apiClient)
        return AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil, mediaCacheDirectory: Self.scratchDirectory()),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient)
        )
    }

    private static func scratchDirectory() -> URL {
        FileManager.default.temporaryDirectory.appendingPathComponent(
            "MediaCacheRevalidatorTests-\(UUID().uuidString)", isDirectory: true
        )
    }

    /// The real `song_detail` fixture with its `song.media` array replaced
    /// by ONE synthetic audio asset of `serverSizeBytes` — mirrors
    /// `SongDetailViewModelMediaCacheTests.fixtureWithOneAudioAsset()`.
    private static func fixture(serverSizeBytes: Int) throws -> Data {
        let raw = try ContractFixtures.songDetail()
        guard var json = try JSONSerialization.jsonObject(with: raw) as? [String: Any],
              var song = json["song"] as? [String: Any] else {
            throw URLError(.cannotParseResponse)
        }
        song["media"] = [[
            "id": 42,
            "kind": "audio",
            "fileName": "amazing-grace.mp3",
            "mimeType": "audio/mpeg",
            "sizeBytes": serverSizeBytes,
            "annotation": "",
            "sortOrder": 0,
            "streamUrl": "/song-media/42"
        ]]
        json["song"] = song
        return try JSONSerialization.data(withJSONObject: json)
    }

    private static let makeAssetStub = SongMediaAsset(
        id: 42, kind: SongMediaAsset.Kind.audio, fileName: "amazing-grace.mp3",
        mimeType: "audio/mpeg", sizeBytes: 100, annotation: "", sortOrder: 0, streamUrl: "/song-media/42"
    )

    /// Pre-seeds asset #42 as already cached with `cachedSizeBytes`, then
    /// backdates `lastValidatedAt` well past the default 24h TTL — every
    /// "stale"/"still fresh" test starts from this state.
    @MainActor
    private func seedStaleCachedAsset(rootViewModel: AppRootViewModel, songId: SongID, cachedSizeBytes: Int) async throws {
        try await rootViewModel.offlineStore.cacheMedia(
            songId: songId,
            asset: Self.makeAssetStub,
            data: Data(repeating: 0, count: cachedSizeBytes)
        )
        let wellPastTTL = Date().addingTimeInterval(-(MediaCacheRevalidationPolicy.defaultStaleAfter + 3_600))
        try await rootViewModel.offlineStore.markCachedMediaValidated(songId: songId, mediaAssetId: 42, at: wellPastTTL)
    }

    @Test("a fresh load whose server sizeBytes still matches leaves the cached file alone, but bumps lastValidatedAt")
    func stillFreshLeavesFileAloneButRevalidates() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try Self.fixture(serverSizeBytes: 100)
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            try await seedStaleCachedAsset(rootViewModel: rootViewModel, songId: songId, cachedSizeBytes: 100)
            let originalURL = await rootViewModel.offlineStore.cachedMediaURL(songId: songId, mediaAssetId: 42)

            // A manually-synchronized counter (not a captured `var`) — the
            // mock `mediaFetcher` is `@Sendable`, so Swift 6 strict
            // concurrency correctly refuses to compile a plain mutable
            // capture here; mirrors `SongDetailViewModelMediaCacheTests
            // .songWithNoMediaCachesNothing()`'s identical fix.
            let fetcherCallCount = LockedCounter()
            let detailViewModel = await SongDetailViewModel(
                songId: songId,
                rootViewModel: rootViewModel,
                mediaFetcher: { _ in
                    fetcherCallCount.increment()
                    return Data(repeating: 9, count: 100)
                }
            )
            await detailViewModel.load()

            #expect(fetcherCallCount.current == 0)
            let afterURL = await rootViewModel.offlineStore.cachedMediaURL(songId: songId, mediaAssetId: 42)
            #expect(afterURL == originalURL)
            let cached = try await rootViewModel.offlineStore.cachedMedia(forSong: songId)
            #expect(cached.first?.lastValidatedAt.timeIntervalSinceNow ?? -999 > -5)
        }
    }

    @Test("a fresh load whose server sizeBytes changed re-downloads and replaces the cached file")
    func staleSizeMismatchTriggersRefetch() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try Self.fixture(serverSizeBytes: 999)
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            try await seedStaleCachedAsset(rootViewModel: rootViewModel, songId: songId, cachedSizeBytes: 100)

            let freshBytes = Data(repeating: 7, count: 999)
            let detailViewModel = await SongDetailViewModel(
                songId: songId,
                rootViewModel: rootViewModel,
                mediaFetcher: { _ in freshBytes }
            )
            await detailViewModel.load()

            let cachedURL = await rootViewModel.offlineStore.cachedMediaURL(songId: songId, mediaAssetId: 42)
            let unwrapped = try #require(cachedURL)
            #expect(try Data(contentsOf: unwrapped) == freshBytes)
            let cached = try await rootViewModel.offlineStore.cachedMedia(forSong: songId)
            #expect(cached.first?.sizeBytes == 999)
        }
    }

    @Test("a re-download failure leaves the existing stale-but-still-present cached file untouched")
    func refetchFailureLeavesOldFileInPlace() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try Self.fixture(serverSizeBytes: 999)
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            try await seedStaleCachedAsset(rootViewModel: rootViewModel, songId: songId, cachedSizeBytes: 100)
            let originalURL = try #require(await rootViewModel.offlineStore.cachedMediaURL(songId: songId, mediaAssetId: 42))
            let originalBytes = try Data(contentsOf: originalURL)

            let detailViewModel = await SongDetailViewModel(
                songId: songId,
                rootViewModel: rootViewModel,
                mediaFetcher: { _ in throw URLError(.notConnectedToInternet) }
            )
            await detailViewModel.load()

            let afterURL = try #require(await rootViewModel.offlineStore.cachedMediaURL(songId: songId, mediaAssetId: 42))
            #expect(afterURL == originalURL)
            #expect(try Data(contentsOf: afterURL) == originalBytes)
        }
    }

    @Test("an offline load (served from the saved-song fallback) never touches cached media at all")
    func offlineFallbackNeverTouchesCachedMedia() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            // No handler at all: every request fails with `.offline`
            // (`MockURLProtocol.startLoading()`'s documented default) —
            // `loadPrimaryDetail()` falls back to the saved-song copy.
            MockURLProtocol.requestHandler = nil

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let fixtureData = try Self.fixture(serverSizeBytes: 999) // "server" would disagree, but is unreachable
            let detail = try APIClient.decodeSongDetail(from: fixtureData)
            try await rootViewModel.offlineStore.saveSongForOffline(detail)
            try await seedStaleCachedAsset(rootViewModel: rootViewModel, songId: songId, cachedSizeBytes: 100)
            let originalURL = try #require(await rootViewModel.offlineStore.cachedMediaURL(songId: songId, mediaAssetId: 42))
            let originalBytes = try Data(contentsOf: originalURL)

            // A manually-synchronized counter (not a captured `var`) — the
            // mock `mediaFetcher` is `@Sendable`, so Swift 6 strict
            // concurrency correctly refuses to compile a plain mutable
            // capture here; mirrors `SongDetailViewModelMediaCacheTests
            // .songWithNoMediaCachesNothing()`'s identical fix.
            let fetcherCallCount = LockedCounter()
            let detailViewModel = await SongDetailViewModel(
                songId: songId,
                rootViewModel: rootViewModel,
                mediaFetcher: { _ in
                    fetcherCallCount.increment()
                    return Data()
                }
            )
            await detailViewModel.load()

            #expect(await detailViewModel.isServingCachedCopy == true)
            #expect(fetcherCallCount.current == 0)
            let afterURL = try #require(await rootViewModel.offlineStore.cachedMediaURL(songId: songId, mediaAssetId: 42))
            #expect(afterURL == originalURL)
            #expect(try Data(contentsOf: afterURL) == originalBytes)
            // The backdated `lastValidatedAt` must also be UNCHANGED — an
            // offline load must not even record that a check happened.
            let cached = try await rootViewModel.offlineStore.cachedMedia(forSong: songId)
            let staleness = try #require(cached.first?.lastValidatedAt)
            #expect(staleness.timeIntervalSinceNow < -MediaCacheRevalidationPolicy.defaultStaleAfter)
        }
    }

    @Test("a song with nothing cached at all is a no-op — no fetcher call, no crash")
    func nothingCachedIsANoOp() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try Self.fixture(serverSizeBytes: 100)
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))

            // A manually-synchronized counter (not a captured `var`) — the
            // mock `mediaFetcher` is `@Sendable`, so Swift 6 strict
            // concurrency correctly refuses to compile a plain mutable
            // capture here; mirrors `SongDetailViewModelMediaCacheTests
            // .songWithNoMediaCachesNothing()`'s identical fix.
            let fetcherCallCount = LockedCounter()
            let detailViewModel = await SongDetailViewModel(
                songId: songId,
                rootViewModel: rootViewModel,
                mediaFetcher: { _ in
                    fetcherCallCount.increment()
                    return Data()
                }
            )
            await detailViewModel.load()

            #expect(fetcherCallCount.current == 0)
            #expect(try await rootViewModel.offlineStore.cachedMedia(forSong: songId).isEmpty)
        }
    }
}

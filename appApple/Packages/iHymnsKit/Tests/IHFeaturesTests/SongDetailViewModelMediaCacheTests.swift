// SongDetailViewModelMediaCacheTests.swift
// IHFeaturesTests
//
// ELI5: Proves that tapping "Save for Offline" on a song that HAS attached
// media also downloads+caches those files (audio/sheet-music/MIDI/
// MusicXML), that un-saving a song forgets its cached media too, and that a
// media download failure never undoes the song-text save itself — all
// against a fake injected `mediaFetcher`, never a real network call. Covers
// the #1440 offline-media-caching half of `SongDetailViewModel`.
//
// DETAILED: Mirrors `SongDetailViewModelOfflineTests.swift`'s exact harness
// shape (`makeViewModel()`, `MockTransportLock.shared.withLock`,
// `MockURLProtocol.requestHandler`) for the PRIMARY `song_detail` fetch —
// but that fixture's own `media` array is empty (`Tests/Fixtures/
// song_detail.json`, recorded before #184/#1440 existed), so every test
// below mutates a copy of the raw fixture JSON to inject one synthetic
// `SongMediaAsset` before feeding it to `MockURLProtocol`. The SEPARATE
// media-bytes fetch (`SongDetailViewModel`'s new `mediaFetcher` parameter)
// is stubbed independently — deliberately NOT routed through
// `MockURLProtocol` at all, mirroring `MediaDownloadViewModelTests`/
// `SheetMusicViewModelTests`' identical "inject a canned fetcher closure"
// posture for a media-bytes fetch that bypasses the `?action=` dispatcher
// entirely (`SongAudioPlaybackEngine.swift`'s own header explains why).
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

@Suite("SongDetailViewModel offline media caching (#1440)", .serialized)
struct SongDetailViewModelMediaCacheTests {

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

    /// Same shape as `SongDetailViewModelOfflineTests.makeViewModel()`.
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
            "SongDetailViewModelMediaCacheTests-\(UUID().uuidString)", isDirectory: true
        )
    }

    /// Loads the real `song_detail` fixture and injects ONE synthetic
    /// `"audio"`-kind media asset into its `song.media` array — see this
    /// file's header for why a raw JSON mutation, not a Swift value copy,
    /// is the simplest way to get a `SongDetail` with media through the
    /// SAME decode path `SongDetailViewModel.load()` actually uses.
    private static func fixtureWithOneAudioAsset() throws -> Data {
        let raw = try ContractFixtures.songDetail()
        var json = try #require(try JSONSerialization.jsonObject(with: raw) as? [String: Any])
        var song = try #require(json["song"] as? [String: Any])
        song["media"] = [[
            "id": 42,
            "kind": "audio",
            "fileName": "amazing-grace.mp3",
            "mimeType": "audio/mpeg",
            "sizeBytes": 12345,
            "annotation": "",
            "sortOrder": 0,
            "streamUrl": "/song-media/42"
        ]]
        json["song"] = song
        return try JSONSerialization.data(withJSONObject: json)
    }

    @Test("toggleOfflineSave() also caches the song's attached media")
    func toggleOfflineSaveCachesMedia() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try Self.fixtureWithOneAudioAsset()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let mediaBytes = Data([0xFF, 0xFB, 0x90]) // a plausible MP3 frame header
            let detailViewModel = await SongDetailViewModel(
                songId: songId,
                rootViewModel: rootViewModel,
                mediaFetcher: { _ in mediaBytes }
            )

            await detailViewModel.load()
            await detailViewModel.toggleOfflineSave()

            #expect(await detailViewModel.isSavedOffline == true)
            let cached = try await rootViewModel.offlineStore.cachedMedia(forSong: songId)
            #expect(cached.count == 1)
            #expect(cached.first?.mediaAssetId == 42)
            #expect(cached.first?.sizeBytes == mediaBytes.count)

            let cachedURL = await rootViewModel.offlineStore.cachedMediaURL(songId: songId, mediaAssetId: 42)
            let unwrappedURL = try #require(cachedURL)
            #expect(try Data(contentsOf: unwrappedURL) == mediaBytes)
        }
    }

    @Test("toggling offline save OFF also removes the song's cached media")
    func togglingOffRemovesCachedMedia() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try Self.fixtureWithOneAudioAsset()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(
                songId: songId,
                rootViewModel: rootViewModel,
                mediaFetcher: { _ in Data([1, 2, 3]) }
            )

            await detailViewModel.load()
            await detailViewModel.toggleOfflineSave() // on: saves + caches media
            #expect(try await rootViewModel.offlineStore.cachedMedia(forSong: songId).count == 1)

            await detailViewModel.toggleOfflineSave() // off: removes both
            #expect(await detailViewModel.isSavedOffline == false)
            #expect(try await rootViewModel.offlineStore.cachedMedia(forSong: songId).isEmpty)
        }
    }

    @Test("a media download failure never undoes the song-text save itself")
    func mediaDownloadFailureDoesNotUndoSongSave() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try Self.fixtureWithOneAudioAsset()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(
                songId: songId,
                rootViewModel: rootViewModel,
                mediaFetcher: { _ in throw URLError(.notConnectedToInternet) }
            )

            await detailViewModel.load()
            await detailViewModel.toggleOfflineSave()

            // The song's lyrics are still saved even though the media
            // fetch failed — a slow/flaky media download must never block
            // or undo the text-only save that already committed.
            #expect(await detailViewModel.isSavedOffline == true)
            #expect(try await rootViewModel.offlineStore.isSongSaved(songId) == true)
            #expect(try await rootViewModel.offlineStore.cachedMedia(forSong: songId).isEmpty)
        }
    }

    @Test("a song with NO media attached caches nothing, with no error")
    func songWithNoMediaCachesNothing() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            // The real fixture's own `media` array is empty — no mutation
            // needed for this one.
            let fixture = try ContractFixtures.songDetail()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            // A manually-synchronized counter (not a captured `var`) —
            // `mediaFetcher` is `@Sendable`, so Swift 6 strict concurrency
            // correctly refuses to compile a plain mutable capture here;
            // `LockedCounter` is the SAME fix `MockURLProtocol.swift`'s own
            // header already documents for the identical shape.
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
            await detailViewModel.toggleOfflineSave()

            #expect(await detailViewModel.isSavedOffline == true)
            #expect(fetcherCallCount.current == 0)
            #expect(try await rootViewModel.offlineStore.cachedMedia(forSong: songId).isEmpty)
        }
    }
}

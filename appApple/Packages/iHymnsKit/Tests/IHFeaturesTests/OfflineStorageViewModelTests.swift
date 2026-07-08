// OfflineStorageViewModelTests.swift
// IHFeaturesTests
//
// ELI5: Proves the "Storage & Offline" screen's numbers actually add up —
// "Total Size" includes BOTH saved-song lyrics AND cached media (#1440),
// "Media Downloads" reports just the media half, and removing one song (or
// everything) forgets its cached media too, not just its lyrics. No real
// network is ever touched — this view model only ever talks to
// `OfflineStore` through `AppRootViewModel`'s pass-throughs.
//
// DETAILED: Builds a real `AppRootViewModel` with a REAL (but in-memory
// SQLite + throwaway temp-directory-backed) `OfflineStore`, exactly like
// `SongDetailViewModelOfflineTests`/`SongDetailViewModelMediaCacheTests` do
// — but never sets `MockURLProtocol.requestHandler`/wraps in
// `MockTransportLock`, since nothing in this suite ever issues a network
// request at all (`OfflineStorageViewModel` reads/writes are purely local).
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

@Suite("OfflineStorageViewModel (#1440 media-cache totals)")
struct OfflineStorageViewModelTests {

    private actor InMemoryTokenStore: TokenStoring {
        private var token: String?
        func save(_ token: String) async throws { self.token = token }
        func load() async throws -> String? { token }
        func delete() async throws { token = nil }
    }

    private static func scratchDirectory() -> URL {
        FileManager.default.temporaryDirectory.appendingPathComponent(
            "OfflineStorageViewModelTests-\(UUID().uuidString)", isDirectory: true
        )
    }

    @MainActor
    private func makeViewModel() throws -> AppRootViewModel {
        let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
        let sessionController = SessionController(tokenStore: InMemoryTokenStore(), apiClient: apiClient)
        return AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil, mediaCacheDirectory: Self.scratchDirectory()),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient)
        )
    }

    /// Mirrors `OfflineStoreSavedSongsTests.makeDetail(...)` — a minimal,
    /// valid `SongDetail` this suite only uses as an opaque "something to
    /// save" payload.
    private static func makeDetail(songId: String, title: String) -> SongDetail {
        SongDetail(
            songId: SongID(rawValue: songId) ?? SongID(songbookAbbreviation: "MP", number: 1),
            number: 1,
            title: title,
            songbookAbbreviation: "MP",
            songbookName: "Mission Praise",
            language: "en",
            copyright: "",
            tuneName: "",
            ccli: "",
            iswc: "",
            verified: false,
            lyricsPublicDomain: false,
            musicPublicDomain: false,
            hasAudio: false,
            hasSheetMusic: false,
            originCity: "",
            originCityId: nil,
            publicId: nil,
            arrangement: nil,
            writers: [],
            composers: [],
            arrangers: [],
            adaptors: [],
            translators: [],
            artists: [],
            components: [SongComponent(type: "verse", number: 1, lines: ["Line one"], chords: nil, language: nil, lineIds: [1], lineLanguages: nil)],
            tags: [],
            alternativeTitles: [],
            links: [],
            works: [],
            media: [],
            translations: nil,
            annotations: nil,
            royaltyIds: nil
        )
    }

    private static func makeAsset(id: Int) -> SongMediaAsset {
        SongMediaAsset(id: id, kind: "audio", fileName: "a.mp3", mimeType: "audio/mpeg", sizeBytes: 0, annotation: "", sortOrder: 0, streamUrl: "/song-media/\(id)")
    }

    @Test("totalSizeDisplay/mediaSizeDisplay start at zero with nothing saved")
    func startsAtZero() async throws {
        let rootViewModel = try await makeViewModel()
        let viewModel = await OfflineStorageViewModel(rootViewModel: rootViewModel)
        await viewModel.loadIfNeeded()

        #expect(await viewModel.totalMediaBytes == 0)
        #expect(await viewModel.mediaSizeDisplay == ByteCountFormatter.string(fromByteCount: 0, countStyle: .file))
    }

    @Test("Total Size combines saved-song bytes AND cached-media bytes")
    func totalSizeCombinesBothSources() async throws {
        let rootViewModel = try await makeViewModel()
        let songId = try #require(SongID(rawValue: "MP-1"))
        try await rootViewModel.offlineStore.saveSongForOffline(Self.makeDetail(songId: "MP-1", title: "Song"))
        _ = try await rootViewModel.offlineStore.cacheMedia(songId: songId, asset: Self.makeAsset(id: 1), data: Data(repeating: 0, count: 100))

        let viewModel = await OfflineStorageViewModel(rootViewModel: rootViewModel)
        await viewModel.loadIfNeeded()

        let lyricsBytes = try await rootViewModel.totalSavedSongBytes()
        #expect(await viewModel.totalMediaBytes == 100)
        #expect(await viewModel.totalSizeDisplay == ByteCountFormatter.string(fromByteCount: Int64(lyricsBytes + 100), countStyle: .file))
    }

    @Test("remove(_:) forgets both the saved song AND its cached media")
    func removeCascadesIntoMediaCache() async throws {
        let rootViewModel = try await makeViewModel()
        let songId = try #require(SongID(rawValue: "MP-1"))
        try await rootViewModel.offlineStore.saveSongForOffline(Self.makeDetail(songId: "MP-1", title: "Song"))
        _ = try await rootViewModel.offlineStore.cacheMedia(songId: songId, asset: Self.makeAsset(id: 1), data: Data([1, 2, 3]))

        let viewModel = await OfflineStorageViewModel(rootViewModel: rootViewModel)
        await viewModel.loadIfNeeded()
        await viewModel.remove(songId)

        #expect(try await rootViewModel.offlineStore.isSongSaved(songId) == false)
        #expect(try await rootViewModel.offlineStore.cachedMedia(forSong: songId).isEmpty)
        #expect(await viewModel.totalMediaBytes == 0)
    }

    @Test("removeAll() forgets every saved song AND every cached media file")
    func removeAllCascadesIntoMediaCache() async throws {
        let rootViewModel = try await makeViewModel()
        let songOne = try #require(SongID(rawValue: "MP-1"))
        let songTwo = try #require(SongID(rawValue: "MP-2"))
        try await rootViewModel.offlineStore.saveSongForOffline(Self.makeDetail(songId: "MP-1", title: "One"))
        try await rootViewModel.offlineStore.saveSongForOffline(Self.makeDetail(songId: "MP-2", title: "Two"))
        _ = try await rootViewModel.offlineStore.cacheMedia(songId: songOne, asset: Self.makeAsset(id: 1), data: Data([1]))
        _ = try await rootViewModel.offlineStore.cacheMedia(songId: songTwo, asset: Self.makeAsset(id: 1), data: Data([2]))

        let viewModel = await OfflineStorageViewModel(rootViewModel: rootViewModel)
        await viewModel.loadIfNeeded()
        await viewModel.removeAll()

        #expect(await viewModel.savedSongs.isEmpty)
        #expect(await viewModel.totalMediaBytes == 0)
        #expect(try await rootViewModel.totalCachedMediaBytes() == 0)
    }
}

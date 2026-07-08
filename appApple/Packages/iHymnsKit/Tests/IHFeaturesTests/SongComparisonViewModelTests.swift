// SongComparisonViewModelTests.swift
// IHFeaturesTests
//
// ELI5: Proves the comparison screen's helper actually fetches both songs,
// falls back to an offline saved copy on the failing side only, reports one
// combined error when a side can't be shown at all, and swaps sides without
// re-fetching. No real network is ever touched.
//
// DETAILED: #1445. Mirrors `SongDetailViewModelOfflineTests.swift`'s exact
// harness shape (`makeViewModel()`, `MockTransportLock.shared.withLock`,
// `MockURLProtocol.requestHandler` inspecting the request URL to serve
// different songs per side) — the same "real production types, faked
// transport only" posture every IHFeatures integration test in this
// package uses.
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

@Suite("SongComparisonViewModel (#1445)", .serialized)
struct SongComparisonViewModelTests {

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

    /// Mirrors `OfflineStorageViewModelTests.makeDetail(...)` — a minimal,
    /// valid `SongDetail` this suite only uses as an opaque "something to
    /// pre-seed the offline cache with" payload.
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

    /// Same shape as `SongDetailViewModelOfflineTests.makeViewModel()` —
    /// a near-zero retry delay so `.offline`'s retry loop doesn't burn real
    /// seconds in these tests.
    @MainActor
    private func makeViewModel() throws -> AppRootViewModel {
        let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), retryBaseDelaySeconds: 0.001)
        let sessionController = SessionController(tokenStore: InMemoryTokenStore(), apiClient: apiClient)
        return AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient)
        )
    }

    @Test("load() succeeds on both sides and produces non-empty pairs")
    func bothSidesSucceed() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try ContractFixtures.songDetail()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let primaryId = try #require(SongID(rawValue: "MP-0031"))
            let secondaryId = try #require(SongID(rawValue: "MP-0032"))
            let viewModel = await SongComparisonViewModel(primaryId: primaryId, secondaryId: secondaryId, rootViewModel: rootViewModel)

            await viewModel.load()

            #expect(await viewModel.isReady)
            #expect(await !viewModel.pairs.isEmpty)
            #expect(await viewModel.combinedErrorMessage == nil)
        }
    }

    @Test("a side that's offline with a saved copy falls back to it; the other side is a fresh success")
    func oneSideFallsBackWhileOtherSucceeds() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let primaryId = try #require(SongID(rawValue: "MP-0031"))
            let secondaryId = try #require(SongID(rawValue: "MP-0099"))

            let rootViewModel = try await makeViewModel()
            // Pre-seed ONLY the secondary song's offline copy.
            let secondaryCached = Self.makeDetail(songId: "MP-0099", title: "Cached Secondary")
            try await rootViewModel.offlineStore.saveSongForOffline(secondaryCached)

            let fixture = try ContractFixtures.songDetail()
            MockURLProtocol.requestHandler = { request in
                let urlString = request.url?.absoluteString ?? ""
                if urlString.contains("MP-0031") {
                    return (Self.response(status: 200), fixture)
                }
                // The secondary song's request always fails — the mock
                // transport reports every unhandled request as `.offline`
                // (see `SongDetailViewModelOfflineTests`'s identical note).
                throw URLError(.notConnectedToInternet)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let viewModel = await SongComparisonViewModel(primaryId: primaryId, secondaryId: secondaryId, rootViewModel: rootViewModel)
            await viewModel.load()

            guard case .loaded = await viewModel.primaryState else {
                Issue.record("Expected primary .loaded, got \(await viewModel.primaryState)")
                return
            }
            #expect(await viewModel.isServingCachedCopyPrimary == false)

            guard case .loaded(let secondaryLoaded) = await viewModel.secondaryState else {
                Issue.record("Expected secondary .loaded (from the offline cache), got \(await viewModel.secondaryState)")
                return
            }
            #expect(secondaryLoaded.title == "Cached Secondary")
            #expect(await viewModel.isServingCachedCopySecondary == true)
            #expect(await viewModel.combinedErrorMessage == nil)
        }
    }

    @Test("a side that's offline with NO saved copy surfaces one combined error")
    func offlineWithNoSavedCopySurfacesCombinedError() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let primaryId = try #require(SongID(rawValue: "MP-0031"))
            let secondaryId = try #require(SongID(rawValue: "MP-0099"))
            let rootViewModel = try await makeViewModel()

            let fixture = try ContractFixtures.songDetail()
            MockURLProtocol.requestHandler = { request in
                let urlString = request.url?.absoluteString ?? ""
                if urlString.contains("MP-0031") {
                    return (Self.response(status: 200), fixture)
                }
                throw URLError(.notConnectedToInternet)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let viewModel = await SongComparisonViewModel(primaryId: primaryId, secondaryId: secondaryId, rootViewModel: rootViewModel)
            await viewModel.load()

            #expect(await viewModel.isReady == false)
            #expect(await viewModel.combinedErrorMessage != nil)

            guard case .error = await viewModel.secondaryState else {
                Issue.record("Expected secondary .error, got \(await viewModel.secondaryState)")
                return
            }
        }
    }

    @Test("swapSides() flips which song is primary/secondary without refetching")
    func swapSidesFlipsWithoutRefetching() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try ContractFixtures.songDetail()
            let callCount = LockedCounter()
            MockURLProtocol.requestHandler = { _ in
                callCount.increment()
                return (Self.response(status: 200), fixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let primaryId = try #require(SongID(rawValue: "MP-0031"))
            let secondaryId = try #require(SongID(rawValue: "MP-0032"))
            let viewModel = await SongComparisonViewModel(primaryId: primaryId, secondaryId: secondaryId, rootViewModel: rootViewModel)

            await viewModel.load()
            let callsAfterLoad = callCount.current

            await viewModel.swapSides()

            #expect(await viewModel.primaryId == secondaryId)
            #expect(await viewModel.secondaryId == primaryId)
            // No new network activity — a pure state swap.
            #expect(callCount.current == callsAfterLoad)
            #expect(await viewModel.isReady)
        }
    }
}

// SongEntityResolverTests.swift
// IHFeaturesTests
//
// ELI5: Checks the PURE "which songs match this search / these ids" logic
// `SongEntityQuery` delegates to, plus how `SongEntity` itself maps from a
// `SongSummary`/`RecentlyViewedSong` — again, all without touching
// `AppDependencyManager`. Also proves `AppRootViewModel
// .songSummariesForIntents()`'s offline-first fallback ladder.
//
// #1415 (App Intents / Siri / Shortcuts / Spotlight). Wraps the network-
// backed tests in `MockTransportLock.shared.withLock` for the same
// cross-suite-exclusivity reason `CatalogueAndSongDetailLoadingTests` does.
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

@Suite("SongEntityResolver + SongEntity + songSummariesForIntents (#1415)", .serialized)
struct SongEntityResolverTests {

    private func summary(_ abbr: String, _ number: Int, title: String, songbookName: String = "Mission Praise") -> SongSummary {
        SongSummary(
            songId: SongID(songbookAbbreviation: abbr, number: number),
            title: title,
            songbookAbbreviation: abbr,
            number: number,
            songbookName: songbookName
        )
    }

    // MARK: - SongEntityResolver.resolve

    @Test("resolve preserves the requested identifier order")
    func resolvePreservesOrder() {
        let songs = [summary("MP", 1, title: "First"), summary("MP", 2, title: "Second")]
        let resolved = SongEntityResolver.resolve(["MP-2", "MP-1"], in: songs)
        #expect(resolved.map(\.id) == ["MP-2", "MP-1"])
    }

    @Test("resolve silently drops an unknown identifier")
    func resolveDropsUnknownIdentifier() {
        let songs = [summary("MP", 1, title: "First")]
        let resolved = SongEntityResolver.resolve(["MP-1", "MP-999"], in: songs)
        #expect(resolved.map(\.id) == ["MP-1"])
    }

    // MARK: - SongEntityResolver.match

    @Test("match delegates to AppRootViewModel.songsMatching — title substring")
    func matchDelegatesTitleSubstring() {
        let songs = [summary("MP", 1, title: "Amazing Grace"), summary("MP", 2, title: "Holy Holy Holy")]
        let matched = SongEntityResolver.match("amazing", in: songs)
        #expect(matched.map(\.title) == ["Amazing Grace"])
    }

    @Test("match delegates to AppRootViewModel.songsMatching — number mode")
    func matchDelegatesNumberMode() {
        let songs = [summary("MP", 1008, title: "Amazing Grace"), summary("MP", 2, title: "Other")]
        let matched = SongEntityResolver.match("MP 1008", in: songs)
        #expect(matched.map(\.title) == ["Amazing Grace"])
    }

    @Test("match caps results at the 20-song limit")
    func matchCapsAtLimit() {
        let songs = (1...30).map { summary("MP", $0, title: "Song \($0)") }
        let matched = SongEntityResolver.match("Song", in: songs)
        #expect(matched.count == 20)
    }

    // MARK: - SongEntity mapping

    @Test("SongEntity(summary:) maps id/title/subtitle from a SongSummary")
    func songEntityMapsFromSummary() {
        let entity = SongEntity(summary: summary("MP", 1008, title: "Amazing Grace"))
        #expect(entity.id == "MP-1008")
        #expect(entity.title == "Amazing Grace")
        #expect(entity.subtitle == "Mission Praise")
    }

    @Test("SongEntity(recentlyViewed:) maps id/title/subtitle from a RecentlyViewedSong")
    func songEntityMapsFromRecentlyViewed() {
        let recent = RecentlyViewedSong(
            songId: SongID(songbookAbbreviation: "MP", number: 1008),
            title: "Amazing Grace",
            songbookAbbreviation: "MP",
            number: 1008
        )
        let entity = SongEntity(recentlyViewed: recent)
        #expect(entity.id == "MP-1008")
        #expect(entity.title == "Amazing Grace")
        #expect(entity.subtitle == "MP")
    }

    // MARK: - AppRootViewModel.songSummariesForIntents() fallback ladder

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
        let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
        let sessionController = SessionController(tokenStore: InMemoryTokenStore(), apiClient: apiClient)
        return AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient)
        )
    }

    @Test("songSummariesForIntents() returns the already-loaded catalogue when there is one")
    func songSummariesForIntentsReturnsLoadedCatalogue() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try ContractFixtures.songsIndex()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let viewModel = try await makeViewModel()
            await viewModel.loadCatalogue()

            let summaries = await viewModel.songSummariesForIntents()
            #expect(!summaries.isEmpty)
        }
    }

    @Test("songSummariesForIntents() falls back to the offline cache when nothing is loaded yet")
    func songSummariesForIntentsFallsBackToOfflineCache() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            // No catalogue load ever happens in this test — the offline
            // store already has a row (e.g. from a previous app run's
            // sync), and `catalogueLoadState` stays `.idle`.
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 500), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            let viewModel = try await makeViewModel()
            await viewModel.syncSystemIndexes([summary("MP", 1, title: "Cached Song")])

            let summaries = await viewModel.songSummariesForIntents()
            #expect(summaries.map(\.title) == ["Cached Song"])
        }
    }

    @Test("songSummariesForIntents() degrades to an empty list when every source fails")
    func songSummariesForIntentsDegradesToEmpty() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 500), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            let viewModel = try await makeViewModel()
            let summaries = await viewModel.songSummariesForIntents()
            #expect(summaries.isEmpty)
        }
    }
}

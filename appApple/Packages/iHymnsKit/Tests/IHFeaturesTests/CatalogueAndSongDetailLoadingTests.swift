// CatalogueAndSongDetailLoadingTests.swift
// IHFeaturesTests
//
// ELI5: Proves the #1399 E2E slice's two view-model load paths actually
// work against the SAME real fixture bytes the other test targets decode —
// `AppRootViewModel.loadCatalogue()` (the song list) and
// `SongDetailViewModel.load()` (one song) — both success and failure
// (mapped-to-copy) cases, with no real network ever touched.
//
// DETAILED: Wraps every test in `MockTransportLock.shared.withLock` for the
// same cross-suite-exclusivity reason `IHAPITests`/`IHAuthTests` do (see
// `MockTransportLock`'s own header) — this suite is a THIRD consumer of the
// same shared `MockURLProtocol.requestHandler`.
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

@Suite("Catalogue + song detail loading (#1399)", .serialized)
struct CatalogueAndSongDetailLoadingTests {

    private static func response(status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: URL(string: "https://dev.ihymns.app/api")!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    /// A test-only in-memory `TokenStoring` — mirrors `IHAuthTests`' fake
    /// so this suite never touches the real Keychain either.
    private actor InMemoryTokenStore: TokenStoring {
        private var token: String?
        func save(_ token: String) async throws { self.token = token }
        func load() async throws -> String? { token }
        func delete() async throws { token = nil }
    }

    /// Builds a real `AppRootViewModel` wired to a `MockURLProtocol`-backed
    /// `APIClient` — every engine is the real production type, only the
    /// transport underneath `APIClient` is faked.
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

    @Test("loadCatalogue() decodes the real songs_index fixture into .loaded")
    func loadCatalogueSucceeds() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try ContractFixtures.songsIndex()
            MockURLProtocol.requestHandler = { _ in
                (Self.response(status: 200), fixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let viewModel = try await makeViewModel()
            await viewModel.loadCatalogue()

            // Same 74-of-84 count every other fixture-decoding test in this
            // package asserts (10 rows have a legacy id `SongID` can't
            // parse — see `Tests/Fixtures/README.md`).
            guard case .loaded(let songs) = await viewModel.catalogueLoadState else {
                Issue.record("Expected .loaded, got \(await viewModel.catalogueLoadState)")
                return
            }
            #expect(songs.count == 74)
        }
    }

    @Test("loadCatalogueIfNeeded() only fetches once — a second call is a no-op while already loaded")
    func loadCatalogueIfNeededFetchesOnlyOnce() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try ContractFixtures.songsIndex()
            let callCount = LockedCounter()
            MockURLProtocol.requestHandler = { _ in
                callCount.increment()
                return (Self.response(status: 200), fixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let viewModel = try await makeViewModel()
            await viewModel.loadCatalogueIfNeeded()
            await viewModel.loadCatalogueIfNeeded()

            #expect(callCount.current == 1)
        }
    }

    @Test("loadCatalogue() maps a 503 to the temporarily-unavailable copy")
    func loadCatalogueMapsMaintenanceError() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 503), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            let viewModel = try await makeViewModel()
            await viewModel.loadCatalogue()

            guard case .error(let message) = await viewModel.catalogueLoadState else {
                Issue.record("Expected .error, got \(await viewModel.catalogueLoadState)")
                return
            }
            #expect(message.contains("temporarily unavailable"))
        }
    }

    @Test("filteredSongs narrows the loaded catalogue by title/number/songbook, case-insensitively")
    func filteredSongsNarrowsByTextSearch() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try ContractFixtures.songsIndex()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let viewModel = try await makeViewModel()
            await viewModel.loadCatalogue()
            let totalCount = await viewModel.filteredSongs.count
            #expect(totalCount == 74)

            await MainActor.run { viewModel.searchText = "amazing grace" }
            let filteredCount = await viewModel.filteredSongs.count
            #expect(filteredCount > 0)
            #expect(filteredCount < totalCount)
        }
    }

    @Test("SongDetailViewModel.load() decodes the real song_detail fixture into .loaded")
    func songDetailViewModelLoadSucceeds() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try ContractFixtures.songDetail()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(songId: songId, rootViewModel: rootViewModel)

            await detailViewModel.load()

            guard case .loaded(let detail) = await detailViewModel.loadState else {
                Issue.record("Expected .loaded, got \(await detailViewModel.loadState)")
                return
            }
            #expect(detail.title == "Amazing grace")
            #expect(detail.components.count == 4)
        }
    }

    @Test("SongDetailViewModel.load() also populates songLinksState/relatedSongsState (#180)")
    func songDetailViewModelLoadPopulatesSecondaryShelves() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let detailFixture = try ContractFixtures.songDetail()
            let linksFixture = try ContractFixtures.songLinks()
            let relatedFixture = try ContractFixtures.relatedSongs()
            // Routes each of the 3 concurrent requests to its OWN fixture by
            // `action=`, rather than the other tests' "same bytes for every
            // request" shortcut — needed here because this test asserts on
            // all three resulting states, not just the primary one.
            MockURLProtocol.requestHandler = { request in
                let action = request.url?.query?.contains("action=song_links") == true ? "song_links"
                    : request.url?.query?.contains("action=related_songs") == true ? "related_songs"
                    : "song_detail"
                switch action {
                case "song_links": return (Self.response(status: 200), linksFixture)
                case "related_songs": return (Self.response(status: 200), relatedFixture)
                default: return (Self.response(status: 200), detailFixture)
                }
            }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(songId: songId, rootViewModel: rootViewModel)

            await detailViewModel.load()

            guard case .loaded(let detail) = await detailViewModel.loadState else {
                Issue.record("Expected .loaded, got \(await detailViewModel.loadState)")
                return
            }
            #expect(detail.title == "Amazing grace")

            guard case .loaded(let linkGroup) = await detailViewModel.songLinksState else {
                Issue.record("Expected .loaded, got \(await detailViewModel.songLinksState)")
                return
            }
            #expect(linkGroup.hasCounterparts == false)

            guard case .loaded(let related) = await detailViewModel.relatedSongsState else {
                Issue.record("Expected .loaded, got \(await detailViewModel.relatedSongsState)")
                return
            }
            #expect(related.count == 10)
        }
    }

    @Test("SongDetailViewModel.load() still loads the primary song when the secondary shelves fail")
    func songDetailViewModelPrimaryLoadSurvivesSecondaryFailure() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let detailFixture = try ContractFixtures.songDetail()
            MockURLProtocol.requestHandler = { request in
                let isSecondary = request.url?.query?.contains("action=song_links") == true
                    || request.url?.query?.contains("action=related_songs") == true
                if isSecondary {
                    return (Self.response(status: 500), Data())
                }
                return (Self.response(status: 200), detailFixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(songId: songId, rootViewModel: rootViewModel)

            await detailViewModel.load()

            guard case .loaded(let detail) = await detailViewModel.loadState else {
                Issue.record("Expected primary .loaded despite secondary failures, got \(await detailViewModel.loadState)")
                return
            }
            #expect(detail.title == "Amazing grace")
            guard case .error = await detailViewModel.songLinksState else {
                Issue.record("Expected .error, got \(await detailViewModel.songLinksState)")
                return
            }
            guard case .error = await detailViewModel.relatedSongsState else {
                Issue.record("Expected .error, got \(await detailViewModel.relatedSongsState)")
                return
            }
        }
    }

    @Test("SongDetailViewModel.loadIfNeeded() only fetches once")
    func songDetailViewModelLoadIfNeededFetchesOnlyOnce() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try ContractFixtures.songDetail()
            let callCount = LockedCounter()
            MockURLProtocol.requestHandler = { _ in
                callCount.increment()
                return (Self.response(status: 200), fixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(songId: songId, rootViewModel: rootViewModel)

            await detailViewModel.loadIfNeeded()
            await detailViewModel.loadIfNeeded()

            // #180: one `load()` now fires THREE concurrent requests
            // (song_detail + song_links + related_songs) — the "only once"
            // guarantee this test protects is that the SECOND
            // `loadIfNeeded()` call is a total no-op (still 3, not 6),
            // not that there's only one request per load.
            #expect(callCount.current == 3)
        }
    }

    @Test("SongDetailViewModel.load() maps a 404-shaped server error to user-facing copy")
    func songDetailViewModelMapsServerError() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 500), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(songId: songId, rootViewModel: rootViewModel)

            await detailViewModel.load()

            guard case .error(let message) = await detailViewModel.loadState else {
                Issue.record("Expected .error, got \(await detailViewModel.loadState)")
                return
            }
            #expect(!message.isEmpty)
        }
    }
}

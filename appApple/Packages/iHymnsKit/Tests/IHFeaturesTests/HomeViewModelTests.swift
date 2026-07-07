// HomeViewModelTests.swift
// IHFeaturesTests
//
// ELI5: Proves the Home screen's two data sources actually work end-to-end
// against real fixture bytes / a real `SongDetailViewModel` load — today's
// Song of the Day (#183) and the "Continue Reading"/"Recently Viewed"
// state that `SongDetailViewModel`'s success hook (`AppRootViewModel.
// recordRecentlyViewed(_:)`) feeds — with no real network ever touched.
//
// DETAILED: Wraps every test in `MockTransportLock.shared.withLock` for the
// same cross-suite-exclusivity reason `CatalogueAndSongDetailLoadingTests`
// does (see `MockTransportLock`'s own header) — this suite is another
// consumer of the same shared `MockURLProtocol.requestHandler`.
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

@Suite("HomeViewModel + recently-viewed wiring (#183)", .serialized)
struct HomeViewModelTests {

    private static func response(status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: URL(string: "https://dev.ihymns.app/api")!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    /// A test-only in-memory `TokenStoring` — mirrors
    /// `CatalogueAndSongDetailLoadingTests`' fake so this suite never
    /// touches the real Keychain either.
    private actor InMemoryTokenStore: TokenStoring {
        private var token: String?
        func save(_ token: String) async throws { self.token = token }
        func load() async throws -> String? { token }
        func delete() async throws { token = nil }
    }

    /// Builds a real `AppRootViewModel` wired to a `MockURLProtocol`-backed
    /// `APIClient`, with its `recentlyViewedStore`/`recentSearchesStore`
    /// pointed at throwaway `UserDefaults` suites so this suite never
    /// pollutes (or is polluted by) the real app's persisted state or
    /// another test's.
    @MainActor
    private func makeViewModel() throws -> AppRootViewModel {
        let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
        let sessionController = SessionController(tokenStore: InMemoryTokenStore(), apiClient: apiClient)
        let suite = "ihtest.home.\(UUID().uuidString)"
        // swiftlint:disable:next force_unwrapping
        let defaults = UserDefaults(suiteName: suite)!
        return AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient),
            recentSearchesStore: RecentSearchesStore(defaults: defaults, key: "recents"),
            recentlyViewedStore: RecentlyViewedStore(defaults: defaults, key: "recentlyViewed")
        )
    }

    @Test("HomeViewModel.load() decodes the real song_of_the_day fixture into .loaded")
    func loadSucceeds() async throws {
        try await MockTransportLock.shared.withLock {
            let fixture = try ContractFixtures.songOfTheDay()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let homeViewModel = await HomeViewModel(rootViewModel: rootViewModel)
            await homeViewModel.load()

            guard case .loaded(let sotd) = await homeViewModel.songOfTheDayState else {
                Issue.record("Expected .loaded, got \(await homeViewModel.songOfTheDayState)")
                return
            }
            #expect(sotd.song?.songId.rawValue == "GASD-0019")
        }
    }

    @Test("HomeViewModel.loadIfNeeded() only fetches once — a second call is a no-op while already loaded")
    func loadIfNeededFetchesOnlyOnce() async throws {
        try await MockTransportLock.shared.withLock {
            let fixture = try ContractFixtures.songOfTheDay()
            let callCount = LockedCounter()
            MockURLProtocol.requestHandler = { _ in
                callCount.increment()
                return (Self.response(status: 200), fixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let homeViewModel = await HomeViewModel(rootViewModel: rootViewModel)
            await homeViewModel.loadIfNeeded()
            await homeViewModel.loadIfNeeded()

            #expect(callCount.current == 1)
        }
    }

    @Test("HomeViewModel.load() maps a 503 to the temporarily-unavailable copy")
    func loadMapsMaintenanceError() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 503), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let homeViewModel = await HomeViewModel(rootViewModel: rootViewModel)
            await homeViewModel.load()

            guard case .error(let message) = await homeViewModel.songOfTheDayState else {
                Issue.record("Expected .error, got \(await homeViewModel.songOfTheDayState)")
                return
            }
            #expect(message.contains("temporarily unavailable"))
        }
    }

    @Test("AppRootViewModel.songOfTheDay() reaches the real APIClient pass-through and decodes successfully")
    func songOfTheDayPassThroughSucceeds() async throws {
        try await MockTransportLock.shared.withLock {
            let fixture = try ContractFixtures.songOfTheDay()
            let callCount = LockedCounter()
            MockURLProtocol.requestHandler = { _ in
                callCount.increment()
                return (Self.response(status: 200), fixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            // `AppRootViewModel.songOfTheDay()` resolves `hemisphere`/
            // `country` from `LocaleRegionSignals` internally (unit-tested
            // in isolation by `IHAPITests/LocaleRegionSignalsTests.swift`
            // and `Endpoint.songOfTheDay`'s own request-building tests) —
            // this test proves the WIRING all the way through: the
            // pass-through actually reaches `APIClient.songOfTheDay(
            // hemisphere:country:)` and the real fixture decodes.
            let rootViewModel = try await makeViewModel()
            let sotd = try await rootViewModel.songOfTheDay()

            #expect(callCount.current == 1)
            #expect(sotd.song?.songId.rawValue == "GASD-0019")
        }
    }

    @Test("SongDetailViewModel's successful load records the song into AppRootViewModel.recentlyViewedSongs (#183)")
    func songDetailLoadRecordsRecentlyViewed() async throws {
        try await MockTransportLock.shared.withLock {
            let detailFixture = try ContractFixtures.songDetail()
            let linksFixture = try ContractFixtures.songLinks()
            let relatedFixture = try ContractFixtures.relatedSongs()
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
            #expect(await rootViewModel.recentlyViewedSongs.isEmpty)

            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(songId: songId, rootViewModel: rootViewModel)
            await detailViewModel.load()

            let recentlyViewed = await rootViewModel.recentlyViewedSongs
            #expect(recentlyViewed.count == 1)
            #expect(recentlyViewed.first?.songId.rawValue == "MP-0031")
            #expect(recentlyViewed.first?.title == "Amazing grace")

            // HomeViewModel's `resumeSong` reads straight through to this
            // same array — proves the two view models actually share state
            // via `AppRootViewModel`, not two independent copies.
            let homeViewModel = await HomeViewModel(rootViewModel: rootViewModel)
            #expect(await homeViewModel.resumeSong?.songId.rawValue == "MP-0031")
            #expect(await homeViewModel.recentlyViewedShelfSongs.isEmpty)
        }
    }

    @Test("A song that fails to load is never recorded into recentlyViewedSongs")
    func failedSongDetailLoadDoesNotRecordRecentlyViewed() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 500), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(songId: songId, rootViewModel: rootViewModel)
            await detailViewModel.load()

            #expect(await rootViewModel.recentlyViewedSongs.isEmpty)
        }
    }
}

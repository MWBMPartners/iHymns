// AppRootViewModelSystemIndexTests.swift
// IHFeaturesTests
//
// ELI5: Proves that once the catalogue has loaded, `AppRootViewModel`
// actually remembers those songs in the OFFLINE cache and tells the
// Spotlight indexer about them too — the #1415 hook that makes "search this
// app's songs from Siri/Spotlight, or open one from a Shortcut with no
// network" actually work, rather than silently doing nothing.
//
// DETAILED: #1415 (App Intents / Siri / Shortcuts / Spotlight,
// `.claude/apple-native-strategy.md` §2.3#4). `syncSystemIndexes(_:)` is a
// fire-and-forget `Task` inside `loadCatalogue()` — this suite POLLS for that
// Task to finish (`waitUntil`) rather than calling `syncSystemIndexes` a
// second time itself: a second explicit call made the spy count the corpus
// TWICE whenever the internal Task also completed in time (a real
// double-index flake). Injects a SPY `SpotlightIndexing` (never the
// real `LiveSpotlightIndex`) so this suite never touches the developer
// machine's actual Spotlight index. Wraps every test in
// `MockTransportLock.shared.withLock` for the same cross-suite-exclusivity
// reason `CatalogueAndSongDetailLoadingTests` does (see `MockTransportLock`'s
// own header) — this suite is another consumer of the same shared
// `MockURLProtocol.requestHandler`.
import Foundation
import Testing
import IHAPITestSupport
import IHAppSupport
import IHTestFixtures
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

@Suite("AppRootViewModel system-index sync (#1415)", .serialized)
struct AppRootViewModelSystemIndexTests {

    private static func response(status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: URL(string: "https://dev.ihymns.app/api")!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    /// Polls `predicate` until it holds or the timeout elapses — used to
    /// await `loadCatalogue()`'s fire-and-forget `syncSystemIndexes` Task
    /// deterministically (there is no structured handle to join).
    private static func waitUntil(timeout: Duration = .seconds(3), _ predicate: @escaping () async -> Bool) async {
        let deadline = ContinuousClock().now.advanced(by: timeout)
        while await !predicate(), ContinuousClock().now < deadline {
            try? await Task.sleep(for: .milliseconds(10))
        }
    }

    private actor InMemoryTokenStore: TokenStoring {
        private var token: String?
        func save(_ token: String) async throws { self.token = token }
        func load() async throws -> String? { token }
        func delete() async throws { token = nil }
    }

    /// A spy `SpotlightIndexing` — records what it was asked to index/delete
    /// rather than touching the real, developer-machine Spotlight index.
    private actor SpySpotlightIndex: SpotlightIndexing {
        private(set) var deletedDomains: [String] = []
        private(set) var indexedEntries: [SpotlightIndexEntry] = []

        func indexEntries(_ entries: [SpotlightIndexEntry], inDomain domain: String) async throws {
            indexedEntries.append(contentsOf: entries)
        }

        func deleteDomain(_ domain: String) async throws {
            deletedDomains.append(domain)
        }
    }

    @MainActor
    private func makeViewModel(spy: SpySpotlightIndex) throws -> AppRootViewModel {
        let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
        let sessionController = SessionController(tokenStore: InMemoryTokenStore(), apiClient: apiClient)
        return AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient),
            // A fresh, unique UserDefaults suite per view model so the
            // indexer's persisted corpus fingerprint can NEVER leak across
            // test runs — otherwise the first run indexes and a second run
            // (same corpus) sees the stored fingerprint and skips, so the spy
            // receives nothing (a non-idempotent-test flake, #1415).
            spotlightIndexer: SpotlightIndexer(
                index: spy,
                defaults: UserDefaults(suiteName: "test.spotlight.\(UUID().uuidString)") ?? .standard
            )
        )
    }

    @Test("A successful catalogue load fills the offline cache AND hands the Spotlight indexer entries")
    func successfulLoadSyncsBothIndexes() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try ContractFixtures.songsIndex()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let spy = SpySpotlightIndex()
            let viewModel = try await makeViewModel(spy: spy)
            await viewModel.loadCatalogue()

            guard case .loaded(let songs) = await viewModel.catalogueLoadState else {
                Issue.record("Expected .loaded")
                return
            }
            #expect(!songs.isEmpty)

            // Poll `loadCatalogue()`'s OWN fire-and-forget sync to completion
            // (upsert, then index) — NOT a second explicit `syncSystemIndexes`
            // call, which would make the spy count the corpus twice.
            await Self.waitUntil { await spy.indexedEntries.count == songs.count }

            let cached = try await viewModel.cachedSongSummaries()
            #expect(cached.count == songs.count)
            #expect(await spy.indexedEntries.count == songs.count)
            #expect(await spy.deletedDomains.count == 1)
        }
    }

    @Test("A failed catalogue load indexes nothing")
    func failedLoadIndexesNothing() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 503), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            let spy = SpySpotlightIndex()
            let viewModel = try await makeViewModel(spy: spy)
            await viewModel.loadCatalogue()

            guard case .error = await viewModel.catalogueLoadState else {
                Issue.record("Expected .error")
                return
            }

            let cached = try await viewModel.cachedSongSummaries()
            #expect(cached.isEmpty)
            let indexedCount = await spy.indexedEntries.count
            #expect(indexedCount == 0)
            let deletedCount = await spy.deletedDomains.count
            #expect(deletedCount == 0)
        }
    }
}

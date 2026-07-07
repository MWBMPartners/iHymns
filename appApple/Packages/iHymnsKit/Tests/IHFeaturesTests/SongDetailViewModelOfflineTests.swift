// SongDetailViewModelOfflineTests.swift
// IHFeaturesTests
//
// ELI5: Proves the #187 offline half of `SongDetailViewModel` actually
// works end-to-end through the real `AppRootViewModel` (not just
// `OfflineStore`'s own lower-level tests): saving/removing a song for
// offline reading, and — the important one — that a song someone already
// saved is still fully readable (with an honest "showing saved copy"
// signal) even when the network fetch itself fails offline. No real network
// or Keychain is ever touched.
//
// DETAILED: Mirrors `CatalogueAndSongDetailLoadingTests.swift`'s exact
// harness shape (`makeViewModel()`, `MockTransportLock.shared.withLock`,
// `MockURLProtocol.requestHandler`) — this is the SAME "real production
// types, faked transport only" posture every other IHFeatures integration
// test in this package uses. `makeViewModel(retryBaseDelaySeconds:)` passes
// a near-zero retry delay (unlike that file's helper, which uses the real
// default) specifically because `.offline` IS a retryable `APIError`
// (`APIClient.isRetryable(_:)`) — without shrinking it, every offline test
// below would burn several real seconds on `APIClient`'s exponential
// -backoff retry loop for no reason (`APIClient.swift`'s own doc comment on
// `retryBaseDelaySeconds`: "tests override this to keep retry tests fast").
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

@Suite("SongDetailViewModel offline saving + fallback (#187)", .serialized)
struct SongDetailViewModelOfflineTests {

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

    /// Builds a real `AppRootViewModel` wired to a `MockURLProtocol`-backed
    /// `APIClient` — same shape as `CatalogueAndSongDetailLoadingTests
    /// .makeViewModel()`, but with a near-zero retry delay (see this file's
    /// header).
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

    @Test("toggleOfflineSave() saves the loaded song, then removes it on a second tap")
    func toggleOfflineSaveSavesThenRemoves() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try ContractFixtures.songDetail()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(songId: songId, rootViewModel: rootViewModel)

            await detailViewModel.load()
            #expect(await detailViewModel.isSavedOffline == false)

            await detailViewModel.toggleOfflineSave()
            #expect(await detailViewModel.isSavedOffline == true)
            #expect(try await rootViewModel.offlineStore.isSongSaved(songId) == true)

            await detailViewModel.toggleOfflineSave()
            #expect(await detailViewModel.isSavedOffline == false)
            #expect(try await rootViewModel.offlineStore.isSongSaved(songId) == false)
        }
    }

    @Test("toggleOfflineSave() before the song has loaded is a harmless no-op")
    func toggleOfflineSaveBeforeLoadedIsNoOp() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(songId: songId, rootViewModel: rootViewModel)

            // Deliberately never calls `load()` first — `loadState` stays
            // `.idle`, so there's no `SongDetail` to save WITH yet.
            await detailViewModel.toggleOfflineSave()

            #expect(await detailViewModel.isSavedOffline == false)
            #expect(try await rootViewModel.offlineStore.savedSongCount() == 0)
        }
    }

    @Test("load() falls back to a saved offline copy when the network is offline, and flags it as such")
    func loadFallsBackToSavedCopyWhenOffline() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixtureData = try ContractFixtures.songDetail()
            let detail = try APIClient.decodeSongDetail(from: fixtureData)

            let rootViewModel = try await makeViewModel()
            // Pre-seed the offline cache directly — this device already
            // saved this exact song on a previous, connected visit.
            try await rootViewModel.offlineStore.saveSongForOffline(detail)

            // No handler at all: `MockURLProtocol.startLoading()` fails
            // every request with `URLError(.unknown)`, which `APIClient
            // .performOnce` maps to `.offline` for every one of `load()`'s
            // three concurrent requests.
            MockURLProtocol.requestHandler = nil

            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(songId: songId, rootViewModel: rootViewModel)
            await detailViewModel.load()

            guard case .loaded(let loaded) = await detailViewModel.loadState else {
                Issue.record("Expected .loaded (from the offline cache), got \(await detailViewModel.loadState)")
                return
            }
            #expect(loaded.title == detail.title)
            #expect(loaded.components.count == detail.components.count)
            #expect(await detailViewModel.isServingCachedCopy == true)
            // The pre-seeded copy is also already "saved" by definition.
            #expect(await detailViewModel.isSavedOffline == true)
        }
    }

    @Test("load() surfaces the ordinary offline error when there's no saved copy to fall back to")
    func loadSurfacesErrorWithNoSavedCopy() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            // Nothing pre-seeded in the offline cache for this song, and
            // every request fails (nil handler → `.offline`, see the
            // previous test's comment).
            MockURLProtocol.requestHandler = nil

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(songId: songId, rootViewModel: rootViewModel)
            await detailViewModel.load()

            guard case .error(let message) = await detailViewModel.loadState else {
                Issue.record("Expected .error, got \(await detailViewModel.loadState)")
                return
            }
            #expect(message.contains("offline"))
            #expect(await detailViewModel.isServingCachedCopy == false)
        }
    }

    @Test("load() never claims to be serving a cached copy on an ordinary successful fetch")
    func loadFreshSuccessIsNotFlaggedAsCached() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let fixture = try ContractFixtures.songDetail()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), fixture) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detailViewModel = await SongDetailViewModel(songId: songId, rootViewModel: rootViewModel)
            await detailViewModel.load()

            guard case .loaded = await detailViewModel.loadState else {
                Issue.record("Expected .loaded, got \(await detailViewModel.loadState)")
                return
            }
            #expect(await detailViewModel.isServingCachedCopy == false)
        }
    }
}

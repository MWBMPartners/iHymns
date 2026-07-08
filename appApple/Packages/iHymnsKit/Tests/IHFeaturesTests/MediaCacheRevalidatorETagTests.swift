// MediaCacheRevalidatorETagTests.swift
// IHFeaturesTests
//
// ELI5: Proves the #1460 conditional-GET upgrade actually gets wired up
// correctly end-to-end through a real `SongDetailViewModel.load()` — a
// server `304` leaves the cached file alone (just notes it was checked), a
// server `200` replaces the file AND remembers its new ETag for next time,
// an asset with no stored ETag yet never even calls the conditional
// fetcher (the sizeBytes heuristic owns that asset instead), and a
// recently-validated ETag'd asset skips the network entirely (the SAME TTL
// gate the sizeBytes path already had).
//
// DETAILED: Split out of `MediaCacheRevalidatorTests.swift` (rather than
// grown inside it) purely to stay under the repo's SwiftLint
// `type_body_length` ceiling, the SAME reasoning
// `SongComparisonEngineWordDiffTests.swift`'s own header documents for its
// split — mirrors that file's harness shape (`makeViewModel()`,
// `MockTransportLock.shared.withLock`, a mutated `song_detail` fixture) one
// for one, since both suites exercise the SAME `SongDetailViewModel.load()`
// entry point into `MediaCacheRevalidator`.
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

@Suite("MediaCacheRevalidator ETag path (#1460)", .serialized)
struct MediaCacheRevalidatorETagTests {

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
            "MediaCacheRevalidatorETagTests-\(UUID().uuidString)", isDirectory: true
        )
    }

    /// Mirrors `MediaCacheRevalidatorTests.fixture(serverSizeBytes:)` — the
    /// ETag path never actually READS `sizeBytes` off this fixture (it's
    /// the sizeBytes-heuristic path's own signal), so it's fixed at 100
    /// throughout this suite; only the fixture's mere PRESENCE (asset #42
    /// still listed) matters here.
    private static func fixture() throws -> Data {
        let raw = try ContractFixtures.songDetail()
        guard var json = try JSONSerialization.jsonObject(with: raw) as? [String: Any],
              var song = json["song"] as? [String: Any] else {
            throw URLError(.cannotParseResponse)
        }
        song["media"] = [[
            "id": 42, "kind": "audio", "fileName": "amazing-grace.mp3", "mimeType": "audio/mpeg",
            "sizeBytes": 100, "annotation": "", "sortOrder": 0, "streamUrl": "/song-media/42"
        ]]
        json["song"] = song
        return try JSONSerialization.data(withJSONObject: json)
    }

    private static let makeAssetStub = SongMediaAsset(
        id: 42, kind: SongMediaAsset.Kind.audio, fileName: "amazing-grace.mp3",
        mimeType: "audio/mpeg", sizeBytes: 100, annotation: "", sortOrder: 0, streamUrl: "/song-media/42"
    )

    /// Seeds asset #42 as cached WITH `etag`, then backdates `lastValidatedAt`
    /// well past the default 24h TTL — every 304/200 test starts here.
    @MainActor
    private func seedStaleCachedAssetWithEtag(rootViewModel: AppRootViewModel, songId: SongID, etag: String) async throws {
        try await rootViewModel.offlineStore.cacheMedia(
            songId: songId, asset: Self.makeAssetStub, data: Data(repeating: 0, count: 100), etag: etag
        )
        let wellPastTTL = Date().addingTimeInterval(-(MediaCacheRevalidationPolicy.defaultStaleAfter + 3_600))
        try await rootViewModel.offlineStore.markCachedMediaValidated(songId: songId, mediaAssetId: 42, at: wellPastTTL)
    }

    @Test("a real 304 leaves the cached file untouched but bumps lastValidatedAt — never touches mediaFetcher")
    func notModifiedLeavesFileAloneButRevalidates() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), try Self.fixture()) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            try await seedStaleCachedAssetWithEtag(rootViewModel: rootViewModel, songId: songId, etag: "\"abc123\"")
            let originalURL = await rootViewModel.offlineStore.cachedMediaURL(songId: songId, mediaAssetId: 42)

            let plainFetcherCallCount = LockedCounter()
            let seenIfNoneMatch = SendableBox<String?>(nil)
            let detailViewModel = await SongDetailViewModel(
                songId: songId,
                rootViewModel: rootViewModel,
                mediaFetcher: { _ in plainFetcherCallCount.increment(); return Data() },
                mediaConditionalFetcher: { _, ifNoneMatch in
                    seenIfNoneMatch.value = ifNoneMatch
                    return .notModified
                }
            )
            await detailViewModel.load()

            #expect(plainFetcherCallCount.current == 0)
            #expect(seenIfNoneMatch.value == "\"abc123\"")
            let afterURL = await rootViewModel.offlineStore.cachedMediaURL(songId: songId, mediaAssetId: 42)
            #expect(afterURL == originalURL)
            let cached = try await rootViewModel.offlineStore.cachedMedia(forSong: songId)
            #expect(cached.first?.lastValidatedAt.timeIntervalSinceNow ?? -999 > -5)
            #expect(cached.first?.etag == "\"abc123\"") // unchanged — a 304 never rewrites the stored etag
        }
    }

    @Test("a real 200 replaces the cached file and stores the response's NEW etag/lastModified")
    func replacedReplacesFileAndCapturesNewEtag() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), try Self.fixture()) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            try await seedStaleCachedAssetWithEtag(rootViewModel: rootViewModel, songId: songId, etag: "\"old-etag\"")

            let freshBytes = Data(repeating: 7, count: 250)
            let detailViewModel = await SongDetailViewModel(
                songId: songId,
                rootViewModel: rootViewModel,
                mediaConditionalFetcher: { _, _ in
                    .replaced(data: freshBytes, etag: "\"new-etag\"", lastModified: "Wed, 08 Jul 2026 12:00:00 GMT")
                }
            )
            await detailViewModel.load()

            let cachedURL = try #require(await rootViewModel.offlineStore.cachedMediaURL(songId: songId, mediaAssetId: 42))
            #expect(try Data(contentsOf: cachedURL) == freshBytes)
            let cached = try await rootViewModel.offlineStore.cachedMedia(forSong: songId)
            #expect(cached.first?.etag == "\"new-etag\"")
            #expect(cached.first?.lastModified == "Wed, 08 Jul 2026 12:00:00 GMT")
        }
    }

    @Test("an asset with NO stored etag never calls the conditional fetcher — the sizeBytes heuristic owns it instead")
    func noStoredEtagNeverCallsConditionalFetcher() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), try Self.fixture()) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            // Seeded WITHOUT an etag — the pre-#1460 shape.
            try await rootViewModel.offlineStore.cacheMedia(songId: songId, asset: Self.makeAssetStub, data: Data(repeating: 0, count: 100))
            let wellPastTTL = Date().addingTimeInterval(-(MediaCacheRevalidationPolicy.defaultStaleAfter + 3_600))
            try await rootViewModel.offlineStore.markCachedMediaValidated(songId: songId, mediaAssetId: 42, at: wellPastTTL)

            let conditionalFetcherCallCount = LockedCounter()
            let detailViewModel = await SongDetailViewModel(
                songId: songId,
                rootViewModel: rootViewModel,
                mediaFetcher: { _ in Data(repeating: 9, count: 100) }, // sizeBytes still matches -> .stillFresh
                mediaConditionalFetcher: { _, _ in
                    conditionalFetcherCallCount.increment()
                    return .notModified
                }
            )
            await detailViewModel.load()

            #expect(conditionalFetcherCallCount.current == 0)
        }
    }

    @Test("an etag'd asset still within the TTL window skips the conditional fetch entirely")
    func etagAssetWithinTTLSkipsConditionalFetch() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), try Self.fixture()) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            // Cached WITH an etag but NOT backdated — still fresh per the TTL.
            try await rootViewModel.offlineStore.cacheMedia(
                songId: songId, asset: Self.makeAssetStub, data: Data(repeating: 0, count: 100), etag: "\"abc123\""
            )

            let conditionalFetcherCallCount = LockedCounter()
            let detailViewModel = await SongDetailViewModel(
                songId: songId,
                rootViewModel: rootViewModel,
                mediaConditionalFetcher: { _, _ in
                    conditionalFetcherCallCount.increment()
                    return .notModified
                }
            )
            await detailViewModel.load()

            #expect(conditionalFetcherCallCount.current == 0)
        }
    }

    @Test("a conditional-fetch failure leaves the existing cached file completely untouched")
    func conditionalFetchFailureLeavesFileInPlace() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), try Self.fixture()) }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-0031"))
            try await seedStaleCachedAssetWithEtag(rootViewModel: rootViewModel, songId: songId, etag: "\"abc123\"")
            let originalURL = try #require(await rootViewModel.offlineStore.cachedMediaURL(songId: songId, mediaAssetId: 42))
            let originalBytes = try Data(contentsOf: originalURL)

            let detailViewModel = await SongDetailViewModel(
                songId: songId,
                rootViewModel: rootViewModel,
                mediaConditionalFetcher: { _, _ in throw URLError(.notConnectedToInternet) }
            )
            await detailViewModel.load()

            let afterURL = try #require(await rootViewModel.offlineStore.cachedMediaURL(songId: songId, mediaAssetId: 42))
            #expect(afterURL == originalURL)
            #expect(try Data(contentsOf: afterURL) == originalBytes)
        }
    }
}

/// A trivially-synchronized single-value box — mirrors `LockedCounter`'s
/// own "manual synchronization, not a captured `var`" reasoning
/// (`MockURLProtocol.swift`'s doc comment) for a `@Sendable` closure that
/// needs to hand a value BACK out, not just count calls.
private final class SendableBox<Value>: @unchecked Sendable {
    private let lock = NSLock()
    private var storedValue: Value

    init(_ initial: Value) { storedValue = initial }

    var value: Value {
        get { lock.lock(); defer { lock.unlock() }; return storedValue }
        set { lock.lock(); defer { lock.unlock() }; storedValue = newValue }
    }
}

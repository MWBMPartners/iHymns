// SongbookBulkSaveViewModelTests.swift
// IHFeaturesTests
//
// ELI5: Proves that "Save All for Offline" actually saves every song in a
// songbook (through the same real save path a single song's "Save for
// Offline" button uses), keeps an honest running count as it goes, keeps
// going even when ONE song's fetch fails (collecting that failure instead
// of giving up on the rest), can be cancelled partway through, and only
// downloads media when the caller opts in. Covers #1439.
//
// DETAILED: Mirrors `SongDetailViewModelOfflineTests.swift`'s exact harness
// shape (`makeViewModel()`, `MockTransportLock.shared.withLock`,
// `MockURLProtocol.requestHandler`) — the same "real production types,
// faked transport only" posture every IHFeatures integration test in this
// package uses. Unlike that file's tests (which only ever fetch ONE fixed
// `songId`), these tests request several DISTINCT songs in one run, so the
// request handler below inspects each request's own `?id=` query item and
// stamps the returned fixture's `song.id` to match — otherwise every
// request would decode back to the SAME song regardless of which
// `SongSummary` the loop was actually attempting, and "3 distinct songs
// got saved" wouldn't be provable.
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

@Suite("SongbookBulkSaveViewModel (#1439)", .serialized)
struct SongbookBulkSaveViewModelTests {

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

    private static func scratchDirectory() -> URL {
        FileManager.default.temporaryDirectory.appendingPathComponent(
            "SongbookBulkSaveViewModelTests-\(UUID().uuidString)", isDirectory: true
        )
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

    private static func makeSong(_ rawId: String, title: String) -> SongSummary {
        // swiftlint:disable:next force_unwrapping
        SongSummary(songId: SongID(rawValue: rawId)!, title: title, songbookAbbreviation: "MP", number: nil)
    }

    /// Extracts the `?id=` query item a `song_detail` request carries —
    /// see this file's header for why the handler needs it.
    private static func requestedSongId(_ request: URLRequest) -> String? {
        guard let url = request.url,
              let components = URLComponents(url: url, resolvingAgainstBaseURL: false) else { return nil }
        return components.queryItems?.first { $0.name == "id" }?.value
    }

    /// Stamps the shared `song_detail` fixture's `song.id` (and, when
    /// `includesMedia` is set, its `media` array) to match `rawId`, so a
    /// per-song request decodes back into a `SongDetail` for THAT song.
    ///
    /// DETAILED: Deliberately plain `guard`/`throw` here, NOT `#require` —
    /// this helper (and `requestedSongId(_:)` above) is called from INSIDE
    /// `MockURLProtocol.requestHandler`, which `URLProtocol`'s own loading
    /// machinery invokes off this test's own `Task` — Swift Testing's
    /// `#require`/`Issue.record` rely on a task-local `Test.current` that
    /// isn't guaranteed to still be bound on that call stack, so a plain
    /// thrown error (surfaced as an ordinary request failure, exactly like
    /// a real malformed-fixture bug would be) is the safe choice here.
    private static func fixture(forSongId rawId: String, includesMedia: Bool = false) throws -> Data {
        let raw = try ContractFixtures.songDetail()
        guard var json = try JSONSerialization.jsonObject(with: raw) as? [String: Any],
              var song = json["song"] as? [String: Any] else {
            throw URLError(.cannotParseResponse)
        }
        song["id"] = rawId
        if includesMedia {
            song["media"] = [[
                "id": 1,
                "kind": "audio",
                "fileName": "\(rawId).mp3",
                "mimeType": "audio/mpeg",
                "sizeBytes": 10,
                "annotation": "",
                "sortOrder": 0,
                "streamUrl": "/song-media/1"
            ]]
        } else {
            song["media"] = []
        }
        json["song"] = song
        return try JSONSerialization.data(withJSONObject: json)
    }

    @Test("start() saves every song, reaches .finished, and tallies an honest completed/total count")
    func savesEverySongAndFinishes() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let songs = [
                Self.makeSong("MP-1", title: "Song One"),
                Self.makeSong("MP-2", title: "Song Two"),
                Self.makeSong("MP-3", title: "Song Three")
            ]
            MockURLProtocol.requestHandler = { request in
                let id = Self.requestedSongId(request) ?? ""
                return (Self.response(status: 200), try Self.fixture(forSongId: id))
            }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let viewModel = await SongbookBulkSaveViewModel(rootViewModel: rootViewModel)

            await viewModel.start(songs: songs, includeMedia: false)
            // `start()` kicks off a detached run loop `Task` — poll until it
            // settles rather than assuming a fixed number of run-loop hops,
            // mirroring every other async-view-model test in this package's
            // "await the observable state, don't sleep" posture.
            try await Self.waitUntilSettled(viewModel)

            #expect(await viewModel.phase == .finished)
            #expect(await viewModel.completedCount == 3)
            #expect(await viewModel.totalCount == 3)
            #expect(await viewModel.failures.isEmpty)
            #expect(try await rootViewModel.offlineStore.savedSongCount() == 3)
            for song in songs {
                #expect(try await rootViewModel.offlineStore.isSongSaved(song.songId) == true)
            }
        }
    }

    @Test("one song failing is collected as a failure, without aborting the rest")
    func oneFailureDoesNotAbortTheRest() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let songs = [
                Self.makeSong("MP-1", title: "Song One"),
                Self.makeSong("MP-999", title: "Broken Song"),
                Self.makeSong("MP-3", title: "Song Three")
            ]
            MockURLProtocol.requestHandler = { request in
                let id = Self.requestedSongId(request) ?? ""
                if id == "MP-999" {
                    return (Self.response(status: 500), Data())
                }
                return (Self.response(status: 200), try Self.fixture(forSongId: id))
            }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let viewModel = await SongbookBulkSaveViewModel(rootViewModel: rootViewModel)

            await viewModel.start(songs: songs, includeMedia: false)
            try await Self.waitUntilSettled(viewModel)

            #expect(await viewModel.phase == .finished)
            #expect(await viewModel.completedCount == 3)
            #expect(await viewModel.failures.count == 1)
            #expect(await viewModel.failures.first?.title == "Broken Song")
            #expect(try await rootViewModel.offlineStore.savedSongCount() == 2)
        }
    }

    @Test("includeMedia: false never downloads media, even for a song that has an attached asset")
    func includeMediaFalseSkipsMedia() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let songs = [Self.makeSong("MP-1", title: "Song One")]
            MockURLProtocol.requestHandler = { request in
                let id = Self.requestedSongId(request) ?? ""
                return (Self.response(status: 200), try Self.fixture(forSongId: id, includesMedia: true))
            }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let viewModel = await SongbookBulkSaveViewModel(rootViewModel: rootViewModel, mediaFetcher: { _ in Data([1, 2, 3]) })

            await viewModel.start(songs: songs, includeMedia: false)
            try await Self.waitUntilSettled(viewModel)

            #expect(await viewModel.phase == .finished)
            #expect(try await rootViewModel.offlineStore.cachedMedia(forSong: songs[0].songId).isEmpty)
        }
    }

    @Test("includeMedia: true downloads and caches each song's attached media via the injected fetcher")
    func includeMediaTrueCachesMedia() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let songs = [Self.makeSong("MP-1", title: "Song One")]
            MockURLProtocol.requestHandler = { request in
                let id = Self.requestedSongId(request) ?? ""
                return (Self.response(status: 200), try Self.fixture(forSongId: id, includesMedia: true))
            }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let mediaBytes = Data([0xFF, 0xFB, 0x90])
            let viewModel = await SongbookBulkSaveViewModel(rootViewModel: rootViewModel, mediaFetcher: { _ in mediaBytes })

            await viewModel.start(songs: songs, includeMedia: true)
            try await Self.waitUntilSettled(viewModel)

            #expect(await viewModel.phase == .finished)
            let cached = try await rootViewModel.offlineStore.cachedMedia(forSong: songs[0].songId)
            #expect(cached.count == 1)
            #expect(cached.first?.sizeBytes == mediaBytes.count)
        }
    }

    @Test("cancel() stops the run before every song is attempted")
    func cancelStopsEarly() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let songs = (1...20).map { Self.makeSong("MP-\($0)", title: "Song \($0)") }
            // A small artificial per-request delay (a real `Thread.sleep`,
            // since `MockURLProtocol.requestHandler` is a plain synchronous
            // closure) — without it, 20 near-instant in-memory mock
            // responses could all complete before this test ever gets a
            // chance to call `cancel()`, making "stops mid-run" untestable.
            MockURLProtocol.requestHandler = { request in
                Thread.sleep(forTimeInterval: 0.02)
                let id = Self.requestedSongId(request) ?? ""
                return (Self.response(status: 200), try Self.fixture(forSongId: id))
            }
            defer { MockURLProtocol.requestHandler = nil }

            let rootViewModel = try await makeViewModel()
            let viewModel = await SongbookBulkSaveViewModel(rootViewModel: rootViewModel)

            await viewModel.start(songs: songs, includeMedia: false)
            // Let the run loop get partway through, then cancel it —
            // proves cancellation takes effect DURING the run, not only
            // when called before any work has started.
            try await Task.sleep(nanoseconds: 30_000_000)
            await viewModel.cancel()
            try await Self.waitUntilSettled(viewModel)

            #expect(await viewModel.phase == .cancelled)
            #expect(await viewModel.completedCount < songs.count)
        }
    }

    @Test("start() with an empty songbook finishes immediately with a zero total")
    func emptySongbookFinishesImmediately() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let rootViewModel = try await makeViewModel()
            let viewModel = await SongbookBulkSaveViewModel(rootViewModel: rootViewModel)

            await viewModel.start(songs: [], includeMedia: false)

            #expect(await viewModel.phase == .finished)
            #expect(await viewModel.totalCount == 0)
            #expect(await viewModel.fractionCompleted == 0)
        }
    }

    /// Polls `viewModel.phase` until it leaves `.running` — the bulk-save
    /// run loop is a detached `Task` started by `start()`, so a test must
    /// wait for it rather than assuming synchronous completion (mirroring
    /// the pattern `AppRootViewModelFavoritesTests`'s pending-op-replay
    /// tests already use for a background sync `Task`).
    @MainActor
    private static func waitUntilSettled(_ viewModel: SongbookBulkSaveViewModel, timeout: TimeInterval = 5) async throws {
        let deadline = Date().addingTimeInterval(timeout)
        while viewModel.phase == .running, Date() < deadline {
            try await Task.sleep(nanoseconds: 5_000_000)
        }
    }
}

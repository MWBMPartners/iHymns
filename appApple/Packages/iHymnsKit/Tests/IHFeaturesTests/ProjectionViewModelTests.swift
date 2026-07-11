// ProjectionViewModelTests.swift
// IHFeaturesTests
//
// ELI5: Drives a real `ProjectionViewModel` with a FAKE `fetchSongDetail`
// closure (never a real network call) and checks it behaves exactly like
// the spec's driver-agnostic contract promises: publishes only on a real
// change, never touches state on a gated/offline/superseded fetch, caches
// via `prepare`, and keeps a frozen screen's PICTURE pinned while the
// canonical position underneath keeps moving.
//
// DETAILED: #1504 (`.claude/apple-phase2-pr5-spec.md` §7.2). `@MainActor`
// suite (mirrors `AppRootViewModelTests`' identical posture — the view
// model under test is itself `@MainActor`). No `MockURLProtocol`/
// `MockTransportLock` involvement: every fixture here is INJECTED directly
// as the `fetchSongDetail` closure, so there is no shared static mutable
// transport state to serialize against. `ProjectionStateCollector`/
// `waitForState`/`expectNothingPublished`/`makeCollector`/`GatedFetcher`
// live in the sibling `ProjectionViewModelTestSupport.swift` — see that
// file's header for why a naive `for await`-raced-against-`Task.sleep`
// helper (mirroring `LANRemoteTestSupport.waitFor` too literally) is unsafe
// to reuse repeatedly against `stateUpdates`.
import Foundation
import Testing
@testable import IHAPI
import IHAPITestSupport
import IHLive
@testable import IHFeatures
@testable import IHModels

@Suite("ProjectionViewModel (#1504)")
@MainActor
struct ProjectionViewModelTests {

    // MARK: - Fixtures

    /// Minimal, valid `SongDetail` — one `SongComponent` per entry in
    /// `componentLineCounts` — via the SAME compiler-synthesized
    /// memberwise-init idiom `SongComparisonEngineTests`/
    /// `ProjectionResolverTests` already establish.
    private static func makeSongDetail(id: String, componentLineCounts: [Int]) -> SongDetail {
        let components = componentLineCounts.enumerated().map { index, count in
            SongComponent(
                type: "verse", number: index + 1, lines: (0..<count).map { "Line \($0)" },
                chords: nil, language: nil, lineIds: Array(0..<count), lineLanguages: nil
            )
        }
        return SongDetail(
            songId: SongID(rawValue: id) ?? SongID(songbookAbbreviation: "MP", number: 1),
            number: 1, title: "Test Song \(id)", songbookAbbreviation: "MP", songbookName: "Mission Praise",
            language: "en", copyright: "", tuneName: "", ccli: "", iswc: "",
            verified: false, lyricsPublicDomain: false, musicPublicDomain: false,
            hasAudio: false, hasSheetMusic: false, originCity: "", originCityId: nil, publicId: nil,
            arrangement: nil, writers: [], composers: [], arrangers: [], adaptors: [], translators: [], artists: [],
            components: components, tags: [], alternativeTitles: [], links: [], works: [], media: [],
            translations: nil, annotations: nil, royaltyIds: nil
        )
    }

    /// A `@Sendable` fetcher that counts every call via `counter` and
    /// answers from a fixed set of fixtures — the "injected fetcher
    /// closure, no network" shape the spec's test plan calls for.
    private static func makeCountingFetcher(
        songs: [SongDetail], counter: LockedCounter
    ) -> @Sendable (SongID) async throws -> SongDetail {
        let byId = Dictionary(uniqueKeysWithValues: songs.map { ($0.songId, $0) })
        return { id in
            counter.increment()
            guard let detail = byId[id] else { throw APIError.decoding }
            return detail
        }
    }

    // MARK: - selectSong: success / gated / offline

    @Test("selectSong success publishes exactly one canonical state and loads the song")
    func selectSongSuccessPublishesOnce() async throws {
        let song = Self.makeSongDetail(id: "MP-7001", componentLineCounts: [3])
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in song })
        let (collector, consumer) = makeCollector(for: viewModel)
        defer { consumer.cancel() }

        let result = await viewModel.selectSong(songId: song.songId)
        #expect(result == .shown)
        #expect(viewModel.song?.songId == song.songId)

        let state = try await waitForState(collector)
        #expect(state == ProjectionViewModel.ProjectionState(songId: song.songId, componentIndex: 0, lineIndex: nil, displayState: .lyrics))
        await expectNothingPublished(collector)
    }

    @Test("a gated selectSong (APIError.unauthorized) leaves state untouched and returns .unavailable")
    func selectSongGatedReturnsUnavailable() async throws {
        let songId = SongID(rawValue: "MP-5001") ?? SongID(songbookAbbreviation: "MP", number: 5001)
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in throw APIError.unauthorized })
        let (collector, consumer) = makeCollector(for: viewModel)
        defer { consumer.cancel() }

        let result = await viewModel.selectSong(songId: songId)
        #expect(result == .unavailable)
        #expect(viewModel.song == nil)
        #expect(viewModel.position == .none)
        await expectNothingPublished(collector)
    }

    @Test("an offline selectSong (APIError.offline) leaves state untouched and returns .failed")
    func selectSongOfflineReturnsFailed() async throws {
        let songId = SongID(rawValue: "MP-5002") ?? SongID(songbookAbbreviation: "MP", number: 5002)
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in throw APIError.offline })
        let (collector, consumer) = makeCollector(for: viewModel)
        defer { consumer.cancel() }

        let result = await viewModel.selectSong(songId: songId)
        guard case .failed = result else {
            Issue.record("expected .failed, got \(result)")
            return
        }
        #expect(viewModel.song == nil)
        #expect(viewModel.position == .none)
        await expectNothingPublished(collector)
    }

    // MARK: - Reposition without refetch

    @Test("selecting the currently-projected song again repositions without a new fetch")
    func selectSongRepositionsWithoutRefetch() async throws {
        let song = Self.makeSongDetail(id: "MP-6001", componentLineCounts: [2, 3])
        let counter = LockedCounter()
        let viewModel = ProjectionViewModel(fetchSongDetail: Self.makeCountingFetcher(songs: [song], counter: counter))
        let (collector, consumer) = makeCollector(for: viewModel)
        defer { consumer.cancel() }

        _ = await viewModel.selectSong(songId: song.songId)
        _ = try await waitForState(collector)
        #expect(counter.current == 1)

        let result = await viewModel.selectSong(songId: song.songId, componentIndex: 1)
        #expect(result == .shown)
        #expect(counter.current == 1)
        #expect(viewModel.position == ProjectionPosition(componentIndex: 1, lineIndex: nil))
        _ = try await waitForState(collector)
    }

    // MARK: - prepare / cache

    @Test("prepare warms the cache silently; a later selectSong for the same id is a cache hit")
    func prepareThenSelectIsACacheHit() async throws {
        let song = Self.makeSongDetail(id: "MP-2001", componentLineCounts: [2])
        let counter = LockedCounter()
        let viewModel = ProjectionViewModel(fetchSongDetail: Self.makeCountingFetcher(songs: [song], counter: counter))
        let (collector, consumer) = makeCollector(for: viewModel)
        defer { consumer.cancel() }

        let prepareResult = await viewModel.prepare(songId: song.songId)
        #expect(prepareResult == .shown)
        #expect(counter.current == 1)
        #expect(viewModel.song == nil)
        await expectNothingPublished(collector)

        let selectResult = await viewModel.selectSong(songId: song.songId)
        #expect(selectResult == .shown)
        #expect(counter.current == 1)
        #expect(viewModel.song?.songId == song.songId)
        _ = try await waitForState(collector)
    }

    @Test("prepare's cap-3 cache evicts the oldest id once a 4th distinct song is prepared")
    func prepareEvictsOldestBeyondCapacity() async throws {
        let songs = (1...4).map { Self.makeSongDetail(id: "MP-800\($0)", componentLineCounts: [2]) }
        let counter = LockedCounter()
        let viewModel = ProjectionViewModel(fetchSongDetail: Self.makeCountingFetcher(songs: songs, counter: counter))
        let (collector, consumer) = makeCollector(for: viewModel)
        defer { consumer.cancel() }

        for song in songs {
            _ = await viewModel.prepare(songId: song.songId)
        }
        #expect(counter.current == 4)

        // Evicted — selecting it now costs a FRESH (5th) fetch.
        _ = await viewModel.selectSong(songId: songs[0].songId)
        #expect(counter.current == 5)
        _ = try await waitForState(collector)

        // Still cached (one of the 3 most-recently prepared) — no further fetch.
        _ = await viewModel.selectSong(songId: songs[2].songId)
        #expect(counter.current == 5)
        _ = try await waitForState(collector)
    }

    // MARK: - Latest-wins on overlapping selectSong

    @Test("latest-wins: the newer selectSong's fetch wins; the superseded one returns .failed and publishes nothing")
    func latestWinsOnOverlappingSelectSong() async throws {
        let songA = Self.makeSongDetail(id: "MP-9001", componentLineCounts: [2])
        let songB = Self.makeSongDetail(id: "MP-9002", componentLineCounts: [2])
        let fetcher = GatedFetcher(songs: [songA, songB])
        let viewModel = ProjectionViewModel(fetchSongDetail: { try await fetcher.fetch($0) })
        let (collector, consumer) = makeCollector(for: viewModel)
        defer { consumer.cancel() }

        async let resultA = viewModel.selectSong(songId: songA.songId)
        async let resultB = viewModel.selectSong(songId: songB.songId)

        await fetcher.waitUntilArrived(songA.songId)
        await fetcher.waitUntilArrived(songB.songId)

        await fetcher.release(songB.songId)
        await fetcher.release(songA.songId)

        let finalResultB = await resultB
        let finalResultA = await resultA

        #expect(finalResultB == .shown)
        #expect(finalResultA == .failed("superseded"))
        #expect(viewModel.song?.songId == songB.songId)
        _ = try await waitForState(collector)  // B's one publish
        await expectNothingPublished(collector)  // A published nothing
    }

    // MARK: - Relative intents

    @Test("relative intents publish on real movement, dedupe on a clamped no-op")
    func relativeIntentsPublishOnRealMovementOnly() async throws {
        let song = Self.makeSongDetail(id: "MP-3001", componentLineCounts: [2])
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in song })
        let (collector, consumer) = makeCollector(for: viewModel)
        defer { consumer.cancel() }
        _ = await viewModel.selectSong(songId: song.songId)
        _ = try await waitForState(collector)

        viewModel.nextLine()
        let first = try await waitForState(collector)
        #expect(first.lineIndex == 0)

        viewModel.nextLine()
        let second = try await waitForState(collector)
        #expect(second.lineIndex == 1)

        viewModel.nextLine()  // already at the last line — clamp-as-no-op
        await expectNothingPublished(collector)
        #expect(viewModel.position.lineIndex == 1)
    }

    // MARK: - Display state + frozen semantics

    @Test("setDisplayState publishes on real change, no-ops on the same value, and frozen pins the picture while canonical state keeps moving")
    func displayStateAndFrozenSemantics() async throws {
        let song = Self.makeSongDetail(id: "MP-4001", componentLineCounts: [3])
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in song })
        let (collector, consumer) = makeCollector(for: viewModel)
        defer { consumer.cancel() }
        _ = await viewModel.selectSong(songId: song.songId)
        _ = try await waitForState(collector)

        viewModel.setDisplayState(.blackout)
        let blackout = try await waitForState(collector)
        #expect(blackout.displayState == .blackout)

        viewModel.setDisplayState(.blackout)
        await expectNothingPublished(collector)

        viewModel.setDisplayState(.lyrics)
        _ = try await waitForState(collector)

        viewModel.setDisplayState(.frozen)
        _ = try await waitForState(collector)
        let frozenPosition = viewModel.renderedContent.position

        viewModel.nextLine()
        _ = try await waitForState(collector)
        viewModel.nextLine()
        _ = try await waitForState(collector)

        #expect(viewModel.projectionState.lineIndex != frozenPosition.lineIndex)
        #expect(viewModel.renderedContent.position == frozenPosition)

        viewModel.setDisplayState(.lyrics)
        _ = try await waitForState(collector)
        #expect(viewModel.renderedContent.position == viewModel.position)
    }

    // MARK: - setAppearance

    @Test("setAppearance clamps textScale, leaves nil fields untouched, and never publishes")
    func setAppearanceClampsAndNeverPublishes() async throws {
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in throw APIError.offline })
        let (collector, consumer) = makeCollector(for: viewModel)
        defer { consumer.cancel() }

        viewModel.setAppearance(theme: "dark", textScale: 9)
        #expect(viewModel.textScale == 3.0)
        #expect(viewModel.appearanceThemeHint == "dark")
        await expectNothingPublished(collector)

        viewModel.setAppearance(theme: nil, textScale: nil)
        #expect(viewModel.textScale == 3.0)
        #expect(viewModel.appearanceThemeHint == "dark")
        await expectNothingPublished(collector)
    }
}

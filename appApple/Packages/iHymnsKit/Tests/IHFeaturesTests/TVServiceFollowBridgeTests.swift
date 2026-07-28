// TVServiceFollowBridgeTests.swift
// IHFeaturesTests
//
// ELI5: Checks the little "the service just said X, make the projection do
// X" translator does exactly the right thing: maps every server display
// state correctly (and never invents `.frozen`), only reports a change when
// something actually moved, copes with a legacy songId that doesn't parse,
// and applies display-state before selection while still returning
// `.unavailable` untouched when the TV's own fetch is denied.
//
// DETAILED: Apple Phase-2 PR-15 (#1428). Mirrors `TVProjectionBridgeTests.swift`'s
// own "inject a fake `fetchSongDetail` closure, no real network" shape
// (#1421) — `TVServiceFollowBridge` has no networking of its own to fake,
// so every test here is pure, in-process, and fast.
import Foundation
import Testing
@testable import IHAPI
import IHLive
@testable import IHFeatures
@testable import IHModels

@Suite("TVServiceFollowBridge (#1428)")
@MainActor
struct TVServiceFollowBridgeTests {

    /// Minimal, valid `SongDetail` fixture — the SAME compiler-synthesized
    /// memberwise-init idiom `TVProjectionBridgeTests`/`ProjectionViewModelTests`
    /// already establish.
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

    private static let idleState = ProjectionViewModel.ProjectionState(songId: nil, componentIndex: nil, lineIndex: nil, displayState: .lyrics)

    // MARK: - .displayState(from:) mapping table

    @Test("nil state maps to .lyrics")
    func displayStateNilMapsToLyrics() {
        #expect(TVServiceFollowBridge.displayState(from: nil) == .lyrics)
    }

    @Test("displayStateRaw \"live\" maps to .lyrics")
    func displayStateLiveMapsToLyrics() {
        let state = LiveBroadcastState(displayStateRaw: "live")
        #expect(TVServiceFollowBridge.displayState(from: state) == .lyrics)
    }

    @Test("displayStateRaw \"blackout\" maps to .blackout")
    func displayStateBlackoutMapsToBlackout() {
        let state = LiveBroadcastState(displayStateRaw: "blackout")
        #expect(TVServiceFollowBridge.displayState(from: state) == .blackout)
    }

    @Test("displayStateRaw \"logo\" maps to .logo")
    func displayStateLogoMapsToLogo() {
        let state = LiveBroadcastState(displayStateRaw: "logo")
        #expect(TVServiceFollowBridge.displayState(from: state) == .logo)
    }

    @Test("no displayStateRaw, blank:true falls back to .blackout (the server's own blank↔displayState bridge)")
    func displayStateBlankFallsBackToBlackout() {
        let state = LiveBroadcastState(blank: true)
        #expect(TVServiceFollowBridge.displayState(from: state) == .blackout)
    }

    @Test("no displayStateRaw, no blank at all defaults to .lyrics")
    func displayStateTotallyEmptyDefaultsToLyrics() {
        #expect(TVServiceFollowBridge.displayState(from: LiveBroadcastState()) == .lyrics)
    }

    @Test(".frozen is never produced — it has no server-side equivalent")
    func displayStateNeverProducesFrozen() {
        for raw in ["live", "blackout", "logo", "unrecognised-future-value", nil] {
            let state = LiveBroadcastState(displayStateRaw: raw)
            #expect(TVServiceFollowBridge.displayState(from: state) != .frozen)
        }
    }

    // MARK: - .plan(for:current:) — dedupe + selection resolution

    @Test("identical snapshot vs. current state → both displayState and selection are nil (a true no-op)")
    func planDedupesAnIdenticalSnapshot() {
        let songId = SongID(songbookAbbreviation: "MP", number: 31)
        let current = ProjectionViewModel.ProjectionState(songId: songId, componentIndex: 1, lineIndex: 2, displayState: .lyrics)
        let snapshot = LiveBroadcastSnapshot(
            songId: songId.rawValue, componentIndex: 1, state: LiveBroadcastState(lineIndex: 2), revision: 5
        )

        let plan = TVServiceFollowBridge.plan(for: snapshot, current: current)
        #expect(plan.displayState == nil)
        #expect(plan.selection == nil)
        #expect(plan.unparsableSongId == nil)
    }

    @Test("a snapshot whose displayState changed but songId/section didn't reports ONLY the displayState change")
    func planReportsOnlyDisplayStateChange() {
        let songId = SongID(songbookAbbreviation: "MP", number: 31)
        let current = ProjectionViewModel.ProjectionState(songId: songId, componentIndex: 0, lineIndex: nil, displayState: .lyrics)
        let snapshot = LiveBroadcastSnapshot(
            songId: songId.rawValue, componentIndex: 0, state: LiveBroadcastState(displayStateRaw: "blackout"), revision: 6
        )

        let plan = TVServiceFollowBridge.plan(for: snapshot, current: current)
        #expect(plan.displayState == .blackout)
        #expect(plan.selection == nil)
    }

    @Test("a snapshot whose section changed but not the song reports ONLY the (re)selection, at the new componentIndex/lineIndex")
    func planReportsRepositionWithinSameSong() {
        let songId = SongID(songbookAbbreviation: "MP", number: 31)
        let current = ProjectionViewModel.ProjectionState(songId: songId, componentIndex: 0, lineIndex: nil, displayState: .lyrics)
        let snapshot = LiveBroadcastSnapshot(songId: songId.rawValue, componentIndex: 1, state: nil, revision: 7)

        let plan = TVServiceFollowBridge.plan(for: snapshot, current: current)
        #expect(plan.displayState == nil)
        #expect(plan.selection == TVServiceFollowBridge.Selection(songId: songId, componentIndex: 1, lineIndex: nil))
    }

    @Test("snapshot.songId == nil → selection is always nil, regardless of displayState")
    func planNilSongIdNeverSelects() {
        let songId = SongID(songbookAbbreviation: "MP", number: 31)
        let current = ProjectionViewModel.ProjectionState(songId: songId, componentIndex: 0, lineIndex: nil, displayState: .lyrics)
        let snapshot = LiveBroadcastSnapshot(songId: nil, componentIndex: 3, state: LiveBroadcastState(displayStateRaw: "logo"), revision: 8)

        let plan = TVServiceFollowBridge.plan(for: snapshot, current: current)
        #expect(plan.displayState == .logo)
        #expect(plan.selection == nil)
        #expect(plan.unparsableSongId == nil)
    }

    @Test("an unparsable songId (D-13's ~10 legacy ids) → no selection, unparsableSongId set to the raw value")
    func planUnparsableSongIdSetsNoticeNotSelection() {
        let snapshot = LiveBroadcastSnapshot(songId: "LEGACY_9", componentIndex: nil, state: nil, revision: 9)

        let plan = TVServiceFollowBridge.plan(for: snapshot, current: Self.idleState)
        #expect(plan.selection == nil)
        #expect(plan.unparsableSongId == "LEGACY_9")
    }

    // MARK: - .apply(_:to:) — ordering + the gated-fetch handoff

    @Test("apply(): displayState lands even when the selection's fetch never resolves (blackout-first ordering)")
    func applyOrdersDisplayStateBeforeSelection() async throws {
        // A fetcher that never returns — proves `setDisplayState` already
        // took effect before `selectSong`'s `await` even started. Observed
        // via `stateUpdates` (`makeCollector`/`waitForState`,
        // `ProjectionViewModelTestSupport.swift`) rather than a raced
        // `Task.yield()` — deterministic, no sleep, matches this package's
        // "injected-clock / bounded-wait, never a wall-clock race" rule.
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in try await Task.sleep(for: .seconds(60)); throw APIError.offline })
        let (collector, consumer) = makeCollector(for: viewModel)
        defer { consumer.cancel() }
        let snapshot = LiveBroadcastSnapshot(
            songId: "MP-0031", componentIndex: 0, state: LiveBroadcastState(displayStateRaw: "blackout"), revision: 1
        )

        let applyTask = Task { await TVServiceFollowBridge.apply(snapshot, to: viewModel) }
        // `setDisplayState` publishes synchronously, BEFORE `selectSong`
        // ever reaches its `await fetchSongDetail(...)` suspension point —
        // so this is the first (and, for this test, only) published state.
        let published = try await waitForState(collector)
        #expect(published.displayState == .blackout)
        #expect(viewModel.displayState == .blackout)
        applyTask.cancel()
    }

    @Test("apply(): no selection implied → returns nil and never calls the fetcher at all")
    func applyWithNoSelectionNeverFetches() async {
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in Issue.record("fetchSongDetail must not be called"); throw APIError.offline })
        let snapshot = LiveBroadcastSnapshot(songId: nil, componentIndex: nil, state: LiveBroadcastState(displayStateRaw: "logo"), revision: 1)

        let result = await TVServiceFollowBridge.apply(snapshot, to: viewModel)
        #expect(result == nil)
        #expect(viewModel.displayState == .logo)
    }

    @Test("apply(): a gated song (fetcher throws .unauthorized) returns .unavailable, VM state untouched")
    func applyGatedSongReturnsUnavailableStateUntouched() async {
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in throw APIError.unauthorized })
        let snapshot = LiveBroadcastSnapshot(songId: "MP-0031", componentIndex: 0, state: nil, revision: 1)

        let result = await TVServiceFollowBridge.apply(snapshot, to: viewModel)
        #expect(result == .unavailable)
        #expect(viewModel.song == nil)
    }

    @Test("apply(): a successful selection returns .shown and loads the song into the view model")
    func applySuccessfulSelectionAppliesToViewModel() async {
        let song = Self.makeSongDetail(id: "MP-7010", componentLineCounts: [3])
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in song })
        let snapshot = LiveBroadcastSnapshot(songId: song.songId.rawValue, componentIndex: 0, state: nil, revision: 1)

        let result = await TVServiceFollowBridge.apply(snapshot, to: viewModel)
        #expect(result == .shown)
        #expect(viewModel.song?.songId == song.songId)
    }
}

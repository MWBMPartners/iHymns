// TVProjectionBridgeTests.swift
// IHFeaturesTests
//
// ELI5: Checks the little "remote said X, make the projection do X"
// translator does exactly the right thing for every kind of message —
// including making sure the two failure kinds (gated vs. everything-else)
// never leak a real error string onto the LAN wire, and that transport/
// pairing messages never touch the projection at all.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §7.3). Mirrors
// `ProjectionViewModelTests.swift`'s own "inject a fake `fetchSongDetail`
// closure, no real network" shape (#1504) — `TVProjectionBridge` itself has
// no networking of its own to fake, so every test here is pure, in-process,
// and fast: no listener, no `RemoteSessionActor`, no sockets at all.
import Foundation
import Testing
@testable import IHAPI
import IHLive
@testable import IHFeatures
@testable import IHModels

@Suite("TVProjectionBridge (#1421)")
@MainActor
struct TVProjectionBridgeTests {

    /// Minimal, valid `SongDetail` fixture — the SAME compiler-synthesized
    /// memberwise-init idiom `ProjectionViewModelTests`/`ProjectionResolverTests`
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

    // MARK: - .selectSong

    @Test(".selectSong with a succeeding fetcher returns nil and loads the song into the view model")
    func selectSongSuccessAppliesToViewModel() async {
        let song = Self.makeSongDetail(id: "MP-7001", componentLineCounts: [3])
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in song })

        let reply = await TVProjectionBridge.apply(.selectSong(songId: song.songId, componentIndex: nil, lineIndex: nil), to: viewModel)

        #expect(reply == nil)
        #expect(viewModel.projectionState.songId == song.songId)
    }

    @Test(".selectSong whose fetcher throws .unauthorized maps to .error(.contentUnavailable, nil) — the §2.4.3 gating handoff")
    func selectSongUnauthorizedMapsToContentUnavailable() async {
        let songId = SongID(songbookAbbreviation: "MP", number: 7002)
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in throw APIError.unauthorized })

        let reply = await TVProjectionBridge.apply(.selectSong(songId: songId, componentIndex: nil, lineIndex: nil), to: viewModel)

        #expect(reply == .error(.contentUnavailable, message: nil))
        #expect(viewModel.song == nil)
    }

    @Test(".selectSong whose fetcher throws .offline maps to .error(.internalError, nil) — never a leaked user-facing string")
    func selectSongOfflineMapsToInternalErrorNoMessage() async {
        let songId = SongID(songbookAbbreviation: "MP", number: 7003)
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in throw APIError.offline })

        let reply = await TVProjectionBridge.apply(.selectSong(songId: songId, componentIndex: nil, lineIndex: nil), to: viewModel)

        guard case .error(let code, let message) = reply else {
            Issue.record("expected .error, got \(String(describing: reply))")
            return
        }
        #expect(code == .internalError)
        #expect(message == nil)
        #expect(viewModel.song == nil)
    }

    // MARK: - Navigation / display / appearance intents mutate the VM exactly as calling it directly would

    @Test(".nextLine/.setDisplayState/.scroll/.jumpLine mutate the view model exactly as calling it directly would")
    func navigationIntentsMutateViewModelDirectly() async {
        let song = Self.makeSongDetail(id: "MP-7004", componentLineCounts: [4, 4])
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in song })
        _ = await viewModel.selectSong(songId: song.songId)

        // `.nextLine` — component mode (line nil) at component 0 enters
        // line mode at line 0 (`ProjectionResolver.nextLine`'s own rule).
        _ = await TVProjectionBridge.apply(.nextLine, to: viewModel)
        #expect(viewModel.position == ProjectionPosition(componentIndex: 0, lineIndex: 0))

        _ = await TVProjectionBridge.apply(.setDisplayState(.blackout), to: viewModel)
        #expect(viewModel.displayState == .blackout)

        // `.scroll(delta: 2)` from (0, 0) advances two lines forward.
        _ = await TVProjectionBridge.apply(.scroll(delta: 2), to: viewModel)
        #expect(viewModel.position == ProjectionPosition(componentIndex: 0, lineIndex: 2))

        // `.jumpLine` — within the CURRENT component only.
        _ = await TVProjectionBridge.apply(.jumpLine(index: 3), to: viewModel)
        #expect(viewModel.position == ProjectionPosition(componentIndex: 0, lineIndex: 3))

        // Every one of these calls returned `nil` — no error to relay.
        let lastReply = await TVProjectionBridge.apply(.prevLine, to: viewModel)
        #expect(lastReply == nil)
    }

    // MARK: - Transport/pairing cases never reach the view model

    @Test(".ping/.hello/.endControl/.pairConfirm never touch the view model")
    func transportAndPairingCasesAreNoOps() async {
        let song = Self.makeSongDetail(id: "MP-7005", componentLineCounts: [2])
        let viewModel = ProjectionViewModel(fetchSongDetail: { _ in song })
        let positionBefore = viewModel.position

        let pingReply = await TVProjectionBridge.apply(.ping, to: viewModel)
        let helloReply = await TVProjectionBridge.apply(.hello(token: nil, kind: .phone), to: viewModel)
        let endControlReply = await TVProjectionBridge.apply(.endControl, to: viewModel)
        let pairConfirmReply = await TVProjectionBridge.apply(.pairConfirm(proof: "irrelevant", deviceName: nil), to: viewModel)

        #expect(pingReply == nil)
        #expect(helloReply == nil)
        #expect(endControlReply == nil)
        #expect(pairConfirmReply == nil)
        #expect(viewModel.song == nil)
        #expect(viewModel.position == positionBefore)
    }
}

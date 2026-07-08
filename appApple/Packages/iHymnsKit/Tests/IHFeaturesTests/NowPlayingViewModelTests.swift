// NowPlayingViewModelTests.swift
// IHFeaturesTests
//
// ELI5: Proves the app-level "now playing" owner (#1441) correctly tracks
// WHICH song is loaded into the shared player — switching `currentSong`
// when a genuinely different song starts, leaving it alone across a plain
// pause/resume of the SAME song, and forgetting it entirely on `stop()` —
// all against a fake "tape deck," never real `AVFoundation`/network.
//
// DETAILED: Reuses `SongAudioPlayerViewModel` UNCHANGED (mirrors
// `SongAudioPlayerViewModelTests.swift`'s own fake-engine harness shape,
// with its own small local `MockAudioPlaybackEngine` — that type is
// `private` to its own file, so this suite defines its own minimal copy
// rather than reaching across files for it, the same "small, file-scoped
// test double" precedent `InMemoryTokenStore` already establishes across
// several other `IHFeaturesTests` suites) — this file's whole point is
// proving `NowPlayingViewModel`'s NEW behaviour (the `currentSong` bookkeeping),
// not re-proving `SongAudioPlayerViewModel`'s own state machine, which
// already has its own dedicated suite.
import Foundation
import Testing
@testable import IHFeatures
@testable import IHModels

@MainActor
private final class MockAudioPlaybackEngine: SongAudioPlaybackEngine {
    private(set) var playCallCount = 0
    private(set) var pauseCallCount = 0
    private(set) var stopCallCount = 0

    func prepareToPlay(url: URL) async throws {}
    func play() { playCallCount += 1 }
    func pause() { pauseCallCount += 1 }
    func stop() { stopCallCount += 1 }
}

@MainActor
@Suite("NowPlayingViewModel (#1441)")
struct NowPlayingViewModelTests {

    private static let songOneURL = URL(string: "https://dev.ihymns.app/song-media/1")!
    private static let songTwoURL = URL(string: "https://dev.ihymns.app/song-media/2")!

    private static func makeSong(id: String, title: String) -> NowPlayingSongInfo {
        // swiftlint:disable:next force_unwrapping
        NowPlayingSongInfo(songId: SongID(rawValue: id)!, title: title, songbookAbbreviation: "MP", number: 1)
    }

    /// Polls `condition` until true or a generous timeout — same reasoning
    /// `SongAudioPlayerViewModelTests.waitUntil(...)` documents.
    private static func waitUntil(
        timeoutNanoseconds: UInt64 = 2_000_000_000,
        _ condition: () -> Bool
    ) async {
        let stepNanoseconds: UInt64 = 5_000_000
        var elapsed: UInt64 = 0
        while !condition(), elapsed < timeoutNanoseconds {
            try? await Task.sleep(nanoseconds: stepNanoseconds)
            elapsed += stepNanoseconds
        }
    }

    @Test("Starts with nothing playing")
    func startsEmpty() {
        let viewModel = NowPlayingViewModel(player: SongAudioPlayerViewModel(engine: MockAudioPlaybackEngine()))
        #expect(viewModel.currentSong == nil)
        #expect(viewModel.player.state == .idle)
    }

    @Test("togglePlayPause(song:url:) sets currentSong and starts the shared player")
    func togglePlayPauseSetsCurrentSong() async {
        let engine = MockAudioPlaybackEngine()
        let viewModel = NowPlayingViewModel(player: SongAudioPlayerViewModel(engine: engine))
        let song = Self.makeSong(id: "MP-1", title: "Amazing Grace")

        viewModel.togglePlayPause(song: song, url: Self.songOneURL)

        #expect(viewModel.currentSong == song)
        await Self.waitUntil { viewModel.player.state == .playing }
        #expect(engine.playCallCount == 1)
    }

    @Test("pausing/resuming the SAME song never re-assigns currentSong to a new (if equal) value")
    func pauseResumeSameSongLeavesCurrentSongAlone() async {
        let engine = MockAudioPlaybackEngine()
        let viewModel = NowPlayingViewModel(player: SongAudioPlayerViewModel(engine: engine))
        let song = Self.makeSong(id: "MP-1", title: "Amazing Grace")

        viewModel.togglePlayPause(song: song, url: Self.songOneURL)
        await Self.waitUntil { viewModel.player.state == .playing }

        viewModel.togglePlayPause(song: song, url: Self.songOneURL) // pause
        #expect(viewModel.player.state == .paused)
        #expect(viewModel.currentSong == song)
        #expect(engine.pauseCallCount == 1)

        viewModel.togglePlayPause(song: song, url: Self.songOneURL) // resume
        #expect(viewModel.player.state == .playing)
        #expect(viewModel.currentSong == song)
        #expect(engine.playCallCount == 2)
    }

    @Test("switching to a DIFFERENT song updates currentSong to the new one")
    func switchingSongsUpdatesCurrentSong() async {
        let engine = MockAudioPlaybackEngine()
        let viewModel = NowPlayingViewModel(player: SongAudioPlayerViewModel(engine: engine))
        let songOne = Self.makeSong(id: "MP-1", title: "Amazing Grace")
        let songTwo = Self.makeSong(id: "MP-2", title: "How Great Thou Art")

        viewModel.togglePlayPause(song: songOne, url: Self.songOneURL)
        await Self.waitUntil { viewModel.player.state == .playing }
        #expect(viewModel.currentSong == songOne)

        viewModel.togglePlayPause(song: songTwo, url: Self.songTwoURL)
        #expect(viewModel.currentSong == songTwo)
        await Self.waitUntil { viewModel.player.state == .playing }
        #expect(viewModel.player.loadedURL == Self.songTwoURL)
    }

    @Test("stop() stops the shared player AND forgets currentSong")
    func stopForgetsCurrentSong() async {
        let engine = MockAudioPlaybackEngine()
        let viewModel = NowPlayingViewModel(player: SongAudioPlayerViewModel(engine: engine))
        let song = Self.makeSong(id: "MP-1", title: "Amazing Grace")

        viewModel.togglePlayPause(song: song, url: Self.songOneURL)
        await Self.waitUntil { viewModel.player.state == .playing }

        viewModel.stop()

        #expect(viewModel.currentSong == nil)
        #expect(viewModel.player.state == .idle)
        #expect(viewModel.player.loadedURL == nil)
        #expect(engine.stopCallCount == 1)
    }
}

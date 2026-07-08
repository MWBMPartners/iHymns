// SongAudioPlayerViewModelTests.swift
// IHFeaturesTests
//
// ELI5: Proves the play/pause button's state machine
// (`SongAudioPlayerViewModel`, #184) walks through
// idle→loading→playing→paused→idle correctly, maps failures to the right
// on-screen message, and never lets a SLOW, superseded load overwrite a
// newer one's result — all against a fake "tape deck"
// (`MockAudioPlaybackEngine`), never a real `AVPlayer`/network/audio
// hardware, per this task's explicit test requirement.
//
// DETAILED: `MockAudioPlaybackEngine` conforms to `SongAudioPlaybackEngine`
// (`SongAudioPlaybackEngine.swift`) and records every call it receives.
// `Task.sleep(nanoseconds:)` is genuinely cancellation-aware (it throws
// `CancellationError` the moment its owning `Task` is cancelled, rather than
// waiting out the full duration) — the superseding-load test below relies on
// exactly that behaviour to prove `SongAudioPlayerViewModel.play(url:)`'s
// `loadTask?.cancel()` actually stops a stale load from ever reaching
// `state = .playing`.
import Foundation
import Testing
@testable import IHFeatures

/// A fake "tape deck" — records every call, and can be told to delay/throw
/// for a specific URL, so tests drive `SongAudioPlayerViewModel`'s state
/// machine deterministically with zero real `AVFoundation`/network use.
@MainActor
private final class MockAudioPlaybackEngine: SongAudioPlaybackEngine {
    private(set) var prepareCallURLs: [URL] = []
    private(set) var playCallCount = 0
    private(set) var pauseCallCount = 0
    private(set) var stopCallCount = 0

    /// If set, `prepareToPlay(url:)` throws this error for EVERY call.
    var errorToThrow: (any Error)?

    /// If set, `prepareToPlay(url:)` sleeps (cancellation-aware) before
    /// resolving, but ONLY when called with THIS specific url — every other
    /// url resolves immediately, letting a test simulate "song A is slow to
    /// load" without also slowing down song B.
    var slowURL: URL?
    var delayNanoseconds: UInt64 = 200_000_000

    func prepareToPlay(url: URL) async throws {
        prepareCallURLs.append(url)
        if url == slowURL {
            try await Task.sleep(nanoseconds: delayNanoseconds)
        }
        if let errorToThrow {
            throw errorToThrow
        }
    }

    func play() { playCallCount += 1 }
    func pause() { pauseCallCount += 1 }
    func stop() { stopCallCount += 1 }
}

@MainActor
@Suite("SongAudioPlayerViewModel (#184)")
struct SongAudioPlayerViewModelTests {

    private static let songURL = URL(string: "https://dev.ihymns.app/song-media/1")!
    private static let otherSongURL = URL(string: "https://dev.ihymns.app/song-media/2")!

    @Test("Starts idle")
    func startsIdle() {
        let viewModel = SongAudioPlayerViewModel(engine: MockAudioPlaybackEngine())
        #expect(viewModel.state == .idle)
        #expect(viewModel.loadedURL == nil)
    }

    @Test("Happy path: idle -> loading -> playing, and the engine actually gets told to play")
    func happyPathReachesPlaying() async {
        let engine = MockAudioPlaybackEngine()
        let viewModel = SongAudioPlayerViewModel(engine: engine)

        viewModel.togglePlayPause(url: Self.songURL)
        // The state flips to `.loading` synchronously, before the `Task`
        // even gets a chance to run — proves the transition doesn't wait on
        // the network/engine call to even START.
        #expect(viewModel.state == .loading)
        #expect(viewModel.loadedURL == Self.songURL)

        await Self.waitUntil { viewModel.state == .playing }
        #expect(viewModel.state == .playing)
        #expect(engine.prepareCallURLs == [Self.songURL])
        #expect(engine.playCallCount == 1)
    }

    @Test("playing -> paused -> playing: toggling twice pauses then resumes without re-loading")
    func toggleTwicePausesThenResumes() async {
        let engine = MockAudioPlaybackEngine()
        let viewModel = SongAudioPlayerViewModel(engine: engine)

        viewModel.togglePlayPause(url: Self.songURL)
        await Self.waitUntil { viewModel.state == .playing }

        viewModel.togglePlayPause(url: Self.songURL)
        #expect(viewModel.state == .paused)
        #expect(engine.pauseCallCount == 1)

        viewModel.togglePlayPause(url: Self.songURL)
        #expect(viewModel.state == .playing)
        #expect(engine.playCallCount == 2)
        // Resuming a PAUSED song must not re-fetch/re-prepare the asset —
        // only the original load should have called `prepareToPlay`.
        #expect(engine.prepareCallURLs == [Self.songURL])
    }

    @Test("A different url while paused starts a fresh load rather than resuming stale playback")
    func differentURLWhilePausedStartsFreshLoad() async {
        let engine = MockAudioPlaybackEngine()
        let viewModel = SongAudioPlayerViewModel(engine: engine)

        viewModel.togglePlayPause(url: Self.songURL)
        await Self.waitUntil { viewModel.state == .playing }
        viewModel.togglePlayPause(url: Self.songURL)
        #expect(viewModel.state == .paused)

        viewModel.togglePlayPause(url: Self.otherSongURL)
        #expect(viewModel.state == .loading)
        #expect(viewModel.loadedURL == Self.otherSongURL)

        await Self.waitUntil { viewModel.state == .playing }
        #expect(engine.prepareCallURLs == [Self.songURL, Self.otherSongURL])
    }

    @Test("A different url while PLAYING starts the new song, rather than just pausing the old one (#1441)")
    func differentURLWhilePlayingStartsNewSong() async {
        // Regression test for the bug #1441 (persistent cross-screen
        // "now playing" bar) exposed: promoting this view model to an
        // app-level shared instance means `togglePlayPause(url:)` can now
        // genuinely be called with a DIFFERENT url while already
        // `.playing` — a real scenario the old one-player-per-screen
        // ownership could never actually reach (see this method's own
        // updated doc comment).
        let engine = MockAudioPlaybackEngine()
        let viewModel = SongAudioPlayerViewModel(engine: engine)

        viewModel.togglePlayPause(url: Self.songURL)
        await Self.waitUntil { viewModel.state == .playing }
        #expect(engine.pauseCallCount == 0)

        // Tapping "play" on a DIFFERENT song while this one is still
        // playing must START the new song — NOT silently pause the old
        // one and do nothing else.
        viewModel.togglePlayPause(url: Self.otherSongURL)
        #expect(viewModel.state == .loading)
        #expect(viewModel.loadedURL == Self.otherSongURL)
        #expect(engine.pauseCallCount == 0)

        await Self.waitUntil { viewModel.state == .playing }
        #expect(viewModel.loadedURL == Self.otherSongURL)
        #expect(engine.prepareCallURLs == [Self.songURL, Self.otherSongURL])
    }

    @Test("stop() cancels playback, forgets the loaded url, and returns to idle")
    func stopReturnsToIdle() async {
        let engine = MockAudioPlaybackEngine()
        let viewModel = SongAudioPlayerViewModel(engine: engine)

        viewModel.togglePlayPause(url: Self.songURL)
        await Self.waitUntil { viewModel.state == .playing }

        viewModel.stop()
        #expect(viewModel.state == .idle)
        #expect(viewModel.loadedURL == nil)
        #expect(engine.stopCallCount == 1)
    }

    @Test("A playback-not-possible failure maps to a specific, honest message")
    func notPlayableErrorMapsToSpecificMessage() async {
        let engine = MockAudioPlaybackEngine()
        engine.errorToThrow = SongAudioPlaybackError.notPlayable
        let viewModel = SongAudioPlayerViewModel(engine: engine)

        viewModel.togglePlayPause(url: Self.songURL)
        await Self.waitUntil { if case .error = viewModel.state { return true } else { return false } }
        #expect(viewModel.state == .error("This recording can't be played."))
    }

    @Test("An offline-shaped URLError maps to the offline message")
    func offlineErrorMapsToOfflineMessage() async {
        let engine = MockAudioPlaybackEngine()
        engine.errorToThrow = URLError(.notConnectedToInternet)
        let viewModel = SongAudioPlayerViewModel(engine: engine)

        viewModel.togglePlayPause(url: Self.songURL)
        await Self.waitUntil { if case .error = viewModel.state { return true } else { return false } }
        #expect(viewModel.state == .error("You're offline. Check your connection and try again."))
    }

    @Test("Any other failure maps to a generic 'try again' message")
    func genericFailureMapsToGenericMessage() async {
        let engine = MockAudioPlaybackEngine()
        engine.errorToThrow = URLError(.badServerResponse)
        let viewModel = SongAudioPlayerViewModel(engine: engine)

        viewModel.togglePlayPause(url: Self.songURL)
        await Self.waitUntil { if case .error = viewModel.state { return true } else { return false } }
        #expect(viewModel.state == .error("Couldn't load this recording. Please try again."))
    }

    @Test("Tapping a second song before the first finishes loading cancels the stale load — only the newest request can ever land .playing")
    func supersedingLoadCancelsStaleOne() async {
        let engine = MockAudioPlaybackEngine()
        engine.slowURL = Self.songURL
        engine.delayNanoseconds = 300_000_000
        let viewModel = SongAudioPlayerViewModel(engine: engine)

        // Start the SLOW load, then immediately supersede it with the fast
        // one before it has any chance to resolve.
        viewModel.togglePlayPause(url: Self.songURL)
        #expect(viewModel.state == .loading)
        viewModel.togglePlayPause(url: Self.otherSongURL)
        #expect(viewModel.loadedURL == Self.otherSongURL)

        // The fast load should win almost immediately — well before the
        // slow one's 300ms delay would otherwise resolve.
        await Self.waitUntil { viewModel.state == .playing }
        #expect(viewModel.loadedURL == Self.otherSongURL)
        #expect(engine.playCallCount == 1)

        // Give the stale, cancelled load's sleep plenty of time to have
        // resolved (or been cut short by cancellation) — either way it must
        // NEVER have been allowed to call `play()` or overwrite the state
        // the newer request already set.
        try? await Task.sleep(nanoseconds: 400_000_000)
        #expect(viewModel.state == .playing)
        #expect(viewModel.loadedURL == Self.otherSongURL)
        #expect(engine.playCallCount == 1)
    }

    /// Polls `condition` on the main actor until it's true or a generous
    /// timeout elapses — avoids a single fixed `Task.sleep` guess for "has
    /// the view model's background `Task` finished yet," which would make
    /// this suite either unnecessarily slow (a long fixed wait every test)
    /// or flaky (a short one that occasionally loses the race) depending on
    /// test-machine load.
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
}

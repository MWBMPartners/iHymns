// SongAudioPlayerViewModel.swift
// IHFeatures
//
// ELI5: What the play/pause button on a song's page actually watches —
// "nothing loaded yet," "fetching the recording," "playing," "paused," or
// "something went wrong" — and the one place that turns a tap on that
// button into the right next state.
//
// DETAILED: #184 (Apple Phase 1: audio & sheet music). `@MainActor
// @Observable`, one fresh instance per `SongDetailView` (owned via `@State`
// exactly the way `SongDetailViewModel` itself is — see that file's own
// header for the "this VIEW owns this per-screen object" convention), NOT a
// `LoadState<Value>` reuse: `LoadState` (`LoadState.swift`) models "fetched
// or not," but a transport control genuinely needs a THIRD post-load state
// (`.playing` vs `.paused`) `LoadState` has no room for — reusing it here
// would force an awkward second flag alongside it, so a bespoke
// (deliberately small) enum is the honest shape, not a violation of the
// "reuse, don't duplicate" rule (that rule is about not re-forking logic
// that's already the SAME shape, not about forcing every state machine in
// the app through one generic type regardless of fit).
//
// Delegates every real `AVFoundation` interaction to an injected
// `SongAudioPlaybackEngine` (`SongAudioPlaybackEngine.swift`) — this file
// itself never imports `AVFoundation` — so `SongAudioPlayerViewModelTests`
// can drive every state transition (`idle`→`loading`→`playing`→`paused`,
// and every error path) against a fake engine with zero real network/audio
// hardware, per this task's explicit test requirement.
import Foundation
import Observation

/// The play/pause control's current state.
///
/// ELI5: "Nothing loaded," "loading," "playing," "paused," or "broke, here's
/// why."
public enum SongAudioPlayerState: Sendable, Equatable {
    case idle
    case loading
    case playing
    case paused
    case error(String)
}

/// Drives one song's audio play/pause control.
///
/// ELI5: "Play this URL" / "pause" / "stop," and always know which of those
/// five states we're currently in.
@MainActor
@Observable
public final class SongAudioPlayerViewModel {
    public private(set) var state: SongAudioPlayerState = .idle

    /// The URL `state == .playing`/`.paused` currently refers to — `nil`
    /// once `stop()` has run or before the first `play(url:)` call.
    /// `private(set)` rather than fully private so `togglePlayPause(url:)`
    /// can tell "resume THIS song" apart from "a different song's URL was
    /// passed in while paused" (the latter starts a fresh load rather than
    /// resuming stale playback — a defensive guard against a theoretical
    /// future screen that reuses one view model across songs, even though
    /// today's one-view-model-per-`SongDetailView` usage never actually hits
    /// it).
    public private(set) var loadedURL: URL?

    private let engine: any SongAudioPlaybackEngine

    /// The in-flight `prepareToPlay(url:)` task, if any — cancelled by a
    /// second `play(url:)` call that supersedes it (e.g. the user taps a
    /// different song's play button before the first one finished loading),
    /// so only the MOST RECENT request can ever land a state update.
    private var loadTask: Task<Void, Never>?

    /// - Parameter engine: The real `AVFoundationAudioPlaybackEngine` by
    ///   default; tests inject a fake conforming to `SongAudioPlaybackEngine`
    ///   instead.
    public init(engine: any SongAudioPlaybackEngine = AVFoundationAudioPlaybackEngine()) {
        self.engine = engine
    }

    /// The single entry point the play/pause button's action calls —
    /// figures out from the CURRENT state whether that tap means "start
    /// playing," "resume," or "pause."
    ///
    /// ELI5: "Tap." Works out what that should do right now.
    public func togglePlayPause(url: URL) {
        switch state {
        case .playing:
            pause()
        case .paused where loadedURL == url:
            resume()
        case .idle, .paused, .loading, .error:
            play(url: url)
        }
    }

    /// Stops playback entirely and returns to `.idle` — called when the
    /// hosting screen disappears (a song page navigated away from), so audio
    /// never keeps playing for a song no longer on screen without the user
    /// explicitly choosing that via a background-playback control (out of
    /// this task's `#184` scope — the brief asks for "a play/pause control,"
    /// not a persistent now-playing bar).
    ///
    /// ELI5: "Stop completely — forget what was loaded."
    public func stop() {
        loadTask?.cancel()
        loadTask = nil
        engine.stop()
        loadedURL = nil
        state = .idle
    }

    // MARK: - Private state transitions

    private func play(url: URL) {
        loadTask?.cancel()
        state = .loading
        loadedURL = url
        loadTask = Task {
            do {
                try await engine.prepareToPlay(url: url)
                // A newer `play(url:)`/`stop()` call may have superseded
                // this task while `prepareToPlay` was suspended awaiting the
                // network — `Task.isCancelled` catches that race explicitly
                // (a cancelled `Task` does NOT always throw immediately from
                // every awaited call, so this check is not redundant with
                // the `catch is CancellationError` below).
                guard !Task.isCancelled else { return }
                engine.play()
                state = .playing
            } catch is CancellationError {
                // Superseded by a newer request — leave whatever state the
                // newer request already set alone; this stale task has
                // nothing useful left to report.
            } catch {
                state = .error(Self.userFacingMessage(for: error))
            }
        }
    }

    private func pause() {
        engine.pause()
        state = .paused
    }

    private func resume() {
        engine.play()
        state = .playing
    }

    /// Maps a thrown error into a short, user-facing sentence — mirrors
    /// `APIError+UserFacing.swift`'s "one place owns this wording" shape,
    /// but for the plain `URLError`/`SongAudioPlaybackError` this file's
    /// engine throws (never an `APIError` — media streaming bypasses the
    /// `?action=` dispatcher entirely, see `APIEnvironment+Media.swift`'s
    /// header).
    private static func userFacingMessage(for error: Error) -> String {
        if let urlError = error as? URLError {
            switch urlError.code {
            case .notConnectedToInternet, .networkConnectionLost, .timedOut, .dataNotAllowed:
                return "You're offline. Check your connection and try again."
            default:
                return "Couldn't load this recording. Please try again."
            }
        }
        if error is SongAudioPlaybackError {
            return "This recording can't be played."
        }
        return "Couldn't load this recording. Please try again."
    }
}

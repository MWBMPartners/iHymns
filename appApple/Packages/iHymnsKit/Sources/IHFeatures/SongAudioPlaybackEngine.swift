// SongAudioPlaybackEngine.swift
// IHFeatures
//
// ELI5: The actual "tape deck" that talks to Apple's audio system — loads a
// song's recording and presses play/pause/stop for real. Kept SEPARATE from
// `SongAudioPlayerViewModel` (the thing views actually observe) so a test can
// swap in a fake tape deck and check the view model's on/off/error logic
// without ever touching a real speaker, network request, or the Simulator's
// (sometimes flaky) audio hardware.
//
// DETAILED: #184 (Apple Phase 1: audio & sheet music). This is the
// "focused, testable type" the task brief asks for — a `@MainActor`
// protocol (`SongAudioPlaybackEngine`) plus ONE concrete `AVFoundation`
// implementation (`AVFoundationAudioPlaybackEngine`). The protocol is
// `@MainActor`-isolated rather than `Sendable`-constrained: every
// conforming method only ever needs to run on the main actor (SwiftUI/
// `AVPlayer` interaction, per Apple's own guidance
// https://developer.apple.com/documentation/avfoundation/avplayer), so
// isolating the PROTOCOL itself sidesteps needing every conforming type
// (including a future mock) to prove `Sendable` conformance for state that,
// by construction, never crosses an actor boundary.
//
// `AVAudioSession` (the API that makes audio survive app backgrounding /
// mix correctly with other apps) is iOS/tvOS/watchOS/visionOS-only — macOS
// has no such session concept, audio just plays through whatever output the
// system default is. Guarded with `#if os(...)` so this file — and the
// whole `IHFeatures` target — keeps compiling on every platform the package
// targets (`Package.swift`: iOS/macOS/tvOS/watchOS/visionOS), matching this
// task's explicit "guard so the shared package still compiles on every
// platform" instruction. `AVPlayer`/`AVURLAsset` themselves ARE available on
// every one of those five platforms, so only the session-configuration call
// needs the guard.
import Foundation
import AVFoundation

/// The minimal "tape deck" surface `SongAudioPlayerViewModel` needs — load a
/// URL, play, pause, stop.
///
/// ELI5: "Load this song, press play, press pause, press stop."
@MainActor
public protocol SongAudioPlaybackEngine: AnyObject {
    /// Loads `url` and leaves it ready to play (paused at the start) —
    /// throws if the asset genuinely can't be played (a 404, a corrupt
    /// file, an unsupported format) or if the load is cancelled mid-flight
    /// (`CancellationError`, when a caller starts loading a DIFFERENT URL
    /// before this one finished).
    func prepareToPlay(url: URL) async throws
    /// Resumes/starts playback of whatever was last prepared.
    func play()
    /// Pauses playback in place — a later `play()` resumes from here.
    func pause()
    /// Stops playback and releases the currently-loaded item entirely (used
    /// when the user navigates away from the song, not for a simple pause).
    func stop()
}

/// Thrown by `AVFoundationAudioPlaybackEngine.prepareToPlay(url:)` when
/// `AVURLAsset.load(.isPlayable)` comes back `false` — a real HTTP response
/// came back, but AVFoundation itself says the bytes aren't a playable
/// audio asset (a corrupt file, or a server error page served with a
/// misleading 200, both real possibilities against a hand-uploaded
/// `tblSongMedia` row).
///
/// ELI5: "We got a file back, but it isn't actually a song."
public enum SongAudioPlaybackError: Error, Sendable, Equatable {
    case notPlayable
}

/// The real `AVFoundation`-backed implementation of `SongAudioPlaybackEngine`
/// — what every app shell actually uses; `SongAudioPlayerViewModel`'s tests
/// substitute a fake instead (see `SongAudioPlayerViewModelTests.swift`).
///
/// ELI5: The real tape deck, built on Apple's `AVPlayer`.
@MainActor
public final class AVFoundationAudioPlaybackEngine: SongAudioPlaybackEngine {
    /// One `AVPlayer` reused across `prepareToPlay(url:)` calls (via
    /// `replaceCurrentItem(with:)`) rather than a fresh player per song —
    /// mirrors Apple's own recommended pattern for a single "now playing"
    /// slot (https://developer.apple.com/documentation/avfoundation/avplayer),
    /// and means only ONE audio-session configuration call is ever needed
    /// per engine instance, not one per song.
    private let player = AVPlayer()

    /// Whether `configureAudioSessionIfNeeded()` has already run once for
    /// this engine instance — configuring the session on EVERY play (rather
    /// than once) is harmless but pointless work; this flag skips the
    /// repeat calls once the first one has (successfully or not) happened.
    private var hasConfiguredAudioSession = false

    public init() {}

    public func prepareToPlay(url: URL) async throws {
        configureAudioSessionIfNeeded()
        let asset = AVURLAsset(url: url)
        let isPlayable = try await asset.load(.isPlayable)
        guard isPlayable else { throw SongAudioPlaybackError.notPlayable }
        let item = AVPlayerItem(asset: asset)
        player.replaceCurrentItem(with: item)
    }

    public func play() {
        player.play()
    }

    public func pause() {
        player.pause()
    }

    public func stop() {
        player.pause()
        // `replaceCurrentItem(with: nil)` (not just `pause()`) fully
        // releases the loaded asset/item — the correct "I'm done with this
        // song" signal, distinct from a user-facing pause the transport
        // controls trigger.
        player.replaceCurrentItem(with: nil)
    }

    /// Configures this process's shared audio session for background-safe
    /// music playback — the `#184` brief's "background-audio-session
    /// configuration so it behaves correctly."
    ///
    /// ELI5: "Tell the phone: I'm about to play music, please keep playing
    /// it even if the screen locks or another app makes a sound."
    ///
    /// DETAILED: `.playback` (not `.ambient`/`.soloAmbient`) is the category
    /// that (a) continues after the screen locks/app backgrounds — provided
    /// the app shell also declares the `audio` `UIBackgroundModes` capability
    /// in its Info.plist (`appApple/project.yml`, `#184`'s own PR note: this
    /// engine alone cannot grant that entitlement, only the app target can)
    /// — and (b) does NOT silence other apps' audio by default the way
    /// `.soloAmbient` would, appropriate for a hymnal app a congregant might
    /// reasonably run alongside another audio source. Best-effort: a session
    /// configuration failure (e.g. a Simulator quirk, or a hardware audio
    /// route conflict) must never block playback outright — `AVPlayer` can
    /// often still play through the OS's own default session even if this
    /// explicit configuration step fails, so the failure is only logged
    /// (DEBUG builds only — no user-facing error for what is, in the field,
    /// an extremely rare condition), never rethrown.
    private func configureAudioSessionIfNeeded() {
        guard !hasConfiguredAudioSession else { return }
        hasConfiguredAudioSession = true
        #if os(iOS) || os(tvOS) || os(watchOS) || os(visionOS)
        do {
            let session = AVAudioSession.sharedInstance()
            try session.setCategory(.playback, mode: .default)
            try session.setActive(true)
        } catch {
            #if DEBUG
            print("AVFoundationAudioPlaybackEngine: audio session configuration failed — \(error)")
            #endif
        }
        #endif
        // macOS has no `AVAudioSession` concept at all — audio plays through
        // the system's default output with no explicit session step needed,
        // so this function is intentionally a no-op there.
    }
}

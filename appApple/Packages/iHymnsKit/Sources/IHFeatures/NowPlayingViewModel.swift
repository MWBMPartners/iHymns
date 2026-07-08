// NowPlayingViewModel.swift
// IHFeatures
//
// ELI5: Which song is currently loaded into the app's ONE shared audio
// player, and is it playing or paused — answered the exact same way no
// matter which screen asks. This is what makes a song keep playing (and a
// tappable "now playing" bar keep showing) even after you navigate away
// from that song's own page.
//
// DETAILED: #1441 (persistent cross-screen "now playing" bar). #184 shipped
// `SongAudioPlayerViewModel`/`SongAudioPlaybackEngine` (the real
// `AVFoundation` "tape deck") but deliberately scoped playback to ONE
// `SongMediaSection` instance at a time — that file's own header called
// this "a v1 posture... a persistent player is a natural v2." This type IS
// that v2: it OWNS the single shared `SongAudioPlayerViewModel` instance
// (never a second player — the repo's explicit "reuse the existing engine"
// instruction) and adds exactly the ONE thing that engine doesn't know
// about — WHICH song a loaded URL actually belongs to — so a cross-screen
// mini-player has a title/songbook to show and a song to navigate back to.
//
// Held ONCE at `RootContainerView` (mirroring how that file already owns
// the one `settingsViewModel` for the whole app run) and injected down via
// SwiftUI's `.environment(_:)` (the Observation-framework object-injection
// form, `@Environment(NowPlayingViewModel.self)` to read it) — NOT passed
// as an explicit initializer parameter the way `AppRootViewModel` is
// threaded through every view today. Environment injection was chosen
// specifically here because `SongMediaSection` (deep inside a pushed
// `SongDetailView`, inside whichever of the SEVEN independent per-section
// `NavigationStack`s is currently active) and `NowPlayingBar` (rendered
// once, above ALL of them, outside any single `NavigationStack`) have no
// other simple path to share one instance — every other engine in this
// package is reached through the single `AppRootViewModel` an app shell
// constructs once and hands to `RootContainerView`, but adding playback
// state directly onto `AppRootViewModel` itself would have coupled a
// UI-navigation concern (which screen a tap should open) into the
// network/auth/offline composition root; keeping it as its own small
// environment-injected object is the more honest layering.
import Foundation
import IHModels
import Observation

/// Just enough about ONE song to render the persistent "now playing" bar
/// and navigate back to its `SongDetailView` — never the song's full
/// record (mirrors `RecentlyViewedSong`/`SavedSongInfo`'s identical
/// "receipt, not the whole song" shape elsewhere in this package).
///
/// ELI5: "This song is the one currently loaded into the player."
public struct NowPlayingSongInfo: Sendable, Hashable, Identifiable {
    public var id: SongID { songId }

    public let songId: SongID
    public let title: String
    public let songbookAbbreviation: String
    public let number: Int?

    public init(songId: SongID, title: String, songbookAbbreviation: String, number: Int?) {
        self.songId = songId
        self.title = title
        self.songbookAbbreviation = songbookAbbreviation
        self.number = number
    }
}

/// The app-level owner of the ONE shared audio player (#1441).
///
/// ELI5: "Which song is loaded into the ONE shared player, and is it
/// playing?"
@MainActor
@Observable
public final class NowPlayingViewModel {
    /// The ONE playback engine/state-machine every screen shares — #184's
    /// existing `SongAudioPlayerViewModel`, UNCHANGED. `SongMediaSection`'s
    /// play/pause control and `NowPlayingBar` both read `player.state`/
    /// `player.loadedURL` directly rather than this type re-declaring
    /// mirrors of them — one source of truth, not two kept in sync.
    public let player: SongAudioPlayerViewModel

    /// Which song `player` currently refers to — `nil` once playback has
    /// fully stopped (`stop()` below), matching `player.loadedURL`'s own
    /// "nil once stopped" lifecycle. `NowPlayingBar` renders nothing at all
    /// while this is `nil`.
    public private(set) var currentSong: NowPlayingSongInfo?

    public init(player: SongAudioPlayerViewModel = SongAudioPlayerViewModel()) {
        self.player = player
    }

    /// The single entry point every play/pause control (`SongMediaSection`'s
    /// row AND `NowPlayingBar`'s own button) calls — starts/resumes/pauses
    /// `song`'s `url` through the ONE shared player, keeping `currentSong`
    /// in lockstep with whichever URL is actually loaded.
    ///
    /// ELI5: "Tap." Works out whether that means play a NEW song, resume,
    /// or pause — same as `SongAudioPlayerViewModel.togglePlayPause(url:)`
    /// already did per-screen, just now aware of WHICH song each URL
    /// belongs to.
    ///
    /// DETAILED: Only overwrites `currentSong` when `url` DIFFERS from
    /// whatever the player already has loaded — resuming/pausing the SAME
    /// song (the common case: tapping play/pause repeatedly on the song
    /// you're already reading) must never flicker `currentSong` to a
    /// freshly-equal-but-different `NowPlayingSongInfo` value, which would
    /// otherwise needlessly re-trigger every observer of this property.
    public func togglePlayPause(song: NowPlayingSongInfo, url: URL) {
        if player.loadedURL != url {
            currentSong = song
        }
        player.togglePlayPause(url: url)
    }

    /// Stops playback entirely and forgets the current song — the
    /// persistent bar's explicit "stop" action. #1441 removes
    /// `SongMediaSection`'s old `.onDisappear { playerViewModel.stop() }`,
    /// which would otherwise defeat the entire point of a CROSS-screen
    /// player by killing playback the instant the user navigates away.
    ///
    /// ELI5: "Stop completely, and hide the bar."
    public func stop() {
        player.stop()
        currentSong = nil
    }
}

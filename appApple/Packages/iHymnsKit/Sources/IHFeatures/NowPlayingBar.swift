// NowPlayingBar.swift
// IHFeatures
//
// ELI5: The little floating bar that shows up above the tab bar (or at the
// bottom of the sidebar layout) whenever a song's recording is playing or
// paused — a title, a play/pause button, a stop button, and tapping the
// title takes you straight back to that song's page. Like Apple Music's
// mini-player, but for one hymn's recording.
//
// DETAILED: #1441 (persistent cross-screen "now playing" bar). Rendered
// ONCE by `RootContainerView` via `.safeAreaInset(edge: .bottom)` — that
// modifier, attached to the platform-specific root content (the compact
// `TabView` or the `NavigationSplitView`), is the standard SwiftUI recipe
// for "float a bar just above a `TabView`'s own tab-bar chrome" (it insets
// the content it's attached to and stacks this bar into the reclaimed
// space), and applies identically well to a `NavigationSplitView`'s bottom
// edge for the iPad/Mac/vision layout — one file, one placement, works for
// both of `RootContainerView`'s platform branches. Reads the shared
// `NowPlayingViewModel` via `@Environment(NowPlayingViewModel.self)` (that
// type's own header explains why environment injection, not an explicit
// initializer parameter, was the right call here) and renders NOTHING at
// all while `currentSong` is `nil` — the bar simply doesn't exist until
// something is actually loaded into the player, exactly like
// `SongMediaSection`'s own "just hide it" treatment for its optional rows.
//
// Deliberately keeps the tappable "return to this song" area and the
// play/pause/stop buttons as SIBLING controls in one `HStack` (a tap
// gesture on the info area, not a `Button` wrapping the whole bar) — a
// `Button` nested inside another `Button`'s label is a well-known SwiftUI
// hit-testing footgun (the outer button can swallow taps meant for the
// inner ones, unpredictably across platforms); this avoids the problem
// entirely rather than working around it.
import IHDesign
import IHModels
import SwiftUI

/// The persistent, cross-screen "now playing" mini-bar.
///
/// ELI5: Shows which song is loaded into the shared player, lets you
/// pause/resume/stop it, and tap back to that song's page — from ANY
/// screen, not just the one you started playback from.
struct NowPlayingBar: View {
    let rootViewModel: AppRootViewModel

    @Environment(NowPlayingViewModel.self) private var nowPlayingViewModel
    @State private var isPresentingSongDetail = false

    var body: some View {
        if let song = nowPlayingViewModel.currentSong {
            bar(for: song)
                .sheet(isPresented: $isPresentingSongDetail) {
                    // A fresh `NavigationStack` (mirroring
                    // `DeepLinkDestinationView`'s identical "present the
                    // destination in its OWN stack inside a sheet" shape,
                    // `RootContainerView.swift`'s own header) — the simplest
                    // way to reach "that song's page" regardless of which
                    // of the SEVEN independent per-section `NavigationStack`s
                    // happens to be active right now, without this bar
                    // needing to know (or reach into) any of them.
                    NavigationStack {
                        SongDetailView(songId: song.songId, rootViewModel: rootViewModel)
                    }
                }
        }
    }

    private func bar(for song: NowPlayingSongInfo) -> some View {
        HStack(spacing: 12) {
            infoArea(for: song)
            playPauseButton(song: song)
            stopButton
        }
        .ihGlassCard(cornerRadius: 16)
        .padding(.horizontal, 12)
        .padding(.bottom, 4)
    }

    /// The tappable "title + songbook, tap to return" area — a plain tap
    /// gesture (not a nested `Button`), see this file's header for why.
    private func infoArea(for song: NowPlayingSongInfo) -> some View {
        HStack(spacing: 8) {
            Image(systemName: "music.note")
                .foregroundStyle(IHColorTokens.accent)
            VStack(alignment: .leading, spacing: 2) {
                Text(song.title)
                    .font(.subheadline.weight(.semibold))
                    .lineLimit(1)
                Text(song.songbookAbbreviation)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .contentShape(Rectangle())
        .onTapGesture { isPresentingSongDetail = true }
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(.isButton)
        .accessibilityLabel("Now playing: \(song.title), \(song.songbookAbbreviation). Tap to return to the song.")
    }

    @ViewBuilder
    private func playPauseButton(song: NowPlayingSongInfo) -> some View {
        // `loadedURL` is only `nil` in the brief window before the FIRST
        // `togglePlayPause(song:url:)` call ever lands (the player is
        // never in this state while `currentSong` is non-nil — the two are
        // set together, `NowPlayingViewModel.togglePlayPause(song:url:)`)
        // — this guard is defensive belt-and-braces, not a state this bar
        // should normally reach.
        if let url = nowPlayingViewModel.player.loadedURL {
            Button {
                nowPlayingViewModel.togglePlayPause(song: song, url: url)
            } label: {
                Image(systemName: playPauseSystemImage)
                    .font(.title3)
            }
            .foregroundStyle(IHColorTokens.accent)
            .accessibilityLabel(playPauseAccessibilityLabel)
        }
    }

    private var playPauseSystemImage: String {
        switch nowPlayingViewModel.player.state {
        case .playing: "pause.fill"
        case .loading: "hourglass"
        default: "play.fill"
        }
    }

    private var playPauseAccessibilityLabel: String {
        nowPlayingViewModel.player.state == .playing ? "Pause" : "Play"
    }

    private var stopButton: some View {
        Button {
            nowPlayingViewModel.stop()
        } label: {
            Image(systemName: "xmark.circle.fill")
                .font(.title3)
        }
        .foregroundStyle(.secondary)
        .accessibilityLabel("Stop playback")
    }
}

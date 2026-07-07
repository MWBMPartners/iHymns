// SongMediaSection.swift
// IHFeatures
//
// ELI5: The "Media" card on a song's page — a play/pause control if there's
// a recording, a "View Sheet Music" button if there's a PDF, and a
// download/share row for every attached MIDI or MusicXML file. Renders
// nothing at all for a song with none of these, the same "just hide it"
// treatment `SongDetailView`'s `counterpartsShelf`/`relatedSongsShelf`
// already establish for optional sections.
//
// DETAILED: #184 (Apple Phase 1: audio & sheet music), composing this
// task's four new focused engines/view-models
// (`SongAudioPlayerViewModel`/`SheetMusicView`/`MediaDownloadRow`
// /`MediaDownloadControl`) into the one section `SongDetailView.swift`
// inserts into its `loadedContent`. Deliberately its OWN file (not folded
// into `SongDetailView.swift`, which the task brief already flags as "near
// budget") or into `SongDetailToolbar.swift` (already six controls) — a new
// cross-cutting concern gets a new focused file, per `.claude/CLAUDE.md`'s
// modularity rule, mirrored 1:1 into this native codebase.
//
// Media URLs are resolved via `rootViewModel.mediaURL(forStreamPath:)`
// (`AppRootViewModel+Media.swift`) — `SongMediaAsset.streamUrl` is a
// SERVER-RELATIVE path (`"/song-media/<id>"`), never something this view
// can hand a player/downloader directly.
import IHDesign
import IHModels
import SwiftUI

/// The song-detail screen's "Media" card: audio playback, sheet-music
/// viewing, and MIDI/MusicXML download — each independently hidden when the
/// song has no asset of that kind.
///
/// ELI5: Everything you can listen to/view/download for this one song.
struct SongMediaSection: View {
    let detail: SongDetail
    let rootViewModel: AppRootViewModel

    /// One player per `SongDetailView` instance (a fresh `SongMediaSection`
    /// is created every time a new song is opened, since `SongDetailView`
    /// itself constructs a fresh `SongDetailViewModel` per `songId` — see
    /// that file's header) — never reused across songs, so there is no risk
    /// of one song's playback state leaking into another's.
    @State private var playerViewModel = SongAudioPlayerViewModel()
    @State private var isPresentingSheetMusic = false

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            Text("Media")
                .font(.headline)
                // Matches `SongWorksSection`'s heading-rotor treatment
                // (#188) — a "Media" heading VoiceOver users can jump to
                // directly rather than swiping past every lyric line first.
                .accessibilityAddTraits(.isHeader)

            if let asset = detail.audioAsset, let url = mediaURL(for: asset) {
                audioRow(url: url)
            }

            if let asset = detail.sheetMusicAsset, let url = mediaURL(for: asset) {
                sheetMusicRow(asset: asset, url: url)
            }

            ForEach(detail.midiAssets) { asset in
                if let url = mediaURL(for: asset) {
                    MediaDownloadRow(asset: asset, url: url, label: "MIDI File", systemImage: "pianokeys")
                }
            }

            ForEach(detail.musicXmlAssets) { asset in
                if let url = mediaURL(for: asset) {
                    MediaDownloadRow(asset: asset, url: url, label: "MusicXML File", systemImage: "doc.plaintext")
                }
            }
        }
        .ihGlassCard()
        // Stops audio the moment this section leaves the view hierarchy
        // (navigating back / to a different song) — a v1 "one song at a
        // time, no persistent now-playing bar" posture matching #184's
        // scoped brief ("a play/pause control on SongDetailView"), not a
        // cross-screen mini-player. A persistent player is a natural v2 and
        // is out of THIS task's scope.
        .onDisappear { playerViewModel.stop() }
    }

    /// Whether this section has anything at all to show — `SongDetailView`
    /// checks this before inserting the section, so a song with zero media
    /// never renders an empty "Media" glass card.
    static func hasAnyMedia(_ detail: SongDetail) -> Bool {
        !detail.media.isEmpty
    }

    private func mediaURL(for asset: SongMediaAsset) -> URL? {
        rootViewModel.mediaURL(forStreamPath: asset.streamUrl)
    }

    // MARK: - Audio

    @ViewBuilder
    private func audioRow(url: URL) -> some View {
        HStack {
            Label("Audio Recording", systemImage: "waveform")
                .foregroundStyle(.primary)
            Spacer()
            audioControl(url: url)
        }
    }

    @ViewBuilder
    private func audioControl(url: URL) -> some View {
        switch playerViewModel.state {
        case .idle, .paused:
            playPauseButton(systemImage: "play.circle.fill", accessibilityLabel: "Play", url: url)

        case .loading:
            ProgressView()
                .accessibilityLabel("Loading audio")

        case .playing:
            playPauseButton(systemImage: "pause.circle.fill", accessibilityLabel: "Pause", url: url)

        case .error(let message):
            Button {
                playerViewModel.togglePlayPause(url: url)
            } label: {
                Image(systemName: "exclamationmark.triangle")
            }
            .foregroundStyle(.red)
            .help(message)
            .accessibilityLabel("Playback error: \(message)")
        }
    }

    private func playPauseButton(systemImage: String, accessibilityLabel: String, url: URL) -> some View {
        Button {
            playerViewModel.togglePlayPause(url: url)
        } label: {
            Image(systemName: systemImage)
                .font(.title2)
        }
        .foregroundStyle(IHColorTokens.accent)
        .accessibilityLabel(accessibilityLabel)
    }

    // MARK: - Sheet music

    @ViewBuilder
    private func sheetMusicRow(asset: SongMediaAsset, url: URL) -> some View {
        HStack {
            Button {
                isPresentingSheetMusic = true
            } label: {
                Label("View Sheet Music", systemImage: "doc.text.image")
            }
            .foregroundStyle(IHColorTokens.accent)

            Spacer()

            // The "save a copy" fallback (#184's "share/export affordance
            // if trivial") — reuses the SAME icon-only control the MIDI/
            // MusicXML rows use, next to the primary "View" action rather
            // than inside a second full `MediaDownloadRow` (which would
            // duplicate the "Sheet Music" label already shown by the button
            // above it).
            MediaDownloadControl(asset: asset, url: url)
        }
        .sheet(isPresented: $isPresentingSheetMusic) {
            SheetMusicView(asset: asset, url: url)
        }
    }
}

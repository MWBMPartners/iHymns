// MediaDownloadRow.swift
// IHFeatures
//
// ELI5: One line in a song's media list for a file you can't play inside
// the app (a MIDI file, a MusicXML file) — a label, and a button that first
// downloads it, then hands it to the system share sheet so you can save it
// to Files, AirDrop it, or open it in another app that DOES understand that
// format.
//
// DETAILED: #184's scope item 3 — "MIDI + MusicXML: if the backend serves
// these, provide at minimum download/share/'open in…', since native
// rendering... is out of scope." ONE generic row (not a MIDI-specific row
// and a separate MusicXML-specific row) — the two kinds need IDENTICAL UI,
// just a different label/icon, so parameterising rather than duplicating is
// the repo's modularity rule applied at the smallest possible scale.
// `SongMediaSection.swift` also reuses this same row for the sheet-music
// PDF's own "Save a Copy" fallback (its `sheetMusicDownloadRow`), so this
// type earns its keep across all three attached-file kinds `#184` covers.
import IHDesign
import IHModels
import SwiftUI

/// One downloadable-media row: an icon+label, and a download → share
/// control driven by its own `MediaDownloadViewModel`.
///
/// ELI5: "Here's a file. Tap to download it, then share/save/open it."
struct MediaDownloadRow: View {
    let asset: SongMediaAsset
    let url: URL
    let label: String
    let systemImage: String

    var body: some View {
        HStack {
            Label(label, systemImage: systemImage)
                .foregroundStyle(.primary)
            Spacer()
            MediaDownloadControl(asset: asset, url: url)
        }
    }
}

/// The bare icon-only download → share control, WITHOUT the leading label
/// `MediaDownloadRow` adds — split out so `SongMediaSection`'s sheet-music
/// row can place this exact control next to its OWN "View Sheet Music"
/// button (a custom leading affordance `MediaDownloadRow`'s fixed
/// `Label(label:systemImage:)` shape can't express) without re-implementing
/// the download state machine's icon/spinner/share-link switch a second
/// time — the repo's "if a piece of UI... exists in more than one place,
/// extract it into a shared module" rule applied at the smallest scale that
/// actually needs sharing.
///
/// ELI5: Just the download/share button part, on its own.
struct MediaDownloadControl: View {
    let asset: SongMediaAsset
    let url: URL

    /// Owned per-control (not shared across rows) so downloading one MIDI
    /// file never affects another row's state — mirrors `SongMediaSection`
    /// owning its OWN `SongAudioPlayerViewModel` per screen.
    @State private var viewModel = MediaDownloadViewModel()

    var body: some View {
        switch viewModel.state {
        case .idle:
            downloadButton(help: "Download \(asset.fileName)")

        case .loading:
            ProgressView()
                .accessibilityLabel("Downloading \(asset.fileName)")

        case .loaded(let localURL):
            // Once downloaded, the control becomes a `ShareLink` for the
            // LOCAL file (never the original remote `url`) — see
            // `MediaDownloadRow`'s header for why a local file, not a remote
            // URL, is what makes "Save to Files"/"open in…" actually work.
            //
            // `ShareLink` is UNAVAILABLE on tvOS (D-1, #1504's tvOS-build
            // gate — the `iHymnsTV` shell reaches this same shared
            // `IHFeatures` code even though its browse tabs never render a
            // media list today) — there's no Files app/share-sheet
            // destination on that platform, so a plain "downloaded"
            // confirmation replaces the share affordance there instead of
            // forking a second row view.
            #if os(tvOS)
            Label("Downloaded \(asset.fileName)", systemImage: "checkmark.circle")
                .labelStyle(.iconOnly)
                .foregroundStyle(IHColorTokens.accent)
            #else
            ShareLink(item: localURL) {
                Label("Share \(asset.fileName)", systemImage: "square.and.arrow.up")
                    .labelStyle(.iconOnly)
            }
            .foregroundStyle(IHColorTokens.accent)
            #endif

        case .error(let message):
            downloadButton(
                systemImage: "exclamationmark.triangle",
                foreground: .red,
                help: message
            )
        }
    }

    private func downloadButton(
        systemImage: String = "arrow.down.circle",
        foreground: Color = IHColorTokens.accent,
        help: String
    ) -> some View {
        Button {
            Task { await viewModel.download(url: url, suggestedFileName: asset.fileName) }
        } label: {
            Label("Download \(asset.fileName)", systemImage: systemImage)
                .labelStyle(.iconOnly)
        }
        .foregroundStyle(foreground)
        .help(help)
    }
}

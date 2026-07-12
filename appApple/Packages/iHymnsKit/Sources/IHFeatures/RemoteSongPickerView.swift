// RemoteSongPickerView.swift
// IHFeatures
//
// ELI5: The "which song should the TV show?" sheet — search or scroll the
// same catalogue you'd see on the Search tab, tap a song to put it on the
// TV screen right now, or long-press → "Prepare on TV" to warm it up in the
// background while the current song is still showing (so switching to it
// later feels instant).
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §6.3). Reuses the
// EXTRACTED shared matcher `AppRootViewModel.songsMatching(_:in:)`
// (`AppRootViewModel+Search.swift`) rather than re-forking the number/
// substring search ladder — the same number-then-substring behaviour the
// Search tab's `filteredSongs` gets, over this sheet's OWN `@State query`
// (deliberately NOT `rootViewModel.searchText`, which would clobber the
// Search tab's own live state the moment this sheet opens). `.selectSong`
// (tap) vs `.prepare` (context menu "Prepare on TV") is Decision D-9:
// sending both on the same tap would hide no round-trip and is cargo-cult
// prefetch — `prepare` is the real "cue the NEXT hymn while THIS one is
// still up" worship-flow move, deliberately a SEPARATE action.
import IHModels
import SwiftUI

/// The song-picker sheet the control surface's "Choose Song…" button opens.
public struct RemoteSongPickerView: View {
    private let rootViewModel: AppRootViewModel
    /// `sendIntent(.selectSong(songId:, componentIndex: nil, lineIndex: nil))`
    /// — the caller also dismisses the sheet.
    let onSelect: (SongID) -> Void
    /// `sendIntent(.prepare(songId:))` — the sheet stays open.
    let onPrepare: (SongID) -> Void

    @State private var query = ""
    @Environment(\.dismiss) private var dismiss

    public init(rootViewModel: AppRootViewModel, onSelect: @escaping (SongID) -> Void, onPrepare: @escaping (SongID) -> Void) {
        self.rootViewModel = rootViewModel
        self.onSelect = onSelect
        self.onPrepare = onPrepare
    }

    public var body: some View {
        NavigationStack {
            content
                .searchable(text: $query, prompt: "Search songs")
                .navigationTitle("Choose Song")
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("Cancel") { dismiss() }
                    }
                }
                .task { await rootViewModel.loadCatalogueIfNeeded() }
        }
    }

    @ViewBuilder
    private var content: some View {
        switch rootViewModel.catalogueLoadState {
        case .idle, .loading:
            ProgressView("Loading songs…")
        case .error(let message):
            ContentUnavailableView("Couldn't Load Songs", systemImage: "exclamationmark.triangle", description: Text(message))
        case .loaded(let songs):
            let matches = AppRootViewModel.songsMatching(query, in: songs)
            if matches.isEmpty {
                ContentUnavailableView.search(text: query)
            } else {
                List(matches) { song in
                    Button {
                        onSelect(song.songId)
                        dismiss()
                    } label: {
                        SongSummaryRow(song)
                    }
                    .buttonStyle(.plain)
                    .contextMenu {
                        Button {
                            onPrepare(song.songId)
                        } label: {
                            Label("Prepare on TV", systemImage: "arrow.triangle.2.circlepath")
                        }
                    }
                }
            }
        }
    }
}

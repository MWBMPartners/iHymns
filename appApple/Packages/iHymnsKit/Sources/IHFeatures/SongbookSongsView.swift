// SongbookSongsView.swift
// IHFeatures
//
// ELI5: The list of every song inside ONE songbook — tap a tile on the
// Songbooks screen and land here, then tap a song to read it.
//
// DETAILED: #1437 (Apple P1 Songbooks browse). Filters the SAME cached
// `songs_index` `AppRootViewModel` already loads (or loads on demand, via
// `loadCatalogueIfNeeded()`) rather than making a second, per-book network
// call — the local index already carries `songbookAbbreviation` for every
// row, so this is a pure client-side filter, matching #1436's own
// local-first search philosophy and never re-fetching data the app already
// has (the web API's `?action=songbook&abbr=` equivalent exists, but
// there's no reason to hit the network again for data already sitting in
// `catalogueLoadState`). Reuses `SongSummaryRow` — the shared song-list row
// — exactly like `CatalogueListView` does, per the repo's modularity rule.
//
// #186 UPDATE (Apple Phase 1, "Sharing & social") — adds the same "Share"
// toolbar affordance `SongDetailToolbarContent`/`SetlistDetailView` already
// have, so a songbook is one of the "every shareable entity" surfaces this
// task's brief lists. Built via `IHAppSupport.CanonicalURL.songbook(abbreviation:)`
// — the ONE shared URL-builder, never a second hand-rolled string here.
import IHAppSupport
import IHModels
import SwiftUI

/// Every song in one songbook, reusing the shared `SongSummaryRow`.
struct SongbookSongsView: View {
    let songbook: Songbook
    let rootViewModel: AppRootViewModel

    var body: some View {
        content
            .navigationTitle(songbook.name)
            .task { await rootViewModel.loadCatalogueIfNeeded() }
            .toolbar {
                ToolbarItem(placement: .primaryAction) {
                    if let shareURL = CanonicalURL.songbook(abbreviation: songbook.id) {
                        ShareLink(item: shareURL, preview: SharePreview(songbook.name)) {
                            Label("Share", systemImage: "square.and.arrow.up")
                        }
                    }
                }
            }
    }

    @ViewBuilder
    private var content: some View {
        switch rootViewModel.catalogueLoadState {
        case .idle, .loading:
            ProgressView("Loading songs…")
                .frame(maxWidth: .infinity, maxHeight: .infinity)

        case .error(let message):
            ContentUnavailableView(
                "Couldn't Load Songs",
                systemImage: "wifi.exclamationmark",
                description: Text(message)
            )

        case .loaded(let songs):
            let songsInBook = songs.filter {
                $0.songbookAbbreviation.caseInsensitiveCompare(songbook.id) == .orderedSame
            }
            if songsInBook.isEmpty {
                ContentUnavailableView(
                    "No Songs Yet",
                    systemImage: "music.note.list",
                    description: Text("This songbook has no songs in the catalogue yet.")
                )
            } else {
                List(songsInBook) { song in
                    NavigationLink(value: song.id) {
                        SongSummaryRow(song)
                    }
                }
                .listStyle(.plain)
            }
        }
    }
}

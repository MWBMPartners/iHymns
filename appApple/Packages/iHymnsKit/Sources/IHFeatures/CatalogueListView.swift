// CatalogueListView.swift
// IHFeatures
//
// ELI5: The main "browse the hymn book" screen — a searchable list of every
// song, built from the real `songs_index` API call. Tap a row and it takes
// you to that song's full lyrics.
//
// DETAILED: The #1399 E2E slice's list half. Reads `AppRootViewModel`'s
// `catalogueLoadState`/`searchText`/`filteredSongs` directly (rather than
// owning a second, competing view model) — per this task's brief,
// `AppRootViewModel` itself IS the catalogue view model; this file is just
// the screen rendering it. `NavigationLink(value:)` + `.navigationDestination
// (for:)` is the "same codepath on every platform" navigation idiom
// (Apple's WWDC22 "The SwiftUI cookbook for navigation"): this exact view
// works unmodified as the root of a `NavigationStack` (iPhone/compact iPad)
// OR as the sidebar column of a `NavigationSplitView` (regular iPad/Mac/
// visionOS, see `RootContainerView`) — pushing into the destination lands
// in the DETAIL column either way, so there is no per-platform fork here.
import IHModels
import SwiftUI

/// The searchable catalogue browser — every song, filterable by title,
/// number, or songbook.
public struct CatalogueListView: View {
    /// `@Bindable` (not a plain `let`) so `$viewModel.searchText` below can
    /// hand `.searchable(text:)` a genuine two-way `Binding` into this
    /// `@Observable` reference type — the standard way to derive a binding
    /// from an already-injected `@Observable` object (as opposed to
    /// `@State`, which OWNS its value; this view does not own
    /// `viewModel`, `AppRootViewModel.makeLive(environment:)` does).
    @Bindable private var viewModel: AppRootViewModel

    public init(viewModel: AppRootViewModel) {
        self.viewModel = viewModel
    }

    public var body: some View {
        content
            .navigationTitle("Songs")
            .searchable(text: $viewModel.searchText, prompt: "Search by title, number, or songbook")
            .navigationDestination(for: SongID.self) { songId in
                SongDetailView(songId: songId, rootViewModel: viewModel)
            }
            .task { await viewModel.loadCatalogueIfNeeded() }
    }

    @ViewBuilder
    private var content: some View {
        switch viewModel.catalogueLoadState {
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
            if songs.isEmpty {
                // A genuinely empty catalogue (e.g. a fresh/misconfigured
                // deployment) — distinct from "no SEARCH results" below.
                ContentUnavailableView(
                    "No Songs Yet",
                    systemImage: "music.note.list",
                    description: Text("This songbook catalogue is currently empty.")
                )
            } else if viewModel.filteredSongs.isEmpty {
                ContentUnavailableView.search(text: viewModel.searchText)
            } else {
                List(viewModel.filteredSongs) { song in
                    NavigationLink(value: song.id) {
                        SongSummaryRow(song)
                    }
                }
                .listStyle(.plain)
            }
        }
    }
}

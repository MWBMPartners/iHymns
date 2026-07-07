// CatalogueListView.swift
// IHFeatures
//
// ELI5: The main "browse the hymn book" screen — a searchable list of every
// song, built from the real `songs_index` API call. Tap a row and it takes
// you to that song's full lyrics. Type a number (with or without a
// songbook code) to jump straight to that hymn; tap the funnel icon to
// narrow the list down to one or more songbooks/languages; an empty search
// field shows your recent searches.
//
// DETAILED: The #1399 E2E slice's list half, extended by #1436 (search:
// by-number mode, songbook/language filters, recent searches). Reads
// `AppRootViewModel`'s `catalogueLoadState`/`searchText`/`filteredSongs`
// directly (rather than owning a second, competing view model) — per this
// task's brief, `AppRootViewModel` itself IS the catalogue view model; this
// file is just the screen rendering it. `NavigationLink(value:)` +
// `.navigationDestination(for:)` is the "same codepath on every platform"
// navigation idiom (Apple's WWDC22 "The SwiftUI cookbook for navigation"):
// this exact view works unmodified as the root of its OWN `NavigationStack`
// (iPhone tab, #1437) OR as the CONTENT column of a 3-column
// `NavigationSplitView` (regular iPad/Mac/visionOS, see
// `RootContainerView`'s section-switcher sidebar) — pushing into the
// destination lands in the DETAIL column either way, so there is no
// per-platform fork here.
//
// Recent searches use SwiftUI's own `.searchSuggestions`/`.searchCompletion`
// pair (not a hand-rolled overlay list) — tapping a suggestion is the
// system's built-in "fill the search field with this text" behaviour, and
// `.onSubmit(of: .search)` is the single point that actually RECORDS a
// query, mirroring Safari/Mail's own "remember on submit, not on every
// keystroke" recent-searches convention.
import IHModels
import SwiftUI

/// The searchable catalogue browser — every song, filterable by title,
/// number, or songbook, narrowable by a songbook/language filter menu.
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
            .searchSuggestions {
                recentSearchSuggestions
            }
            .onSubmit(of: .search) {
                viewModel.commitCurrentSearch()
            }
            .navigationDestination(for: SongID.self) { songId in
                SongDetailView(songId: songId, rootViewModel: viewModel)
            }
            .toolbar {
                ToolbarItem(placement: .primaryAction) {
                    filterMenu
                }
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
                emptyResultsView
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

    /// Distinguishes "nothing matched what you TYPED" (the system's own
    /// `ContentUnavailableView.search` copy) from "nothing matched your
    /// FILTERS" (#1436) — an empty search field with an active filter would
    /// otherwise show the search-specific empty state for a problem that
    /// has nothing to do with searching.
    @ViewBuilder
    private var emptyResultsView: some View {
        if viewModel.searchText.isEmpty {
            ContentUnavailableView {
                Label("No Songs Match Your Filters", systemImage: "line.3.horizontal.decrease.circle")
            } description: {
                Text("Try removing a songbook or language filter.")
            } actions: {
                Button("Clear Filters") { viewModel.clearFilters() }
            }
        } else {
            ContentUnavailableView.search(text: viewModel.searchText)
        }
    }

    /// The "Recent Searches" suggestions shown while the search field is
    /// active and empty — each row's `.searchCompletion(_:)` fills
    /// `searchText` with that query when tapped (#1436's "tap to re-run").
    @ViewBuilder
    private var recentSearchSuggestions: some View {
        if viewModel.searchText.isEmpty && !viewModel.recentSearches.isEmpty {
            Section("Recent Searches") {
                ForEach(viewModel.recentSearches, id: \.self) { query in
                    Label(query, systemImage: "clock.arrow.circlepath")
                        .searchCompletion(query)
                }
            }
            Button(role: .destructive) {
                viewModel.clearRecentSearches()
            } label: {
                Label("Clear Recent Searches", systemImage: "trash")
            }
        }
    }

    /// The songbook/language filter menu (#1436) — a `Menu` with two
    /// submenus (each row toggles that facet, a checkmark shows the current
    /// selection) plus a "Clear Filters" action once any facet is active.
    /// The button's own glyph fills in when a filter is active, so the
    /// affordance is visible without opening the menu at all.
    private var filterMenu: some View {
        Menu {
            if !viewModel.availableSongbookAbbreviations.isEmpty {
                Menu("Songbook") {
                    ForEach(viewModel.availableSongbookAbbreviations, id: \.abbreviation) { entry in
                        Button {
                            viewModel.toggleSongbookFilter(entry.abbreviation)
                        } label: {
                            if viewModel.selectedSongbookAbbreviations.contains(entry.abbreviation) {
                                Label(entry.name, systemImage: "checkmark")
                            } else {
                                Text(entry.name)
                            }
                        }
                    }
                }
            }

            if !viewModel.availableLanguages.isEmpty {
                Menu("Language") {
                    ForEach(viewModel.availableLanguages, id: \.self) { language in
                        Button {
                            viewModel.toggleLanguageFilter(language)
                        } label: {
                            if viewModel.selectedLanguages.contains(language) {
                                Label(language, systemImage: "checkmark")
                            } else {
                                Text(language)
                            }
                        }
                    }
                }
            }

            if viewModel.hasActiveFilters {
                Button(role: .destructive) {
                    viewModel.clearFilters()
                } label: {
                    Label("Clear Filters", systemImage: "xmark.circle")
                }
            }
        } label: {
            Label(
                "Filter",
                systemImage: viewModel.hasActiveFilters ? "line.3.horizontal.decrease.circle.fill" : "line.3.horizontal.decrease.circle"
            )
        }
    }
}

// SearchView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - SearchView
/// A full-text search view that filters all songs across every songbook.
///
/// The user can search by:
/// - Song title
/// - Lyrics content (all component lines)
/// - Songbook name or abbreviation
/// - Writer / composer names
/// - Song number
///
/// Results are displayed in a list with each row showing the song number, title,
/// a songbook badge and a brief lyrics preview. Tapping a result navigates to
/// `SongDetailView`.
///
/// An empty state is shown when the search yields no matches.
struct SearchView: View {

    // MARK: Environment
    /// The shared song store providing the complete list of songs to search through.
    @EnvironmentObject var songStore: SongStore

    // MARK: State
    /// The current search query entered by the user. Bound to the search field.
    @State var searchText: String

    // MARK: Initialisers
    /// Default initialiser with an empty search string (used in the iPhone tab).
    init() {
        _searchText = State(initialValue: "")
    }

    /// Initialiser that accepts an initial search string, used when the iPad/Mac
    /// split-view global search bar passes a query to the detail column.
    init(initialSearchText: String) {
        _searchText = State(initialValue: initialSearchText)
    }

    // MARK: Computed Properties
    /// The filtered list of songs matching the current search query.
    /// Returns an empty array when the search text is blank to avoid showing
    /// all 3,600+ songs at once.
    private var searchResults: [Song] {
        // Do not display results until the user has typed at least one character
        guard !searchText.isEmpty else { return [] }

        let query = searchText.lowercased()

        return songStore.songData.songs.filter { song in
            // Match against the song title
            if song.title.lowercased().contains(query) { return true }

            // Match against the song number (as a string)
            if String(song.number).contains(query) { return true }

            // Match against the songbook name or abbreviation
            if song.songbookName.lowercased().contains(query) { return true }
            if song.songbook.lowercased().contains(query) { return true }

            // Match against writer names
            if song.writers.contains(where: { $0.lowercased().contains(query) }) { return true }

            // Match against composer names
            if song.composers.contains(where: { $0.lowercased().contains(query) }) { return true }

            // Match against lyrics content (all lines in all components)
            for component in song.components {
                if component.lines.contains(where: { $0.lowercased().contains(query) }) {
                    return true
                }
            }

            return false
        }
    }

    // MARK: Body
    var body: some View {
        Group {
            if searchText.isEmpty {
                // ── Initial State ────────────────────────────────────────
                // Shown before the user has typed anything. Provides a
                // friendly prompt encouraging search input.
                searchPrompt
            } else if searchResults.isEmpty {
                // ── No Results State ─────────────────────────────────────
                // Shown when the query does not match any songs.
                ContentUnavailableView.search(text: searchText)
            } else {
                // ── Results List ─────────────────────────────────────────
                resultsList
            }
        }
        .navigationTitle("Search")
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.large)
        #endif
        // Attach the searchable modifier so the system provides a search bar
        .searchable(text: $searchText, prompt: "Title, lyrics, number, writer…")
    }

    // MARK: - Search Prompt (Initial State)
    /// A centred placeholder shown before the user begins typing.
    private var searchPrompt: some View {
        ContentUnavailableView {
            Label("Search Hymns", systemImage: "magnifyingglass")
        } description: {
            Text("Search across all songbooks by title, lyrics, song number, writer or composer.")
        }
    }

    // MARK: - Results List
    /// The list of search results, each row showing song metadata and a songbook badge.
    private var resultsList: some View {
        List {
            // ── Result Count Header ──────────────────────────────────────
            // A small header indicating how many songs matched the query.
            Section {
                Text("\(searchResults.count) result\(searchResults.count == 1 ? "" : "s") for \"\(searchText)\"")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .listRowBackground(Color.clear)
            }

            // ── Search Result Rows ───────────────────────────────────────
            // Each result links to the full song detail view.
            Section {
                ForEach(searchResults, id: \.id) { song in
                    NavigationLink(destination: SongDetailView(song: song)) {
                        searchResultRow(for: song)
                    }
                }
            }
        }
        .listStyle(.plain)
    }

    // MARK: - Search Result Row
    /// A single row in the search results list showing the song number, title,
    /// a coloured songbook badge, and a brief lyrics preview.
    private func searchResultRow(for song: Song) -> some View {
        HStack(spacing: 14) {
            // ── Song Number Badge ────────────────────────────────────────
            // The number displayed in an amber rounded rectangle.
            Text("\(song.number)")
                .font(.subheadline.bold().monospacedDigit())
                .foregroundStyle(.white)
                .frame(width: 44, height: 44)
                .background(AmberTheme.accent, in: RoundedRectangle(cornerRadius: 8))

            // ── Title, Songbook Badge and Preview ────────────────────────
            VStack(alignment: .leading, spacing: 4) {
                // Song title
                Text(song.title)
                    .font(.body.bold())
                    .lineLimit(1)

                HStack(spacing: 8) {
                    // Songbook abbreviation badge (e.g. "MP", "CH")
                    Text(song.songbook)
                        .font(.caption2.bold())
                        .foregroundStyle(.white)
                        .padding(.horizontal, 6)
                        .padding(.vertical, 2)
                        .background(AmberTheme.accent.opacity(0.7), in: Capsule())

                    // Lyrics preview from first component
                    if let firstComponent = song.components.first {
                        Text(firstComponent.lines.prefix(2).joined(separator: " "))
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    }
                }
            }

            Spacer()
        }
        .padding(.vertical, 4)
    }
}

// MARK: - Preview
#if DEBUG
#Preview {
    NavigationStack {
        SearchView()
            .environmentObject(SongStore())
    }
}
#endif

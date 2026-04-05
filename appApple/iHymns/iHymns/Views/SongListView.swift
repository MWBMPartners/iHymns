// SongListView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - SongListView
/// Displays a scrollable list of songs belonging to a specific songbook.
///
/// Songs are sorted by their number within the songbook. Each row shows the
/// song number, title, a brief lyrics preview drawn from the first component,
/// and a favourite-star indicator. Tapping a row navigates to `SongDetailView`.
///
/// A local `.searchable` modifier allows the user to filter songs within the
/// current songbook by title or number.
struct SongListView: View {

    // MARK: Properties
    /// The identifier of the songbook whose songs should be displayed
    /// (e.g. "MP" for Mission Praise).
    let songbookId: String

    // MARK: Environment
    /// The shared song store that supplies song data and favourite state.
    @EnvironmentObject var songStore: SongStore

    // MARK: State
    /// Text entered into the in-songbook search field.
    @State private var searchText: String = ""

    // MARK: Computed Properties
    /// All songs for the current songbook, sorted numerically and filtered by
    /// the user's local search query (matching title or number).
    private var filteredSongs: [Song] {
        // Retrieve every song that belongs to this songbook, sorted by number
        let allSongs = songStore.songsForSongbook(songbookId)
            .sorted { $0.number < $1.number }

        // If no search text has been entered, return the full list
        guard !searchText.isEmpty else { return allSongs }

        // Case-insensitive filter on title and stringified number
        let query = searchText.lowercased()
        return allSongs.filter { song in
            song.title.lowercased().contains(query)
            || String(song.number).contains(query)
        }
    }

    /// The songbook model matching `songbookId`, used to display the songbook name.
    private var songbook: Songbook? {
        songStore.songData.songbooks.first { $0.id == songbookId }
    }

    // MARK: Body
    var body: some View {
        List {
            // ── Song Rows ────────────────────────────────────────────────
            // Each song is rendered as a NavigationLink so the user can tap
            // through to the full lyrics in SongDetailView.
            ForEach(filteredSongs, id: \.id) { song in
                NavigationLink(destination: SongDetailView(song: song)) {
                    SongRow(song: song, songStore: songStore)
                }
            }
        }
        .listStyle(.plain)
        // Navigation title shows the songbook name (e.g. "Mission Praise")
        .navigationTitle(songbook?.name ?? songbookId)
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.large)
        #endif
        // Attach a local searchable modifier for filtering within this songbook
        .searchable(text: $searchText, prompt: "Search by title or number…")
        // Show an appropriate empty state when the filter yields no results
        .overlay {
            if filteredSongs.isEmpty && !searchText.isEmpty {
                ContentUnavailableView.search(text: searchText)
            } else if filteredSongs.isEmpty {
                ContentUnavailableView(
                    "No Songs",
                    systemImage: "music.note",
                    description: Text("This songbook does not contain any songs.")
                )
            }
        }
    }
}

// MARK: - SongRow
/// A single row in the song list showing the song number, title, a short
/// lyrics preview from the first component, and a filled/empty star
/// indicating whether the song has been favourited.
struct SongRow: View {

    /// The song model to display.
    let song: Song

    /// A reference to the song store, used to query favourite status.
    /// Passed explicitly rather than via `@EnvironmentObject` so the row
    /// can be used in previews without injecting the environment.
    @ObservedObject var songStore: SongStore

    var body: some View {
        HStack(spacing: 14) {
            // ── Song Number Badge ────────────────────────────────────────
            // Displays the hymn number in a rounded amber box.
            Text("\(song.number)")
                .font(.subheadline.bold().monospacedDigit())
                .foregroundStyle(.white)
                .frame(width: 44, height: 44)
                .background(AmberTheme.accent, in: RoundedRectangle(cornerRadius: 8))

            // ── Title and Lyrics Preview ─────────────────────────────────
            VStack(alignment: .leading, spacing: 4) {
                // Song title
                Text(song.title)
                    .font(.body.bold())
                    .lineLimit(1)

                // Preview: first two lines of the first component joined
                if let firstComponent = song.components.first {
                    Text(firstComponent.lines.prefix(2).joined(separator: " "))
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }

            Spacer()

            // ── Favourite Star ───────────────────────────────────────────
            // A filled star appears if the song is in the user's favourites.
            if songStore.isFavorite(songId: song.id) {
                Image(systemName: "star.fill")
                    .foregroundStyle(AmberTheme.light)
                    .imageScale(.small)
            }
        }
        .padding(.vertical, 4)
    }
}

// MARK: - Preview
#if DEBUG
#Preview {
    NavigationStack {
        SongListView(songbookId: "CP")
            .environmentObject(SongStore())
    }
}
#endif

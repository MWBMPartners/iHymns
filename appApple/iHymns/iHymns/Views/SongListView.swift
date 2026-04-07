// SongListView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - SongListView
/// Displays a scrollable list of songs belonging to a specific songbook.
/// Features alphabetical index strip, local search, and favourite indicators.
struct SongListView: View {

    let songbookId: String

    @EnvironmentObject var songStore: SongStore
    @State private var searchText: String = ""

    private var filteredSongs: [Song] {
        let allSongs = songStore.songsForSongbook(songbookId)

        guard !searchText.isEmpty else { return allSongs }

        let query = searchText.lowercased()
        return allSongs.filter { song in
            song.title.lowercased().contains(query)
            || String(song.number).contains(query)
        }
    }

    private var songbook: Songbook? {
        songStore.songData?.songbooks.first { $0.id == songbookId }
    }

    /// Available first letters for alphabetical index.
    private var availableLetters: [String] {
        let letters = Set(filteredSongs.compactMap { $0.title.first.map { String($0).uppercased() } })
        return letters.sorted()
    }

    var body: some View {
        List {
            ForEach(filteredSongs, id: \.id) { song in
                NavigationLink(destination: SongDetailView(song: song)) {
                    SongRow(song: song, songStore: songStore)
                }
            }
        }
        .listStyle(.plain)
        .navigationTitle(songbook?.name ?? songbookId)
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.large)
        #endif
        .searchable(text: $searchText, prompt: "Search by title or number...")
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
/// A single row showing song number, title, preview, and favourite star.
struct SongRow: View {

    let song: Song
    @ObservedObject var songStore: SongStore

    var body: some View {
        HStack(spacing: 14) {
            // Song number badge with songbook colour
            Text("\(song.number)")
                .font(.subheadline.bold().monospacedDigit())
                .foregroundStyle(.white)
                .frame(width: 44, height: 44)
                .background(
                    AmberTheme.songbookColor(song.songbook),
                    in: RoundedRectangle(cornerRadius: 8)
                )

            VStack(alignment: .leading, spacing: 4) {
                Text(song.title)
                    .font(.body.bold())
                    .lineLimit(1)

                if let firstComponent = song.components.first {
                    Text(firstComponent.lines.prefix(2).joined(separator: " "))
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }

            Spacer()

            if songStore.isFavorite(song.id) {
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

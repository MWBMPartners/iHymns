// FavoritesView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - FavoritesView
/// Displays the user's favourited songs with tag filtering,
/// batch selection, and export capabilities.
struct FavoritesView: View {

    @EnvironmentObject var songStore: SongStore
    @State private var searchText: String = ""

    /// Filtered favourites based on search text.
    private var displayedFavorites: [Song] {
        let all = songStore.favoriteSongs
        guard !searchText.isEmpty else { return all }
        let query = searchText.lowercased()
        return all.filter {
            $0.title.lowercased().contains(query) ||
            $0.songbook.lowercased().contains(query) ||
            String($0.number).contains(query)
        }
    }

    var body: some View {
        Group {
            if songStore.favoriteSongs.isEmpty {
                emptyState
            } else {
                favouritesList
            }
        }
        .navigationTitle("Favourites")
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.large)
        #endif
        .searchable(text: $searchText, prompt: "Filter favourites...")
        #if !os(watchOS) && !os(tvOS)
        .toolbar {
            if !songStore.favoriteSongs.isEmpty {
                ToolbarItem(placement: .automatic) {
                    ShareLink(
                        item: exportFavoritesText(),
                        subject: Text("My iHymns Favourites"),
                        message: Text("Shared from iHymns")
                    ) {
                        Label("Export", systemImage: "square.and.arrow.up")
                    }
                }
            }
        }
        #endif
    }

    // MARK: - Empty State

    private var emptyState: some View {
        ContentUnavailableView {
            Label("No Favourites Yet", systemImage: "star")
        } description: {
            Text("Tap the star icon on any song to add it to your favourites for quick access.")
        }
    }

    // MARK: - Favourites List

    private var favouritesList: some View {
        List {
            // Count header
            Section {
                HStack {
                    Text("\(displayedFavorites.count) favourite\(displayedFavorites.count == 1 ? "" : "s")")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    Spacer()
                }
                .listRowBackground(Color.clear)
            }

            ForEach(displayedFavorites, id: \.id) { song in
                NavigationLink(destination: SongDetailView(song: song)) {
                    SongRow(song: song, songStore: songStore)
                }
            }
            .onDelete(perform: removeFromFavourites)
        }
        .listStyle(.plain)
    }

    // MARK: - Remove From Favourites

    private func removeFromFavourites(at offsets: IndexSet) {
        for index in offsets {
            let song = displayedFavorites[index]
            songStore.toggleFavorite(song.id)
        }
    }

    // MARK: - Export

    private func exportFavoritesText() -> String {
        var lines = ["My iHymns Favourites", ""]
        for (index, song) in songStore.favoriteSongs.enumerated() {
            lines.append("\(index + 1). \(song.title) (\(song.songbook) #\(song.number))")
        }
        lines.append("\nExported from iHymns — ihymns.app")
        return lines.joined(separator: "\n")
    }
}

// MARK: - Preview
#if DEBUG
#Preview {
    NavigationStack {
        FavoritesView()
            .environmentObject(SongStore())
    }
}
#endif

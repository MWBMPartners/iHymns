// FavoritesView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - FavoritesView
/// Displays the user's favourited songs in a list.
///
/// The view mirrors the row format used in `SongListView` (number badge, title,
/// lyrics preview, star indicator) so the experience is consistent. Songs can be
/// removed from favourites via a swipe-to-delete gesture or by tapping the star
/// in the detail view.
///
/// An empty state is shown when the user has no favourites, featuring a large
/// star icon and instructional text.
struct FavoritesView: View {

    // MARK: Environment
    /// The shared song store that provides the list of favourited songs and
    /// methods to add/remove favourites.
    @EnvironmentObject var songStore: SongStore

    // MARK: Body
    var body: some View {
        Group {
            if songStore.favoriteSongs.isEmpty {
                // ── Empty State ──────────────────────────────────────────
                // Shown when the user has not yet favourited any songs.
                // Provides a friendly prompt with a star icon.
                emptyState
            } else {
                // ── Favourites List ──────────────────────────────────────
                // A list of all favourited songs with swipe-to-remove support.
                favouritesList
            }
        }
        .navigationTitle("Favourites")
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.large)
        #endif
    }

    // MARK: - Empty State
    /// A centred placeholder displayed when no songs have been favourited.
    /// Uses `ContentUnavailableView` for a consistent system appearance.
    private var emptyState: some View {
        ContentUnavailableView {
            // Primary label with a star icon
            Label("No Favourites Yet", systemImage: "star")
        } description: {
            // Instructional text explaining how to add favourites
            Text("Tap the star icon on any song to add it to your favourites for quick access.")
        } actions: {
            // No action buttons needed; the user navigates elsewhere to favourite songs
        }
    }

    // MARK: - Favourites List
    /// The main list of favourited songs, each navigable to its detail view.
    /// Swipe-to-delete removes the song from favourites without deleting it
    /// from the songbook.
    private var favouritesList: some View {
        List {
            // ── Favourite Song Rows ──────────────────────────────────────
            // Each row uses the same `SongRow` component from SongListView
            // for visual consistency across the app.
            ForEach(songStore.favoriteSongs, id: \.id) { song in
                NavigationLink(destination: SongDetailView(song: song)) {
                    SongRow(song: song, songStore: songStore)
                }
            }
            // Swipe-to-delete gesture removes the song from favourites
            .onDelete(perform: removeFromFavourites)
        }
        .listStyle(.plain)
    }

    // MARK: - Remove From Favourites
    /// Called when the user swipes to delete a row. Removes the corresponding
    /// song from the favourites list in the song store.
    ///
    /// - Parameter offsets: The index set of rows the user swiped to delete.
    private func removeFromFavourites(at offsets: IndexSet) {
        // Map the row offsets to song IDs and toggle each one off
        for index in offsets {
            let song = songStore.favoriteSongs[index]
            songStore.toggleFavorite(songId: song.id)
        }
    }
}

// MARK: - Preview
#if DEBUG
#Preview("With Favourites") {
    NavigationStack {
        FavoritesView()
            .environmentObject(SongStore())
    }
}

#Preview("Empty State") {
    NavigationStack {
        FavoritesView()
            .environmentObject(SongStore())
    }
}
#endif

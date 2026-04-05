// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  SongStore.swift
//  iHymns
//
//  The central data store for the iHymns app. It loads the bundled
//  songs.json file, exposes the decoded song and songbook data to
//  the rest of the app via `@Published` properties, and manages the
//  user's favourites list (persisted to UserDefaults).
//
//  Usage: Create a single instance at app launch and inject it into
//  the SwiftUI view hierarchy with `.environmentObject(songStore)`.
//

import Foundation
import SwiftUI
import Combine

// MARK: - SongStore

/// An `ObservableObject` that acts as the single source of truth for
/// all song data and user preferences (favourites). Views observe
/// its `@Published` properties and automatically re-render when the
/// underlying data changes.
class SongStore: ObservableObject {

    // MARK: - Published Properties

    /// The fully decoded song data including metadata, songbooks, and
    /// the complete catalogue of songs. `nil` until loading completes.
    @Published var songData: SongData?

    /// Indicates whether the store is still loading data from disk.
    /// Views can show a progress indicator while this is `true`.
    @Published var isLoading: Bool = true

    /// An optional error message populated if JSON loading or
    /// decoding fails. Views can display this to aid debugging.
    @Published var errorMessage: String?

    /// The set of song IDs that the user has marked as favourites.
    /// Persisted to UserDefaults so favourites survive app restarts.
    @Published var favorites: Set<String>

    // MARK: - Constants

    /// The UserDefaults key under which the favourites array is stored.
    /// Using a namespaced key avoids collisions with other stored data.
    private static let favoritesKey = "ihymns_favorites"

    // MARK: - Initialiser

    /// Creates the store, loads persisted favourites from UserDefaults,
    /// and immediately begins loading the bundled songs.json file.
    init() {
        // Retrieve the saved favourites array from UserDefaults.
        // If no array has been saved yet, default to an empty array.
        let savedFavorites = UserDefaults.standard.stringArray(
            forKey: SongStore.favoritesKey
        ) ?? []

        // Convert the array to a Set for O(1) membership checks.
        self.favorites = Set(savedFavorites)

        // Kick off the JSON loading process.
        loadSongs()
    }

    // MARK: - Data Loading

    /// Loads and decodes the bundled `songs.json` file from the app's
    /// main bundle. On success, populates `songData` and clears the
    /// loading flag. On failure, sets `errorMessage` for the UI to
    /// display.
    func loadSongs() {
        // Mark the store as loading so the UI can show a spinner.
        isLoading = true

        // Clear any previous error message from a prior attempt.
        errorMessage = nil

        // Locate the songs.json file inside the app bundle.
        guard let url = Bundle.main.url(
            forResource: "songs",
            withExtension: "json"
        ) else {
            // The file was not found in the bundle — this is a build
            // configuration issue and should not happen in production.
            errorMessage = "songs.json not found in app bundle."
            isLoading = false
            return
        }

        do {
            // Read the raw bytes from disk.
            let data = try Data(contentsOf: url)

            // Create a JSONDecoder instance. No custom date or key
            // strategies are needed because the JSON keys already
            // match Swift's camelCase naming convention.
            let decoder = JSONDecoder()

            // Attempt to decode the top-level SongData structure.
            let decoded = try decoder.decode(SongData.self, from: data)

            // Store the decoded data and mark loading as complete.
            songData = decoded
            isLoading = false

        } catch {
            // Capture a human-readable error description for the UI.
            errorMessage = "Failed to load songs: \(error.localizedDescription)"
            isLoading = false
        }
    }

    // MARK: - Song Queries

    /// Returns all songs that belong to the specified songbook,
    /// sorted in ascending order by their song number.
    ///
    /// - Parameter id: The songbook identifier (e.g. "MP", "CH").
    /// - Returns: A sorted array of `Song` values, or an empty array
    ///   if the data has not been loaded yet.
    func songsForSongbook(_ id: String) -> [Song] {
        // Guard against the data not being available yet.
        guard let songs = songData?.songs else {
            return []
        }

        // Filter to only songs whose songbook matches the given id,
        // then sort by song number for a natural reading order.
        return songs
            .filter { $0.songbook == id }  // Keep only matching songbook.
            .sorted { $0.number < $1.number } // Sort by song number ascending.
    }

    /// Finds and returns a single song by its unique identifier.
    ///
    /// - Parameter id: The song identifier (e.g. "CP-0001").
    /// - Returns: The matching `Song`, or `nil` if not found or if
    ///   data has not been loaded yet.
    func song(byId id: String) -> Song? {
        // Use `first(where:)` for a simple linear scan. With ~3,600
        // songs this is fast enough; no index is needed.
        return songData?.songs.first { $0.id == id }
    }

    // MARK: - Favourites Management

    /// Toggles the favourite status of the song with the given ID.
    /// If the song is already a favourite it is removed; otherwise it
    /// is added. The updated set is immediately persisted to
    /// UserDefaults.
    ///
    /// - Parameter songId: The unique identifier of the song to
    ///   toggle (e.g. "MP-0742").
    func toggleFavorite(_ songId: String) {
        // Check whether the song is currently in the favourites set.
        if favorites.contains(songId) {
            // Remove it — the user is "un-favouriting" this song.
            favorites.remove(songId)
        } else {
            // Insert it — the user is marking this song as a favourite.
            favorites.insert(songId)
        }

        // Persist the updated favourites to UserDefaults immediately
        // so changes survive app termination.
        saveFavorites()
    }

    /// Checks whether a song is currently in the user's favourites.
    ///
    /// - Parameter songId: The unique identifier of the song.
    /// - Returns: `true` if the song is a favourite, `false` otherwise.
    func isFavorite(_ songId: String) -> Bool {
        // O(1) lookup in the Set.
        return favorites.contains(songId)
    }

    /// A computed array of `Song` objects corresponding to every song
    /// the user has marked as a favourite. Songs are returned sorted
    /// by title for a consistent display order.
    var favoriteSongs: [Song] {
        // Guard against the data not being available yet.
        guard let songs = songData?.songs else {
            return []
        }

        // Filter the full catalogue to only those whose id appears
        // in the favourites set, then sort alphabetically by title.
        return songs
            .filter { favorites.contains($0.id) } // Keep only favourited songs.
            .sorted { $0.title < $1.title }        // Sort by title A-Z.
    }

    // MARK: - Persistence Helpers

    /// Writes the current favourites set to UserDefaults as a string
    /// array. Called every time the set is modified.
    private func saveFavorites() {
        // Convert the Set to an Array for UserDefaults storage.
        let array = Array(favorites)

        // Store the array under the dedicated key.
        UserDefaults.standard.set(array, forKey: SongStore.favoritesKey)
    }
}

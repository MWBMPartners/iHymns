// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  SongStoreViewModel.swift
//  iHymns
//
//  Enhanced song store that serves as the single source of truth
//  for all app data. Combines bundled JSON data with API-sourced
//  updates, manages favourites, set lists, view history, search
//  history, and user preferences — all persisted to UserDefaults
//  with App Group support for widget data sharing.
//

import Foundation
import SwiftUI
import Combine

// MARK: - SongStore (Enhanced)

/// The central data store and view model for the iHymns app.
/// Manages song data loading (bundle + API), user collections,
/// and all persistent preferences.
@MainActor
class SongStore: ObservableObject {

    // MARK: - Published: Song Data

    /// The fully decoded song catalogue. `nil` until loading completes.
    @Published var songData: SongData?

    /// Whether the store is currently loading data.
    @Published var isLoading: Bool = true

    /// Error message if data loading fails.
    @Published var errorMessage: String?

    // MARK: - Published: User Collections

    /// Song IDs the user has marked as favourites.
    @Published var favorites: Set<String>

    /// The user's worship set lists.
    @Published var setLists: [SetList]

    /// Recently viewed songs (most recent first, max 20).
    @Published var viewHistory: [ViewHistoryEntry]

    /// Recent search queries (most recent first, max 10).
    @Published var searchHistory: [SearchHistoryEntry]

    // MARK: - Published: Tags

    /// Song-to-tag assignments for favourites categorisation.
    @Published var tagAssignments: FavouriteTagAssignment

    /// Custom tags created by the user (in addition to the 14 predefined).
    @Published var customTags: [FavouriteTag]

    // MARK: - Published: Preferences

    /// User display and behaviour preferences.
    @Published var preferences: UserPreferences

    // MARK: - Published: API State

    /// Whether the app is currently fetching data from the API.
    @Published var isSyncing: Bool = false

    /// Timestamp of the last successful API sync.
    @Published var lastSyncDate: Date?

    // MARK: - Constants

    private static let favoritesKey = "ihymns_favorites"
    private static let setListsKey = "ihymns_setlists"
    private static let viewHistoryKey = "ihymns_history"
    private static let searchHistoryKey = "ihymns_search_history"
    private static let preferencesKey = "ihymns_preferences"
    private static let lastSyncKey = "ihymns_last_sync"
    private static let ownerIdKey = "ihymns_owner_id"
    private static let tagAssignmentsKey = "ihymns_tag_assignments"
    private static let customTagsKey = "ihymns_custom_tags"

    /// App Group identifier for widget data sharing.
    private static let appGroupId = "group.com.mwbm.ihymns"

    /// Maximum number of history entries to retain.
    private static let maxViewHistory = 20
    private static let maxSearchHistory = 10

    // MARK: - Private

    /// The API client for remote operations.
    private let apiClient = APIClient()

    /// The persistent owner ID for set list sharing.
    let ownerId: String

    // MARK: - Song Index

    /// Dictionary-based index for O(1) song lookup by ID.
    private var songIndex: [String: Song] = [:]

    /// Dictionary-based index for songs by songbook.
    private var songsBySongbook: [String: [Song]] = [:]

    // MARK: - Initialiser

    init() {
        // Load owner ID or generate a new one
        if let savedOwnerId = UserDefaults.standard.string(forKey: SongStore.ownerIdKey) {
            self.ownerId = savedOwnerId
        } else {
            let newOwnerId = UUID().uuidString
            UserDefaults.standard.set(newOwnerId, forKey: SongStore.ownerIdKey)
            self.ownerId = newOwnerId
        }

        // Load persisted favourites
        let savedFavorites = UserDefaults.standard.stringArray(
            forKey: SongStore.favoritesKey
        ) ?? []
        self.favorites = Set(savedFavorites)

        // Load persisted set lists
        if let setListData = UserDefaults.standard.data(forKey: SongStore.setListsKey),
           let decoded = try? JSONDecoder().decode([SetList].self, from: setListData) {
            self.setLists = decoded
        } else {
            self.setLists = []
        }

        // Load view history
        if let historyData = UserDefaults.standard.data(forKey: SongStore.viewHistoryKey),
           let decoded = try? JSONDecoder().decode([ViewHistoryEntry].self, from: historyData) {
            self.viewHistory = decoded
        } else {
            self.viewHistory = []
        }

        // Load search history
        if let searchData = UserDefaults.standard.data(forKey: SongStore.searchHistoryKey),
           let decoded = try? JSONDecoder().decode([SearchHistoryEntry].self, from: searchData) {
            self.searchHistory = decoded
        } else {
            self.searchHistory = []
        }

        // Load tag assignments
        if let tagData = UserDefaults.standard.data(forKey: SongStore.tagAssignmentsKey),
           let decoded = try? JSONDecoder().decode(FavouriteTagAssignment.self, from: tagData) {
            self.tagAssignments = decoded
        } else {
            self.tagAssignments = FavouriteTagAssignment()
        }

        // Load custom tags
        if let customTagData = UserDefaults.standard.data(forKey: SongStore.customTagsKey),
           let decoded = try? JSONDecoder().decode([FavouriteTag].self, from: customTagData) {
            self.customTags = decoded
        } else {
            self.customTags = []
        }

        // Load preferences
        if let prefsData = UserDefaults.standard.data(forKey: SongStore.preferencesKey),
           let decoded = try? JSONDecoder().decode(UserPreferences.self, from: prefsData) {
            self.preferences = decoded
        } else {
            self.preferences = UserPreferences()
        }

        // Load last sync date
        self.lastSyncDate = UserDefaults.standard.object(forKey: SongStore.lastSyncKey) as? Date

        // Load songs from bundle
        loadSongs()
    }

    // MARK: - Data Loading

    /// Loads the bundled songs.json and builds the search index.
    func loadSongs() {
        isLoading = true
        errorMessage = nil

        guard let url = Bundle.main.url(forResource: "songs", withExtension: "json") else {
            errorMessage = "songs.json not found in app bundle."
            isLoading = false
            return
        }

        do {
            let data = try Data(contentsOf: url)
            let decoded = try JSONDecoder().decode(SongData.self, from: data)
            songData = decoded
            buildSongIndex()
            syncWidgetData()
            isLoading = false
        } catch {
            errorMessage = "Failed to load songs: \(error.localizedDescription)"
            isLoading = false
        }
    }

    /// Builds dictionary-based indices for fast song lookups.
    private func buildSongIndex() {
        guard let songs = songData?.songs else { return }

        songIndex = Dictionary(uniqueKeysWithValues: songs.map { ($0.id, $0) })

        songsBySongbook = Dictionary(grouping: songs, by: { $0.songbook })
        for key in songsBySongbook.keys {
            songsBySongbook[key]?.sort { $0.number < $1.number }
        }
    }

    // MARK: - API Sync

    /// Attempts to refresh song data from the API.
    /// Falls back gracefully to bundled data if offline.
    func syncFromAPI() async {
        guard preferences.autoUpdateSongs else { return }
        isSyncing = true

        do {
            if let updatedData = try await apiClient.fetchSongsJSON() {
                songData = updatedData
                buildSongIndex()
                syncWidgetData()
                lastSyncDate = Date()
                UserDefaults.standard.set(lastSyncDate, forKey: SongStore.lastSyncKey)
            }
        } catch {
            // Sync failure is non-critical — bundled data is the fallback
        }

        isSyncing = false
    }

    /// Performs an API search query.
    func searchFromAPI(query: String, songbook: String? = nil) async -> [Song] {
        do {
            let response = try await apiClient.searchSongs(
                query: query,
                songbook: songbook
            )
            return response.results
        } catch {
            return []
        }
    }

    /// Fetches a random song from the API.
    func fetchRandomSong(songbook: String? = nil) async -> Song? {
        do {
            let response = try await apiClient.fetchRandomSong(songbook: songbook)
            return response.song
        } catch {
            // Fallback: pick a random song from local data
            guard let songs = songData?.songs, !songs.isEmpty else { return nil }
            return songs.randomElement()
        }
    }

    // MARK: - Song Queries (Indexed)

    /// Returns a song by ID using the O(1) dictionary index.
    func song(byId id: String) -> Song? {
        return songIndex[id]
    }

    /// Returns all songs for a songbook, pre-sorted by number.
    func songsForSongbook(_ id: String) -> [Song] {
        return songsBySongbook[id] ?? []
    }

    /// Returns songs matching a local search query using weighted fuzzy scoring.
    func searchSongsLocally(query: String) -> [Song] {
        guard !query.isEmpty, let songs = songData?.songs else { return [] }

        return FuzzySearchEngine.search(
            query: query,
            in: songs,
            includeLyrics: preferences.searchLyrics
        )
    }

    /// Searches by song number within a specific songbook.
    /// Matches songs whose number starts with the given prefix.
    func searchByNumber(_ numberPrefix: String, songbook: String) -> [Song] {
        let songs = songsForSongbook(songbook)
        guard !numberPrefix.isEmpty else { return songs }
        return songs.filter { String($0.number).hasPrefix(numberPrefix) }
    }

    /// Returns songs for a songbook sorted by the given mode.
    func songsForSongbook(_ id: String, sortedBy sort: SongSortMode) -> [Song] {
        let songs = songsForSongbook(id)
        switch sort {
        case .number: return songs
        case .title:  return songs.sorted { $0.title.localizedCaseInsensitiveCompare($1.title) == .orderedAscending }
        }
    }

    /// Sort modes for song lists within a songbook.
    enum SongSortMode: String, CaseIterable {
        case number = "Number"
        case title = "A–Z"
    }

    /// Returns the Song of the Day with calendar theme awareness.
    var songOfTheDay: Song? {
        guard let songs = songData?.songs, !songs.isEmpty else { return nil }
        return SongOfTheDayEngine.selectSong(from: songs, for: Date())?.song
    }

    /// Returns the current calendar theme name, if any (e.g., "Christmas", "Easter").
    var songOfTheDayTheme: String? {
        guard let songs = songData?.songs, !songs.isEmpty else { return nil }
        return SongOfTheDayEngine.selectSong(from: songs, for: Date())?.themeName
    }

    // MARK: - Favourites Management

    /// Toggles the favourite status of a song.
    func toggleFavorite(_ songId: String) {
        if favorites.contains(songId) {
            favorites.remove(songId)
        } else {
            favorites.insert(songId)
        }
        HapticManager.lightImpact()
        saveFavorites()
        syncWidgetData()
    }

    /// Checks if a song is favourited.
    func isFavorite(_ songId: String) -> Bool {
        return favorites.contains(songId)
    }

    /// Returns all favourite songs sorted by title.
    var favoriteSongs: [Song] {
        guard let songs = songData?.songs else { return [] }
        return songs
            .filter { favorites.contains($0.id) }
            .sorted { $0.title < $1.title }
    }

    // MARK: - Set List Management

    /// Creates a new set list with the given name.
    @discardableResult
    func createSetList(name: String) -> SetList {
        let setList = SetList(name: name)
        setLists.append(setList)
        saveSetLists()
        return setList
    }

    /// Deletes set lists at the specified indices.
    func deleteSetLists(at offsets: IndexSet) {
        setLists.remove(atOffsets: offsets)
        saveSetLists()
    }

    /// Adds a song to a set list.
    func addSongToSetList(_ songId: String, setListId: UUID) {
        guard let index = setLists.firstIndex(where: { $0.id == setListId }) else { return }
        setLists[index].addSong(songId)
        saveSetLists()
    }

    /// Removes a song from a set list.
    func removeSongFromSetList(at offsets: IndexSet, setListId: UUID) {
        guard let index = setLists.firstIndex(where: { $0.id == setListId }) else { return }
        for offset in offsets.sorted().reversed() {
            setLists[index].removeSong(at: offset)
        }
        saveSetLists()
    }

    /// Reorders songs within a set list.
    func moveSetListSongs(from source: IndexSet, to destination: Int, setListId: UUID) {
        guard let index = setLists.firstIndex(where: { $0.id == setListId }) else { return }
        setLists[index].moveSong(from: source, to: destination)
        saveSetLists()
    }

    /// Shares a set list via the API and returns the share URL.
    func shareSetList(_ setList: SetList) async -> String? {
        let shared = SharedSetList(
            name: setList.name,
            songs: setList.songIds,
            owner: ownerId,
            id: setList.shareId
        )

        do {
            let response = try await apiClient.shareSetList(shared)
            // Update local set list with share ID
            if let index = setLists.firstIndex(where: { $0.id == setList.id }) {
                setLists[index].shareId = response.id
                saveSetLists()
            }
            return "https://ihymns.app\(response.url)"
        } catch {
            return nil
        }
    }

    /// Renames a set list.
    func renameSetList(_ setListId: UUID, to newName: String) {
        guard let index = setLists.firstIndex(where: { $0.id == setListId }) else { return }
        setLists[index].name = newName
        setLists[index].updatedAt = Date()
        saveSetLists()
    }

    /// Duplicates a set list with a new name.
    @discardableResult
    func duplicateSetList(_ setListId: UUID) -> SetList? {
        guard let original = setLists.first(where: { $0.id == setListId }) else { return nil }
        let copy = SetList(name: "\(original.name) (Copy)", songIds: original.songIds)
        setLists.append(copy)
        saveSetLists()
        return copy
    }

    // MARK: - Tag Management

    /// All available tags (predefined + custom).
    var allTags: [FavouriteTag] {
        FavouriteTag.predefined + customTags
    }

    /// Adds a tag to a favourite song.
    func addTag(_ tagName: String, to songId: String) {
        tagAssignments.addTag(tagName, to: songId)
        saveTagAssignments()
    }

    /// Removes a tag from a song.
    func removeTag(_ tagName: String, from songId: String) {
        tagAssignments.removeTag(tagName, from: songId)
        saveTagAssignments()
    }

    /// Returns tags assigned to a song.
    func tagsForSong(_ songId: String) -> [String] {
        tagAssignments.tags(for: songId)
    }

    /// Returns favourite songs filtered by tag.
    func favoriteSongs(withTag tag: String) -> [Song] {
        let songIds = tagAssignments.songIds(for: tag)
        return favoriteSongs.filter { songIds.contains($0.id) }
    }

    /// Creates a new custom tag.
    func createCustomTag(_ name: String) {
        let trimmed = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return }
        guard !allTags.contains(where: { $0.name.lowercased() == trimmed.lowercased() }) else { return }
        customTags.append(FavouriteTag(name: trimmed, isCustom: true))
        saveCustomTags()
    }

    /// Deletes a custom tag and removes all its assignments.
    func deleteCustomTag(_ tagId: UUID) {
        guard let tag = customTags.first(where: { $0.id == tagId }) else { return }
        // Remove all assignments for this tag
        for songId in tagAssignments.songIds(for: tag.name) {
            tagAssignments.removeTag(tag.name, from: songId)
        }
        customTags.removeAll { $0.id == tagId }
        saveCustomTags()
        saveTagAssignments()
    }

    /// Batch removes multiple songs from favourites.
    func removeFavorites(_ songIds: Set<String>) {
        for songId in songIds {
            favorites.remove(songId)
        }
        saveFavorites()
        syncWidgetData()
    }

    /// Batch adds a tag to multiple songs.
    func batchAddTag(_ tagName: String, to songIds: Set<String>) {
        for songId in songIds {
            tagAssignments.addTag(tagName, to: songId)
        }
        saveTagAssignments()
    }

    // MARK: - Backup / Restore

    /// Exports all user data as a JSON-encodable backup.
    func exportBackup() -> UserDataBackup {
        UserDataBackup(
            version: UserDataBackup.currentVersion,
            exportDate: Date(),
            favorites: Array(favorites),
            setLists: setLists,
            tags: tagAssignments,
            customTags: customTags,
            preferences: preferences
        )
    }

    /// Exports backup as JSON Data.
    func exportBackupJSON() -> Data? {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
        encoder.dateEncodingStrategy = .iso8601
        return try? encoder.encode(exportBackup())
    }

    /// Imports user data from a backup, merging with existing data.
    func importBackup(_ backup: UserDataBackup) {
        // Merge favourites
        favorites = favorites.union(Set(backup.favorites))
        saveFavorites()

        // Merge set lists (skip duplicates by name)
        let existingNames = Set(setLists.map(\.name))
        for setList in backup.setLists where !existingNames.contains(setList.name) {
            setLists.append(setList)
        }
        saveSetLists()

        // Merge tags
        for (songId, tags) in backup.tags.songTags {
            for tag in tags {
                tagAssignments.addTag(tag, to: songId)
            }
        }
        saveTagAssignments()

        // Merge custom tags
        let existingTagNames = Set(customTags.map { $0.name.lowercased() })
        for tag in backup.customTags where !existingTagNames.contains(tag.name.lowercased()) {
            customTags.append(tag)
        }
        saveCustomTags()

        syncWidgetData()
        HapticManager.success()
    }

    /// Imports backup from JSON Data.
    func importBackupFromJSON(_ data: Data) -> Bool {
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
        guard let backup = try? decoder.decode(UserDataBackup.self, from: data) else { return false }
        importBackup(backup)
        return true
    }

    // MARK: - View History

    /// Records a song view in the history.
    func recordSongView(_ song: Song) {
        // Remove existing entry for this song (will be re-added at top)
        viewHistory.removeAll { $0.songId == song.id }

        // Add new entry at the beginning
        let entry = ViewHistoryEntry(
            songId: song.id,
            songTitle: song.title,
            songbook: song.songbook
        )
        viewHistory.insert(entry, at: 0)

        // Trim to maximum size
        if viewHistory.count > SongStore.maxViewHistory {
            viewHistory = Array(viewHistory.prefix(SongStore.maxViewHistory))
        }

        saveViewHistory()
    }

    /// Clears all view history.
    func clearViewHistory() {
        viewHistory.removeAll()
        saveViewHistory()
    }

    // MARK: - Search History

    /// Records a search query in the history.
    func recordSearch(_ query: String) {
        let trimmed = query.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return }

        // Remove duplicate
        searchHistory.removeAll { $0.query.lowercased() == trimmed.lowercased() }

        // Add at beginning
        searchHistory.insert(SearchHistoryEntry(query: trimmed), at: 0)

        // Trim to max
        if searchHistory.count > SongStore.maxSearchHistory {
            searchHistory = Array(searchHistory.prefix(SongStore.maxSearchHistory))
        }

        saveSearchHistory()
    }

    /// Clears all search history.
    func clearSearchHistory() {
        searchHistory.removeAll()
        saveSearchHistory()
    }

    // MARK: - Preferences

    /// Saves updated preferences and applies them.
    func updatePreferences(_ newPrefs: UserPreferences) {
        preferences = newPrefs
        savePreferences()
    }

    // MARK: - Persistence

    private func saveFavorites() {
        UserDefaults.standard.set(Array(favorites), forKey: SongStore.favoritesKey)
    }

    private func saveSetLists() {
        if let data = try? JSONEncoder().encode(setLists) {
            UserDefaults.standard.set(data, forKey: SongStore.setListsKey)
        }
    }

    private func saveViewHistory() {
        if let data = try? JSONEncoder().encode(viewHistory) {
            UserDefaults.standard.set(data, forKey: SongStore.viewHistoryKey)
        }
    }

    private func saveSearchHistory() {
        if let data = try? JSONEncoder().encode(searchHistory) {
            UserDefaults.standard.set(data, forKey: SongStore.searchHistoryKey)
        }
    }

    private func saveTagAssignments() {
        if let data = try? JSONEncoder().encode(tagAssignments) {
            UserDefaults.standard.set(data, forKey: SongStore.tagAssignmentsKey)
        }
    }

    private func saveCustomTags() {
        if let data = try? JSONEncoder().encode(customTags) {
            UserDefaults.standard.set(data, forKey: SongStore.customTagsKey)
        }
    }

    private func savePreferences() {
        if let data = try? JSONEncoder().encode(preferences) {
            UserDefaults.standard.set(data, forKey: SongStore.preferencesKey)
        }
    }

    // MARK: - Widget Data Sync

    /// Syncs essential data to the App Group UserDefaults for widget access.
    private func syncWidgetData() {
        guard let sharedDefaults = UserDefaults(suiteName: SongStore.appGroupId) else { return }

        // Write favourites for the widget
        sharedDefaults.set(Array(favorites), forKey: SongStore.favoritesKey)

        // Write a compact song catalogue for widgets
        if let songs = songData?.songs {
            let widgetSongs = songs.map { song in
                [
                    "id": song.id,
                    "title": song.title,
                    "songbook": song.songbook,
                    "preview": song.lyricsPreview,
                    "writers": song.writersDisplay
                ]
            }
            if let data = try? JSONSerialization.data(withJSONObject: widgetSongs) {
                sharedDefaults.set(data, forKey: "ihymns_widget_song_catalogue")
            }
        }
    }
}

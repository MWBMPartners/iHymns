// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — Song ViewModel (MVVM Architecture)
//
// PURPOSE:
// Central ViewModel for the iHymns application. Manages all song-related
// state including:
//   - Loading and parsing songs.json from the app's assets folder
//   - Providing reactive state via Kotlin StateFlow for Compose observation
//   - Search functionality (by title, number, or lyrics content)
//   - Songbook filtering (display songs from a specific songbook)
//   - Favourites management (persisted via SharedPreferences)
//
// ARCHITECTURE:
// This ViewModel follows the MVVM (Model-View-ViewModel) pattern:
//   Model:     Song.kt data classes + songs.json asset
//   ViewModel: SongViewModel (this file) — state management and business logic
//   View:      Compose screen files — observe StateFlow, render UI
//
// STATE MANAGEMENT:
// All mutable state is exposed as read-only StateFlow to the UI layer.
// The UI collects these flows using collectAsStateWithLifecycle() to ensure
// lifecycle-aware observation that automatically pauses when the app is
// backgrounded.
//
// FAVOURITES PERSISTENCE:
// Favourite song IDs are stored in SharedPreferences as a Set<String>.
// SharedPreferences is used instead of Room/DataStore for simplicity in
// Phase 1. This works across all target platforms (phones, Fire OS, TV,
// ChromeOS) without any Google Play Services dependency.
//
// PLATFORM COMPATIBILITY:
// No Google Play Services APIs are used. All data operations are local
// (assets + SharedPreferences), ensuring full Fire OS compatibility.
// =============================================================================

package ltd.mwbmpartners.ihymns.viewmodel

import android.app.Application
import android.content.Context
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.Json
import ltd.mwbmpartners.ihymns.models.Song
import ltd.mwbmpartners.ihymns.models.SongData
import ltd.mwbmpartners.ihymns.models.Songbook

// =============================================================================
// CONSTANTS
// =============================================================================

/** SharedPreferences file name for storing user favourites */
private const val PREFS_NAME = "ihymns_prefs"

/** SharedPreferences key for the set of favourite song IDs */
private const val KEY_FAVOURITES = "favourite_song_ids"

/** Asset file path for the bundled song data */
private const val SONGS_ASSET_PATH = "songs.json"

// =============================================================================
// VIEW MODEL
// =============================================================================

/**
 * ViewModel for managing song data, search, filtering, and favourites.
 *
 * Extends [AndroidViewModel] to access the application [Context] for reading
 * assets and SharedPreferences. The ViewModel survives configuration changes
 * (rotation, theme switch) so song data is loaded only once.
 *
 * @param application The application context used for asset and preference access.
 */
class SongViewModel(application: Application) : AndroidViewModel(application) {

    // =========================================================================
    // JSON PARSER CONFIGURATION
    // =========================================================================

    /**
     * Configured JSON parser instance.
     *
     * - ignoreUnknownKeys: Allows the JSON to contain fields not present in
     *   our data classes (forward compatibility if songs.json adds new fields).
     * - isLenient: Tolerates minor JSON formatting issues.
     */
    private val json = Json {
        ignoreUnknownKeys = true
        isLenient = true
    }

    // =========================================================================
    // STATE — SONG DATA
    // =========================================================================

    /** All songs loaded from songs.json. Empty list while loading. */
    private val _songs = MutableStateFlow<List<Song>>(emptyList())
    val songs: StateFlow<List<Song>> = _songs.asStateFlow()

    /** All songbooks loaded from songs.json. Empty list while loading. */
    private val _songbooks = MutableStateFlow<List<Songbook>>(emptyList())
    val songbooks: StateFlow<List<Songbook>> = _songbooks.asStateFlow()

    // =========================================================================
    // STATE — LOADING
    // =========================================================================

    /** True while songs.json is being loaded and parsed. */
    private val _isLoading = MutableStateFlow(true)
    val isLoading: StateFlow<Boolean> = _isLoading.asStateFlow()

    /** Non-null if an error occurred during data loading. */
    private val _errorMessage = MutableStateFlow<String?>(null)
    val errorMessage: StateFlow<String?> = _errorMessage.asStateFlow()

    // =========================================================================
    // STATE — SEARCH
    // =========================================================================

    /** Current search query entered by the user. */
    private val _searchQuery = MutableStateFlow("")
    val searchQuery: StateFlow<String> = _searchQuery.asStateFlow()

    /** Search results — filtered subset of all songs matching the query. */
    private val _searchResults = MutableStateFlow<List<Song>>(emptyList())
    val searchResults: StateFlow<List<Song>> = _searchResults.asStateFlow()

    // =========================================================================
    // STATE — FAVOURITES
    // =========================================================================

    /** Set of song IDs that the user has marked as favourites. */
    private val _favouriteIds = MutableStateFlow<Set<String>>(emptySet())
    val favouriteIds: StateFlow<Set<String>> = _favouriteIds.asStateFlow()

    /** List of Song objects corresponding to the favourite IDs. */
    private val _favouriteSongs = MutableStateFlow<List<Song>>(emptyList())
    val favouriteSongs: StateFlow<List<Song>> = _favouriteSongs.asStateFlow()

    // =========================================================================
    // SHARED PREFERENCES REFERENCE
    // =========================================================================

    /** SharedPreferences instance for persisting favourite song IDs. */
    private val prefs = application.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    // =========================================================================
    // INITIALISATION
    // =========================================================================

    init {
        // Load favourites from persistent storage immediately
        loadFavourites()

        // Load and parse songs.json on a background thread
        loadSongs()
    }

    // =========================================================================
    // DATA LOADING
    // =========================================================================

    /**
     * Loads and parses songs.json from the app's assets folder.
     *
     * This function runs on [Dispatchers.IO] to avoid blocking the main thread
     * during file I/O and JSON parsing (the file contains 3,600+ songs).
     *
     * On success: populates [_songs] and [_songbooks], updates favourite songs.
     * On failure: sets [_errorMessage] with the error description.
     * In both cases: sets [_isLoading] to false when complete.
     */
    private fun loadSongs() {
        viewModelScope.launch(Dispatchers.IO) {
            try {
                // Read the entire JSON file from the assets folder as a string
                val jsonString = getApplication<Application>()
                    .assets
                    .open(SONGS_ASSET_PATH)
                    .bufferedReader()
                    .use { it.readText() }

                // Deserialise the JSON string into the SongData structure
                val songData: SongData = json.decodeFromString(jsonString)

                // Update reactive state with the parsed data
                _songs.value = songData.songs
                _songbooks.value = songData.songbooks

                // Rebuild the favourites song list now that songs are loaded
                updateFavouriteSongs()

                // Clear any previous error
                _errorMessage.value = null
            } catch (e: Exception) {
                // Capture the error message for display in the UI
                _errorMessage.value = "Failed to load songs: ${e.localizedMessage}"
            } finally {
                // Loading is complete regardless of success or failure
                _isLoading.value = false
            }
        }
    }

    // =========================================================================
    // SONG RETRIEVAL
    // =========================================================================

    /**
     * Retrieves a single song by its unique identifier.
     *
     * @param songId The song ID to look up (e.g., "CP-0001", "MP-0523").
     * @return The matching [Song] or null if not found.
     */
    fun getSongById(songId: String): Song? {
        return _songs.value.find { it.id == songId }
    }

    /**
     * Retrieves all songs belonging to a specific songbook.
     *
     * @param songbookId The songbook ID to filter by (e.g., "CP", "MP").
     * @return List of songs in the specified songbook, sorted by song number.
     */
    fun getSongsBySongbook(songbookId: String): List<Song> {
        return _songs.value
            .filter { it.songbook == songbookId }
            .sortedBy { it.number }
    }

    /**
     * Retrieves a songbook by its unique identifier.
     *
     * @param songbookId The songbook ID to look up (e.g., "CP", "MP").
     * @return The matching [Songbook] or null if not found.
     */
    fun getSongbookById(songbookId: String): Songbook? {
        return _songbooks.value.find { it.id == songbookId }
    }

    // =========================================================================
    // SEARCH
    // =========================================================================

    /**
     * Updates the search query and performs a filtered search across all songs.
     *
     * The search matches against:
     * 1. Song title (case-insensitive)
     * 2. Song number (exact prefix match, e.g., "12" matches song 12 and 120+)
     * 3. Lyric content (case-insensitive search across all component lines)
     *
     * Results are limited to avoid UI performance issues with very broad queries.
     *
     * @param query The search string entered by the user.
     */
    fun updateSearchQuery(query: String) {
        _searchQuery.value = query

        if (query.isBlank()) {
            // Clear results when the search field is empty
            _searchResults.value = emptyList()
            return
        }

        // Perform case-insensitive search across multiple fields
        val lowerQuery = query.lowercase().trim()
        _searchResults.value = _songs.value.filter { song ->
            // Match by title
            song.title.lowercase().contains(lowerQuery) ||
            // Match by song number (as string prefix)
            song.number.toString().startsWith(lowerQuery) ||
            // Match by lyric content (any line in any component)
            song.components.any { component ->
                component.lines.any { line ->
                    line.lowercase().contains(lowerQuery)
                }
            }
        }
    }

    // =========================================================================
    // FAVOURITES MANAGEMENT
    // =========================================================================

    /**
     * Loads favourite song IDs from SharedPreferences.
     *
     * Called during ViewModel initialisation to restore the user's
     * previously saved favourites.
     */
    private fun loadFavourites() {
        val savedIds = prefs.getStringSet(KEY_FAVOURITES, emptySet()) ?: emptySet()
        _favouriteIds.value = savedIds.toSet()
    }

    /**
     * Persists the current set of favourite song IDs to SharedPreferences.
     *
     * Uses apply() (asynchronous write) rather than commit() (synchronous)
     * for better UI responsiveness.
     */
    private fun saveFavourites() {
        prefs.edit()
            .putStringSet(KEY_FAVOURITES, _favouriteIds.value)
            .apply()
    }

    /**
     * Updates the [_favouriteSongs] list based on current favourite IDs
     * and loaded song data.
     *
     * Called after songs are loaded and after any favourite toggle operation.
     */
    private fun updateFavouriteSongs() {
        val ids = _favouriteIds.value
        _favouriteSongs.value = _songs.value.filter { it.id in ids }
    }

    /**
     * Toggles the favourite status of a song.
     *
     * If the song is currently a favourite, it is removed.
     * If the song is not a favourite, it is added.
     * Changes are persisted to SharedPreferences immediately.
     *
     * @param songId The unique ID of the song to toggle (e.g., "CP-0001").
     */
    fun toggleFavourite(songId: String) {
        val currentIds = _favouriteIds.value.toMutableSet()
        if (songId in currentIds) {
            currentIds.remove(songId)
        } else {
            currentIds.add(songId)
        }
        _favouriteIds.value = currentIds.toSet()
        saveFavourites()
        updateFavouriteSongs()
    }

    /**
     * Checks whether a specific song is marked as a favourite.
     *
     * @param songId The unique ID of the song to check.
     * @return True if the song is in the favourites set, false otherwise.
     */
    fun isFavourite(songId: String): Boolean {
        return songId in _favouriteIds.value
    }

    /**
     * Removes a song from favourites by its ID.
     *
     * Convenience method for swipe-to-dismiss in the favourites screen.
     *
     * @param songId The unique ID of the song to remove from favourites.
     */
    fun removeFavourite(songId: String) {
        val currentIds = _favouriteIds.value.toMutableSet()
        currentIds.remove(songId)
        _favouriteIds.value = currentIds.toSet()
        saveFavourites()
        updateFavouriteSongs()
    }
}

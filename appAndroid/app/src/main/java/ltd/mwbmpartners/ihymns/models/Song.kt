// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

// =============================================================================
// iHymns — Song Data Models (Kotlinx Serialization)
//
// PURPOSE:
// Defines the Kotlin data classes that model the songs.json data structure.
// These classes are annotated with @Serializable for compile-time JSON
// serialisation/deserialisation via kotlinx.serialization (no reflection).
//
// DATA STRUCTURE (mirrors songs.json):
//
//   SongData (root object)
//   ├── meta: SongMeta
//   │     ├── generatedAt: String
//   │     ├── generatorVersion: String
//   │     ├── totalSongs: Int
//   │     └── totalSongbooks: Int
//   ├── songbooks: List<Songbook>
//   │     ├── id: String          (e.g., "CP", "JP", "MP", "SDAH", "CH")
//   │     ├── name: String        (e.g., "Carol Praise", "Junior Praise")
//   │     └── songCount: Int
//   └── songs: List<Song>
//         ├── id: String          (e.g., "CP-0001")
//         ├── number: Int         (song number within songbook)
//         ├── title: String
//         ├── songbook: String    (songbook ID reference)
//         ├── songbookName: String
//         ├── writers: List<String>
//         ├── composers: List<String>
//         ├── copyright: String
//         ├── ccli: String
//         ├── hasAudio: Boolean
//         ├── hasSheetMusic: Boolean
//         └── components: List<SongComponent>
//               ├── type: String  ("verse", "chorus", "bridge", etc.)
//               ├── number: Int?  (verse number, null for chorus/bridge)
//               └── lines: List<String>
//
// SONGBOOKS INCLUDED:
//   CP   — Carol Praise (243 songs)
//   JP   — Junior Praise (617 songs)
//   MP   — Mission Praise (1,355 songs)
//   SDAH — Seventh-day Adventist Hymnal (695 songs)
//   CH   — The Church Hymnal (702 songs)
//   Total: 3,612 songs
// =============================================================================

package ltd.mwbmpartners.ihymns.models

import kotlinx.serialization.Serializable

// =============================================================================
// ROOT DATA OBJECT
// =============================================================================

/**
 * Root data structure representing the entire songs.json file.
 *
 * This is the top-level object deserialised from the bundled JSON asset.
 * It contains metadata about the data file, the list of available songbooks,
 * and the complete catalogue of songs with their lyrics.
 *
 * @property meta Metadata about when and how the JSON was generated.
 * @property songbooks List of songbook definitions (id, name, song count).
 * @property songs Complete list of all songs across all songbooks.
 */
@Serializable
data class SongData(
    val meta: SongMeta,
    val songbooks: List<Songbook>,
    val songs: List<Song>
)

// =============================================================================
// METADATA
// =============================================================================

/**
 * Metadata about the songs.json data file.
 *
 * Provides information about when the data was generated and summary
 * statistics. Useful for debugging, cache invalidation, and display
 * in the app's "about" or help screens.
 *
 * @property generatedAt ISO 8601 timestamp of when the JSON was generated.
 * @property generatorVersion Version of the tool that generated the JSON.
 * @property totalSongs Total number of songs across all songbooks.
 * @property totalSongbooks Total number of songbooks in the data.
 */
@Serializable
data class SongMeta(
    val generatedAt: String,
    val generatorVersion: String,
    val totalSongs: Int,
    val totalSongbooks: Int
)

// =============================================================================
// SONGBOOK
// =============================================================================

/**
 * Represents a songbook (hymnal) containing a collection of songs.
 *
 * Each songbook has a short identifier used as a prefix in song IDs
 * (e.g., "CP" for Carol Praise, so song IDs are "CP-0001", "CP-0002", etc.)
 *
 * @property id Short unique identifier for the songbook (e.g., "CP", "MP").
 * @property name Full display name of the songbook (e.g., "Carol Praise").
 * @property songCount Number of songs contained in this songbook.
 */
@Serializable
data class Songbook(
    val id: String,
    val name: String,
    val songCount: Int
)

// =============================================================================
// SONG
// =============================================================================

/**
 * Represents a single song (hymn) with its metadata and lyrics.
 *
 * Each song belongs to exactly one songbook and contains structured
 * lyrics broken into components (verses, choruses, bridges, etc.).
 *
 * @property id Unique song identifier in the format "{songbook}-{number}"
 *              (e.g., "CP-0001", "MP-0523").
 * @property number Song number within its songbook.
 * @property title Display title of the song.
 * @property songbook Songbook ID this song belongs to (foreign key to [Songbook.id]).
 * @property songbookName Full display name of the parent songbook.
 * @property writers List of lyricist/writer names.
 * @property composers List of composer/musician names.
 * @property copyright Copyright notice for the song's content.
 * @property ccli CCLI licence number (empty string if not available).
 * @property hasAudio Whether an audio recording is available for this song.
 * @property hasSheetMusic Whether sheet music is available for this song.
 * @property components Ordered list of lyric sections (verses, choruses, etc.).
 */
@Serializable
data class Song(
    val id: String,
    val number: Int,
    val title: String,
    val songbook: String,
    val songbookName: String,
    val writers: List<String>,
    val composers: List<String>,
    val copyright: String,
    val ccli: String,
    val hasAudio: Boolean,
    val hasSheetMusic: Boolean,
    val components: List<SongComponent>
)

// =============================================================================
// SONG COMPONENT (Verse, Chorus, Bridge, etc.)
// =============================================================================

/**
 * Represents a structural component of a song's lyrics.
 *
 * Songs are divided into ordered components, each with a type (verse, chorus,
 * bridge, etc.), an optional number (for verses), and the actual lyric lines.
 *
 * @property type Component type label: "verse", "chorus", "bridge", "intro",
 *               "outro", "pre-chorus", "tag", etc.
 * @property number Component number within its type (e.g., verse 1, verse 2).
 *                   May be null for component types that are not numbered
 *                   (e.g., a single chorus that repeats).
 * @property lines Ordered list of lyric lines for this component. Each string
 *                 represents one line of text as it should be displayed.
 */
@Serializable
data class SongComponent(
    val type: String,
    val number: Int? = null,
    val lines: List<String>
)

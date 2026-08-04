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
 * @property subtitle Optional song subtitle (#1741 P1). Empty string when
 *              unset — matches every OTHER string field on this class
 *              (e.g. [ccli]), rather than a nullable `String?`, because
 *              Kotlin defaults + `ignoreUnknownKeys` already make an ABSENT
 *              wire key safe (see the #1752 Slice E file-header note below);
 *              there is no separate "explicitly null" case to model here.
 * @property disambiguation Short parenthetical distinguishing same-named
 *              songs, e.g. "(Christmas version)" (#1741 P1). Empty when unset.
 * @property firstPublishedYear Year of first publication (#1741 P1), or
 *              `null` when unknown. `Int?` (not a bare `Int`) because a
 *              genuinely missing year — the overwhelmingly common case for
 *              this brand-new column — must be distinguishable from year
 *              zero, matching `SongDetail.firstPublishedYear`'s (Apple) and
 *              `_songIdentityCols()`'s (PHP) identical `NULL`-means-unknown
 *              treatment (never a sentinel int).
 * @property copyrightYears As-printed copyright year(s), free text e.g.
 *              "1978, 1987, 2011" (#1741 P1) — the split-fields half of
 *              [copyrightDisplay]'s precedence fold.
 * @property copyrightHolder Copyright holder name (#1741 P1) — the other
 *              split-fields half.
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
    val components: List<SongComponent>,
    // #1752 Slice E — the five #1741 P1 song-identity fields, forward-compat
    // only (see this file's header + `SongDetailScreen.kt`'s
    // `copyrightDisplay()` for the FULL "why now, if nothing populates it
    // yet" reasoning, per `.claude/catalogue-1741-1752-plan.md` §6's
    // honesty clause). Plain Kotlin DEFAULTS (not nullable `?` types for the
    // strings) are what make this safe against the bundled `songs.json`
    // asset, which predates these keys entirely and will never carry them
    // (the source `.SourceSongData/` text files this JSON is generated from
    // never had this data — see the build spec §0.2's "dead end" finding):
    // `kotlinx.serialization`'s `ignoreUnknownKeys = true`
    // (`SongViewModel.kt`) already tolerates an unrecognised key in the
    // JSON; a MISSING expected key on a `@Serializable` class with a
    // default value degrades to that default rather than throwing, so a
    // `Song` parsed from the current bundled asset decodes exactly as it
    // did before this change — genuinely pixel-identical, not merely
    // "shouldn't crash."
    val subtitle: String = "",
    val disambiguation: String = "",
    val firstPublishedYear: Int? = null,
    val copyrightYears: String = "",
    val copyrightHolder: String = ""
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

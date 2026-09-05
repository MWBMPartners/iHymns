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
 * @property voices Which voice(s) sing each contiguous run of lines — "the
 *              men sing lines 3-6" (#2073, commit 16,
 *              `.claude/vocal-parts-2073-plan.md` design pass 7 / pass 3
 *              §4.1+§6.5). ELI5: who's singing right now, grouped into
 *              blocks. DETAILED: a brand new, SPARSE wire key — most songs
 *              won't carry it for a while yet, and even a song WITH voice
 *              parts assigned only carries it on the components a curator
 *              actually touched. Defaults to an empty list (not `null`) so
 *              an absent key on the wire — every song response today, and
 *              every already-bundled `songs.json` asset — decodes to "no
 *              runs" rather than needing a null-check at every call site;
 *              this mirrors [Song.subtitle]'s "empty string, not nullable"
 *              precedent immediately above in this same file, just for a
 *              list instead of a string. `ignoreUnknownKeys = true` on the
 *              shared `Json {}` parser (`SongViewModel.kt`) already makes an
 *              UNRECOGNISED wire key silently ignored; the default value
 *              here is what additionally makes a recognised-but-ABSENT key
 *              safe (`kotlinx.serialization` is strict about a missing
 *              required property regardless of `ignoreUnknownKeys` — a
 *              property needs its OWN default to degrade gracefully).
 *              DEFERRED: nothing renders this list yet — that is a
 *              separate, later commit; this property exists purely so the
 *              app can start reading the value without crashing.
 * @property voiceSpans Which voice sings a PART of one line's text — an
 *              echo on just the last few words rather than the whole line
 *              (#2073). Same sparse/default-empty/deferred-rendering
 *              reasoning as [voices] above.
 */
@Serializable
data class SongComponent(
    val type: String,
    val number: Int? = null,
    val lines: List<String>,
    val voices: List<VoiceRun> = emptyList(),
    val voiceSpans: List<VoiceSpan> = emptyList()
)

// =============================================================================
// VOICE PARTS (#2073) — "who sings this run of lines / this bit of a line"
// =============================================================================

/**
 * One contiguous run of lines sung by the same voice part(s) within a single
 * [SongComponent] — e.g. "lines 0 through 3, sung by the women".
 *
 * ELI5: "From here to here, it's the women singing."
 *
 * DETAILED (#2073): Mirrors the web project's `lyricLinesFoldVoiceRuns()`
 * wire shape (`.claude/vocal-parts-2073-plan.md` design pass 3 §4.1/§6.5).
 * [from]/[to] are 0-based, INCLUSIVE indexes into the OWNING component's
 * `lines` list — never a `tblLyricLines.Id` (that identity is a SEPARATE
 * server concept this simplified Android model doesn't carry at all today);
 * resolve a run's actual text via `lines.subList(from, to + 1)`.
 *
 * @property from First line index (0-based, inclusive) this run covers.
 * @property to Last line index (0-based, inclusive) this run covers.
 * @property parts The part(s) singing this run — almost always one; more
 *              than one models, e.g., a duet or two named singers sharing a
 *              line.
 */
@Serializable
data class VoiceRun(
    val from: Int,
    val to: Int,
    val parts: List<VoicePart>
)

/**
 * One named voice part attached to a [VoiceRun] (or, without [enters],
 * embedded in a [VoiceSpan]) — "the women", "echo", "Soloist: Sarah".
 *
 * ELI5: A label for who's singing, plus whether it's an echo.
 *
 * DETAILED (#2073): [kind] is the open, app-validated vocabulary key from
 * the web project's `includes/vocal_parts.php`'s `IHYMNS_VOCAL_PART_KINDS`
 * map — deliberately a plain [String], never a Kotlin `enum class`
 * (`.claude/CLAUDE.md` rule #20: a growable vocabulary is VARCHAR/String +
 * an app-level allow-list, never a closed enum — a Kotlin `enum class`
 * here would fail exactly the same way a fixed set would server-side the
 * day a new kind is added, since `kotlinx.serialization` throws on an
 * unrecognised enum constant with no built-in "unknown case" fallback).
 * [bg] marks a background/echo
 * part (a whole-LINE echo rides here; a sub-line echo rides on [VoiceSpan]
 * instead, never both at once). [enters] is `true` only on the run where
 * this part first appears relative to the immediately-preceding run in the
 * same component (server-computed adjacency) — present on [VoiceRun.parts]
 * but genuinely absent from the smaller shape embedded in
 * [VoiceSpan.part], hence nullable-with-default here rather than a second,
 * near-duplicate class.
 *
 * @property id The underlying `tblVocalParts.Id` — stable across saves; use
 *              THIS for chip identity when re-rendering, never [label] text.
 * @property kind Open vocabulary key, e.g. `"women"`, `"men"`,
 *              `"named-singer"`.
 * @property label Human-facing label, e.g. `"Women"`, or a named singer's
 *              display name.
 * @property bg `true` for a background/echo part.
 * @property enters `true` only when this part is entering fresh in this
 *              run; `null`/absent on the trimmed copy nested inside
 *              [VoiceSpan.part] — see the class-level doc comment above.
 */
@Serializable
data class VoicePart(
    val id: Int,
    val kind: String,
    val label: String,
    val bg: Boolean = false,
    val enters: Boolean? = null
)

/**
 * A sub-line echo/voice-part assignment — some voice singing only PART of
 * one line's text, e.g. just the last three words.
 *
 * ELI5: "Just this bit of the line is a different voice."
 *
 * DETAILED (#2073): Mirrors the web project's `lyricLinesFoldVoiceSpans()`
 * wire shape. [start]/[end] are Unicode CODE-POINT offsets into the
 * REFERENCED line's text (`.claude/CLAUDE.md` rule #21 — never a byte or
 * UTF-16 offset; slice with `text.codePoints()`/`String(Character.toChars
 * (...))`, never a raw Kotlin `String` index, which is UTF-16 code-unit
 * based and would misalign on any line containing a surrogate-pair
 * character), [end] EXCLUSIVE. SPARSE on [SongComponent.voiceSpans] —
 * present only when at least one span exists anywhere in that component.
 *
 * @property line 0-based index into the owning component's `lines` list —
 *              the line this span slices (same indexing convention as
 *              [VoiceRun.from]/[VoiceRun.to]).
 * @property start Start code-point offset into that line's text (inclusive).
 * @property end End code-point offset into that line's text (exclusive).
 * @property part The part singing this span. No `enters` here — that
 *              concept only applies to a whole run, not a sub-line slice.
 */
@Serializable
data class VoiceSpan(
    val line: Int,
    val start: Int,
    val end: Int,
    val part: VoicePart
)

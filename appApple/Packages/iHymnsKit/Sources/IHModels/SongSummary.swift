// SongSummary.swift
// IHModels
//
// ELI5: This is the "business card" version of a song — just enough to show
// a row in a search result or a songbook list (title, number, which book)
// without downloading the whole song (lyrics, chords, credits, ...).
//
// DETAILED: Mirrors the shape the web/PWA calls the "slim index" —
// `SongData::getSongsSlimIndex()` served over `/api?action=songs_index`
// (`.claude/CLAUDE.md` rule #17: reads are scoped, never a whole-corpus
// materialisation). The native client is explicitly forbidden from ever
// building an equivalent whole-corpus structure client-side either
// (strategy §1.5, "NO whole-corpus, natively enforced") — `SongSummary` is
// exactly the row shape that index endpoint returns, decoded one page/batch
// at a time into `IHPersistence`'s FTS5-indexed offline cache, never held
// as one giant in-memory array of ~14k entries at once by the UI layer.
import Foundation

/// A lightweight, list-friendly summary of a song — the native mirror of the
/// web API's `songs_index` row shape.
///
/// ELI5: Everything you need to draw one line in a song list.
///
/// DETAILED: Every stored property is a `Sendable` value type, so a whole
/// array of `SongSummary` can cross from the background `actor APIClient`
/// (IHAPI) into `IHPersistence`'s GRDB writer and then into a `@MainActor`
/// SwiftUI list — all without a single manual synchronisation primitive —
/// because Swift 6 strict concurrency (strategy §1.3) can *prove* the value
/// is safe to hand across those actor boundaries at compile time.
public struct SongSummary: Sendable, Hashable, Codable, Identifiable {

    /// Stable identity for SwiftUI `List`/`ForEach` — the `SongID` itself is
    /// already a unique, stable, `Hashable` key, so no synthetic UUID needed.
    public var id: SongID { songId }

    /// The parsed `<letters>-<digits>` identifier, e.g. `MP-1008`.
    public let songId: SongID

    /// Display title, e.g. `"Amazing Grace"`.
    public let title: String

    /// The owning songbook's abbreviation (redundant with
    /// `songId.songbookAbbreviation` today, but kept as its own field
    /// because the web API returns it separately and a future songbook
    /// rename could, in principle, decouple the two — see CLAUDE.md #27 on
    /// `Abbreviation` vs `DisplayAbbr`).
    public let songbookAbbreviation: String

    /// The song's display number within its songbook (may differ in
    /// formatting from `songId.number`, e.g. a book that pads numbers).
    public let displayNumber: String

    /// Creates a `SongSummary`.
    ///
    /// ELI5: Just bundles the four pieces of info together.
    ///
    /// DETAILED: A plain memberwise-style initializer (kept explicit —
    /// rather than relying on the compiler-synthesized memberwise init —
    /// only so this file can carry the doc comment; behaviourally
    /// equivalent to the synthesized one).
    public init(songId: SongID, title: String, songbookAbbreviation: String, displayNumber: String) {
        self.songId = songId
        self.title = title
        self.songbookAbbreviation = songbookAbbreviation
        self.displayNumber = displayNumber
    }
}

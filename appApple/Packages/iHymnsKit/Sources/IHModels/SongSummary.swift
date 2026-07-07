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
//
// #1396 UPDATE — this file originally modelled a GUESSED row shape before
// any live fixture existed. A real pull from `?action=songs_index`
// (`Tests/Fixtures/songs_index.json`, 2026-07-07) showed the actual server
// field names/types differ in three ways, now reflected below:
//   1. The JSON key is `id`, not `songId` (still decoded into `songId: SongID`
//      — the Swift property name doesn't have to match the wire name once a
//      `CodingKeys` mapping exists).
//   2. `number` is a JSON **integer, nullable** (`null` for a handful of
//      manually-keyed "Misc" rows with no catalogue number), not the
//      previously-assumed `displayNumber: String`. The `displayNumber`
//      computed property below exists purely so `IHFeatures/SongSummaryRow`
//      keeps a ready-to-render `String` without every call site doing its
//      own `number.map(String.init) ?? …`.
//   3. The row carries three fields this file didn't know about at all:
//      `songbookName` (the full songbook title, not just the abbreviation),
//      `language` (BCP-47 primary tag), and `publicId` (a public,
//      non-sequential id — kept `String?` defensively since it isn't in
//      `api-docs.yaml`'s documented shape at all, even though every row in
//      the live pull carried one).
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

    /// The parsed `<letters>-<digits>` identifier, e.g. `MP-1008`. Wire key
    /// is `"id"` (see the `CodingKeys` mapping below) — kept as `songId` on
    /// the Swift side so it doesn't collide with the `Identifiable.id`
    /// computed property above.
    public let songId: SongID

    /// Display title, e.g. `"Amazing Grace"`.
    public let title: String

    /// The owning songbook's abbreviation (redundant with
    /// `songId.songbookAbbreviation` today, but kept as its own field
    /// because the web API returns it separately and a future songbook
    /// rename could, in principle, decouple the two — see CLAUDE.md #27 on
    /// `Abbreviation` vs `DisplayAbbr`). Wire key is `"songbook"`.
    public let songbookAbbreviation: String

    /// The songbook's full display name, e.g. `"Mission Praise"` — added
    /// after seeing the real payload; every row in the corpus carries it,
    /// letting list UIs show "Amazing Grace — Mission Praise" without a
    /// second `?action=songbooks` round-trip just to resolve the name.
    public let songbookName: String

    /// BCP-47 primary language tag for this song, e.g. `"en"`, `"en-Latn-ZA"`.
    public let language: String

    /// The song's number within its songbook — `nil` for a small set of
    /// manually-catalogued rows (the live `Misc` songbook has a few) that
    /// have no meaningful sequence number. Non-optional `Int` (not `String`)
    /// so numeric sort/format never has to re-parse; see `displayNumber`
    /// for the ready-to-render form.
    public let number: Int?

    /// Whether an audio recording is attached (drives the play-button
    /// affordance in list rows).
    public let hasAudio: Bool

    /// Whether sheet music is attached (drives the PDF-icon affordance).
    public let hasSheetMusic: Bool

    /// A public, non-sequential identifier for this song (used by share
    /// links / QR codes that shouldn't leak the internal sequential id).
    /// `nil`-tolerant: not documented in `api-docs.yaml`'s `songs_index`
    /// schema at all, even though the live pull always carried one — kept
    /// optional per the "model what's there, keep unknowns optional" rule
    /// rather than assuming a field an older/not-yet-migrated deployment
    /// might not emit.
    public let publicId: String?

    /// Render-ready form of `number` — `""` when there is none, otherwise
    /// the plain decimal string. Exists so UI code (`SongSummaryRow`) never
    /// has to repeat the same `number.map(String.init) ?? ""` fold.
    public var displayNumber: String {
        number.map(String.init) ?? ""
    }

    /// Creates a `SongSummary`.
    ///
    /// ELI5: Just bundles the pieces of info together.
    ///
    /// DETAILED: A plain memberwise-style initializer (kept explicit —
    /// rather than relying on the compiler-synthesized memberwise init —
    /// only so this file can carry the doc comment; behaviourally
    /// equivalent to the synthesized one). The four fields the live payload
    /// added after this type's Phase-0 debut (`songbookName`/`language`/
    /// `hasAudio`/`hasSheetMusic`/`publicId`) default to sensible neutral
    /// values so the handful of existing call sites across the package
    /// that predate this file's #1396 update didn't *all* need to start
    /// naming every field.
    public init(
        songId: SongID,
        title: String,
        songbookAbbreviation: String,
        number: Int?,
        songbookName: String = "",
        language: String = "en",
        hasAudio: Bool = false,
        hasSheetMusic: Bool = false,
        publicId: String? = nil
    ) {
        self.songId = songId
        self.title = title
        self.songbookAbbreviation = songbookAbbreviation
        self.number = number
        self.songbookName = songbookName
        self.language = language
        self.hasAudio = hasAudio
        self.hasSheetMusic = hasSheetMusic
        self.publicId = publicId
    }

    /// Maps this type's Swift-side names onto the server's real JSON keys.
    /// Every case without an explicit raw value already matches the wire
    /// name (`title`, `songbookName`, `language`, `number`, `hasAudio`,
    /// `hasSheetMusic`, `publicId`) — Swift's synthesized `Codable`
    /// conformance uses this enum for both `Decodable` and `Encodable`, and
    /// correctly `decodeIfPresent`s the two `Optional`-typed cases
    /// (`number`, `publicId`) so a missing OR explicit-`null` key both fold
    /// to `nil` without any hand-written `init(from:)`.
    private enum CodingKeys: String, CodingKey {
        case songId = "id"
        case title
        case songbookAbbreviation = "songbook"
        case songbookName
        case language
        case number
        case hasAudio
        case hasSheetMusic
        case publicId
    }
}

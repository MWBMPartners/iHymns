// SongLineEnrichment.swift
// IHModels
//
// ELI5: The extra, optional bits a song's lyric lines can carry — a
// translation/pronunciation-guide for one line, a footnote explaining what a
// line means, or a royalty-society ID for the whole song — none of which
// come back UNLESS the app specifically asks for them.
//
// DETAILED: These three shapes back `?action=song_detail`'s `include=`
// opt-in enrichment mechanism (#1099, `SongData::songDetailIncludeBlocks()`
// in `appWeb/public_html/includes/SongData.php`): a native/casting client
// appends `&include=translations,annotations,royaltyIds` (comma-separated,
// allow-listed server-side — unknown block names are silently ignored) and
// the server folds the requested, PRESENT blocks onto the `song` object as
// extra top-level keys. A block is omitted entirely (not sent as an empty
// array) when the song/table has nothing for it — this task's own dev-API
// survey (fetching ~600 distinct songs across all 40 songbooks with every
// block requested) found EVERY song currently returns none of these three
// blocks: the schema is real and live (`tblLyricLineTranslations` /
// `tblLyricLineAnnotations` / `tblSongRoyaltyIds` all exist per
// `appWeb/.sql/schema.sql`) but no song on `dev` has been curated with this
// data yet. Every field below is therefore modelled directly from
// `SongData::getSongDetailExtras()`'s SQL column aliases (verified reading
// the PHP source, not a live non-empty payload) — the same conservative
// posture `Work.swift`'s header already documents for the same reason.
//
// `SongDetail.swift` wires these three blocks onto its own `translations`/
// `annotations`/`royaltyIds` properties — see that file for the constants
// requested and `SongDetailTranslationsField` below for a real key-collision
// this task discovered in the wire contract.
import Foundation

/// One per-line translation or transliteration (romanization) row (#1088)
/// — Apple Music TTML's `<translation>`/`<transliteration>` head-track model,
/// one row per (line, target language, kind).
///
/// ELI5: "This line, translated (or sounded-out) into another language."
public struct LyricLineTranslation: Sendable, Hashable, Codable {
    /// FK to `tblLyricLines.Id` — matches one entry in the owning
    /// `SongComponent.lineIds` array (CLAUDE.md rule #21: per-line
    /// enrichment anchors on the STABLE line id, never a positional index).
    public let lineId: Int

    /// `"translation"` (meaning) or `"transliteration"` (romanization/
    /// pronunciation) — open `String` vocabulary (`tblLyricLineTranslations.Kind`
    /// is `VARCHAR`, not `ENUM`, so a future kind like furigana/IPA decodes
    /// instead of throwing).
    public let kind: String

    /// IETF BCP-47 tag of THIS translated/romanized text, e.g. `"es"`,
    /// `"ja-Latn"` — may differ from the line's own `language`.
    public let targetLanguage: String

    /// The translated/romanized text itself.
    public let text: String

    /// Whether this is the preferred row to display for this
    /// (line, language, kind) combination, when several providers/sources
    /// have supplied one (`tblLyricLineTranslations.IsPrimary`).
    public let isPrimary: Bool
}

/// One explanatory annotation attached to a line span (#1088) — a Genius-
/// style footnote/gloss, e.g. a scripture cross-reference or historical note.
///
/// ELI5: "Here's what this line (or these few lines) means."
public struct LyricLineAnnotation: Sendable, Hashable, Codable {
    /// FK to `tblLyricLines.Id` — the span's first line.
    public let startLineId: Int

    /// FK to `tblLyricLines.Id` — the span's last line, or `nil` for a
    /// single-line annotation (`tblLyricLineAnnotations.EndLineId`, NULL =
    /// "ends on `startLineId`").
    public let endLineId: Int?

    /// `"explanation"`, `"reference"`, `"scripture"`, `"history"`,
    /// `"translation"`, `"trivia"`, … — open `String` vocabulary
    /// (`tblLyricLineAnnotations.AnnotationType` is `VARCHAR`, app-validated
    /// against a central map server-side, never an `ENUM`).
    public let annotationType: String

    /// The annotation's own body text (Markdown/HTML/plain per `bodyFormat`).
    public let body: String

    /// `"markdown"`, `"html"`, or `"plain"` — how to render `body`.
    public let bodyFormat: String

    /// A first-class "staff/artist-cosigned" badge, distinct from ordinary
    /// moderation approval (`tblLyricLineAnnotations.IsVerified`).
    public let isVerified: Bool
}

/// One song's registration with a performing-rights/royalty authority
/// (#1140) — ASCAP, BMI, PRS, and similar societies each assign their own
/// work/agreement id, distinct from the single top-level `ccli`/`iswc`
/// fields `SongDetail` already carries.
///
/// ELI5: "This song is registered with {society} under id {x}."
public struct SongRoyaltyId: Sendable, Hashable, Codable {
    /// `"ASCAP"`, `"BMI"`, `"PRS"`, `"SESAC"`, `"GEMA"`, … — open `String`
    /// vocabulary (`tblSongRoyaltyIds.Authority` is `VARCHAR`, growable).
    public let authority: String

    /// The society-assigned work/agreement identifier.
    public let authorityId: String

    /// Optional curator note, e.g. clarifying which edition/arrangement the
    /// registration covers.
    public let note: String?
}

/// Wraps a REAL wire-level ambiguity this task discovered while reading
/// `SongData.php`: the JSON key `"translations"` on a `song_detail` response
/// means TWO different things depending on how it got there —
///
/// 1. **Always-possible base field** (`SongData::_fetchSongRow()` line
///    ~3301): a SPARSE array of `SongTranslationRef` — whole-song
///    cross-language sibling references, only present when non-empty.
/// 2. **Opt-in `include=translations` enrichment block**
///    (`SongData::getSongDetailExtras()` line ~2232): an array of
///    `LyricLineTranslation` rows — this song's OWN per-line translations/
///    romanizations.
///
/// The include-block handler literally does `$song[$blockKey] = $blockVal`
/// AFTER `_fetchSongRow()` already built `$song` — so on a (currently
/// hypothetical, since no live song has EITHER shape populated yet) future
/// song that has BOTH a curated cross-language sibling AND its own per-line
/// translations, requesting `include=translations` would silently overwrite
/// the whole-song sibling refs with the per-line rows in that one response.
/// This wrapper can't fix that upstream ambiguity (native code can't rewrite
/// the wire contract), but it DOES mean this client's decode never guesses
/// wrong about which shape actually arrived: it tries the per-line shape
/// first (unambiguous — `LyricLineTranslation` requires `lineId`/`text`/
/// `isPrimary`, none of which `SongTranslationRef` carries, so a successful
/// decode can only mean the per-line shape truly arrived) and falls back to
/// the whole-song shape otherwise.
///
/// ELI5: "This one JSON field can mean two different things — this type
/// figures out which one actually showed up, instead of guessing."
public enum SongDetailTranslationsField: Sendable, Hashable, Codable {
    /// Whole-song cross-language sibling song references (the always-
    /// possible base shape).
    case crossLanguageSiblings([SongTranslationRef])

    /// This song's own per-line translation/romanization rows (the
    /// `include=translations` opt-in enrichment shape).
    case perLine([LyricLineTranslation])

    public init(from decoder: any Decoder) throws {
        let container = try decoder.singleValueContainer()
        if let perLine = try? container.decode([LyricLineTranslation].self) {
            self = .perLine(perLine)
            return
        }
        self = .crossLanguageSiblings(try container.decode([SongTranslationRef].self))
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.singleValueContainer()
        switch self {
        case .crossLanguageSiblings(let refs):
            try container.encode(refs)
        case .perLine(let rows):
            try container.encode(rows)
        }
    }
}

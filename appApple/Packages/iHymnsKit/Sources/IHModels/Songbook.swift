// Songbook.swift
// IHModels
//
// ELI5: A hymnal/songbook — "Mission Praise," "The Church Hymnal," and so
// on — plus everything the catalogue knows ABOUT that book (who published
// it, when, its Wikipedia page, whether it's an official denominational
// book or a curator-made grouping, which other songbooks are translations
// of it, ...).
//
// DETAILED: Mirrors `?action=songbooks`, verified field-for-field against a
// live pull (`Tests/Fixtures/songbooks.json`, all 54 songbooks on `dev`,
// recorded 2026-07-07). That live payload disagrees with
// `#/components/schemas/Songbook` in `appWeb/public_html/api-docs.yaml` in
// two concrete, verified ways worth flagging (#1396 finding — a docs-
// freshness follow-up is worth its own tracked issue):
//   1. `publicationYear` is documented as `type: integer` but the live
//      field is a JSON **string** (`"1977"`, not `1977`) — confirmed by
//      reading `SongData.php`'s own `SongbookWriteInput.publication_year`
//      doc comment: "Stored as VARCHAR so non-numeric strings (e.g. '1985 /
//      2002 reprint') survive." The read schema's doc comment just never
//      got updated to match.
//   2. `displayAbbr` (#1332, `.claude/CLAUDE.md` rule #27) and the plural
//      `languages` array (#857, the union of the songbook's own tag plus
//      every distinct language actually used by songs inside it) aren't in
//      the YAML schema AT ALL, even though every live row carries both.
// `series`/`compilers`/`alternativeNames`/`parent` (#782 phase D, #831,
// #832) are documented in prose but not in the OpenAPI schema either — all
// modelled here from reading `SongData.php`'s `getSongbooks()` +
// `_songbookSeriesMap()`/`_songbookCompilersMap()`/`_songbookAltNamesMap()`/
// `_normaliseSongbookParent()` directly, since that's the actual source of
// truth for their shape.
import Foundation

/// The Work-equivalent self-reference for songbooks: "this book is a
/// translation of (or otherwise related to) that book." Only ever present
/// when `tblSongbooks`'s parent-songbook self-join (#782 phase D) has a row
/// — most songbooks have no parent and decode `Songbook.parent` as `nil`.
public struct SongbookParentRef: Sendable, Hashable, Codable {
    public let id: Int
    public let abbreviation: String
    public let name: String

    /// Free-text relationship label, e.g. `"translation"`. Open vocabulary
    /// (same non-`enum` reasoning as `ExternalLink.category`) — the web
    /// admin UI is the source of truth for which labels exist today, and a
    /// new one shouldn't be a native decode failure.
    public let relationship: String
}

/// One series a songbook belongs to (#1181), e.g. "Songs Of Fellowship"
/// grouping its six numbered books under one shared badge colour.
public struct SongbookSeries: Sendable, Hashable, Codable, Identifiable {
    public let id: Int
    public let name: String
    public let slug: String

    /// 6-digit hex (no `#`? — live data shows both a set colour and an
    /// empty string for "no series colour"; kept `String` rather than
    /// `String?` because the server always sends the key, just sometimes
    /// empty, matching `Songbook.colour`'s equivalent empty-vs-nil choice
    /// below).
    public let colour: String
}

/// One person credited with compiling a songbook (#831).
public struct SongbookCompiler: Sendable, Hashable, Codable, Identifiable {
    public let id: Int
    public let name: String
    public let slug: String
    public let note: String
}

/// One alternative name a songbook is also known by (#832) — simpler than
/// `SongAlternativeTitle` (no `language` field; confirmed against
/// `SongData::_songbookAltNamesMap()`, which only ever selects `title` +
/// `note`).
public struct SongbookAlternativeName: Sendable, Hashable, Codable {
    public let title: String
    public let note: String
}

/// A songbook / hymnal, with its full bibliographic + authority-control +
/// grouping metadata.
///
/// ELI5: One hymnal, plus everything the catalogue knows about it.
public struct Songbook: Sendable, Hashable, Codable, Identifiable {

    /// The songbook's abbreviation, e.g. `"MP"` — this IS the `SongId`
    /// prefix (`.claude/CLAUDE.md` rule #27), so it stays a plain `String`
    /// here rather than `SongID` (a `Songbook` has no song number of its
    /// own to pair it with).
    public let id: String

    public let name: String
    public let songCount: Int

    /// 6-digit hex tile colour, e.g. `"#264a26"` — `nil` means "use the
    /// auto-picked theme tone" (matches `api-docs.yaml`'s documented
    /// `nullable: true`; the live payload does send explicit `null` for
    /// this one, unlike `SongbookSeries.colour`'s empty-string convention).
    public let colour: String?

    public let isOfficial: Bool
    public let publisher: String?

    /// Publication year — a **string**, not an integer; see this file's
    /// header comment for why (`api-docs.yaml`'s schema doc is stale here).
    public let publicationYear: String?

    public let copyright: String?
    public let affiliation: String?

    /// Optional free-text display label overriding the `id` abbreviation
    /// badge (#1332, `.claude/CLAUDE.md` rule #27) — e.g. the unofficial
    /// "Psalty" collection can carry characters `id` itself can't (`- _ :`).
    /// URLs/SongIds always use `id`, never this field.
    public let displayAbbr: String?

    /// The songbook's own primary BCP-47 language tag — `nil`/mixed for a
    /// multi-language grouping like "Miscellaneous". See `languages` below
    /// for the full per-song union.
    public let language: String?

    public let websiteUrl: String?
    public let internetArchiveUrl: String?
    public let wikipediaUrl: String?
    public let wikidataId: String?
    public let oclcNumber: String?
    public let ocnNumber: String?
    public let lcpNumber: String?
    public let isbn: String?
    public let arkId: String?
    public let isniId: String?
    public let viafId: String?
    public let lccn: String?
    public let lcClass: String?

    /// The songbook this one is a translation/edition of, if any.
    public let parent: SongbookParentRef?

    public let series: [SongbookSeries]
    public let compilers: [SongbookCompiler]
    public let alternativeNames: [SongbookAlternativeName]
    public let links: [ExternalLink]

    /// The UNION of this songbook's own primary language subtag and every
    /// distinct primary subtag actually carried by songs inside it (#857) —
    /// deliberately plural/distinct from the singular `language` above.
    /// Drives the "Show languages" filter chip set; empty on a
    /// pre-#673/#857 deployment.
    public let languages: [String]

    private enum CodingKeys: String, CodingKey {
        case id
        case name
        case songCount
        case colour
        case isOfficial
        case publisher
        case publicationYear
        case copyright
        case affiliation
        case displayAbbr
        case language
        case websiteUrl
        case internetArchiveUrl
        case wikipediaUrl
        case wikidataId
        case oclcNumber
        case ocnNumber
        case lcpNumber
        case isbn
        case arkId
        case isniId
        case viafId
        case lccn
        case lcClass
        case parent
        case series
        case compilers
        case alternativeNames
        case links
        case languages
    }
}

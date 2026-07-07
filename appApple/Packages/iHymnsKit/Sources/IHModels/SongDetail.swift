// SongDetail.swift
// IHModels
//
// ELI5: The FULL record for one song — lyrics, who wrote it, what tags it
// carries, other titles it's known by, attached audio/PDF files, and which
// other songbooks share it. This is what you get after tapping into a
// `SongSummary` row from the list.
//
// DETAILED: Mirrors `?action=song_detail` (alias `song_data`), verified
// field-for-field against a live pull (`Tests/Fixtures/song_detail.json`,
// `MP-0031` "Amazing grace", recorded 2026-07-07). That live payload is
// SUBSTANTIALLY richer than `#/components/schemas/Song` in
// `appWeb/public_html/api-docs.yaml`, which documents only 12 of this
// type's ~28 properties — `tuneName`/`iswc`/`verified`/`originCity`/
// `originCityId`/`publicId`/`arrangement`/`arrangers`/`adaptors`/
// `translators`/`tags`/`alternativeTitles`/`media` are all real, always-
// present fields the YAML doc simply doesn't mention (a #1396 finding worth
// a docs-freshness follow-up issue — see this task's final report). Every
// field below is modelled from either the live JSON or, where the live
// sample happened to be empty (`works`), from the corresponding named
// schema in `api-docs.yaml` (`Song.works` → `SongDetail.works: [Work]`,
// see `Work.swift`'s own header for why that one stays conservative).
//
// No explicit memberwise initializer is hand-written here (unlike the much
// smaller `SongSummary`) — with ~28 stored properties, a hand-rolled
// initializer would be pure repetition of the property list with zero
// behavioural value; the compiler-synthesized memberwise init (available
// because no custom `init(from:)` is written either — see `CodingKeys`
// below) is what tests actually use.
//
// Apple P1 (#180) UPDATE — a deep survey of the dev API's `include=`
// opt-in enrichment mechanism (#1099, see `SongLineEnrichment.swift`'s
// header) added `royaltyIds`/`annotations` and changed `translations`' TYPE
// (still the SAME wire key) to `SongDetailTranslationsField`, which resolves
// a real key-collision between that field's two possible live shapes — see
// that type's own doc comment. All three are only ever populated when this
// client's own fetch asked for them via `include=` (`APIClient.songDetail
// (id:)` always does — see that file); every song this task could find on
// `dev` has none of the three, so in practice they decode to `nil` today,
// same as `translations` already did before this update.
import Foundation

/// The complete song record: metadata, every credit role, lyric components,
/// tags, alternative titles, external links, containing Works, and media.
///
/// ELI5: Everything the app needs to render a full song page.
public struct SongDetail: Sendable, Hashable, Codable, Identifiable {
    public var id: SongID { songId }

    /// Wire key `"id"` — see `SongSummary.songId`'s identical rationale.
    public let songId: SongID

    /// `nil` for the handful of manually-catalogued rows with no sequence
    /// number (same real-world nullability as `SongSummary.number`).
    public let number: Int?

    public let title: String

    /// Wire key `"songbook"` (the abbreviation, e.g. `"MP"`).
    public let songbookAbbreviation: String
    public let songbookName: String

    /// BCP-47 primary language tag, e.g. `"en"`.
    public let language: String

    /// Copyright statement — always present as a string server-side (empty
    /// `""` rather than `null` when unset, per the live sample), so kept
    /// non-optional to match what's actually sent.
    public let copyright: String

    /// The tune's name, e.g. a hymn-tune identifier distinct from the lyric
    /// title — empty string when unset, same non-optional reasoning.
    public let tuneName: String

    /// CCLI SongSelect number, empty string when unset.
    public let ccli: String

    /// International Standard Musical Work Code for this specific
    /// arrangement (distinct from a parent `Work.iswc`) — empty when unset.
    public let iswc: String

    /// Whether a curator has personally verified these lyrics.
    public let verified: Bool
    public let lyricsPublicDomain: Bool
    public let musicPublicDomain: Bool
    public let hasAudio: Bool
    public let hasSheetMusic: Bool

    /// Free-text place-of-origin, empty string when unset.
    public let originCity: String

    /// FK into a places/gazetteer table when the origin city has been
    /// resolved to a curated record; `nil` for free-text-only entries.
    public let originCityId: Int?

    /// Public, non-sequential id for share links/QR codes.
    public let publicId: String?

    /// Curator-chosen display order for `components`, as a list of
    /// **0-based ordinal indices into the `components` array** — `nil` when
    /// the server falls back to plain insertion order (the common case; see
    /// `SongData::_decodeArrangement()`, which returns `nil` for anything
    /// that isn't a clean list of ints).
    ///
    /// DETAILED (#180 correction): an earlier version of this doc comment
    /// said these were `tblSongComponents` row ids — verified WRONG by
    /// reading how the web renderer actually consumes this field
    /// (`includes/pages/song.php`: `array_map(fn($i) => $components[$i] ??
    /// null, $arrangement)`, i.e. positional array indices, confirmed by
    /// `includes/lyric_lines_sync.php`'s own comment that these ordinals
    /// stay "contiguous 0..n-1"). `orderedComponents` below reproduces that
    /// EXACT web behaviour (including silently dropping an out-of-range
    /// index via `array_filter`) so native rendering matches the web
    /// pixel-for-pixel — this task's own #1399-era `SongDetailView` never
    /// applied `arrangement` at all before this fix, always rendering plain
    /// `components` order.
    public let arrangement: [Int]?

    /// `components`, reordered per `arrangement` when present — the ONE
    /// property `SongDetailView`/any future renderer should actually
    /// iterate, never `components` directly, so a curator-set custom
    /// arrangement is never silently ignored.
    ///
    /// ELI5: "The verses/choruses, in the order the curator actually wants
    /// them sung — not just the order they happen to be stored in."
    public var orderedComponents: [SongComponent] {
        guard let arrangement else { return components }
        let reordered = arrangement.compactMap { index in
            components.indices.contains(index) ? components[index] : nil
        }
        // A malformed arrangement (every index out of range) degrades to
        // the plain stored order rather than rendering an empty song.
        return reordered.isEmpty ? components : reordered
    }

    public let writers: [String]
    public let composers: [String]
    public let arrangers: [String]
    public let adaptors: [String]
    public let translators: [String]

    /// Recording/release artist names (#587) — most useful for
    /// contemporary worship songs; traditional hymns leave this empty.
    public let artists: [String]

    /// The lyric body, in display order (verses/choruses/bridges/...).
    public let components: [SongComponent]

    public let tags: [SongTag]
    public let alternativeTitles: [SongAlternativeTitle]

    /// Curated external links (Wikipedia, Spotify, sheet-music sellers, …).
    public let links: [ExternalLink]

    /// Works (#840) this song belongs to — empty on a pre-migration
    /// deployment or a song with no curated grouping yet.
    public let works: [Work]

    /// Attached audio/sheet-music/MIDI/MusicXML files (#853) — metadata
    /// only, never raw bytes (streaming goes through `streamUrl`).
    public let media: [SongMediaAsset]

    /// Cross-language translation links, OR this song's own per-line
    /// translation/romanization rows — see `SongDetailTranslationsField`'s
    /// doc comment for the real wire-level ambiguity behind this one key.
    /// SPARSE either way: `nil` when the server sent neither shape (the
    /// base field is only emitted when non-empty; the `include=translations`
    /// block is only emitted when this song's lyrics have approved rows).
    public let translations: SongDetailTranslationsField?

    /// Per-line explanatory annotations (#1088) — opt-in via
    /// `include=annotations` (`APIClient.songDetail(id:)` always requests
    /// it); `nil` when not requested, or the song's lyrics have none.
    public let annotations: [LyricLineAnnotation]?

    /// Per-song PRO/royalty-authority registrations (#1140) — opt-in via
    /// `include=royaltyIds`; `nil` when not requested or the song has none.
    /// Distinct from the single top-level `ccli`/`iswc` fields above — a
    /// song can be registered with several societies at once.
    public let royaltyIds: [SongRoyaltyId]?

    private enum CodingKeys: String, CodingKey {
        case songId = "id"
        case number
        case title
        case songbookAbbreviation = "songbook"
        case songbookName
        case language
        case copyright
        case tuneName
        case ccli
        case iswc
        case verified
        case lyricsPublicDomain
        case musicPublicDomain
        case hasAudio
        case hasSheetMusic
        case originCity
        case originCityId
        case publicId
        case arrangement
        case writers
        case composers
        case arrangers
        case adaptors
        case translators
        case artists
        case components
        case tags
        case alternativeTitles
        case links
        case works
        case media
        case translations
        case annotations
        case royaltyIds
    }
}

/// One curated theme/topic tag attached to a song (#1152 standard theme
/// vocabulary, or a curator-authored variant pending canonicalisation).
public struct SongTag: Sendable, Hashable, Codable, Identifiable {
    public let id: Int
    public let name: String
    public let slug: String
}

/// One alternative title a song is also known by (#832), e.g. a different
/// translation's title or a well-known first-line nickname.
public struct SongAlternativeTitle: Sendable, Hashable, Codable {
    public let title: String
    public let note: String
    public let language: String
}

/// One attached media file's metadata (#853) — audio, sheet-music PDF,
/// MIDI, or MusicXML. Bytes are never part of this shape; playback/download
/// goes through `streamUrl`, which itself passes through the same content-
/// access gate (`checkContentAccess()`) the web backend enforces.
public struct SongMediaAsset: Sendable, Hashable, Codable, Identifiable {
    public let id: Int

    /// `"audio"`, `"sheet-music"`, `"midi"`, `"musicxml"`, … — open
    /// vocabulary, same non-`enum` reasoning as `ExternalLink.category`.
    public let kind: String

    public let fileName: String
    public let mimeType: String
    public let sizeBytes: Int
    public let annotation: String
    public let sortOrder: Int

    /// The gated streaming URL (`/song-media/<id>`) — never a direct
    /// filesystem/database path.
    public let streamUrl: String
}

/// One cross-language translation reference (#352) — the sibling song in
/// another language, distinct from a same-language cross-book counterpart
/// (`?action=song_links`, not modelled by this file).
public struct SongTranslationRef: Sendable, Hashable, Codable {
    public let songId: String
    public let language: String
}

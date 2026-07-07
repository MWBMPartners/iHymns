// SongRelations.swift
// IHModels
//
// ELI5: Two different ways the app can find "other songs connected to this
// one" — songs that are secretly the SAME hymn in a different songbook
// (`song_links`), and songs that just seem related (same writer/composer/
// neighbourhood in the book, `related_songs`).
//
// DETAILED: Both are SEPARATE `?action=` calls from `song_detail` itself
// (`appWeb/public_html/api-docs.yaml`'s `Discovery`/`Songs` tags) — modelled
// here, alongside `SongDetail`, because a song's screen is the one place
// both get consumed together. Verified against LIVE pulls recorded as
// `Tests/Fixtures/song_links.json` / `Tests/Fixtures/related_songs.json`
// (`?action=song_links&id=MP-0031` / `?action=related_songs&id=MP-0031`,
// 2026-07-07):
//   - `song_links` (#807, cross-book counterparts/duplicates) returned
//     `{"groupId":0,"songs":[]}` for every song this task tried (a
//     broad ~60-song sample across songbooks, including three OTHER books'
//     copies of "Amazing grace" itself) — `tblSongLinks` has no curated rows
//     on `dev` yet, so `SongLinkedSong`'s per-row shape is modelled from
//     `api-docs.yaml`'s documented schema (unverified against a live
//     non-empty payload, same conservative posture as `Work.swift`).
//   - `related_songs` (computed server-side by shared writer/composer/
//     songbook vicinity — NOT curator-curated, so it's populated even on an
//     otherwise-uncurated `dev` deployment) returned genuine, rich data for
//     MP-0031 ("Amazing grace"): 10 other songs, each carrying a
//     human-readable `reason` field (e.g. `"Same writer: John Newton"`) that
//     `api-docs.yaml` documents as a bare `$ref: SongSummary` with NO
//     `reason` field at all, and — more importantly — the LIVE rows also
//     omit `songbookName`/`language`/`hasAudio`/`hasSheetMusic`/`publicId`
//     that a genuine `SongSummary` decode would require. `RelatedSongSummary`
//     below is therefore its OWN purpose-built shape, not a reuse of
//     `SongSummary` — decoding the real `related` array against the full
//     `SongSummary` type would throw on every row (missing required keys).
import Foundation

/// One song appearing in a `song_links` counterpart group (#807) — "this
/// songbook's copy of the exact same hymn."
///
/// ELI5: "The same song, but in a different hymnal."
public struct SongLinkedSong: Sendable, Hashable, Codable, Identifiable {
    public var id: SongID { songId }

    /// Wire key `"id"` — see `SongSummary.songId`'s identical rationale.
    public let songId: SongID

    public let title: String
    public let number: Int?

    /// Wire key `"songbook"`.
    public let songbookAbbreviation: String
    public let songbookName: String
    public let language: String

    /// Optional curator note on this specific counterpart link, e.g. why the
    /// two are considered the same hymn.
    public let note: String?

    /// Whether a curator has personally confirmed this link is correct.
    public let verified: Bool

    private enum CodingKeys: String, CodingKey {
        case songId = "id"
        case title
        case number
        case songbookAbbreviation = "songbook"
        case songbookName
        case language
        case note
        case verified
    }
}

/// The full `?action=song_links` response — a counterpart group id plus its
/// member songs (this song itself is NOT included in `songs`).
///
/// ELI5: "Every OTHER hymnal's copy of this exact same song" (may be empty —
/// most songs have no curated counterpart link yet).
public struct SongLinkGroup: Sendable, Hashable, Codable {
    /// `0` when this song has no counterparts (`api-docs.yaml`: "Link group
    /// ID (0 when the song has no counterparts)") — `hasCounterparts` below
    /// is the ready-to-use check UI code should actually branch on, since it
    /// also tolerates a hypothetical non-zero `groupId` with an empty
    /// `songs` array (defensive; never observed live, but cheap to guard).
    public let groupId: Int
    public let songs: [SongLinkedSong]

    /// Whether this song genuinely has at least one linked counterpart —
    /// the check `SongDetailView`'s "Also appears as" shelf uses to decide
    /// whether to render at all.
    public var hasCounterparts: Bool { groupId != 0 && !songs.isEmpty }
}

/// One song returned by `?action=related_songs` — matched server-side by
/// shared tag, writer, composer, or songbook vicinity.
///
/// ELI5: "Here's a song you might also like, and here's why."
public struct RelatedSongSummary: Sendable, Hashable, Codable, Identifiable {
    public var id: SongID { songId }

    /// Wire key `"id"`.
    public let songId: SongID

    public let title: String
    public let number: Int?

    /// Wire key `"songbook"`.
    public let songbookAbbreviation: String

    /// Human-readable match reason, e.g. `"Same writer: John Newton"` — the
    /// live payload always carries one, but kept `String?` defensively since
    /// `api-docs.yaml` doesn't document this field at all (see this file's
    /// header). `nil`-tolerant rather than defaulted to `""` so "no reason
    /// given" and "an intentionally blank reason" stay distinguishable.
    public let reason: String?

    private enum CodingKeys: String, CodingKey {
        case songId = "id"
        case title
        case number
        case songbookAbbreviation = "songbook"
        case reason
    }
}

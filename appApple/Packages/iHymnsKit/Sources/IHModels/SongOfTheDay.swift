// SongOfTheDay.swift
// IHModels
//
// ELI5: The "featured hymn for today" card the Home screen leads with — one
// song, a short heading like "Christmas Song of the Day," and its opening
// line as a teaser.
//
// DETAILED: Mirrors `?action=song_of_the_day` (#183, Apple P1's Home
// surface — `.claude/apple-native-strategy.md` §2.2/§2.1's "Song of the Day
// (hemisphere+country)" row), verified against two real pulls (2026-07-07):
//
//   curl "https://dev.ihymns.app/api?action=song_of_the_day"
//   → {"song":{"id":"GASD-0019","number":19,"title":"Бог Есть Любовь",
//      "songbook":"GASD","songbookName":"Гимн адвентистов седьмого дня",
//      "verified":false},"themeLabel":"Song of the Day",
//      "firstLine":"Бог есть любовь; лишь тот, кто Бога знает,"}
//
//   curl "https://ihymns.app/api?action=song_of_the_day&date=2026-12-25"
//   → same envelope shape, "themeLabel":"Christmas Song of the Day", a
//      different `song`.
//
// (`Tests/Fixtures/song_of_the_day.json` holds the first pull byte-for-byte;
// see `README.md` alongside it.) Both pulls confirm `api-docs.yaml`'s
// documented `songOfTheDay` response schema is accurate — unlike several
// sibling endpoints this package's other DTOs found stale (see
// `SongDetail.swift`/`Songbook.swift`'s own header comments) — so this type
// is modelled directly from that schema, cross-checked against the live
// bytes above.
//
// The card payload is deliberately its OWN, smaller shape — the YAML's own
// description calls it "a minimal card payload — exactly the fields the
// home card renders (not the shared SongSummary shape)" — so
// `SongOfTheDayCard` is intentionally NOT `SongSummary` reused: it has no
// `language`/`hasAudio`/`hasSheetMusic`/`publicId`, and decoding it as a
// `SongSummary` would either throw on those missing keys or silently invent
// values the server never actually sent. This is the same "model what's
// really there, don't reuse a shape that happens to overlap" call
// `RelatedSongSummary` already made for `?action=related_songs` (see
// `SongRelations.swift`).
//
// `song` is nullable: `api-docs.yaml` documents "song is null only if the
// catalogue is empty" — a real, if rare, deployment state (a fresh or
// misconfigured install) this type must decode cleanly rather than throw.
import Foundation

/// The minimal song payload `?action=song_of_the_day` embeds — "exactly the
/// fields the home card renders."
///
/// ELI5: Just enough about today's featured song to draw the hero card:
/// title, number, which book, and whether a curator has verified it.
public struct SongOfTheDayCard: Sendable, Hashable, Codable, Identifiable {
    /// Stable identity for SwiftUI — the parsed `SongID` itself.
    public var id: SongID { songId }

    /// Wire key `"id"` — same `<letters>-<digits>` shape/parsing as every
    /// other song id in this package (`SongID`'s own doc comment).
    public let songId: SongID

    /// `nil` for the same handful of manually-catalogued rows with no
    /// sequence number as `SongSummary.number`/`SongDetail.number`.
    public let number: Int?

    public let title: String

    /// The owning songbook's abbreviation. Wire key `"songbook"` — matches
    /// `SongSummary.songbookAbbreviation`'s own wire key, kept as the same
    /// Swift-side name here for consistency across this package's song
    /// shapes.
    public let songbookAbbreviation: String

    public let songbookName: String

    /// Whether a curator has personally verified this song's lyrics — the
    /// same flag `SongDetail.verified` carries for the full record.
    public let verified: Bool

    /// Creates a `SongOfTheDayCard`.
    ///
    /// DETAILED: A plain memberwise-style initializer (kept explicit, like
    /// `SongSummary`'s own — see that file's doc comment for why: a hand
    /// -written init is `public` by default, whereas the compiler-
    /// synthesized memberwise init for a `public` type is only `internal`
    /// and therefore unusable from `IHFeatures`, e.g. `SongOfTheDayHeroCard`'s
    /// `#Preview`).
    public init(
        songId: SongID,
        number: Int?,
        title: String,
        songbookAbbreviation: String,
        songbookName: String,
        verified: Bool
    ) {
        self.songId = songId
        self.number = number
        self.title = title
        self.songbookAbbreviation = songbookAbbreviation
        self.songbookName = songbookName
        self.verified = verified
    }

    private enum CodingKeys: String, CodingKey {
        case songId = "id"
        case number
        case title
        case songbookAbbreviation = "songbook"
        case songbookName
        case verified
    }
}

/// The full `?action=song_of_the_day` response envelope.
///
/// ELI5: "Here's today's featured song, what to call today's theme, and its
/// opening line as a teaser."
public struct SongOfTheDay: Sendable, Hashable, Codable {
    /// `nil` only when the whole catalogue is empty (`api-docs.yaml`'s
    /// documented `"200": … song is null only if the catalogue is empty`).
    /// A real, if rare, deployment state — `HomeView`'s hero card renders
    /// nothing at all in that case, the same "just hide it" posture
    /// `SongDetailView`'s own secondary shelves use for an empty/failed
    /// state, rather than a scary error card for something that isn't
    /// really an error.
    public let song: SongOfTheDayCard?

    /// The card's heading, e.g. `"Christmas Song of the Day"` on a themed
    /// date, or the neutral `"Song of the Day"` on an ordinary day —
    /// always present (never `nil`), even when `song` itself is.
    public let themeLabel: String

    /// The song's opening lyric line, shown as a teaser under the title —
    /// `""` when `song` is `nil` (the server always sends the key, per the
    /// live pull above, just empty rather than omitted).
    public let firstLine: String

    /// Creates a `SongOfTheDay` — see `SongOfTheDayCard.init`'s doc comment
    /// for why this is hand-written rather than relying on the
    /// compiler-synthesized (internal-only) memberwise initializer.
    public init(song: SongOfTheDayCard?, themeLabel: String, firstLine: String) {
        self.song = song
        self.themeLabel = themeLabel
        self.firstLine = firstLine
    }
}

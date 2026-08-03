// CreditPerson.swift
// IHModels
//
// ELI5: A hymn's writer, composer, arranger, translator, or artist — this
// file teaches Swift the shape of "everything we know about one of those
// people": their name, a short bio if we have one, when/where they were
// born and died, links to find them elsewhere on the web, and every song in
// the catalogue they're credited on.
//
// DETAILED: #1443/#1444 — the native app's first credit-person model. Mirrors
// the JSON `?action=credit_person` returns (`appWeb/public_html/api.php`'s
// `case 'credit_person'`, added alongside this file), which is itself a thin
// JSON twin of `includes/pages/person.php`'s existing HTML page — modelled
// field-for-field from that PHP source (`SongData::getCreditPerson()`), NOT
// a live-captured fixture (this is a brand-new endpoint with no prior
// traffic to record). Kept conservative the same way `Work.swift`'s
// never-live-verified corners are: every field that PHP could omit or send
// `null` is `Optional` here, and nothing is force-unwrapped.
//
// `tblCreditPeople` has NO direct foreign key from the six per-role credit
// tables (`tblSongWriters`/`tblSongComposers`/…) — a credit is a plain NAME
// STRING, matched case-sensitively against `tblCreditPeople.Name` (UNIQUE).
// This is why `SongDetail.writers`/`.composers`/etc. (`SongDetail.swift`)
// stay plain `[String]`, and why `CreditPersonLookup.name(_:)`
// (`CatalogEndpoints.swift`) exists at all — the "tap a composer name → see
// their songs" flow (#1444, #185's original ask) has only a NAME to look up
// with, never a stable id, until a curator has attached a `tblCreditPeople`
// row for that exact name.
import Foundation

/// One song this credit person is attributed on, within one role group.
///
/// ELI5: "One hymn this person wrote/composed/arranged/…" — just enough to
/// draw a list row and jump to the full song.
public struct CreditPersonSong: Sendable, Hashable, Codable, Identifiable {
    public var id: SongID { songId }

    /// Wire key `"songId"` (NOT `"id"`, unlike `SongSummary`/`SongDetail` —
    /// `SongData::getCreditPerson()` emits this field as `songId` because it
    /// sits inside a `discography[].songs[]` array rather than being the
    /// top-level record itself; kept exactly as the server sends it rather
    /// than force-fitting `SongSummary`'s own `CodingKeys` convention onto a
    /// genuinely different envelope).
    public let songId: SongID

    public let title: String

    /// `nil` for the handful of manually-catalogued rows with no sequence
    /// number — same real-world nullability as `SongSummary.number`.
    public let number: Int?

    /// Wire key `"songbook"` — the abbreviation, e.g. `"MP"`.
    public let songbookAbbreviation: String
    public let songbookName: String

    private enum CodingKeys: String, CodingKey {
        case songId, title, number
        case songbookAbbreviation = "songbook"
        case songbookName
    }
}

/// One role's worth of credited songs, e.g. every song this person is
/// credited as a WRITER on.
///
/// ELI5: A labelled shelf — "As Writer (12 songs)" — full of songs.
public struct CreditPersonRoleGroup: Sendable, Hashable, Codable, Identifiable {
    public var id: String { role }

    /// Stable, lower-case key: `"writer"`, `"composer"`, `"arranger"`,
    /// `"adaptor"`, `"translator"`, or `"artist"` — matches
    /// `SongData::getCreditPerson()`'s `$roleTables` keys exactly. Kept a
    /// plain `String` (not a Swift `enum`), same growable-vocabulary
    /// reasoning as `ExternalLink.category`'s own doc comment — a role this
    /// client doesn't recognise yet should still decode and render under
    /// its own server-supplied `label`, never throw.
    public let role: String

    /// Server-supplied display label, e.g. `"As Writer"` — rendered as-is
    /// rather than the client deriving one from `role`, so a new role added
    /// server-side needs no app update to read correctly.
    public let label: String

    public let songs: [CreditPersonSong]
}

/// The complete credit-person record: bio, lifespan, external links, and
/// every song they're credited on, grouped by role.
///
/// ELI5: Everything the app needs to render a writer/composer's page.
public struct CreditPerson: Sendable, Hashable, Codable {
    /// The `tblCreditPeople.Id`, when a registry row exists for this person
    /// — `nil` for a name-only lookup that matched credited songs but has
    /// no curated registry row yet (`SongData::getCreditPerson()`'s own
    /// "no row, but has songs" fallback, mirroring `includes/pages/
    /// person.php`'s identical behaviour for an as-yet-uncurated writer).
    public let id: Int?

    /// URL-safe identifier used by the web `/person/<slug>` route — `nil`
    /// under the same "no registry row yet" condition as `id`.
    public let slug: String?

    /// Display name — always present, either the registry row's `Name` or
    /// (when there's no row) the plain credited-name string the caller
    /// looked up with.
    public let name: String

    /// Free-text biography — `nil` when unset or no registry row exists.
    public let notes: String?

    public let birthPlace: String?

    /// Raw `YYYY-MM-DD` date string from the server, same "Phase 0, no
    /// dateDecodingStrategy chosen yet" reasoning `Work.createdAt` documents
    /// — never parsed into a `Date` at this layer.
    public let birthDate: String?
    public let deathPlace: String?
    public let deathDate: String?

    /// Special-case attribution (e.g. "Traditional," "Unknown") — rendered
    /// with the same italic treatment the web person page gives it.
    public let isSpecialCase: Bool

    /// A group/band/collective rather than one individual — flips
    /// "born/died" copy to "founded/active/dissolved" in the UI layer.
    public let isGroup: Bool

    /// Every song this person is credited on, grouped by role — empty when
    /// the name matches nothing in any of the six credit tables.
    public let discography: [CreditPersonRoleGroup]

    /// Curated external links (same shared shape as `Song`/`Songbook`/
    /// `Work` links) — empty on a pre-#833-migration deployment or a person
    /// with none curated yet.
    public let links: [ExternalLink]

    /// Count of DISTINCT songs across every role (a song credited to this
    /// person as BOTH writer and composer counts once) — the server
    /// computes this from a de-duplicated `SongId` set, not a naive sum of
    /// each role group's `songs.count` (which would double-count).
    public let totalSongs: Int

    /// IPI/ISNI-style authority identifiers (#1741 P2 `tblMusicianIdentifiers`,
    /// #1752 Slice D) — `nil` on a server response that predates this key
    /// (same staggered-deploy tolerance every other #1752 addition uses),
    /// otherwise an array that's simply empty when this person has none
    /// curated yet. Distinct from `SongDetail.royaltyIds`/`.externalIds` —
    /// this is the PERSON's own registry identity, not a per-song/recording
    /// one.
    public let identifiers: [MusicianIdentifier]?
}

/// One authority-registry identifier for a musician, e.g. an IPI Name
/// Number or an ISNI.
public struct MusicianIdentifier: Sendable, Hashable, Codable {
    /// Open vocabulary (app-validated, not a Swift `enum`) — same
    /// growable-vocabulary reasoning as `ExternalLink.category`: `ipi` |
    /// `isni` | `cae` | `ipi-base` | a PRO-specific id, and a type this
    /// client doesn't recognise yet should still decode and render.
    public let type: String
    public let value: String
}

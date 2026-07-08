// Work.swift
// IHModels
//
// ELI5: A "Work" groups together every songbook's version of the SAME
// underlying composition — e.g. "Amazing Grace" the tune, which shows up
// worded slightly differently across a dozen different hymnals. This file
// teaches Swift the shape of that grouping.
//
// DETAILED: Mirrors `#/components/schemas/Work` in `api-docs.yaml` (#840) —
// modelled directly from that OpenAPI contract because every live fixture
// this task recorded (`Tests/Fixtures/song_detail.json`, `songs_index.json`)
// happened to carry an EMPTY `works: []` (no song we pulled from `dev`
// belongs to a curated Work yet), so there is no live non-empty payload to
// verify field-for-field against. Kept intentionally conservative as a
// result: `songId` on `WorkMember` stays a plain `String` rather than the
// stricter `SongID` (see its doc comment below) precisely because this
// corner of the contract is unverified against a real response.
//
// #1443 FIX — reading the ACTUAL PHP source this task's backend survey
// required (`includes/SongData.php::_worksMap()`, the function that builds
// `SongDetail.works`) found a real, previously-undetected decode bug: each
// entry in the EMBEDDED `song_detail.works[]` array carries `id`/
// `parentId`/`title`/`slug`/`iswc`/`isCanonical`/`memberNote`/`members`/
// `links` but — unlike the STANDALONE `getWork()` response (`?action=work`,
// new in #1443) — never carries a `children` key at all (`_worksMap()`
// simply never sets one). Swift's synthesized `Decodable` conformance
// requires every non-Optional property's key to be PRESENT, so decoding any
// song belonging to a real Work would have thrown `.decoding` and blanked
// the ENTIRE `song_detail` response the moment a curator attached the first
// Work (#840 has shipped schema but, per this file's own header, zero live
// Work↔song associations existed to catch this until now). Fixed below with
// a hand-written `init(from:)` that treats `children` as `decodeIfPresent(
// ...) ?? []` — present-and-populated (the `getWork()` shape) decodes
// exactly as before; absent (the embedded shape) now degrades to "no known
// children" instead of throwing, matching how `parent`/`notes`/
// `createdAt`/`updatedAt` (already `Optional`) already tolerated absence.
import Foundation

/// A lightweight header reference to a Work — used both for `Work.parent`
/// (the Work this one nests under) and `Work.children` (direct child Works),
/// which `api-docs.yaml` documents as two separately-inlined object shapes
/// that happen to share every field. Modelled once here and reused for both
/// per the repo's modularity rule, rather than duplicating the same four
/// properties twice.
public struct WorkSummary: Sendable, Hashable, Codable, Identifiable {
    public let id: Int
    public let title: String
    public let slug: String

    /// International Standard Musical Work Code, shape `T-NNN.NNN.NNN-C` —
    /// `nil` for the many traditional/public-domain hymns that never had
    /// one assigned.
    public let iswc: String?
}

/// One song's membership in a Work — "this songbook's version of the piece."
public struct WorkMember: Sendable, Hashable, Codable {

    /// The member song's id, e.g. `"CP-0202"`.
    ///
    /// DETAILED: Deliberately `String`, not `SongID`. Every OTHER place this
    /// file's sibling DTOs reference a song id (`SongSummary.songId`) uses
    /// the strict, parse-validated `SongID` type — but `Work`/`WorkMember`
    /// have never been exercised against a real, non-empty API response
    /// (see the file header), so forcing `SongID`'s stricter parse here
    /// would risk a decode failure this task has no live evidence to rule
    /// out. Promote to `SongID` once a real populated `works` payload is
    /// recorded and verified.
    public let songId: String

    public let title: String

    /// `nil`-tolerant to match `SongSummary.number`'s same real-world
    /// nullability (a handful of catalogue rows have no sequence number).
    public let number: Int?

    public let songbookAbbreviation: String
    public let songbookName: String

    /// True for the curator-marked "preferred" version of the Work
    /// (typically zero or one member per Work has this set).
    public let isCanonical: Bool

    /// Optional context for this specific membership, e.g. `"1779 original"`.
    public let memberNote: String?

    private enum CodingKeys: String, CodingKey {
        case songId
        case title
        case number
        case songbookAbbreviation = "songbook"
        case songbookName
        case isCanonical
        case memberNote
    }
}

/// A composition grouping spanning multiple songbooks/arrangements/
/// translations of the same underlying piece (#840).
///
/// ELI5: "Every version of this song, across every hymnal that has it."
public struct Work: Sendable, Hashable, Codable, Identifiable {
    public let id: Int

    /// Self-FK enabling unlimited nesting (parent → child → grandchild).
    public let parentId: Int?

    public let title: String

    /// URL-safe identifier used by the web `/work/<slug>` route.
    public let slug: String

    /// International Standard Musical Work Code — `nil` for most hymns.
    public let iswc: String?

    public let notes: String?

    /// Header summary of the parent Work, when `parentId` is set.
    public let parent: WorkSummary?

    /// Direct children only (one level) — a deeper tree is walked
    /// client-side by re-fetching, not eagerly nested infinitely.
    public let children: [WorkSummary]

    /// Every song belonging to this Work, ordered server-side by
    /// `isCanonical` DESC then songbook/number.
    public let members: [WorkMember]

    /// External links attached to the Work itself (same shared shape as
    /// `Song`/`Songbook` links — see `ExternalLink.swift`).
    public let links: [ExternalLink]

    /// Kept as the server's raw ISO-8601 string rather than a parsed
    /// `Date` for Phase 0 — no `JSONDecoder.dateDecodingStrategy` has been
    /// chosen yet across IHAPI, and guessing one for a field this task
    /// never saw populated would be exactly the kind of unverified
    /// assumption this file's header warns against.
    public let createdAt: String?
    public let updatedAt: String?

    private enum CodingKeys: String, CodingKey {
        case id, parentId, title, slug, iswc, notes, parent, children, members, links, createdAt, updatedAt
    }

    /// Hand-written (rather than compiler-synthesized) SOLELY so `children`
    /// can tolerate a missing key — see this file's #1443 header comment for
    /// exactly which real server response omits it and why the synthesized
    /// conformance would have thrown for it. Every other property still
    /// decodes with the same semantics the synthesized initializer would
    /// have used (a plain `decode`/`decodeIfPresent` per its own
    /// `Optional`-ness); this is NOT a hand-rolled reinterpretation of the
    /// contract, just the one targeted tolerance.
    public init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(Int.self, forKey: .id)
        parentId = try container.decodeIfPresent(Int.self, forKey: .parentId)
        title = try container.decode(String.self, forKey: .title)
        slug = try container.decode(String.self, forKey: .slug)
        iswc = try container.decodeIfPresent(String.self, forKey: .iswc)
        notes = try container.decodeIfPresent(String.self, forKey: .notes)
        parent = try container.decodeIfPresent(WorkSummary.self, forKey: .parent)
        children = try container.decodeIfPresent([WorkSummary].self, forKey: .children) ?? []
        members = try container.decode([WorkMember].self, forKey: .members)
        links = try container.decode([ExternalLink].self, forKey: .links)
        createdAt = try container.decodeIfPresent(String.self, forKey: .createdAt)
        updatedAt = try container.decodeIfPresent(String.self, forKey: .updatedAt)
    }
}

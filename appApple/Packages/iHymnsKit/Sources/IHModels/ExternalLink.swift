// ExternalLink.swift
// IHModels
//
// ELI5: One button on a song/songbook/work page that sends you somewhere
// else on the web — Wikipedia, Spotify, IMSLP, an archive.org scan, a
// publisher's shop. This one small shape is shared by every entity that can
// carry a list of these, instead of each one growing its own copy.
//
// DETAILED: Mirrors `#/components/schemas/ExternalLink` in
// `appWeb/public_html/api-docs.yaml` (#833) and is genuinely reused
// server-side across `tblSongExternalLinks` / `tblSongbookExternalLinks` /
// `tblCreditPersonExternalLinks` / `tblWorkExternalLinks` — the repo's
// modularity rule ("when a piece of data exists in more than one place,
// extract it into a shared module") applies just as much to this Swift
// model layer as to the web/PHP side, so `Song.links`, `Songbook.links` and
// `Work.links` (see `SongDetail.swift`/`Songbook.swift`/`Work.swift`) all
// reuse THIS one type rather than each declaring their own.
import Foundation

/// One curated external link.
///
/// ELI5: A labelled button that opens some other website.
public struct ExternalLink: Sendable, Hashable, Codable {

    /// Stable registry slug, e.g. `"wikipedia"`, `"spotify"`, `"imslp"`.
    public let slug: String

    /// Display name shown on the button, e.g. `"Wikipedia"`.
    public let name: String

    /// Which "Find this … elsewhere" section this link is grouped under.
    ///
    /// DETAILED: `api-docs.yaml` documents a closed list (`official`,
    /// `information`, `listen`, `watch`, `read`, `sheet-music`, `purchase`,
    /// `authority`, `social`, `other`) but is kept a plain `String` here
    /// rather than a Swift `enum` — the *server's* own curator-facing type
    /// registry (`manage/external-link-types.php`) is explicitly a growable
    /// VARCHAR vocabulary, never an `ENUM` (`.claude/CLAUDE.md` rule #20), so
    /// a native `enum` here would just recreate the exact "value-add =
    /// ALTER/App-Store-release" trap the web side deliberately avoided. A
    /// category this code doesn't recognise yet should still decode and
    /// render (grouped under a generic fallback bucket by the UI layer),
    /// not throw.
    public let category: String

    /// Bootstrap-icons class for the button, e.g. `"bi-wikipedia"` — carried
    /// through as-is; native UI maps a handful of known classes to SF
    /// Symbols and falls back to a generic link glyph for the rest (Phase 1
    /// concern, not this DTO's job).
    public let iconClass: String

    /// The destination URL, as the server sent it.
    ///
    /// DETAILED: Kept as `String`, not `URL`, at the DTO boundary. `URL`
    /// wraps `Decodable` too, but a single malformed row would then fail
    /// the WHOLE `[ExternalLink]` array decode for a song/songbook/work —
    /// exactly the fragility this file's sibling `SongsIndexDecoding.swift`
    /// works around for `songs_index`. `resolvedURL` below is the
    /// best-effort, never-throwing accessor UI code should actually use.
    public let url: String

    /// Optional curator note, e.g. `"1779 first edition"`. Modelled
    /// `String?` (not defaulted to `""`) even though every observed live
    /// row so far used `""` rather than JSON `null` — `api-docs.yaml`
    /// documents this as `nullable: true`, and decoding an absent/`null`
    /// value to `nil` rather than silently coercing it to `""` keeps
    /// "no note" and "an intentionally empty note" distinguishable.
    public let note: String?

    /// Whether a curator has personally confirmed the URL still resolves.
    public let verified: Bool

    /// Within-category ordering hint (ties broken by server-side query
    /// order, which this DTO doesn't need to reproduce — arrays decode in
    /// the order the server already sorted them).
    public let sortOrder: Int

    /// Best-effort `URL` conversion of `url` — `nil` (never a thrown error)
    /// if the server ever sends a string `URL(string:)` can't parse.
    public var resolvedURL: URL? { URL(string: url) }
}

// SharedSetlist.swift
// IHModels
//
// ELI5: The "here's a public link anyone can open" version of a setlist —
// DIFFERENT from the private, per-user `Setlist` (`Setlist.swift`): this one
// has no owner-specific song metadata (just plain song id strings) and is
// fetched by a short public code, not an auth token.
//
// DETAILED: #181's LIVE-share half — the owner's brief: "a Share action →
// setlist_share → present the canonical web URL... owner edits reflect to
// link-holders; the app just shares the canonical link." Verified against
// the LIVE `appWeb/public_html/api.php` handlers (`case 'setlist_share'` /
// `case 'setlist_get'`, read 2026-07-07), which back a SEPARATE MySQL table
// (`tblSharedSetlists`) from `Setlist`'s `tblUserSetlists` — a share is a
// snapshot-or-live-link PROJECTION of a setlist, keyed by an 8-character hex
// id, never the same row. Two load-bearing shape differences from `Setlist`:
//   1. `songs` here is a bare `[String]` of raw ids, NOT `[SetlistSongEntry]`
//      objects — `sharedSetlistResolveWire()`'s wire shape carries no
//      title/songbook/number per song at all.
//   2. Those strings are NOT guaranteed to be valid `SongID`s. #1380's fix
//      (see `api.php`'s `setlist_share` handler comment block) deliberately
//      ALSO admits unsaved-draft ids shaped `song-XXXXXX` (mixed
//      letters+digits after the dash, which does NOT match `SongID`'s
//      `^[A-Za-z]+-[0-9]+$` pattern) alongside canonical `<letters>-<digits>`
//      ids — modelling this field as `[SongID]` would make the ENTIRE array
//      fail to decode the moment one draft id appeared. Kept as `[String]`;
//      `IHFeatures` resolves each entry to a `SongID` (dropping any that
//      don't parse) only where it actually needs one.
import Foundation

/// A publicly link-shared setlist, as returned by `?action=setlist_get` —
/// "public-safe fields only," per that endpoint's own doc comment (the
/// owner's anon UUID / any live-link user id are NEVER included).
///
/// ELI5: What you see when you open someone's shared setlist link.
public struct SharedSetlist: Sendable, Hashable, Codable, Identifiable {
    /// The 8-character hex share id, e.g. `"1a2b3c4d"` — also the value that
    /// appears in the canonical URL `https://ihymns.app/setlist/shared/<id>`.
    public let id: String

    public let name: String

    /// Ordered raw song id strings — see this file's header for why these
    /// are NOT modelled as `[SongID]`.
    public let songs: [String]

    /// Whether this share is a LIVE link to the owner's still-existing
    /// `Setlist` (edits there keep reflecting here) versus a frozen
    /// anonymous snapshot. Not in `api-docs.yaml`'s documented
    /// `SharedSetlist` schema at all, but every live `setlist_get` response
    /// carries it (`api.php`'s `'live' => $wire['live']` — #1380's live-link
    /// projection) — modelled here rather than silently dropped, since
    /// `IHFeatures`' share/view UI cares whether "open this link again
    /// later" will show fresh content or a fixed-in-time snapshot.
    public let live: Bool

    /// Raw ISO-8601-ish server timestamps — see `Setlist.swift`'s header for
    /// why these stay `String?` rather than a parsed `Date`. `nil`-tolerant
    /// because `api.php` itself sends `'created' => $meta['created'] ?? null`.
    public let created: String?
    public let updated: String?

    /// Optional per-song custom component-arrangement overrides, keyed by
    /// song id string (same reasoning as `songs` above for why the key is a
    /// raw `String`, not `SongID`) — only present in the response at all
    /// when at least one song in the share actually has one
    /// (`api.php`: `if (!empty($wire['arrangements'])) { ... }`).
    public let arrangements: [String: [Int]]?

    public init(
        id: String,
        name: String,
        songs: [String],
        live: Bool,
        created: String? = nil,
        updated: String? = nil,
        arrangements: [String: [Int]]? = nil
    ) {
        self.id = id
        self.name = name
        self.songs = songs
        self.live = live
        self.created = created
        self.updated = updated
        self.arrangements = arrangements
    }
}

/// `?action=setlist_share`'s response — `{id, url}`, nothing else.
///
/// ELI5: "Here's the short id, and the path to the shareable page."
public struct ShareSetlistResult: Sendable, Hashable, Codable {
    /// The freshly-minted (or reused, on update) 8-character hex share id.
    public let id: String

    /// A site-RELATIVE path, e.g. `"/setlist/shared/1a2b3c4d"` — `api.php`
    /// never returns a full URL. `canonicalURL` below is what
    /// `IHFeatures`' `ShareLink` actually presents (strategy §1.7: "every
    /// share surface must emit the canonical web URL, never a custom
    /// scheme").
    public let url: String

    public init(id: String, url: String) {
        self.id = id
        self.url = url
    }

    /// The full, absolute, shareable web URL — `https://ihymns.app` + `url`.
    /// `nil` only if `url` somehow isn't a valid URL path component (never
    /// observed from the real handler, which always emits
    /// `'/setlist/shared/' . $shareId` with `$shareId` already validated
    /// hex) — defensive rather than a force-unwrap, matching this package's
    /// posture everywhere else a server string becomes a `URL`.
    public var canonicalURL: URL? {
        URL(string: "https://ihymns.app\(url)")
    }
}

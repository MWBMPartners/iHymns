// Setlist.swift
// IHModels
//
// ELI5: A "setlist" is a little ordered playlist of songs the user has put
// together (e.g. "Sunday Morning Service") — this file teaches Swift the
// exact shape the server uses for one, so the rest of the app can create,
// reorder, and sync them without guessing.
//
// DETAILED: #181's setlists half. Verified field-for-field against the LIVE
// `appWeb/public_html/api.php` handlers (`case 'user_setlists'` /
// `case 'user_setlists_sync'`, read 2026-07-07) — NOT just `api-docs.yaml`
// (which agrees, `#/components/schemas/UserSetlist` /
// `#/components/schemas/SetlistSongEntry`), because the PHP is the actual
// wire contract. `?action=user_setlists` returns
// `{"setlists": [{"id","name","songs": [...], "createdAt","updatedAt"}]}`;
// `?action=user_setlists_sync` accepts/returns the same per-setlist shape,
// keyed by a CLIENT-generated `id` (`SetlistId` — `VARCHAR(100)`,
// `preg_replace('/[^a-zA-Z0-9_\-]/', '', ...)`-sanitised server-side, so any
// alphanumeric/`_`/`-` string works; `Setlist.makeId()` below produces one).
// This is a DIFFERENT feature from the anonymous/public "share a setlist by
// link" mechanism (`setlist_share`/`setlist_get`, `tblSharedSetlists`) — see
// `SharedSetlist.swift`'s own header for that half.
//
// `createdAt`/`updatedAt` are kept as raw `String?` rather than a parsed
// `Date`, mirroring `Work.swift`'s existing precedent for exactly the same
// reason: no `JSONDecoder.dateDecodingStrategy` has been chosen anywhere in
// this package yet, and the two server-side code paths that can populate
// these columns disagree on shape — `tblUserSetlists.CreatedAt`/`UpdatedAt`
// are MySQL `TIMESTAMP` columns (mysqli renders those `"YYYY-MM-DD
// HH:MM:SS"`, no `T`/offset) for a row nobody has ever re-synced, but
// `user_setlists_sync`'s own upsert falls back to `gmdate('c')` (ISO-8601
// WITH a `T`/offset, e.g. `"2026-07-07T12:34:56+00:00"`) whenever a caller's
// payload omits `createdAt`. Guessing a single `DateFormatter` for both
// would silently fail to decode one of them; keeping the raw string is
// honest about what's actually known, exactly like `Work.createdAt`.
import Foundation

/// One song within a user-owned setlist — the `SetlistSongEntry` wire shape.
///
/// ELI5: One line in the setlist: which song, and (optionally) which order
/// its verses/chorus should play in, if this setlist customised that.
///
/// DETAILED: Deliberately carries its OWN copy of `title`/`songbookAbbreviation`/
/// `number` rather than just a bare `SongID` — the server does too
/// (`api.php`'s `user_setlists_sync` handler stores `id`/`title`/`songbook`/
/// `number` verbatim per entry), so a setlist renders instantly offline with
/// no second `song_detail` round trip per song, the same reasoning
/// `FavoriteSong` documents for keeping its own display metadata. `number`
/// is non-optional here (unlike `SongSummary.number`) because the server
/// itself always emits an `Int` for it — `(int)($s['number'] ?? 0)` in the
/// PHP sync handler defaults a missing value to `0` rather than `null`.
public struct SetlistSongEntry: Sendable, Hashable, Codable, Identifiable {
    public var id: SongID { songId }

    /// Wire key `"id"` — the song this entry refers to.
    public let songId: SongID

    /// Display title, captured at add-time (never re-fetched just to render
    /// a setlist row).
    public let title: String

    /// Wire key `"songbook"` — the owning songbook's abbreviation.
    public let songbookAbbreviation: String

    /// The song's number within its songbook. `0` is the server's own
    /// "unknown/none" sentinel (see this type's header) rather than a
    /// genuine hymn numbered zero.
    public let number: Int

    /// An optional CUSTOM component-arrangement override for this song
    /// WITHIN this setlist (e.g. "repeat the chorus twice, skip verse 3") —
    /// an array of `SongComponent` indices, mirroring `SharedSetlist`'s
    /// per-song `arrangements` map (#1343-B/#1380's live-link arrangement
    /// feature) but stored inline per-entry here since a user setlist's
    /// `SongsJson` already models one row per song. `nil` when this song
    /// plays in its normal, unmodified component order.
    public let arrangement: [Int]?

    public init(
        songId: SongID,
        title: String,
        songbookAbbreviation: String,
        number: Int,
        arrangement: [Int]? = nil
    ) {
        self.songId = songId
        self.title = title
        self.songbookAbbreviation = songbookAbbreviation
        self.number = number
        self.arrangement = arrangement
    }

    /// Convenience: builds a setlist entry directly from an already-loaded
    /// `SongSummary` — the shape `SetlistCatalogueBrowserView` (IHFeatures)
    /// has on hand when the user picks a song to add to a setlist, and
    /// `SongDetailViewModel` has on hand via a loaded `SongDetail`.
    public init(summary: SongSummary) {
        self.init(
            songId: summary.songId,
            title: summary.title,
            songbookAbbreviation: summary.songbookAbbreviation,
            number: summary.number ?? 0
        )
    }

    /// A `SongSummary` view of this entry — lets `SetlistDetailView` reuse
    /// the shared `SongSummaryRow` (IHFeatures) instead of drawing its own
    /// row, per the repo's modularity rule — mirrors `FavoriteSong.summary`'s
    /// identical purpose. `number == 0` (this type's own "unknown" sentinel,
    /// see this type's header) maps to `SongSummary.number == nil` so
    /// `SongSummaryRow`'s `displayNumber` renders blank rather than a
    /// misleading literal "0".
    public var summary: SongSummary {
        SongSummary(songId: songId, title: title, songbookAbbreviation: songbookAbbreviation, number: number == 0 ? nil : number)
    }

    private enum CodingKeys: String, CodingKey {
        case songId = "id"
        case title
        case songbookAbbreviation = "songbook"
        case number
        case arrangement
    }
}

/// A user-owned setlist stored server-side — the `UserSetlist` wire shape
/// (`?action=user_setlists` / `?action=user_setlists_sync`).
///
/// ELI5: "Sunday Morning Service" and the ordered list of songs in it.
///
/// DETAILED: `Identifiable` via `id` (the client-generated `SetlistId`
/// string) directly — unlike `SongSummary`/`FavoriteSong`, there is no
/// `SongID` to wrap here; a setlist's identity is a free-standing string the
/// CLIENT mints once (`makeId()`) and the server never renames. `songs`
/// ORDER is significant and IS the setlist's play order — reordering a
/// setlist is exactly "produce a new `songs` array in the new order," no
/// separate position/index field needed (mirrors how the server itself has
/// no `SortOrder` column on `SongsJson` — the JSON array's own order is the
/// order, `api.php`'s `user_setlists_sync` round-trips it verbatim).
public struct Setlist: Sendable, Hashable, Codable, Identifiable {
    /// Client-generated, e.g. `"slist-4f9a2c1b"` — see `makeId()`.
    public let id: String

    public var name: String

    /// Ordered — this IS the setlist's song order (see this type's header).
    public var songs: [SetlistSongEntry]

    public let createdAt: String?
    public let updatedAt: String?

    public init(
        id: String,
        name: String,
        songs: [SetlistSongEntry] = [],
        createdAt: String? = nil,
        updatedAt: String? = nil
    ) {
        self.id = id
        self.name = name
        self.songs = songs
        self.createdAt = createdAt
        self.updatedAt = updatedAt
    }

    /// Mints a fresh, collision-safe client-generated setlist id.
    ///
    /// ELI5: "Give this brand-new setlist a unique name-tag before we've
    /// even told the server about it."
    ///
    /// DETAILED: `"slist-"` prefix matches `api-docs.yaml`'s own documented
    /// example (`slist-abc123`, `setlist_schedule_set`'s request schema) —
    /// purely a readability convention, not something the server requires
    /// (its only validation is the `[^a-zA-Z0-9_\-]` strip, `api.php`'s
    /// `user_setlists_sync` handler). A `UUID`'s 122 bits of randomness
    /// (minus the fixed version/variant bits) makes a same-device collision
    /// astronomically unlikely even without a server round trip to check —
    /// the same "trust client-generated randomness, don't round-trip to
    /// confirm uniqueness" posture `FavoriteSong`/`PendingFavoriteOp`
    /// already take for granted with `SongID`-keyed rows. Lowercased and
    /// hyphens stripped purely for a shorter, tidier id.
    public static func makeId() -> String {
        let raw = UUID().uuidString.lowercased().replacingOccurrences(of: "-", with: "")
        return "slist-\(raw.prefix(16))"
    }

    /// Whether `songId` already appears somewhere in this setlist — the
    /// check `AddToSetlistSheet` (IHFeatures) uses to show a checkmark
    /// instead of letting a song be added twice.
    public func contains(_ songId: SongID) -> Bool {
        songs.contains { $0.songId == songId }
    }
}

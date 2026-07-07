// FavoriteSong.swift
// IHModels
//
// ELI5: Two shapes for "a song the user has favourited." One is exactly what
// the SERVER sends/expects (just the song id + any tags) — the other is a
// richer, DEVICE-side shape that also remembers the song's title/songbook/
// number, so the Favourites screen can draw a row instantly without a second
// network round trip.
//
// DETAILED: Mirrors the `SongSummary` (API/domain) vs `CachedSongSummary`
// (IHPersistence row) split already established in this package — see that
// file's own header. Here the split happens ONE layer higher, inside
// IHModels itself, because BOTH `IHAPI` (encode/decode the wire shape) AND
// `IHPersistence` (persist the richer shape) need a shared, zero-dependency
// type to speak in (strategy §1.2: "IHModels — zero deps — the foundation").
//
// `?action=favorites` / `?action=favorites_sync` (WS-G #1019, verified
// against `appWeb/public_html/api.php`'s `case 'favorites'`/`case
// 'favorites_sync'` handlers, 2026-07-07) return/accept `{"id": "MP-1008",
// "tags": ["Easter"]}` objects — exactly `FavoriteEntry` below. Neither the
// GET nor the sync response carries a title, songbook name, or number at
// all: favouriting is server-side just a `(UserId, SongId)` row plus a JSON
// tag array (`tblUserFavorites`). Any richer metadata a screen wants to
// render (title, songbook, number) has to come from somewhere else — either
// a song the client already knows about (its own local record of favouriting
// it, captured from an already-loaded `SongDetail`) or, for a favourite
// pulled fresh from another device via `favorites_sync`'s first-login
// backfill, from a later catalogue lookup. `FavoriteSong` is that richer,
// device-side shape — see `AppRootViewModel+Favorites.swift` for how the two
// get reconciled.
import Foundation

/// The exact `{id, tags}` shape `?action=favorites`/`?action=favorites_sync`
/// send and accept — the WIRE contract, nothing more.
///
/// ELI5: What the server actually knows about one favourite: which song, and
/// what tags (if any) the user filed it under.
///
/// DETAILED: `Sendable`/`Hashable`/`Codable` for the same cross-actor-boundary
/// reasons every other IHModels DTO carries them (`SongID.swift`'s own doc
/// comment). Deliberately carries NO title/songbook/number — encoding one
/// straight back out (`favorites_sync`'s request body) would silently invent
/// fields the server's `FavoriteEntry` schema (`api-docs.yaml`) doesn't
/// define and doesn't want.
public struct FavoriteEntry: Sendable, Hashable, Codable, Identifiable {
    public var id: SongID { songId }

    /// Wire key `"id"` — same `<letters>-<digits>` shape as every other song
    /// id in this package.
    public let songId: SongID

    /// Per-favourite user tags, e.g. `["Easter", "Communion"]` — empty (never
    /// `nil`) when the user hasn't tagged this favourite, matching the
    /// server's own `'tags' => is_array($tags) ? array_values($tags) : []`
    /// normalisation (`api.php`'s `case 'favorites'`).
    public let tags: [String]

    public init(songId: SongID, tags: [String] = []) {
        self.songId = songId
        self.tags = tags
    }

    private enum CodingKeys: String, CodingKey {
        case songId = "id"
        case tags
    }

    /// Custom `init(from:)` (rather than the synthesized one) purely so a
    /// response row that happens to omit `tags` entirely — never observed
    /// live, but not ruled out by `api-docs.yaml`'s schema, which doesn't
    /// mark it `required` — still decodes to `[]` instead of throwing.
    /// Every OTHER field (`id`) IS required; a missing/malformed id is
    /// genuine contract drift and correctly still throws.
    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        songId = try container.decode(SongID.self, forKey: .songId)
        tags = try container.decodeIfPresent([String].self, forKey: .tags) ?? []
    }
}

/// The richer, DEVICE-side record of one favourited song — what
/// `IHPersistence.OfflineStore` caches and `IHFeatures.FavoritesView` renders.
///
/// ELI5: "You favourited this song — here's its title and where it lives in
/// the hymnal, so we can show a proper row without asking the server again."
///
/// DETAILED: `title`/`songbookAbbreviation` default to `""` and `number` to
/// `nil` for the one case this package can't avoid: a favourite pulled from
/// `?action=favorites_sync`'s first-login backfill (WS-G #1019's "merge"
/// mode) for a song this device has never itself opened/favourited before —
/// the wire `FavoriteEntry` that produced it simply doesn't carry a title.
/// `FavoritesView` treats an empty `title` as "not yet known" and falls back
/// to the raw `SongID` (see that file), rather than pretending to have data
/// it doesn't; the moment the user actually opens that song, `SongDetailView`
/// re-favourites it with the real metadata via `toggleFavorite`'s upsert,
/// self-healing the cached row (see `AppRootViewModel+Favorites.swift`).
public struct FavoriteSong: Sendable, Hashable, Codable, Identifiable {
    public var id: SongID { songId }

    public let songId: SongID
    public let title: String
    public let songbookAbbreviation: String
    public let number: Int?
    public let tags: [String]

    /// When this device first recorded the favourite — drives
    /// `FavoritesView`'s most-recent-first ordering, the same convention
    /// `RecentlyViewedSong.viewedAt` already uses.
    public let addedAt: Date

    public init(
        songId: SongID,
        title: String = "",
        songbookAbbreviation: String = "",
        number: Int? = nil,
        tags: [String] = [],
        addedAt: Date = Date()
    ) {
        self.songId = songId
        self.title = title
        self.songbookAbbreviation = songbookAbbreviation
        self.number = number
        self.tags = tags
        self.addedAt = addedAt
    }

    /// The bare wire shape this record would sync as — used whenever
    /// `IHAPI.APIClient.favoritesSync(mode:favorites:)` needs to tell the
    /// server about a LOCALLY known favourite (title/songbook/number never
    /// leave the device; the server has no column for them).
    public var asEntry: FavoriteEntry {
        FavoriteEntry(songId: songId, tags: tags)
    }

    /// A `SongSummary` view of this favourite — lets `FavoritesView` reuse
    /// the shared `SongSummaryRow` (IHFeatures) instead of drawing its own
    /// row, per the repo's modularity rule ("if a shared module already
    /// exists, reuse it").
    public var summary: SongSummary {
        SongSummary(songId: songId, title: title, songbookAbbreviation: songbookAbbreviation, number: number)
    }
}

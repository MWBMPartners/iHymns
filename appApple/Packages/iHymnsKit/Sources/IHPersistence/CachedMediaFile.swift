// CachedMediaFile.swift
// IHPersistence
//
// ELI5: The "receipt" for one song's downloaded audio/PDF/MIDI/MusicXML
// file — which song it belongs to, which attached file it is, what kind of
// file, and (privately) WHERE on this device the actual bytes live. The
// public-facing half of that receipt (`CachedMediaInfo`) is what the rest of
// the app sees; the private half (`relativePath`) never leaves
// `IHPersistence` at all.
//
// DETAILED: #1440 (offline media caching). Mirrors `CachedSongDetail.swift`'s
// exact "adapter at the seam" pattern — a GRDB-facing row type
// (`CachedMediaFile`) kept separate from what callers outside this module
// actually see (`CachedMediaInfo`) — but for a genuinely different reason
// than `CachedSongDetail`'s: there, the split is about not decoding a JSON
// blob just to list a title; HERE, the split is about not leaking
// `relativePath` — an on-disk implementation detail (this file's own
// `OfflineStore.swift` header explains why media is a FILE, not a blob
// column) — to any code outside `OfflineStore+MediaCache.swift`, which is
// the ONLY place that ever needs to turn a `relativePath` into an absolute
// `URL` (by joining it against `OfflineStore.mediaCacheDirectory`, itself
// `internal`). A caller outside this module should only ever be handed a
// READY-TO-USE absolute `URL` (`OfflineStore.cachedMediaURL(songId:
// mediaAssetId:)`), never a path fragment it would have to resolve itself.
import Foundation
import GRDB
import IHModels

/// A single row of the on-device `cached_media` table (#1440) — one
/// downloaded media file attached to a saved song.
///
/// ELI5: "This song's audio recording is cached, and here's the file kind/
/// name/size — but not WHERE exactly, that's `OfflineStore`'s own business."
struct CachedMediaFile: Codable, FetchableRecord, PersistableRecord, Sendable, Equatable {
    static let databaseTableName = "cached_media"

    /// The full `<letters>-<digits>` SongID string this file belongs to —
    /// paired with `mediaAssetId` as the composite primary key
    /// (`OfflineStore.swift`'s `v5CreateCachedMedia` migration), since one
    /// song can have several cached files (one audio recording, one PDF,
    /// several MIDI/MusicXML files — see `SongDetail+Media.swift`'s
    /// `midiAssets`/`musicXmlAssets` plurals).
    var songId: String

    /// `SongMediaAsset.id` — the specific attached file this cache entry
    /// mirrors. Re-downloading the SAME asset naturally upserts (never
    /// duplicates), mirroring `CachedSongDetail.songId`'s identical
    /// "primary key IS the upsert key" role.
    var mediaAssetId: Int

    /// `SongMediaAsset.Kind` (`"audio" | "sheet-music" | "midi" |
    /// "musicxml"`) — denormalised purely so a future query never needs to
    /// cross-reference `saved_song`'s own JSON blob just to know what kind
    /// of file this is.
    var kind: String

    var fileName: String
    var mimeType: String
    var sizeBytes: Int

    /// The cached file's path, RELATIVE to `OfflineStore.mediaCacheDirectory`
    /// — see this file's header for why this never leaves `IHPersistence`.
    var relativePath: String

    /// When this device last (re-)downloaded this file.
    var cachedAt: Date

    /// When this asset's freshness was last actually CONFIRMED against the
    /// server (#1450) — distinct from `cachedAt`: a `.stillFresh`
    /// revalidation decision (`MediaCacheRevalidationPolicy`) bumps THIS
    /// without touching the file or `cachedAt` at all. `nil` for any row a
    /// pre-#1450 app version wrote (the `v6AddMediaCacheLastValidated`
    /// migration adds this column with no backfill) — `toCachedMediaInfo()`
    /// below folds that `nil` to `cachedAt`, so an old row simply reads as
    /// "last validated when it was cached," which is both true and exactly
    /// the "due for a re-check" answer `MediaCacheRevalidationPolicy` should
    /// give it.
    var lastValidatedAt: Date?

    /// Converts this row into the public shape callers outside
    /// `IHPersistence` actually see — everything except `relativePath` (this
    /// file's header explains why that stays private).
    func toCachedMediaInfo() -> CachedMediaInfo? {
        guard let parsedSongId = SongID(rawValue: songId) else { return nil }
        return CachedMediaInfo(
            songId: parsedSongId,
            mediaAssetId: mediaAssetId,
            kind: kind,
            fileName: fileName,
            mimeType: mimeType,
            sizeBytes: sizeBytes,
            cachedAt: cachedAt,
            lastValidatedAt: lastValidatedAt ?? cachedAt
        )
    }
}

/// A cached media file's PUBLIC metadata — everything a caller outside
/// `IHPersistence` (e.g. `IHFeatures.OfflineStorageViewModel`) needs to list
/// or describe one cached file, without ever seeing (or needing) its
/// on-disk location.
///
/// ELI5: "This song has this file cached, this big, since this date" — never
/// "and here's exactly where the bytes are," which stays `OfflineStore`'s
/// own business.
public struct CachedMediaInfo: Sendable, Hashable, Identifiable {
    public var id: String { "\(songId.rawValue)#\(mediaAssetId)" }

    public let songId: SongID
    public let mediaAssetId: Int
    public let kind: String
    public let fileName: String
    public let mimeType: String
    public let sizeBytes: Int
    public let cachedAt: Date

    /// When this asset's freshness was last confirmed against the server
    /// (#1450) — see `CachedMediaFile.lastValidatedAt`'s doc comment for the
    /// full "distinct from `cachedAt`, and why a pre-#1450 row still gets a
    /// sensible value" explanation. `MediaCacheRevalidator` reads this
    /// directly as `MediaCacheRevalidationPolicy.decide(...)`'s
    /// `lastValidatedAt` argument.
    public let lastValidatedAt: Date

    public init(songId: SongID, mediaAssetId: Int, kind: String, fileName: String, mimeType: String, sizeBytes: Int, cachedAt: Date, lastValidatedAt: Date) {
        self.songId = songId
        self.mediaAssetId = mediaAssetId
        self.kind = kind
        self.fileName = fileName
        self.mimeType = mimeType
        self.sizeBytes = sizeBytes
        self.cachedAt = cachedAt
        self.lastValidatedAt = lastValidatedAt
    }
}

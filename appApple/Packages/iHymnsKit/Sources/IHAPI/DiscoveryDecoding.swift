// DiscoveryDecoding.swift
// IHAPI
//
// ELI5: Turns the raw bytes for "does this song have counterparts?" / "what
// else might I like?" into the `IHModels` Swift values the rest of the app
// uses — the `song_links`/`related_songs` equivalent of
// `SongsIndexDecoding.swift`.
//
// DETAILED: Kept in its own file (rather than folded into
// `SongsIndexDecoding.swift`) purely to mirror `DiscoveryEndpoints.swift`'s
// same split — one file per API "concern," not per HTTP verb or module.
// Neither decode here is lossy: both `song_links` and `related_songs` are
// per-song, small-cardinality reads (never the corpus), so a genuine shape
// mismatch is contract drift worth surfacing loudly via `.decoding`, exactly
// like `decodeSongDetail`/`decodeSongbooks` already do.
import Foundation
import IHModels

extension APIClient {
    /// Decodes a `song_links` response body into a `SongLinkGroup`.
    ///
    /// - Parameter data: The raw HTTP response body — a bare
    ///   `{"groupId": ..., "songs": [...]}` object (NOT wrapped in a further
    ///   envelope key, unlike `song_detail`'s `{"song": {...}}`).
    nonisolated public static func decodeSongLinks(from data: Data) throws -> SongLinkGroup {
        do {
            return try JSONDecoder().decode(SongLinkGroup.self, from: data)
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a `related_songs` response body into `[RelatedSongSummary]`.
    ///
    /// - Parameter data: The raw HTTP response body — `{"related": [...]}`.
    nonisolated public static func decodeRelatedSongs(from data: Data) throws -> [RelatedSongSummary] {
        struct Envelope: Decodable {
            let related: [RelatedSongSummary]
        }
        do {
            return try JSONDecoder().decode(Envelope.self, from: data).related
        } catch {
            throw APIError.decoding
        }
    }
}

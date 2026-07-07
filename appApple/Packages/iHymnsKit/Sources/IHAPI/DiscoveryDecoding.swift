// DiscoveryDecoding.swift
// IHAPI
//
// ELI5: Turns the raw bytes for "does this song have counterparts?" / "what
// else might I like?" / "what's today's featured song?" into the
// `IHModels` Swift values the rest of the app uses — the
// `song_links`/`related_songs`/`song_of_the_day` equivalent of
// `SongsIndexDecoding.swift`.
//
// DETAILED: Kept in its own file (rather than folded into
// `SongsIndexDecoding.swift`) purely to mirror `DiscoveryEndpoints.swift`'s
// same split — one file per API "concern," not per HTTP verb or module.
// None of the decodes here are lossy: `song_links`/`related_songs`/
// `song_of_the_day` are all per-song (or per-day), small-cardinality reads
// (never the corpus), so a genuine shape mismatch is contract drift worth
// surfacing loudly via `.decoding`, exactly like `decodeSongDetail`/
// `decodeSongbooks` already do.
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

    /// Decodes a `song_of_the_day` response body into a `SongOfTheDay`
    /// (#183).
    ///
    /// - Parameter data: The raw HTTP response body — a bare
    ///   `{"song": {...}|null, "themeLabel": ..., "firstLine": ...}` object
    ///   (like `song_links`, NOT wrapped in a further envelope key).
    nonisolated public static func decodeSongOfTheDay(from data: Data) throws -> SongOfTheDay {
        do {
            return try JSONDecoder().decode(SongOfTheDay.self, from: data)
        } catch {
            throw APIError.decoding
        }
    }
}

// SongsIndexDecoding.swift
// IHAPI
//
// ELI5: Turns the raw bytes the server sends back for "give me the song
// list" / "give me one full song" / "give me every songbook" into the
// `IHModels` Swift values the rest of the app actually uses.
//
// DETAILED: This is the concrete point where IHAPI depends on IHModels
// (the target dependency declared in `Package.swift`) — decoding each of
// the three Phase-0/#1397 catalog reads' response bodies into their DTOs.
// Kept as free functions on `APIClient` (`nonisolated`, pure) rather than
// baked directly into the actor-isolated network calls in `APIClient.swift`,
// so each can be exercised in `IHAPITests` against the real, committed
// fixtures in `Tests/Fixtures/` (`appApple-refresh-fixtures.sh` re-records
// them from `dev`) with zero networking.
//
// #1396 FIX — the original `decodeSongsIndex` assumed the response body
// was a bare JSON array (`[SongSummary]`). A real pull showed the server
// actually wraps it in a `{"songs": [...]}` envelope — decoding a bare
// array against that real shape would have thrown `.decoding` on every
// single live call. Fixed here, alongside a second real-payload problem the
// live pull surfaced: ~10 of the live catalogue's 16,084 rows have a
// legacy/manually-keyed `id` that does NOT match `SongID`'s
// `<letters>-<digits>` shape (see `Tests/Fixtures/README.md`). Decoding
// `[SongSummary]` directly would let ONE such row fail the decode of the
// entire array — unacceptable for the app's single offline-browse index.
// `LossyElement` below decodes each row independently and silently drops
// any that don't parse, so the ~16,000 good rows still load.
import Foundation
import IHModels

/// Decodes one element of a JSON array independently of its siblings,
/// swallowing (never propagating) a per-element decode failure.
///
/// ELI5: Tries to read one row; if that one row is garbled, shrugs and
/// moves on instead of throwing the whole page away.
///
/// DETAILED: A single-value container decode of `Wrapped` wrapped in `try?`
/// — if `Wrapped.init(from:)` throws for any reason (a malformed `SongID`,
/// a genuinely missing required field, ...), `decoded` is simply `nil`
/// rather than propagating the error up through the array's own decode.
/// This is the standard "lossy array" Codable pattern. Deliberately
/// module-visible (not `private` to this file) — `SearchDecoding.swift`'s
/// `decodeSearchResults(from:)` (#1436) reuses this SAME wrapper, since a
/// `search`/`search_num` hit is drawn from the exact same `SongSummary`
/// -shaped table and can carry the same occasional non-`SongID`-shaped
/// legacy row `songs_index` does; `song_detail`/`songbooks` are still
/// fetched/decoded whole (no `LossyElement` involved there) because a
/// malformed nested row in either of THOSE is a genuine contract-drift
/// signal worth surfacing as `.decoding`, not silently dropping.
struct LossyElement<Wrapped: Decodable>: Decodable {
    let decoded: Wrapped?
    init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        decoded = try? container.decode(Wrapped.self)
    }
}

extension APIClient {
    /// Decodes a `songs_index` response body into `[SongSummary]`.
    ///
    /// ELI5: Bytes in, list of songs out — a handful of unparsable legacy
    /// rows are quietly skipped rather than blanking the whole list.
    ///
    /// - Parameter data: The raw HTTP response body — a JSON object shaped
    ///   `{"songs": [{...}, ...]}` (NOT a bare array; see file header).
    /// - Throws: `APIError.decoding` if the body doesn't even match that
    ///   outer envelope shape (e.g. the `songs` key itself is missing or
    ///   renamed — real contract drift, as opposed to one bad row).
    nonisolated public static func decodeSongsIndex(from data: Data) throws -> [SongSummary] {
        struct Envelope: Decodable {
            let songs: [LossyElement<SongSummary>]
        }
        do {
            let envelope = try JSONDecoder().decode(Envelope.self, from: data)
            return envelope.songs.compactMap(\.decoded)
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a `song_detail` (alias `song_data`) response body into a
    /// single `SongDetail`.
    ///
    /// - Parameter data: The raw HTTP response body — `{"song": {...}}`.
    /// - Throws: `APIError.decoding` on any shape mismatch. Unlike
    ///   `decodeSongsIndex`, this is NOT lossy — a single song fetched by an
    ///   id the caller already validated (from a `SongSummary` or a parsed
    ///   deep link) failing to decode is a genuine contract-drift bug worth
    ///   surfacing loudly, not a "one bad row among thousands" situation.
    nonisolated public static func decodeSongDetail(from data: Data) throws -> SongDetail {
        struct Envelope: Decodable {
            let song: SongDetail
        }
        do {
            return try JSONDecoder().decode(Envelope.self, from: data).song
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a `songbooks` response body into `[Songbook]`.
    ///
    /// - Parameter data: The raw HTTP response body — `{"songbooks": [...]}`.
    /// - Throws: `APIError.decoding` on any shape mismatch. Not lossy: the
    ///   live catalogue's 54 songbooks all decoded cleanly (`Songbook.id` is
    ///   a plain abbreviation string, not a `SongID` — there's no per-row
    ///   parse step that could selectively fail the way `songs_index`'s
    ///   `SongID` parse can), so a decode failure here means the contract
    ///   itself drifted.
    nonisolated public static func decodeSongbooks(from data: Data) throws -> [Songbook] {
        struct Envelope: Decodable {
            let songbooks: [Songbook]
        }
        do {
            return try JSONDecoder().decode(Envelope.self, from: data).songbooks
        } catch {
            throw APIError.decoding
        }
    }
}

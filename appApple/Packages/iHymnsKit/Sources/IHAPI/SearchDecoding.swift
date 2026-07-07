// SearchDecoding.swift
// IHAPI
//
// ELI5: Turns the raw bytes the server sends back for "search for this" /
// "search by number" into the same `IHModels` song rows the rest of the app
// already knows how to display.
//
// DETAILED: #1436's "wired for completeness/tests" server search client.
// `?action=search` and `?action=search_num` both answer with the SAME
// `{"results": [...]}` envelope shape (`api-docs.yaml`'s `searchSongs`/
// `searchByNumber` operations), each row itself `SongSummary`-shaped — so
// one decode function covers both actions, exactly like
// `SongsIndexDecoding.swift`'s `decodeSongsIndex` covers `songs_index`.
// Reuses that SAME file's `LossyElement` wrapper (relaxed from `private` to
// module-visible for this purpose) rather than re-declaring an identical
// lossy-array helper here — a search hit is drawn from the exact same
// table `songs_index` reads, so it can carry the same occasional
// non-`SongID`-shaped legacy row.
import Foundation
import IHModels

extension APIClient {
    /// Decodes a `search`/`search_num` response body into `[SongSummary]`.
    ///
    /// - Parameter data: The raw HTTP response body — `{"results": [...]}`.
    /// - Throws: `APIError.decoding` if the outer `results` envelope itself
    ///   doesn't match (a genuine contract-drift signal); an individual
    ///   malformed row is silently skipped instead, same as
    ///   `decodeSongsIndex`.
    nonisolated public static func decodeSearchResults(from data: Data) throws -> [SongSummary] {
        struct Envelope: Decodable {
            let results: [LossyElement<SongSummary>]
        }
        do {
            let envelope = try JSONDecoder().decode(Envelope.self, from: data)
            return envelope.results.compactMap(\.decoded)
        } catch {
            throw APIError.decoding
        }
    }
}

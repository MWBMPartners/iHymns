// SongsIndexDecoding.swift
// IHAPI
//
// ELI5: Turns the raw bytes the server sends back for "give me the song
// list" into an array of `SongSummary` Swift values the rest of the app can
// actually use.
//
// DETAILED: This is the concrete point where IHAPI depends on IHModels
// (the target dependency declared in `Package.swift`) — decoding a
// `songs_index` response body into `[SongSummary]`. Kept as a free function
// on `APIClient` (`nonisolated`, pure) rather than baked directly into an
// actor-isolated network call, so it can be exercised in
// `IHAPITests/SongsIndexDecodingTests.swift` against a small embedded JSON
// fixture with zero networking — the same pattern strategy §3.3 describes
// scaling up to real "contract tests decoding committed live fixtures"
// once `appApple/Tests/Fixtures/` exists (Phase 0 item "IHModels + first
// contract fixtures").
import Foundation
import IHModels

extension APIClient {
    /// Decodes a `songs_index` response body into `[SongSummary]`.
    ///
    /// ELI5: Bytes in, list of songs out.
    ///
    /// - Parameter data: The raw HTTP response body (expected to be a JSON
    ///   array of song-summary objects).
    /// - Throws: `APIError.decoding` if the body doesn't match the
    ///   expected shape — never lets a raw `DecodingError` leak past IHAPI's
    ///   boundary, so every caller only ever needs to handle the one
    ///   `APIError` taxonomy (strategy §1.5).
    nonisolated public static func decodeSongsIndex(from data: Data) throws -> [SongSummary] {
        do {
            return try JSONDecoder().decode([SongSummary].self, from: data)
        } catch {
            throw APIError.decoding
        }
    }
}

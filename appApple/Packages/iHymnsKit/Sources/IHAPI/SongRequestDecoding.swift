// SongRequestDecoding.swift
// IHAPI
//
// ELI5: Turns the raw bytes for "your missing-song request was received"
// into the `IHModels.SongRequestResult` Swift value the rest of the app
// uses.
//
// DETAILED: #1447. Kept in its own file, sibling to
// `WorkAndCreditPersonDecoding.swift` — a genuinely different concern (a
// community submission's response, not a catalogue read).
import Foundation
import IHModels

extension APIClient {
    /// Decodes a `song_request` response body into a `SongRequestResult`.
    ///
    /// - Parameter data: The raw HTTP response body — a bare
    ///   `{"ok": true, "id": ...}` object (NOT wrapped in a further
    ///   envelope key, matching `song_links`/`song_of_the_day`'s shape).
    nonisolated public static func decodeSongRequestResult(from data: Data) throws -> SongRequestResult {
        do {
            return try JSONDecoder().decode(SongRequestResult.self, from: data)
        } catch {
            throw APIError.decoding
        }
    }
}

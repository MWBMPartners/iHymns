// WorkAndCreditPersonDecoding.swift
// IHAPI
//
// ELI5: Turns the raw bytes for "one Work" / "one credit person" into the
// `IHModels` Swift values the rest of the app uses — the `work`/
// `credit_person` equivalent of `SongsIndexDecoding.swift`.
//
// DETAILED: Kept in its own file, mirroring `DiscoveryDecoding.swift`'s
// split from `SongsIndexDecoding.swift` — one file per API "concern."
// `work`/`credit_person` are per-record reads a caller already navigated
// to deliberately (a resolved deep link, or a tapped name) — a genuine
// decode failure is contract drift worth surfacing loudly via `.decoding`,
// the SAME non-lossy posture `decodeSongDetail` already takes, not a
// "silently drop one bad row" situation. `song_request`'s decode (#1447)
// lives in its own sibling `SongRequestDecoding.swift`.
import Foundation
import IHModels

extension APIClient {
    /// Decodes a `work` response body into a `Work`.
    ///
    /// - Parameter data: The raw HTTP response body — `{"work": {...}}`.
    nonisolated public static func decodeWork(from data: Data) throws -> Work {
        struct Envelope: Decodable {
            let work: Work
        }
        do {
            return try JSONDecoder().decode(Envelope.self, from: data).work
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a `credit_person` response body into a `CreditPerson`.
    ///
    /// - Parameter data: The raw HTTP response body — `{"person": {...}}`.
    nonisolated public static func decodeCreditPerson(from data: Data) throws -> CreditPerson {
        struct Envelope: Decodable {
            let person: CreditPerson
        }
        do {
            return try JSONDecoder().decode(Envelope.self, from: data).person
        } catch {
            throw APIError.decoding
        }
    }
}

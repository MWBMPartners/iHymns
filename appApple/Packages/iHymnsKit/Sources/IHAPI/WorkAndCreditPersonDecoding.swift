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

    /// Decodes a `musician` response body into a `CreditPerson`.
    ///
    /// - Parameter data: The raw HTTP response body — `{"musician": {...}}`.
    ///
    /// #1752 Slice D UPDATE (#1741 P2-B) — the envelope key changed from
    /// `person` to `musician` alongside `Endpoint.creditPerson(_:)`'s switch
    /// to the canonical action (`WorkAndCreditPersonEndpoints.swift`); both
    /// changes must move together — a client requesting `action=musician`
    /// but still decoding a `person` key would throw `.decoding` on every
    /// real response. The `credit_person`/`{"person":{…}}` legacy shape is
    /// permanently frozen server-side but has no live decoder left in this
    /// client — see `api.php`'s comment on why that's fine (old already-
    /// shipped binaries are the only consumers left).
    nonisolated public static func decodeCreditPerson(from data: Data) throws -> CreditPerson {
        struct Envelope: Decodable {
            let musician: CreditPerson
        }
        do {
            return try JSONDecoder().decode(Envelope.self, from: data).musician
        } catch {
            throw APIError.decoding
        }
    }
}

// AppStatusDecoding.swift
// IHAPI
//
// ELI5: Turns the raw bytes `?action=app_status` sends back into the
// `AppStatus` Swift value the rest of the app uses.
//
// DETAILED: Mirrors `DiscoveryDecoding.swift`'s pattern exactly — a
// `nonisolated static` decode function on `APIClient`, independently
// unit-testable with zero networking, PLUS the one typed read method
// (`appStatus()`) that calls it. Unlike `song_links`/`song_of_the_day`
// (also bare, un-enveloped objects), `AppStatus`'s own `Decodable`
// conformance is deliberately TOLERANT — see `IHModels/AppStatus.swift`'s
// header for why an absent `captcha` key must decode to `nil`, never throw
// — so `.decoding` here only ever fires on genuinely non-JSON bytes (a
// maintenance HTML page slipping through, say), not on a dormant install's
// normal response.
import Foundation
import IHModels

extension APIClient {
    /// `?action=app_status` — the app's first, launch-time read of the
    /// small bag of dormant/optional flags this package models (today:
    /// only `captcha`, `.claude/captcha-native-and-outage-plan.md` §2.3-1).
    ///
    /// ELI5: "Anything I should know about before I try to do anything?"
    public func appStatus() async throws -> AppStatus {
        let data = try await performIdempotentGET(.appStatus)
        return try Self.decodeAppStatus(from: data)
    }

    /// Decodes an `app_status` response body into an `AppStatus`.
    ///
    /// - Parameter data: The raw HTTP response body — the bare top-level
    ///   object itself (NOT wrapped in a further envelope key, matching
    ///   `decodeSongLinks`/`decodeSongOfTheDay`'s precedent).
    nonisolated public static func decodeAppStatus(from data: Data) throws -> AppStatus {
        do {
            return try JSONDecoder().decode(AppStatus.self, from: data)
        } catch {
            throw APIError.decoding
        }
    }
}

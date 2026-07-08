// APIClient+WorkAndCreditPerson.swift
// IHAPI
//
// ELI5: The "give me one Work / one credit person" phone calls — layered on
// top of the SAME `APIClient` actor every other read/write already uses,
// just split into their own file.
//
// DETAILED: Split out of `APIClient.swift` (already at the repo's 400-line
// LOC-budget tripwire, `Scripts/loc-budget.sh`) the same way
// `APIClient+Discovery.swift`/`APIClient+Auth.swift` were. Both are
// idempotent public GETs, so they go through the retrying
// `performIdempotentGET` every other catalogue read uses. The sibling
// `submitSongRequest(...)` (#1447) lives in its own `APIClient+SongRequest
// .swift` — a genuinely different concern (a non-idempotent community
// submission, never auto-retried) that happens to have landed in the same
// task.
import Foundation
import IHModels

extension APIClient {
    /// `?action=work&slug=…` — one Work (composition grouping), with member
    /// songs, parent/children headers, and external links (#840, #1443).
    ///
    /// ELI5: "Give me everything about this one composition grouping."
    public func work(slug: String) async throws -> Work {
        let data = try await performIdempotentGET(.work(slug: slug))
        return try Self.decodeWork(from: data)
    }

    /// `?action=credit_person` — one credit person's bio/lifespan/external
    /// links plus every song they're credited on, grouped by role (#1443,
    /// #1444).
    ///
    /// ELI5: "Give me everything about this one writer/composer."
    public func creditPerson(_ lookup: CreditPersonLookup) async throws -> CreditPerson {
        let data = try await performIdempotentGET(.creditPerson(lookup))
        return try Self.decodeCreditPerson(from: data)
    }
}

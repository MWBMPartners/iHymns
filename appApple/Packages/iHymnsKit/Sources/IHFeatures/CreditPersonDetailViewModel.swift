// CreditPersonDetailViewModel.swift
// IHFeatures
//
// ELI5: The little helper behind the "one writer/composer" screen — asks
// the server for that person's bio and every song they're credited on, and
// remembers whether that's still loading, succeeded, or failed.
//
// DETAILED: #1443/#1444 — native detail screens for Work / credit-person /
// Live-join Universal Links, AND the "writer/composer detail pages" #185
// deferred sub-scope. THE SAME screen satisfies both issues (see
// `CreditPersonDetailView.swift`'s header) — mirrors `WorkDetailViewModel`'s
// identical shape, just parameterised over `CreditPersonLookup` (slug/id/
// name) instead of a bare slug, since a credit name tapped on a song has no
// stable id to look up with (`CreditPersonLookup.name(_:)`'s own doc
// comment, `IHAPI/WorkAndCreditPersonEndpoints.swift`).
import Foundation
import IHAPI
import IHModels
import Observation

/// Loads and holds one credit person's full record.
///
/// ELI5: "Go get everything about this one writer/composer."
@MainActor
@Observable
public final class CreditPersonDetailViewModel {
    public private(set) var loadState: LoadState<CreditPerson> = .idle

    private let lookup: CreditPersonLookup
    private let rootViewModel: AppRootViewModel

    public init(lookup: CreditPersonLookup, rootViewModel: AppRootViewModel) {
        self.lookup = lookup
        self.rootViewModel = rootViewModel
    }

    public func loadIfNeeded() async {
        guard case .idle = loadState else { return }
        await load()
    }

    public func load() async {
        loadState = .loading
        do {
            let person = try await rootViewModel.creditPerson(lookup)
            loadState = .loaded(person)
        } catch let error as APIError {
            loadState = .error(error.userFacingMessage)
        } catch {
            loadState = .error("Something went wrong loading this person. Please try again.")
        }
    }
}

// WorkDetailViewModel.swift
// IHFeatures
//
// ELI5: The little helper behind the "one Work" screen — asks the server
// for one composition grouping (title, member songs across songbooks,
// parent/child groupings, links) and remembers whether that's still
// loading, succeeded, or failed.
//
// DETAILED: #1443 — native detail screens for Work / credit-person /
// Live-join Universal Links. Mirrors `SongDetailViewModel`'s exact shape
// (per-screen, constructed fresh by each `WorkDetailView`, `LoadState`-
// driven, delegates every network call to `AppRootViewModel` rather than
// holding an `APIClient` of its own — there is exactly ONE `APIClient`
// instance per app run). Deliberately much simpler than
// `SongDetailViewModel`: a Work has no secondary shelves, no favourites, no
// offline-save affordance today — just one fetch.
import Foundation
import IHAPI
import IHModels
import Observation

/// Loads and holds one Work's full record.
///
/// ELI5: "Go get everything about this one composition grouping."
@MainActor
@Observable
public final class WorkDetailViewModel {
    public private(set) var loadState: LoadState<Work> = .idle

    private let slug: String
    private let rootViewModel: AppRootViewModel

    public init(slug: String, rootViewModel: AppRootViewModel) {
        self.slug = slug
        self.rootViewModel = rootViewModel
    }

    /// Fetches, but only if it hasn't already been fetched (or isn't
    /// currently mid-fetch) — safe to call from `.task {}` on every
    /// re-appearance without re-issuing the network call each time.
    public func loadIfNeeded() async {
        guard case .idle = loadState else { return }
        await load()
    }

    /// Forces a (re)fetch, regardless of current state — `IHLoadErrorView`'s
    /// retry button calls this directly.
    public func load() async {
        loadState = .loading
        do {
            let work = try await rootViewModel.work(slug: slug)
            loadState = .loaded(work)
        } catch let error as APIError {
            loadState = .error(error.userFacingMessage)
        } catch {
            loadState = .error("Something went wrong loading this work. Please try again.")
        }
    }
}

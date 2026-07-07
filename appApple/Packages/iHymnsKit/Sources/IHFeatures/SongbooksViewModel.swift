// SongbooksViewModel.swift
// IHFeatures
//
// ELI5: The little helper behind the Songbooks screen — asks the server
// for every songbook and remembers whether that's still loading, succeeded,
// or failed.
//
// DETAILED: #1437 (Apple P1 Songbooks browse). Mirrors
// `SongDetailViewModel`'s shape exactly: a per-screen view model
// (constructed fresh by `SongbooksView`, unlike the single app-wide
// `AppRootViewModel`) that delegates the actual network call to
// `AppRootViewModel.songbooks()` rather than holding an `APIClient` of its
// own — there is exactly ONE `APIClient` instance per app run (owned by
// `AppRootViewModel`), and every screen that needs a network call reaches
// it through that one root view model.
import IHAPI
import IHModels
import Observation

/// Loads and holds every songbook in the catalogue.
///
/// ELI5: "Go get the list of hymnals."
@MainActor
@Observable
public final class SongbooksViewModel {
    /// The current load state of the songbook list.
    public private(set) var loadState: LoadState<[Songbook]> = .idle

    private let rootViewModel: AppRootViewModel

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
    }

    /// Fetches the songbook list, but only if it hasn't already been
    /// fetched (or isn't currently mid-fetch) — safe to call from
    /// `SongbooksView`'s `.task {}` on every re-appearance without
    /// re-issuing the network call each time.
    ///
    /// ELI5: "Get the hymnal list, but only if we don't already have it."
    public func loadIfNeeded() async {
        guard case .idle = loadState else { return }
        await load()
    }

    /// Forces a songbook-list (re)fetch regardless of current state — the
    /// hook a future manual "retry"/pull-to-refresh action calls into.
    ///
    /// ELI5: "Go get the hymnal list right now, even if we already tried."
    public func load() async {
        loadState = .loading
        do {
            let books = try await rootViewModel.songbooks()
            loadState = .loaded(books)
        } catch let error as APIError {
            loadState = .error(error.userFacingMessage)
        } catch {
            loadState = .error("Something went wrong loading songbooks. Please try again.")
        }
    }
}

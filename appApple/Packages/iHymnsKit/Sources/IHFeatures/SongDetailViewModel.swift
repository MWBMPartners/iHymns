// SongDetailViewModel.swift
// IHFeatures
//
// ELI5: The little helper behind the "one full song" screen — asks for the
// song's full record over the network and remembers whether that's still
// loading, succeeded, or failed.
//
// DETAILED: A per-song, per-screen view model (constructed fresh by each
// `SongDetailView`, unlike the single app-wide `AppRootViewModel`) — mirrors
// the same `LoadState`-driven shape `AppRootViewModel.loadCatalogue()` uses
// for the list, but scoped to exactly one `SongDetail` rather than the
// whole catalogue. Delegates the actual network call to
// `AppRootViewModel.songDetail(id:)` (#1399) rather than holding an
// `APIClient` of its own — there is exactly ONE `APIClient` instance per
// app run (owned by `AppRootViewModel`), and every screen that needs a
// network call reaches it through that one root view model.
import IHAPI
import IHModels
import Observation

/// Loads and holds ONE song's full record.
///
/// ELI5: "Go get this one song's lyrics and everything else about it."
@MainActor
@Observable
public final class SongDetailViewModel {
    /// The current load state of this song's full record.
    public private(set) var loadState: LoadState<SongDetail> = .idle

    private let songId: SongID
    private let rootViewModel: AppRootViewModel

    public init(songId: SongID, rootViewModel: AppRootViewModel) {
        self.songId = songId
        self.rootViewModel = rootViewModel
    }

    /// Fetches the song, but only if it hasn't already been fetched (or
    /// isn't currently mid-fetch) — safe to call from `.task {}` on every
    /// re-appearance without re-issuing the network call each time.
    public func loadIfNeeded() async {
        guard case .idle = loadState else { return }
        await load()
    }

    /// Forces a (re)fetch regardless of current state.
    public func load() async {
        loadState = .loading
        do {
            let detail = try await rootViewModel.songDetail(id: songId)
            loadState = .loaded(detail)
        } catch let error as APIError {
            loadState = .error(error.userFacingMessage)
        } catch {
            loadState = .error("Something went wrong loading this song. Please try again.")
        }
    }
}

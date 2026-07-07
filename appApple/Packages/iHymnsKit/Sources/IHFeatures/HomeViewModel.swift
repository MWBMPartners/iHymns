// HomeViewModel.swift
// IHFeatures
//
// ELI5: The little helper behind the Home screen — asks for today's
// featured song over the network and remembers whether that's still
// loading, succeeded, or failed; also hands `HomeView` the "Continue
// Reading" song and the "Recently Viewed" shelf list, both read straight
// from `AppRootViewModel` (this view model owns no persistence of its own).
//
// DETAILED: #183 (Apple P1 Home surface: "SOTD hero + resume + live banner
// + favourites shelf" per strategy §2.2, narrowed by this task's own brief
// to hero + resume + a browse-songbooks shelf + a recently-viewed shelf —
// see `HomeView.swift`'s header for why a live banner/favourites shelf
// aren't built here). Mirrors `SongbooksViewModel`'s shape exactly: a
// per-screen view model (constructed fresh by `HomeView`, unlike the single
// app-wide `AppRootViewModel`) that delegates the actual network call to
// `AppRootViewModel.songOfTheDay()` rather than holding an `APIClient` of
// its own — there is exactly ONE `APIClient` instance per app run (owned by
// `AppRootViewModel`), and every screen that needs a network call reaches
// it through that one root view model.
import IHAPI
import IHModels
import Observation

/// Loads today's Song of the Day and surfaces the "Continue Reading"/
/// "Recently Viewed" state Home renders alongside it.
///
/// ELI5: "Go get today's featured song, and tell me what I was reading
/// last."
@MainActor
@Observable
public final class HomeViewModel {
    /// The current load state of today's Song of the Day.
    public private(set) var songOfTheDayState: LoadState<SongOfTheDay> = .idle

    private let rootViewModel: AppRootViewModel

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
    }

    /// Fetches the Song of the Day, but only if it hasn't already been
    /// fetched (or isn't currently mid-fetch) — safe to call from
    /// `HomeView`'s `.task {}` on every re-appearance (e.g. switching back
    /// to the Home tab) without re-issuing the network call each time.
    ///
    /// ELI5: "Get today's featured song, but only if we don't already have
    /// it."
    public func loadIfNeeded() async {
        guard case .idle = songOfTheDayState else { return }
        await load()
    }

    /// Forces a Song-of-the-Day (re)fetch regardless of current state — the
    /// hook a future manual "retry"/pull-to-refresh action calls into.
    public func load() async {
        songOfTheDayState = .loading
        do {
            songOfTheDayState = .loaded(try await rootViewModel.songOfTheDay())
        } catch let error as APIError {
            songOfTheDayState = .error(error.userFacingMessage)
        } catch {
            songOfTheDayState = .error("Something went wrong loading today's song. Please try again.")
        }
    }

    /// The most recently opened song, if any — "Continue Reading"'s data
    /// source.
    ///
    /// ELI5: "What was I reading last?"
    ///
    /// DETAILED: A computed property reading straight from
    /// `AppRootViewModel.recentlyViewedSongs` on every access (never cached
    /// into a `HomeViewModel`-owned `@State`/stored property) — `Observable`
    /// tracks the read, so `HomeView` re-renders automatically the moment
    /// `AppRootViewModel.recordRecentlyViewed(_:)` updates that array (e.g.
    /// returning to Home right after reading a song), with no manual
    /// refresh call needed on this type at all.
    public var resumeSong: RecentlyViewedSong? {
        rootViewModel.recentlyViewedSongs.first
    }

    /// Every OTHER recently viewed song, excluding the one already featured
    /// as "Continue Reading" above — the "Recently Viewed" shelf's row set.
    ///
    /// ELI5: "What else have I read lately?"
    public var recentlyViewedShelfSongs: [RecentlyViewedSong] {
        Array(rootViewModel.recentlyViewedSongs.dropFirst())
    }
}

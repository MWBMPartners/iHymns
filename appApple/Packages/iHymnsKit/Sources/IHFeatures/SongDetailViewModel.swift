// SongDetailViewModel.swift
// IHFeatures
//
// ELI5: The little helper behind the "one full song" screen — asks for the
// song's full record (PLUS its cross-book counterparts and related songs)
// over the network and remembers whether each is still loading, succeeded,
// or failed.
//
// DETAILED: A per-song, per-screen view model (constructed fresh by each
// `SongDetailView`, unlike the single app-wide `AppRootViewModel`) — mirrors
// the same `LoadState`-driven shape `AppRootViewModel.loadCatalogue()` uses
// for the list, but scoped to exactly one `SongDetail` rather than the
// whole catalogue. Delegates every network call to `AppRootViewModel`
// (#1399's `songDetail(id:)`, #180's `songLinks(id:)`/`relatedSongs(id:)`)
// rather than holding an `APIClient` of its own — there is exactly ONE
// `APIClient` instance per app run (owned by `AppRootViewModel`), and every
// screen that needs a network call reaches it through that one root view
// model.
//
// #180 UPDATE — the full song-display screen also wants the "Also appears
// as" (`song_links`) and "Related Songs" (`related_songs`) shelves. Both are
// SEPARATE `?action=` calls from `song_detail` itself, fetched concurrently
// with it (`async let`, not sequentially chained) so a slow/failed secondary
// call never delays or blanks the main lyrics — each has its OWN
// `LoadState`, and `SongDetailView` treats anything other than a non-empty
// `.loaded` for these two as "just don't show that shelf," never a scary
// error card (unlike the primary `loadState`, where `.error` IS the screen).
//
// #183 UPDATE — `loadPrimaryDetail()` now also calls
// `rootViewModel.recordRecentlyViewed(detail)` the moment the primary load
// succeeds. This IS this task's "persist the last-opened SongID... when a
// SongDetailView appears" hook: anchoring it to a SUCCESSFUL primary load
// (rather than a separate `.onAppear`/`.task` side effect in the View
// itself) is a strictly better signal — a song whose fetch is still in
// flight, or that errored, was never actually "opened" from the user's
// perspective — and keeps the hook exactly where the load's own
// success/failure is already known, with no new View-layer wiring needed.
import IHAPI
import IHModels
import Observation

/// Loads and holds ONE song's full record, plus its (best-effort, secondary)
/// cross-book counterparts and related songs.
///
/// ELI5: "Go get this one song's lyrics and everything else about it,
/// including who else sings it and what else I might like."
@MainActor
@Observable
public final class SongDetailViewModel {
    /// The current load state of this song's full record — the PRIMARY
    /// state; `.error` here is the whole screen's error state.
    public private(set) var loadState: LoadState<SongDetail> = .idle

    /// This song's cross-book counterparts (#807) — SECONDARY: a failure
    /// here never blocks or blanks the lyrics above, it just means the
    /// "Also appears as" shelf doesn't render (see `RelatedSongsShelfView`).
    public private(set) var songLinksState: LoadState<SongLinkGroup> = .idle

    /// Songs related to this one by shared tag/writer/composer/vicinity —
    /// SECONDARY, same "just hide the shelf on failure" treatment as
    /// `songLinksState` above.
    public private(set) var relatedSongsState: LoadState<[RelatedSongSummary]> = .idle

    private let songId: SongID
    private let rootViewModel: AppRootViewModel

    public init(songId: SongID, rootViewModel: AppRootViewModel) {
        self.songId = songId
        self.rootViewModel = rootViewModel
    }

    /// Fetches everything, but only if it hasn't already been fetched (or
    /// isn't currently mid-fetch) — safe to call from `.task {}` on every
    /// re-appearance without re-issuing the network calls each time. Checks
    /// only the PRIMARY `loadState` (matching #1399's original behaviour) —
    /// the two secondary loads always ride along with it in `load()`.
    public func loadIfNeeded() async {
        guard case .idle = loadState else { return }
        await load()
    }

    /// Forces a (re)fetch of the song plus both secondary shelves,
    /// regardless of current state — all three run CONCURRENTLY (`async
    /// let`), each updating its own `LoadState` independently as it
    /// completes, rather than one slow/failed call gating the others.
    public func load() async {
        loadState = .loading
        songLinksState = .loading
        relatedSongsState = .loading

        async let detail: Void = loadPrimaryDetail()
        async let links: Void = loadSongLinks()
        async let related: Void = loadRelatedSongs()
        _ = await (detail, links, related)
    }

    private func loadPrimaryDetail() async {
        do {
            let detail = try await rootViewModel.songDetail(id: songId)
            loadState = .loaded(detail)
            // #183 — see this file's header comment for why this is the
            // "last opened song" hook, anchored to success rather than a
            // View-level `.onAppear`.
            rootViewModel.recordRecentlyViewed(detail)
        } catch let error as APIError {
            loadState = .error(error.userFacingMessage)
        } catch {
            loadState = .error("Something went wrong loading this song. Please try again.")
        }
    }

    private func loadSongLinks() async {
        do {
            songLinksState = .loaded(try await rootViewModel.songLinks(id: songId))
        } catch {
            // Secondary/best-effort — the exact message is never shown to
            // the user (`SongDetailView` only checks for `.loaded` with a
            // non-empty group before rendering the shelf), so any non-empty
            // placeholder satisfies `LoadState`'s `Value: Sendable`
            // requirement without needing per-failure-kind copy.
            songLinksState = .error("")
        }
    }

    private func loadRelatedSongs() async {
        do {
            relatedSongsState = .loaded(try await rootViewModel.relatedSongs(id: songId))
        } catch {
            relatedSongsState = .error("")
        }
    }
}

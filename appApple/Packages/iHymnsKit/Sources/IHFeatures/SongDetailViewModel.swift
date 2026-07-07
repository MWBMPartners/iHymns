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
//
// #187 UPDATE (offline support + data management) — two new
// responsibilities, both following the SAME "this view model owns the
// per-song state, the toolbar just renders it" shape `isFavorite`/
// `toggleFavorite()` already establish: (1) `isSavedOffline` +
// `toggleOfflineSave()` — whether THIS song has a full offline copy saved,
// and the toggle `SongDetailToolbar`'s new save/saved button drives; (2)
// the offline READ-FALLBACK itself — when the network fetch in
// `loadPrimaryDetail()` fails with exactly `.offline`/`.maintenance` (the
// two failure kinds that mean "the request never reached the server," per
// `APIError.swift`'s own doc comments — genuinely never `.unauthorized`/
// `.decoding`/other `.server` errors, which mean something IS wrong and
// silently papering over it with a possibly-stale cached copy would hide
// that), it reaches for `rootViewModel.savedSongDetail(_:)` and, if this
// device has one, serves that instead of the error card, setting
// `isServingCachedCopy` so `SongDetailView` can show an unobtrusive
// "Offline — showing saved copy" indicator rather than pretending the
// content came in fresh.
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

    /// Whether THIS song currently has a full offline copy saved (#187) —
    /// `SongDetailToolbar`'s save/saved button reads this directly, mirroring
    /// `isFavorite`'s identical role for the heart button.
    public private(set) var isSavedOffline = false

    /// Whether `loadState`'s current `.loaded` value came from the offline
    /// cache (a network failure fell back to a saved copy) rather than a
    /// fresh network response — `SongDetailView`'s "Offline — showing saved
    /// copy" indicator reads this. `false` on every fresh network success,
    /// including a RETRY that succeeds after a previous cached fallback.
    public private(set) var isServingCachedCopy = false

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
        // #187 — independent of the primary/secondary network loads above:
        // a cheap local existence check, never gated on any of them
        // succeeding (a song can be "saved offline" regardless of whether
        // THIS load came from the network or the cache).
        async let saved: Void = refreshOfflineSavedState()
        _ = await (detail, links, related, saved)
    }

    private func loadPrimaryDetail() async {
        do {
            let detail = try await rootViewModel.songDetail(id: songId)
            loadState = .loaded(detail)
            isServingCachedCopy = false
            // #183 — see this file's header comment for why this is the
            // "last opened song" hook, anchored to success rather than a
            // View-level `.onAppear`.
            rootViewModel.recordRecentlyViewed(detail)
        } catch let error as APIError {
            // #187 — the request never reached/returned from the server at
            // all (`.offline`) or the server itself says "back soon"
            // (`.maintenance`); if THIS device already saved a full copy of
            // this exact song, serve that instead of an error card. Any
            // OTHER `APIError` (`.unauthorized`/`.decoding`/a genuine
            // `.server` rejection) falls through to the normal error state
            // below — those mean something IS actually wrong, and silently
            // masking that with a possibly-stale cached copy would hide it.
            if Self.isOfflineLikeFailure(error), let cached = await rootViewModel.savedSongDetail(songId) {
                loadState = .loaded(cached)
                isServingCachedCopy = true
                rootViewModel.recordRecentlyViewed(cached)
                return
            }
            loadState = .error(error.userFacingMessage)
        } catch {
            loadState = .error("Something went wrong loading this song. Please try again.")
        }
    }

    /// Whether `error` means "the request never reached/returned from the
    /// server" — the ONLY failure kinds worth falling back to a saved
    /// offline copy for. See `loadPrimaryDetail()`'s own comment for why
    /// every other `APIError` case is deliberately excluded.
    private static func isOfflineLikeFailure(_ error: APIError) -> Bool {
        switch error {
        case .offline, .maintenance:
            true
        default:
            false
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

    // MARK: - Favourites (#181)

    /// Whether THIS song is currently favourited — `SongDetailToolbar`'s
    /// heart icon reads this directly. A thin read-through to
    /// `AppRootViewModel.isFavorite(_:)`, mirroring every OTHER pass-through
    /// this view model already does for `rootViewModel`.
    public var isFavorite: Bool {
        rootViewModel.isFavorite(songId)
    }

    /// Adds or removes THIS song from favourites — `SongDetailView` wires
    /// this straight to the toolbar's heart button (`Task { await
    /// viewModel.toggleFavorite() }`, since a SwiftUI `Button` action is
    /// synchronous but this call is `async`).
    ///
    /// ELI5: "Tap the heart for the song I'm currently reading."
    ///
    /// DETAILED: A no-op while the primary load hasn't succeeded yet
    /// (`loadState` isn't `.loaded`) — there's no title/songbook/number to
    /// favourite WITH yet, and the toolbar's heart button only renders once
    /// `SongDetailView.hasChords`/`shareURL` are already gating on a loaded
    /// `SongDetail` anyway, so this guard should never actually trip in
    /// practice; it's the same defensive belt-and-braces posture
    /// `lineText(forLineId:)` already takes for an out-of-sync `selectedLine`.
    public func toggleFavorite() async {
        guard case .loaded(let detail) = loadState else { return }
        await rootViewModel.toggleFavorite(
            songId: detail.songId,
            title: detail.title,
            songbookAbbreviation: detail.songbookAbbreviation,
            number: detail.number
        )
    }

    // MARK: - Offline saving (#187)

    /// Refreshes `isSavedOffline` from the local cache — cheap enough
    /// (a `COUNT`-only existence check, see `OfflineStore.isSongSaved(_:)`)
    /// to run on every `load()`, unlike the network-backed
    /// `loadPrimaryDetail()`/`loadSongLinks()`/`loadRelatedSongs()` it runs
    /// alongside.
    private func refreshOfflineSavedState() async {
        isSavedOffline = await rootViewModel.isSongSavedOffline(songId)
    }

    /// Saves or removes THIS song's offline copy — `SongDetailToolbar`'s
    /// save/saved button wires this the same way `onToggleFavorite` wires
    /// `toggleFavorite()`.
    ///
    /// ELI5: "Tap the download button for the song I'm currently reading."
    ///
    /// DETAILED: A no-op while the primary load hasn't produced a `SongDetail`
    /// yet (`loadState` isn't `.loaded`) — same defensive guard
    /// `toggleFavorite()` already takes, and for the identical reason: there's
    /// nothing to save WITH yet. Deliberately has NO `isSignedIn` gate —
    /// unlike favourites/setlists, saving a song offline needs no account at
    /// all (this file's header explains why in full).
    public func toggleOfflineSave() async {
        guard case .loaded(let detail) = loadState else { return }
        if isSavedOffline {
            try? await rootViewModel.removeSavedSong(detail.songId)
            isSavedOffline = false
        } else {
            try? await rootViewModel.saveSongForOffline(detail)
            isSavedOffline = true
        }
    }
}

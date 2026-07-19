// AppRootViewModel.swift
// IHFeatures
//
// ELI5: The "front desk" object every app shell (iPhone/iPad/Mac, tvOS,
// watchOS) creates once at launch — it holds the app's four engines
// (network, auth, offline cache, live-session) and mirrors "are we signed
// in?" onto the main thread so SwiftUI views can read it directly.
//
// DETAILED: This is IHFeatures' composition root — the concrete proof that
// the `IHFeatures (→ all)` dependency direction from strategy §1.2 actually
// wires together and compiles: it holds one instance each of
// `IHAuth.SessionController`, `IHAPI.APIClient`, `IHPersistence.OfflineStore`,
// and `IHLive.LiveFollowEngine`. Per strategy §1.3 ("`@Observable` MainActor
// VMs, no Combine"), it's `@MainActor` + `@Observable` (the Observation
// framework, not Combine) and bridges `SessionController`'s actor-isolated
// `AsyncStream<SessionState>` onto a plain `@MainActor` stored property —
// this bridging pattern (actor state → AsyncStream → MainActor mirror) is
// exactly what every Phase-1 feature view model built on top of the real
// engines will repeat, so it's worth getting right here at Phase 0 even
// though the screens that will actually consume it are still placeholders.
//
// Dependencies are all INJECTED (never constructed internally) — the app
// shells are responsible for building the real `APIEnvironment`/
// `KeychainTokenStore`/on-disk `OfflineStore` path per strategy §1.6's
// custody rules; a shared library type constructing its own Keychain
// access-group or a hard-coded file path would bake shell-specific
// decisions into code that has no business making them.
//
// #1399 UPDATE — the E2E slice adds the catalogue-browsing state
// `CatalogueListView` renders directly against this view model (per this
// task's brief: "AppRootViewModel: on appear, APIClient.songsIndex() → hold
// [SongSummary]; a search field filters locally... loading/error/empty
// states mapped from APIError"), plus a `songDetail(id:)` pass-through
// `SongDetailViewModel` calls into — mirroring `cachedSongSummaries()`'s
// existing "expose the operation, not the engine" pattern below, so
// `apiClient` itself stays `private` rather than leaking out to every
// screen that needs one network call.
//
// #183 UPDATE — the Home surface adds two more responsibilities, both
// following the SAME "one root view model, every screen reaches the
// engines through it" shape: `songOfTheDay()` (a pass-through, like
// `songbooks()`, that also resolves `hemisphere`/`country` from the
// device's own `Locale` via `IHAPI.LocaleRegionSignals` — Home never talks
// to `LocaleRegionSignals` directly) and `recentlyViewedSongs` +
// `recordRecentlyViewed(_:)` (the "last opened song" hook this task's brief
// asks for, mirroring `recentSearches`/`recentSearchesStore` below exactly:
// an `@Observable` mirror of a `RecentlyViewedStore`, refreshed after every
// write).
import Foundation
import IHAPI
import IHAuth
import IHLive
import IHLiveActivity
import IHModels
import IHPersistence
import Observation

/// The root view model every app shell instantiates once at launch and
/// injects into its top-level scene.
///
/// ELI5: Holds the four "engines" and keeps a main-thread-safe copy of
/// "are we signed in?" for views to read.
@MainActor
@Observable
public final class AppRootViewModel {
    /// A main-thread-readable mirror of `SessionController.state`,
    /// kept in sync via `stateUpdates` (`observeSessionState()`,
    /// `AppRootViewModel+Auth.swift`).
    ///
    /// ELI5: "Are we signed in?" — safe for any SwiftUI view to read
    /// directly, no `await` needed.
    ///
    /// `internal(set)` (not `private(set)`) — `observeSessionState()` moved
    /// to `AppRootViewModel+Auth.swift` (#1429 LOC-budget tripwire), the
    /// SAME cross-file-extension mutation reason `recentSearches` below
    /// documents.
    public internal(set) var sessionState: SessionState = .signedOut

    /// The current load state of the slim catalogue index (#1399) —
    /// `CatalogueListView` renders its loading/error/list states directly
    /// from this. `[SongSummary]` (not a bare `Bool`/`String` pair) so a
    /// `.loaded` payload IS the data a view needs, no second lookup.
    public private(set) var catalogueLoadState: LoadState<[SongSummary]> = .idle

    /// Local search text, bound directly to a SwiftUI `.searchable(text:)`
    /// — filtering is entirely client-side (strategy §2.2's "search text/
    /// number"; the whole slim index comfortably fits in memory, per this
    /// task's "the index is small enough" design call).
    public var searchText: String = ""

    /// The songbook abbreviations currently ticked in `CatalogueListView`'s
    /// filter menu (#1436) — an EMPTY set means "no songbook filter," never
    /// "match nothing." See `AppRootViewModel+Search.swift` for how this
    /// combines with `searchText`/`selectedLanguages` in `filteredSongs`.
    public var selectedSongbookAbbreviations: Set<String> = []

    /// The BCP-47 language tags currently ticked in the same filter menu
    /// (#1436) — same "empty = unfiltered" convention as
    /// `selectedSongbookAbbreviations` above.
    public var selectedLanguages: Set<String> = []

    /// The last ~10 distinct queries the user has searched, most-recent
    /// -first (#1436) — mirrored from `recentSearchesStore` on init and
    /// after every mutation (`AppRootViewModel+Search.swift`'s
    /// `commitCurrentSearch()`/`clearRecentSearches()`), so
    /// `CatalogueListView`'s `.searchSuggestions` renders directly from this
    /// plain array rather than re-reading `UserDefaults` on every body
    /// evaluation. `internal(set)` (not `private(set)`) because
    /// `AppRootViewModel+Search.swift` — a Swift EXTENSION in a separate
    /// file — mutates this after every recent-search bookkeeping call;
    /// `private` is file-scoped even for a type's own extensions, so the
    /// setter needs to be at least module-visible for that same-module,
    /// different-file split to compile.
    public internal(set) var recentSearches: [String] = []

    /// The songs the user has actually opened recently, most-recent-first
    /// (#183) — `HomeViewModel`'s `resumeSong`/`recentlyViewedShelfSongs`
    /// read straight from this rather than caching a stale copy of their
    /// own, so returning to Home after reading a song always reflects the
    /// latest state. Mirrored from `recentlyViewedStore` on init and after
    /// every `recordRecentlyViewed(_:)` call — same "`@Observable` mirror
    /// of a plain `UserDefaults`-backed store" shape as `recentSearches`
    /// above. `#1446 UPDATE`: now `internal(set)` (previously `private(set)`)
    /// — `recordRecentlyViewed(_:)` moved OUT to
    /// `AppRootViewModel+Activity.swift`, so this needs the SAME
    /// cross-file-extension mutation access `recentSearches` above already
    /// has.
    public internal(set) var recentlyViewedSongs: [RecentlyViewedSong] = []

    /// The current signed-in user's public profile (native login/account UI
    /// task), refreshed via `?action=auth_me` whenever `sessionState`
    /// transitions to `.signedIn` (see `observeSessionState()` below and
    /// `AppRootViewModel+Auth.swift`'s `refreshCurrentUser()`) and cleared
    /// back to `nil` on sign-out. `AccountView` reads this directly rather
    /// than issuing its own network call on every appearance.
    ///
    /// ELI5: "Who am I signed in as?" — cached here so any screen can show
    /// it instantly.
    ///
    /// `internal(set)` (not `private(set)`) — same cross-file-extension
    /// reason `recentSearches`/`favorites` document — `AppRootViewModel+Auth.swift`
    /// sets this from `refreshCurrentUser()`/`signOut()`.
    public internal(set) var currentUser: AuthUser?

    /// Every favourited song this device knows about, most-recently-added
    /// first — an OFFLINE-FIRST mirror of `IHPersistence.OfflineStore`'s
    /// `favorite` table (#181), NOT a `LoadState`-gated network read: it's
    /// populated from the local cache the moment `loadFavoritesIfNeeded()`
    /// runs (near-instant, no network needed) and only ever refined
    /// afterwards by a best-effort server sync
    /// (`AppRootViewModel+Favorites.swift`'s `refreshFavoritesFromServer()`).
    /// `internal(set)` (not `private(set)`) for the same cross-file-extension
    /// reason `recentSearches` above documents — `AppRootViewModel+Favorites.swift`
    /// mutates this after every cache read/toggle/sync.
    ///
    /// ELI5: "Which songs have I favourited?" — answered instantly, even
    /// offline.
    public internal(set) var favorites: [FavoriteSong] = []

    /// Whether `loadFavoritesIfNeeded()` has already populated `favorites`
    /// from the local cache this app run — guards against re-reading the
    /// (actor-isolated, so never instant) offline store on every
    /// `FavoritesView`/`SongDetailView` re-appearance, mirroring
    /// `catalogueLoadState`'s `.idle` guard on `loadCatalogueIfNeeded()`.
    /// `@ObservationIgnored` + `internal` (not `private`) — pure plumbing a
    /// SwiftUI view should never re-render over, but still needs to be
    /// settable from `AppRootViewModel+Favorites.swift`.
    @ObservationIgnored
    var hasLoadedFavoritesFromCache = false

    /// Every setlist this device knows about, most-recently-touched first
    /// (#181's setlists half) — an OFFLINE-FIRST mirror of
    /// `IHPersistence.OfflineStore`'s `setlist` table, refined by a
    /// best-effort server sync, the exact same "root view model IS the
    /// state" shape `favorites` above establishes. `internal(set)` for the
    /// identical cross-file-extension reason `favorites` documents.
    ///
    /// ELI5: "What are my setlists?" — answered instantly, even offline.
    public internal(set) var setlists: [Setlist] = []

    /// Whether `loadSetlistsIfNeeded()` has already populated `setlists`
    /// from the local cache this app run — same one-shot-guard shape as
    /// `hasLoadedFavoritesFromCache` above, for the identical reason.
    @ObservationIgnored
    var hasLoadedSetlistsFromCache = false

    /// Whether `restoreSessionIfNeeded()` (`AppRootViewModel+Auth.swift`) has
    /// already run this app launch — same one-shot guard shape as
    /// `hasLoadedFavoritesFromCache` above, for the identical "safe to call
    /// from `.task {}` on every `RootContainerView` re-appearance" reason.
    /// `internal` (not `private`) — that method lives in the extension file.
    @ObservationIgnored
    var hasAttemptedSessionRestore = false

    /// `internal` (not `private`) — `AppRootViewModel+Auth.swift` calls
    /// `sessionController.signIn(...)`/`.signOut()` directly, and
    /// `restoreSessionIfNeeded()`/`isSignedInNow()` in THIS file already
    /// need it too; same cross-file-extension reasoning as `apiClient`/
    /// `offlineStore` below.
    let sessionController: SessionController

    /// The shared network client. `internal` (not `private`) — same
    /// cross-file-extension reasoning `recentSearchesStore` below documents
    /// — `AppRootViewModel+Auth.swift`/`AppRootViewModel+Favorites.swift`
    /// call straight into it (`apiClient.authMe()`, `apiClient.favorites()`,
    /// ...) the same way `AppRootViewModel+Search.swift` already reads
    /// this file's OTHER internal-visibility properties.
    let apiClient: APIClient

    /// The on-device offline cache. `internal`, same reasoning as
    /// `apiClient` above — `AppRootViewModel+Favorites.swift` reads/writes
    /// it directly for every favourites cache/queue operation.
    let offlineStore: OfflineStore

    /// PR-10 (#1426, #1427) — see `AppRootViewModel+LiveSync.swift`.
    let liveFollowEngine: LiveFollowEngine
    let serviceModeEngine: ServiceModeEngine
    public internal(set) var liveState: LiveSyncUIState = .idle
    public internal(set) var liveSnapshot: LiveBroadcastSnapshot?

    /// PR-16 (#1429) — see `AppRootViewModel+LiveActivity.swift`. Real
    /// only behind `#if os(iOS) && canImport(ActivityKit)` (`IHLiveActivity
    /// .NowSingingActivityController`, injected by `+Live.swift`'s
    /// `makeLive(environment:)`); `nil` elsewhere, which every
    /// `+LiveActivity.swift` call site treats as a silent no-op
    /// (`IHFeatures` holds the PROTOCOL, never `ActivityKit` itself).
    let nowSingingActivity: (any NowSingingActivityControlling)?
    /// The host's current Live Activity content (`nil` = no card showing),
    /// diffed old→new via `NowSingingActivityReducer` by
    /// `syncNowSingingActivity()` (`+LiveActivity.swift`).
    var nowSingingContext: NowSingingHostContext?
    /// Shadow of `nowSingingContext` as of the last successful sync — the
    /// reducer's "previous" input. `@ObservationIgnored`: pure plumbing.
    @ObservationIgnored
    var nowSingingSyncedContext: NowSingingHostContext?
    /// The host's current song id, mirrored locally so `hostAdvanceSection(_:)`
    /// avoids an extra `await` hop into the actor-isolated engine.
    var hostSongId: String?
    /// The host's current section index — reset to `nil` on a new song.
    var hostComponentIndex: Int?
    /// A push token that arrived before the session id was known; flushed
    /// once `hostingStarted` delivers it (`+LiveActivity.swift`).
    var pendingActivityPushTokenHex: String?
    /// #1429 Audit-B F5 — FIFO-serializes `nowSingingActivity?.apply(_:)`
    /// calls (`syncNowSingingActivity()`, `+LiveActivity.swift`): each new
    /// call chains `await prev?.value` before its own `apply(_:)`, so a
    /// rapid `.end`-then-`.start` pair (e.g. `hostingEnded` immediately
    /// followed by a fresh `hostingStarted`) can never reorder on the wire
    /// — without this, two independently-spawned `Task { await ... }`s race
    /// the actor hop with no ordering guarantee. Not `private`: same
    /// cross-file-extension reason `pendingActivityPushTokenHex` above
    /// documents — `private` is file-scoped in Swift, so the sibling
    /// extension file needs at least `internal` write access.
    /// `@ObservationIgnored`: pure plumbing, never read by a view.
    @ObservationIgnored
    var lastApplyTask: Task<Void, Never>?

    /// Persists `recentSearches` (#1436) — see `RecentSearchesStore`'s own
    /// header for why this is a plain injectable value type rather than
    /// `@AppStorage` directly on the property above. Deliberately NOT
    /// `private` (same file-scoping reason as `recentSearches`'s
    /// `internal(set)` above) — `AppRootViewModel+Search.swift` reads it
    /// directly.
    let recentSearchesStore: RecentSearchesStore

    /// Persists `recentlyViewedSongs` (#183) — same injectable-store
    /// reasoning as `recentSearchesStore` above. No longer `private`
    /// (#1446): `recordRecentlyViewed(_:)` moved to
    /// `AppRootViewModel+Activity.swift` (LOC-budget tripwire), so this
    /// needs the same cross-file visibility `recentSearchesStore` has.
    let recentlyViewedStore: RecentlyViewedStore

    /// Persists local, on-device-only reading activity for the "Your
    /// Activity" dashboard (#1446) — day-by-day distinct songs opened plus
    /// per-song open counts. `internal`, for `AppRootViewModel+Activity.swift`.
    let usageActivityStore: UsageActivityStore

    /// The background observation loop mirroring `sessionController.stateUpdates`
    /// into `sessionState` (`observeSessionState()`, moved to
    /// `AppRootViewModel+Auth.swift` — LOC-budget tripwire — which is why
    /// this is `internal` not `private`: same cross-file-extension mutation
    /// reason `liveObservationTask` below documents). Held so it can be
    /// cancelled in `deinit`; `nonisolated(unsafe)` because `deinit` itself
    /// is `nonisolated` (runs from any thread) but is PROVABLY safe here —
    /// by the time it fires, `self` has zero other references, so nothing
    /// races with the cancel (see `observeSessionState()`'s own doc comment
    /// for the full argument).
    @ObservationIgnored
    nonisolated(unsafe) var sessionObservationTask: Task<Void, Never>?

    /// PR-10: consumes both engines' events (`+LiveSync.swift`, which sets this).
    @ObservationIgnored nonisolated(unsafe) var liveObservationTask: Task<Void, Never>?

    public init(
        sessionController: SessionController,
        apiClient: APIClient,
        offlineStore: OfflineStore,
        liveFollowEngine: LiveFollowEngine,
        serviceModeEngine: ServiceModeEngine? = nil,
        recentSearchesStore: RecentSearchesStore = RecentSearchesStore(),
        recentlyViewedStore: RecentlyViewedStore = RecentlyViewedStore(),
        usageActivityStore: UsageActivityStore = UsageActivityStore(),
        // PR-16 (#1429) — `nil` DEFAULT keeps every existing call site
        // unchanged; only `+Live.swift`'s `makeLive(environment:)` (iOS) passes a real one.
        nowSingingActivity: (any NowSingingActivityControlling)? = nil
    ) {
        self.sessionController = sessionController
        self.apiClient = apiClient
        self.offlineStore = offlineStore
        self.liveFollowEngine = liveFollowEngine
        self.serviceModeEngine = serviceModeEngine ?? ServiceModeEngine(apiClient: apiClient)
        self.recentSearchesStore = recentSearchesStore
        self.recentSearches = recentSearchesStore.load()
        self.recentlyViewedStore = recentlyViewedStore
        self.recentlyViewedSongs = recentlyViewedStore.load()
        self.usageActivityStore = usageActivityStore
        self.nowSingingActivity = nowSingingActivity
        observeSessionState()
        observeLiveSyncEvents()
        configureNowSingingActivity()
    }

    deinit {
        sessionObservationTask?.cancel()
        liveObservationTask?.cancel()
    }

    // `cachedSongSummaries()` moved to `AppRootViewModel+Offline.swift` (PR-10 LOC relief).
    // `restoreSessionIfNeeded()`/`isSignedInNow()` (native login/account UI
    // task) live in `AppRootViewModel+Auth.swift` — moved out purely to keep
    // this file under the repo's LOC-budget tripwire now that it also
    // carries `favorites`/`currentUser` state; see that file's own header
    // for the full "why `isSignedInNow()` exists instead of just reading
    // `sessionState`" race-condition explanation.

    // `filteredSongs` (#1399's original substring match, now #1436's
    // number-mode + songbook/language facet filtering on top of it) lives
    // in `AppRootViewModel+Search.swift` — a Swift extension can't add new
    // STORED properties, but every property that search/filter logic needs
    // to read (`searchText`/`selectedSongbookAbbreviations`/
    // `selectedLanguages`/`catalogueLoadState`) is already declared above,
    // so the behaviour itself is free to live in its own file purely to
    // keep this one from re-growing past the repo's LOC-budget tripwire.

    /// Fetches the catalogue index over the network — but only if it
    /// hasn't already been fetched (or isn't currently mid-fetch). Safe to
    /// call from `CatalogueListView`'s `.task {}` on every re-appearance
    /// (e.g. navigating back from a song and returning to the list) without
    /// re-issuing the network call each time.
    ///
    /// ELI5: "Get the song list, but only if we don't already have it."
    public func loadCatalogueIfNeeded() async {
        guard case .idle = catalogueLoadState else { return }
        await loadCatalogue()
    }

    /// Forces a catalogue (re)fetch regardless of current state — the hook
    /// a future manual "retry"/pull-to-refresh action calls into.
    ///
    /// ELI5: "Go get the song list right now, even if we already tried."
    public func loadCatalogue() async {
        catalogueLoadState = .loading
        do {
            let songs = try await apiClient.songsIndex()
            catalogueLoadState = .loaded(songs)
        } catch let error as APIError {
            catalogueLoadState = .error(error.userFacingMessage)
        } catch {
            catalogueLoadState = .error("Something went wrong loading the song list. Please try again.")
        }
    }

    // `songDetail(id:)`/`songLinks(id:)`/`relatedSongs(id:)`/`songbooks()`/
    // `songOfTheDay()` (#1399/#180/#1437/#183's read pass-throughs to
    // `APIClient`) live in `AppRootViewModel+Catalog.swift` — moved out
    // (native login/account UI + favourites task) purely to keep this file
    // from crossing the repo's LOC-budget tripwire (`Scripts/loc-budget.sh`)
    // now that it also carries `favorites`/`currentUser` state; every one of
    // them only touches `apiClient` (already `internal`-visible below), so
    // the move is behaviourally a no-op.

    // `recordRecentlyViewed(_:)` (#183, the "last opened song" hook called
    // from `SongDetailViewModel`'s successful primary load) lives in
    // `AppRootViewModel+Activity.swift` — moved out (#1446) to keep THIS
    // file under the LOC-budget tripwire now that it also carries the new
    // `usageActivityStore` property above; same reasoning
    // `AppRootViewModel+Catalog.swift`'s header documents. A pure move —
    // its ONE #1446 addition (recording the open into `usageActivityStore`)
    // is unchanged from being called at this exact call site.

    // `observeSessionState()` (mirrors every `SessionState` change published
    // by `sessionController` onto `sessionState`, and force-ends hosting on
    // sign-out) moved to `AppRootViewModel+Auth.swift` (#1429 PR-16
    // LOC-budget tripwire) — see that file's own copy of this doc comment
    // for the full "why this is deliberately NOT where sign-in/out syncs
    // its side effects" rationale. A pure move: `sessionObservationTask`
    // above is `internal` (not `private`) for exactly this cross-file
    // mutation, mirroring `liveObservationTask`'s own precedent.
}

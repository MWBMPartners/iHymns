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
import Foundation
import IHAPI
import IHAuth
import IHLive
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
    /// kept in sync via `stateUpdates` (see `observeSessionState()` below).
    ///
    /// ELI5: "Are we signed in?" — safe for any SwiftUI view to read
    /// directly, no `await` needed.
    public private(set) var sessionState: SessionState = .signedOut

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

    private let sessionController: SessionController
    private let apiClient: APIClient
    private let offlineStore: OfflineStore
    private let liveFollowEngine: LiveFollowEngine

    /// Persists `recentSearches` (#1436) — see `RecentSearchesStore`'s own
    /// header for why this is a plain injectable value type rather than
    /// `@AppStorage` directly on the property above. Deliberately NOT
    /// `private` (same file-scoping reason as `recentSearches`'s
    /// `internal(set)` above) — `AppRootViewModel+Search.swift` reads it
    /// directly.
    let recentSearchesStore: RecentSearchesStore

    /// The background observation loop mirroring `sessionController.stateUpdates`
    /// into `sessionState`. Held so it can be cancelled in `deinit`.
    ///
    /// DETAILED: `deinit` on a `@MainActor` class is itself `nonisolated`
    /// (deallocation can be triggered from any thread, so the compiler
    /// can't prove actor isolation there — Swift 6 strict concurrency
    /// forbids touching MainActor-isolated state from it). By the time
    /// `deinit` runs, though, there are — by definition — zero other
    /// references to `self`, so no concurrent access to this property can
    /// possibly be racing with the cancel below; `nonisolated(unsafe)` is
    /// the sanctioned escape hatch for exactly this "provably safe, but the
    /// compiler can't see why" situation (see the Swift evolution pitch on
    /// task cancellation in `deinit`, and Migrating to Swift 6:
    /// https://www.swift.org/migration/documentation/migrationguide/).
    ///
    /// `@ObservationIgnored` because this is pure plumbing, not UI-facing
    /// state (a SwiftUI view should never re-render because this changed);
    /// it also sidesteps `@Observable`'s macro-generated tracked accessor,
    /// which does not support plain `nonisolated` on a mutable stored
    /// property — `nonisolated(unsafe)` is the correct tool once the
    /// property is no longer being wrapped by that macro. Safety argument
    /// is unchanged: by the time `deinit` reads/cancels it, `self` has no
    /// other live references, so nothing can race with this access.
    @ObservationIgnored
    nonisolated(unsafe) private var sessionObservationTask: Task<Void, Never>?

    public init(
        sessionController: SessionController,
        apiClient: APIClient,
        offlineStore: OfflineStore,
        liveFollowEngine: LiveFollowEngine,
        recentSearchesStore: RecentSearchesStore = RecentSearchesStore()
    ) {
        self.sessionController = sessionController
        self.apiClient = apiClient
        self.offlineStore = offlineStore
        self.liveFollowEngine = liveFollowEngine
        self.recentSearchesStore = recentSearchesStore
        self.recentSearches = recentSearchesStore.load()
        observeSessionState()
    }

    deinit {
        sessionObservationTask?.cancel()
    }

    /// The list of songs currently cached for offline use — a thin,
    /// MainActor-safe read-through to `OfflineStore`, demonstrating
    /// IHFeatures' IHPersistence dependency without pulling GRDB types into
    /// this module's public surface.
    ///
    /// ELI5: "What songs have we already saved for offline use?"
    public func cachedSongSummaries() async throws -> [SongSummary] {
        try await offlineStore.allSongSummaries()
    }

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

    /// Fetches one full song record — a thin pass-through to `APIClient`,
    /// exactly like `cachedSongSummaries()`'s read-through above, so
    /// `SongDetailViewModel` (#1399) never needs the `APIClient` itself.
    ///
    /// ELI5: "Give me everything about this one song."
    public func songDetail(id: SongID) async throws -> SongDetail {
        try await apiClient.songDetail(id: id)
    }

    /// Fetches this song's cross-book counterparts (#180, #807) — same
    /// pass-through pattern as `songDetail(id:)` above.
    ///
    /// ELI5: "Does this exact song also appear, under a different id, in
    /// some other songbook?"
    public func songLinks(id: SongID) async throws -> SongLinkGroup {
        try await apiClient.songLinks(id: id)
    }

    /// Fetches songs related to this one by shared tag/writer/composer/
    /// vicinity (#180) — same pass-through pattern as `songDetail(id:)`.
    ///
    /// ELI5: "What else might I like, based on this song?"
    public func relatedSongs(id: SongID) async throws -> [RelatedSongSummary] {
        try await apiClient.relatedSongs(id: id)
    }

    /// Fetches every songbook in the catalogue (#1437, `SongbooksViewModel`'s
    /// only network dependency) — same pass-through pattern as
    /// `songDetail(id:)`/`songLinks(id:)`/`relatedSongs(id:)` above: exactly
    /// ONE `APIClient` instance exists per app run (owned here), so every
    /// screen that needs a network call reaches it through this root view
    /// model rather than holding an `APIClient` of its own.
    ///
    /// ELI5: "Give me the list of hymnals."
    public func songbooks() async throws -> [Songbook] {
        try await apiClient.songbooks()
    }

    /// Starts a `Task` that mirrors every `SessionState` change published by
    /// `sessionController` onto `sessionState`.
    ///
    /// ELI5: Keeps our main-thread copy of "signed in or not" always up to
    /// date.
    ///
    /// DETAILED: Created as a plain (non-detached) `Task` from within this
    /// `@MainActor` initializer, so — per Swift's structured-concurrency
    /// isolation-inheritance rule — the task's body itself runs
    /// MainActor-isolated, meaning `self.sessionState = state` below needs
    /// no extra `await MainActor.run { ... }` hop. Captures `self` WEAKLY:
    /// `sessionController.stateUpdates` never terminates on its own, so a
    /// strong capture here would keep this task (and therefore this whole
    /// view model) alive forever — a classic retain cycle. With a weak
    /// capture, once `self` is deallocated the loop simply exits on its
    /// next iteration instead of pinning it in memory.
    private func observeSessionState() {
        sessionObservationTask = Task { [weak self, sessionController] in
            for await state in sessionController.stateUpdates {
                guard let self, !Task.isCancelled else { break }
                self.sessionState = state
            }
        }
    }
}

// MARK: - Live composition (#1399)

extension AppRootViewModel {
    /// Builds the "real" four-engine composition every live app shell
    /// should use — Keychain-backed auth, the given API environment, and an
    /// in-memory offline cache — centralised here so this exact wiring is
    /// defined ONCE rather than repeated (and inevitably drifting) across
    /// `IHymnsApp.swift`/a future `IHymnsTVApp.swift`/`IHymnsWatchApp.swift`
    /// real-screen wiring.
    ///
    /// ELI5: "Build me the real app, talking to this environment."
    ///
    /// DETAILED: The on-disk GRDB path (rather than this in-memory one)
    /// lands with strategy §3.4's "#187 offline — GRDB+FTS5 + bulk download"
    /// Phase-1 work, once there's an actual offline-save feature to
    /// populate it; an in-memory `OfflineStore` is the honest placeholder
    /// until then (matching what every existing test in this package
    /// already does via `OfflineStore(path: nil)`). `OfflineStore.init`
    /// only throws on a genuine SQLite-open failure, which — for an
    /// in-memory database — is not a realistic failure mode on any
    /// supported platform; `try!` here is the same "developer-known-safe,
    /// force it" posture this package already applies to constant URL
    /// literals (see `APIEnvironment.baseURL`), not a new precedent.
    @MainActor
    public static func makeLive(environment: APIEnvironment) -> AppRootViewModel {
        let apiClient = APIClient(environment: environment)
        let tokenStore = KeychainTokenStore()
        let sessionController = SessionController(tokenStore: tokenStore, apiClient: apiClient)
        // swiftlint:disable:next force_try
        let offlineStore = try! OfflineStore(path: nil)
        let liveFollowEngine = LiveFollowEngine(apiClient: apiClient)

        return AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: offlineStore,
            liveFollowEngine: liveFollowEngine
        )
    }
}

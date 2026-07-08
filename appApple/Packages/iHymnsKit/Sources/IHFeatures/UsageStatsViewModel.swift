// UsageStatsViewModel.swift
// IHFeatures
//
// ELI5: The helper behind the "Your Activity" screen — reads the local
// reading-activity notebook, works out the streak/weekly/monthly/top-songs
// numbers, and pulls in the songs-saved-offline count so the whole screen
// is one snapshot the view just renders.
//
// DETAILED: #1446. `@Observable @MainActor`, mirrors `OfflineStorageViewModel`'s
// exact shape: a `snapshot`/`savedSongCount`/`savedSongBytes`/
// `cachedMediaBytes` stored state, populated by `load()` (safe to call on
// every screen re-appearance — it's all cheap local reads), plus computed
// display properties (`songsReadThisWeek`, `currentStreak`, ...) so the
// view never touches `UsageStatsCalculator`/`UsageActivityStore` directly.
// `favorites`/`setlists`/`recentSearches` are read LIVE from
// `rootViewModel` (already `@Observable` mirrors of their own local
// stores) rather than copied into this view model's own state — there is
// nothing to "load" for them, they're already sitting on the root view
// model.
import Foundation
import IHModels
import Observation

/// Loads and aggregates the local "Your Activity" dashboard's stats — see
/// this file's header.
@MainActor
@Observable
public final class UsageStatsViewModel {
    /// The raw store snapshot — `UsageStatsCalculator`'s every computed
    /// property below derives from this.
    public private(set) var snapshot: UsageActivitySnapshot = .empty

    /// How many songs currently have a saved offline copy.
    public private(set) var savedSongCount = 0

    /// The combined on-disk size, in bytes, of saved song lyrics.
    public private(set) var savedSongBytes = 0

    /// The combined on-disk size, in bytes, of cached media (audio/sheet
    /// music/MIDI/MusicXML) — a SEPARATE figure from `savedSongBytes`, same
    /// "lyrics vs. media" split `OfflineStorageViewModel.totalBytes`/
    /// `.totalMediaBytes` already establishes.
    public private(set) var cachedMediaBytes = 0

    /// Whether `loadIfNeeded()` has already populated this view model this
    /// screen visit — same one-shot-guard shape as
    /// `OfflineStorageViewModel.hasLoaded`.
    @ObservationIgnored
    private var hasLoaded = false

    private let store: UsageActivityStore
    private let rootViewModel: AppRootViewModel
    private let calendar: Calendar

    /// Injectable "now" — defaults to the real `Date()`; tests inject a
    /// fixed closure so streak/window assertions never depend on the
    /// wall-clock moment the test happens to run.
    private let now: @Sendable () -> Date

    public init(
        rootViewModel: AppRootViewModel,
        store: UsageActivityStore = UsageActivityStore(),
        calendar: Calendar = UsageActivityStore.defaultCalendar,
        now: @escaping @Sendable () -> Date = { Date() }
    ) {
        self.rootViewModel = rootViewModel
        self.store = store
        self.calendar = calendar
        self.now = now
    }

    /// Populates this view model, but only once per screen visit — safe to
    /// call from `UsageStatsView`'s `.task {}` on every re-appearance.
    public func loadIfNeeded() async {
        guard !hasLoaded else { return }
        hasLoaded = true
        await load()
    }

    /// Re-reads everything regardless of `hasLoaded` — called by
    /// `resetActivityData()` below, and available for a future manual
    /// pull-to-refresh.
    public func load() async {
        snapshot = store.snapshot()
        savedSongCount = (try? await rootViewModel.savedSongCount()) ?? 0
        savedSongBytes = (try? await rootViewModel.totalSavedSongBytes()) ?? 0
        cachedMediaBytes = (try? await rootViewModel.totalCachedMediaBytes()) ?? 0
    }

    /// Forgets every recorded reading activity, then reloads — the "Reset
    /// Activity Data" confirmation's action.
    ///
    /// ELI5: "Wipe my reading history on this device and start fresh."
    public func resetActivityData() async {
        store.clear()
        await load()
    }

    // MARK: - Derived stats (`UsageStatsCalculator` pass-throughs)

    public var songsReadThisWeek: Int {
        UsageStatsCalculator.songsRead(inLastDays: 7, from: snapshot.dailyOpens, now: now(), calendar: calendar)
    }

    public var songsReadThisMonth: Int {
        UsageStatsCalculator.songsRead(inLastDays: 30, from: snapshot.dailyOpens, now: now(), calendar: calendar)
    }

    public var currentStreak: Int {
        UsageStatsCalculator.currentStreak(dayKeys: Set(snapshot.dailyOpens.keys), today: now(), calendar: calendar)
    }

    /// The top 5 most-viewed songs — a fixed limit (not a parameter) since
    /// `UsageStatsView` only ever renders one "Most Viewed" list.
    public var topSongs: [SongOpenCounter] {
        UsageStatsCalculator.topSongs(snapshot.songCounters, limit: 5)
    }

    public var songbooksExplored: Int {
        UsageStatsCalculator.songbooksExplored(snapshot.songCounters)
    }

    // MARK: - Live pass-throughs (already-`@Observable` root state)

    public var favoritesCount: Int { rootViewModel.favorites.count }
    public var setlistsCount: Int { rootViewModel.setlists.count }
    public var recentSearches: [String] { rootViewModel.recentSearches }

    // MARK: - Display formatting (mirrors `OfflineStorageViewModel`'s own convention)

    /// `savedSongBytes` + `cachedMediaBytes` combined, formatted for display
    /// (e.g. `"4.2 MB"`).
    public var totalOfflineSizeDisplay: String {
        ByteCountFormatter.string(fromByteCount: Int64(savedSongBytes + cachedMediaBytes), countStyle: .file)
    }
}

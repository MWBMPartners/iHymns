// UsageActivityStore.swift
// IHFeatures
//
// ELI5: A tiny, on-device diary of "which songs did I actually open, and
// on which days" — so Settings → "Your Activity" can show a reading streak
// and a "songs read this week" number without ever leaving this device or
// asking a server for anything.
//
// DETAILED: #1446 (local-only v1, `.claude/apple-comparison-usage-plan.md`
// §2.3). Follows `RecentlyViewedStore.swift`'s exact established shape —
// injectable `UserDefaults`, a JSON-`Data` payload, `@unchecked Sendable`,
// defensive decode-to-empty — chosen deliberately over a GRDB table: the
// whole payload is a few KB of counters, and a relational migration would
// buy nothing at this size (see the plan's own "GRDB buys nothing here"
// reasoning). `UserDefaults` usage here is covered by the app's
// `PrivacyInfo.xcprivacy` manifest's existing `NSPrivacyAccessedAPICategoryUserDefaults`
// (reason CA92.1) call-site list — this store is added to that comment in
// the SAME commit, per that manifest's own maintenance rule.
//
// TWO counters, two different semantics:
//   - `dailyOpens["YYYY-MM-DD"]` = how many DISTINCT songs were opened that
//     day (re-reading the same hymn five times in one sitting is "1 song
//     read," not "5") — the substrate for "songs read this week/month" and
//     the reading streak.
//   - `songCounters[i].openCount` = how many times THAT song has EVER been
//     opened, incrementing on every single open — the substrate for
//     "most-viewed songs."
// The day key is computed in a FIXED Gregorian/UTC calendar (`dayKey(for:)`
// below), not the device's current locale/timezone, so neither a language
// change NOR travelling across timezones ever splits one real day's
// activity into two keys, or merges two different days into one.
//
// Privacy: see this store's entry in `PrivacyInfo.xcprivacy`'s
// `NSPrivacyAccessedAPITypes` comment (Apps/iHymns/Sources/) — local-only,
// never read by any analytics sink or network path, never gated on the
// (unrelated) `analyticsConsentEnabled` toggle: showing a user their own
// on-device data is not "collection" under Apple's privacy definitions.
import Foundation
import IHModels

/// How many times one song has been opened, and when it was last opened —
/// the "most-viewed songs" substrate.
///
/// ELI5: "You've opened this song N times, most recently on this date."
public struct SongOpenCounter: Sendable, Hashable, Codable, Identifiable {
    public var id: SongID { songId }

    public let songId: SongID
    public let title: String
    public let songbookAbbreviation: String
    public let number: Int?

    /// How many times this song has EVER been opened — increments on every
    /// single `recordSongOpen`, unlike `dailyOpens` (which dedupes per day).
    public let openCount: Int

    /// When this song was most recently opened — the tie-break
    /// `UsageStatsCalculator.topSongs(_:limit:)` and this store's own
    /// pruning both use when two songs share an `openCount`.
    public let lastOpenedAt: Date

    public init(songId: SongID, title: String, songbookAbbreviation: String, number: Int?, openCount: Int, lastOpenedAt: Date) {
        self.songId = songId
        self.title = title
        self.songbookAbbreviation = songbookAbbreviation
        self.number = number
        self.openCount = openCount
        self.lastOpenedAt = lastOpenedAt
    }
}

/// The read model `UsageStatsViewModel` consumes — a plain snapshot of
/// `UsageActivityStore`'s current persisted state, so the view model never
/// has to know this store's `UserDefaults`/JSON storage details.
public struct UsageActivitySnapshot: Sendable {
    public let dailyOpens: [String: Int]
    public let songCounters: [SongOpenCounter]

    public init(dailyOpens: [String: Int], songCounters: [SongOpenCounter]) {
        self.dailyOpens = dailyOpens
        self.songCounters = songCounters
    }

    /// The empty snapshot — a fresh install, or right after `clear()`.
    public static let empty = UsageActivitySnapshot(dailyOpens: [:], songCounters: [])
}

/// Persists local, on-device-only reading activity: which days songs were
/// opened, and how many times each song has been opened.
///
/// ELI5: A little notebook of "what have I actually read, and when" — never
/// sent anywhere, never read by anything except the "Your Activity" screen.
///
/// DETAILED: `@unchecked Sendable` for the identical reason
/// `RecentlyViewedStore`/`RecentSearchesStore` document — this SDK's
/// `UserDefaults` doesn't (yet) carry a compiler-visible `Sendable`
/// conformance despite being documented thread-safe for this usage.
public struct UsageActivityStore: @unchecked Sendable {
    private let defaults: UserDefaults
    private let key: String
    private let maxDays: Int
    private let maxSongCounters: Int

    /// - Parameters:
    ///   - defaults: Where to persist — `.standard` for the real app; tests
    ///     inject a throwaway `UserDefaults(suiteName:)`.
    ///   - key: The `UserDefaults` key this store owns exclusively.
    ///   - maxDays: How many days of `dailyOpens` history to retain — 366
    ///     comfortably covers "this month" plus a full year of streak
    ///     history without growing unbounded.
    ///   - maxSongCounters: How many distinct songs' counters to retain —
    ///     300 is generous for "most-viewed top 5" while keeping the
    ///     payload small; eviction (see `prune(_:referenceDate:)`) drops the
    ///     LEAST-viewed songs first, so the top-5 list is unaffected in
    ///     practice even once the cap is hit.
    public init(
        defaults: UserDefaults = .standard,
        key: String = "ihUsageActivity",
        maxDays: Int = 366,
        maxSongCounters: Int = 300
    ) {
        self.defaults = defaults
        self.key = key
        self.maxDays = maxDays
        self.maxSongCounters = maxSongCounters
    }

    private struct Payload: Codable {
        var dailyOpens: [String: Int]
        var songCounters: [SongOpenCounter]
    }

    /// The current snapshot of everything this store has recorded.
    ///
    /// ELI5: "What do you know about my reading activity so far?"
    public func snapshot() -> UsageActivitySnapshot {
        let payload = load()
        return UsageActivitySnapshot(dailyOpens: payload.dailyOpens, songCounters: payload.songCounters)
    }

    /// Records that `songId` was just opened — the ONE write hook, called
    /// from `AppRootViewModel.recordRecentlyViewed(_:)`
    /// (`AppRootViewModel+Activity.swift`) on every successful primary song
    /// load, exactly mirroring `RecentlyViewedStore.record(_:)`'s own
    /// anchor point.
    ///
    /// ELI5: "Remember: I just read this song, right now."
    ///
    /// DETAILED: `date` is injectable (defaults to `Date()`) purely for
    /// deterministic tests — production call sites never pass it.
    public func recordSongOpen(
        songId: SongID,
        title: String,
        songbookAbbreviation: String,
        number: Int?,
        date: Date = Date()
    ) {
        var payload = load()
        let todayKey = Self.dayKey(for: date)

        let existingIndex = payload.songCounters.firstIndex { $0.songId == songId }
        // A song only counts toward `dailyOpens[todayKey]` the FIRST time
        // it's opened on a given day — if its most recent open already fell
        // on today, this open is a repeat read, not a "new song read
        // today."
        let alreadyCountedToday = existingIndex.map { Self.dayKey(for: payload.songCounters[$0].lastOpenedAt) == todayKey } ?? false
        if !alreadyCountedToday {
            payload.dailyOpens[todayKey, default: 0] += 1
        }

        if let index = existingIndex {
            let previous = payload.songCounters[index]
            payload.songCounters[index] = SongOpenCounter(
                songId: songId,
                title: title,
                songbookAbbreviation: songbookAbbreviation,
                number: number,
                openCount: previous.openCount + 1,
                lastOpenedAt: date
            )
        } else {
            payload.songCounters.append(SongOpenCounter(
                songId: songId,
                title: title,
                songbookAbbreviation: songbookAbbreviation,
                number: number,
                openCount: 1,
                lastOpenedAt: date
            ))
        }

        prune(&payload, referenceDate: date)
        save(payload)
    }

    /// Forgets every recorded reading activity — the "Reset Activity Data"
    /// affordance on the "Your Activity" screen.
    public func clear() {
        defaults.removeObject(forKey: key)
    }

    // MARK: - Day keys

    /// A fixed Gregorian calendar pinned to UTC — used to derive day keys
    /// (`dayKey(for:)` below), and exposed as the default `calendar:`
    /// argument for `UsageStatsCalculator`'s window/streak arithmetic so
    /// both files walk days using the IDENTICAL calendar, never two
    /// independently-configured ones that could disagree at a DST/leap
    /// boundary. Deliberately NOT a locale-aware `DateFormatter` (which —
    /// Swift 6 strict concurrency correctly flags — is a mutable,
    /// non-`Sendable` class unsafe to share as a `static let`): a
    /// `Calendar` value pinned to UTC achieves the SAME goal (a day key
    /// that never drifts with the device's locale OR timezone) while
    /// staying a plain, `Sendable` value type.
    public static let defaultCalendar: Calendar = {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "UTC") ?? .gmt
        return calendar
    }()

    /// The stable `"YYYY-MM-DD"` key `date` falls on, always computed in
    /// UTC — see `defaultCalendar`'s own doc comment for why. `public` +
    /// `static` so `UsageStatsCalculator` can derive the SAME key for an
    /// arbitrary `Date` (e.g. "today") without duplicating this logic.
    ///
    /// ELI5: "What calendar day does this moment fall on, always measured
    /// the same way no matter where you are or what language your phone is
    /// set to?"
    public static func dayKey(for date: Date) -> String {
        let components = defaultCalendar.dateComponents([.year, .month, .day], from: date)
        guard let year = components.year, let month = components.month, let day = components.day else {
            // Unreachable in practice (a valid `Date` always decomposes),
            // but a fixed sentinel is safer than crashing on a
            // Foundation edge case this store doesn't control.
            return "0000-00-00"
        }
        return String(format: "%04d-%02d-%02d", year, month, day)
    }

    // MARK: - Persistence + pruning

    private func load() -> Payload {
        guard let data = defaults.data(forKey: key) else { return Payload(dailyOpens: [:], songCounters: []) }
        return (try? JSONDecoder().decode(Payload.self, from: data)) ?? Payload(dailyOpens: [:], songCounters: [])
    }

    private func save(_ payload: Payload) {
        guard let data = try? JSONEncoder().encode(payload) else { return }
        defaults.set(data, forKey: key)
    }

    /// Drops `dailyOpens` entries older than `maxDays`, then caps
    /// `songCounters` at `maxSongCounters` by evicting the LOWEST-`openCount`
    /// entries first (oldest `lastOpenedAt` as the tie-break) — see this
    /// file's header for why the top-5 "most viewed" list is unaffected in
    /// practice even once the cap is hit.
    private func prune(_ payload: inout Payload, referenceDate: Date) {
        if let cutoff = Self.defaultCalendar.date(byAdding: .day, value: -maxDays, to: referenceDate) {
            let cutoffKey = Self.dayKey(for: cutoff)
            // "YYYY-MM-DD" string keys sort lexicographically identically
            // to chronological order, so a plain string comparison is a
            // correct (and cheap) "is this day within the retention
            // window" check.
            payload.dailyOpens = payload.dailyOpens.filter { $0.key >= cutoffKey }
        }

        guard payload.songCounters.count > maxSongCounters else { return }
        let ranked = payload.songCounters.sorted {
            if $0.openCount != $1.openCount { return $0.openCount > $1.openCount }
            return $0.lastOpenedAt > $1.lastOpenedAt
        }
        payload.songCounters = Array(ranked.prefix(maxSongCounters))
    }
}

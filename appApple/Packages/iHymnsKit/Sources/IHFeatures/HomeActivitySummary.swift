// HomeActivitySummary.swift
// IHFeatures
//
// ELI5: Works out the 2-3 headline numbers Home's "Your Activity" card
// shows — songs read this week, your current streak, and a tiny 7-day
// sparkline of daily reading — and, just as importantly, decides whether
// there's enough activity yet to bother showing the card at all.
//
// DETAILED: #1457 (Home stat-strip card, following #1446's shipped local
// "Your Activity" screen). A PURE, `Sendable`, side-effect-free type — no
// view, no `@Observable`, no store access of its own — mirroring
// `SongComparisonEngine`/`UsageStatsCalculator`'s own "pure shared shape,
// not a view" precedent in this same directory, so `HomeActivitySummaryTests`
// can drive it directly with hand-built snapshots.
//
// Deliberately built ONLY from `UsageStatsCalculator`'s ALREADY-SHIPPED pure
// functions (`songsRead(inLastDays:from:now:calendar:)`/
// `currentStreak(dayKeys:today:calendar:)`) — never a second copy of that
// streak/window arithmetic (the repo's modularity rule). The ONLY new logic
// here is (a) the "is there anything worth showing" gate (`make(from:...)`
// returns `nil` on a snapshot with no recorded days at all — a fresh
// install, or right after "Reset Activity Data") and (b) `recentDailyCounts`,
// a 7-day, oldest-first array the optional Swift Charts sparkline
// (`HomeActivityCard`) renders — a plain numeric card doesn't need it, but
// computing it here (not in the view) keeps `HomeActivityCard` itself
// free of any `UsageActivityStore.dayKey(for:)` date arithmetic.
import Foundation
import IHModels

/// The Home stat-strip card's data — `nil` (via `make(from:...)`) means
/// "nothing recorded yet, render no card at all."
public struct HomeActivitySummary: Sendable, Equatable {
    public let songsReadThisWeek: Int
    public let currentStreak: Int

    /// The last 7 days' distinct-song-read counts, OLDEST first (today
    /// last) — matches how a reader visually scans a sparkline left-to-right
    /// as "the week leading up to today."
    public let recentDailyCounts: [Int]

    public init(songsReadThisWeek: Int, currentStreak: Int, recentDailyCounts: [Int]) {
        self.songsReadThisWeek = songsReadThisWeek
        self.currentStreak = currentStreak
        self.recentDailyCounts = recentDailyCounts
    }

    /// Builds the summary from a raw `UsageActivityStore` snapshot — `nil`
    /// when `dailyOpens` is completely empty (see this file's header for
    /// why that's the "hide the card" signal, matching `HomeView`'s
    /// existing "render nothing when nothing to show" idiom for its resume
    /// section).
    ///
    /// ELI5: "Here's everything we've recorded — is it worth showing on
    /// Home, and if so, what should the card say?"
    public static func make(
        from snapshot: UsageActivitySnapshot,
        now: Date = Date(),
        calendar: Calendar = UsageActivityStore.defaultCalendar
    ) -> HomeActivitySummary? {
        guard !snapshot.dailyOpens.isEmpty else { return nil }

        let songsReadThisWeek = UsageStatsCalculator.songsRead(
            inLastDays: 7, from: snapshot.dailyOpens, now: now, calendar: calendar
        )
        let currentStreak = UsageStatsCalculator.currentStreak(
            dayKeys: Set(snapshot.dailyOpens.keys), today: now, calendar: calendar
        )
        let recentDailyCounts: [Int] = (0..<7).reversed().map { offset in
            guard let day = calendar.date(byAdding: .day, value: -offset, to: now) else { return 0 }
            return snapshot.dailyOpens[UsageActivityStore.dayKey(for: day)] ?? 0
        }

        return HomeActivitySummary(
            songsReadThisWeek: songsReadThisWeek,
            currentStreak: currentStreak,
            recentDailyCounts: recentDailyCounts
        )
    }
}

// UsageStatsCalculator.swift
// IHFeatures
//
// ELI5: Turns the raw "which days did I open songs, how many times has each
// song been opened" notebook into the actual numbers the "Your Activity"
// screen shows — "5 songs this week," "a 3-day streak," "your top 5 songs."
//
// DETAILED: #1446. A PURE, `Sendable`, side-effect-free namespace — no
// view, no `@Observable`, no store access — mirroring `SongComparisonEngine`
// /`SongNavigationContext`'s own "pure shared shape, not a view" precedent
// in this same directory. Every function takes its inputs as plain values
// (a `[String: Int]`, a `Set<String>`, a `[SongOpenCounter]`, an injectable
// `Date`/`Calendar`) rather than reading `UsageActivityStore` itself, so
// `UsageStatsCalculatorTests` can assert on exact day boundaries and streak
// edge cases without touching `UserDefaults` at all.
import Foundation
import IHModels

/// Pure aggregation over a `UsageActivitySnapshot` — see this file's header.
public enum UsageStatsCalculator {
    /// How many songs were read in the `days`-day window ending TODAY
    /// (inclusive) — summed from `dailyOpens`' per-day distinct-song counts,
    /// so a song read on two different days within the window counts
    /// twice (an honest "how much reading happened," not a distinct-song
    /// total across the whole window).
    ///
    /// ELI5: "Add up 'songs read' for today and the last (days-1) days."
    public static func songsRead(inLastDays days: Int, from dailyOpens: [String: Int], now: Date, calendar: Calendar = UsageActivityStore.defaultCalendar) -> Int {
        guard days > 0 else { return 0 }
        var total = 0
        for offset in 0..<days {
            guard let day = calendar.date(byAdding: .day, value: -offset, to: now) else { continue }
            total += dailyOpens[UsageActivityStore.dayKey(for: day)] ?? 0
        }
        return total
    }

    /// The current consecutive-day reading streak, counting either from
    /// TODAY (if already recorded) or — the "grace rule" — from YESTERDAY
    /// (if today has no entry yet but yesterday does, so the streak isn't
    /// punished before the user has had a chance to read today).
    ///
    /// ELI5: "How many days in a row have you read something, allowing
    /// 'still current' if you read yesterday but haven't opened the app yet
    /// today?"
    public static func currentStreak(dayKeys: Set<String>, today: Date, calendar: Calendar = UsageActivityStore.defaultCalendar) -> Int {
        let todayKey = UsageActivityStore.dayKey(for: today)
        var cursor = today
        if !dayKeys.contains(todayKey) {
            guard let yesterday = calendar.date(byAdding: .day, value: -1, to: today) else { return 0 }
            cursor = yesterday
        }

        var streak = 0
        while dayKeys.contains(UsageActivityStore.dayKey(for: cursor)) {
            streak += 1
            guard let previous = calendar.date(byAdding: .day, value: -1, to: cursor) else { break }
            cursor = previous
        }
        return streak
    }

    /// The top `limit` most-viewed songs, ranked by `openCount` descending,
    /// then most-recently-opened first as the tie-break (mirrors
    /// `UsageActivityStore`'s own pruning tie-break, so "most viewed" and
    /// "least likely to be evicted" agree).
    ///
    /// ELI5: "Which songs have you opened the most?"
    public static func topSongs(_ counters: [SongOpenCounter], limit: Int) -> [SongOpenCounter] {
        guard limit > 0 else { return [] }
        let ranked = counters.sorted {
            if $0.openCount != $1.openCount { return $0.openCount > $1.openCount }
            return $0.lastOpenedAt > $1.lastOpenedAt
        }
        return Array(ranked.prefix(limit))
    }

    /// How many DISTINCT songbooks the recorded songs come from.
    ///
    /// ELI5: "How many different hymnals have you actually read from?"
    public static func songbooksExplored(_ counters: [SongOpenCounter]) -> Int {
        Set(counters.map(\.songbookAbbreviation)).count
    }
}

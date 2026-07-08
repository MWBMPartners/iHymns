// HomeActivitySummaryTests.swift
// IHFeaturesTests
//
// ELI5: Proves the Home "Your Activity" card's two decisions are both
// right — "is there enough activity yet to even show a card" (a completely
// empty snapshot means no card, anything at all means yes) and "what does
// the card say" (this week's count, the streak, and a 7-day, oldest-first
// sparkline array) — with pure hand-built snapshots, no store, no view,
// mirroring `UsageStatsCalculatorTests.swift`'s identical shape for its
// sibling pure-logic type.
import Foundation
import Testing
@testable import IHFeatures
@testable import IHModels

@Suite("HomeActivitySummary (#1457)")
struct HomeActivitySummaryTests {

    private static let calendar = UsageActivityStore.defaultCalendar

    private static func day(_ offset: Int, from reference: Date) -> Date {
        // swiftlint:disable:next force_unwrapping
        calendar.date(byAdding: .day, value: offset, to: reference)!
    }

    private static func key(_ offset: Int, from reference: Date) -> String {
        UsageActivityStore.dayKey(for: day(offset, from: reference))
    }

    // MARK: - The "is this worth showing" gate

    @Test("A completely empty snapshot (a fresh install) produces no summary — the card stays hidden")
    func emptySnapshotProducesNoSummary() {
        let summary = HomeActivitySummary.make(from: .empty, now: Date(), calendar: Self.calendar)
        #expect(summary == nil)
    }

    @Test("A snapshot with even one recorded day produces a summary")
    func oneRecordedDayProducesASummary() {
        let today = Date()
        let snapshot = UsageActivitySnapshot(dailyOpens: [Self.key(0, from: today): 1], songCounters: [])
        #expect(HomeActivitySummary.make(from: snapshot, now: today, calendar: Self.calendar) != nil)
    }

    // MARK: - What the card says

    @Test("songsReadThisWeek and currentStreak match UsageStatsCalculator's own answers for the same inputs")
    func summaryMatchesUsageStatsCalculator() {
        let today = Date()
        let dailyOpens = [Self.key(0, from: today): 2, Self.key(-1, from: today): 1, Self.key(-10, from: today): 5]
        let snapshot = UsageActivitySnapshot(dailyOpens: dailyOpens, songCounters: [])

        let summary = HomeActivitySummary.make(from: snapshot, now: today, calendar: Self.calendar)

        let expectedWeek = UsageStatsCalculator.songsRead(inLastDays: 7, from: dailyOpens, now: today, calendar: Self.calendar)
        let expectedStreak = UsageStatsCalculator.currentStreak(dayKeys: Set(dailyOpens.keys), today: today, calendar: Self.calendar)
        #expect(summary?.songsReadThisWeek == expectedWeek)
        #expect(summary?.currentStreak == expectedStreak)
    }

    @Test("recentDailyCounts is exactly 7 entries, oldest first, today last")
    func recentDailyCountsIsSevenEntriesOldestFirst() {
        let today = Date()
        let dailyOpens = [Self.key(0, from: today): 3, Self.key(-6, from: today): 9]
        let snapshot = UsageActivitySnapshot(dailyOpens: dailyOpens, songCounters: [])

        let summary = HomeActivitySummary.make(from: snapshot, now: today, calendar: Self.calendar)

        #expect(summary?.recentDailyCounts.count == 7)
        #expect(summary?.recentDailyCounts.first == 9) // 6 days ago — oldest
        #expect(summary?.recentDailyCounts.last == 3) // today — newest
    }

    @Test("A day with nothing recorded shows as 0 in recentDailyCounts, not missing")
    func unrecordedDaysAreZeroNotMissing() {
        let today = Date()
        let snapshot = UsageActivitySnapshot(dailyOpens: [Self.key(0, from: today): 1], songCounters: [])

        let summary = HomeActivitySummary.make(from: snapshot, now: today, calendar: Self.calendar)

        #expect(summary?.recentDailyCounts == [0, 0, 0, 0, 0, 0, 1])
    }
}

// UsageStatsCalculatorTests.swift
// IHFeaturesTests
//
// ELI5: Proves the streak/window/top-songs maths gets the tricky edges
// right — an empty history is a 0-day streak, reading yesterday (but not
// yet today) still counts as "current," a gap breaks the streak, and the
// 7-day/30-day windows include exactly the right number of days. Pure
// Swift Testing coverage, no store, no view, matching `UsageStatsCalculator`
// itself (mirrors `SongComparisonEngineTests.swift`'s shape for its sibling
// pure-logic type).
import Foundation
import Testing
@testable import IHFeatures
@testable import IHModels

@Suite("UsageStatsCalculator (#1446)")
struct UsageStatsCalculatorTests {

    private static let calendar = UsageActivityStore.defaultCalendar

    private static func day(_ offset: Int, from reference: Date) -> Date {
        // swiftlint:disable:next force_unwrapping
        calendar.date(byAdding: .day, value: offset, to: reference)!
    }

    private static func key(_ offset: Int, from reference: Date) -> String {
        UsageActivityStore.dayKey(for: day(offset, from: reference))
    }

    private func makeCounter(_ number: Int, openCount: Int, lastOpenedAt: Date, songbook: String = "MP") -> SongOpenCounter {
        SongOpenCounter(
            songId: SongID(songbookAbbreviation: songbook, number: number),
            title: "Song \(number)",
            songbookAbbreviation: songbook,
            number: number,
            openCount: openCount,
            lastOpenedAt: lastOpenedAt
        )
    }

    // MARK: - Streak

    @Test("An empty history has a 0-day streak")
    func emptyHistoryHasZeroStreak() {
        let today = Date()
        #expect(UsageStatsCalculator.currentStreak(dayKeys: [], today: today, calendar: Self.calendar) == 0)
    }

    @Test("Reading only today gives a 1-day streak")
    func todayOnlyGivesOneDayStreak() {
        let today = Date()
        let dayKeys: Set<String> = [Self.key(0, from: today)]
        #expect(UsageStatsCalculator.currentStreak(dayKeys: dayKeys, today: today, calendar: Self.calendar) == 1)
    }

    @Test("Reading today AND yesterday gives a 2-day streak")
    func todayAndYesterdayGiveTwoDayStreak() {
        let today = Date()
        let dayKeys: Set<String> = [Self.key(0, from: today), Self.key(-1, from: today)]
        #expect(UsageStatsCalculator.currentStreak(dayKeys: dayKeys, today: today, calendar: Self.calendar) == 2)
    }

    @Test("A gap in the history breaks the streak at the gap")
    func gapBreaksStreak() {
        let today = Date()
        // Today and the day before yesterday are recorded, but YESTERDAY
        // is missing — the streak must stop at today (1), not jump the gap.
        let dayKeys: Set<String> = [Self.key(0, from: today), Self.key(-2, from: today)]
        #expect(UsageStatsCalculator.currentStreak(dayKeys: dayKeys, today: today, calendar: Self.calendar) == 1)
    }

    @Test("A streak that ended YESTERDAY (nothing read yet today) still reports as current (grace rule)")
    func streakEndingYesterdayStillCounts() {
        let today = Date()
        let dayKeys: Set<String> = [Self.key(-1, from: today), Self.key(-2, from: today)]
        #expect(UsageStatsCalculator.currentStreak(dayKeys: dayKeys, today: today, calendar: Self.calendar) == 2)
    }

    @Test("A streak broken more than a day before today reports 0")
    func longAbsenceReportsZero() {
        let today = Date()
        let dayKeys: Set<String> = [Self.key(-5, from: today)]
        #expect(UsageStatsCalculator.currentStreak(dayKeys: dayKeys, today: today, calendar: Self.calendar) == 0)
    }

    // MARK: - Window counts

    @Test("songsRead(inLastDays: 7) includes exactly today plus the previous 6 days")
    func sevenDayWindowBoundary() {
        let today = Date()
        var dailyOpens: [String: Int] = [:]
        // One song read on each of the last 8 days (offsets 0...-7).
        for offset in -7...0 {
            dailyOpens[Self.key(offset, from: today)] = 1
        }
        // 7-day window (offsets 0...-6) should sum to 7; the 8th day
        // (offset -7) must be excluded.
        #expect(UsageStatsCalculator.songsRead(inLastDays: 7, from: dailyOpens, now: today, calendar: Self.calendar) == 7)
    }

    @Test("songsRead(inLastDays: 30) sums a full month window")
    func thirtyDayWindow() {
        let today = Date()
        var dailyOpens: [String: Int] = [:]
        for offset in -29...0 {
            dailyOpens[Self.key(offset, from: today)] = 2
        }
        #expect(UsageStatsCalculator.songsRead(inLastDays: 30, from: dailyOpens, now: today, calendar: Self.calendar) == 60)
    }

    @Test("songsRead with days <= 0 returns 0")
    func nonPositiveWindowReturnsZero() {
        #expect(UsageStatsCalculator.songsRead(inLastDays: 0, from: ["x": 5], now: Date(), calendar: Self.calendar) == 0)
    }

    // MARK: - Top songs

    @Test("topSongs orders by openCount descending")
    func topSongsOrdersByOpenCountDescending() {
        let now = Date()
        let counters = [
            makeCounter(1, openCount: 2, lastOpenedAt: now),
            makeCounter(2, openCount: 9, lastOpenedAt: now),
            makeCounter(3, openCount: 5, lastOpenedAt: now)
        ]
        let top = UsageStatsCalculator.topSongs(counters, limit: 5)
        #expect(top.map(\.openCount) == [9, 5, 2])
    }

    @Test("topSongs ties break on most-recently-opened first")
    func topSongsTieBreaksOnMostRecent() {
        let now = Date()
        let earlier = Self.day(-1, from: now)
        let counters = [
            makeCounter(1, openCount: 3, lastOpenedAt: earlier),
            makeCounter(2, openCount: 3, lastOpenedAt: now)
        ]
        let top = UsageStatsCalculator.topSongs(counters, limit: 5)
        #expect(top.first?.number == 2)
    }

    @Test("topSongs respects the limit")
    func topSongsRespectsLimit() {
        let now = Date()
        let counters = (1...10).map { makeCounter($0, openCount: $0, lastOpenedAt: now) }
        #expect(UsageStatsCalculator.topSongs(counters, limit: 3).count == 3)
    }

    // MARK: - Songbooks explored

    @Test("songbooksExplored counts distinct songbook abbreviations")
    func songbooksExploredCountsDistinct() {
        let now = Date()
        let counters = [
            makeCounter(1, openCount: 1, lastOpenedAt: now, songbook: "MP"),
            makeCounter(2, openCount: 1, lastOpenedAt: now, songbook: "MP"),
            makeCounter(3, openCount: 1, lastOpenedAt: now, songbook: "SDAH")
        ]
        #expect(UsageStatsCalculator.songbooksExplored(counters) == 2)
    }
}

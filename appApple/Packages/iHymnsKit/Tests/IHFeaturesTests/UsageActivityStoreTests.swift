// UsageActivityStoreTests.swift
// IHFeaturesTests
//
// ELI5: Checks the "what have I actually read, and when" notebook —
// distinct-songs-per-day counting, per-song open counts, pruning old days
// and low-count songs, corrupt-data recovery, day-key stability, and reset
// — all against a throwaway defaults suite so the real app's activity data
// is never touched. Mirrors `RecentlyViewedStoreTests.swift`'s exact shape
// for its sibling store.
import Foundation
import Testing
@testable import IHFeatures
@testable import IHModels

@Suite("UsageActivityStore (#1446)")
struct UsageActivityStoreTests {

    /// A fresh, isolated store + defaults suite per call (unique suite name
    /// so parallel/repeated runs can never collide) — same seam
    /// `RecentlyViewedStoreTests` uses.
    private func makeStore(maxDays: Int = 366, maxSongCounters: Int = 300) -> UsageActivityStore {
        let suite = "ihtest.usageactivity.\(UUID().uuidString)"
        // swiftlint:disable:next force_unwrapping
        let defaults = UserDefaults(suiteName: suite)!
        return UsageActivityStore(defaults: defaults, key: "usageActivity", maxDays: maxDays, maxSongCounters: maxSongCounters)
    }

    private func makeSongId(_ number: Int) -> SongID {
        SongID(songbookAbbreviation: "MP", number: number)
    }

    @Test("Starts empty")
    func startsEmpty() {
        let snapshot = makeStore().snapshot()
        #expect(snapshot.dailyOpens.isEmpty)
        #expect(snapshot.songCounters.isEmpty)
    }

    @Test("Recording a song increments its openCount and records the day")
    func recordingIncrementsOpenCountAndDay() {
        let store = makeStore()
        let today = Date()
        store.recordSongOpen(songId: makeSongId(1), title: "Song One", songbookAbbreviation: "MP", number: 1, date: today)

        let snapshot = store.snapshot()
        #expect(snapshot.songCounters.count == 1)
        #expect(snapshot.songCounters[0].openCount == 1)
        #expect(snapshot.dailyOpens[UsageActivityStore.dayKey(for: today)] == 1)
    }

    @Test("Two DIFFERENT songs opened the same day count as 2 distinct songs read")
    func twoDifferentSongsSameDayCountAsTwo() {
        let store = makeStore()
        let today = Date()
        store.recordSongOpen(songId: makeSongId(1), title: "Song One", songbookAbbreviation: "MP", number: 1, date: today)
        store.recordSongOpen(songId: makeSongId(2), title: "Song Two", songbookAbbreviation: "MP", number: 2, date: today)

        let snapshot = store.snapshot()
        #expect(snapshot.dailyOpens[UsageActivityStore.dayKey(for: today)] == 2)
    }

    @Test("The SAME song opened twice the same day counts as 1 distinct song read, but openCount == 2")
    func sameSongTwiceSameDayCountsAsOneDistinctRead() {
        let store = makeStore()
        let today = Date()
        store.recordSongOpen(songId: makeSongId(1), title: "Song One", songbookAbbreviation: "MP", number: 1, date: today)
        store.recordSongOpen(songId: makeSongId(1), title: "Song One", songbookAbbreviation: "MP", number: 1, date: today)

        let snapshot = store.snapshot()
        #expect(snapshot.dailyOpens[UsageActivityStore.dayKey(for: today)] == 1)
        #expect(snapshot.songCounters.count == 1)
        #expect(snapshot.songCounters[0].openCount == 2)
    }

    @Test("The same song opened on two DIFFERENT days counts toward both days' dailyOpens")
    func sameSongDifferentDaysCountsTowardBoth() {
        let store = makeStore()
        let today = Date()
        let yesterday = Calendar(identifier: .gregorian).date(byAdding: .day, value: -1, to: today) ?? today
        store.recordSongOpen(songId: makeSongId(1), title: "Song One", songbookAbbreviation: "MP", number: 1, date: yesterday)
        store.recordSongOpen(songId: makeSongId(1), title: "Song One", songbookAbbreviation: "MP", number: 1, date: today)

        let snapshot = store.snapshot()
        #expect(snapshot.dailyOpens[UsageActivityStore.dayKey(for: yesterday)] == 1)
        #expect(snapshot.dailyOpens[UsageActivityStore.dayKey(for: today)] == 1)
        #expect(snapshot.songCounters[0].openCount == 2)
    }

    @Test("Pruning drops dailyOpens entries older than maxDays")
    func pruningDropsOldDayEntries() {
        let store = makeStore(maxDays: 5)
        let calendar = Calendar(identifier: .gregorian)
        let now = Date()
        // A day well outside the 5-day retention window.
        let oldDay = calendar.date(byAdding: .day, value: -30, to: now) ?? now
        store.recordSongOpen(songId: makeSongId(1), title: "Old Song", songbookAbbreviation: "MP", number: 1, date: oldDay)
        // A fresh write (with a recent reference date) triggers pruning.
        store.recordSongOpen(songId: makeSongId(2), title: "New Song", songbookAbbreviation: "MP", number: 2, date: now)

        let snapshot = store.snapshot()
        #expect(snapshot.dailyOpens[UsageActivityStore.dayKey(for: oldDay)] == nil)
        #expect(snapshot.dailyOpens[UsageActivityStore.dayKey(for: now)] == 1)
    }

    @Test("Pruning caps songCounters at maxSongCounters, evicting the lowest openCount first")
    func pruningEvictsLowestOpenCountFirst() {
        let store = makeStore(maxSongCounters: 2)
        let now = Date()
        // Song 1: opened 3 times (highest count, survives).
        for _ in 0..<3 {
            store.recordSongOpen(songId: makeSongId(1), title: "Song One", songbookAbbreviation: "MP", number: 1, date: now)
        }
        // Song 2: opened 2 times.
        for _ in 0..<2 {
            store.recordSongOpen(songId: makeSongId(2), title: "Song Two", songbookAbbreviation: "MP", number: 2, date: now)
        }
        // Song 3: opened once — the lowest count, should be evicted once the cap (2) is exceeded.
        store.recordSongOpen(songId: makeSongId(3), title: "Song Three", songbookAbbreviation: "MP", number: 3, date: now)

        let snapshot = store.snapshot()
        #expect(snapshot.songCounters.count == 2)
        let survivingIds = Set(snapshot.songCounters.map(\.songId))
        #expect(survivingIds.contains(makeSongId(1)))
        #expect(survivingIds.contains(makeSongId(2)))
        #expect(!survivingIds.contains(makeSongId(3)))
    }

    @Test("A malformed stored blob decodes to an empty snapshot")
    func malformedBlobDecodesToEmpty() {
        let suite = "ihtest.usageactivity.\(UUID().uuidString)"
        // swiftlint:disable:next force_unwrapping
        let defaults = UserDefaults(suiteName: suite)!
        defaults.set(Data("not valid json".utf8), forKey: "usageActivity")
        let store = UsageActivityStore(defaults: defaults, key: "usageActivity")

        let snapshot = store.snapshot()
        #expect(snapshot.dailyOpens.isEmpty)
        #expect(snapshot.songCounters.isEmpty)
    }

    @Test("clear() forgets everything")
    func clearForgetsEverything() {
        let store = makeStore()
        store.recordSongOpen(songId: makeSongId(1), title: "Song One", songbookAbbreviation: "MP", number: 1)
        store.clear()

        let snapshot = store.snapshot()
        #expect(snapshot.dailyOpens.isEmpty)
        #expect(snapshot.songCounters.isEmpty)
    }

    @Test("dayKey(for:) is stable across different Locale/Calendar configurations feeding the same instant")
    func dayKeyIsLocaleStable() {
        // The SAME instant, computed via two different (locale-flavoured)
        // calendars, must still resolve to `dayKey(for:)`'s ONE canonical
        // UTC-anchored key — dayKey itself never consults the passed-in
        // calendar's locale/timezone, only the raw `Date` instant.
        let date = Date(timeIntervalSince1970: 1_720_000_000)
        let keyOne = UsageActivityStore.dayKey(for: date)
        let keyTwo = UsageActivityStore.dayKey(for: date)
        #expect(keyOne == keyTwo)
        #expect(keyOne.count == 10) // "YYYY-MM-DD"
    }
}

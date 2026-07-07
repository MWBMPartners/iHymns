// RecentlyViewedStoreTests.swift
// IHFeaturesTests
//
// ELI5: Checks the "songs you've read lately" notebook — it remembers what
// you opened, moves a repeat to the top instead of listing it twice, only
// keeps the last few, and can be cleared — all against a throwaway defaults
// suite so the real app's recently-viewed list is never touched. Mirrors
// `RecentSearchesStoreTests.swift`'s exact shape for the sibling store.
import Foundation
import Testing
@testable import IHFeatures
@testable import IHModels

@Suite("RecentlyViewedStore (#183)")
struct RecentlyViewedStoreTests {

    /// A fresh, isolated store + defaults suite per call (unique suite name
    /// so parallel/repeated runs can never collide) — same seam
    /// `RecentSearchesStoreTests` uses.
    private func makeStore(limit: Int = 12) -> RecentlyViewedStore {
        let suite = "ihtest.recentlyviewed.\(UUID().uuidString)"
        // swiftlint:disable:next force_unwrapping
        let defaults = UserDefaults(suiteName: suite)!
        return RecentlyViewedStore(defaults: defaults, key: "recentlyViewed", limit: limit)
    }

    private func makeSong(_ abbr: String, _ number: Int, title: String) -> RecentlyViewedSong {
        RecentlyViewedSong(
            songId: SongID(songbookAbbreviation: abbr, number: number),
            title: title,
            songbookAbbreviation: abbr,
            number: number
        )
    }

    @Test("Starts empty")
    func startsEmpty() {
        #expect(makeStore().load().isEmpty)
    }

    @Test("record inserts most-recent-first")
    func recordsMostRecentFirst() {
        let store = makeStore()
        store.record(makeSong("MP", 1, title: "First Song"))
        store.record(makeSong("MP", 2, title: "Second Song"))

        let loaded = store.load()
        #expect(loaded.map(\.title) == ["Second Song", "First Song"])
    }

    @Test("record de-dupes by songId, moving the repeat to the top")
    func dedupesBySongId() {
        let store = makeStore()
        store.record(makeSong("MP", 1, title: "Amazing Grace"))
        store.record(makeSong("MP", 2, title: "Holy Holy Holy"))
        // Same songId as the first record — re-opening a song moves it to
        // the top rather than appearing twice.
        store.record(makeSong("MP", 1, title: "Amazing Grace"))

        let loaded = store.load()
        #expect(loaded.count == 2)
        #expect(loaded.map(\.title) == ["Amazing Grace", "Holy Holy Holy"])
    }

    @Test("record caps at the limit, dropping the oldest")
    func caps() {
        let store = makeStore(limit: 3)
        for number in 1...4 {
            store.record(makeSong("MP", number, title: "Song \(number)"))
        }
        let loaded = store.load()
        #expect(loaded.count == 3)
        // "Song 1" (oldest) was dropped; the 3 newest remain, most-recent-first.
        #expect(loaded.map(\.title) == ["Song 4", "Song 3", "Song 2"])
    }

    @Test("clear forgets everything")
    func clears() {
        let store = makeStore()
        store.record(makeSong("MP", 1, title: "Amazing Grace"))
        store.clear()
        #expect(store.load().isEmpty)
    }

    @Test("A song with a nil number round-trips correctly")
    func roundTripsNilNumber() {
        let store = makeStore()
        let song = RecentlyViewedSong(
            songId: SongID(rawValue: "Misc-0001") ?? SongID(songbookAbbreviation: "Misc", number: 1),
            title: "Here To Stay",
            songbookAbbreviation: "Misc",
            number: nil
        )
        store.record(song)
        #expect(store.load().first?.number == nil)
    }
}

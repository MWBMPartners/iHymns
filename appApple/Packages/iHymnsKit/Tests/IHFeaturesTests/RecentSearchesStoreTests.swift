// RecentSearchesStoreTests.swift
// IHFeaturesTests
//
// ELI5: Checks the recent-searches notebook — it remembers what you
// searched, moves a repeat to the top instead of listing it twice, only
// keeps the last few, and can be cleared — all against a throwaway defaults
// suite so the real app's saved searches are never touched.
//
// DETAILED: Covers #1436's `RecentSearchesStore`. Each test injects its own
// `UserDefaults(suiteName:)` (the store's designed test seam) so runs never
// pollute `.standard` or each other.
import Foundation
import Testing
@testable import IHFeatures

@Suite("RecentSearchesStore")
struct RecentSearchesStoreTests {

    /// A fresh, isolated store + defaults suite per call (unique suite name
    /// so parallel/repeated runs can never collide).
    private func makeStore(limit: Int = 10) -> RecentSearchesStore {
        let suite = "ihtest.recent.\(UUID().uuidString)"
        let defaults = UserDefaults(suiteName: suite)!
        return RecentSearchesStore(defaults: defaults, key: "recents", limit: limit)
    }

    @Test("Starts empty")
    func startsEmpty() {
        #expect(makeStore().load().isEmpty)
    }

    @Test("record inserts most-recent-first")
    func recordsMostRecentFirst() {
        let store = makeStore()
        store.record("grace")
        store.record("holy")
        #expect(store.load() == ["holy", "grace"])
    }

    @Test("record de-dupes case-insensitively, moving the match to the top")
    func dedupes() {
        let store = makeStore()
        store.record("grace")
        store.record("holy")
        store.record("GRACE")   // same as "grace" ignoring case → moves to top, keeps the new casing
        #expect(store.load() == ["GRACE", "holy"])
    }

    @Test("record caps at the limit, dropping the oldest")
    func caps() {
        let store = makeStore(limit: 3)
        for query in ["a", "b", "c", "d"] { store.record(query) }
        #expect(store.load() == ["d", "c", "b"])   // "a" (oldest) dropped
    }

    @Test("record ignores empty / whitespace-only queries")
    func ignoresBlank() {
        let store = makeStore()
        store.record("   ")
        store.record("")
        #expect(store.load().isEmpty)
    }

    @Test("clear forgets everything")
    func clears() {
        let store = makeStore()
        store.record("grace")
        store.clear()
        #expect(store.load().isEmpty)
    }
}

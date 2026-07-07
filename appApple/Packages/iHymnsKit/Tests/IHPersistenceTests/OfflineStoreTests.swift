// OfflineStoreTests.swift
// IHPersistenceTests
//
// ELI5: Saves a couple of songs into an in-memory offline notebook, then
// checks reading them back gives the exact same songs, in the right order.
import Testing
@testable import IHPersistence
import IHModels

@Suite("OfflineStore")
struct OfflineStoreTests {

    @Test("Migrates cleanly and starts empty")
    func migratesAndStartsEmpty() async throws {
        let store = try OfflineStore(path: nil)
        let songs = try await store.allSongSummaries()
        #expect(songs.isEmpty)
    }

    @Test("Round-trips upserted song summaries")
    func roundTripsSongSummaries() async throws {
        let store = try OfflineStore(path: nil)
        let one = SongSummary(
            songId: try #require(SongID(rawValue: "MP-2")),
            title: "Song Two",
            songbookAbbreviation: "MP",
            number: 2
        )
        let two = SongSummary(
            songId: try #require(SongID(rawValue: "MP-1")),
            title: "Song One",
            songbookAbbreviation: "MP",
            number: 1
        )

        try await store.upsert([one, two])
        let fetched = try await store.allSongSummaries()

        // Ordered by displayNumber, so "1" should come before "2" even
        // though it was upserted second.
        #expect(fetched.map(\.title) == ["Song One", "Song Two"])
    }

    @Test("Upserting the same SongID again replaces, not duplicates")
    func upsertReplacesExistingRow() async throws {
        let store = try OfflineStore(path: nil)
        let songId = try #require(SongID(rawValue: "MP-1"))
        let original = SongSummary(songId: songId, title: "Original Title", songbookAbbreviation: "MP", number: 1)
        let updated = SongSummary(songId: songId, title: "Updated Title", songbookAbbreviation: "MP", number: 1)

        try await store.upsert([original])
        try await store.upsert([updated])

        let fetched = try await store.allSongSummaries()
        #expect(fetched.count == 1)
        #expect(fetched.first?.title == "Updated Title")
    }
}

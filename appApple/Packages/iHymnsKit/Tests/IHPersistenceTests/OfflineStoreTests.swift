// OfflineStoreTests.swift
// IHPersistenceTests
//
// ELI5: Saves a couple of songs into an in-memory offline notebook, then
// checks reading them back gives the exact same songs, in the right order.
// Also covers the #181 favourites cache + offline pending-op queue (native
// login/account UI + favourites task).
import Foundation
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

    // MARK: - Favourites (#181)

    @Test("allFavorites starts empty")
    func favoritesStartEmpty() async throws {
        let store = try OfflineStore(path: nil)
        #expect(try await store.allFavorites().isEmpty)
    }

    @Test("upsertFavorite then allFavorites round-trips, most-recently-added first")
    func favoritesRoundTrip() async throws {
        let store = try OfflineStore(path: nil)
        let older = FavoriteSong(
            songId: try #require(SongID(rawValue: "MP-1")),
            title: "Song One",
            songbookAbbreviation: "MP",
            number: 1,
            tags: ["Easter"],
            addedAt: Date(timeIntervalSince1970: 1000)
        )
        let newer = FavoriteSong(
            songId: try #require(SongID(rawValue: "MP-2")),
            title: "Song Two",
            songbookAbbreviation: "MP",
            number: 2,
            addedAt: Date(timeIntervalSince1970: 2000)
        )

        try await store.upsertFavorite(older)
        try await store.upsertFavorite(newer)

        let fetched = try await store.allFavorites()
        #expect(fetched.map(\.title) == ["Song Two", "Song One"])
        #expect(fetched.last?.tags == ["Easter"])
    }

    @Test("upsertFavorite for an already-cached songId replaces, not duplicates")
    func favoritesUpsertReplaces() async throws {
        let store = try OfflineStore(path: nil)
        let songId = try #require(SongID(rawValue: "MP-1"))
        try await store.upsertFavorite(FavoriteSong(songId: songId, title: "Original", songbookAbbreviation: "MP", number: 1))
        try await store.upsertFavorite(FavoriteSong(songId: songId, title: "Updated", songbookAbbreviation: "MP", number: 1))

        let fetched = try await store.allFavorites()
        #expect(fetched.count == 1)
        #expect(fetched.first?.title == "Updated")
    }

    @Test("removeFavorite removes exactly that song")
    func favoritesRemove() async throws {
        let store = try OfflineStore(path: nil)
        let keep = try #require(SongID(rawValue: "MP-1"))
        let drop = try #require(SongID(rawValue: "MP-2"))
        try await store.upsertFavorite(FavoriteSong(songId: keep, title: "Keep", songbookAbbreviation: "MP", number: 1))
        try await store.upsertFavorite(FavoriteSong(songId: drop, title: "Drop", songbookAbbreviation: "MP", number: 2))

        try await store.removeFavorite(drop)

        let fetched = try await store.allFavorites()
        #expect(fetched.map(\.songId) == [keep])
    }

    @Test("removeFavorite on a song that was never cached is a harmless no-op")
    func favoritesRemoveMissingIsNoOp() async throws {
        let store = try OfflineStore(path: nil)
        try await store.removeFavorite(try #require(SongID(rawValue: "MP-404")))
        #expect(try await store.allFavorites().isEmpty)
    }

    @Test("replaceFavorites deletes absent entries and preserves already-known metadata for the rest")
    func favoritesReplacePreservesKnownMetadata() async throws {
        let store = try OfflineStore(path: nil)
        let known = try #require(SongID(rawValue: "MP-1"))
        let toDrop = try #require(SongID(rawValue: "MP-2"))
        let brandNew = try #require(SongID(rawValue: "MP-3"))

        // This device already knows MP-1's real title (e.g. the user
        // favourited it locally, or opened it before) and has MP-2 cached
        // too (about to be dropped by the authoritative pull below).
        try await store.upsertFavorite(FavoriteSong(songId: known, title: "Known Title", songbookAbbreviation: "MP", number: 1))
        try await store.upsertFavorite(FavoriteSong(songId: toDrop, title: "Will Be Dropped", songbookAbbreviation: "MP", number: 2))

        // The server's authoritative list: MP-1 (still favourited, tags
        // updated from another device) and MP-3 (a favourite from another
        // device this one has never seen — no title available).
        try await store.replaceFavorites(with: [
            FavoriteEntry(songId: known, tags: ["Easter"]),
            FavoriteEntry(songId: brandNew, tags: [])
        ])

        let fetched = try await store.allFavorites()
        #expect(Set(fetched.map(\.songId)) == [known, brandNew])

        let knownRow = try #require(fetched.first { $0.songId == known })
        #expect(knownRow.title == "Known Title") // preserved, not clobbered
        #expect(knownRow.tags == ["Easter"]) // updated from the server

        let newRow = try #require(fetched.first { $0.songId == brandNew })
        #expect(newRow.title == "") // honestly unknown — see FavoriteSong's header
    }

    @Test("clearFavorites empties both the favourite cache and the pending-op queue")
    func favoritesClearEmptiesBoth() async throws {
        let store = try OfflineStore(path: nil)
        let songId = try #require(SongID(rawValue: "MP-1"))
        try await store.upsertFavorite(FavoriteSong(songId: songId, title: "Song", songbookAbbreviation: "MP", number: 1))
        try await store.enqueuePendingFavoriteOp(PendingFavoriteOp(songId: songId, operation: .add, favorite: nil))

        try await store.clearFavorites()

        #expect(try await store.allFavorites().isEmpty)
        #expect(try await store.pendingFavoriteOps().isEmpty)
    }

    // MARK: - Favourites offline queue (#181)

    @Test("enqueuePendingFavoriteOp then pendingFavoriteOps round-trips")
    func pendingOpRoundTrips() async throws {
        let store = try OfflineStore(path: nil)
        let songId = try #require(SongID(rawValue: "MP-1"))
        let favorite = FavoriteSong(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1)
        try await store.enqueuePendingFavoriteOp(PendingFavoriteOp(songId: songId, operation: .add, favorite: favorite))

        let ops = try await store.pendingFavoriteOps()
        #expect(ops.count == 1)
        #expect(ops.first?.kind == .add)
        #expect(ops.first?.favoriteToReplay()?.title == "Amazing Grace")
    }

    @Test("A second enqueue for the SAME song REPLACES the first queued op, never duplicates")
    func pendingOpReplacesForSameSong() async throws {
        let store = try OfflineStore(path: nil)
        let songId = try #require(SongID(rawValue: "MP-1"))

        try await store.enqueuePendingFavoriteOp(PendingFavoriteOp(songId: songId, operation: .add, favorite: nil))
        try await store.enqueuePendingFavoriteOp(PendingFavoriteOp(songId: songId, operation: .remove, favorite: nil))

        let ops = try await store.pendingFavoriteOps()
        #expect(ops.count == 1)
        #expect(ops.first?.kind == .remove) // the LATEST intent wins
    }

    @Test("dequeuePendingFavoriteOp removes exactly that song's queued op")
    func pendingOpDequeue() async throws {
        let store = try OfflineStore(path: nil)
        let done = try #require(SongID(rawValue: "MP-1"))
        let stillQueued = try #require(SongID(rawValue: "MP-2"))
        try await store.enqueuePendingFavoriteOp(PendingFavoriteOp(songId: done, operation: .add, favorite: nil))
        try await store.enqueuePendingFavoriteOp(PendingFavoriteOp(songId: stillQueued, operation: .add, favorite: nil))

        try await store.dequeuePendingFavoriteOp(songId: done)

        let ops = try await store.pendingFavoriteOps()
        #expect(ops.map(\.songId) == [stillQueued.rawValue])
    }

    @Test("PendingFavoriteOp.favoriteToReplay() is nil for a .remove op")
    func pendingRemoveHasNoReplayFavorite() {
        let pendingOp = PendingFavoriteOp(songId: SongID(songbookAbbreviation: "MP", number: 1), operation: .remove, favorite: nil)
        #expect(pendingOp.favoriteToReplay() == nil)
    }
}

// The setlists half of this suite (#181) continues in
// `OfflineStoreSetlistsTests.swift` as an `extension OfflineStoreTests` —
// split out purely to stay under SwiftLint's `type_body_length` threshold
// now that this suite covers THREE cache families (song summaries,
// favourites, setlists); see that file's own header.

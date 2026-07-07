// OfflineStoreSetlistsTests.swift
// IHPersistenceTests
//
// ELI5: The setlists half of the `OfflineStore` test suite — saving
// setlists into the on-device notebook, reading them back in the right
// order, deleting one, reconciling with a server pull, and the offline
// pending-op queue. Split out of `OfflineStoreTests.swift` (same `@Suite`,
// via `extension OfflineStoreTests`) purely to stay under SwiftLint's
// `type_body_length` threshold now that suite covers three cache families
// (song summaries, favourites, setlists) — see that file's header.
import Foundation
import Testing
@testable import IHPersistence
import IHModels

extension OfflineStoreTests {

    // MARK: - Setlists (#181)

    @Test("allSetlists starts empty")
    func setlistsStartEmpty() async throws {
        let store = try OfflineStore(path: nil)
        #expect(try await store.allSetlists().isEmpty)
    }

    @Test("upsertSetlist then allSetlists round-trips, most-recently-touched first")
    func setlistsRoundTrip() async throws {
        let store = try OfflineStore(path: nil)
        let songId = try #require(SongID(rawValue: "MP-1008"))
        let older = Setlist(id: "slist-older", name: "Older", songs: [])
        let newer = Setlist(
            id: "slist-newer",
            name: "Newer",
            songs: [SetlistSongEntry(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)]
        )

        try await store.upsertSetlist(older, localUpdatedAt: Date(timeIntervalSince1970: 1000))
        try await store.upsertSetlist(newer, localUpdatedAt: Date(timeIntervalSince1970: 2000))

        let fetched = try await store.allSetlists()
        #expect(fetched.map(\.id) == ["slist-newer", "slist-older"])
        #expect(fetched.first?.songs.first?.title == "Amazing Grace")
    }

    @Test("upsertSetlist for an already-cached setlistId replaces, not duplicates")
    func setlistUpsertReplaces() async throws {
        let store = try OfflineStore(path: nil)
        try await store.upsertSetlist(Setlist(id: "slist-1", name: "Original"))
        try await store.upsertSetlist(Setlist(id: "slist-1", name: "Renamed"))

        let fetched = try await store.allSetlists()
        #expect(fetched.count == 1)
        #expect(fetched.first?.name == "Renamed")
    }

    @Test("deleteSetlistLocally removes exactly that setlist")
    func setlistDelete() async throws {
        let store = try OfflineStore(path: nil)
        try await store.upsertSetlist(Setlist(id: "slist-keep", name: "Keep"))
        try await store.upsertSetlist(Setlist(id: "slist-drop", name: "Drop"))

        try await store.deleteSetlistLocally("slist-drop")

        let fetched = try await store.allSetlists()
        #expect(fetched.map(\.id) == ["slist-keep"])
    }

    @Test("deleteSetlistLocally on a setlist that was never cached is a harmless no-op")
    func setlistDeleteMissingIsNoOp() async throws {
        let store = try OfflineStore(path: nil)
        try await store.deleteSetlistLocally("slist-nonexistent")
        #expect(try await store.allSetlists().isEmpty)
    }

    @Test("replaceSetlists deletes absent entries and adopts the authoritative content for the rest")
    func setlistsReplaceIsAuthoritative() async throws {
        let store = try OfflineStore(path: nil)
        try await store.upsertSetlist(Setlist(id: "slist-1", name: "Stale Name"))
        try await store.upsertSetlist(Setlist(id: "slist-2", name: "Will Be Dropped"))

        try await store.replaceSetlists(with: [Setlist(id: "slist-1", name: "Fresh Name"), Setlist(id: "slist-3", name: "New From Another Device")])

        let fetched = try await store.allSetlists()
        #expect(Set(fetched.map(\.id)) == ["slist-1", "slist-3"])
        #expect(fetched.first { $0.id == "slist-1" }?.name == "Fresh Name")
    }

    @Test("clearSetlists empties both the setlist cache and the pending-op queue")
    func setlistsClearEmptiesBoth() async throws {
        let store = try OfflineStore(path: nil)
        try await store.upsertSetlist(Setlist(id: "slist-1", name: "Test"))
        try await store.enqueuePendingSetlistOp(PendingSetlistOp(setlistId: "slist-1", operation: .upsert, snapshot: nil))

        try await store.clearSetlists()

        #expect(try await store.allSetlists().isEmpty)
        #expect(try await store.pendingSetlistOps().isEmpty)
    }

    // MARK: - Setlists offline queue (#181)

    @Test("enqueuePendingSetlistOp then pendingSetlistOps round-trips")
    func setlistPendingOpRoundTrips() async throws {
        let store = try OfflineStore(path: nil)
        let setlist = Setlist(id: "slist-1", name: "Evening Worship")
        try await store.enqueuePendingSetlistOp(PendingSetlistOp(setlistId: "slist-1", operation: .upsert, snapshot: setlist))

        let ops = try await store.pendingSetlistOps()
        #expect(ops.count == 1)
        #expect(ops.first?.kind == .upsert)
        #expect(ops.first?.setlistId == "slist-1")
    }

    @Test("A second enqueue for the SAME setlist REPLACES the first queued op, never duplicates")
    func setlistPendingOpReplacesForSameSetlist() async throws {
        let store = try OfflineStore(path: nil)
        try await store.enqueuePendingSetlistOp(PendingSetlistOp(setlistId: "slist-1", operation: .upsert, snapshot: Setlist(id: "slist-1", name: "First Edit")))
        try await store.enqueuePendingSetlistOp(PendingSetlistOp(setlistId: "slist-1", operation: .delete, snapshot: nil))

        let ops = try await store.pendingSetlistOps()
        #expect(ops.count == 1)
        #expect(ops.first?.kind == .delete) // the LATEST intent wins
    }

    @Test("dequeuePendingSetlistOp removes exactly that setlist's queued op")
    func setlistPendingOpDequeue() async throws {
        let store = try OfflineStore(path: nil)
        try await store.enqueuePendingSetlistOp(PendingSetlistOp(setlistId: "slist-done", operation: .upsert, snapshot: nil))
        try await store.enqueuePendingSetlistOp(PendingSetlistOp(setlistId: "slist-still-queued", operation: .upsert, snapshot: nil))

        try await store.dequeuePendingSetlistOp(setlistId: "slist-done")

        let ops = try await store.pendingSetlistOps()
        #expect(ops.map(\.setlistId) == ["slist-still-queued"])
    }

    @Test("clearAllPendingSetlistOps empties the ENTIRE queue in one call")
    func setlistPendingOpsClearAll() async throws {
        let store = try OfflineStore(path: nil)
        try await store.enqueuePendingSetlistOp(PendingSetlistOp(setlistId: "slist-1", operation: .upsert, snapshot: nil))
        try await store.enqueuePendingSetlistOp(PendingSetlistOp(setlistId: "slist-2", operation: .delete, snapshot: nil))

        try await store.clearAllPendingSetlistOps()

        #expect(try await store.pendingSetlistOps().isEmpty)
    }
}

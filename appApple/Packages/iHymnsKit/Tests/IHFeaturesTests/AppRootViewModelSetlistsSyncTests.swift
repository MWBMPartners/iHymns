// AppRootViewModelSetlistsSyncTests.swift
// IHFeaturesTests
//
// ELI5: The other half of the #181 setlists test suite — what happens when
// a sync fails and later succeeds (the offline queue), what sign-out
// forgets, pulling the server's authoritative list, and sharing a setlist
// this device doesn't actually know about. Split out of
// `AppRootViewModelSetlistsTests.swift` purely to stay under the repo's
// LOC-budget tripwire (`Scripts/loc-budget.sh`) — see that file's header for
// the full "why an `extension`, not a second `@Suite`" reasoning and the
// shared harness (`makeViewModel()`/`signIn(_:extraRouting:)`/
// `offlineHandler`/`SetlistsLockedBox`) this file reuses.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

extension AppRootViewModelSetlistsTests {

    // MARK: - Offline queue

    @Test("A mutation queues a PendingSetlistOp when the immediate sync fails, and flushPendingSetlistOps replays it once reachable")
    func mutationQueuesOnFailureThenFlushes() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()

            // First: sign in successfully, THEN go "offline" for the create.
            try await signIn(viewModel)
            MockURLProtocol.requestHandler = { request in
                if Self.action(of: request) == "user_setlists_sync" {
                    return (Self.response(status: 503), Data())
                }
                return (Self.response(status: 200), Data("{}".utf8))
            }
            let created = await viewModel.createSetlist(name: "Made Offline")

            // The UI-visible state already reflects the new setlist — this
            // IS the "works instantly even offline" contract, same as
            // favourites.
            #expect(await viewModel.setlists.contains { $0.id == created.id })
            let queuedBeforeFlush = try await viewModel.offlineStore.pendingSetlistOps()
            #expect(queuedBeforeFlush.count == 1)
            #expect(queuedBeforeFlush.first?.kind == .upsert)
            #expect(queuedBeforeFlush.first?.setlistId == created.id)

            // Now "come back online" and flush.
            let seenFlushRequest = SetlistsLockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                if Self.action(of: request) == "user_setlists_sync" {
                    seenFlushRequest.set(request)
                    return (Self.response(status: 200), Data(#"{"setlists":[{"id":"\#(created.id)","name":"Made Offline","songs":[]}]}"#.utf8))
                }
                return (Self.response(status: 200), Data("{}".utf8))
            }
            await viewModel.flushPendingSetlistOps()

            #expect(seenFlushRequest.current != nil)
            #expect(try await viewModel.offlineStore.pendingSetlistOps().isEmpty)
            #expect(await viewModel.setlists.contains { $0.id == created.id })
        }
    }

    @Test("flushPendingSetlistOps is a no-op when the queue is empty — never issues a needless sync call")
    func flushIsNoOpWhenQueueEmpty() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel)

            let callCount = LockedCounter()
            MockURLProtocol.requestHandler = { request in
                if Self.action(of: request) == "user_setlists_sync" { callCount.increment() }
                return (Self.response(status: 200), Data("{}".utf8))
            }
            await viewModel.flushPendingSetlistOps()

            #expect(callCount.current == 0)
        }
    }

    @Test("createSetlist is a no-op while signed out")
    func createSetlistNoOpWhileSignedOut() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let viewModel = try await makeViewModel()
            // `requestHandler` deliberately left `nil` — a signed-out create
            // must never even attempt a network call.
            let created = await viewModel.createSetlist(name: "Should Not Sync")
            #expect(await viewModel.setlists.map(\.id) == [created.id])
            // Queued locally for whenever the user does sign in — matches
            // `pushCurrentSetlistsToServer()`'s "no-op while signed out"
            // contract (it returns `false`, so `persistAndSync` queues it).
            let queued = try await viewModel.offlineStore.pendingSetlistOps()
            #expect(queued.map(\.setlistId) == [created.id])
        }
    }

    // MARK: - Sync protocol 2 (#1661, #1675)

    /// A throwaway store so a test never reads or writes the real `.standard` defaults.
    private func throwawayStateStore() -> SetlistSyncStateStore {
        let suite = "ih-setlist-sync-tests-\(UUID().uuidString)"
        // swiftlint:disable:next force_unwrapping
        return SetlistSyncStateStore(defaults: UserDefaults(suiteName: suite)!)
    }

    @Test("A delete is queued as a deleted id BEFORE the push, so the request can name it, and a successful push clears it")
    func deleteIsQueuedBeforePushAndClearedOnSuccess() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel(setlistSyncStateStore: throwawayStateStore())
            try await signIn(viewModel)

            // Offline: the delete stays queued and is the id the next sync names.
            MockURLProtocol.requestHandler = { request in
                if Self.action(of: request) == "user_setlists_sync" { return (Self.response(status: 503), Data()) }
                return (Self.response(status: 200), Data("{}".utf8))
            }
            let created = await viewModel.createSetlist(name: "Doomed")
            await viewModel.deleteSetlist(created.id)
            #expect(try await viewModel.offlineStore.pendingDeletedSetlistIds() == [created.id])

            // Back online: one successful push empties the whole queue, deletes included.
            MockURLProtocol.requestHandler = { request in
                if Self.action(of: request) == "user_setlists_sync" {
                    return (Self.response(status: 200), Data(#"{"setlists":[],"syncedAt":"2026-11-24 09:30:00","tombstones":[],"conflicts":[]}"#.utf8))
                }
                return (Self.response(status: 200), Data("{}".utf8))
            }
            await viewModel.flushPendingSetlistOps()
            #expect(try await viewModel.offlineStore.pendingDeletedSetlistIds().isEmpty)
            #expect(try await viewModel.offlineStore.pendingSetlistOps().isEmpty)
        }
    }

    @Test("A successful sync remembers syncedAt for this user, and keeps the old reading when the server sends none")
    func syncRemembersSyncedAtPerUser() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let store = throwawayStateStore()
            let viewModel = try await makeViewModel(setlistSyncStateStore: store)
            try await signIn(viewModel)
            #expect(await viewModel.currentUser?.id == 1)

            MockURLProtocol.requestHandler = { request in
                if Self.action(of: request) == "user_setlists_sync" {
                    return (Self.response(status: 200), Data(#"{"setlists":[],"syncedAt":"2026-11-24 10:00:00"}"#.utf8))
                }
                return (Self.response(status: 200), Data("{}".utf8))
            }
            _ = await viewModel.createSetlist(name: "One")
            #expect(store.syncedAt(forUser: 1) == "2026-11-24 10:00:00")
            #expect(store.syncedAt(forUser: 2) == nil)

            // A paused site answers read-only with syncedAt null: the reading we hold must survive.
            MockURLProtocol.requestHandler = { request in
                if Self.action(of: request) == "user_setlists_sync" {
                    return (Self.response(status: 200), Data(#"{"setlists":[],"syncedAt":null}"#.utf8))
                }
                return (Self.response(status: 200), Data("{}".utf8))
            }
            _ = await viewModel.createSetlist(name: "Two")
            #expect(store.syncedAt(forUser: 1) == "2026-11-24 10:00:00")
        }
    }

    @Test("Tombstoned setlists are dropped locally and conflicts are published")
    func syncAbsorbsTombstonesAndConflicts() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel(setlistSyncStateStore: throwawayStateStore())
            try await signIn(viewModel)

            MockURLProtocol.requestHandler = { request in
                if Self.action(of: request) == "user_setlists_sync" {
                    return (Self.response(status: 200), Data(#"""
                    {"setlists":[{"id":"slist-kept","name":"Kept","songs":[]}],
                     "syncedAt":"2026-11-24 10:05:00",
                     "tombstones":[{"id":"slist-elsewhere","deletedAt":"2026-11-24 10:00:00","reason":"user"}],
                     "conflicts":[{"id":"slist-kept","reason":"newer_on_server","serverUpdatedAt":"2026-11-24 10:04:59"}]}
                    """#.utf8))
                }
                return (Self.response(status: 200), Data("{}".utf8))
            }
            _ = await viewModel.createSetlist(name: "Anything")

            #expect(await viewModel.setlists.map(\.id) == ["slist-kept"])
            #expect(await viewModel.setlistConflicts.map(\.id) == ["slist-kept"])
        }
    }

    @Test("signOut forgets the saved syncedAt for that user")
    func signOutClearsSyncedAt() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let store = throwawayStateStore()
            let viewModel = try await makeViewModel(setlistSyncStateStore: store)
            try await signIn(viewModel)
            store.record("2026-11-24 10:00:00", forUser: 1)

            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), Data()) }
            try await viewModel.signOut()

            #expect(store.syncedAt(forUser: 1) == nil)
        }
    }

    // MARK: - Sign-out

    @Test("signOut forgets every locally-cached setlist and clears the pending queue")
    func signOutClearsLocalSetlists() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel) { _, action in
                guard action == "user_setlists_sync" else { return nil }
                return (Self.response(status: 200), Data(#"{"setlists":[{"id":"slist-1","name":"Test","songs":[]}]}"#.utf8))
            }
            _ = await viewModel.createSetlist(name: "Test")
            #expect(await viewModel.setlists.isEmpty == false)

            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), Data()) } // auth_logout
            try await viewModel.signOut()

            #expect(await viewModel.setlists.isEmpty)
            #expect(try await viewModel.offlineStore.allSetlists().isEmpty)
        }
    }

    // MARK: - Refresh / merge

    @Test("refreshSetlistsFromServer reconciles the local cache to the server's authoritative list")
    func refreshSetlistsFromServerReconciles() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel)

            MockURLProtocol.requestHandler = { request in
                if Self.action(of: request) == "user_setlists" {
                    return (Self.response(status: 200), Data(#"{"setlists":[{"id":"slist-remote","name":"From Another Device","songs":[]}]}"#.utf8))
                }
                return (Self.response(status: 200), Data("{}".utf8))
            }
            await viewModel.refreshSetlistsFromServer()

            #expect(await viewModel.setlists.map(\.id) == ["slist-remote"])
            #expect(try await viewModel.offlineStore.allSetlists().map(\.id) == ["slist-remote"])
        }
    }

    @Test("shareSetlist(_:) throws SetlistNotFoundLocally for an id this device doesn't know")
    func shareUnknownSetlistThrows() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel)

            await #expect(throws: SetlistNotFoundLocally.self) {
                _ = try await viewModel.shareSetlist("slist-does-not-exist")
            }
        }
    }
}

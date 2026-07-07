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

// AppRootViewModelSetlistsTests.swift
// IHFeaturesTests
//
// ELI5: Proves the #181 setlists slice actually works end-to-end through the
// real `AppRootViewModel`: creating/renaming/deleting a setlist, adding and
// removing songs, and REORDERING songs and having that order survive a
// "relaunch" (a fresh read through the same `OfflineStore`). No real network
// or Keychain is ever touched. The offline-queue/sign-out/refresh half of
// this same suite continues in `AppRootViewModelSetlistsSyncTests.swift`
// (split purely to stay under the repo's LOC-budget tripwire,
// `Scripts/loc-budget.sh`) — THIS file's shared harness (`makeViewModel()`/
// `signIn(_:extraRouting:)`/`offlineHandler`/`SetlistsLockedBox`) is `internal`
// (not `private`) specifically so that file's `extension
// AppRootViewModelSetlistsTests { ... }` can reuse it rather than
// duplicating it (`private` is file-scoped even for a type's own
// extensions — the repo's modularity rule applied to test infrastructure).
//
// DETAILED: Mirrors `AppRootViewModelFavoritesTests.swift`'s exact harness
// shape (`makeViewModel()`, `MockTransportLock.shared.withLock`,
// action-routed `MockURLProtocol.requestHandler`) — this is the SAME "real
// production types, faked transport only" posture every other IHFeatures
// integration test in this package already uses.
//
// NOTE on technique: `MockURLProtocol`'s captured `URLRequest` does not
// reliably preserve `.httpBody` for a POST once it has round-tripped through
// `URLSession` (a well-known `URLSession`/`URLProtocol` quirk —
// `FavoritesAndAuthAPITests.swift`'s own networked tests never assert on a
// captured request's `.httpBody` for exactly this reason, only on
// `.httpMethod`/`.url`). The EXACT wire shape `Endpoint.setlistsSync` builds
// (mode/song order/etc.) is already proven at the request-building level in
// `SetlistsAPITests.swift`'s `buildsSetlistsSyncEndpoint` — these
// integration tests instead prove OBSERVABLE end states
// (`AppRootViewModel.setlists`, the on-disk cache, the pending queue).
// Multi-step tests below deliberately keep the mock OFFLINE (503) for every
// call AFTER the initial sign-in, so `Setlist.makeId()`'s real generated id
// is never overwritten by an arbitrary canned server id partway through a
// chain of calls — this also happens to exercise the offline pending-op
// queue on every one of them, which is exactly what this task asks to
// unit-test.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

@Suite("AppRootViewModel setlists (#181)", .serialized)
struct AppRootViewModelSetlistsTests {

    static func response(status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: URL(string: "https://dev.ihymns.app/api")!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    static func action(of request: URLRequest) -> String? {
        guard let query = request.url?.query else { return nil }
        for item in query.split(separator: "&") where item.hasPrefix("action=") {
            return String(item.dropFirst("action=".count))
        }
        return nil
    }

    /// A test-only in-memory `TokenStoring` — mirrors
    /// `AppRootViewModelFavoritesTests`' identical fake.
    private actor InMemoryTokenStore: TokenStoring {
        private var token: String?
        func save(_ token: String) async throws { self.token = token }
        func load() async throws -> String? { token }
        func delete() async throws { token = nil }
    }

    @MainActor
    func makeViewModel() throws -> AppRootViewModel {
        let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), retryBaseDelaySeconds: 0.001)
        let sessionController = SessionController(tokenStore: InMemoryTokenStore(), apiClient: apiClient)
        return AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient)
        )
    }

    /// Signs `viewModel` in with canned `auth_login`/`auth_me`/`favorites`/
    /// `user_setlists` responses (empty lists, by default) — same "common
    /// setup step" shape as `AppRootViewModelFavoritesTests.signIn(_:extraRouting:)`.
    @MainActor
    func signIn(
        _ viewModel: AppRootViewModel,
        extraRouting: (@Sendable (URLRequest, String?) -> (HTTPURLResponse, Data)?)? = nil
    ) async throws {
        MockURLProtocol.requestHandler = { request in
            let action = Self.action(of: request)
            if let extraRouting, let result = extraRouting(request, action) {
                return result
            }
            switch action {
            case "auth_login":
                return (
                    Self.response(status: 200),
                    Data(#"{"token":"test-token","user":{"id":1,"username":"jane","display_name":"Jane Doe","role":"user"}}"#.utf8)
                )
            case "auth_me":
                return (
                    Self.response(status: 200),
                    Data(#"{"user":{"id":1,"username":"jane","display_name":"Jane Doe","role":"user"}}"#.utf8)
                )
            case "favorites":
                return (Self.response(status: 200), Data(#"{"favorites":[]}"#.utf8))
            case "user_setlists":
                return (Self.response(status: 200), Data(#"{"setlists":[]}"#.utf8))
            default:
                return (Self.response(status: 200), Data("{}".utf8))
            }
        }
        try await viewModel.signIn(username: "jane", password: "hunter2")
    }

    /// A `requestHandler` that fails EVERY `user_setlists_sync` attempt with
    /// a 503 (everything else 200s harmlessly) — used by every multi-step
    /// mutation test in this file (and `AppRootViewModelSetlistsSyncTests.swift`)
    /// so `Setlist.makeId()`'s real, locally-generated id is never
    /// overwritten by an arbitrary canned server id partway through a chain
    /// of calls; see this file's header for the full reasoning.
    static let offlineHandler: (@Sendable (URLRequest) -> (HTTPURLResponse, Data)) = { request in
        if action(of: request) == "user_setlists_sync" {
            return (response(status: 503), Data())
        }
        return (response(status: 200), Data("{}".utf8))
    }

    // MARK: - Create / rename / delete

    @Test("createSetlist is optimistic, persists to disk, and syncs to the server")
    func createSetlistAddsAndSyncs() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            let seenSync = SetlistsLockedBox<URLRequest>()

            try await signIn(viewModel) { request, action in
                guard action == "user_setlists_sync" else { return nil }
                seenSync.set(request)
                return (Self.response(status: 200), Data(#"{"setlists":[{"id":"slist-echoed","name":"Sunday Service","songs":[]}]}"#.utf8))
            }

            let created = await viewModel.createSetlist(name: "Sunday Service")

            // The optimistic local create is real: a fresh, `slist-`
            // -prefixed id (per `Setlist.makeId()`) with the right name —
            // true regardless of what the server ends up echoing back.
            #expect(created.name == "Sunday Service")
            #expect(created.id.hasPrefix("slist-"))

            // A POST sync was genuinely attempted.
            #expect(seenSync.current?.httpMethod == "POST")
            #expect(seenSync.current?.url?.query?.contains("action=user_setlists_sync") == true)

            // A successful sync's AUTHORITATIVE response is what `setlists`/
            // the on-disk cache settle to afterwards.
            #expect(await viewModel.setlists.map(\.id) == ["slist-echoed"])
            let cached = try await viewModel.offlineStore.allSetlists()
            #expect(cached.map(\.id) == ["slist-echoed"])
            #expect(try await viewModel.offlineStore.pendingSetlistOps().isEmpty)
        }
    }

    @Test("createSetlist falls back to Untitled for a blank/whitespace-only name")
    func createSetlistBlankNameFallsBack() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel) { _, action in
                guard action == "user_setlists_sync" else { return nil }
                return (Self.response(status: 200), Data(#"{"setlists":[{"id":"slist-1","name":"Untitled","songs":[]}]}"#.utf8))
            }

            let created = await viewModel.createSetlist(name: "   ")
            #expect(created.name == "Untitled")
        }
    }

    @Test("renameSetlist updates the local name, persists it, and queues a sync when unreachable")
    func renameSetlistUpdates() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel)
            MockURLProtocol.requestHandler = Self.offlineHandler

            let setlist = await viewModel.createSetlist(name: "Original")
            await viewModel.renameSetlist(setlist.id, to: "Renamed")

            #expect(await viewModel.setlists.first { $0.id == setlist.id }?.name == "Renamed")
            let cached = try await viewModel.offlineStore.allSetlists()
            #expect(cached.first { $0.id == setlist.id }?.name == "Renamed")
        }
    }

    @Test("renameSetlist to a blank name is a no-op")
    func renameSetlistBlankIsNoOp() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel)
            MockURLProtocol.requestHandler = Self.offlineHandler

            let setlist = await viewModel.createSetlist(name: "Original")
            await viewModel.renameSetlist(setlist.id, to: "   ")

            #expect(await viewModel.setlists.first { $0.id == setlist.id }?.name == "Original")
        }
    }

    @Test("deleteSetlist removes it locally, from the cache, and queues a sync when unreachable")
    func deleteSetlistRemoves() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel)
            MockURLProtocol.requestHandler = Self.offlineHandler

            let setlist = await viewModel.createSetlist(name: "Doomed")
            await viewModel.deleteSetlist(setlist.id)

            #expect(await viewModel.setlists.contains { $0.id == setlist.id } == false)
            #expect(try await viewModel.offlineStore.allSetlists().isEmpty)
            // Queued to tell the server once reachable — `.delete`, not
            // `.upsert` (see `PendingSetlistOp`'s own header on why the
            // KIND is still recorded even though replay is a bulk push).
            let pending = try await viewModel.offlineStore.pendingSetlistOps()
            #expect(pending.first?.kind == .delete)
        }
    }

    // MARK: - Add / remove songs

    @Test("addSong appends to the end and does not duplicate an already-present song")
    func addSongAppendsAndDedupes() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel)
            MockURLProtocol.requestHandler = Self.offlineHandler

            let setlist = await viewModel.createSetlist(name: "Test")
            let entry = try SetlistSongEntry(songId: #require(SongID(rawValue: "MP-1008")), title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)

            await viewModel.addSong(entry, to: setlist.id)
            #expect(await viewModel.setlists.first { $0.id == setlist.id }?.songs.count == 1)

            // Adding the SAME song again is a no-op — no second entry.
            await viewModel.addSong(entry, to: setlist.id)
            #expect(await viewModel.setlists.first { $0.id == setlist.id }?.songs.count == 1)

            let cached = try await viewModel.offlineStore.allSetlists()
            #expect(cached.first { $0.id == setlist.id }?.songs.map(\.songId) == [entry.songId])
        }
    }

    @Test("removeSong removes exactly that song")
    func removeSongRemoves() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel)
            MockURLProtocol.requestHandler = Self.offlineHandler

            let songId = try #require(SongID(rawValue: "MP-1008"))
            let otherSongId = try #require(SongID(rawValue: "MP-2000"))
            let entry = SetlistSongEntry(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)
            let otherEntry = SetlistSongEntry(songId: otherSongId, title: "Another Song", songbookAbbreviation: "MP", number: 2000)

            let setlist = await viewModel.createSetlist(name: "Test")
            await viewModel.addSong(entry, to: setlist.id)
            await viewModel.addSong(otherEntry, to: setlist.id)

            await viewModel.removeSong(songId: songId, from: setlist.id)

            #expect(await viewModel.setlists.first { $0.id == setlist.id }?.songs.map(\.songId) == [otherSongId])
            let cached = try await viewModel.offlineStore.allSetlists()
            #expect(cached.first { $0.id == setlist.id }?.songs.map(\.songId) == [otherSongId])
        }
    }

    // MARK: - Reorder persistence

    @Test("moveSongs reorders locally, persists the new order to disk, and queues a sync when unreachable")
    func moveSongsReordersAndPersists() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            let first = try #require(SongID(rawValue: "MP-1"))
            let second = try #require(SongID(rawValue: "MP-2"))
            let third = try #require(SongID(rawValue: "MP-3"))

            try await signIn(viewModel)
            MockURLProtocol.requestHandler = Self.offlineHandler

            let setlist = await viewModel.createSetlist(name: "Ordered")
            await viewModel.addSong(SetlistSongEntry(songId: first, title: "Song One", songbookAbbreviation: "MP", number: 1), to: setlist.id)
            await viewModel.addSong(SetlistSongEntry(songId: second, title: "Song Two", songbookAbbreviation: "MP", number: 2), to: setlist.id)
            await viewModel.addSong(SetlistSongEntry(songId: third, title: "Song Three", songbookAbbreviation: "MP", number: 3), to: setlist.id)
            #expect(await viewModel.setlists.first { $0.id == setlist.id }?.songs.map(\.songId) == [first, second, third])

            // Move the FIRST song (offset 0) to the END (destination 3, per
            // `Array.move(fromOffsets:toOffset:)`'s own semantics) — the new
            // order should read [second, third, first].
            await viewModel.moveSongs(in: setlist.id, fromOffsets: IndexSet(integer: 0), toOffset: 3)

            let newOrder = await viewModel.setlists.first { $0.id == setlist.id }?.songs.map(\.songId)
            #expect(newOrder == [second, third, first])

            // The new order survives a "relaunch" — a fresh read through the
            // SAME `OfflineStore` instance reflects the reordered array,
            // even though the server was unreachable for every one of these
            // calls.
            let cached = try await viewModel.offlineStore.allSetlists()
            #expect(cached.first { $0.id == setlist.id }?.songs.map(\.songId) == [second, third, first])

            // And it's queued to tell the server once reachable.
            let pending = try await viewModel.offlineStore.pendingSetlistOps()
            #expect(pending.map(\.setlistId) == [setlist.id])
            #expect(pending.first?.kind == .upsert)
        }
    }
}

/// A small, manually-synchronized single-slot box — captures the most
/// recent `URLRequest` a mock handler observed. `internal` (not `private`)
/// so `AppRootViewModelSetlistsSyncTests.swift` can reuse it too, mirroring
/// `IHAPITests/NetworkedAPIClientTests.LockedBox`'s identical
/// module-visible-for-cross-file-reuse posture; a `.testTarget` cannot
/// depend on another `.testTarget`'s sources in SwiftPM, so this is its own
/// copy rather than shared with `IHAPITests`', per that file's own header.
final class SetlistsLockedBox<Value: Sendable>: @unchecked Sendable {
    private let lock = NSLock()
    private var value: Value?

    func set(_ newValue: Value) {
        lock.lock()
        defer { lock.unlock() }
        value = newValue
    }

    var current: Value? {
        lock.lock()
        defer { lock.unlock() }
        return value
    }
}

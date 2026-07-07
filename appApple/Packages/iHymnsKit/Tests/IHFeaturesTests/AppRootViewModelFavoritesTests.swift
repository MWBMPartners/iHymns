// AppRootViewModelFavoritesTests.swift
// IHFeaturesTests
//
// ELI5: Proves the #181 favourites slice actually works end-to-end through
// the real `AppRootViewModel` (not just the lower-level `OfflineStore`/
// `APIClient` pieces each already have their own tests for): signing in,
// toggling a favourite on/off, what happens when the toggle's network call
// fails (queued, then replayed once we're back online), and what happens on
// sign-out (everything local is forgotten). No real network or Keychain is
// ever touched.
//
// DETAILED: Mirrors `CatalogueAndSongDetailLoadingTests.swift`'s exact
// harness shape (`makeViewModel()`, `MockTransportLock.shared.withLock`,
// action-routed `MockURLProtocol.requestHandler`) — this is the SAME "real
// production types, faked transport only" posture every other IHFeatures
// integration test in this package already uses. Every "seed a favourite
// first" step below goes through the REAL `toggleFavorite(...)` add path
// (never a raw internal-property poke) — a slightly longer setup per test,
// but it means each test is also implicitly re-proving the add path works,
// and it never risks drifting from whatever `toggleFavorite`'s actual
// optimistic-update shape happens to be.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

@Suite("AppRootViewModel favourites (#181)", .serialized)
struct AppRootViewModelFavoritesTests {

    private static func response(status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: URL(string: "https://dev.ihymns.app/api")!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    private static func action(of request: URLRequest) -> String? {
        guard let query = request.url?.query else { return nil }
        for item in query.split(separator: "&") where item.hasPrefix("action=") {
            return String(item.dropFirst("action=".count))
        }
        return nil
    }

    /// A test-only in-memory `TokenStoring` — mirrors `IHAuthTests`' fake so
    /// this suite never touches the real Keychain.
    private actor InMemoryTokenStore: TokenStoring {
        private var token: String?
        func save(_ token: String) async throws { self.token = token }
        func load() async throws -> String? { token }
        func delete() async throws { token = nil }
    }

    /// Builds a real `AppRootViewModel` wired to a `MockURLProtocol`-backed
    /// `APIClient` and a fresh in-memory `OfflineStore` — every engine is
    /// the real production type, only the network transport is faked.
    @MainActor
    private func makeViewModel() throws -> AppRootViewModel {
        let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), retryBaseDelaySeconds: 0.001)
        let sessionController = SessionController(tokenStore: InMemoryTokenStore(), apiClient: apiClient)
        return AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient)
        )
    }

    /// Signs `viewModel` in with canned `auth_login`/`auth_me`/`favorites`
    /// responses (an empty favourites list, by default) — the common setup
    /// step every test below starts from. `extraRouting`, if supplied, is
    /// consulted FIRST for any action this default routing doesn't
    /// recognise (e.g. a test's own `favorites_sync`/`favorites_remove`
    /// canned response).
    @MainActor
    private func signIn(
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
            default:
                return (Self.response(status: 200), Data("{}".utf8))
            }
        }
        try await viewModel.signIn(username: "jane", password: "hunter2")
    }

    @Test("toggleFavorite (add) is optimistic, persists to disk, and syncs to the server")
    func toggleFavoriteAddsAndSyncs() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            let seenSync = LockedBox<URLRequest>()

            try await signIn(viewModel) { request, action in
                guard action == "favorites_sync" else { return nil }
                seenSync.set(request)
                return (Self.response(status: 200), Data(#"{"favorites":[{"id":"MP-1008","tags":[]}]}"#.utf8))
            }

            let songId = try #require(SongID(rawValue: "MP-1008"))
            await viewModel.toggleFavorite(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)

            #expect(await viewModel.isFavorite(songId) == true)
            #expect(await viewModel.favorites.first?.title == "Amazing Grace")
            #expect(seenSync.current?.httpMethod == "POST")

            // Persisted to the on-disk cache too, not just the in-memory
            // mirror — offline-first survives a "relaunch" (a fresh read
            // through the SAME OfflineStore instance, simulating the next
            // launch's `loadFavoritesIfNeeded()`).
            let cached = try await viewModel.offlineStore.allFavorites()
            #expect(cached.map(\.songId) == [songId])

            // Nothing left queued — the sync succeeded first try.
            #expect(try await viewModel.offlineStore.pendingFavoriteOps().isEmpty)
        }
    }

    @Test("toggleFavorite (remove) removes locally and tells the server")
    func toggleFavoriteRemoves() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-1008"))

            // Seed: add it first, through the real add path.
            try await signIn(viewModel) { _, action in
                guard action == "favorites_sync" else { return nil }
                return (Self.response(status: 200), Data(#"{"favorites":[{"id":"MP-1008","tags":[]}]}"#.utf8))
            }
            await viewModel.toggleFavorite(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)
            #expect(await viewModel.isFavorite(songId) == true)

            // Now remove it.
            let seenRemove = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                if Self.action(of: request) == "favorites_remove" {
                    seenRemove.set(request)
                    return (Self.response(status: 200), Data(#"{"ok":true}"#.utf8))
                }
                return (Self.response(status: 200), Data("{}".utf8))
            }
            await viewModel.toggleFavorite(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)

            #expect(await viewModel.isFavorite(songId) == false)
            #expect(seenRemove.current?.url?.query?.contains("action=favorites_remove") == true)
            #expect(try await viewModel.offlineStore.allFavorites().isEmpty)
        }
    }

    @Test("toggleFavorite queues a PendingFavoriteOp when the immediate sync fails, and flushPendingFavoriteOps replays it once reachable")
    func toggleFavoriteQueuesOnFailureThenFlushes() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-1008"))

            // First: favourite it while "offline" — favorites_sync 503s.
            try await signIn(viewModel) { _, action in
                guard action == "favorites_sync" else { return nil }
                return (Self.response(status: 503), Data())
            }
            await viewModel.toggleFavorite(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)

            // The UI-visible state already reflects the favourite — this IS
            // the "works instantly even offline" contract.
            #expect(await viewModel.isFavorite(songId) == true)
            let queuedBeforeFlush = try await viewModel.offlineStore.pendingFavoriteOps()
            #expect(queuedBeforeFlush.count == 1)
            #expect(queuedBeforeFlush.first?.kind == .add)

            // Now "come back online" — favorites_sync succeeds — and flush.
            let seenFlushRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                let action = Self.action(of: request)
                if action == "favorites_sync" {
                    seenFlushRequest.set(request)
                    return (Self.response(status: 200), Data(#"{"favorites":[{"id":"MP-1008","tags":[]}]}"#.utf8))
                }
                return (Self.response(status: 200), Data("{}".utf8))
            }
            await viewModel.flushPendingFavoriteOps()

            #expect(seenFlushRequest.current != nil)
            #expect(try await viewModel.offlineStore.pendingFavoriteOps().isEmpty)
            #expect(await viewModel.isFavorite(songId) == true)
        }
    }

    @Test("refreshFavoritesFromServer reconciles the local cache to the server's authoritative list")
    func refreshFavoritesFromServerReconciles() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            let kept = try #require(SongID(rawValue: "MP-1008"))
            let dropped = try #require(SongID(rawValue: "MP-2000"))

            try await signIn(viewModel) { _, action in
                guard action == "favorites_sync" else { return nil }
                return (Self.response(status: 200), Data(#"{"favorites":[]}"#.utf8))
            }
            await viewModel.toggleFavorite(songId: kept, title: "Kept Song", songbookAbbreviation: "MP", number: 1008)
            await viewModel.toggleFavorite(songId: dropped, title: "Dropped Song", songbookAbbreviation: "MP", number: 2000)
            #expect(await viewModel.favorites.count == 2)

            // The server's authoritative pull no longer has `dropped`, and
            // has picked up a server-set tag for `kept`.
            MockURLProtocol.requestHandler = { request in
                if Self.action(of: request) == "favorites" {
                    return (Self.response(status: 200), Data(#"{"favorites":[{"id":"MP-1008","tags":["Easter"]}]}"#.utf8))
                }
                return (Self.response(status: 200), Data("{}".utf8))
            }
            await viewModel.refreshFavoritesFromServer()

            let favorites = await viewModel.favorites
            #expect(favorites.map(\.songId) == [kept])
            #expect(favorites.first?.title == "Kept Song") // preserved, not clobbered
            #expect(favorites.first?.tags == ["Easter"]) // updated from the server
        }
    }

    @Test("signOut forgets every locally-cached favourite and clears the pending queue")
    func signOutClearsLocalFavorites() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-1008"))

            try await signIn(viewModel) { _, action in
                guard action == "favorites_sync" else { return nil }
                return (Self.response(status: 200), Data(#"{"favorites":[{"id":"MP-1008","tags":[]}]}"#.utf8))
            }
            await viewModel.toggleFavorite(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)
            #expect(await viewModel.favorites.isEmpty == false)

            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), Data()) } // auth_logout
            try await viewModel.signOut()

            #expect(await viewModel.favorites.isEmpty)
            #expect(await viewModel.currentUser == nil)
            #expect(try await viewModel.offlineStore.allFavorites().isEmpty)
        }
    }

    @Test("toggleFavorite is a no-op while signed out")
    func toggleFavoriteNoOpWhileSignedOut() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let viewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-1008"))
            // `requestHandler` deliberately left `nil` — a signed-out toggle
            // must never even attempt a network call.
            await viewModel.toggleFavorite(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)
            #expect(await viewModel.isFavorite(songId) == false)
        }
    }

    @Test("loadFavoritesIfNeeded only reads the local cache once")
    func loadFavoritesIfNeededOnlyOnce() async throws {
        try await MockTransportLock.shared.withLock {
            let viewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-1008"))
            try await viewModel.offlineStore.upsertFavorite(
                FavoriteSong(songId: songId, title: "Seeded", songbookAbbreviation: "MP", number: 1008)
            )

            await viewModel.loadFavoritesIfNeeded()
            #expect(await viewModel.favorites.count == 1)

            // A second cached favourite lands in the store directly
            // (bypassing the view model) — if `loadFavoritesIfNeeded()`
            // incorrectly re-read every time, this second call WOULD pick
            // it up; the one-shot guard means it must NOT.
            let secondSongId = try #require(SongID(rawValue: "MP-2000"))
            try await viewModel.offlineStore.upsertFavorite(
                FavoriteSong(songId: secondSongId, title: "Added After", songbookAbbreviation: "MP", number: 2000)
            )
            await viewModel.loadFavoritesIfNeeded()
            #expect(await viewModel.favorites.count == 1)
        }
    }

    @Test("restoreSessionIfNeeded, given an already-valid Keychain token, signs in AND syncs favourites (closes #1398's noted gap)")
    func restoreSessionSignsInAndSyncsFavorites() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), retryBaseDelaySeconds: 0.001)
            let tokenStore = InMemoryTokenStore()
            try await tokenStore.save("already-valid-token")
            let sessionController = SessionController(tokenStore: tokenStore, apiClient: apiClient)
            let viewModel = await AppRootViewModel(
                sessionController: sessionController,
                apiClient: apiClient,
                offlineStore: try OfflineStore(path: nil),
                liveFollowEngine: LiveFollowEngine(apiClient: apiClient)
            )

            MockURLProtocol.requestHandler = { request in
                switch Self.action(of: request) {
                case "auth_me":
                    return (Self.response(status: 200), Data(#"{"user":{"id":1,"username":"jane","display_name":"Jane Doe","role":"user"}}"#.utf8))
                case "favorites":
                    return (Self.response(status: 200), Data(#"{"favorites":[{"id":"MP-1008","tags":[]}]}"#.utf8))
                default:
                    return (Self.response(status: 200), Data("{}".utf8))
                }
            }

            await viewModel.restoreSessionIfNeeded()

            #expect(await viewModel.sessionState == .signedIn(token: "already-valid-token"))
            #expect(await viewModel.currentUser?.username == "jane")
            #expect(await viewModel.favorites.map(\.songId.rawValue) == ["MP-1008"])
        }
    }
}

/// A small, manually-synchronized single-slot box — captures the most
/// recent `URLRequest` a mock handler observed, for assertions made safely
/// back on the test's own task after the `await` completes (mirrors
/// `IHAPITests/NetworkedAPIClientTests.LockedBox` /
/// `IHAuthTests/SessionControllerTests.LockedRequestBox`; kept as its own
/// tiny type here rather than sharing either of those, since a
/// `.testTarget` cannot depend on another `.testTarget`'s sources in
/// SwiftPM).
private final class LockedBox<Value: Sendable>: @unchecked Sendable {
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

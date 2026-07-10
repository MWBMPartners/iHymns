// AppRootViewModelAccountDeleteTests.swift
// IHFeaturesTests
//
// ELI5: Proves in-app account deletion (#1477, App Review §5.1.1(v)) works
// end-to-end through the real `AppRootViewModel`: a successful delete wipes
// every piece of local per-account state (identity, favourites, setlists) —
// exactly like signing out — while a REJECTED re-auth attempt (wrong
// password, expired code, rate-limited, or "you're the last Global Admin")
// leaves everything untouched so the user can simply try again. No real
// network or Keychain is ever touched.
//
// DETAILED: Mirrors `AppRootViewModelFavoritesTests.swift`'s exact harness
// shape (`makeViewModel()`, `signIn(...)`, `MockTransportLock.shared
// .withLock`, action-routed `MockURLProtocol.requestHandler`) — the SAME
// "real production types, faked transport only" posture every IHFeatures
// integration test in this package already uses.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

@Suite("AppRootViewModel account deletion (#1477)", .serialized)
struct AppRootViewModelAccountDeleteTests {

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
    /// responses — the common setup step every test below starts from.
    /// `extraRouting`, if supplied, is consulted FIRST for any action this
    /// default routing doesn't recognise (mirrors
    /// `AppRootViewModelFavoritesTests.signIn(_:extraRouting:)` exactly).
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

    @Test("deleteAccount(reauth: .password) on success clears identity, favourites, setlists, and signs out")
    func deleteAccountSucceedsClearsEverything() async throws {
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

            let seenRequest = LockedRequestBox()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(status: 200), Data(#"{"ok":true,"deleted":true}"#.utf8))
            }
            try await viewModel.deleteAccount(reauth: .password("hunter2"))

            #expect(seenRequest.current?.url?.query?.contains("action=account_delete") == true)
            #expect(await viewModel.sessionState == .signedOut)
            #expect(await viewModel.currentUser == nil)
            #expect(await viewModel.favorites.isEmpty)
            #expect(try await viewModel.offlineStore.allFavorites().isEmpty)
            #expect(await viewModel.setlists.isEmpty)
        }
    }

    @Test("deleteAccount(reauth:) on a wrong/expired re-auth credential (401) leaves the session and cached data untouched")
    func deleteAccountRejectedReauthLeavesStateIntact() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-1008"))

            try await signIn(viewModel) { _, action in
                guard action == "favorites_sync" else { return nil }
                return (Self.response(status: 200), Data(#"{"favorites":[{"id":"MP-1008","tags":[]}]}"#.utf8))
            }
            await viewModel.toggleFavorite(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)

            MockURLProtocol.requestHandler = { _ in (Self.response(status: 401), Data()) }
            await #expect(throws: APIError.unauthorized) {
                try await viewModel.deleteAccount(reauth: .password("wrong"))
            }

            #expect(await viewModel.sessionState.isSignedIn == true)
            #expect(await viewModel.currentUser != nil)
            #expect(await viewModel.favorites.isEmpty == false)
        }
    }

    @Test("deleteAccount(reauth:) surfaces .rateLimited (429) without touching local state")
    func deleteAccountRateLimitedLeavesStateIntact() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel)

            MockURLProtocol.requestHandler = { _ in (Self.response(status: 429), Data()) }
            await #expect(throws: APIError.self) {
                try await viewModel.deleteAccount(reauth: .emailCode("000000"))
            }

            #expect(await viewModel.sessionState.isSignedIn == true)
        }
    }

    @Test("deleteAccount(reauth:) surfaces the 409 last-Global-Admin conflict without touching local state")
    func deleteAccountLastGlobalAdminConflictLeavesStateIntact() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel)

            MockURLProtocol.requestHandler = { _ in (Self.response(status: 409), Data()) }
            await #expect(throws: APIError.server(status: 409, message: nil)) {
                try await viewModel.deleteAccount(reauth: .password("hunter2"))
            }

            #expect(await viewModel.sessionState.isSignedIn == true)
        }
    }
}

/// A small, manually-synchronized single-slot box — captures the most
/// recent `URLRequest` a mock handler observed, for assertions made safely
/// back on the test's own task after the `await` completes. `private` is
/// file-scoped in Swift and a `.testTarget` cannot depend on another
/// `.testTarget`'s sources in SwiftPM, so this mirrors the identical
/// per-file copy `SessionControllerTests.swift`/`AppRootViewModelFavoritesTests
/// .swift` each already declare, rather than sharing one across files.
private final class LockedRequestBox: @unchecked Sendable {
    private let lock = NSLock()
    private var value: URLRequest?

    func set(_ newValue: URLRequest) {
        lock.lock()
        defer { lock.unlock() }
        value = newValue
    }

    var current: URLRequest? {
        lock.lock()
        defer { lock.unlock() }
        return value
    }
}

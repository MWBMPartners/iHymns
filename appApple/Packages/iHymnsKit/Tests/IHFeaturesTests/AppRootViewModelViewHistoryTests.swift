// AppRootViewModelViewHistoryTests.swift
// IHFeaturesTests
//
// ELI5: Checks the app tells the server about a song view only when signed in,
// ignores every failure, and reads the history back (or gets nothing when it
// is not available).
//
// DETAILED: PR 3b step I3 (#2180). `reportSongView(_:)` is called directly
// (the `Task` that `recordRecentlyViewed` starts for it would make a test
// timing-dependent); that `recordRecentlyViewed` really starts it is checked
// from the source by `tests/test-apple-view-history.js`. Same harness shape as
// `AppRootViewModelSetlistsTests`. Runs in CI on macOS.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

@Suite("AppRootViewModel view history (I3)", .serialized)
struct AppRootViewModelViewHistoryTests {

    private actor MemoryStore: TokenStoring {
        private var value: String?
        func save(_ token: String) async throws { value = token }
        func load() async throws -> String? { value }
        func delete() async throws { value = nil }
    }

    private static func response(status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: URL(string: "https://dev.ihymns.app/api")!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    private static func action(of request: URLRequest) -> String {
        (request.url?.query ?? "").split(separator: "&").first { $0.hasPrefix("action=") }.map { String($0.dropFirst("action=".count)) } ?? ""
    }

    @MainActor
    private func makeViewModel() throws -> AppRootViewModel {
        let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), retryBaseDelaySeconds: 0.001)
        return AppRootViewModel(
            sessionController: SessionController(tokenStore: MemoryStore(), apiClient: apiClient),
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient)
        )
    }

    /// Signs the view model in with a canned token and waits for its main-actor mirror to catch up.
    @MainActor
    private func signIn(_ viewModel: AppRootViewModel) async throws {
        try await viewModel.sessionController.signIn(token: "t")
        for _ in 0..<200 where !viewModel.sessionState.isSignedIn { await Task.yield() }
    }

    @Test("A view is reported while signed in, as an authenticated song_view POST")
    func reportsWhileSignedIn() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let counter = LockedCounter()
            MockURLProtocol.requestHandler = { request in
                if Self.action(of: request) == "song_view" { counter.increment() }
                return (Self.response(status: 200), Data(#"{"ok":true}"#.utf8))
            }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel)
            let songId = try #require(SongID(rawValue: "MP-1008"))

            await viewModel.reportSongView(songId)

            #expect(counter.current == 1)
        }
    }

    @Test("Nothing is reported while signed out")
    func silentWhileSignedOut() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let counter = LockedCounter()
            MockURLProtocol.requestHandler = { _ in counter.increment(); return (Self.response(status: 200), Data()) }
            let viewModel = try await makeViewModel()
            let songId = try #require(SongID(rawValue: "MP-1008"))

            await viewModel.reportSongView(songId)

            #expect(counter.current == 0)
        }
    }

    @Test("A 429 or an offline failure is ignored and never thrown")
    func failuresAreIgnored() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            try await signIn(viewModel)
            let songId = try #require(SongID(rawValue: "MP-1008"))

            MockURLProtocol.requestHandler = { _ in (Self.response(status: 429), Data()) }
            await viewModel.reportSongView(songId)
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 503), Data()) }
            await viewModel.reportSongView(songId)
            // Reaching here without a throw is the assertion: `reportSongView` has no `throws`.
            #expect(await viewModel.sessionState.isSignedIn)
        }
    }

    @Test("History and most viewed come back signed in, and are empty or nil when signed out or switched off")
    func readsAndFallbacks() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeViewModel()
            #expect(await viewModel.songHistory().isEmpty)
            #expect(await viewModel.myMostViewed() == nil)

            try await signIn(viewModel)
            MockURLProtocol.requestHandler = { request in
                switch Self.action(of: request) {
                case "song_history":
                    return (Self.response(status: 200), Data(#"{"history":[{"songId":"MP-1008","title":"Amazing Grace","number":1008,"songbook":"MP"}]}"#.utf8))
                default:
                    return (Self.response(status: 409), Data(#"{"ok":false,"error":{"code":"http_409","message":"x","reason":"profile_sync_disabled"}}"#.utf8))
                }
            }
            #expect(await viewModel.songHistory().map(\.title) == ["Amazing Grace"])
            #expect(await viewModel.myMostViewed() == nil) // switched off here: nothing, not an error
        }
    }
}

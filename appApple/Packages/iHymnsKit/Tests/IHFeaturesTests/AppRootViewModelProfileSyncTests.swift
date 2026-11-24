// AppRootViewModelProfileSyncTests.swift
// IHFeaturesTests
//
// ELI5: Checks the app only runs the shared-settings sync when it is allowed to
// (signed in, and the site has it switched on), and stops asking once the site
// says "not here".
//
// DETAILED: PR 3b step I4 (#2180). The sync rules themselves are tested on the
// engine (`ProfileSyncEngineTests`); this file proves only the view model's
// gate and its memory of a 409 `profile_sync_disabled`. Same harness shape as
// `AppRootViewModelViewHistoryTests`. Runs in CI on macOS.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

@Suite("AppRootViewModel profile sync gate (I4)", .serialized)
struct AppRootViewModelProfileSyncTests {

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
        // swiftlint:disable:next force_unwrapping
        let defaults = UserDefaults(suiteName: "ih-profile-vm-tests-\(UUID().uuidString)")!
        return AppRootViewModel(
            sessionController: SessionController(tokenStore: MemoryStore(), apiClient: apiClient),
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient),
            profileSync: ProfileSyncCoordinator(schema: try ProfileSchema.bundled(), defaults: defaults)
        )
    }

    /// Signs in with a canned token and loads the user (so a user id is known).
    @MainActor
    private func signInAndLoadUser(_ viewModel: AppRootViewModel) async throws {
        try await viewModel.sessionController.signIn(token: "t")
        await viewModel.refreshCurrentUser()
    }

    @Test("Signed out, or with the site switch off, the sync makes no request at all")
    func gateStaysClosed() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let counter = LockedCounter()
            MockURLProtocol.requestHandler = { request in
                if Self.action(of: request) == "profile_sync" { counter.increment() }
                return (Self.response(status: 200), Data(#"{"user":{"id":7,"username":"j","display_name":"J","role":"user"}}"#.utf8))
            }
            let viewModel = try await makeViewModel()

            await MainActor.run { viewModel.appStatus = AppStatus(profileSync: true) }
            await viewModel.syncProfileSettings()                       // signed out
            #expect(counter.current == 0)

            try await signInAndLoadUser(viewModel)
            await MainActor.run { viewModel.appStatus = AppStatus(profileSync: false) }
            await viewModel.syncProfileSettings()                       // the site switch is off
            #expect(counter.current == 0)
        }
    }

    @Test("Switched on and signed in, it reads from cursor 0; a 409 profile_sync_disabled is remembered and not asked again")
    func disabledAnswerIsRemembered() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let counter = LockedCounter()
            MockURLProtocol.requestHandler = { request in
                switch Self.action(of: request) {
                case "auth_me":
                    return (Self.response(status: 200), Data(#"{"user":{"id":7,"username":"j","display_name":"J","role":"user"}}"#.utf8))
                case "profile_sync":
                    counter.increment()
                    return (Self.response(status: 409), Data(#"{"ok":false,"error":{"code":"http_409","message":"x","reason":"profile_sync_disabled"}}"#.utf8))
                default:
                    return (Self.response(status: 200), Data("{}".utf8))
                }
            }
            let viewModel = try await makeViewModel()
            try await signInAndLoadUser(viewModel)
            await MainActor.run { viewModel.appStatus = AppStatus(profileSync: true) }

            await viewModel.syncProfileSettings()
            #expect(counter.current == 1)
            #expect(await viewModel.profileSync.switchedOff)

            await viewModel.syncProfileSettings()
            #expect(counter.current == 1)                               // not asked again
        }
    }
}

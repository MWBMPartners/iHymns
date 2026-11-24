// AppRootViewModelDeviceSignInTests.swift
// IHFeaturesTests
//
// ELI5: Checks the account screen's two new actions through the real root view
// model: "Sign out everywhere" signs this device out and forgets its cached
// account data (or, if the server cannot be reached, changes nothing and says
// so), and the status the screen draws its controls from.
//
// DETAILED: PR 3b step I2d (#2180). The deep rules (what is deleted, in which
// order, what the shared sign-in may and may not do) are tested at the
// controller in `IHAuthTests/SessionControllerDeviceSign*Tests.swift`; this
// file proves only the root view model's own part: the local teardown after a
// success, and no teardown after a failure. Same harness shape as
// `AppRootViewModelSetlistsTests`: real production types, faked transport and
// in-memory safes only. Runs in CI on macOS, not in the sandbox this was
// written in.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

@Suite("AppRootViewModel device sign-in (I2d)", .serialized)
struct AppRootViewModelDeviceSignInTests {

    private actor MemoryStore: TokenStoring {
        private var value: String?
        init(_ value: String? = nil) { self.value = value }
        func save(_ token: String) async throws { value = token }
        func load() async throws -> String? { value }
        func delete() async throws { value = nil }
    }

    private struct NoLegacyToken: LegacyTokenReading {
        func load() async throws -> String? { nil }
    }

    private struct FixedIdentity: DeviceIdentityProviding {
        func installId() async throws -> String { "install-1234-abcd" }
    }

    private static func response(status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: URL(string: "https://dev.ihymns.app/api")!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    private static func action(of request: URLRequest) -> String {
        (request.url?.query ?? "").split(separator: "&").first { $0.hasPrefix("action=") }.map { String($0.dropFirst("action=".count)) } ?? ""
    }

    /// A view model whose controller holds its OWN token and has device sign-in support.
    @MainActor
    private func makeSignedInViewModel(everywhereStatus: Int) async throws -> AppRootViewModel {
        let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), retryBaseDelaySeconds: 0.001)
        let support = DeviceSignInSupport(
            enrolKeyStore: MemoryStore(),
            legacyReader: NoLegacyToken(),
            deviceIdentity: FixedIdentity(),
            signInState: DeviceSignInState(store: MemoryStore())
        )
        let controller = SessionController(tokenStore: MemoryStore("own-device-token"), apiClient: apiClient, deviceSignIn: support)
        let viewModel = AppRootViewModel(
            sessionController: controller,
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient),
            setlistSyncStateStore: SetlistSyncStateStore(defaults: UserDefaults(suiteName: "ih-device-vm-\(UUID().uuidString)") ?? .standard)
        )
        MockURLProtocol.requestHandler = { request in
            switch Self.action(of: request) {
            case "auth_me":
                return (Self.response(status: 200), Data(#"{"user":{"id":7,"username":"jane","display_name":"Jane","role":"user"}}"#.utf8))
            case "auth_signout_everywhere":
                return (Self.response(status: everywhereStatus), Data(#"{"tokensRevoked":2,"enrolKeysRevoked":1,"keptCurrent":false}"#.utf8))
            default:
                return (Self.response(status: 200), Data("{}".utf8))
            }
        }
        try await controller.restoreFromStorage(deviceSignInEnabled: true)
        await viewModel.refreshCurrentUser()
        return viewModel
    }

    @Test("signOutEverywhere signs this device out and forgets the cached account data")
    func signOutEverywhereClearsLocalState() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeSignedInViewModel(everywhereStatus: 200)
            _ = await viewModel.createSetlist(name: "Sunday")
            #expect(await viewModel.currentUser?.id == 7)
            #expect(await viewModel.setlists.isEmpty == false)

            try await viewModel.signOutEverywhere()

            #expect(await viewModel.sessionController.state == .signedOut)
            #expect(await viewModel.currentUser == nil)
            #expect(await viewModel.setlists.isEmpty)
        }
    }

    @Test("A failed sign-out-everywhere throws and leaves this device signed in with its data")
    func signOutEverywhereFailureChangesNothing() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeSignedInViewModel(everywhereStatus: 503)
            _ = await viewModel.createSetlist(name: "Sunday")

            await #expect(throws: APIError.self) { try await viewModel.signOutEverywhere() }

            #expect(await viewModel.sessionController.state.isSignedIn)
            #expect(await viewModel.currentUser?.id == 7)
            #expect(await viewModel.setlists.isEmpty == false)
        }
    }

    @Test("deviceSignInStatus reports the switch the controller was started with")
    func statusReportsSwitch() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let viewModel = try await makeSignedInViewModel(everywhereStatus: 200)
            let status = await viewModel.deviceSignInStatus()
            #expect(status.switchedOn)
            #expect(status.legacyMode == false)
            #expect(status.keySupported)
            #expect(status.canOfferAutomaticSignIn)
        }
    }
}

// LiveSyncUIStateTests.swift
// IHFeaturesTests
//
// ELI5: Checks that whatever the live engines announce turns into the
// RIGHT "what should the Live screen show?" value, that signing out force-
// ends a hosting session, and that "Join with a Code" tries the service
// namespace before the leader-code namespace.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §7.4). Drives `AppRootViewModel.apply(_:)` (both overloads, `@testable
// import IHFeatures`) directly for the event->state table — no real engine
// loop needed for those rows. `joinWithCode`'s fallback order IS driven
// against `MockURLProtocol` (mirroring `AppRootViewModelSetlistsSyncTests`'
// existing pattern in this same test target) since that's genuine
// cross-engine network behaviour, not just a state transition. Only the
// individual methods that touch `AppRootViewModel` (a `@MainActor` class)
// are themselves marked `@MainActor` — the `Suite` itself is NOT, mirroring
// `AppRootViewModelSetlistsTests.swift`'s exact split (its own header notes
// why: the mock-transport closures passed to `MockURLProtocol.requestHandler`
// are plain `@Sendable`, non-isolated, and must stay able to call the
// `response`/`action` helpers without an actor hop).
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

@Suite("AppRootViewModel+LiveSync", .serialized)
struct LiveSyncUIStateTests {

    private actor InMemoryTokenStore: TokenStoring {
        private var token: String?
        func save(_ token: String) async throws { self.token = token }
        func load() async throws -> String? { token }
        func delete() async throws { token = nil }
    }

    @MainActor
    private func makeViewModel(apiClient: APIClient = APIClient(environment: .dev)) throws -> AppRootViewModel {
        let sessionController = SessionController(tokenStore: InMemoryTokenStore(), apiClient: apiClient)
        let offlineStore = try OfflineStore(path: nil)
        let liveFollowEngine = LiveFollowEngine(apiClient: apiClient)
        let serviceModeEngine = ServiceModeEngine(apiClient: apiClient)
        return AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: offlineStore,
            liveFollowEngine: liveFollowEngine,
            serviceModeEngine: serviceModeEngine
        )
    }

    private static let sampleSnapshot = LiveBroadcastSnapshot(songId: "MP-0031", componentIndex: 1, state: nil, revision: 1)

    // MARK: - Event -> state rows

    @Test("hostingStarted -> .hosting, currentSongTitle nil")
    @MainActor
    func hostingStartedMapsToHosting() throws {
        let viewModel = try makeViewModel()
        viewModel.apply(LiveFollowEvent.hostingStarted(code: "K7M4PQ", sessionId: 42))
        #expect(viewModel.liveState == .hosting(code: "K7M4PQ", currentSongTitle: nil))
    }

    @Test("followingStarted -> .followingLeader(host:), snapshot published")
    @MainActor
    func followingStartedMapsToFollowingLeader() throws {
        let viewModel = try makeViewModel()
        viewModel.apply(LiveFollowEvent.followingStarted(code: "K7M4PQ", hostDisplayName: "Pastor Sam", initial: Self.sampleSnapshot))
        #expect(viewModel.liveState == .followingLeader(hostDisplayName: "Pastor Sam", isFresh: true))
        #expect(viewModel.liveSnapshot == Self.sampleSnapshot)
    }

    @Test("service .joined -> .followingService")
    @MainActor
    func serviceJoinedMapsToFollowingService() throws {
        let viewModel = try makeViewModel()
        viewModel.apply(ServiceModeEvent.joined(initial: Self.sampleSnapshot))
        #expect(viewModel.liveState == .followingService(isFresh: true))
        #expect(viewModel.liveSnapshot == Self.sampleSnapshot)
    }

    @Test("freshnessChanged(false) while following a leader flips isFresh — the banner's Reconnecting… state")
    @MainActor
    func freshnessChangedFalseWhileFollowingLeader() throws {
        let viewModel = try makeViewModel()
        viewModel.apply(LiveFollowEvent.followingStarted(code: "K7M4PQ", hostDisplayName: "Pastor Sam", initial: Self.sampleSnapshot))
        viewModel.apply(LiveFollowEvent.freshnessChanged(false))
        #expect(viewModel.liveState == .followingLeader(hostDisplayName: "Pastor Sam", isFresh: false))
    }

    @Test("freshnessChanged(false) while following a service flips isFresh")
    @MainActor
    func freshnessChangedFalseWhileFollowingService() throws {
        let viewModel = try makeViewModel()
        viewModel.apply(ServiceModeEvent.joined(initial: Self.sampleSnapshot))
        viewModel.apply(ServiceModeEvent.freshnessChanged(false))
        #expect(viewModel.liveState == .followingService(isFresh: false))
    }

    @Test("hostingEnded -> .idle, snapshot cleared")
    @MainActor
    func hostingEndedMapsToIdle() throws {
        let viewModel = try makeViewModel()
        viewModel.apply(LiveFollowEvent.hostingStarted(code: "K7M4PQ", sessionId: 42))
        viewModel.apply(LiveFollowEvent.hostingEnded(.userLeft))
        #expect(viewModel.liveState == .idle)
        #expect(viewModel.liveSnapshot == nil)
    }

    @Test("followingEnded -> .idle")
    @MainActor
    func followingEndedMapsToIdle() throws {
        let viewModel = try makeViewModel()
        viewModel.apply(LiveFollowEvent.followingStarted(code: "K7M4PQ", hostDisplayName: "Pastor Sam", initial: Self.sampleSnapshot))
        viewModel.apply(LiveFollowEvent.followingEnded(.serverEnded))
        #expect(viewModel.liveState == .idle)
    }

    @Test("service .left -> .idle")
    @MainActor
    func serviceLeftMapsToIdle() throws {
        let viewModel = try makeViewModel()
        viewModel.apply(ServiceModeEvent.joined(initial: Self.sampleSnapshot))
        viewModel.apply(ServiceModeEvent.left(.serverEnded))
        #expect(viewModel.liveState == .idle)
    }

    @Test("presenceChanged carries no observable state change on its own")
    @MainActor
    func presenceChangedIsANoOpForState() throws {
        let viewModel = try makeViewModel()
        viewModel.apply(ServiceModeEvent.joined(initial: Self.sampleSnapshot))
        let stateBefore = viewModel.liveState
        viewModel.apply(ServiceModeEvent.presenceChanged)
        #expect(viewModel.liveState == stateBefore)
    }

    @Test("a .snapshot event while following does not change liveState, only liveSnapshot")
    @MainActor
    func snapshotEventOnlyUpdatesSnapshot() throws {
        let viewModel = try makeViewModel()
        viewModel.apply(LiveFollowEvent.followingStarted(code: "K7M4PQ", hostDisplayName: "Pastor Sam", initial: Self.sampleSnapshot))
        let updated = LiveBroadcastSnapshot(songId: "MP-0031", componentIndex: 2, state: nil, revision: 2)
        viewModel.apply(LiveFollowEvent.snapshot(updated))
        #expect(viewModel.liveSnapshot == updated)
        #expect(viewModel.liveState == .followingLeader(hostDisplayName: "Pastor Sam", isFresh: true))
    }

    // MARK: - Join-code normalisation

    @Test("normalizedJoinCode strips spaces/hyphens/punctuation and uppercases")
    func normalizesJoinCode() {
        #expect(AppRootViewModel.normalizedJoinCode("k7m-4 pq") == "K7M4PQ")
        #expect(AppRootViewModel.normalizedJoinCode("ab•cd") == "ABCD")
    }

    @Test("isValidJoinCode enforces the 4-12 char [A-Z0-9] shape")
    func validatesJoinCodeShape() {
        #expect(AppRootViewModel.isValidJoinCode("ABCD"))
        #expect(AppRootViewModel.isValidJoinCode("ABCDEFGHIJKL"))
        #expect(!AppRootViewModel.isValidJoinCode("ABC"))
        #expect(!AppRootViewModel.isValidJoinCode("ABCDEFGHIJKLM"))
    }

    // MARK: - joinWithCode fallback order (D-8)

    @Test("joinWithCode tries Service Mode FIRST, falls back to Live Follow on .codeNotActive")
    func joinWithCodeTriesServiceModeFirst() async throws {
        try await MockTransportLock.shared.withLock {
            let order = LockedOrderRecorder()
            MockURLProtocol.requestHandler = { request in
                let action = Self.action(from: request)
                order.record(action)
                switch action {
                case "service_join":
                    return (Self.response(for: request, status: 404), Data(#"{"ok":false,"error":"not active"}"#.utf8))
                case "live_follow_join":
                    return (Self.response(for: request, status: 200), Data("""
                    {"ok":true,"code":"K7M4PQ","followToken":"a1b2c3d4e5f60718293a4b5c6d7e8f90","hostDisplayName":"Pastor Sam","currentSongId":null,"componentIndex":null,"state":null,"revision":0}
                    """.utf8))
                default:
                    return (Self.response(for: request, status: 200), Data("{}".utf8))
                }
            }
            defer { MockURLProtocol.requestHandler = nil }

            let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let viewModel = try await makeViewModel(apiClient: apiClient)

            try await viewModel.joinWithCode("K7M4PQ")

            #expect(order.recorded == ["service_join", "live_follow_join"])
            await viewModel.leaveLive()
        }
    }

    // MARK: - Sign-out force-ends hosting

    @Test("Signing out force-ends an active hosting session")
    func signOutForceEndsHosting() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { request in
                let action = Self.action(from: request)
                switch action {
                case "live_follow_create":
                    return (Self.response(for: request, status: 200), Data(#"{"ok":true,"code":"K7M4PQ","revision":0}"#.utf8))
                case "auth_logout":
                    return (Self.response(for: request, status: 200), Data("{}".utf8))
                default:
                    return (Self.response(for: request, status: 200), Data(#"{"ok":true}"#.utf8))
                }
            }
            defer { MockURLProtocol.requestHandler = nil }

            let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let sessionController = SessionController(tokenStore: InMemoryTokenStore(), apiClient: apiClient)
            try await sessionController.signIn(token: "deadbeef")
            let liveFollowEngine = LiveFollowEngine(apiClient: apiClient)
            let viewModel = await AppRootViewModel(
                sessionController: sessionController,
                apiClient: apiClient,
                offlineStore: try OfflineStore(path: nil),
                liveFollowEngine: liveFollowEngine,
                serviceModeEngine: ServiceModeEngine(apiClient: apiClient)
            )

            _ = try await viewModel.goLive()
            try await sessionController.signOut()

            // `observeSessionState()`'s sign-out hook fires a detached
            // `Task` — poll briefly for it to land rather than asserting
            // the instant `signOut()` returns.
            let deadline = ContinuousClock.now.advanced(by: .seconds(2))
            while await liveFollowEngine.role != .idle, ContinuousClock.now < deadline {
                try? await Task.sleep(nanoseconds: 2_000_000)
            }
            #expect(await liveFollowEngine.role == .idle)
        }
    }

    // MARK: - Helpers (plain, non-isolated — called from the `@Sendable`
    // `MockURLProtocol.requestHandler` closures above, which must never hop
    // through MainActor)

    private static func response(for request: URLRequest, status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    private static func action(from request: URLRequest) -> String {
        guard let query = request.url?.query else { return "" }
        for pair in query.split(separator: "&") {
            let parts = pair.split(separator: "=", maxSplits: 1)
            if parts.first == "action" { return parts.count > 1 ? String(parts[1]) : "" }
        }
        return ""
    }
}

/// A tiny manually-synchronized ordered-action recorder — the `LockedCounter`
/// pattern (`IHAPITestSupport`) applied to an ordered log instead of a count.
private final class LockedOrderRecorder: @unchecked Sendable {
    private let lock = NSLock()
    private var actions: [String] = []

    func record(_ action: String) {
        lock.lock(); defer { lock.unlock() }
        actions.append(action)
    }

    var recorded: [String] {
        lock.lock(); defer { lock.unlock() }
        return actions
    }
}

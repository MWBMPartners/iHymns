// AppRootViewModelTests.swift
// IHFeaturesTests
//
// ELI5: Builds a real `AppRootViewModel` wired to real (in-memory/fake)
// engines, signs a fake user in, and checks the view model's main-thread
// `sessionState` mirror actually catches up — proving the actor-to-
// MainActor bridge in `observeSessionState()` really works, not just
// compiles.
import Testing
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

@Suite("AppRootViewModel")
@MainActor
struct AppRootViewModelTests {

    /// A test-only in-memory `TokenStoring`, mirroring `IHAuthTests`'
    /// fake so this suite doesn't touch the real Keychain either.
    private actor InMemoryTokenStore: TokenStoring {
        private var token: String?
        func save(_ token: String) async throws { self.token = token }
        func load() async throws -> String? { token }
        func delete() async throws { token = nil }
    }

    private func makeViewModel() throws -> AppRootViewModel {
        let sessionController = SessionController(tokenStore: InMemoryTokenStore())
        let apiClient = APIClient(environment: .dev)
        let offlineStore = try OfflineStore(path: nil)
        let liveFollowEngine = LiveFollowEngine(apiClient: apiClient)

        return AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: offlineStore,
            liveFollowEngine: liveFollowEngine
        )
    }

    @Test("Starts signed out")
    func startsSignedOut() throws {
        let viewModel = try makeViewModel()
        #expect(viewModel.sessionState == .signedOut)
    }

    @Test("cachedSongSummaries reads through to the offline store")
    func cachedSongSummariesReadsThroughOfflineStore() async throws {
        let viewModel = try makeViewModel()
        let summaries = try await viewModel.cachedSongSummaries()
        #expect(summaries.isEmpty)
    }

    @Test("sessionState mirrors a sign-in that happens on the SessionController actor")
    func sessionStateMirrorsSignIn() async throws {
        let sessionController = SessionController(tokenStore: InMemoryTokenStore())
        let apiClient = APIClient(environment: .dev)
        let viewModel = AppRootViewModel(
            sessionController: sessionController,
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient)
        )
        #expect(viewModel.sessionState == .signedOut)

        try await sessionController.signIn(token: "test-token")

        // The mirror task's `for await` resumption is a separately
        // scheduled job on the MainActor's executor queue, not something
        // that runs synchronously inside `signIn()` above. `Task.yield()`
        // cooperatively hands control back to the MainActor executor so
        // that already-enqueued job gets a chance to run before we assert;
        // looped (bounded, not a wall-clock sleep) since a single yield
        // isn't reliably enough hops for the resumed continuation to
        // actually land.
        for _ in 0..<100 where viewModel.sessionState != .signedIn(token: "test-token") {
            await Task.yield()
        }
        #expect(viewModel.sessionState == .signedIn(token: "test-token"))
    }
}

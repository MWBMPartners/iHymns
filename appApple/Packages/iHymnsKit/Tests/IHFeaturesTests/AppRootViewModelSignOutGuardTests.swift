// AppRootViewModelSignOutGuardTests.swift
// IHFeaturesTests
//
// ELI5: Checks the app asks before a sign-out would lose changes that never
// reached the account: it first tries to send them, refuses to sign out (leaving
// everything as it was) if some could not be sent, and goes ahead only when
// nothing is left or the person explicitly says "sign out anyway".
//
// DETAILED: PR 3b step I5 (#2180), the Apple twin of the web's guarded sign-out
// (rule #54). A set-list change queued while offline is the unsent change used
// here (the simplest queue to make). Same harness shape as
// `AppRootViewModelViewHistoryTests`. Which queues are counted, and that no
// screen can sign out around the check, are proven from the source by
// `tests/test-apple-sign-out-guard.js`. Runs in CI on macOS.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth
@testable import IHFeatures
@testable import IHLive
@testable import IHModels
@testable import IHPersistence

@Suite("AppRootViewModel guarded sign-out (I5)", .serialized)
struct AppRootViewModelSignOutGuardTests {

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

    /// A signed-in view model with one set list queued because the sync is failing.
    @MainActor
    private func makeViewModelWithUnsentSetlist(accountSyncOn: Bool, log: LockedCounter) async throws -> (AppRootViewModel, Setlist) {
        let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), retryBaseDelaySeconds: 0.001)
        // swiftlint:disable:next force_unwrapping
        let defaults = UserDefaults(suiteName: "ih-signout-tests-\(UUID().uuidString)")!
        let viewModel = AppRootViewModel(
            sessionController: SessionController(tokenStore: MemoryStore(), apiClient: apiClient),
            apiClient: apiClient,
            offlineStore: try OfflineStore(path: nil),
            liveFollowEngine: LiveFollowEngine(apiClient: apiClient),
            setlistSyncStateStore: SetlistSyncStateStore(defaults: defaults),
            profileSync: ProfileSyncCoordinator(schema: try ProfileSchema.bundled(), defaults: defaults)
        )
        MockURLProtocol.requestHandler = { request in
            switch Self.action(of: request) {
            case "auth_me":
                return (Self.response(status: 200), Data(#"{"user":{"id":7,"username":"j","display_name":"J","role":"user"}}"#.utf8))
            case "user_setlists_sync":
                return (Self.response(status: 503), Data())
            case "profile_sync":
                return (Self.response(status: 200), Data(#"{"cursor":0}"#.utf8))
            case "auth_logout":
                log.increment()
                return (Self.response(status: 200), Data())
            default:
                return (Self.response(status: 200), Data("{}".utf8))
            }
        }
        try await viewModel.sessionController.signIn(token: "t")
        await viewModel.refreshCurrentUser()
        let created = await viewModel.createSetlist(name: "Unsent")
        if accountSyncOn { viewModel.appStatus = AppStatus(profileSync: true) }
        return (viewModel, created)
    }

    @Test("With the account sync off, sign-out is exactly the older one: it does not ask and clears")
    func syncOffIsUnchanged() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let logouts = LockedCounter()
            let (viewModel, _) = try await makeViewModelWithUnsentSetlist(accountSyncOn: false, log: logouts)

            try await viewModel.signOut()

            #expect(logouts.current == 1)
            #expect(await viewModel.setlists.isEmpty)
        }
    }

    @Test("With the account sync on and a change that could not be sent, sign-out throws and changes nothing")
    func blockedWhenUnsent() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let logouts = LockedCounter()
            let (viewModel, _) = try await makeViewModelWithUnsentSetlist(accountSyncOn: true, log: logouts)

            await #expect(throws: SignOutBlockedByUnsentChanges(unsent: UnsentChanges(favourites: 0, setlists: 1, settings: 0))) {
                try await viewModel.signOut()
            }

            #expect(logouts.current == 0)                                    // the server was never told to sign out
            #expect(await viewModel.sessionController.state.isSignedIn)
            #expect(await viewModel.setlists.isEmpty == false)               // the data is still here
        }
    }

    @Test("Sign out anyway is an explicit choice: it signs out and clears")
    func discardGoesAhead() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let logouts = LockedCounter()
            let (viewModel, _) = try await makeViewModelWithUnsentSetlist(accountSyncOn: true, log: logouts)

            try await viewModel.signOut(discardUnsent: true)

            #expect(logouts.current == 1)
            #expect(await viewModel.setlists.isEmpty)
            #expect(await viewModel.sessionController.state == .signedOut)
        }
    }

    @Test("When the waiting changes can be sent, they are sent first and sign-out then goes ahead without asking")
    func flushedThenSignsOut() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let logouts = LockedCounter()
            let (viewModel, created) = try await makeViewModelWithUnsentSetlist(accountSyncOn: true, log: logouts)
            // Back online: the set-list sync now works.
            MockURLProtocol.requestHandler = { request in
                switch Self.action(of: request) {
                case "user_setlists_sync":
                    return (Self.response(status: 200), Data(#"{"setlists":[{"id":"\#(created.id)","name":"Unsent","songs":[]}],"syncedAt":"2026-11-24 10:00:00"}"#.utf8))
                case "auth_logout":
                    logouts.increment()
                    return (Self.response(status: 200), Data())
                case "profile_sync":
                    return (Self.response(status: 200), Data(#"{"cursor":0}"#.utf8))
                default:
                    return (Self.response(status: 200), Data(#"{"user":{"id":7,"username":"j","display_name":"J","role":"user"}}"#.utf8))
                }
            }

            try await viewModel.signOut()

            #expect(logouts.current == 1)
            #expect(await viewModel.sessionController.state == .signedOut)
        }
    }

    @Test("Sign out everywhere asks the same question first")
    func everywhereIsGuardedToo() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let logouts = LockedCounter()
            let (viewModel, _) = try await makeViewModelWithUnsentSetlist(accountSyncOn: true, log: logouts)

            await #expect(throws: SignOutBlockedByUnsentChanges.self) { try await viewModel.signOutEverywhere() }

            #expect(await viewModel.sessionController.state.isSignedIn)
            #expect(await viewModel.setlists.isEmpty == false)
        }
    }

    @Test("prepareSignOut says nothing is unsent while signed out, or with the account sync off")
    func prepareIsQuietWhenNotApplicable() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let logouts = LockedCounter()
            let (viewModel, _) = try await makeViewModelWithUnsentSetlist(accountSyncOn: false, log: logouts)
            #expect(await viewModel.prepareSignOut() == .none)
        }
    }

    @Test("An unreadable queue counts as unknown: it asks, where a count of zero would not")
    func unknownCountMeansAsk() {
        #expect(UnsentChanges.none.needsAsking == false)
        #expect(UnsentChanges(favourites: 0, setlists: 0, settings: 0, couldNotCount: true).needsAsking)
        #expect(UnsentChanges(favourites: 0, setlists: 1, settings: 0).needsAsking)
        // A count with the flag unset is unchanged from before (the older equality used in the other tests).
        #expect(UnsentChanges(favourites: 0, setlists: 1, settings: 0) == UnsentChanges(favourites: 0, setlists: 1, settings: 0, couldNotCount: false))
    }
}

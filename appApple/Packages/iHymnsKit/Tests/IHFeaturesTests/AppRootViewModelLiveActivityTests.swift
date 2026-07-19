// AppRootViewModelLiveActivityTests.swift
// IHFeaturesTests
//
// ELI5: Drives `AppRootViewModel` through a real hosting session (against a
// mocked transport) with a SPY controller standing in for the real
// ActivityKit one, and checks: starting hosting sends `.start`, opening a
// song sends `.update`, and ending hosting sends `.end`.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). Mirrors
// `LiveSyncUIStateTests.swift`'s exact mock-transport shape, cross-actor
// access always via explicit `await` rather than marking the whole test
// `@MainActor` — that file's own header explains why:
// `MockTransportLock.shared.withLock`'s body closure is typed `@Sendable`,
// so it is inferred NON-isolated regardless of the enclosing test
// function's own actor, meaning every `AppRootViewModel`/spy touch inside
// it needs an explicit `await` hop, never a bare synchronous access). The
// spy controller (`SpyNowSingingActivityController`, `AppRootViewModelLiveActivityTestSupport
// .swift`) never touches `ActivityKit` at all, which is exactly the point
// of the `NowSingingActivityControlling` protocol boundary — these tests
// exercise the FULL `AppRootViewModel` -> reducer -> controller pipeline
// with zero device/simulator dependency. `AppRootViewModelLiveActivityNavTests
// .swift` (hostAdvanceSection + pending push-token flush) is the sibling
// split of this same feature's test coverage — see THAT file's own header
// for why it's a separate file rather than a second `@Suite` here.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHFeatures
@testable import IHLiveActivity
@testable import IHModels

@Suite("AppRootViewModel+LiveActivity — start/update/end (#1429)", .serialized)
struct AppRootViewModelLiveActivityTests {

    @Test("goLive() -> hostingStarted -> the spy receives .start with role .host and the session's code/id")
    func goLiveSendsStartCommand() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { request in
                switch action(from: request) {
                case "live_follow_create":
                    return (response(for: request), Data(#"{"ok":true,"code":"K7M4PQ","revision":0,"sessionId":42}"#.utf8))
                default:
                    return (response(for: request), Data(#"{"ok":true}"#.utf8))
                }
            }
            defer { MockURLProtocol.requestHandler = nil }

            let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "deadbeef")
            let spy = SpyNowSingingActivityController()
            let viewModel = try await makeViewModel(apiClient: apiClient, nowSingingActivity: spy)

            _ = try await viewModel.goLive()
            await waitUntil { await !spy.received.isEmpty }

            let received = await spy.received
            #expect(received.count == 1)
            guard case .start(let attributes, let state) = received.first else {
                Issue.record("expected .start, got \(String(describing: received.first))")
                return
            }
            #expect(attributes.role == .host)
            #expect(attributes.sessionCode == "K7M4PQ")
            #expect(attributes.sessionId == 42)
            #expect(state.isLive == true)

            await viewModel.leaveLive()
        }
    }

    @Test("liveSongViewed(_:) while hosting -> the spy receives .update carrying the new song")
    func liveSongViewedSendsUpdateCommand() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { request in
                switch action(from: request) {
                case "live_follow_create":
                    return (response(for: request), Data(#"{"ok":true,"code":"K7M4PQ","revision":0,"sessionId":42}"#.utf8))
                case "live_follow_update":
                    return (response(for: request), Data(#"{"ok":true,"revision":1}"#.utf8))
                default:
                    return (response(for: request), Data(#"{"ok":true}"#.utf8))
                }
            }
            defer { MockURLProtocol.requestHandler = nil }

            let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "deadbeef")
            let spy = SpyNowSingingActivityController()
            let viewModel = try await makeViewModel(apiClient: apiClient, nowSingingActivity: spy)

            _ = try await viewModel.goLive()
            await waitUntil { await !spy.received.isEmpty }
            let songId = try #require(SongID(rawValue: "MP-0031"))
            await viewModel.liveSongViewed(songId)
            await waitUntil { await spy.received.count >= 2 }

            let received = await spy.received
            #expect(received.count == 2)
            guard case .update(let state) = received.last else {
                Issue.record("expected .update, got \(String(describing: received.last))")
                return
            }
            #expect(state.songId == "MP-0031")
            #expect(state.componentIndex == nil)

            await viewModel.leaveLive()
        }
    }

    @Test("leaveLive() while hosting -> hostingEnded -> the spy receives .end")
    func leaveLiveSendsEndCommand() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { request in
                switch action(from: request) {
                case "live_follow_create":
                    return (response(for: request), Data(#"{"ok":true,"code":"K7M4PQ","revision":0,"sessionId":42}"#.utf8))
                default:
                    return (response(for: request), Data(#"{"ok":true}"#.utf8))
                }
            }
            defer { MockURLProtocol.requestHandler = nil }

            let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "deadbeef")
            let spy = SpyNowSingingActivityController()
            let viewModel = try await makeViewModel(apiClient: apiClient, nowSingingActivity: spy)

            _ = try await viewModel.goLive()
            await waitUntil { await !spy.received.isEmpty }
            await viewModel.leaveLive()
            await waitUntil { await spy.received.count >= 2 }

            let received = await spy.received
            guard case .end(let final) = received.last else {
                Issue.record("expected .end, got \(String(describing: received.last))")
                return
            }
            #expect(final.isLive == false)
        }
    }
}

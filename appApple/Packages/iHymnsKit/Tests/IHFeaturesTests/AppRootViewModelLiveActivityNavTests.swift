// AppRootViewModelLiveActivityNavTests.swift
// IHFeaturesTests
//
// ELI5: Checks that tapping Next/Previous on the Live Activity card actually
// re-broadcasts the right section (and safely no-ops when there's nothing
// to advance), and that a push token which arrives before we know the
// hosting session's id waits patiently instead of getting lost.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). Sibling split of
// `AppRootViewModelLiveActivityTests.swift` (that file's own header covers
// the shared harness reasoning) — kept in a SEPARATE file purely to keep
// both under the repo's LOC-budget tripwire (`Scripts/loc-budget.sh`) once
// this feature's full test coverage is accounted for; the split follows
// this file's own natural seam (start/update/end vs. navigation/push-token)
// rather than an arbitrary line cut. Shared helpers
// (`SpyNowSingingActivityController`/`LiveActivityLockedBox`/`makeViewModel`/
// `response(for:)`/`action(from:)`/`requestBodyData(_:)`/`waitUntil`) live
// in `AppRootViewModelLiveActivityTestSupport.swift`.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHFeatures
@testable import IHModels

@Suite("AppRootViewModel+LiveActivity — navigation + push token (#1429)", .serialized)
struct AppRootViewModelLiveActivityNavTests {

    @Test("hostAdvanceSection broadcasts the navigated index and syncs the Live Activity")
    func hostAdvanceSectionBroadcastsAndSyncs() async throws {
        try await MockTransportLock.shared.withLock {
            let updateRequests = LiveActivityLockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                switch action(from: request) {
                case "live_follow_create":
                    return (response(for: request), Data(#"{"ok":true,"code":"K7M4PQ","revision":0,"sessionId":42}"#.utf8))
                case "live_follow_update":
                    updateRequests.set(request)
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

            await viewModel.hostAdvanceSection(1)
            await waitUntil { updateRequests.current != nil }
            await waitUntil { await spy.received.count >= 3 }

            let body = requestBodyData(try #require(updateRequests.current))
            struct Body: Decodable { let componentIndex: Int? }
            let decoded = try JSONDecoder().decode(Body.self, from: body)
            #expect(decoded.componentIndex == 0) // Next from nil starts at section 0.

            let received = await spy.received
            guard case .update(let state) = received.last else {
                Issue.record("expected .update, got \(String(describing: received.last))")
                return
            }
            #expect(state.componentIndex == 0)

            await viewModel.leaveLive()
        }
    }

    @Test("hostAdvanceSection is a no-op while idle (never hosting)")
    func hostAdvanceSectionNoOpsWhileIdle() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { request in (response(for: request), Data(#"{"ok":true}"#.utf8)) }
            defer { MockURLProtocol.requestHandler = nil }

            let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "deadbeef")
            let spy = SpyNowSingingActivityController()
            let viewModel = try await makeViewModel(apiClient: apiClient, nowSingingActivity: spy)

            await viewModel.hostAdvanceSection(1)

            let received = await spy.received
            #expect(received.isEmpty)
        }
    }

    @Test("hostAdvanceSection is a no-op while hosting but no song has been broadcast yet")
    func hostAdvanceSectionNoOpsWithNoSong() async throws {
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
            let commandCountAfterStart = await spy.received.count

            await viewModel.hostAdvanceSection(1)
            // No network call, no reducer diff — nothing further to WAIT
            // for; a brief settle covers the (non-)event either way.
            try? await Task.sleep(nanoseconds: 20_000_000)

            let received = await spy.received
            #expect(received.count == commandCountAfterStart)
            await viewModel.leaveLive()
        }
    }

    @Test("a push token that arrives BEFORE the session id is parked, then flushed once hostingStarted delivers it")
    func pendingPushTokenFlushesOnceSessionIdKnown() async throws {
        try await MockTransportLock.shared.withLock {
            let apnsRegisterRequests = LiveActivityLockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                switch action(from: request) {
                case "live_follow_create":
                    return (response(for: request), Data(#"{"ok":true,"code":"K7M4PQ","revision":0,"sessionId":42}"#.utf8))
                case "apns_register":
                    apnsRegisterRequests.set(request)
                    return (response(for: request), Data(#"{"ok":true}"#.utf8))
                default:
                    return (response(for: request), Data(#"{"ok":true}"#.utf8))
                }
            }
            defer { MockURLProtocol.requestHandler = nil }

            let apiClient = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "deadbeef")
            let spy = SpyNowSingingActivityController()
            let viewModel = try await makeViewModel(apiClient: apiClient, nowSingingActivity: spy)

            // The token arrives BEFORE any hosting session exists at all —
            // `nowSingingContext` is still `nil`, so this must park rather
            // than register.
            let pushTokenHandler = await spy.onPushTokenHex
            await pushTokenHandler?("aabbccddeeff")
            #expect(apnsRegisterRequests.current == nil)

            // `hostingStarted` delivers the session id — the parked token
            // should flush automatically.
            _ = try await viewModel.goLive()

            let deadline = ContinuousClock.now.advanced(by: .seconds(2))
            while apnsRegisterRequests.current == nil, ContinuousClock.now < deadline {
                try? await Task.sleep(nanoseconds: 2_000_000)
            }

            let body = requestBodyData(try #require(apnsRegisterRequests.current))
            let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
            #expect(json["pushToken"] as? String == "aabbccddeeff")
            #expect(json["sessionId"] as? Int == 42)
            #expect(json["kind"] as? String == "liveActivity")

            await viewModel.leaveLive()
        }
    }
}

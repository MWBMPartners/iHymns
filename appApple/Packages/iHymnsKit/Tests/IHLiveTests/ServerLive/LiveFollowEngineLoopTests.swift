// LiveFollowEngineLoopTests.swift
// IHLiveTests/ServerLive
//
// ELI5: Drives both halves of `LiveFollowEngine` — hosting (go live,
// broadcast, heartbeat, end) and following (join, poll, leave) — against a
// fake network, and checks the mutual-exclusion rule and the wake/suspend
// behaviour work right.
//
// DETAILED: Apple Phase-2 PR-10 (#1426; `.claude/apple-phase2-pr10-spec.md`
// §7.3). Shares `MockURLProtocol`/`MockTransportLock` + this directory's
// `ActionResponseQueue`/`SleepRecorder`/`EventCollector`/`waitUntil`
// (`ServerLiveTestSupport.swift`, also used by `ServiceModeEngineTests`).
// Every test that starts a background loop (`goLive`/`join`) explicitly
// stops it (`endHosting()`/`leaveFollow()`) before returning — a stray loop
// left running would keep firing against the process-wide
// `MockURLProtocol.requestHandler` static and could contaminate whichever
// test runs next (the exact bug class this file's sibling suite's own
// history records).
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHLive
@testable import IHModels

@Suite("LiveFollowEngine — host + follower loops", .serialized)
struct LiveFollowEngineLoopTests {

    private static func createBody(code: String = "K7M4PQ") -> Data {
        Data(#"{"ok":true,"code":"\#(code)","revision":0}"#.utf8)
    }

    private static func updateOkBody(revision: Int) -> Data {
        Data(#"{"ok":true,"revision":\#(revision)}"#.utf8)
    }

    private static let updateSupersededBody = Data(#"{"ok":false,"error":"Session not found, not yours, or ended."}"#.utf8)
    private static let heartbeatAliveBody = Data(#"{"ok":true}"#.utf8)
    private static let heartbeatDeadBody = Data(#"{"ok":false}"#.utf8)
    private static let leaveOkBody = Data(#"{"ok":true}"#.utf8)

    private static func joinBody(code: String = "K7M4PQ", revision: Int, songId: String? = nil, index: Int? = nil) -> Data {
        Data("""
        {"ok":true,"code":"\(code)","followToken":"a1b2c3d4e5f60718293a4b5c6d7e8f90","hostDisplayName":"Pastor Sam","currentSongId":\(songId.map { "\"\($0)\"" } ?? "null"),"componentIndex":\(index.map(String.init) ?? "null"),"state":null,"revision":\(revision)}
        """.utf8)
    }

    private static func changedPollBody(revision: Int, songId: String, componentIndex: Int) -> Data {
        Data(#"{"changed":true,"currentSongId":"\#(songId)","componentIndex":\#(componentIndex),"state":null,"revision":\#(revision)}"#.utf8)
    }

    private static func unchangedPollBody(revision: Int) -> Data {
        Data(#"{"changed":false,"revision":\#(revision)}"#.utf8)
    }

    private static let endedPollBody = Data(#"{"active":false}"#.utf8)

    private func makeEngine(queue: ActionResponseQueue, sleeps: SleepRecorder) -> LiveFollowEngine {
        MockURLProtocol.requestHandler = { queue.handle($0) }
        let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "deadbeef")
        let config = LiveSyncConfiguration(sleep: { duration in
            sleeps.record(duration)
            try await Task.sleep(nanoseconds: 200_000)
        })
        let persistence = LiveSyncPersistence(defaults: UserDefaults(suiteName: UUID().uuidString)!)
        return LiveFollowEngine(apiClient: client, configuration: config, persistence: persistence)
    }

    // MARK: - Host

    @Test("goLive: .hostingStarted emitted, heartbeat loop sleeps at the 30s interval")
    func goLiveStartsHeartbeatLoop() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "live_follow_create", body: Self.createBody())
            queue.enqueue(action: "live_follow_heartbeat", body: Self.heartbeatAliveBody)
            queue.enqueue(action: "live_follow_leave", body: Self.leaveOkBody)
            defer { MockURLProtocol.requestHandler = nil }

            let sleeps = SleepRecorder()
            let engine = makeEngine(queue: queue, sleeps: sleeps)
            let collector = EventCollector<LiveFollowEvent>()
            await collector.start(engine.events)

            let code = try await engine.goLive(songId: nil, componentIndex: nil)
            #expect(code == "K7M4PQ")

            await waitUntil { await collector.received.contains(.hostingStarted(code: "K7M4PQ")) }
            let received = await collector.received
            #expect(received.contains(.hostingStarted(code: "K7M4PQ")))

            await waitUntil { !sleeps.recorded.isEmpty }
            #expect(sleeps.recorded.first == .seconds(30))

            await engine.endHosting()
        }
    }

    @Test("A {ok:false} heartbeat ends hosting with .hostingEnded(.serverEnded)")
    func deadHeartbeatEndsHosting() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "live_follow_create", body: Self.createBody())
            queue.enqueue(action: "live_follow_heartbeat", body: Self.heartbeatDeadBody)
            defer { MockURLProtocol.requestHandler = nil }

            let engine = makeEngine(queue: queue, sleeps: SleepRecorder())
            let collector = EventCollector<LiveFollowEvent>()
            await collector.start(engine.events)

            _ = try await engine.goLive(songId: nil, componentIndex: nil)
            await waitUntil(timeoutMs: 3_000) { await collector.received.contains(.hostingEnded(.serverEnded)) }

            let received = await collector.received
            #expect(received.contains(.hostingEnded(.serverEnded)))
        }
    }

    @Test("broadcast dedups an identical songId+componentIndex — no second POST; a changed index DOES post")
    func broadcastDedupsRepeats() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "live_follow_create", body: Self.createBody())
            queue.enqueue(action: "live_follow_update", body: Self.updateOkBody(revision: 1))
            queue.enqueue(action: "live_follow_update", body: Self.updateOkBody(revision: 2))
            queue.enqueue(action: "live_follow_heartbeat", body: Self.heartbeatAliveBody)
            queue.enqueue(action: "live_follow_leave", body: Self.leaveOkBody)
            defer { MockURLProtocol.requestHandler = nil }

            let engine = makeEngine(queue: queue, sleeps: SleepRecorder())
            _ = try await engine.goLive(songId: nil, componentIndex: nil)

            await engine.broadcast(songId: "MP-0031", componentIndex: 1)
            await engine.broadcast(songId: "MP-0031", componentIndex: 1)
            #expect(queue.count(of: "live_follow_update") == 1)

            await engine.broadcast(songId: "MP-0031", componentIndex: 2)
            #expect(queue.count(of: "live_follow_update") == 2)

            await engine.endHosting()
        }
    }

    @Test("A 409 on broadcast ends hosting with .hostingEnded(.superseded)")
    func broadcast409EndsHostingAsSuperseded() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "live_follow_create", body: Self.createBody())
            queue.enqueue(action: "live_follow_update", status: 409, body: Self.updateSupersededBody)
            queue.enqueue(action: "live_follow_heartbeat", body: Self.heartbeatAliveBody)
            defer { MockURLProtocol.requestHandler = nil }

            let engine = makeEngine(queue: queue, sleeps: SleepRecorder())
            let collector = EventCollector<LiveFollowEvent>()
            await collector.start(engine.events)

            _ = try await engine.goLive(songId: nil, componentIndex: nil)
            await engine.broadcast(songId: "MP-0031", componentIndex: 1)

            // `broadcast(_:_:)` itself awaits `endHostingLocally(reason:)`
            // to completion (including the `eventContinuation.yield` call)
            // before returning, but the `EventCollector`'s OWN `for await`
            // consumer Task still needs its own scheduling turn to drain
            // that yielded value into `received` — wait for it rather than
            // reading the array the instant `broadcast` returns.
            await waitUntil { await collector.received.contains(.hostingEnded(.superseded)) }
            let received = await collector.received
            #expect(received.contains(.hostingEnded(.superseded)))
        }
    }

    @Test("endHosting() POSTs live_follow_leave and clears persistence")
    func endHostingLeavesAndClearsPersistence() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "live_follow_create", body: Self.createBody())
            queue.enqueue(action: "live_follow_leave", body: Self.leaveOkBody)
            defer { MockURLProtocol.requestHandler = nil }

            let engine = makeEngine(queue: queue, sleeps: SleepRecorder())
            _ = try await engine.goLive(songId: nil, componentIndex: nil)
            await engine.endHosting()

            #expect(queue.count(of: "live_follow_leave") == 1)
            #expect(await engine.role == .idle)
        }
    }

    @Test("setScenePhaseActive(true) fires an immediate wake heartbeat before the next 30s sleep")
    func wakeFiresImmediateHeartbeat() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "live_follow_create", body: Self.createBody())
            queue.enqueue(action: "live_follow_heartbeat", body: Self.heartbeatAliveBody)
            queue.enqueue(action: "live_follow_leave", body: Self.leaveOkBody)
            defer { MockURLProtocol.requestHandler = nil }

            let engine = makeEngine(queue: queue, sleeps: SleepRecorder())
            _ = try await engine.goLive(songId: nil, componentIndex: nil)
            await engine.setScenePhaseActive(false)
            let countBeforeWake = queue.count(of: "live_follow_heartbeat")

            await engine.setScenePhaseActive(true)
            await waitUntil { queue.count(of: "live_follow_heartbeat") > countBeforeWake }
            #expect(queue.count(of: "live_follow_heartbeat") > countBeforeWake)

            await engine.endHosting()
        }
    }

    @Test("setScenePhaseActive(false) cancels the loop — no further heartbeats fire")
    func suspensionCancelsTheLoop() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "live_follow_create", body: Self.createBody())
            queue.enqueue(action: "live_follow_heartbeat", body: Self.heartbeatAliveBody)
            defer { MockURLProtocol.requestHandler = nil }

            let engine = makeEngine(queue: queue, sleeps: SleepRecorder())
            _ = try await engine.goLive(songId: nil, componentIndex: nil)
            await engine.setScenePhaseActive(false)
            let countAfterSuspend = queue.count(of: "live_follow_heartbeat")

            try await Task.sleep(nanoseconds: 20_000_000)
            #expect(queue.count(of: "live_follow_heartbeat") == countAfterSuspend)
        }
    }

    // MARK: - Mutual exclusion (web parity, "host wins")

    @Test("goLive while following throws .alreadyFollowing")
    func goLiveWhileFollowingThrows() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "live_follow_join", body: Self.joinBody(revision: 0))
            queue.enqueue(action: "live_follow_poll", body: Self.unchangedPollBody(revision: 0))
            defer { MockURLProtocol.requestHandler = nil }

            let engine = makeEngine(queue: queue, sleeps: SleepRecorder())
            _ = try await engine.join(code: "K7M4PQ")

            await #expect(throws: LiveSyncError.alreadyFollowing) {
                _ = try await engine.goLive(songId: nil, componentIndex: nil)
            }

            await engine.leaveFollow()
        }
    }

    @Test("join while hosting throws .alreadyHosting")
    func joinWhileHostingThrows() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "live_follow_create", body: Self.createBody())
            queue.enqueue(action: "live_follow_heartbeat", body: Self.heartbeatAliveBody)
            queue.enqueue(action: "live_follow_leave", body: Self.leaveOkBody)
            defer { MockURLProtocol.requestHandler = nil }

            let engine = makeEngine(queue: queue, sleeps: SleepRecorder())
            _ = try await engine.goLive(songId: nil, componentIndex: nil)

            await #expect(throws: LiveSyncError.alreadyHosting) {
                _ = try await engine.join(code: "ZZZZ99")
            }

            await engine.endHosting()
        }
    }

    // MARK: - Follower

    @Test("join seeds the reducer from the response's OWN revision — a pre-join broadcast is not re-applied")
    func joinSeedsReducerFromResponseRevision() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "live_follow_join", body: Self.joinBody(revision: 5, songId: "MP-0031", index: 1))
            queue.enqueue(action: "live_follow_poll", body: Self.unchangedPollBody(revision: 5))
            defer { MockURLProtocol.requestHandler = nil }

            let engine = makeEngine(queue: queue, sleeps: SleepRecorder())
            let info = try await engine.join(code: "K7M4PQ")
            #expect(info.initial.revision == 5)

            await waitUntil { queue.count(of: "live_follow_poll") >= 1 }
            let since = queue.sinceValues(forAction: "live_follow_poll")
            #expect(since.first == "5")

            await engine.leaveFollow()
        }
    }

    @Test("poll loop: changed -> active:false yields ONE .snapshot then .followingEnded(.serverEnded)")
    func pollLoopAppliesChangeThenEnds() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "live_follow_join", body: Self.joinBody(revision: 1))
            queue.enqueue(action: "live_follow_poll", body: Self.changedPollBody(revision: 2, songId: "MP-0031", componentIndex: 3))
            queue.enqueue(action: "live_follow_poll", body: Self.endedPollBody)
            defer { MockURLProtocol.requestHandler = nil }

            let engine = makeEngine(queue: queue, sleeps: SleepRecorder())
            let collector = EventCollector<LiveFollowEvent>()
            await collector.start(engine.events)

            _ = try await engine.join(code: "K7M4PQ")
            await waitUntil(timeoutMs: 3_000) { await collector.received.contains(.followingEnded(.serverEnded)) }

            let received = await collector.received
            let snapshots = received.compactMap { event -> LiveBroadcastSnapshot? in
                if case .snapshot(let snapshot) = event { return snapshot }
                return nil
            }
            #expect(snapshots.count == 1)
            #expect(snapshots.first?.componentIndex == 3)
            #expect(received.contains(.followingEnded(.serverEnded)))
        }
    }

    @Test("leaveFollow() clears local state — no server call exists for an anonymous LF follower")
    func leaveFollowClearsLocalStateOnly() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "live_follow_join", body: Self.joinBody(revision: 1))
            queue.enqueue(action: "live_follow_poll", body: Self.unchangedPollBody(revision: 1))
            defer { MockURLProtocol.requestHandler = nil }

            let engine = makeEngine(queue: queue, sleeps: SleepRecorder())
            _ = try await engine.join(code: "K7M4PQ")
            await engine.leaveFollow()

            #expect(await engine.role == .idle)
        }
    }
}

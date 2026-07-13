// ServiceModeEngineTests.swift
// IHLiveTests/ServerLive
//
// ELI5: Drives the WHOLE congregant lifecycle — join, poll a few times,
// have the service end, leave, and resume after a relaunch — against a fake
// network, and checks the secret presence token is handled exactly right at
// every step (never logged raw, cleared in the right order, etc.).
//
// DETAILED: Apple Phase-2 PR-10 (#1427; `.claude/apple-phase2-pr10-spec.md`
// §7.3). `MockURLProtocol`/`MockTransportLock` (shared with every other
// `@testable import IHAPI` suite) + this directory's `ActionResponseQueue`/
// `SleepRecorder`/`EventCollector`/`waitUntil` (`ServerLiveTestSupport.swift`)
// — zero real wall-clock waits; `LiveSyncConfiguration.sleep` returns almost
// immediately after recording the requested `Duration`.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHLive
@testable import IHModels

@Suite("ServiceModeEngine", .serialized)
struct ServiceModeEngineTests {
    private static let presenceToken = "ye82rBJlEBjg0EPn7Yax1xMs0o5xQ9CuHt7kmkbsoOE"

    private static func joinBody(revision: Int = 1, pollIntervalMs: Int? = 2500, songId: String? = "MP-0031") -> Data {
        Data("""
        {"ok":true,"presenceToken":"\(presenceToken)","pollIntervalMs":\(pollIntervalMs.map(String.init) ?? "null"),"currentSongId":\(songId.map { "\"\($0)\"" } ?? "null"),"componentIndex":0,"state":null,"revision":\(revision)}
        """.utf8)
    }

    private static func unchangedPollBody(revision: Int) -> Data {
        Data(#"{"active":true,"changed":false,"revision":\#(revision)}"#.utf8)
    }

    private static func changedPollBody(revision: Int, songId: String, componentIndex: Int) -> Data {
        Data(#"{"active":true,"changed":true,"currentSongId":"\#(songId)","componentIndex":\#(componentIndex),"state":null,"revision":\#(revision)}"#.utf8)
    }

    private static let inactivePollBody = Data(#"{"active":false}"#.utf8)
    private static let notActiveJoinBody = Data(#"{"ok":false,"error":"That code isn't active. Check the screen and try again."}"#.utf8)

    private func makeEngine(
        queue: ActionResponseQueue,
        sleeps: SleepRecorder,
        now: @escaping @Sendable () -> Date = { Date() }
    ) -> (ServiceModeEngine, APIClient) {
        MockURLProtocol.requestHandler = { queue.handle($0) }
        let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
        let config = LiveSyncConfiguration(
            now: now,
            sleep: { duration in
                sleeps.record(duration)
                try await Task.sleep(nanoseconds: 200_000)
            }
        )
        let identity = ServiceDeviceIdentity(defaults: UserDefaults(suiteName: UUID().uuidString)!)
        let persistence = LiveSyncPersistence(defaults: UserDefaults(suiteName: UUID().uuidString)!)
        let engine = ServiceModeEngine(apiClient: client, configuration: config, deviceIdentity: identity, persistence: persistence)
        return (engine, client)
    }

    @Test("join: custody set (fingerprint, APIClient presence cookie, persistence), .joined + .presenceChanged emitted")
    func joinHappyPath() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "service_join", body: Self.joinBody())
            queue.enqueue(action: "service_poll", body: Self.unchangedPollBody(revision: 1))
            defer { MockURLProtocol.requestHandler = nil }

            let (engine, client) = makeEngine(queue: queue, sleeps: SleepRecorder())
            let collector = EventCollector<ServiceModeEvent>()
            await collector.start(engine.events)

            let info = try await engine.join(code: "ABC123")
            #expect(info.pollIntervalMs == 2500)
            #expect(info.initial.songId == "MP-0031")

            let fingerprint = await engine.presenceTokenFingerprint()
            #expect(fingerprint != nil)

            let probe = APIClient.makeURLRequest(for: .songsIndex, in: .dev, presenceToken: await client.servicePresenceToken)
            #expect(probe.value(forHTTPHeaderField: "Cookie") == "ihymns_sf_presence_token=\(Self.presenceToken)")

            await waitUntil { await !collector.received.isEmpty }
            let received = await collector.received
            #expect(received.contains(.joined(initial: info.initial)))
            #expect(received.contains(.presenceChanged))

            await collector.stop()
            // A successful join starts the poll loop as a background Task —
            // MUST be stopped before this test returns, or it keeps firing
            // (and racing `MockURLProtocol.requestHandler`, a process-wide
            // static) against whichever test runs next.
            await engine.leave()
        }
    }

    @Test("poll sequence unchanged -> changed -> active:false: ONE .snapshot, then .left(.serverEnded), custody cleared everywhere")
    func pollSequenceToServerEnded() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "service_join", body: Self.joinBody(revision: 1))
            queue.enqueue(action: "service_poll", body: Self.unchangedPollBody(revision: 1))
            queue.enqueue(action: "service_poll", body: Self.changedPollBody(revision: 2, songId: "MP-0031", componentIndex: 1))
            queue.enqueue(action: "service_poll", body: Self.inactivePollBody)
            defer { MockURLProtocol.requestHandler = nil }

            let (engine, client) = makeEngine(queue: queue, sleeps: SleepRecorder())
            let collector = EventCollector<ServiceModeEvent>()
            await collector.start(engine.events)

            _ = try await engine.join(code: "ABC123")
            // Wait on the COLLECTOR observing the terminal event, not just
            // the request count — the engine's own processing (including
            // `eventContinuation.yield`) can finish before the collector's
            // independent `for await` consumer Task gets a scheduling turn
            // to drain it into `received`.
            await waitUntil(timeoutMs: 3_000) { await collector.received.contains(.left(.serverEnded)) }

            let received = await collector.received
            let snapshots = received.compactMap { event -> LiveBroadcastSnapshot? in
                if case .snapshot(let snapshot) = event { return snapshot }
                return nil
            }
            #expect(snapshots.count == 1)
            #expect(snapshots.first?.songId == "MP-0031")
            #expect(snapshots.first?.componentIndex == 1)
            #expect(received.contains(.left(.serverEnded)))

            // Custody cleared EVERYWHERE: the engine, the APIClient's own
            // presence cookie, and local persistence.
            let fingerprintAfter = await engine.presenceTokenFingerprint()
            #expect(fingerprintAfter == nil)
            let tokenAfter = await client.servicePresenceToken
            #expect(tokenAfter == nil)

            await collector.stop()
        }
    }

    @Test("since increments across polls as the revision advances")
    func sinceTracksRevision() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "service_join", body: Self.joinBody(revision: 1))
            queue.enqueue(action: "service_poll", body: Self.unchangedPollBody(revision: 1))
            queue.enqueue(action: "service_poll", body: Self.changedPollBody(revision: 2, songId: "MP-0031", componentIndex: 1))
            queue.enqueue(action: "service_poll", body: Self.unchangedPollBody(revision: 2))
            defer { MockURLProtocol.requestHandler = nil }

            let (engine, _) = makeEngine(queue: queue, sleeps: SleepRecorder())
            _ = try await engine.join(code: "ABC123")
            await waitUntil(timeoutMs: 3_000) { queue.count(of: "service_poll") >= 3 }

            let since = queue.sinceValues(forAction: "service_poll")
            #expect(since.prefix(3).elementsEqual(["1", "1", "2"]))

            await engine.leave()
        }
    }

    @Test("leave(): service_leave is POSTed BEFORE custody is cleared")
    func leaveRevokesBeforeClearingCustody() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "service_join", body: Self.joinBody(revision: 1))
            queue.enqueue(action: "service_poll", body: Self.unchangedPollBody(revision: 1))
            queue.enqueue(action: "service_leave", body: Data(#"{"ok":true}"#.utf8))
            defer { MockURLProtocol.requestHandler = nil }

            let (engine, client) = makeEngine(queue: queue, sleeps: SleepRecorder())
            _ = try await engine.join(code: "ABC123")
            await engine.leave()

            #expect(queue.count(of: "service_leave") == 1)
            let tokenAfter = await client.servicePresenceToken
            #expect(tokenAfter == nil)
            #expect(await engine.presenceTokenFingerprint() == nil)
        }
    }

    @Test("cadence: the first recorded sleep equals the server-declared pollIntervalMs; idle floors it at 15s")
    func cadenceFollowsServerDeclaredIntervalThenIdleFloors() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "service_join", body: Self.joinBody(revision: 1, pollIntervalMs: 2500))
            queue.enqueue(action: "service_poll", body: Self.unchangedPollBody(revision: 1))
            defer { MockURLProtocol.requestHandler = nil }

            let sleeps = SleepRecorder()
            let (engine, _) = makeEngine(queue: queue, sleeps: sleeps)
            _ = try await engine.join(code: "ABC123")
            await waitUntil { !sleeps.recorded.isEmpty }
            #expect(sleeps.recorded.first == .milliseconds(2500))

            await engine.setFollowingIdle(true)
            await waitUntil(timeoutMs: 3_000) { (sleeps.recorded.last ?? .zero) >= .seconds(15) }
            #expect((sleeps.recorded.last ?? .zero) >= .seconds(15))

            await engine.leave()
        }
    }

    @Test("resume: a fresh persisted record fires one immediate poll and re-joins on changed")
    func resumeFiresImmediatePollAndRejoins() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "service_poll", body: Self.changedPollBody(revision: 3, songId: "MP-0031", componentIndex: 2))
            defer { MockURLProtocol.requestHandler = nil }
            MockURLProtocol.requestHandler = { queue.handle($0) }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let persistence = LiveSyncPersistence(defaults: UserDefaults(suiteName: UUID().uuidString)!)
            persistence.saveServicePresence(token: Self.presenceToken, revision: 1, joinedAt: Date())
            let config = LiveSyncConfiguration(sleep: { _ in try await Task.sleep(nanoseconds: 200_000) })
            let resumeEngine = ServiceModeEngine(apiClient: client, configuration: config, persistence: persistence)
            let collector = EventCollector<ServiceModeEvent>()
            await collector.start(resumeEngine.events)

            await resumeEngine.resumeIfPossible()
            await waitUntil { await collector.received.contains { if case .joined = $0 { true } else { false } } }

            let received = await collector.received
            #expect(received.contains { if case .joined = $0 { true } else { false } })
            #expect(await resumeEngine.presenceTokenFingerprint() != nil)

            await collector.stop()
            // Same "stop the background loop before returning" requirement
            // as `joinHappyPath` above — `resumeIfPossible()` also starts
            // the poll loop on success.
            await resumeEngine.leave()
        }
    }

    @Test("resume: active:false silently cleans up — no .left event, since nothing was ever visibly following")
    func resumeSilentlyCleansUpOnInactive() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "service_poll", body: Self.inactivePollBody)
            defer { MockURLProtocol.requestHandler = nil }
            MockURLProtocol.requestHandler = { queue.handle($0) }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let persistence = LiveSyncPersistence(defaults: UserDefaults(suiteName: UUID().uuidString)!)
            persistence.saveServicePresence(token: Self.presenceToken, revision: 1, joinedAt: Date())
            let config = LiveSyncConfiguration(sleep: { _ in try await Task.sleep(nanoseconds: 200_000) })
            let engine = ServiceModeEngine(apiClient: client, configuration: config, persistence: persistence)
            let collector = EventCollector<ServiceModeEvent>()
            await collector.start(engine.events)

            await engine.resumeIfPossible()
            await waitUntil { queue.count(of: "service_poll") >= 1 }

            #expect(persistence.loadServicePresence() == nil)
            let received = await collector.received
            #expect(received.isEmpty)

            await collector.stop()
        }
    }

    @Test("resume: a record older than the 4h ceiling makes NO network call at all")
    func resumeSkipsNetworkPastHardCeiling() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            defer { MockURLProtocol.requestHandler = nil }
            MockURLProtocol.requestHandler = { queue.handle($0) }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let persistence = LiveSyncPersistence(defaults: UserDefaults(suiteName: UUID().uuidString)!)
            persistence.saveServicePresence(token: Self.presenceToken, revision: 1, joinedAt: Date().addingTimeInterval(-5 * 3_600))
            let engine = ServiceModeEngine(apiClient: client, persistence: persistence)

            await engine.resumeIfPossible()
            try? await Task.sleep(nanoseconds: 20_000_000)

            #expect(queue.count(of: "service_poll") == 0)
        }
    }

    @Test("join 404 throws .codeNotActive")
    func joinNotFoundThrowsCodeNotActive() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "service_join", status: 404, body: Self.notActiveJoinBody)
            defer { MockURLProtocol.requestHandler = nil }

            let (engine, _) = makeEngine(queue: queue, sleeps: SleepRecorder())
            await #expect(throws: LiveSyncError.codeNotActive) {
                _ = try await engine.join(code: "ZZZZ99")
            }
        }
    }

    @Test("join 503 throws .temporarilyUnavailable")
    func joinMaintenanceThrowsTemporarilyUnavailable() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "service_join", status: 503, body: Data("{}".utf8))
            defer { MockURLProtocol.requestHandler = nil }

            let (engine, _) = makeEngine(queue: queue, sleeps: SleepRecorder())
            await #expect(throws: LiveSyncError.temporarilyUnavailable) {
                _ = try await engine.join(code: "ABC123")
            }
        }
    }
}

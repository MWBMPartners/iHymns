// ServiceModeEngineProjectorTests.swift
// IHLiveTests/ServerLive
//
// ELI5: Checks the tvOS projector's two ServiceModeEngine additions — join
// as `role: .projector` and get the faster 1000ms cadence back, and have
// that cadence survive a relaunch (resume) — plus that an old, pre-PR-15
// saved session (with no cadence recorded at all) still resumes fine.
//
// DETAILED: Apple Phase-2 PR-15 (#1428). Split out of `ServiceModeEngineTests.swift`
// (rather than added as a second `@Suite` in that same file) for TWO
// independent reasons: (1) SwiftLint's `type_body_length` warning — two
// `@Suite` structs in one file each still count as separate types, but the
// FILE itself still has to clear `Scripts/loc-budget.sh`'s 400-line
// tripwire, and appending here pushed that file over it; (2) it is the
// SAME "split when a file/type grows past budget" rule `.swiftlint.yml`'s
// header names as the philosophy for the `type_body_length`
// warning-vs-LOC-budget split in the first place. Reuses
// `ServiceModeEngineTests`'s `fileprivate` fixtures (`presenceToken`/
// `joinBody`/`unchangedPollBody`) and its `makeEngine(queue:sleeps:now:)`
// instance helper (via a throwaway `ServiceModeEngineTests()` instance) —
// `fileprivate` in Swift is FILE-scoped, but `ServiceModeEngineTests.swift`
// and THIS file are compiled as the same target/module, and Swift's
// `fileprivate` still means "this exact file" even across files in one
// module... which means `fileprivate` members are NOT visible here. See
// `ServiceModeEngineTests.swift`'s own doc comment on those members — they
// are `fileprivate` for `ServiceModeEngineProjectorTests` in THAT file
// (when it lived there); now that this suite is a separate file, this file
// instead duplicates the tiny fixtures it needs directly (three one-line
// JSON builders) — a small, deliberate trade-off: duplicating 3 short pure
// functions is cheaper and clearer than widening their visibility to
// `internal` (which would let every OTHER test file reach into
// `ServiceModeEngineTests`'s private fixtures too, the opposite of what
// `private`/`fileprivate` are for).
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHLive
@testable import IHModels

@Suite("ServiceModeEngine — PR-15 projector role + cadence persistence (#1428)", .serialized)
struct ServiceModeEngineProjectorTests {
    private static let presenceToken = "ye82rBJlEBjg0EPn7Yax1xMs0o5xQ9CuHt7kmkbsoOE"

    private static func joinBody(revision: Int = 1, pollIntervalMs: Int? = 2500, songId: String? = "MP-0031") -> Data {
        Data("""
        {"ok":true,"presenceToken":"\(presenceToken)","pollIntervalMs":\(pollIntervalMs.map(String.init) ?? "null"),"currentSongId":\(songId.map { "\"\($0)\"" } ?? "null"),"componentIndex":0,"state":null,"revision":\(revision)}
        """.utf8)
    }

    private static func unchangedPollBody(revision: Int) -> Data {
        Data(#"{"active":true,"changed":false,"revision":\#(revision)}"#.utf8)
    }

    private func makeEngine(queue: ActionResponseQueue, sleeps: SleepRecorder) -> (ServiceModeEngine, APIClient) {
        MockURLProtocol.requestHandler = { queue.handle($0) }
        let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
        let config = LiveSyncConfiguration(sleep: { duration in
            sleeps.record(duration)
            try await Task.sleep(nanoseconds: 200_000)
        })
        let identity = ServiceDeviceIdentity(defaults: UserDefaults(suiteName: UUID().uuidString)!)
        let persistence = LiveSyncPersistence(defaults: UserDefaults(suiteName: UUID().uuidString)!)
        let engine = ServiceModeEngine(apiClient: client, configuration: config, deviceIdentity: identity, persistence: persistence)
        return (engine, client)
    }

    @Test("join(role: .projector): role encodes as \"projector\"; the first recorded sleep is the server's 1000ms cadence")
    func joinAsProjectorUsesProjectorCadence() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "service_join", body: Self.joinBody(revision: 1, pollIntervalMs: 1_000))
            queue.enqueue(action: "service_poll", body: Self.unchangedPollBody(revision: 1))
            defer { MockURLProtocol.requestHandler = nil }

            let sleeps = SleepRecorder()
            let (engine, _) = makeEngine(queue: queue, sleeps: sleeps)

            let info = try await engine.join(code: "ABC123", role: .projector)
            #expect(info.pollIntervalMs == 1_000)

            await waitUntil { !sleeps.recorded.isEmpty }
            #expect(sleeps.recorded.first == .milliseconds(1_000))

            await engine.leave()
        }
    }

    @Test("resume: restores the joined pollIntervalMs — a projector's persisted record resumes at 1000ms, not the service default")
    func resumeRestoresProjectorCadence() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "service_poll", body: Self.unchangedPollBody(revision: 1))
            defer { MockURLProtocol.requestHandler = nil }
            MockURLProtocol.requestHandler = { queue.handle($0) }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let persistence = LiveSyncPersistence(defaults: UserDefaults(suiteName: UUID().uuidString)!)
            persistence.saveServicePresence(token: Self.presenceToken, revision: 1, joinedAt: Date(), pollIntervalMs: 1_000)
            let sleeps = SleepRecorder()
            let config = LiveSyncConfiguration(sleep: { duration in
                sleeps.record(duration)
                try await Task.sleep(nanoseconds: 200_000)
            })
            let engine = ServiceModeEngine(apiClient: client, configuration: config, persistence: persistence)

            await engine.resumeIfPossible()
            await waitUntil { !sleeps.recorded.isEmpty }
            #expect(sleeps.recorded.first == .milliseconds(1_000))

            await engine.leave()
        }
    }

    @Test("resume: a legacy on-disk record (no pollIntervalMs key, pre-PR-15) still decodes and resumes")
    func resumeDecodesLegacyRecordWithoutPollIntervalMs() async throws {
        try await MockTransportLock.shared.withLock {
            let queue = ActionResponseQueue()
            queue.enqueue(action: "service_poll", body: Self.unchangedPollBody(revision: 1))
            defer { MockURLProtocol.requestHandler = nil }
            MockURLProtocol.requestHandler = { queue.handle($0) }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let defaults = UserDefaults(suiteName: UUID().uuidString)!
            // A hand-built record in the PRE-PR-15 shape — no `pollIntervalMs`
            // key at all, proving `Optional`'s missing-key-decodes-to-nil
            // behaviour (`LiveSyncPersistence.swift`'s own doc note) rather
            // than a crash.
            let legacyJSON = Data("""
            {"token":"\(Self.presenceToken)","revision":1,"joinedAt":\(Date().timeIntervalSinceReferenceDate)}
            """.utf8)
            defaults.set(legacyJSON, forKey: "live.servicePresence")
            let persistence = LiveSyncPersistence(defaults: defaults)
            let engine = ServiceModeEngine(apiClient: client, persistence: persistence)
            let collector = EventCollector<ServiceModeEvent>()
            await collector.start(engine.events)

            await engine.resumeIfPossible()
            await waitUntil { await collector.received.contains { if case .joined = $0 { true } else { false } } }

            let received = await collector.received
            #expect(received.contains { if case .joined = $0 { true } else { false } })

            await collector.stop()
            await engine.leave()
        }
    }
}

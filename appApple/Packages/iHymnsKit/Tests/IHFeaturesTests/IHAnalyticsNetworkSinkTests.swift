// IHAnalyticsNetworkSinkTests.swift
// IHFeaturesTests
//
// ELI5: Proves the REAL analytics sink (#1448) keeps its two promises:
// never phones home while "Share Anonymous Usage Data" is off, and — once
// it's on — actually batches several events into ONE request instead of
// one request per event.
//
// DETAILED: Drives `IHAnalyticsNetworkSink.enqueue(_:)`/`.flushNow()`
// directly (awaited, deterministic) rather than through `record(_:)`
// (which dispatches through an untracked `Task` — see that method's own
// doc comment for why tests avoid it). Uses the SAME `MockURLProtocol` +
// `MockTransportLock` seam `NetworkedAPIClientTests.swift`/
// `SessionControllerTests.swift` already establish, `@Suite(.serialized)`
// for the same "this suite mutates the shared static `requestHandler`"
// reason those suites are.
import Foundation
import Testing
import IHAPI
import IHAPITestSupport
import IHAppSupport
@testable import IHFeatures

/// `URLSession` frequently moves a POST's body into `httpBodyStream`
/// rather than leaving it on `httpBody` once the request is actually
/// handed to a transport (even a mocked one) — `Endpoint`-level tests
/// (`AnalyticsEndpointTests.swift`) never hit this because they inspect
/// `endpoint.httpBody` BEFORE any `URLSession` involvement; THIS suite
/// captures the request `MockURLProtocol` sees, after that move may have
/// happened, so it reads both possible locations.
private func analyticsSinkTestsRequestBodyData(_ request: URLRequest) -> Data {
    if let body = request.httpBody { return body }
    guard let stream = request.httpBodyStream else { return Data() }
    stream.open()
    defer { stream.close() }
    var data = Data()
    let bufferSize = 4096
    var buffer = [UInt8](repeating: 0, count: bufferSize)
    while stream.hasBytesAvailable {
        let bytesRead = stream.read(&buffer, maxLength: bufferSize)
        guard bytesRead > 0 else { break }
        data.append(buffer, count: bytesRead)
    }
    return data
}

/// Thread-safe capture of every request `MockURLProtocol` handed to this
/// suite's handler — mirrors `IHAPITestSupport.LockedCounter`'s
/// `@unchecked Sendable` + `NSLock` shape, but records the request BODIES
/// (needed to assert how many events landed in one POST), not just a count.
private final class RequestRecorder: @unchecked Sendable {
    private let lock = NSLock()
    private var bodies: [Data] = []

    func record(_ body: Data) {
        lock.lock()
        defer { lock.unlock() }
        bodies.append(body)
    }

    var requestCount: Int {
        lock.lock()
        defer { lock.unlock() }
        return bodies.count
    }

    var lastEventCount: Int? {
        lock.lock()
        defer { lock.unlock() }
        guard let last = bodies.last,
              let json = try? JSONSerialization.jsonObject(with: last) as? [String: Any],
              let events = json["events"] as? [[String: Any]] else { return nil }
        return events.count
    }
}

// Deliberately NOT `@MainActor` — `IHAnalyticsNetworkSink` is a plain
// `actor` (see that type's own header), and this suite's helper functions
// are called from `MockURLProtocol.requestHandler`, a plain `@Sendable`,
// non-isolated closure; forcing `@MainActor` here (as `IHAnalyticsServiceTests`
// does, correctly, for the DIFFERENT `@MainActor`-isolated `IHAnalyticsService`)
// would make those helpers uncallable from that closure.
@Suite("IHAnalyticsNetworkSink", .serialized)
struct IHAnalyticsNetworkSinkTests {

    private func makeSettingsStore(consentEnabled: Bool) -> IHSettingsStore {
        let defaults = UserDefaults(suiteName: "ihtest.analyticssink.\(UUID().uuidString)")!
        let store = IHSettingsStore(defaults: defaults)
        store.analyticsConsentEnabled = consentEnabled
        return store
    }

    private static func okResponse(for request: URLRequest) -> (HTTPURLResponse, Data) {
        let response = HTTPURLResponse(url: request.url!, statusCode: 200, httpVersion: nil, headerFields: nil)!
        return (response, Data(#"{"ok":true,"accepted":1,"rejected":0}"#.utf8))
    }

    @Test("Consent OFF: flushNow() sends nothing and drains the queue")
    func consentOffNeverSendsARequest() async throws {
        try await MockTransportLock.shared.withLock {
            let recorder = RequestRecorder()
            MockURLProtocol.requestHandler = { request in
                recorder.record(analyticsSinkTestsRequestBodyData(request))
                return Self.okResponse(for: request)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let sink = IHAnalyticsNetworkSink(
                session: MockURLProtocol.makeSession(),
                settingsStore: makeSettingsStore(consentEnabled: false),
                batchSize: 10
            )
            await sink.enqueue(.screenView("Home"))
            let sent = await sink.flushNow()

            #expect(sent == false)
            #expect(recorder.requestCount == 0)
        }
    }

    @Test("Batching: enqueueing batchSize events auto-flushes exactly ONE request containing all of them")
    func batchSizeTriggersOneRequestWithAllEvents() async throws {
        try await MockTransportLock.shared.withLock {
            let recorder = RequestRecorder()
            MockURLProtocol.requestHandler = { request in
                recorder.record(analyticsSinkTestsRequestBodyData(request))
                return Self.okResponse(for: request)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let sink = IHAnalyticsNetworkSink(
                session: MockURLProtocol.makeSession(),
                settingsStore: makeSettingsStore(consentEnabled: true),
                batchSize: 3
            )
            await sink.enqueue(.screenView("Home"))
            await sink.enqueue(.songOpened(songId: "MP-1008"))
            #expect(recorder.requestCount == 0, "below batchSize — no flush yet")

            await sink.enqueue(.searchPerformed(resultCount: 5))   // 3rd event — hits batchSize
            #expect(recorder.requestCount == 1, "exactly one batched request, not three individual ones")
            #expect(recorder.lastEventCount == 3)
        }
    }

    @Test("flushNow() sends a partial (below-batchSize) queue on demand")
    func flushNowSendsAPartialBatch() async throws {
        try await MockTransportLock.shared.withLock {
            let recorder = RequestRecorder()
            MockURLProtocol.requestHandler = { request in
                recorder.record(analyticsSinkTestsRequestBodyData(request))
                return Self.okResponse(for: request)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let sink = IHAnalyticsNetworkSink(
                session: MockURLProtocol.makeSession(),
                settingsStore: makeSettingsStore(consentEnabled: true),
                batchSize: 20
            )
            await sink.enqueue(.offlineSave(songId: "MP-1008"))
            let sent = await sink.flushNow()

            #expect(sent == true)
            #expect(recorder.requestCount == 1)
            #expect(recorder.lastEventCount == 1)
        }
    }

    @Test("flushNow() on an empty queue is a no-op — no request, returns false")
    func flushNowOnEmptyQueueIsANoOp() async throws {
        try await MockTransportLock.shared.withLock {
            let recorder = RequestRecorder()
            MockURLProtocol.requestHandler = { request in
                recorder.record(analyticsSinkTestsRequestBodyData(request))
                return Self.okResponse(for: request)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let sink = IHAnalyticsNetworkSink(
                session: MockURLProtocol.makeSession(),
                settingsStore: makeSettingsStore(consentEnabled: true)
            )
            let sent = await sink.flushNow()

            #expect(sent == false)
            #expect(recorder.requestCount == 0)
        }
    }

    @Test("Consent revoked between queuing and flushing: the already-queued batch is dropped, not sent")
    func consentRevokedBeforeFlushDropsTheQueue() async throws {
        try await MockTransportLock.shared.withLock {
            let recorder = RequestRecorder()
            MockURLProtocol.requestHandler = { request in
                recorder.record(analyticsSinkTestsRequestBodyData(request))
                return Self.okResponse(for: request)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let store = makeSettingsStore(consentEnabled: true)
            let sink = IHAnalyticsNetworkSink(
                session: MockURLProtocol.makeSession(),
                settingsStore: store,
                batchSize: 20
            )
            await sink.enqueue(.songOpened(songId: "MP-1008"))
            store.analyticsConsentEnabled = false   // revoked before the batch ever flushed

            let sent = await sink.flushNow()

            #expect(sent == false)
            #expect(recorder.requestCount == 0)
        }
    }

    @Test("A failed request is swallowed — failure-silent, matches IHAnalyticsSink's contract")
    func serverErrorIsFailureSilent() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { request in
                let response = HTTPURLResponse(url: request.url!, statusCode: 500, httpVersion: nil, headerFields: nil)!
                return (response, Data())
            }
            defer { MockURLProtocol.requestHandler = nil }

            let sink = IHAnalyticsNetworkSink(
                session: MockURLProtocol.makeSession(),
                settingsStore: makeSettingsStore(consentEnabled: true),
                batchSize: 20
            )
            await sink.enqueue(.screenView("Home"))
            // Must not throw — flushNow() has no `throws` in its signature,
            // so this line alone proves the failure never escapes.
            let sent = await sink.flushNow()
            #expect(sent == false)
        }
    }
}

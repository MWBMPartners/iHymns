// LANRemoteTestSupport.swift
// IHLiveTests
//
// ELI5: The handful of little test-only helpers every LANRemote test file
// needs — a fake clock that only moves when a test tells it to, and a "wait
// for this async thing to happen, but don't hang forever if it never does"
// helper — kept in one place so no test file re-invents them.
//
// DETAILED: #1420. `ManualLANRemoteClock` is the test double for
// `LANRemoteClock` (`Sources/IHLive/LANRemote/LANRemoteClock.swift`) — the
// task brief's "Injected clock (no wall-clock sleeps)" requirement applied
// to every test that cares about `IHRPFrame.timestamp`/last-writer-wins
// ordering. `waitFor`/`waitForCondition` below are NOT a workaround for
// that rule — they bound how long a test waits for a REAL asynchronous
// network event (a TLS handshake completing, a byte arriving over 127.0.0.1)
// to settle, which cannot be "injected" away since it's genuine OS-level
// I/O, not business-logic timing. Every test that reasons about SEQUENCE
// or ORDERING still does so via `ManualLANRemoteClock`/directly-constructed
// `IHRPFrame` timestamps, never by racing real sleeps against each other.
import Foundation
@testable import IHLive

/// A `LANRemoteClock` a test fully controls — starts at a fixed instant,
/// only moves when `advance(by:)` is called.
final class ManualLANRemoteClock: LANRemoteClock, @unchecked Sendable {
    private let lock = NSLock()
    private var current: Date

    init(start: Date = Date(timeIntervalSince1970: 1_700_000_000)) {
        self.current = start
    }

    func now() -> Date {
        lock.lock()
        defer { lock.unlock() }
        return current
    }

    func advance(by seconds: TimeInterval) {
        lock.lock()
        current = current.addingTimeInterval(seconds)
        lock.unlock()
    }
}

/// Thrown by `waitFor`/`waitForCondition` when their real-world timeout
/// elapses — always a TEST FAILURE (either the code under test is broken,
/// or the timeout is too short for the CI host), never an expected outcome
/// a test asserts on directly.
struct LANRemoteTestTimeoutError: Error {}

/// Consumes `stream` until an element matching `predicate` arrives, or
/// `timeout` elapses.
///
/// ELI5: "Watch this stream of events until you see the one I'm looking
/// for — but give up after a few seconds if it never shows up."
func waitFor<Element: Sendable>(
    _ stream: AsyncStream<Element>,
    timeout: Duration = .seconds(5),
    where predicate: @escaping @Sendable (Element) -> Bool
) async throws -> Element {
    try await withThrowingTaskGroup(of: Element.self) { group in
        group.addTask {
            for await element in stream where predicate(element) {
                return element
            }
            throw LANRemoteTestTimeoutError()
        }
        group.addTask {
            try await Task.sleep(for: timeout)
            throw LANRemoteTestTimeoutError()
        }
        guard let result = try await group.next() else { throw LANRemoteTestTimeoutError() }
        group.cancelAll()
        return result
    }
}

/// Polls `condition` (a cheap, actor-hopping async read — e.g.
/// `await listener.pairingConnectionIds.isEmpty`) until it returns `true`
/// or `timeout` elapses. Only ever used to wait for genuine OS/network
/// async completion (e.g. "has the TV finished processing the `hello` this
/// remote already sent over the wire") — never to simulate a business-logic
/// time window, which every test in this suite instead drives via
/// `ManualLANRemoteClock`.
func waitForCondition(
    timeout: Duration = .seconds(5),
    pollInterval: Duration = .milliseconds(5),
    _ condition: @escaping @Sendable () async -> Bool
) async throws {
    let deadline = ContinuousClock.now + timeout
    while ContinuousClock.now < deadline {
        if await condition() { return }
        try await Task.sleep(for: pollInterval)
    }
    throw LANRemoteTestTimeoutError()
}

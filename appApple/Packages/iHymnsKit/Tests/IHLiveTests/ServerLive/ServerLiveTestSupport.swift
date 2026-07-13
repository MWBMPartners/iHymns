// ServerLiveTestSupport.swift
// IHLiveTests/ServerLive
//
// ELI5: The little helpers BOTH engine-loop test files share — a fake
// network that hands back a queued, per-`action=` canned response (repeating
// the last one forever once the queue runs dry, so a loop that keeps
// spinning after a test's assertions never crashes it), a recorder for
// every `Duration` the engines ask to "sleep" for, and a tiny "keep
// checking until this becomes true, or give up after a timeout" waiter for
// asserting on a BACKGROUND `Task` loop's progress without a real wall-clock
// wait.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §7.3). `LiveSyncConfiguration.sleep` here returns essentially immediately
// (recording the Duration first) — the engines' `while` loops are therefore
// bounded ONLY by how fast the mocked network answers, which is why every
// canned queue's LAST entry is a deliberately-harmless "nothing new"
// response: once a test's queue of interesting transitions is exhausted,
// further (uncontrolled) loop spins just repeat that steady state rather
// than throwing or corrupting the state under test. Assertions on
// loop-driven facts use `waitUntil` (a bounded poll, not a fixed sleep) so
// they're robust to exactly how many extra spins happened before the
// interesting one was observed.
import Foundation

/// Routes `MockURLProtocol` requests by their `?action=` value to a
/// per-action FIFO queue of canned `(status, body)` responses.
final class ActionResponseQueue: @unchecked Sendable {
    private let lock = NSLock()
    private var queues: [String: [(Int, Data)]] = [:]
    private var log: [(action: String, url: URL)] = []

    func enqueue(action: String, status: Int = 200, body: Data) {
        lock.lock(); defer { lock.unlock() }
        queues[action, default: []].append((status, body))
    }

    /// `MockURLProtocol.requestHandler`'s body.
    func handle(_ request: URLRequest) -> (HTTPURLResponse, Data) {
        lock.lock()
        let action = Self.action(from: request)
        // swiftlint:disable:next force_unwrapping
        log.append((action: action, url: request.url!))
        let next: (Int, Data)
        var queue = queues[action] ?? []
        if queue.isEmpty {
            next = (200, Data("{}".utf8))
        } else if queue.count == 1 {
            next = queue[0]
        } else {
            next = queue.removeFirst()
            queues[action] = queue
        }
        lock.unlock()
        // swiftlint:disable:next force_unwrapping
        let response = HTTPURLResponse(url: request.url!, statusCode: next.0, httpVersion: nil, headerFields: nil)!
        return (response, next.1)
    }

    func count(of action: String) -> Int {
        lock.lock(); defer { lock.unlock() }
        return log.filter { $0.action == action }.count
    }

    /// The `?since=` query value of every logged request for `action`, in
    /// the order they arrived — used to prove the poll cadence's `since`
    /// value tracks the advancing revision.
    func sinceValues(forAction action: String) -> [String] {
        lock.lock(); defer { lock.unlock() }
        return log.filter { $0.action == action }.compactMap { entry in
            entry.url.query?.split(separator: "&")
                .first { $0.hasPrefix("since=") }
                .map { String($0.dropFirst("since=".count)) }
        }
    }

    static func action(from request: URLRequest) -> String {
        guard let query = request.url?.query else { return "" }
        for pair in query.split(separator: "&") {
            let parts = pair.split(separator: "=", maxSplits: 1)
            if parts.first == "action" {
                return parts.count > 1 ? String(parts[1]) : ""
            }
        }
        return ""
    }
}

/// Records every `Duration` a `LiveSyncConfiguration.sleep` closure was
/// asked to wait for, then returns immediately (a real, tiny yield — never
/// a true wall-clock wait) — see this file's header.
final class SleepRecorder: @unchecked Sendable {
    private let lock = NSLock()
    private var durations: [Duration] = []

    func record(_ duration: Duration) {
        lock.lock(); defer { lock.unlock() }
        durations.append(duration)
    }

    var recorded: [Duration] {
        lock.lock(); defer { lock.unlock() }
        return durations
    }
}

/// The ONE consumer of an engine's `events` stream for a test — collects
/// every value into an array a test can inspect after `await`-ing whatever
/// it was waiting for. Mirrors the "exactly one `for await` loop per
/// stream" rule the product code itself follows.
actor EventCollector<Event: Sendable> {
    private(set) var received: [Event] = []
    private var task: Task<Void, Never>?

    func start(_ stream: AsyncStream<Event>) {
        task = Task { [weak self] in
            for await event in stream {
                guard let self else { break }
                await self.append(event)
            }
        }
    }

    private func append(_ event: Event) {
        received.append(event)
    }

    func stop() {
        task?.cancel()
    }
}

/// Polls `condition` every couple of milliseconds until it returns `true`,
/// or `timeoutMs` elapses (whichever first) — the bounded-wait idiom every
/// loop-driven assertion below uses instead of a fixed `Task.sleep`.
func waitUntil(timeoutMs: Int = 2_000, _ condition: @Sendable () async -> Bool) async {
    let deadline = ContinuousClock.now.advanced(by: .milliseconds(timeoutMs))
    while await !condition(), ContinuousClock.now < deadline {
        try? await Task.sleep(nanoseconds: 2_000_000)
    }
}

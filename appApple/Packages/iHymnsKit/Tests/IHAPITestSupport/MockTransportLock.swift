// MockTransportLock.swift
// IHAPITestSupport
//
// ELI5: `MockURLProtocol.requestHandler` is ONE shared "which fake response
// do I hand back" slot — fine as long as only ONE test uses it at a time.
// This file is the bouncer that makes sure of that, even across DIFFERENT
// test suites (and different test TARGETS) that all reuse the same mock.
//
// DETAILED: `@Suite(.serialized)` (used throughout `IHAPITests`/
// `IHAuthTests`/`IHFeaturesTests`) only guarantees tests WITHIN that one
// suite run one at a time relative to EACH OTHER — Swift Testing still
// happily runs DIFFERENT suites concurrently on its cooperative thread
// pool. Once `MockURLProtocol` moved from being `IHAPITests`-only (#1397)
// to a shared `IHAPITestSupport` target consumed by `IHAuthTests`'
// `SessionController` tests and `IHFeaturesTests`' `AppRootViewModel`/
// `SongDetailViewModel` tests too (#1398/#1399), a second suite's
// `requestHandler` assignment could — and, observed empirically while
// building this, DID — stomp on a concurrently-running suite's in-flight
// mock, misrouting its request to the wrong canned response. This actor is
// the fix: a real async mutex (NOT `NSLock`/`DispatchSemaphore`, which
// can't safely guard a critical section spanning an `await` without risking
// deadlocking the cooperative thread pool) that every `MockURLProtocol`
// -using test wraps its `requestHandler`-setting body in, so at most one
// such test runs at a time PROCESS-WIDE, regardless of which suite/target
// it's declared in.
//
// This is the standard "actor-based async semaphore" recipe: `acquire()`
// checks a plain `isBusy` flag and, if already held, suspends the caller on
// a `CheckedContinuation` queued in `waiters` rather than busy-spinning;
// `release()` hands the lock directly to the next waiter (if any) instead
// of ever setting `isBusy = false` while someone is queued, so ownership
// transfers without a window where a THIRD caller could sneak in between.
// Actor reentrancy (an isolated method can be re-entered by another queued
// call while the current one is suspended at an `await`) is exactly what
// makes this correct rather than a hazard: while a caller's `body()` is
// mid-flight (suspended awaiting the mocked network round trip), another
// caller's `acquire()` CAN run on this same actor — and it must, to see
// `isBusy == true` and correctly queue itself, rather than being blocked
// from running at all.
import Foundation

/// A process-wide async mutex serializing every test that drives
/// `MockURLProtocol.requestHandler`.
public actor MockTransportLock {
    /// One process-wide instance — deliberately a singleton (not per-test)
    /// because the resource it protects (`MockURLProtocol`'s static
    /// `requestHandler`) is ITSELF process-wide; a per-test lock instance
    /// would protect nothing.
    public static let shared = MockTransportLock()

    private var isBusy = false
    private var waiters: [CheckedContinuation<Void, Never>] = []

    private init() {}

    /// Runs `body` with exclusive ownership of the mock transport — no
    /// other `withLock` caller (in ANY suite/target) can be inside its own
    /// `body` at the same time.
    ///
    /// ELI5: "Wait your turn, then go — and let the next one in line go
    /// once you're done."
    ///
    /// DETAILED: Declared plain `async throws` (NOT `rethrows`) even though
    /// every real caller's `body` closure throws — `rethrows`'s "does the
    /// argument closure literal throw" inference interacts badly with a
    /// generic `Value` return type here (observed directly while building
    /// this: a trailing closure containing only a `try` nested inside a
    /// FURTHER closure, e.g. `#expect(throws:) { try await ... }`, could
    /// get the enclosing `withLock` body closure mis-inferred as
    /// non-throwing, then fail to compile the very next bare `try`
    /// statement in that same closure). A plain `throws` function has no
    /// such special-case inference: the argument is simply type-checked
    /// against the declared `@Sendable () async throws -> Value` parameter
    /// type, unconditionally — robust at the minor cost of every call site
    /// needing `try` even on the rare non-throwing body.
    public func withLock<Value: Sendable>(_ body: @Sendable () async throws -> Value) async throws -> Value {
        await acquire()
        defer { release() }
        return try await body()
    }

    private func acquire() async {
        if !isBusy {
            isBusy = true
            return
        }
        await withCheckedContinuation { continuation in
            waiters.append(continuation)
        }
    }

    /// Non-`async` deliberately — called from `withLock`'s `defer`, which
    /// (like every `defer`) can only run synchronous code. Safe to call
    /// synchronously here because it always runs from within THIS actor's
    /// own already-isolated context (never from outside it).
    private func release() {
        guard !waiters.isEmpty else {
            isBusy = false
            return
        }
        // Hand the lock directly to the next waiter — `isBusy` stays
        // `true` throughout, so no third caller can slip in between this
        // release and the next waiter actually resuming.
        let next = waiters.removeFirst()
        next.resume()
    }
}

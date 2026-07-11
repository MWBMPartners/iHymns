// ProjectionViewModelTestSupport.swift
// IHFeaturesTests
//
// ELI5: The two small test-only helpers `ProjectionViewModelTests.swift`
// needs but that aren't worth inlining there: a safe way to "wait for the
// next thing `ProjectionViewModel` published" and a fake network fetch that
// a test can pause and resume on demand.
//
// DETAILED: #1504. Split out of `ProjectionViewModelTests.swift` purely to
// keep that file under the repo's LOC-budget tripwire (`Scripts/loc-budget.sh`,
// which scans every `*.swift` file under `appApple/` including test files) —
// the same "same-type/same-concern code in its own file" split
// `TVListenerActor`/`TVListenerActor+Messages.swift` and `APIClient`/
// `APIClient+Networking.swift` already establish for production code,
// applied here to test infrastructure. Every type below is plain `internal`
// (not `private`) specifically so the sibling test file can use it — Swift's
// `private`/`fileprivate` are FILE-scoped, the same cross-file-visibility
// note `AppRootViewModel.recentSearches`/`TVListenerActor.connections`
// already document in this package's production code.
//
// `ProjectionStateCollector` replaces a first attempt at mirroring
// `LANRemoteTestSupport.waitFor`'s shape LITERALLY (a `for await` raced
// against a `Task.sleep` inside a `TaskGroup`, cancelling the loser) — that
// shape is UNSAFE reused repeatedly against `stateUpdates` itself:
// EMPIRICALLY VERIFIED while building this suite, cancelling a `for await`
// loop over an `AsyncStream` leaves it unable to deliver to ANY future
// consumer afterward (a stray "expect nothing published" timeout would
// silently swallow every subsequent real publish for the rest of the test).
// `stateUpdates`'s own contract is "exactly ONE intended consumer" anyway
// (its doc comment), so the fix is to actually HONOUR that: exactly ONE
// persistent, never-cancelled-mid-test `for await` loop drains `stateUpdates`
// into this plain buffering actor, and every wait races only against THIS
// actor's own (cancellation-safe) continuation, never against the raw
// stream again.
import Foundation
import Testing
@testable import IHFeatures
@testable import IHModels

// MARK: - Cancellation-safe `stateUpdates` collector

actor ProjectionStateCollector {
    private var buffer: [ProjectionViewModel.ProjectionState] = []
    private var waiters: [(id: UUID, continuation: CheckedContinuation<ProjectionViewModel.ProjectionState?, Never>)] = []

    func push(_ state: ProjectionViewModel.ProjectionState) {
        buffer.append(state)
        drain()
    }

    /// The next published state, or `nil` if none arrives before `timeout`.
    /// A timed-out wait removes its OWN registration (`cancelWaiter`)
    /// rather than leaving a dangling waiter that could intercept a LATER,
    /// genuinely-published value — the bug this whole type exists to avoid.
    func next(timeout: Duration) async -> ProjectionViewModel.ProjectionState? {
        if !buffer.isEmpty { return buffer.removeFirst() }
        let id = UUID()
        return await withTaskGroup(of: ProjectionViewModel.ProjectionState?.self) { group in
            group.addTask {
                await withCheckedContinuation { (continuation: CheckedContinuation<ProjectionViewModel.ProjectionState?, Never>) in
                    Task { await self.register(id: id, continuation: continuation) }
                }
            }
            group.addTask {
                try? await Task.sleep(for: timeout)
                return nil
            }
            let result = (await group.next()) ?? nil
            group.cancelAll()
            self.cancelWaiter(id)
            return result
        }
    }

    private func drain() {
        guard !buffer.isEmpty, !waiters.isEmpty else { return }
        let (_, continuation) = waiters.removeFirst()
        continuation.resume(returning: buffer.removeFirst())
    }

    private func register(id: UUID, continuation: CheckedContinuation<ProjectionViewModel.ProjectionState?, Never>) {
        waiters.append((id, continuation))
        drain()
    }

    private func cancelWaiter(_ id: UUID) {
        guard let index = waiters.firstIndex(where: { $0.id == id }) else { return }
        waiters.remove(at: index).continuation.resume(returning: nil)
    }
}

struct ProjectionTestTimeoutError: Error {}

func waitForState(_ collector: ProjectionStateCollector, timeout: Duration = .seconds(5)) async throws -> ProjectionViewModel.ProjectionState {
    guard let state = await collector.next(timeout: timeout) else { throw ProjectionTestTimeoutError() }
    return state
}

/// Asserts NOTHING arrives within a short bound. Safe with a tight timeout
/// (unlike a real network wait): the operation under test has already
/// fully returned by the time this is called, so there is no slow-but-
/// eventually-arriving producer to race against.
func expectNothingPublished(_ collector: ProjectionStateCollector) async {
    let result = await collector.next(timeout: .milliseconds(50))
    #expect(result == nil, "expected nothing published, got \(String(describing: result))")
}

/// Starts the ONE persistent `stateUpdates` consumer a test needs — cancel
/// it via the returned task's `.cancel()` (every call site uses `defer`)
/// once the test is done, but NEVER mid-test (see this file's header for
/// why an early cancel would poison the stream).
func makeCollector(for viewModel: ProjectionViewModel) -> (ProjectionStateCollector, Task<Void, Never>) {
    let collector = ProjectionStateCollector()
    let consumer = Task {
        for await state in viewModel.stateUpdates {
            await collector.push(state)
        }
    }
    return (collector, consumer)
}

// MARK: - Gated fetcher (latest-wins test)

/// Suspends a `fetch(_:)` call until released — lets the latest-wins test
/// control exactly when each of two overlapping fetches resolves.
/// `waitUntilArrived(_:)` is the deterministic (non-sleeping) way to know a
/// given fetch has actually reached this actor and is now parked.
actor GatedFetcher {
    private let songsById: [SongID: SongDetail]
    private var pending: [SongID: CheckedContinuation<SongDetail, Error>] = [:]
    private var arrived: Set<SongID> = []
    private var arrivalWaiters: [SongID: CheckedContinuation<Void, Never>] = [:]

    init(songs: [SongDetail]) {
        songsById = Dictionary(uniqueKeysWithValues: songs.map { ($0.songId, $0) })
    }

    func waitUntilArrived(_ id: SongID) async {
        guard !arrived.contains(id) else { return }
        await withCheckedContinuation { arrivalWaiters[id] = $0 }
    }

    func fetch(_ id: SongID) async throws -> SongDetail {
        arrived.insert(id)
        arrivalWaiters.removeValue(forKey: id)?.resume()
        return try await withCheckedThrowingContinuation { pending[id] = $0 }
    }

    func release(_ id: SongID) {
        guard let continuation = pending.removeValue(forKey: id), let detail = songsById[id] else { return }
        continuation.resume(returning: detail)
    }
}

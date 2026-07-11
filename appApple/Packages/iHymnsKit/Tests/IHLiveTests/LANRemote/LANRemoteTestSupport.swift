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

/// Whether THIS process can actually bridge a self-signed identity through
/// the keychain — the precondition every test that builds a real
/// `LANRemoteIdentity` / `TVListenerActor` implicitly needs.
///
/// ELI5: "Can this machine actually make a TV identity right now?" — yes on
/// a normal dev Mac, no on the CI runner.
///
/// DETAILED: #1420. `LANRemoteIdentityFactory.generateSelfSigned()` adds a
/// loose `SecKey` + `SecCertificate` under a shared label and then queries
/// `kSecClassIdentity` to get them linked into a `SecIdentity`
/// (`LANRemoteIdentity.swift`'s header comment explains why that Keychain
/// round-trip is the only public route to a `SecIdentity`). On a headless,
/// **unsigned** `swift test` binary (GitHub `macos-26` CI) the two `SecItemAdd`
/// calls succeed but that final identity query returns
/// `errSecItemNotFound` (-25300) — there is no login keychain to reconstruct
/// the pair into an identity, and forcing the file-based keychain isn't an
/// option because the same factory must run on iOS/tvOS (data-protection
/// keychain only). This is an environment limitation of the entitlement-less
/// test binary, **not** a code defect: the real signed tvOS app builds its
/// identity fine, and a dev Mac with an unlocked login keychain runs these
/// tests for real. Suites/tests that need a genuine identity gate on this so
/// CI cleanly **skips** them instead of red-failing — the exact posture
/// `LANRemoteLoopbackTests` takes for the Local Network permission it can't
/// obtain. Cleans up the throwaway identity it probes with when it succeeds.
func lanRemoteKeychainIdentityAvailable() -> Bool {
    guard let identity = try? LANRemoteIdentityFactory.generateSelfSigned() else { return false }
    identity.removeFromKeychain()
    return true
}

/// A process-wide mutex every REAL-Keychain-touching LANRemote test wraps
/// its body in — `await KeychainTestSerialization.shared.run { ... }`.
///
/// ELI5: "Only one Keychain test gets to actually talk to the Keychain at a
/// time — everyone else politely waits their turn," so a burst of tests
/// from DIFFERENT files never all hammer `Security.framework` at once.
///
/// DETAILED: #1421. Empirically REQUIRED (not just a nice-to-have): Swift
/// Testing runs different `@Suite` types concurrently with each other by
/// default — a PER-SUITE `.serialized` trait (which `LANRemoteIdentityTests`/
/// `LANRemoteIdentityStoreTests`/`KeychainPairingAuthorityTests` all also
/// carry) only stops a suite's OWN tests from overlapping EACH OTHER, not
/// from overlapping ANOTHER suite's tests. Once this file grew a SECOND
/// (`LANRemoteIdentityStoreTests`) and THIRD (`KeychainPairingAuthorityTests`)
/// suite that call the SAME `LANRemoteIdentityFactory`/real Keychain APIs
/// PR-4's original `LANRemoteIdentityTests` already used, running a plain
/// `swift test` reproducibly hit TRANSIENT `errSecItemNotFound`/"failed to
/// generate CDSA key" failures under concurrent `SecKeyCreateRandomKey`/
/// `SecItemAdd`/`SecItemCopyMatching` load — a genuine macOS Keychain-daemon
/// contention artifact under heavy simultaneous access from multiple
/// threads, not a logic bug (a bounded retry was ALSO added in
/// `LANRemoteIdentityFactory.generatePermanentKey(attributes:)` for the
/// identical reason production code faces the same theoretical, if far
/// rarer, contention window — this lock additionally eliminates it for the
/// test suite specifically, since a real tvOS app never runs 13 Keychain
/// tests concurrently the way this local package's test binary does).
///
/// A true mutex (not merely `.serialized`): `run(_:)` suspends a caller
/// until any IN-PROGRESS call — INCLUDING one currently suspended at an
/// `await` inside its own body — has fully finished, unlike a naive
/// `actor`-isolated method (which Swift's actor-reentrancy rules would
/// allow a SECOND caller to enter the moment the first one hits its own
/// first `await`, defeating the whole point here).
actor KeychainTestSerialization {
    static let shared = KeychainTestSerialization()
    private init() {}

    private var isBusy = false
    private var waiters: [CheckedContinuation<Void, Never>] = []

    /// Runs `body` with exclusive access — every other concurrent caller
    /// (from ANY suite) waits until this call fully completes (success OR
    /// throw) before its own `body` begins.
    func run<T: Sendable>(_ body: () async throws -> T) async throws -> T {
        await acquire()
        do {
            let result = try await body()
            release()
            return result
        } catch {
            release()
            throw error
        }
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

    private func release() {
        if waiters.isEmpty {
            isBusy = false
        } else {
            waiters.removeFirst().resume()
        }
    }
}

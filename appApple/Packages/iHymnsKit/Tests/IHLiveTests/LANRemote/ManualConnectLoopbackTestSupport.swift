// ManualConnectLoopbackTestSupport.swift
// IHLiveTests
//
// ELI5: A "watch this session's events for a bit, and complain if the
// forbidden thing happens" helper — used by the one test that has to prove
// something NEVER happens rather than that something DOES.
//
// DETAILED: #1424. Split out of `ManualConnectLoopbackTests.swift` purely
// to keep that file's `function_body_length`/overall length under
// SwiftLint's budget (the `RemoteControlSessionLoopbackTests`/
// `RemoteControlSessionReArmTests.swift` precedent for the identical
// reason) — `internal` (not `private`) so that file's
// `pinHoldsAgainstIdentitySwap` test can call it.
import Foundation
import Testing
import os
@testable import IHLive

/// Watches `events` for up to `timeout`, recording an `Issue` if
/// `.controlling` or `.awaitingFingerprintConfirmation` ever appears.
///
/// NOT a deadline-checked-between-`next()`-calls loop (that idiom,
/// `RemoteControlSessionLoopbackTests.waitForControlling`'s, assumes events
/// keep arriving). A genuine bounded race instead: an UNSTRUCTURED task
/// drains `events` (may leak forever if it never gets another event —
/// tolerated here the same way `RemoteControlSessionLoopbackTests.swift`'s
/// own header already tolerates a leaked ping-ticker task under
/// `.serialized`), a SEPARATE task sleeps for the window; a
/// `CheckedContinuation` resolves on WHICHEVER finishes first, guarded so
/// only the first ever resumes it.
///
/// ★ ADVERSARIAL FINDING this exists to be robust against (pre-existing,
/// NOT introduced by this PR, OUT OF SCOPE to fix — `RemoteSessionActor
/// +Connection.swift` takes ZERO diff, D-2): a real diagnostic proved
/// `NWConnection` reaches `.waiting` (never `.failed`) both for "nothing
/// ever listened here" AND for a TLS verify-block rejection against a LIVE
/// peer (a pin mismatch) — `onConnectionStateChanged` only resolves
/// `.ready`/`.failed`, so EITHER case can leave a connect attempt's
/// `pendingConnectContinuation` — and therefore a reconnect ladder's retry
/// — parked forever. Every EXISTING (PR-4→PR-7) test only ever reconnects
/// to an address that answered at least once, so this gap was invisible
/// before; PR-8's "identity swap" scenario is the first to exercise a
/// pin-mismatch RECONNECT and surfaces it. Flag for the Opus reviewer / a
/// follow-up issue — this helper makes the test robust to it, not a fix
/// for it.
///
/// `events` can't be captured directly by an escaping `Task {}` closure
/// under Swift 6 strict concurrency ("still accessible to code in the
/// current task") — boxed in an `@unchecked Sendable` class instead, the
/// same controlled-mutable-sharing idiom `ManualLANRemoteClock`
/// (`LANRemoteTestSupport.swift`) already uses; only the ONE spawned task
/// below ever touches it.
func assertNeverReachesControllingOrConfirmation(
    _ events: AsyncStream<RemoteControlSession.Event>.AsyncIterator, timeout: Duration
) async {
    let eventsBox = EventIteratorBox(events)
    let resolveOnce = OSAllocatedUnfairLock<Bool>(initialState: false)
    await withCheckedContinuation { (continuation: CheckedContinuation<Void, Never>) in
        func finish() {
            let isFirst = resolveOnce.withLock { resolved -> Bool in
                guard !resolved else { return false }
                resolved = true
                return true
            }
            guard isFirst else { return }
            continuation.resume()
        }
        Task {
            while true {
                guard let event = await eventsBox.iterator.next() else { break }
                switch event {
                case .controlling, .awaitingFingerprintConfirmation:
                    Issue.record("TOFU must be unreachable from a reconnect — got \(event)")
                    finish()
                    return
                default:
                    break
                }
            }
            finish()
        }
        Task {
            try? await Task.sleep(for: timeout)
            finish()
        }
    }
}

/// A one-shot mutable box around a session's event iterator, so it can be
/// captured by an escaping `Task {}` closure without tripping Swift 6
/// strict concurrency's "still accessible from the current task" check on
/// a `var` local. `@unchecked Sendable` is safe here ONLY because exactly
/// one task ever calls `next()` on it (mirrors `ManualLANRemoteClock`'s
/// identical controlled-mutable-sharing justification,
/// `LANRemoteTestSupport.swift`).
private final class EventIteratorBox: @unchecked Sendable {
    var iterator: AsyncStream<RemoteControlSession.Event>.AsyncIterator
    init(_ iterator: AsyncStream<RemoteControlSession.Event>.AsyncIterator) {
        self.iterator = iterator
    }
}

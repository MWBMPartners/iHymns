// LiveSyncConfiguration.swift
// IHLive/ServerLive
//
// ELI5: ONE box holding every "how often" number the live engines use, plus
// the fake clock/sleep a test can swap in so nothing here ever has to wait
// on a real wall-clock second.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §3.3). Every literal below is cited to its web/backend source — see the
// spec's cadence table for the full "why this number" reasoning. Injected
// into both engines (defaulted, so every pre-PR-10 call site compiles
// unchanged) and read by `LiveFollowerReducer.nextPollDelay` — the actor
// loops never read `Date()`/call `Task.sleep` directly, only through
// `config.now`/`config.sleep` (discipline restated from PR-8 §3: "no
// wall-clock reads, no real sleeps in anything testable").
import Foundation

/// Injected cadence literals + clock/sleep — see this file's header.
public struct LiveSyncConfiguration: Sendable {
    /// Live Follow's follower poll base — no server-declared interval exists
    /// on this endpoint, so this literal IS the base every tick
    /// (`live-follow.js:25`).
    public var liveFollowPollFallback: Duration = .milliseconds(2500)
    /// Service Mode's poll base ONLY when the join response omitted
    /// `pollIntervalMs` (an un-migrated/older backend) — strategy §2.4.6.
    public var servicePollFallback: Duration = .milliseconds(4000)
    /// The range a server-declared `pollIntervalMs` is clamped into before
    /// use — defensive only (`api.php:14686`'s own values already sit well
    /// inside this).
    public var serverIntervalClamp: ClosedRange<Int> = 1000...60000
    /// The minimum cadence while the following screen isn't frontmost, or
    /// the session has gone stale — strategy §2.4.6, "15-30 s idle," bottom
    /// of the band.
    public var idleFloor: Duration = .seconds(15)
    /// The host's heartbeat interval — `live-follow.js:26`; 6x inside the
    /// 180 s freshness window (`service_mode.php:36-45`).
    public var heartbeatInterval: Duration = .seconds(30)
    /// The injected clock — tests supply a stepped fake sequence; every
    /// non-test caller uses the real `Date()`.
    public var now: @Sendable () -> Date = { Date() }
    /// The injected sleep — tests supply a recording no-op that returns
    /// immediately (cancellation-cooperative); every non-test caller uses a
    /// real `Task.sleep(for:)`.
    public var sleep: @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) }

    public init(
        liveFollowPollFallback: Duration = .milliseconds(2500),
        servicePollFallback: Duration = .milliseconds(4000),
        serverIntervalClamp: ClosedRange<Int> = 1000...60000,
        idleFloor: Duration = .seconds(15),
        heartbeatInterval: Duration = .seconds(30),
        now: @escaping @Sendable () -> Date = { Date() },
        sleep: @escaping @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) }
    ) {
        self.liveFollowPollFallback = liveFollowPollFallback
        self.servicePollFallback = servicePollFallback
        self.serverIntervalClamp = serverIntervalClamp
        self.idleFloor = idleFloor
        self.heartbeatInterval = heartbeatInterval
        self.now = now
        self.sleep = sleep
    }
}

extension Duration {
    /// This duration expressed as whole milliseconds — the ONE conversion
    /// helper `LiveFollowerReducer`/both engines share (rather than each
    /// re-deriving `components.seconds * 1000 + ...`).
    var milliseconds: Int {
        let parts = components
        let wholeMs = Double(parts.seconds) * 1_000
        let fractionalMs = Double(parts.attoseconds) / 1_000_000_000_000_000
        return Int((wholeMs + fractionalMs).rounded())
    }
}

// RemoteLinkPolicy.swift
// IHLive/LANRemote
//
// ELI5: Two little rulebooks for keeping a remote's connection to a TV
// healthy: one says "ping every 5 seconds, and if 3 pings in a row go
// unanswered, the link is dead"; the other says "when the link dies, wait a
// bit before trying again, a bit longer each time, up to a cap, with some
// randomness so a room full of remotes doesn't all redial in lockstep."
// Neither one touches the network, a clock, or a random-number generator —
// they're handed everything they need, which is what makes them trivial to
// test at millisecond speed instead of real seconds.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §3.2/§3.3 — BINDING
// shapes). Mirrors PR-6's `LANRemotePairingCeremonyState` posture
// (`LANRemotePairingCeremony.swift`'s header: "a plain struct... every input
// is handed to it") and the repo-wide injected-clock/injected-RNG seam
// (`LiveFollowEngine.isFresh(lastUpdatedAt:now:)`, strategy §3.3). The ONLY
// place either machine's numbers actually cost real time is the session
// actor's driver loop (`RemoteControlSession+Link.swift`), which sleeps for
// exactly the intervals these structs report and supplies the one piece of
// entropy the backoff machine needs (`unitRandom`) from a real
// `Double.random(in: 0..<1)` in production, a literal in tests.
import Foundation

/// The "ping every N seconds, 3 misses = dead" link-health machine (spec
/// §3.2) — tick-counted, not `Date`-based, so no clock object is needed at
/// all: the DRIVER's own sleep cadence between calls to `onTick()` IS the
/// time source.
///
/// ELI5: "Did the last few pings get answered? If not enough of them did,
/// the connection is probably dead."
public struct RemoteLinkHealthState: Sendable, Equatable {
    public struct Configuration: Sendable, Equatable {
        /// Strategy §2.4.4's "`ping`(5s)" — the DRIVER sleeps this long
        /// between ticks.
        public var pingInterval: Duration
        /// Ticks that may elapse with no pong before the link is declared
        /// dead.
        public var maxMissedPongs: Int

        public init(pingInterval: Duration = .seconds(5), maxMissedPongs: Int = 3) {
            self.pingInterval = pingInterval
            self.maxMissedPongs = maxMissedPongs
        }
    }

    /// What one timer tick means the driver should do.
    public enum TickOutcome: Sendable, Equatable {
        /// Send a `.ping` — the link is still within its miss budget.
        case sendPing
        /// `maxMissedPongs` consecutive pings went unanswered — the link is
        /// dead; the driver disconnects and starts the reconnect ladder.
        case declareDetached
    }

    public private(set) var pingsAwaitingPong: Int = 0
    public let configuration: Configuration

    public init(configuration: Configuration = .init()) {
        self.configuration = configuration
    }

    /// One timer tick. If the miss budget is ALREADY exhausted
    /// (`pingsAwaitingPong >= maxMissedPongs` — i.e. `maxMissedPongs`
    /// consecutive pings already went unanswered), the link is dead; else a
    /// fresh ping goes out and the miss counter advances by one (assuming
    /// nothing answers it before the next tick).
    ///
    /// ELI5: "Time to check in again — has the other side gone quiet?"
    public mutating func onTick() -> TickOutcome {
        guard pingsAwaitingPong < configuration.maxMissedPongs else {
            return .declareDetached
        }
        pingsAwaitingPong += 1
        return .sendPing
    }

    /// A `.pong` arrived — ANY pong proves liveness, so this resets the miss
    /// counter to zero regardless of how many pings were outstanding.
    ///
    /// ELI5: "Good, they answered — the link is alive, reset the worry
    /// counter."
    public mutating func onPong() {
        pingsAwaitingPong = 0
    }

    /// Fresh connection — same as a brand-new `RemoteLinkHealthState`, kept
    /// as an explicit method so the driver never has to reconstruct one from
    /// scratch just to reset it.
    public mutating func reset() {
        pingsAwaitingPong = 0
    }
}

/// The jittered exponential-backoff reconnect machine (spec §3.3) — same
/// no-clock/no-RNG purity as the health machine above; `nextDelay(unitRandom:)`
/// takes its ONE random input from the caller instead of drawing it itself.
///
/// ELI5: "How long should I wait before trying to reconnect this time?" —
/// waits a bit longer each failed attempt (up to a cap), with a little
/// randomness mixed in so a whole room of remotes doesn't all redial at
/// exactly the same instant.
public struct RemoteReconnectBackoffState: Sendable, Equatable {
    public struct Configuration: Sendable, Equatable {
        public var baseDelay: TimeInterval
        public var multiplier: Double
        public var maxDelay: TimeInterval
        /// `±20%` by default — see `nextDelay(unitRandom:)`'s doc comment
        /// for the exact jitter formula.
        public var jitterFraction: Double

        public init(baseDelay: TimeInterval = 1, multiplier: Double = 2, maxDelay: TimeInterval = 30, jitterFraction: Double = 0.2) {
            self.baseDelay = baseDelay
            self.multiplier = multiplier
            self.maxDelay = maxDelay
            self.jitterFraction = jitterFraction
        }
    }

    public private(set) var attempt: Int = 0
    public let configuration: Configuration

    public init(configuration: Configuration = .init()) {
        self.configuration = configuration
    }

    /// Computes the delay for the CURRENT attempt, then advances `attempt`
    /// for next time.
    ///
    /// `delay = min(maxDelay, baseDelay × multiplier^attempt) × (1 − j + 2j·unitRandom)`
    /// — with `unitRandom ∈ [0, 1)`, the multiplier ranges from `(1 − j)` to
    /// `(1 + j)`; `unitRandom == 0.5` reproduces the nominal (unjittered)
    /// delay exactly.
    ///
    /// - Parameter unitRandom: A value in `[0, 1)` — production supplies one
    ///   real `Double.random(in: 0..<1)` draw per call at the driver's call
    ///   site; tests supply literals for exact, reproducible sequences.
    ///
    /// ELI5: "Work out this attempt's wait time (a bit random so we don't
    /// collide with every other remote), then remember we've tried one more
    /// time."
    public mutating func nextDelay(unitRandom: Double) -> TimeInterval {
        let nominal = min(configuration.maxDelay, configuration.baseDelay * pow(configuration.multiplier, Double(attempt)))
        let jitterMultiplier = 1 - configuration.jitterFraction + (2 * configuration.jitterFraction * unitRandom)
        attempt += 1
        return nominal * jitterMultiplier
    }

    /// Back to attempt 0 — called the instant a reconnect actually succeeds
    /// (`RemoteControlSession+Link.swift`'s first `.controlling` arrival
    /// after a detach).
    public mutating func reset() {
        attempt = 0
    }
}

// HeadlessRelayPolicy.swift
// IHLive/WatchRelay
//
// ELI5: The little rulebook for "should we start connecting to the TV right
// now, and when should we hang up again" — completely on paper, with no
// real clock, no real network, no real session. Something ELSE (the driver)
// reads this rulebook's instructions and actually does the connecting/
// hanging-up; this file only ever decides WHAT should happen and WHEN.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §3.5 —
// BINDING transition table). Every timestamp is an explicit `Date` argument
// — never `Date()`, never a real `Task.sleep` — so `HeadlessRelayPolicyTests`
// can drive the WHOLE wake/connect/linger machine with literal dates and no
// waiting at all, the same injected-clock discipline `LANRemoteClock.swift`
// establishes for the rest of `IHLive`. `HeadlessRelayDriver` (`IHFeatures`)
// is the ONLY consumer: it owns the real session/clock/tasks and turns each
// returned `Effect` into an actual action; this type never touches any of
// those itself.
import Foundation

/// The pure wake/connect/linger state machine — see this file's header.
public struct HeadlessRelayPolicy: Sendable, Equatable {

    /// Tunables — every interval an injected value, mirroring
    /// `RemoteControlSession.Configuration`'s identical discipline so tests
    /// can run the whole machine in milliseconds.
    public struct Configuration: Sendable, Equatable {
        /// How long a wake-connect may take before every pending command
        /// fails `.couldNotReachTV` — well inside any WCSession reply
        /// window and the background-task budget.
        public var connectTimeout: Duration
        /// How long a live headless session survives after the LAST watch
        /// command before a clean `endControl` + disconnect — inside the
        /// ~30 s background-task budget with margin; consecutive taps
        /// inside it reuse the live link.
        public var idleDisconnect: Duration
        /// Bounded in-flight buffer while connecting (revised D-8): rapid
        /// taps during the sub-second reconnect ride along IN ORDER, never
        /// more than this many.
        public var maxPending: Int

        public init(connectTimeout: Duration = .seconds(4), idleDisconnect: Duration = .seconds(20), maxPending: Int = 4) {
            self.connectTimeout = connectTimeout
            self.idleDisconnect = idleDisconnect
            self.maxPending = maxPending
        }
    }

    /// One watch command waiting for its own wake-connect to resolve.
    public struct PendingCommand: Sendable, Equatable {
        public let id: UUID
        public let command: WatchRelayCommand

        public init(id: UUID = UUID(), command: WatchRelayCommand) {
            self.id = id
            self.command = command
        }
    }

    public enum State: Sendable, Equatable {
        case idle
        /// Pending commands ride alongside in `pending`, not in this case's
        /// own associated value.
        case connecting(deadline: Date)
        case connected(idleDeadline: Date)
        /// Teardown in flight — awaited by `retire()`/a takeover hook.
        case retiring
    }

    public enum Event: Sendable, Equatable {
        case commandAccepted(PendingCommand, now: Date)
        /// The first `.controlling` arrived for this burst.
        case sessionControlling(now: Date)
        /// Terminal: torn down / ended / detached / a cancelled ceremony.
        case sessionFailed
        /// The driver's own scheduled deadline check fired.
        case tick(now: Date)
        case retireRequested
        case teardownComplete
    }

    public enum Effect: Sendable, Equatable {
        /// Build a session + attach — the driver supplies the target.
        case startConnect
        /// Send these, in order, on the live session.
        case forward([PendingCommand])
        case failPending([UUID], WatchRelayReply.Reason)
        case scheduleTick(deadline: Date)
        /// Clean `endControl` + `stop()`.
        case disconnect
        case none
    }

    private let configuration: Configuration
    public private(set) var state: State
    public private(set) var pending: [PendingCommand]

    public init(configuration: Configuration = .init()) {
        self.configuration = configuration
        self.state = .idle
        self.pending = []
    }

    /// The whole transition table (BINDING — `HeadlessRelayPolicyTests`
    /// asserts every row with literal `Date`s). Ticks that fire EARLY (a
    /// stale scheduled tick after the deadline moved) are no-ops — the
    /// state carries the authoritative deadline, this compares against it
    /// rather than trusting the tick's own timing. Every effect is data;
    /// this type never touches a session, a clock, or a task itself.
    ///
    /// Dispatches to one per-state helper below purely to keep each
    /// function's own branching shallow (SwiftLint's `cyclomatic_complexity`
    /// budget) — the four helpers TOGETHER are this one BINDING table, just
    /// split by `state` instead of written as one flat `(state, event)`
    /// tuple switch.
    ///
    /// ELI5: "Given what just happened, what should we do next — and what's
    /// the new situation?"
    public mutating func handle(_ event: Event) -> [Effect] {
        switch state {
        case .idle:
            return handleIdle(event)
        case .connecting(let deadline):
            return handleConnecting(deadline: deadline, event: event)
        case .connected(let idleDeadline):
            return handleConnected(idleDeadline: idleDeadline, event: event)
        case .retiring:
            return handleRetiring(event)
        }
    }

    private mutating func handleIdle(_ event: Event) -> [Effect] {
        switch event {
        case .commandAccepted(let command, let now):
            let deadline = addDuration(configuration.connectTimeout, to: now)
            pending = [command]
            state = .connecting(deadline: deadline)
            return [.startConnect, .scheduleTick(deadline: deadline)]
        case .retireRequested, .tick, .sessionFailed, .sessionControlling, .teardownComplete:
            // Stray events in `.idle` are no-ops, never traps.
            return []
        }
    }

    private mutating func handleConnecting(deadline: Date, event: Event) -> [Effect] {
        switch event {
        case .commandAccepted(let command, _):
            guard pending.count < configuration.maxPending else {
                return [.failPending([command.id], .couldNotReachTV)]
            }
            pending.append(command)
            return []
        case .sessionControlling(let now):
            let flushed = pending
            pending = []
            let idleDeadline = addDuration(configuration.idleDisconnect, to: now)
            state = .connected(idleDeadline: idleDeadline)
            return [.forward(flushed), .scheduleTick(deadline: idleDeadline)]
        case .tick(let now):
            guard now >= deadline else { return [] }
            return failAllPendingAndDisconnect(reason: .couldNotReachTV)
        case .sessionFailed, .retireRequested:
            return failAllPendingAndDisconnect(reason: .couldNotReachTV)
        case .teardownComplete:
            return []
        }
    }

    private mutating func handleConnected(idleDeadline: Date, event: Event) -> [Effect] {
        switch event {
        case .commandAccepted(let command, let now):
            let newDeadline = addDuration(configuration.idleDisconnect, to: now)
            state = .connected(idleDeadline: newDeadline)
            return [.forward([command]), .scheduleTick(deadline: newDeadline)]
        case .tick(let now):
            guard now >= idleDeadline else { return [] }
            state = .retiring
            return [.disconnect]
        case .sessionFailed:
            // No `failPending` — nothing is ever buffered while `.connected`
            // (commands forward immediately), unlike the generic
            // `.retireRequested` row below.
            state = .retiring
            return [.disconnect]
        case .retireRequested:
            return failAllPendingAndDisconnect(reason: .couldNotReachTV)
        case .sessionControlling, .teardownComplete:
            return []
        }
    }

    private mutating func handleRetiring(_ event: Event) -> [Effect] {
        switch event {
        case .teardownComplete:
            state = .idle
            return [.none]
        case .retireRequested:
            // "Any non-idle state + retireRequested" applies to `.retiring`
            // itself too (already-empty `pending`, so this is effectively
            // a defensive re-issued disconnect) — kept consistent with the
            // generic rule rather than special-cased away.
            return failAllPendingAndDisconnect(reason: .couldNotReachTV)
        case .commandAccepted, .sessionControlling, .sessionFailed, .tick:
            // Unreachable in practice — `HeadlessRelayDriver.perform` awaits
            // teardown completion BEFORE ever calling `handle` again — but a
            // stray event here is still a no-op, never a trap.
            return []
        }
    }

    /// Shared by the three "fail everything pending, then disconnect" rows
    /// (`.connecting` deadline, `.connecting` `.sessionFailed`, and any
    /// non-idle `.retireRequested`) — resets `pending`/`state` identically
    /// in all three so they can never drift apart. `reason` is always
    /// `.couldNotReachTV` from the pure policy's own perspective; the
    /// DRIVER substitutes `.repairNeeded` on the specific ceremony-cancel
    /// case by overriding the resumed continuations' reason itself before
    /// this effect's ids are ever surfaced to the watch (spec §4.2 — the
    /// policy has no notion of "why," only "everything failed").
    private mutating func failAllPendingAndDisconnect(reason: WatchRelayReply.Reason) -> [Effect] {
        let ids = pending.map(\.id)
        pending = []
        state = .retiring
        return [.failPending(ids, reason), .disconnect]
    }
}

/// `Duration` has no built-in `TimeInterval`/`Date` bridge in the standard
/// library — this walks `.components` (seconds + attoseconds) the same way
/// `Duration.milliseconds` (`LiveSyncConfiguration.swift`, this same
/// `IHLive` target) does for its own distinct need, so this policy's
/// literal-`Date` transition table can add an injected `Duration` tunable to
/// an injected `Date` with no wall-clock read anywhere in this file.
private func addDuration(_ duration: Duration, to date: Date) -> Date {
    let seconds = Double(duration.components.seconds) + Double(duration.components.attoseconds) / 1_000_000_000_000_000_000
    return date.addingTimeInterval(seconds)
}

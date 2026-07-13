// LiveFollowerReducer.swift
// IHLive/ServerLive
//
// ELI5: The ONE set of rules both "follow a leader's code" and "follow a
// service" use to decide "did anything actually change?", "has this gone
// quiet?", and "how long should I wait before checking again?" — written
// once so both features can never quietly drift apart on this logic.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §3.3, D-3 binding). Pure — no `Date()` reads, no sleeps; every time-driven
// decision is a function of an injected `Date`/`LiveSyncConfiguration`. Both
// `LiveFollowEngine`(follower half) and `ServiceModeEngine` call ONLY this
// type; a second copy of this dedup/freshness/cadence maths anywhere
// (including a future PR-15 tvOS projector) is the same `_bsls_*`-style
// regression class the web rule #22 calls out, applied to Swift (D-3).
import Foundation
import IHAPI
import IHModels

/// The follower spine's own state — meaningful for BOTH a Live Follow
/// follower and a Service Mode congregant.
public struct LiveFollowerState: Sendable, Equatable {
    /// The monotonic high-water revision — the server's `StateRevision`/
    /// `stateVersion` counter. A response carrying a lower-or-equal revision
    /// NEVER rewinds this (out-of-order/duplicate responses are ignored).
    public var revision: Int
    /// The last SUCCESSFUL poll/join response's arrival time — `nil` before
    /// the very first contact.
    public var lastContactAt: Date?
    /// Consecutive poll failures since the last success — drives the
    /// bounded backoff row of the cadence table; reset to `0` on any
    /// success (even an "unchanged" one).
    public var consecutiveFailures: Int
    /// Whether the following screen is currently frontmost — `false` = the
    /// idle-floor cadence row applies.
    public var isIdle: Bool
    /// The most recently APPLIED (changed:true) snapshot, if any.
    public var latest: LiveBroadcastSnapshot?

    public init(
        revision: Int = 0,
        lastContactAt: Date? = nil,
        consecutiveFailures: Int = 0,
        isIdle: Bool = false,
        latest: LiveBroadcastSnapshot? = nil
    ) {
        self.revision = revision
        self.lastContactAt = lastContactAt
        self.consecutiveFailures = consecutiveFailures
        self.isIdle = isIdle
        self.latest = latest
    }
}

/// What a poll response means the caller should DO — the engine translates
/// this into an event (or a teardown), never interpreting `LivePollUpdate`
/// itself.
public enum LiveFollowerEffect: Sendable, Equatable {
    /// `changed == true` and the revision genuinely advanced — the caller
    /// should apply `snapshot` and emit it.
    case apply(LiveBroadcastSnapshot)
    /// `active == false` — a positive end signal; the caller should leave/
    /// clear custody.
    case sessionEnded
    /// Unchanged, or a duplicate/lower revision — nothing to do.
    case none
}

/// The shared pure spine — see this file's header. D-3 binding: the ONE
/// place revision-dedup/freshness/cadence logic lives.
public enum LiveFollowerReducer {

    /// Applies one poll response. `revision <= state.revision` NEVER
    /// applies (an out-of-order/duplicate response must not rewind the
    /// display). `active == false` ends the session regardless of revision.
    /// A successful contact (any outcome except a thrown error — handled by
    /// `applyFailure` instead) always refreshes `lastContactAt` and resets
    /// `consecutiveFailures`.
    @discardableResult
    public static func applyPoll(_ update: LivePollUpdate, state: inout LiveFollowerState, now: Date) -> LiveFollowerEffect {
        if update.active == false {
            return .sessionEnded
        }

        state.lastContactAt = now
        state.consecutiveFailures = 0

        guard let newRevision = update.revision, newRevision > state.revision else {
            return .none
        }
        state.revision = newRevision

        guard update.changed == true else {
            return .none
        }

        let snapshot = LiveBroadcastSnapshot(
            songId: update.currentSongId,
            componentIndex: update.componentIndex,
            state: update.state,
            revision: newRevision
        )
        state.latest = snapshot
        return .apply(snapshot)
    }

    /// The poll itself THREW (network/5xx/decode). Pure — bumps
    /// `consecutiveFailures`, never touches `revision`/`lastContactAt`.
    /// ALWAYS a `.none`-shaped outcome: a transient failure is NEVER an end
    /// signal (D-11, web parity `live-follow.js:330-331` — "keep the
    /// session, retry next tick"); only `active == false` (a genuine server
    /// positive) or the 180 s freshness lapse (surfaced separately via
    /// `isFresh`) are visible to the user.
    public static func applyFailure(state: inout LiveFollowerState, now: Date) {
        state.consecutiveFailures += 1
    }

    /// Freshness — delegates to the EXISTING `LiveFollowEngine.isFresh` seam
    /// (180 s, strict `<`), never re-derived. `false` (not fresh) when there
    /// has been no successful contact yet.
    public static func isFresh(_ state: LiveFollowerState, now: Date) -> Bool {
        guard let lastContactAt = state.lastContactAt else { return false }
        return LiveFollowEngine.isFresh(lastUpdatedAt: lastContactAt, now: now)
    }

    /// The cadence table (spec §3.3, binding — every number cited there).
    /// `serverIntervalMs` is the CALLER's already-resolved base: Service
    /// Mode passes the join response's own `pollIntervalMs` (or `nil` if the
    /// backend omitted it, in which case `config.servicePollFallback`
    /// applies); Live Follow's follower loop passes its OWN 2500 ms fallback
    /// AS the "declared" value (that endpoint has no server field at all),
    /// so this one function serves both without needing to know which
    /// feature is calling.
    public static func nextPollDelay(_ state: LiveFollowerState, serverIntervalMs: Int?, config: LiveSyncConfiguration) -> Duration {
        let baseMs: Int
        if let serverIntervalMs {
            baseMs = min(max(serverIntervalMs, config.serverIntervalClamp.lowerBound), config.serverIntervalClamp.upperBound)
        } else {
            baseMs = config.servicePollFallback.milliseconds
        }

        var delayMs = baseMs
        if state.consecutiveFailures > 0 {
            let power = min(state.consecutiveFailures, 3)
            let backoffMs = baseMs * Int(pow(2.0, Double(power)))
            delayMs = min(backoffMs, config.idleFloor.milliseconds)
        }

        var delay = Duration.milliseconds(delayMs)
        if state.isIdle || !isFresh(state, now: config.now()) {
            delay = max(delay, config.idleFloor)
        }
        return delay
    }
}

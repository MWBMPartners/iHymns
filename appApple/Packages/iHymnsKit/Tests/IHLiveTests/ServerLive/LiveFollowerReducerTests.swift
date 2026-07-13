// LiveFollowerReducerTests.swift
// IHLiveTests/ServerLive
//
// ELI5: Exhaustively checks the ONE shared "did anything change, has this
// gone quiet, how long should I wait" rulebook both Live Follow and Service
// Mode use — every clock value here is made up, never real wall-clock time.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §7.2). Zero wall-clock: every `Date`/`Duration` is a literal; the cadence
// table tests inject `LiveSyncConfiguration.now`.
import Foundation
import IHModels
import Testing
@testable import IHAPI
@testable import IHLive

@Suite("LiveFollowerReducer.applyPoll")
struct LiveFollowerReducerApplyPollTests {
    private static let epoch = Date(timeIntervalSince1970: 0)

    private func update(active: Bool? = nil, changed: Bool? = nil, songId: String? = nil, index: Int? = nil, revision: Int?) -> LivePollUpdate {
        LivePollUpdate(active: active, changed: changed, currentSongId: songId, componentIndex: index, state: nil, revision: revision)
    }

    @Test("A duplicate (equal) revision never re-applies")
    func equalRevisionNeverReapplies() {
        var state = LiveFollowerState(revision: 5)
        let effect = LiveFollowerReducer.applyPoll(update(changed: true, revision: 5), state: &state, now: Self.epoch)
        #expect(effect == .none)
        #expect(state.revision == 5)
    }

    @Test("A lower (out-of-order/duplicate) revision never rewinds the display")
    func lowerRevisionNeverRewinds() {
        var state = LiveFollowerState(revision: 10)
        let effect = LiveFollowerReducer.applyPoll(update(changed: true, revision: 3), state: &state, now: Self.epoch)
        #expect(effect == .none)
        #expect(state.revision == 10)
    }

    @Test("changed:true with a higher revision applies and advances state")
    func changedTrueHigherRevisionApplies() {
        var state = LiveFollowerState(revision: 1)
        let effect = LiveFollowerReducer.applyPoll(update(changed: true, songId: "MP-0031", index: 2, revision: 4), state: &state, now: Self.epoch)
        #expect(effect == .apply(LiveBroadcastSnapshot(songId: "MP-0031", componentIndex: 2, state: nil, revision: 4)))
        #expect(state.revision == 4)
        #expect(state.latest?.songId == "MP-0031")
    }

    @Test("active:false ends the session regardless of revision")
    func activeFalseEndsRegardlessOfRevision() {
        var state = LiveFollowerState(revision: 99)
        let effect = LiveFollowerReducer.applyPoll(update(active: false, revision: 1), state: &state, now: Self.epoch)
        #expect(effect == .sessionEnded)
    }

    @Test("active:nil, changed:false — unchanged, but lastContactAt refreshes")
    func activeNilChangedFalseRefreshesContact() {
        var state = LiveFollowerState(revision: 2, lastContactAt: Self.epoch)
        let now = Self.epoch.addingTimeInterval(60)
        let effect = LiveFollowerReducer.applyPoll(update(changed: false, revision: 2), state: &state, now: now)
        #expect(effect == .none)
        #expect(state.lastContactAt == now)
        #expect(state.consecutiveFailures == 0)
    }

    @Test("A response with no revision field at all is a no-op, contact still refreshes")
    func missingRevisionIsANoOp() {
        var state = LiveFollowerState(revision: 2, lastContactAt: nil)
        let effect = LiveFollowerReducer.applyPoll(update(revision: nil), state: &state, now: Self.epoch)
        #expect(effect == .none)
        #expect(state.revision == 2)
        #expect(state.lastContactAt == Self.epoch)
    }

    @Test("A success resets consecutiveFailures back to 0")
    func successResetsFailureCount() {
        var state = LiveFollowerState(revision: 1, consecutiveFailures: 3)
        _ = LiveFollowerReducer.applyPoll(update(changed: false, revision: 1), state: &state, now: Self.epoch)
        #expect(state.consecutiveFailures == 0)
    }
}

@Suite("LiveFollowerReducer.applyFailure")
struct LiveFollowerReducerApplyFailureTests {
    private static let epoch = Date(timeIntervalSince1970: 0)

    @Test("A failure never touches lastContactAt or revision, only bumps the counter")
    func failureNeverTouchesContactOrRevision() {
        var state = LiveFollowerState(revision: 7, lastContactAt: Self.epoch, consecutiveFailures: 0)
        LiveFollowerReducer.applyFailure(state: &state, now: Self.epoch.addingTimeInterval(500))
        #expect(state.revision == 7)
        #expect(state.lastContactAt == Self.epoch)
        #expect(state.consecutiveFailures == 1)
    }

    @Test("Repeated failures keep incrementing")
    func repeatedFailuresIncrement() {
        var state = LiveFollowerState()
        for _ in 0..<4 {
            LiveFollowerReducer.applyFailure(state: &state, now: Self.epoch)
        }
        #expect(state.consecutiveFailures == 4)
    }
}

@Suite("LiveFollowerReducer.isFresh")
struct LiveFollowerReducerIsFreshTests {
    private static let epoch = Date(timeIntervalSince1970: 0)

    @Test("No contact yet is never fresh")
    func noContactIsNeverFresh() {
        #expect(!LiveFollowerReducer.isFresh(LiveFollowerState(), now: Self.epoch))
    }

    @Test("Fresh just under the 180s boundary")
    func freshJustUnderBoundary() {
        let state = LiveFollowerState(lastContactAt: Self.epoch)
        #expect(LiveFollowerReducer.isFresh(state, now: Self.epoch.addingTimeInterval(179)))
    }

    @Test("Stale at exactly the 180s boundary (strict <, matches LiveFollowEngine.isFresh unchanged)")
    func staleAtExactBoundary() {
        let state = LiveFollowerState(lastContactAt: Self.epoch)
        #expect(!LiveFollowerReducer.isFresh(state, now: Self.epoch.addingTimeInterval(180)))
    }
}

@Suite("LiveFollowerReducer.nextPollDelay — the cadence table (spec §3.3, binding)")
struct LiveFollowerReducerCadenceTests {
    private static let epoch = Date(timeIntervalSince1970: 0)

    private func config(now: Date = epoch) -> LiveSyncConfiguration {
        LiveSyncConfiguration(now: { now })
    }

    @Test("Server-declared interval wins, clamped to the configured range")
    func serverDeclaredIntervalWins() {
        let fresh = LiveFollowerState(lastContactAt: Self.epoch)
        let delay = LiveFollowerReducer.nextPollDelay(fresh, serverIntervalMs: 5000, config: config())
        #expect(delay == .milliseconds(5000))
    }

    @Test("A server interval below the clamp floor is raised to 1000ms")
    func clampsBelowFloor() {
        let fresh = LiveFollowerState(lastContactAt: Self.epoch)
        let delay = LiveFollowerReducer.nextPollDelay(fresh, serverIntervalMs: 999, config: config())
        #expect(delay == .milliseconds(1000))
    }

    @Test("A server interval above the clamp ceiling is lowered to 60000ms")
    func clampsAboveCeiling() {
        let fresh = LiveFollowerState(lastContactAt: Self.epoch)
        let delay = LiveFollowerReducer.nextPollDelay(fresh, serverIntervalMs: 60_001, config: config())
        #expect(delay == .milliseconds(60_000))
    }

    @Test("Absent server interval falls back to the Service Mode 4000ms literal")
    func absentIntervalFallsBackToServiceDefault() {
        let fresh = LiveFollowerState(lastContactAt: Self.epoch)
        let delay = LiveFollowerReducer.nextPollDelay(fresh, serverIntervalMs: nil, config: config())
        #expect(delay == .milliseconds(4000))
    }

    @Test("isIdle floors the delay at 15s even for a fast base")
    func idleFloorsDelay() {
        var state = LiveFollowerState(lastContactAt: Self.epoch)
        state.isIdle = true
        let delay = LiveFollowerReducer.nextPollDelay(state, serverIntervalMs: 2500, config: config())
        #expect(delay == .seconds(15))
    }

    @Test("A stale (not fresh) follower floors the delay at 15s even while active")
    func staleFloorsDelay() {
        let state = LiveFollowerState(lastContactAt: Self.epoch)
        let farFuture = Self.epoch.addingTimeInterval(500)
        let delay = LiveFollowerReducer.nextPollDelay(state, serverIntervalMs: 2500, config: config(now: farFuture))
        #expect(delay == .seconds(15))
    }

    @Test("Idle floor does not LOWER an already-longer server-declared interval")
    func idleFloorNeverLowersALongerBase() {
        var state = LiveFollowerState(lastContactAt: Self.epoch)
        state.isIdle = true
        let delay = LiveFollowerReducer.nextPollDelay(state, serverIntervalMs: 30_000, config: config())
        #expect(delay == .milliseconds(30_000))
    }

    @Test("Failure backoff doubles per attempt, capped at 15s")
    func failureBackoffDoublesAndCaps() {
        let fresh1 = LiveFollowerState(lastContactAt: Self.epoch, consecutiveFailures: 1)
        #expect(LiveFollowerReducer.nextPollDelay(fresh1, serverIntervalMs: 2500, config: config()) == .milliseconds(5000))

        let fresh2 = LiveFollowerState(lastContactAt: Self.epoch, consecutiveFailures: 2)
        #expect(LiveFollowerReducer.nextPollDelay(fresh2, serverIntervalMs: 2500, config: config()) == .milliseconds(10_000))

        // 2500 * 2^3 = 20000, capped to the 15s idle-floor ceiling.
        let fresh3 = LiveFollowerState(lastContactAt: Self.epoch, consecutiveFailures: 3)
        #expect(LiveFollowerReducer.nextPollDelay(fresh3, serverIntervalMs: 2500, config: config()) == .seconds(15))

        // consecutiveFailures beyond 3 clamps the EXPONENT at 3 (min(n,3)), same 15s cap.
        let fresh4 = LiveFollowerState(lastContactAt: Self.epoch, consecutiveFailures: 9)
        #expect(LiveFollowerReducer.nextPollDelay(fresh4, serverIntervalMs: 2500, config: config()) == .seconds(15))
    }

    @Test("Zero consecutive failures uses the plain base — reset-on-success is the caller's job (applyPoll already zeroes it)")
    func zeroFailuresUsesPlainBase() {
        let state = LiveFollowerState(lastContactAt: Self.epoch, consecutiveFailures: 0)
        #expect(LiveFollowerReducer.nextPollDelay(state, serverIntervalMs: 2500, config: config()) == .milliseconds(2500))
    }
}

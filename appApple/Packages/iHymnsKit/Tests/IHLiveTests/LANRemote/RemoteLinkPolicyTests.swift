// RemoteLinkPolicyTests.swift
// IHLiveTests
//
// ELI5: Checks the two little "is the link OK?" rulebooks — the ping-miss
// counter correctly declares a link dead after 3 misses (and any pong at all
// resets it), and the reconnect-wait calculator produces the right sequence
// of waits, stays within its jitter band, respects the cap, and resets back
// to the start once told to.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §7.2 — BINDING).
// Both machines are pure (no clock, no RNG reads) — `unitRandom` is supplied
// as a plain literal here, exactly the seam the production driver
// (`RemoteControlSession+Link.swift`) fills with a real `Double.random(in:
// 0..<1)` draw. Defaults are asserted LITERALLY so a drive-by "tune" of the
// numbers shows up as a diff in review, per spec §7.2's explicit instruction.
import Foundation
import Testing
@testable import IHLive

@Suite("RemoteLinkHealthState")
struct RemoteLinkHealthStateTests {

    @Test("Defaults are 5s ping interval / 3 missed pongs — asserted literally")
    func defaultsAreTheStrategyNumbers() {
        let configuration = RemoteLinkHealthState.Configuration()
        #expect(configuration.pingInterval == .seconds(5))
        #expect(configuration.maxMissedPongs == 3)
    }

    @Test("3 ticks each send a ping and advance the miss counter; the 4th declares detached")
    func threeTicksSendPingsThenDetach() {
        var state = RemoteLinkHealthState()
        #expect(state.onTick() == .sendPing)
        #expect(state.onTick() == .sendPing)
        #expect(state.onTick() == .sendPing)
        #expect(state.pingsAwaitingPong == 3)

        #expect(state.onTick() == .declareDetached)
    }

    @Test("onPong() resets the miss counter to zero regardless of how many pings were outstanding")
    func onPongResetsCounter() {
        var state = RemoteLinkHealthState()
        _ = state.onTick()
        _ = state.onTick()
        #expect(state.pingsAwaitingPong == 2)

        state.onPong()
        #expect(state.pingsAwaitingPong == 0)

        // The ladder restarts cleanly from zero.
        #expect(state.onTick() == .sendPing)
        #expect(state.onTick() == .sendPing)
        #expect(state.onTick() == .sendPing)
        #expect(state.onTick() == .declareDetached)
    }

    @Test("reset() returns a fresh connection's state")
    func resetReturnsToZero() {
        var state = RemoteLinkHealthState()
        _ = state.onTick()
        _ = state.onTick()
        _ = state.onTick()
        state.reset()
        #expect(state.pingsAwaitingPong == 0)
        #expect(state.onTick() == .sendPing)
    }

    @Test("A custom maxMissedPongs is honoured")
    func customMissBudget() {
        var state = RemoteLinkHealthState(configuration: .init(maxMissedPongs: 1))
        #expect(state.onTick() == .sendPing)
        #expect(state.onTick() == .declareDetached)
    }
}

@Suite("RemoteReconnectBackoffState")
struct RemoteReconnectBackoffStateTests {

    @Test("Defaults are 1s base / ×2 / 30s cap / ±20% jitter — asserted literally")
    func defaultsAreTheStrategyNumbers() {
        let configuration = RemoteReconnectBackoffState.Configuration()
        #expect(configuration.baseDelay == 1)
        #expect(configuration.multiplier == 2)
        #expect(configuration.maxDelay == 30)
        #expect(configuration.jitterFraction == 0.2)
    }

    @Test("With unitRandom 0.5 (no jitter offset) the sequence is 1, 2, 4, 8, 16, 30, 30")
    func nominalSequenceAtHalfUnitRandom() {
        var state = RemoteReconnectBackoffState()
        let expected: [TimeInterval] = [1, 2, 4, 8, 16, 30, 30]
        for expectedDelay in expected {
            let delay = state.nextDelay(unitRandom: 0.5)
            #expect(abs(delay - expectedDelay) < 0.0001)
        }
    }

    @Test("Every delay stays within ±20% of nominal across the full unitRandom range")
    func everyDelayStaysWithinJitterBounds() {
        var lowState = RemoteReconnectBackoffState()
        var highState = RemoteReconnectBackoffState()
        let nominals: [TimeInterval] = [1, 2, 4, 8, 16, 30, 30]

        for nominal in nominals {
            let low = lowState.nextDelay(unitRandom: 0.0)
            let high = highState.nextDelay(unitRandom: 1.0)
            #expect(abs(low - nominal * 0.8) < 0.0001)
            #expect(abs(high - nominal * 1.2) < 0.0001)
        }
    }

    @Test("reset() returns to attempt 0")
    func resetReturnsToAttemptZero() {
        var state = RemoteReconnectBackoffState()
        _ = state.nextDelay(unitRandom: 0.5)
        _ = state.nextDelay(unitRandom: 0.5)
        #expect(state.attempt == 2)

        state.reset()
        #expect(state.attempt == 0)
        let delay = state.nextDelay(unitRandom: 0.5)
        #expect(abs(delay - 1) < 0.0001)
    }

    @Test("A custom configuration's numbers are honoured")
    func customConfiguration() {
        var state = RemoteReconnectBackoffState(configuration: .init(baseDelay: 0.05, multiplier: 2, maxDelay: 0.2, jitterFraction: 0))
        #expect(abs(state.nextDelay(unitRandom: 0.5) - 0.05) < 0.0001)
        #expect(abs(state.nextDelay(unitRandom: 0.5) - 0.1) < 0.0001)
        #expect(abs(state.nextDelay(unitRandom: 0.5) - 0.2) < 0.0001)
        // Capped, doesn't keep growing past maxDelay.
        #expect(abs(state.nextDelay(unitRandom: 0.5) - 0.2) < 0.0001)
    }
}

// HeadlessRelayPolicyTests.swift
// IHLiveTests
//
// ELI5: Proves the "should we connect now, and when should we hang up"
// rulebook gets every single row right, using only made-up timestamps — no
// real waiting, no real network, no real session.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §7.1/§3.5 —
// BINDING transition table, literal `Date`s throughout).
import Foundation
import Testing
@testable import IHLive

@Suite("HeadlessRelayPolicy — pure wake/connect/linger state machine")
struct HeadlessRelayPolicyTests {

    private let epoch = Date(timeIntervalSince1970: 1_700_000_000)
    private let config = HeadlessRelayPolicy.Configuration(connectTimeout: .seconds(4), idleDisconnect: .seconds(20), maxPending: 4)

    private func newCommand() -> HeadlessRelayPolicy.PendingCommand {
        .init(command: .nextComponent)
    }

    // MARK: - idle -> connecting

    @Test("idle + commandAccepted -> connecting, [startConnect, scheduleTick(deadline)]")
    func idleToConnecting() {
        var policy = HeadlessRelayPolicy(configuration: config)
        let cmd = newCommand()
        let effects = policy.handle(.commandAccepted(cmd, now: epoch))
        let deadline = epoch.addingTimeInterval(4)
        #expect(effects == [.startConnect, .scheduleTick(deadline: deadline)])
        #expect(policy.state == .connecting(deadline: deadline))
        #expect(policy.pending == [cmd])
    }

    // MARK: - connecting buffering + the maxPending overflow row

    @Test("connecting + commandAccepted under the cap buffers in order with NO effects")
    func connectingBuffersUnderCap() {
        var policy = HeadlessRelayPolicy(configuration: config)
        let first = newCommand()
        _ = policy.handle(.commandAccepted(first, now: epoch))
        let second = newCommand()
        let effects = policy.handle(.commandAccepted(second, now: epoch.addingTimeInterval(1)))
        #expect(effects.isEmpty)
        #expect(policy.pending == [first, second])
    }

    @Test("connecting + commandAccepted beyond maxPending fails ONLY the overflowing command")
    func connectingOverflowFailsOnlyTheNewCommand() {
        let tightConfig = HeadlessRelayPolicy.Configuration(connectTimeout: .seconds(4), idleDisconnect: .seconds(20), maxPending: 2)
        var policy = HeadlessRelayPolicy(configuration: tightConfig)
        let commands = [newCommand(), newCommand(), newCommand()]
        _ = policy.handle(.commandAccepted(commands[0], now: epoch))
        _ = policy.handle(.commandAccepted(commands[1], now: epoch))
        let effects = policy.handle(.commandAccepted(commands[2], now: epoch))
        #expect(effects == [.failPending([commands[2].id], .couldNotReachTV)])
        #expect(policy.pending == [commands[0], commands[1]])
    }

    // MARK: - connecting -> connected (order matters: forward before scheduleTick)

    @Test("connecting + sessionControlling flushes pending IN ORDER — forward before scheduleTick — and clears pending")
    func connectingToConnectedFlushesInOrder() {
        var policy = HeadlessRelayPolicy(configuration: config)
        let first = newCommand()
        let second = newCommand()
        _ = policy.handle(.commandAccepted(first, now: epoch))
        _ = policy.handle(.commandAccepted(second, now: epoch))
        let now = epoch.addingTimeInterval(1)
        let effects = policy.handle(.sessionControlling(now: now))
        let idleDeadline = now.addingTimeInterval(20)
        #expect(effects == [.forward([first, second]), .scheduleTick(deadline: idleDeadline)])
        #expect(policy.pending.isEmpty)
        #expect(policy.state == .connected(idleDeadline: idleDeadline))
    }

    // MARK: - connecting deadline / stale-tick / sessionFailed

    @Test("connecting + a tick BEFORE the deadline is a no-op — a stale early tick changes nothing")
    func connectingEarlyTickIsNoOp() {
        var policy = HeadlessRelayPolicy(configuration: config)
        _ = policy.handle(.commandAccepted(newCommand(), now: epoch))
        let effects = policy.handle(.tick(now: epoch.addingTimeInterval(1)))
        #expect(effects.isEmpty)
        #expect(policy.state == .connecting(deadline: epoch.addingTimeInterval(4)))
    }

    @Test("connecting + a tick AT/AFTER the deadline fails ALL pending and disconnects")
    func connectingDeadlineTickFailsAllPending() {
        var policy = HeadlessRelayPolicy(configuration: config)
        let first = newCommand()
        let second = newCommand()
        _ = policy.handle(.commandAccepted(first, now: epoch))
        _ = policy.handle(.commandAccepted(second, now: epoch))
        let effects = policy.handle(.tick(now: epoch.addingTimeInterval(4)))
        #expect(effects == [.failPending([first.id, second.id], .couldNotReachTV), .disconnect])
        #expect(policy.state == .retiring)
        #expect(policy.pending.isEmpty)
    }

    @Test("connecting + sessionFailed fails all pending and disconnects")
    func connectingSessionFailedFailsAllPending() {
        var policy = HeadlessRelayPolicy(configuration: config)
        let cmd = newCommand()
        _ = policy.handle(.commandAccepted(cmd, now: epoch))
        let effects = policy.handle(.sessionFailed)
        #expect(effects == [.failPending([cmd.id], .couldNotReachTV), .disconnect])
        #expect(policy.state == .retiring)
    }

    // MARK: - connected: immediate forward + idle-deadline refresh

    @Test("connected + commandAccepted forwards immediately and refreshes the idle deadline")
    func connectedForwardsImmediatelyAndRefreshesDeadline() {
        var policy = HeadlessRelayPolicy(configuration: config)
        _ = policy.handle(.commandAccepted(newCommand(), now: epoch))
        _ = policy.handle(.sessionControlling(now: epoch))
        let cmd = newCommand()
        let now = epoch.addingTimeInterval(5)
        let effects = policy.handle(.commandAccepted(cmd, now: now))
        let idleDeadline = now.addingTimeInterval(20)
        #expect(effects == [.forward([cmd]), .scheduleTick(deadline: idleDeadline)])
        #expect(policy.state == .connected(idleDeadline: idleDeadline))
    }

    @Test("connected + a tick BEFORE the idle deadline is a no-op")
    func connectedEarlyTickIsNoOp() {
        var policy = HeadlessRelayPolicy(configuration: config)
        _ = policy.handle(.commandAccepted(newCommand(), now: epoch))
        _ = policy.handle(.sessionControlling(now: epoch))
        let effects = policy.handle(.tick(now: epoch.addingTimeInterval(1)))
        #expect(effects.isEmpty)
    }

    @Test("connected + a tick AT/AFTER the idle deadline disconnects with nothing pending to fail")
    func connectedIdleTickDisconnects() {
        var policy = HeadlessRelayPolicy(configuration: config)
        _ = policy.handle(.commandAccepted(newCommand(), now: epoch))
        _ = policy.handle(.sessionControlling(now: epoch))
        let effects = policy.handle(.tick(now: epoch.addingTimeInterval(20)))
        #expect(effects == [.disconnect])
        #expect(policy.state == .retiring)
    }

    @Test("connected + sessionFailed disconnects with nothing pending to fail")
    func connectedSessionFailedDisconnects() {
        var policy = HeadlessRelayPolicy(configuration: config)
        _ = policy.handle(.commandAccepted(newCommand(), now: epoch))
        _ = policy.handle(.sessionControlling(now: epoch))
        let effects = policy.handle(.sessionFailed)
        #expect(effects == [.disconnect])
        #expect(policy.state == .retiring)
    }

    // MARK: - retireRequested from every non-idle state

    @Test("retireRequested from .connecting fails pending and disconnects")
    func retireRequestedFromConnecting() {
        var policy = HeadlessRelayPolicy(configuration: config)
        let cmd = newCommand()
        _ = policy.handle(.commandAccepted(cmd, now: epoch))
        let effects = policy.handle(.retireRequested)
        #expect(effects == [.failPending([cmd.id], .couldNotReachTV), .disconnect])
        #expect(policy.state == .retiring)
    }

    @Test("retireRequested from .connected disconnects with nothing pending")
    func retireRequestedFromConnected() {
        var policy = HeadlessRelayPolicy(configuration: config)
        _ = policy.handle(.commandAccepted(newCommand(), now: epoch))
        _ = policy.handle(.sessionControlling(now: epoch))
        let effects = policy.handle(.retireRequested)
        #expect(effects == [.failPending([], .couldNotReachTV), .disconnect])
        #expect(policy.state == .retiring)
    }

    // MARK: - idle strays are all no-ops

    @Test("idle + retireRequested/tick/sessionFailed are all stray no-ops")
    func idleStrayEventsAreNoOps() {
        var policy = HeadlessRelayPolicy(configuration: config)
        #expect(policy.handle(.retireRequested).isEmpty)
        #expect(policy.handle(.tick(now: epoch)).isEmpty)
        #expect(policy.handle(.sessionFailed).isEmpty)
        #expect(policy.state == .idle)
    }

    // MARK: - retiring -> idle

    @Test("retiring + teardownComplete returns to idle")
    func teardownCompleteReturnsToIdle() {
        var policy = HeadlessRelayPolicy(configuration: config)
        _ = policy.handle(.commandAccepted(newCommand(), now: epoch))
        _ = policy.handle(.sessionFailed) // -> .retiring
        let effects = policy.handle(.teardownComplete)
        #expect(effects == [.none])
        #expect(policy.state == .idle)
    }
}

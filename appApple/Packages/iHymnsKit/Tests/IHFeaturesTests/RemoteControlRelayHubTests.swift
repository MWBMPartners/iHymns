// RemoteControlRelayHubTests.swift
// IHFeaturesTests
//
// ELI5: Proves the traffic cop between the watch and the phone gets every
// call right — routes to the open screen when it's open, routes to a
// background connection otherwise, never double-announces the same status
// twice, and can't be confused by a screen that's already gone.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §7.2 —
// BINDING). Every test builds its OWN `RemoteControlRelayHub` instance
// (never the process-wide `.shared`) for isolation. `FakeHeadlessRelayDriver`
// is a plain in-memory `HeadlessRelayServicing` double — no session, no
// network, no WCSession anywhere in this file.
import Foundation
import IHLive
import IHModels
import Testing
@testable import IHFeatures

@MainActor
private final class FakeHeadlessRelayDriver: HeadlessRelayServicing {
    var liveSnapshot: WatchRelaySnapshot?
    var baselineSnapshot: WatchRelaySnapshot = .noSavedTV
    var replyToReturn = WatchRelayReply(outcome: .failed, reason: .noSavedTV, snapshot: .noSavedTV)
    private(set) var performedCommands: [WatchRelayCommand] = []
    private(set) var retireCallCount = 0
    private(set) var forceDownCallCount = 0

    func perform(_ command: WatchRelayCommand) async -> WatchRelayReply {
        performedCommands.append(command)
        return replyToReturn
    }

    func retire() async { retireCallCount += 1 }
    func forceDown() async { forceDownCallCount += 1 }
}

@Suite("RemoteControlRelayHub")
@MainActor
struct RemoteControlRelayHubTests {

    private let tvName = "Fellowship Hall TV"

    private func makeCoordinator() -> RemoteControlCoordinator {
        RemoteControlCoordinator(store: InMemoryPairedTVStore())
    }

    // MARK: - No coordinator, no driver

    @Test("With no coordinator and no driver registered, handle(.nextComponent) fails .noSavedTV")
    func noCoordinatorNoDriverFailsNoSavedTV() async {
        let hub = RemoteControlRelayHub()
        let reply = await hub.handle(.nextComponent)
        #expect(reply.outcome == .failed)
        #expect(reply.reason == .noSavedTV)
    }

    // MARK: - A registered .controlling coordinator forwards, with no network

    @Test("A registered coordinator in .controlling routes requestState -> replyOnly and a control command -> forwarded")
    func registeredControllingCoordinatorRoutesForeground() async {
        let hub = RemoteControlRelayHub()
        let coordinator = makeCoordinator()
        hub.register(coordinator)

        let songId = SongID(songbookAbbreviation: "MP", number: 1008)
        let state = IHRPState(songId: songId, componentIndex: 0, lineIndex: nil, displayState: .lyrics, revision: 1)
        coordinator.uiPhase = .controlling(tvName: tvName, state: state)
        hub.publishCoordinator(RemoteControlCoordinator.relaySnapshot(from: coordinator.uiPhase))

        let stateReply = await hub.handle(.requestState)
        #expect(stateReply.outcome == .replyOnly)

        // The un-started session's `try?` swallows the actual send — no
        // network, no crash.
        let nextReply = await hub.handle(.nextComponent)
        #expect(nextReply.outcome == .forwarded)
    }

    // MARK: - A registered .codeEntry coordinator rejects

    @Test("A registered coordinator in .codeEntry rejects a control command as busyPairing")
    func registeredCodeEntryCoordinatorRejectsBusyPairing() async {
        let hub = RemoteControlRelayHub()
        let coordinator = makeCoordinator()
        hub.register(coordinator)
        coordinator.uiPhase = .codeEntry(tvName: tvName, failedAttempts: 1)
        hub.publishCoordinator(RemoteControlCoordinator.relaySnapshot(from: coordinator.uiPhase))

        let reply = await hub.handle(.nextComponent)
        #expect(reply.outcome == .failed)
        #expect(reply.reason == .busyPairing)
    }

    // MARK: - Publish dedup

    @Test("Publishing the identical merged snapshot twice fires onSnapshot only once")
    func publishDedup() {
        let hub = RemoteControlRelayHub()
        var fireCount = 0
        hub.onSnapshot = { _ in fireCount += 1 }
        hub.publishCoordinator(.standby(tvName: tvName))
        hub.publishCoordinator(.standby(tvName: tvName))
        #expect(fireCount == 1)
    }

    // MARK: - Unregister falls back to the baseline

    @Test("Unregistering the coordinator falls the merged snapshot back to the driver baseline")
    func unregisterFallsBackToBaseline() {
        let hub = RemoteControlRelayHub()
        let coordinator = makeCoordinator()
        hub.register(coordinator)
        hub.publishCoordinator(.standby(tvName: tvName))
        #expect(hub.lastMerged.tvName == tvName)

        hub.unregister(coordinator)
        #expect(hub.lastMerged == .noSavedTV)
    }

    // MARK: - Stale unregister can't evict a successor

    @Test("A stale unregister (an old coordinator instance) cannot evict a NEWER registered successor")
    func staleUnregisterCannotEvictSuccessor() {
        let hub = RemoteControlRelayHub()
        let first = makeCoordinator()
        let second = makeCoordinator()
        hub.register(first)
        hub.register(second)
        hub.publishCoordinator(.standby(tvName: "Second TV"))

        hub.unregister(first)

        #expect(hub.lastMerged.tvName == "Second TV")
    }

    // MARK: - retireHeadless()

    @Test("retireHeadless() with no driver registered is a no-op that returns immediately")
    func retireHeadlessNoDriverIsNoOp() async {
        let hub = RemoteControlRelayHub()
        await hub.retireHeadless()
    }

    @Test("retireHeadless() with a driver attached calls its retire()")
    func retireHeadlessCallsDriverRetire() async {
        let hub = RemoteControlRelayHub()
        let fake = FakeHeadlessRelayDriver()
        hub.attachDriver(fake)
        await hub.retireHeadless()
        #expect(fake.retireCallCount == 1)
    }

    // MARK: - expireBackgroundBudget()

    @Test("expireBackgroundBudget() with a driver attached calls its forceDown()")
    func expireBackgroundBudgetCallsDriverForceDown() async {
        let hub = RemoteControlRelayHub()
        let fake = FakeHeadlessRelayDriver()
        hub.attachDriver(fake)
        await hub.expireBackgroundBudget()
        #expect(fake.forceDownCallCount == 1)
    }

    // MARK: - .headless route dispatches to the driver

    @Test("With no coordinator registered, a control command routes .headless and is forwarded via the driver")
    func headlessRouteDispatchesToDriver() async {
        let hub = RemoteControlRelayHub()
        let fake = FakeHeadlessRelayDriver()
        fake.replyToReturn = WatchRelayReply(outcome: .forwarded, snapshot: .standby(tvName: tvName))
        hub.attachDriver(fake)

        let reply = await hub.handle(.nextComponent)
        #expect(reply.outcome == .forwarded)
        #expect(fake.performedCommands == [.nextComponent])
    }
}

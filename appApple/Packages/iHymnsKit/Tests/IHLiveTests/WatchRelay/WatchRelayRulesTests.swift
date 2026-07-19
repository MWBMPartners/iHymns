// WatchRelayRulesTests.swift
// IHLiveTests
//
// ELI5: Proves the relay rulebook gets every single decision right — which
// TV command each watch button means, who should handle a tap in every
// possible situation the phone's screen could be in, and which single
// picture of the world the watch should be shown when several sources of
// truth disagree.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §7.1 —
// BINDING, the full 36-cell route table + the merge table). Pure — no
// session, no actor, no network.
import Foundation
import Testing
@testable import IHLive

@Suite("WatchRelayRules — intent map, route table, merge")
struct WatchRelayRulesTests {

    // MARK: - intent(for:)

    @Test("intent(for:) maps every command to its IHRP counterpart exactly")
    func intentMapIsExact() {
        #expect(WatchRelayRules.intent(for: .nextComponent) == .nextComponent)
        #expect(WatchRelayRules.intent(for: .prevComponent) == .prevComponent)
        #expect(WatchRelayRules.intent(for: .showLyrics) == .setDisplayState(.lyrics))
        #expect(WatchRelayRules.intent(for: .showBlackout) == .setDisplayState(.blackout))
        #expect(WatchRelayRules.intent(for: .showLogo) == .setDisplayState(.logo))
        #expect(WatchRelayRules.intent(for: .requestState) == nil)
    }

    @Test("Every forwardable intent satisfies isControlIntent — proves the sendIntent assertion never trips")
    func everyForwardedIntentIsAControlIntent() {
        for command in WatchRelayCommand.allCases {
            guard let intent = WatchRelayRules.intent(for: command) else { continue }
            #expect(intent.isControlIntent, "\(command) mapped to a non-control intent")
        }
    }

    // MARK: - The full 36-cell route table

    private func expectedRoute(
        for command: WatchRelayCommand, coordinatorPhase: WatchRelaySnapshot.Phase?
    ) -> WatchRelayRules.WatchRelayRoute {
        guard command != .requestState else { return .replyOnly }
        guard let coordinatorPhase else { return .headless }
        switch coordinatorPhase {
        case .noSavedTV, .standby: return .headless
        case .pairing: return .reject(.busyPairing)
        case .connecting: return .reject(.busyConnecting)
        case .controlling: return .foreground
        }
    }

    @Test("The full 6x6 route table matches the spec exactly", arguments: WatchRelayCommand.allCases)
    func routeTableIsExact(_ command: WatchRelayCommand) {
        let phases: [WatchRelaySnapshot.Phase?] = [nil] + WatchRelaySnapshot.Phase.allCases
        for phase in phases {
            let actual = WatchRelayRules.route(command, coordinatorPhase: phase)
            let expected = expectedRoute(for: command, coordinatorPhase: phase)
            #expect(actual == expected, "command=\(command) phase=\(String(describing: phase))")
        }
    }

    // MARK: - mergeSnapshot

    @Test("A live headless burst always wins the merge, over both other sources")
    func driverLiveWins() {
        let live = WatchRelaySnapshot(phase: .connecting, tvName: "Live TV")
        let coordinator = WatchRelaySnapshot(phase: .controlling, tvName: "Coord TV")
        let baseline = WatchRelaySnapshot.standby(tvName: "Baseline TV")
        let merged = WatchRelayRules.mergeSnapshot(coordinator: coordinator, driverLive: live, driverBaseline: baseline)
        #expect(merged == live)
    }

    @Test("A registered coordinator with its own tvName wins over the baseline when there's no live burst")
    func coordinatorWinsOverBaseline() {
        let coordinator = WatchRelaySnapshot(
            phase: .controlling, tvName: "Coord TV", songRef: "MP-1", displayState: "lyrics", revision: 1
        )
        let baseline = WatchRelaySnapshot.standby(tvName: "Baseline TV")
        let merged = WatchRelayRules.mergeSnapshot(coordinator: coordinator, driverLive: nil, driverBaseline: baseline)
        #expect(merged == coordinator)
    }

    @Test("A coordinator .standby with no tvName is enriched from the driver baseline's tvName")
    func standbyEnrichedFromBaseline() {
        let coordinator = WatchRelaySnapshot(phase: .standby, tvName: nil)
        let baseline = WatchRelaySnapshot.standby(tvName: "Baseline TV")
        let merged = WatchRelayRules.mergeSnapshot(coordinator: coordinator, driverLive: nil, driverBaseline: baseline)
        #expect(merged.phase == .standby)
        #expect(merged.tvName == "Baseline TV")
    }

    @Test("A coordinator .noSavedTV with no tvName is also enriched from the driver baseline's tvName")
    func noSavedTVEnrichedFromBaseline() {
        let coordinator = WatchRelaySnapshot(phase: .noSavedTV, tvName: nil)
        let baseline = WatchRelaySnapshot.standby(tvName: "Baseline TV")
        let merged = WatchRelayRules.mergeSnapshot(coordinator: coordinator, driverLive: nil, driverBaseline: baseline)
        #expect(merged.tvName == "Baseline TV")
    }

    @Test("With no coordinator registered and no live burst, the driver baseline wins outright")
    func baselineFallbackWhenNoCoordinator() {
        let baseline = WatchRelaySnapshot.noSavedTV
        let merged = WatchRelayRules.mergeSnapshot(coordinator: nil, driverLive: nil, driverBaseline: baseline)
        #expect(merged == baseline)
    }
}

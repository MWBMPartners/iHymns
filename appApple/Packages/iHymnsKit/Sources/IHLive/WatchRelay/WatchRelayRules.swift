// WatchRelayRules.swift
// IHLive/WatchRelay
//
// ELI5: The one rulebook that decides three things — "what does this watch
// button actually mean to the TV," "who should handle this tap right now —
// the screen that's already open, or a fresh background connection," and
// "what should the watch's screen show, given everything we know."
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §3.3 —
// BINDING). Pure, static, zero I/O — `RemoteControlRelayHub` (`IHFeatures`)
// is the ONLY caller; every branch here is exhaustively table-tested
// (`WatchRelayRulesTests`) so a routing/merge bug is always reproducible
// without a session, an actor, or a network.
import Foundation

/// The pure relay brain — see this file's header.
public enum WatchRelayRules {

    /// The COMPLETE command → LAN-intent map. Exhaustive `switch`, no
    /// `default` — a new `WatchRelayCommand` case without a row here is a
    /// COMPILE ERROR, never a silently-missing mapping. `.requestState`
    /// never becomes an intent — it's answered from the snapshot alone
    /// (`route(_:coordinatorPhase:)` routes it `.replyOnly` unconditionally).
    ///
    /// ELI5: "Turn this watch button into the exact thing the TV
    /// understands."
    public static func intent(for command: WatchRelayCommand) -> IHRPMessage? {
        switch command {
        case .nextComponent: return .nextComponent
        case .prevComponent: return .prevComponent
        case .showLyrics: return .setDisplayState(.lyrics)
        case .showBlackout: return .setDisplayState(.blackout)
        case .showLogo: return .setDisplayState(.logo)
        case .requestState: return nil
        }
    }

    /// WHO serves a watch command — the single-owner arbitration, pure.
    ///
    /// ELI5: "Should the screen that's already open handle this, or should a
    /// fresh background connection?"
    public enum WatchRelayRoute: Sendable, Equatable {
        /// Forward via `coordinator.sendIntent` — the live on-screen
        /// session.
        case foreground
        /// Hand to `HeadlessRelayDriver.perform` — wake-connect if needed.
        case headless
        /// `.requestState` — answered straight from the hub's snapshot.
        case replyOnly
        /// The phone is busy elsewhere — try again shortly.
        case reject(WatchRelayReply.Reason)
    }

    /// The route table (BINDING — `WatchRelayRulesTests` asserts all
    /// `WatchRelayCommand.allCases.count × (WatchRelaySnapshot.Phase
    /// .allCases.count + 1)` = 36 cells). `coordinatorPhase` is the
    /// REGISTERED coordinator's current snapshot phase; `nil` means no
    /// coordinator is registered at all (e.g. a non-iOS platform, or the
    /// coordinator was torn down).
    ///
    /// Driver state is deliberately NOT an input: the driver serializes its
    /// own bursts internally, and `coordinatorPhase` alone decides ownership
    /// because the single-owner retire hooks (`RemoteControlRelayHub
    /// .retireHeadless()`) guarantee the driver is idle whenever the
    /// coordinator is live (`.controlling`/`.connecting`/`.pairing`).
    ///
    /// ELI5: "Given what the phone's screen is doing right now, where
    /// should this tap go?"
    public static func route(_ command: WatchRelayCommand, coordinatorPhase: WatchRelaySnapshot.Phase?) -> WatchRelayRoute {
        guard command != .requestState else { return .replyOnly }
        guard let coordinatorPhase else { return .headless }
        switch coordinatorPhase {
        case .noSavedTV, .standby:
            // A registered-but-browsing coordinator (the user is looking at
            // the TV list) — a watch tap still drives the last TV headlessly;
            // the coordinator's own retire hook (start of `resolveAndAttach`)
            // steps in the instant the user taps a row.
            return .headless
        case .pairing:
            return .reject(.busyPairing)
        case .connecting:
            // Momentary by construction — the foreground connect resolves
            // to `.controlling` or back within seconds.
            return .reject(.busyConnecting)
        case .controlling:
            return .foreground
        }
    }

    /// The ONE snapshot the watch sees — pure merge of the three sources.
    /// Priority: a LIVE headless burst beats a registered coordinator beats
    /// the driver's at-rest baseline. A coordinator `.standby`/`.noSavedTV`
    /// with `tvName == nil` (the browsing screen doesn't know the last TV's
    /// name) is enriched from `driverBaseline`'s `tvName` before falling
    /// through — never a wholesale baseline override of a live coordinator
    /// snapshot.
    ///
    /// ELI5: "Out of everything we know right now, what's the ONE true
    /// picture to show the watch?"
    public static func mergeSnapshot(
        coordinator: WatchRelaySnapshot?,
        driverLive: WatchRelaySnapshot?,
        driverBaseline: WatchRelaySnapshot
    ) -> WatchRelaySnapshot {
        if let driverLive { return driverLive }
        guard let coordinator else { return driverBaseline }
        guard coordinator.tvName == nil, coordinator.phase == .standby || coordinator.phase == .noSavedTV else {
            return coordinator
        }
        return WatchRelaySnapshot(
            phase: coordinator.phase, tvName: driverBaseline.tvName,
            songRef: coordinator.songRef, displayState: coordinator.displayState, revision: coordinator.revision
        )
    }
}

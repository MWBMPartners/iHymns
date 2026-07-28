// RemoteControlCoordinator+WatchRelay.swift
// IHFeatures/WatchRelay
//
// ELI5: The coordinator's own thin plug into the traffic cop — "here's what
// my screen is showing right now," "I've just started/stopped," and "please
// make sure nothing's connecting in the background before I do."
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §3.1/§4.3/§6.1
// — BINDING). The pure `relaySnapshot(from:)` mapping is `nonisolated
// static`, mirroring `RemoteControlCoordinator+UIPhase.swift`'s
// `uiPhase(after:current:tvName:)` precedent exactly, so
// `WatchRelaySnapshotMappingTests` can drive it with zero actor/session/
// network involved. The four thin `relay*` helpers below are what
// `RemoteControlCoordinator.swift`'s six call-lines (`start()`/`stop()`/
// `apply(_:)`/`resolveAndAttach`/`setScenePhaseActive(_:)`) and
// `+ManualConnect.swift`'s one call-line actually invoke — keeping the core
// file's own diff to call-lines only (spec §2's LOC-budget rationale).
import IHLive

extension RemoteControlCoordinator {

    /// The pure `UIPhase → WatchRelaySnapshot` mapping (spec §3.1's table,
    /// BINDING) — `WatchRelaySnapshotMappingTests` asserts every one of the
    /// 7 `UIPhase` rows.
    ///
    /// `.browsing`/`.suspended` carry the tvName they actually know (`nil`
    /// for `.browsing` — the list screen has no "current" TV in mind;
    /// whatever the coordinator itself was last suspended from for
    /// `.suspended`) — `WatchRelayRules.mergeSnapshot`'s baseline enrichment
    /// only ever fires on a `nil` tvName, so passing through a REAL one here
    /// where the coordinator has it is strictly more informative and never
    /// contradicts that merge rule.
    ///
    /// ELI5: "Turn what the phone's screen is showing into the tiny picture
    /// the watch is allowed to see."
    nonisolated static func relaySnapshot(from phase: UIPhase) -> WatchRelaySnapshot {
        switch phase {
        case .browsing:
            return .standby(tvName: nil)
        case .connecting(let tvName, _):
            return WatchRelaySnapshot(phase: .connecting, tvName: tvName)
        case .reconnecting(let tvName, _):
            return WatchRelaySnapshot(phase: .connecting, tvName: tvName)
        case .codeEntry(let tvName, _):
            return WatchRelaySnapshot(phase: .pairing, tvName: tvName)
        case .confirmingFingerprint(let tvName, _):
            // The fingerprint hex itself never enters the snapshot — only
            // the phase + the TV's cosmetic name (spec §8 row 8's executable
            // assertion, `WatchRelaySnapshotMappingTests`).
            return WatchRelaySnapshot(phase: .pairing, tvName: tvName)
        case .controlling(let tvName, let state):
            return WatchRelaySnapshot(
                phase: .controlling, tvName: tvName, songRef: state.songId?.rawValue,
                displayState: state.displayState.rawValue, revision: state.revision
            )
        case .suspended(let tvName):
            // The pocket redesign's key row — no more `.phoneBackgrounded`
            // dead-end: a suspended (backgrounded) coordinator is `.standby`,
            // exactly the pocket-control headline state.
            return .standby(tvName: tvName)
        }
    }

    // MARK: - Relay tap + single-owner retire hooks (spec §4.3/§6.1)

    func relayRegister() {
        RemoteControlRelayHub.shared.register(self)
    }

    func relayUnregister() {
        RemoteControlRelayHub.shared.unregister(self)
    }

    func relayPublish() {
        RemoteControlRelayHub.shared.publishCoordinator(Self.relaySnapshot(from: uiPhase))
    }

    /// The single-owner handoff door, called from all four retire-hook
    /// sites (spec §4.3) — awaits the driver's complete teardown before the
    /// caller proceeds to attach.
    func relayRetireHeadless() async {
        await RemoteControlRelayHub.shared.retireHeadless()
    }
}

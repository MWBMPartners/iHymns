// LANTroubleshooterAssessment.swift
// IHLive/LANRemote
//
// ELI5: Takes everything the troubleshooter observed (could it ask for
// Local Network permission, did it find any TVs nearby, did a direct
// address test work) and turns that into "here's the ONE main thing
// that's wrong (if anything), plus anything else worth knowing."
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §5.3 — BINDING
// decision table, do not improvise). A pure, static decision function — no
// I/O, no clock, no network — so `LANTroubleshooterAssessmentTests` can
// table-drive every row with plain constructed inputs. Decision D-8: the
// findings are a semantic `enum` (never a raw string) and the user-facing
// copy lives in `IHFeatures` (`NetworkTroubleshooterViewModel`'s exhaustive
// `switch` copy map) — a new `Finding` case is a compile error there,
// never a silently-missing message.
import Foundation

/// Everything the troubleshooter observed, in one bag — what
/// `LANTroubleshooterAssessment.evaluate(_:)` decides from.
public struct LANTroubleshooterInputs: Sendable, Equatable {
    /// `true` when the troubleshooter's OWN Bonjour browse hit
    /// `RemoteSessionActor.isLocalNetworkPermissionDenied(_:)` — dominates
    /// every other row (spec §5.3 row 1).
    public var localNetworkPermissionDenied: Bool
    /// How many distinct Bonjour services the troubleshooter's bounded
    /// browse window saw.
    public var discoveredServiceCount: Int
    /// `nil` ⇒ no address was probed (a discovery-only run); non-nil ⇒ the
    /// `LANConnectivityProbe.probe(...)` outcome for a typed/saved address.
    public var probe: LANConnectivityProbeOutcome?

    public init(localNetworkPermissionDenied: Bool, discoveredServiceCount: Int, probe: LANConnectivityProbeOutcome?) {
        self.localNetworkPermissionDenied = localNetworkPermissionDenied
        self.discoveredServiceCount = discoveredServiceCount
        self.probe = probe
    }
}

/// The semantic outcomes `evaluate(_:)` can report — spec §5.3's table,
/// one case per row's HEADLINE (or secondary) finding. `CaseIterable` so a
/// new case is impossible to add without every exhaustive `switch` over
/// this type (the copy map, `IHFeatures`) noticing at compile time.
public enum LANTroubleshooterFinding: Sendable, Equatable, CaseIterable {
    case localNetworkPermissionDenied
    case nothingDiscoveredNoProbe
    case discoveryWorking
    case allClear
    case vpnOrMulticastBlocked
    case likelyClientIsolation
    case unreachableAndInvisible
    case portClosedOnHost
    case notAnIHymnsListener
    case hostnameNotResolving
    case invalidAddress
}

/// The pure decision function — see this file's header for why it's a
/// static `enum`, not an `actor`.
public enum LANTroubleshooterAssessment {
    /// Turns `inputs` into an ORDERED list of findings — the first element
    /// is always the headline the UI leads with; any further elements are
    /// secondary findings worth also surfacing (spec §5.3: row 6 appends
    /// `.discoveryWorking` after its `.likelyClientIsolation` headline).
    /// Deterministic order, exhaustively table-tested.
    ///
    /// ELI5: "Given everything we just checked, what's the main thing
    /// wrong (if anything), and what else is worth knowing?"
    public static func evaluate(_ inputs: LANTroubleshooterInputs) -> [LANTroubleshooterFinding] {
        // Row 1 — dominates every other row: nothing else the troubleshooter
        // observed matters until Local Network permission itself is granted.
        if inputs.localNetworkPermissionDenied {
            return [.localNetworkPermissionDenied]
        }

        guard let probe = inputs.probe else {
            // Rows 2/3 — a discovery-only run (no address was probed).
            return inputs.discoveredServiceCount > 0 ? [.discoveryWorking] : [.nothingDiscoveredNoProbe]
        }

        switch probe {
        case .reachable:
            // Rows 4/5 — direct connection works either way; discovery
            // status is what distinguishes "all clear" from "the owner's
            // day-one VPN/multicast-blocked case" (strategy §2.4.5).
            return inputs.discoveredServiceCount > 0 ? [.allClear] : [.vpnOrMulticastBlocked]
        case .timedOut:
            // Rows 6/7 — devices visible but unreachable (row 6, likely AP
            // isolation) vs. nothing visible AND unreachable (row 7).
            return inputs.discoveredServiceCount > 0 ? [.likelyClientIsolation, .discoveryWorking] : [.unreachableAndInvisible]
        case .refused:
            return [.portClosedOnHost] // Row 8.
        case .tlsFailed:
            return [.notAnIHymnsListener] // Row 9.
        case .dnsFailed:
            return [.hostnameNotResolving] // Row 10.
        case .invalidAddress:
            return [.invalidAddress] // Row 11.
        }
    }
}

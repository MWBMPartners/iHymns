// NetworkTroubleshooterViewModel.swift
// IHFeatures
//
// ELI5: Drives the "why can't my phone find/reach my TV?" screen — runs
// the check, remembers what it found, and turns each finding into plain
// -English words a non-technical volunteer can act on.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §6.4). A thin
// `@MainActor @Observable` wrapper around `LANTroubleshooter` (`IHLive`) —
// `findingCopy(_:)` is the ONE user-facing copy map (spec §5.3's table,
// Decision D-8: an exhaustive `switch` so a new `LANTroubleshooterFinding`
// case is a compile error here, never a silently-missing message).
import IHLive
import Observation

/// Drives `NetworkTroubleshooterView` — see this file's header.
@MainActor
@Observable
public final class NetworkTroubleshooterViewModel {
    public var addressInput: String
    public var portInput: String
    public private(set) var isRunning = false
    public private(set) var report: LANTroubleshooterReport?

    public init() {
        self.addressInput = IHSettingsStore().lastManualConnectAddress ?? ""
        self.portInput = String(LANRemoteDiscovery.defaultPort)
    }

    /// Runs the whole check — parses the (optional) address field first;
    /// an EMPTY address runs a discovery-only pass (spec §6.4: "empty
    /// address ⇒ discovery-only run").
    ///
    /// ELI5: "Run the network check now."
    public func runChecks() async {
        isRunning = true
        defer { isRunning = false }

        let trimmedAddress = addressInput.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmedAddress.isEmpty else {
            report = await LANTroubleshooter().run(probingHost: nil, port: LANRemoteDiscovery.defaultPort)
            return
        }

        switch LANRemoteManualAddress.parse(hostInput: addressInput, portInput: portInput) {
        case .failure:
            // A parse-level reject reaches the same `.invalidAddress`
            // finding the pure assessment table already defines (spec
            // §5.3 row 11) — no separate error state needed here.
            report = LANTroubleshooterReport(
                inputs: LANTroubleshooterInputs(localNetworkPermissionDenied: false, discoveredServiceCount: 0, probe: .invalidAddress),
                discoveredNames: [], findings: [.invalidAddress]
            )
        case .success(let parsed):
            report = await LANTroubleshooter().run(probingHost: parsed.host, port: parsed.port)
        }
    }

    // The §5.3 copy column, exactly — an exhaustive switch (this file's
    // header, Decision D-8) so a new Finding case fails to compile here
    // until it's given real copy. ELI5: "turn this technical finding into
    // a sentence a person can act on." An exhaustive switch over every
    // `LANTroubleshooterFinding` case is high CYCLOMATIC complexity by the
    // metric's definition, but each branch is an independent one-liner —
    // the same complexity-suppression precedent `IHRPFrame.swift`'s
    // Codable switch already established (no `///` doc block immediately
    // before this comment, matching that file's shape, avoids an
    // orphaned-doc-comment lint violation).
    // swiftlint:disable:next cyclomatic_complexity
    public func findingCopy(_ finding: LANTroubleshooterFinding) -> (title: String, guidance: String) {
        switch finding {
        case .localNetworkPermissionDenied:
            return ("Local Network access is off", "Allow Local Network for iHymns: Settings → Privacy & Security → Local Network.")
        case .nothingDiscoveredNoProbe:
            return (
                "No TVs found",
                "Check you're on the venue Wi-Fi and iHymns is open on the TV — or type the TV's address below to test it directly."
            )
        case .discoveryWorking:
            return ("Discovery is working", "Go back and pair by QR code, or test a specific address below.")
        case .allClear:
            return ("Everything checks out", "Discovery and direct connection both work.")
        case .vpnOrMulticastBlocked:
            return (
                "Direct connection works, but discovery doesn't",
                "Typical on a VPN or a network that blocks device discovery. Use Connect by Address."
            )
        case .likelyClientIsolation:
            return (
                "Devices can see each other, but can't connect",
                "The Wi-Fi likely has AP/client isolation enabled. The venue network must allow devices on this "
                    + "Wi-Fi to reach each other (see the venue network guide)."
            )
        case .unreachableAndInvisible:
            return ("Nothing answered", "Wrong Wi-Fi, client isolation, a firewall, or the TV is off.")
        case .portClosedOnHost:
            return (
                "Nothing is listening at that address",
                "That device is on the network, but nothing is listening on that port — is iHymns open on the TV? "
                    + "Check the port on the TV's Settings → Remote Control screen."
            )
        case .notAnIHymnsListener:
            return ("That doesn't look like an iHymns TV", "Something answered, but it doesn't look like iHymns on an Apple TV — double-check the address and port.")
        case .hostnameNotResolving:
            return ("That name didn't resolve", "Try the TV's IP address instead (shown on the TV's Settings → Remote Control screen).")
        case .invalidAddress:
            return ("That address isn't valid", "Check what you typed and try again.")
        }
    }
}

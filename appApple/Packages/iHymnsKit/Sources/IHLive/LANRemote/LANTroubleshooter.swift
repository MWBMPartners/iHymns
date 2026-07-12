// LANTroubleshooter.swift
// IHLive/LANRemote
//
// ELI5: Runs the whole "why can't my phone find/reach my TV?" check in one
// go — looks for TVs on the network for a few seconds, optionally tests one
// specific address directly, and hands both results to the decision table
// so the screen can show plain-English guidance.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §5.2). Plumbing only
// — the actual DECISION logic lives in `LANTroubleshooterAssessment
// .evaluate(_:)`, which this actor is a thin, injected-Duration runner
// around. Runs its OWN bounded `NWBrowser` (independent of anything
// `RemoteSessionActor` owns — the single-consumer rule that file's header
// documents concerns `RemoteSessionActor`'s OWN two streams, not the OS's
// browser multiplicity) and REUSES `RemoteSessionActor
// .isLocalNetworkPermissionDenied(_:)` (internal, same module) rather than
// re-deriving the `kDNSServiceErr_PolicyDenied` shape a second time
// (Decision D-7).
import Foundation
import IHLog
import Network
import os

/// One troubleshooting run's full result — what `NetworkTroubleshooterView`
/// renders from.
public struct LANTroubleshooterReport: Sendable, Equatable {
    public var inputs: LANTroubleshooterInputs
    /// Shown on-screen; NEVER logged verbatim (an instance name can be a
    /// venue/device identifier — this module's `.private` convention for
    /// names/addresses).
    public var discoveredNames: [String]
    public var findings: [LANTroubleshooterFinding]

    public init(inputs: LANTroubleshooterInputs, discoveredNames: [String], findings: [LANTroubleshooterFinding]) {
        self.inputs = inputs
        self.discoveredNames = discoveredNames
        self.findings = findings
    }
}

/// The diagnostic runner — see this file's header for why it's plumbing,
/// not policy.
public actor LANTroubleshooter {
    private let discoveryWindow: Duration
    private let probeTimeout: Duration

    public init(discoveryWindow: Duration = .seconds(3), probeTimeout: Duration = .seconds(4)) {
        self.discoveryWindow = discoveryWindow
        self.probeTimeout = probeTimeout
    }

    /// Runs a bounded discovery sample, then (if `probingHost` is given) a
    /// direct-connect probe, then hands both to the pure assessment.
    ///
    /// - Parameter probingHost: `nil` ⇒ a discovery-only run (`inputs.probe`
    ///   is `nil`); otherwise the typed/saved address to test directly.
    ///
    /// ELI5: "Run the whole network check and tell me what's wrong (if
    /// anything)."
    public func run(probingHost: String?, port: UInt16) async -> LANTroubleshooterReport {
        let (names, permissionDenied) = await runDiscoveryWindow()

        var probeOutcome: LANConnectivityProbeOutcome?
        if let probingHost {
            probeOutcome = await LANConnectivityProbe.probe(host: probingHost, port: port, timeout: probeTimeout)
        }

        let inputs = LANTroubleshooterInputs(
            localNetworkPermissionDenied: permissionDenied, discoveredServiceCount: names.count, probe: probeOutcome
        )
        return LANTroubleshooterReport(inputs: inputs, discoveredNames: names, findings: LANTroubleshooterAssessment.evaluate(inputs))
    }

    /// Starts its OWN `NWBrowser`, watches for a Local Network permission
    /// denial via the REUSED `RemoteSessionActor` helper, collects results
    /// for `discoveryWindow`, then stops.
    private func runDiscoveryWindow() async -> (names: [String], permissionDenied: Bool) {
        let permissionDeniedBox = OSAllocatedUnfairLock<Bool>(initialState: false)
        let namesBox = OSAllocatedUnfairLock<Set<String>>(initialState: [])

        let browser = NWBrowser(for: .bonjour(type: LANRemoteDiscovery.bonjourServiceType, domain: nil), using: .tcp)
        browser.stateUpdateHandler = { state in
            if case .waiting(let error) = state, RemoteSessionActor.isLocalNetworkPermissionDenied(error) {
                permissionDeniedBox.withLock { $0 = true }
            }
        }
        browser.browseResultsChangedHandler = { results, _ in
            let names: Set<String> = Set(results.compactMap { result in
                guard case .service(let name, _, _, _) = result.endpoint else { return nil }
                return name
            })
            namesBox.withLock { $0 = names }
        }
        browser.start(queue: DispatchQueue(label: "app.ihymns.lanremote.troubleshoot.discovery"))

        try? await Task.sleep(for: discoveryWindow)
        browser.cancel()

        let names = namesBox.withLock { $0 }
        IHLog.discovery.notice("lanremote.troubleshoot discovery-window count=\(names.count, privacy: .public)")
        return (names.sorted(), permissionDeniedBox.withLock { $0 })
    }
}

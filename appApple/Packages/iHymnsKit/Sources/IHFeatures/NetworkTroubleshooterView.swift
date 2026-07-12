// NetworkTroubleshooterView.swift
// IHFeatures
//
// ELI5: The "why can't my phone find/reach my TV?" screen — runs the usual
// checks and shows plain-English guidance for whatever it finds, with a
// quick way to jump straight to "Connect by Address" if that's the fix.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §6.4). Reached from
// `RemoteControlView`'s "Can't find your TV?" section / empty state. No
// `Menu`, no segmented picker, no `DisclosureGroup` (the #1549 watchOS
// -compat rule every new `IHFeatures` file must honour) — `List`/`Form`/
// `Button`/`TextField`/`Label`/`ContentUnavailableView` only. This screen's
// OWN Bonjour browse can legitimately be the FIRST browse the app ever
// runs (a user went straight here instead of the "TV Remote" tab first) —
// the OS Local Network permission prompt fires then, which is exactly
// where a user is primed for network questions (spec §5.4).
//
// #1424 Opus-review fix L1: `onConnectByAddress` is a plain `(host,
// portInput)` closure (the exact shape of `ManualConnectSheet.onConnect`
// and `RemoteControlCoordinator.connectByAddress(hostInput:portInput:)`),
// not a coordinator reference — this file never imports `IHFeatures`'
// coordinator type or `Network` (this repo's D-14 tripwire), it just
// forwards whatever `RemoteControlView` (which OWNS the ONE coordinator
// for the whole remote screen, this repo's composition-root rule) hands
// it. `dismiss()` afterward pops THIS pushed screen back to the remote
// list — the caller sees the connecting banner / interstitial there,
// exactly where every other connect entry point already surfaces it.
import IHLive
import SwiftUI

/// The network troubleshooter screen — see this file's header.
public struct NetworkTroubleshooterView: View {
    @State private var viewModel = NetworkTroubleshooterViewModel()
    @State private var isPresentingManualSheet = false
    @Environment(\.dismiss) private var dismiss

    /// Forwards a "Connect" tap from this screen's own `ManualConnectSheet`
    /// to `RemoteControlCoordinator.connectByAddress(hostInput:portInput:)`
    /// — see this file's header (#1424 Opus-review fix L1).
    private let onConnectByAddress: (String, String?) -> Void

    public init(onConnectByAddress: @escaping (String, String?) -> Void) {
        self.onConnectByAddress = onConnectByAddress
    }

    public var body: some View {
        Form {
            Section {
                Text("If your phone can't find or reach the TV, this checks the usual causes.")
                    .foregroundStyle(.secondary)
            }

            Section("Test an Address (optional)") {
                TextField("192.168.1.50 or hall-tv.local", text: $viewModel.addressInput)
                    #if os(iOS) || os(visionOS)
                    .textInputAutocapitalization(.never)
                    #endif
                    .autocorrectionDisabled()
                TextField("Port", text: $viewModel.portInput)
                    #if os(iOS) || os(visionOS)
                    .textInputAutocapitalization(.never)
                    #endif
                    .autocorrectionDisabled()
            }

            Section {
                Button {
                    Task { await viewModel.runChecks() }
                } label: {
                    if viewModel.isRunning {
                        HStack {
                            ProgressView()
                            Text("Running Checks…")
                        }
                    } else {
                        Text("Run Checks")
                    }
                }
                .disabled(viewModel.isRunning)
            }

            if let report = viewModel.report {
                resultsSection(report)
            }

            Section {
                // #1424 — device-only realities (spec §5.4): real-AP mDNS,
                // actual client isolation, VPN-without-multicast, and the
                // Local Network permission prompt are all only truthfully
                // observable on a real device, never the Simulator.
                Text("Some of these checks only reflect real network conditions on a physical device, not the Simulator.")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
            }
        }
        .navigationTitle("Troubleshoot Connection")
        .sheet(isPresented: $isPresentingManualSheet) {
            ManualConnectSheet(
                onConnect: { host, port in
                    isPresentingManualSheet = false
                    onConnectByAddress(host, port)
                    // #1424 Opus-review fix L1 — pop back to the remote
                    // list so the connecting banner / fingerprint
                    // interstitial (both driven off `RemoteControlView`'s
                    // OWN `coordinator.uiPhase`, never rendered by this
                    // screen) is actually visible.
                    dismiss()
                },
                onCancel: { isPresentingManualSheet = false }
            )
        }
    }

    @ViewBuilder
    private func resultsSection(_ report: LANTroubleshooterReport) -> some View {
        Section("What We Found") {
            Label(
                report.inputs.localNetworkPermissionDenied ? "Local Network access is off" : "Local Network access is allowed",
                systemImage: report.inputs.localNetworkPermissionDenied ? "xmark.circle" : "checkmark.circle"
            )
            Label("Found \(report.inputs.discoveredServiceCount) device(s) via discovery", systemImage: "wifi")
            if let probe = report.inputs.probe {
                Label("Direct connection: \(probeOutcomeSummary(probe))", systemImage: "network")
            }
        }

        Section("Guidance") {
            ForEach(Array(report.findings.enumerated()), id: \.offset) { index, finding in
                findingCard(finding, isHeadline: index == 0)
            }

            if isReachableHeadline(report.findings.first) {
                Button("Connect by Address…") { isPresentingManualSheet = true }
            }
        }
    }

    private func findingCard(_ finding: LANTroubleshooterFinding, isHeadline: Bool) -> some View {
        let copy = viewModel.findingCopy(finding)
        return VStack(alignment: .leading, spacing: 4) {
            Text(copy.title)
                .font(isHeadline ? .headline : .subheadline)
            Text(copy.guidance)
                .font(.footnote)
                .foregroundStyle(.secondary)
        }
    }

    /// The contextual "Connect by Address" hand-off shows only when direct
    /// connection is already known to work (spec §6.4: rows 4/5 —
    /// `.allClear`/`.vpnOrMulticastBlocked`).
    private func isReachableHeadline(_ finding: LANTroubleshooterFinding?) -> Bool {
        finding == .allClear || finding == .vpnOrMulticastBlocked
    }

    private func probeOutcomeSummary(_ outcome: LANConnectivityProbeOutcome) -> String {
        switch outcome {
        case .reachable: "reachable"
        case .refused: "nothing listening"
        case .timedOut: "no answer"
        case .tlsFailed: "not an iHymns listener"
        case .dnsFailed: "hostname didn't resolve"
        case .invalidAddress: "invalid address"
        }
    }
}

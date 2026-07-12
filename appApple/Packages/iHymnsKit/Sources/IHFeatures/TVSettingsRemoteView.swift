// TVSettingsRemoteView.swift
// IHFeatures
//
// ELI5: The TV's Settings tab — "here's how a remote finds and connects to
// this TV" (address/fingerprint/standing QR), a button to start pairing a
// new one, and the list of remotes this TV currently trusts, each with a
// one-tap "revoke" that immediately kicks it off.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §6.3). Fills the
// Settings tab slot `TVRootView`'s own header comment named as deferred
// "until PR-6's trusted-remotes/pairing screens" exist — this is that
// screen. The per-row destructive "Revoke" action uses the SAME
// `.confirmationDialog` pattern `UsageStatsView.swift` already establishes
// in this package, extended to carry WHICH row is pending (a single `Bool`
// flag can't disambiguate rows — `pendingRevokeTarget` does).
import IHDesign
import IHLive
import SwiftUI

/// The tvOS Settings tab's remote-control content.
public struct TVSettingsRemoteView: View {
    let coordinator: TVRemoteControlCoordinator
    @State private var pendingRevokeTarget: PairedRemoteRecord?

    public init(coordinator: TVRemoteControlCoordinator) {
        self.coordinator = coordinator
    }

    public var body: some View {
        List {
            remoteControlSection
            pairSection
            trustedRemotesSection
        }
        .navigationTitle("Settings")
        .confirmationDialog(
            "Revoke this remote?",
            isPresented: isPresentingRevokeConfirmation,
            titleVisibility: .visible
        ) {
            Button("Revoke", role: .destructive) {
                if let target = pendingRevokeTarget {
                    Task { await coordinator.revoke(target) }
                }
                pendingRevokeTarget = nil
            }
            Button("Cancel", role: .cancel) { pendingRevokeTarget = nil }
        } message: {
            Text("This remote will immediately lose control of this TV.")
        }
    }

    private var isPresentingRevokeConfirmation: Binding<Bool> {
        Binding(get: { pendingRevokeTarget != nil }, set: { isPresented in
            if !isPresented { pendingRevokeTarget = nil }
        })
    }

    // MARK: - (1) Remote Control info

    private var remoteControlSection: some View {
        Section("Remote Control") {
            statusRow
            if let ipAddress = coordinator.listenerInfo?.ipAddress {
                LabeledContent("IP Address", value: ipAddress)
            }
            if let fingerprint = coordinator.listenerInfo?.fingerprint {
                fingerprintRow(fingerprint)
            }
            standingQRRow
        }
    }

    @ViewBuilder
    private var statusRow: some View {
        if let startError = coordinator.startError {
            Label(startError, systemImage: "exclamationmark.triangle")
                .foregroundStyle(.red)
        } else if let port = coordinator.listenerInfo?.port {
            Label("Listening on port \(String(port))", systemImage: "antenna.radiowaves.left.and.right")
        } else {
            Label("Starting…", systemImage: "hourglass")
                .foregroundStyle(.secondary)
        }
    }

    private func fingerprintRow(_ fingerprint: String) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("Fingerprint")
                .font(.caption)
                .foregroundStyle(.secondary)
            // The FULL value — this is the out-of-band value strategy
            // §2.4.3's trust model actually pins against; the overlay's own
            // line is a truncated 12-character convenience only.
            //
            // #1424 mechanical swap (PR-8 spec §2, "extract first, use
            // second"): this row's whole-string grouping now calls the
            // canonical `LANRemoteFingerprint.displayGrouped(_:every:)`
            // (`IHLive`) — the SAME helper `FingerprintConfirmView`'s TOFU
            // interstitial uses — instead of this package's own
            // `PairingFingerprintDisplay.grouped(_:prefixLength:)`.
            // `TVPairingOverlayView`'s 12-character-PREFIX short line keeps
            // using `PairingFingerprintDisplay` — that truncate-then-group
            // shape isn't part of this new helper's contract.
            Text(LANRemoteFingerprint.displayGrouped(fingerprint))
                .font(.footnote.monospaced())
        }
    }

    /// The "standing" QR — connect-info only (`includeCode: false`), safe
    /// to leave on screen indefinitely; the pairing CODE never appears
    /// here (spec §6.3: "never a pairing code").
    @ViewBuilder
    private var standingQRRow: some View {
        if let qrImage = PairingQRCode.image(from: coordinator.qrPayloadString(includeCode: false)) {
            VStack(alignment: .leading, spacing: 8) {
                Image(decorative: qrImage, scale: 1)
                    .interpolation(.none)
                    .resizable()
                    .scaledToFit()
                    .frame(width: 180, height: 180)
                Text("Connect info only — never shows a pairing code")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
    }

    // MARK: - (2) Pair

    private var pairSection: some View {
        Section("Pair") {
            Button {
                Task { await coordinator.beginPairing() }
            } label: {
                Label("Pair a remote…", systemImage: "qrcode.viewfinder")
            }
            // The brute-force ceiling's operator-facing notice (post-#1421
            // adversarial-review fix): shown after the listener auto-disarms
            // a ceremony that got too many wrong codes; cleared the next time
            // "Pair a remote…" runs.
            if let notice = coordinator.pairingNotice {
                Label(notice, systemImage: "exclamationmark.shield")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
    }

    // MARK: - (3) Trusted remotes

    private var trustedRemotesSection: some View {
        Section("Trusted Remotes") {
            if coordinator.pairedRemotes.isEmpty {
                Text("No remotes have been paired yet.")
                    .foregroundStyle(.secondary)
            } else {
                ForEach(coordinator.pairedRemotes) { record in
                    pairedRemoteRow(record)
                }
            }
        }
    }

    private func pairedRemoteRow(_ record: PairedRemoteRecord) -> some View {
        HStack {
            Image(systemName: iconName(for: record.metadata.kind))
                .foregroundStyle(IHColorTokens.accent)
            VStack(alignment: .leading, spacing: 2) {
                Text(displayName(for: record.metadata))
                Text(record.metadata.pairedAt, style: .date)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            Spacer(minLength: 12)
            Button("Revoke", role: .destructive) {
                pendingRevokeTarget = record
            }
        }
        .accessibilityElement(children: .combine)
    }

    private func displayName(for metadata: LANRemotePairingMetadata) -> String {
        guard let name = metadata.name, !name.isEmpty else { return "Unknown remote" }
        return name
    }

    /// `IHRPRemoteKind` (`IHLive`) → SF Symbol — a UI-only mapping with
    /// exactly one caller, so it lives here rather than on the model type
    /// itself.
    private func iconName(for kind: IHRPRemoteKind?) -> String {
        switch kind {
        case .phone: return "iphone"
        case .pad: return "ipad"
        case .mac: return "laptopcomputer"
        case .vision: return "visionpro"
        case .watchRelay: return "applewatch"
        case nil: return "questionmark.circle"
        }
    }
}

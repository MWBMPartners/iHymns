// TVPairingOverlayView.swift
// IHFeatures
//
// ELI5: The big full-screen "pair a remote" screen — a giant 6-digit code
// readable from across a room, a QR code to scan instead, the TV's
// fingerprint for a quick human cross-check, and a live "someone's trying
// to pair" indicator, with a Cancel button to back out.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §6.3). Shown by
// `TVRootView` in a `ZStack` layer over the tab content while `coordinator
// .isPairingActive` — this view itself never touches `TVListenerActor`
// directly, only `TVRemoteControlCoordinator`'s already-`@Observable`
// surface (the same "hold the coordinator as a plain property, not
// `@State`" idiom `ProjectionSceneView` already establishes for
// `ProjectionViewModel`).
import IHDesign
import SwiftUI

/// The full-screen pairing ceremony overlay.
public struct TVPairingOverlayView: View {
    let coordinator: TVRemoteControlCoordinator

    public init(coordinator: TVRemoteControlCoordinator) {
        self.coordinator = coordinator
    }

    public var body: some View {
        ZStack {
            Color.black.opacity(0.82).ignoresSafeArea()
            VStack(spacing: 28) {
                Text("Pair a remote")
                    .font(.largeTitle.bold())

                codeText

                qrSection

                fingerprintLine

                if coordinator.pairingRemoteCount > 0 {
                    Text(pairingRemoteCountText)
                        .font(.headline)
                        .foregroundStyle(IHColorTokens.accent)
                }

                if let lastPairedName = coordinator.lastPairedName {
                    Text(lastPairedName.isEmpty ? "A remote just paired." : "Paired: \(lastPairedName)")
                        .font(.headline)
                        .foregroundStyle(.green)
                }

                Button("Cancel") {
                    Task { await coordinator.endPairing() }
                }
                .buttonStyle(.bordered)
            }
            .padding(48)
            .ihGlassCard(cornerRadius: 36)
        }
        .task { await runCountdown() }
    }

    /// A COSMETIC-only countdown — `LANRemotePairingCeremonyState
    /// .isActive(now:)` (`IHLive`) is what actually enforces the 2-minute
    /// TTL regardless of whether this task ever runs; this timer exists
    /// purely so the code shown on screen never visibly goes stale before
    /// the TV would reject it anyway.
    private func runCountdown() async {
        while !Task.isCancelled {
            try? await Task.sleep(for: .seconds(120))
            guard !Task.isCancelled else { return }
            await coordinator.rotateCode()
        }
    }

    /// The giant, hall-readable code — `.kerning` widens the digit spacing
    /// (spec: "readable across a hall"); the whole `Text` is ONE
    /// accessibility element with a digit-by-digit spoken label rather than
    /// VoiceOver reading the six digits as a single six-digit number.
    private var codeText: some View {
        let code = coordinator.pairingCode ?? "------"
        return Text(code)
            .font(.system(size: 120, weight: .bold, design: .monospaced))
            .kerning(20)
            .accessibilityLabel("Pairing code " + code.map(String.init).joined(separator: " "))
    }

    @ViewBuilder
    private var qrSection: some View {
        if let qrImage = PairingQRCode.image(from: coordinator.qrPayloadString(includeCode: true)) {
            VStack(spacing: 8) {
                Image(decorative: qrImage, scale: 1)
                    .interpolation(.none)
                    .resizable()
                    .scaledToFit()
                    .frame(width: 320, height: 320)
                Text("Scan with iHymns on your phone")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
        }
    }

    /// The short verification line — first 12 hex characters, grouped in
    /// 4s, "enough for a human cross-check" (spec §6.3); the FULL
    /// fingerprint lives in Settings (`TVSettingsRemoteView`, which reuses
    /// `PairingFingerprintDisplay.grouped(_:prefixLength:)` below) for
    /// anyone who wants to check every character.
    private var fingerprintLine: some View {
        let fingerprint = coordinator.listenerInfo?.fingerprint
        let text = fingerprint.map { PairingFingerprintDisplay.grouped($0, prefixLength: 12) } ?? "unavailable"
        return Text("Fingerprint: \(text)")
            .font(.footnote.monospaced())
            .foregroundStyle(.secondary)
    }

    private var pairingRemoteCountText: String {
        coordinator.pairingRemoteCount == 1
            ? "1 remote is trying to pair…"
            : "\(coordinator.pairingRemoteCount) remotes are trying to pair…"
    }
}

/// Shared "lowercase hex, grouped in 4s" display formatting — this file's
/// own short verification line AND `TVSettingsRemoteView`'s full-fingerprint
/// row both need EXACTLY this grouping, so it lives here once rather than
/// two near-identical private copies (this repo's modularity rule).
enum PairingFingerprintDisplay {
    /// - Parameter prefixLength: `nil` groups the WHOLE string (Settings'
    ///   full fingerprint); a value truncates first (this file's own
    ///   12-character verification line).
    static func grouped(_ hex: String, prefixLength: Int? = nil) -> String {
        let source = prefixLength.map { String(hex.prefix($0)) } ?? hex
        var groups: [String] = []
        var remainder = Substring(source)
        while !remainder.isEmpty {
            groups.append(String(remainder.prefix(4)))
            remainder = remainder.dropFirst(4)
        }
        return groups.joined(separator: " ")
    }
}

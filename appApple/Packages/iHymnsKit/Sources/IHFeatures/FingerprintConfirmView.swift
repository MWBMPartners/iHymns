// FingerprintConfirmView.swift
// IHFeatures
//
// ELI5: The screen that shows up the FIRST time you connect to a TV by
// typing its address instead of scanning its QR code — it shows a long
// code (the TV's "fingerprint") and asks you to check it matches the same
// code on the TV's own screen before going any further. That's how the app
// makes sure you're really talking to YOUR TV, not something else on the
// network.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §6.3, Decision D-3 —
// the TOFU path's ONE security-critical piece of UI). Full-screen, not a
// sheet (the `.codeEntry` precedent — a modal MOMENT, so Cancel is
// unambiguous), rendered by `RemoteControlView` for `UIPhase
// .confirmingFingerprint`. This screen is REQUIRED on every unknown-TV
// manual connect — there is no code path from
// `.awaitingFingerprintConfirmation` to the code-entry sheet that skips it
// (only the coordinator's known-TV shortcut, which needs no human because
// the pin already decided). Shows the FULL 64-character fingerprint,
// grouped via the shared `LANRemoteFingerprint.displayGrouped(_:every:)`
// (`IHLive`) — never a truncated prefix, which would train partial
// -checking (the TV's own Settings screen shows the full value too, PR-6
// §6.3).
import IHLive
import SwiftUI

/// The TOFU fingerprint-confirm interstitial — see this file's header.
public struct FingerprintConfirmView: View {
    let tvName: String
    let fingerprintHex: String
    let onConfirm: () -> Void
    let onCancel: () -> Void

    public init(tvName: String, fingerprintHex: String, onConfirm: @escaping () -> Void, onCancel: @escaping () -> Void) {
        self.tvName = tvName
        self.fingerprintHex = fingerprintHex
        self.onConfirm = onConfirm
        self.onCancel = onCancel
    }

    public var body: some View {
        VStack(spacing: 24) {
            Spacer()

            Text("Check This Is Your TV")
                .font(.title2.bold())
                .multilineTextAlignment(.center)

            Text(
                "On your TV, open **Settings → Remote Control** and compare this fingerprint. "
                    + "Only continue if it matches — this is how you know you're talking to YOUR TV "
                    + "and not something else on the network."
            )
            .font(.body)
            .foregroundStyle(.secondary)
            .multilineTextAlignment(.center)
            .padding(.horizontal)

            groupedFingerprintText

            Spacer()

            VStack(spacing: 12) {
                Button("It Matches — Continue", action: onConfirm)
                    .buttonStyle(.borderedProminent)
                    .frame(minHeight: 44)

                Button("Cancel", role: .cancel, action: onCancel)
                    .buttonStyle(.bordered)
                    .frame(minHeight: 44)
            }
            .padding(.horizontal)

            Spacer()
        }
        .padding()
        .navigationTitle(tvName)
    }

    /// The full fingerprint, grouped in 4s — ONE accessibility element with
    /// a digit-grouped label, mirroring `TVPairingOverlayView`'s pairing
    /// -code accessibility idiom (spec §6.3). `.textSelection(.enabled)` is
    /// UNAVAILABLE on tvOS AND watchOS (`EnabledTextSelectability` isn't
    /// offered on either) — moot in practice (this screen is only ever
    /// reached from the phone-shell's manual-connect flow, never tvOS's
    /// `TVRootView`, this file's header), but `IHFeatures` still compiles
    /// for every platform (`Package.swift`), so the guard is required for
    /// this FILE to build there at all — the `RemoteControlView.swift`
    /// `.swipeActions`/`.contextMenu` precedent for the identical reason.
    private var groupedFingerprintText: some View {
        let grouped = LANRemoteFingerprint.displayGrouped(fingerprintHex)
        let text = Text(grouped)
            .font(.system(.footnote, design: .monospaced))
            .multilineTextAlignment(.center)
        return text
            #if !os(tvOS) && !os(watchOS)
            .textSelection(.enabled)
            #endif
            .padding()
            .background(.thinMaterial, in: RoundedRectangle(cornerRadius: 12))
            .accessibilityElement(children: .ignore)
            .accessibilityLabel("Fingerprint " + grouped)
    }
}

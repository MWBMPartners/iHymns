// PairingPayloadEntrySheet.swift
// IHFeatures
//
// ELI5: The "how do you want to tell iHymns which TV to pair with" sheet —
// on iPhone/iPad, a big "Scan QR Code" button; on every platform, a place
// to paste the pairing text instead (handy on a Mac, or if AirDrop/Messages
// carried the code over from the TV's screen).
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §4 path B′, §6.3/D-12).
// Whichever way the string arrives, it's handed to `RemoteControlCoordinator
// .handleScannedOrPasted(_:)` unchanged — that's the ONE place
// `LANRemotePairingPayload(qrString:)` gets parsed and turned into a connect
// attempt (spec §4's invariant: never a second, ad-hoc parse site). Scan is
// `#if os(iOS)` only (visionOS has no third-party camera access; macOS
// skips it for ergonomics/sandbox reasons — spec Decision D-12) — paste is
// on every platform, and is the ONLY new-pair path macOS/visionOS have.
#if os(iOS)
import AVFoundation
#endif
import SwiftUI

/// The scan-or-paste chooser (spec §4 path B′).
public struct PairingPayloadEntrySheet: View {
    /// Called with the raw scanned/pasted string — `RemoteControlView` wires
    /// this straight to `coordinator.handleScannedOrPasted(_:)`.
    let onResolved: (String) -> Void
    let onCancel: () -> Void

    @State private var pastedText = ""
    #if os(iOS)
    @State private var isPresentingScanner = false
    #endif

    public init(onResolved: @escaping (String) -> Void, onCancel: @escaping () -> Void) {
        self.onResolved = onResolved
        self.onCancel = onCancel
    }

    public var body: some View {
        NavigationStack {
            Form {
                #if os(iOS)
                Section {
                    Button {
                        Task { await requestCameraAccessThenPresentScanner() }
                    } label: {
                        Label("Scan QR Code", systemImage: "qrcode.viewfinder")
                    }
                } footer: {
                    Text("Scan the QR code shown on your Apple TV's Settings → Remote Control screen.")
                }
                #endif

                Section {
                    TextField("Paste pairing code", text: $pastedText, axis: .vertical)
                        .lineLimit(3...6)
                        #if os(iOS) || os(visionOS)
                        .textInputAutocapitalization(.never)
                        #endif
                        .autocorrectionDisabled()
                } header: {
                    Text("Or paste it")
                } footer: {
                    Text("Paste the pairing text shown under the QR code — for example, if it was sent by AirDrop or Messages.")
                }
            }
            .navigationTitle("Pair with a TV")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel", role: .cancel, action: onCancel)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Connect") { onResolved(pastedText.trimmingCharacters(in: .whitespacesAndNewlines)) }
                        .disabled(pastedText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                }
            }
            #if os(iOS)
            .sheet(isPresented: $isPresentingScanner) {
                PairingScanView { scanned in
                    isPresentingScanner = false
                    onResolved(scanned)
                }
            }
            #endif
        }
    }

    #if os(iOS)
    /// Requests camera access ONLY if it hasn't been decided yet — spec
    /// §4.3: "`.notDetermined` → `requestAccess` on the Scan button tap
    /// (never on screen appear)". Presents `PairingScanView` regardless of
    /// the outcome; that view reads the FINAL authorization status itself
    /// to decide between the live preview and its own denied-guidance card
    /// (spec: "camera denial NEVER dead-ends pairing").
    private func requestCameraAccessThenPresentScanner() async {
        if AVCaptureDevice.authorizationStatus(for: .video) == .notDetermined {
            _ = await AVCaptureDevice.requestAccess(for: .video)
        }
        isPresentingScanner = true
    }
    #endif
}

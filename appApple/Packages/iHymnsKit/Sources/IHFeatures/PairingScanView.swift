// PairingScanView.swift
// IHFeatures
//
// ELI5: The actual camera screen that watches for the TV's pairing QR code
// — as soon as it sees one that really is an iHymns pairing code (not a
// Wi-Fi poster or some other QR someone points it at by mistake), it hands
// the text back and closes itself.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §4.3/§6.4, Decision
// D-12). `#if os(iOS)` ONLY — on every other platform this file compiles to
// NOTHING (visionOS has no third-party main-camera access; macOS skips
// camera scanning for ergonomics + sandbox-entitlement reasons). Adds NO new
// framework class to the app's dependency picture: AVFoundation is already
// imported elsewhere in `IHFeatures` (`SongAudioPlaybackEngine.swift`), and
// this file needs nothing beyond `AVCaptureSession`/`AVCaptureMetadataOutput`/
// `AVCaptureVideoPreviewLayer` — all first-party, no new SwiftPM dependency
// (spec §8 D-15's "no new external package" tripwire).
#if os(iOS)
import AVFoundation
// `LANRemotePairingPayload(qrString:)` (used in `handleScanned` to pre-filter
// non-iHymns QR codes) lives in IHLive — imported explicitly here because Swift
// imports are per-FILE, and this file's whole body is `#if os(iOS)`, so a
// missing import only ever surfaces on an iOS compile (the macOS/tvOS builds
// compile this file to nothing). #1422.
import IHLive
import SwiftUI

/// The QR-scanning camera screen — see this file's header for the full
/// platform story.
public struct PairingScanView: View {
    /// Called EXACTLY ONCE, with the first scanned string that
    /// `LANRemotePairingPayload(qrString:)` (`IHLive`) would accept — the
    /// caller (`PairingPayloadEntrySheet`) re-parses it again itself via
    /// `RemoteControlCoordinator.handleScannedOrPasted(_:)`, this view only
    /// pre-filters obviously-irrelevant QR codes so a stray scan of the
    /// venue's Wi-Fi poster never even reaches the coordinator.
    let onScanned: (String) -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var isAuthorized = AVCaptureDevice.authorizationStatus(for: .video) == .authorized

    public init(onScanned: @escaping (String) -> Void) {
        self.onScanned = onScanned
    }

    public var body: some View {
        ZStack {
            if isAuthorized {
                CameraPreview(onScanned: handleScanned)
                    .ignoresSafeArea()
            } else {
                deniedView
            }
        }
        .overlay(alignment: .topTrailing) {
            Button {
                dismiss()
            } label: {
                Image(systemName: "xmark.circle.fill")
                    .font(.title)
                    .foregroundStyle(.white, .black.opacity(0.5))
            }
            .padding()
            .accessibilityLabel("Close scanner")
        }
        .task {
            // Re-check on appear — the tap that presented this screen
            // already ran `requestAccess` if needed (spec §4.3: "request on
            // the Scan button tap, never on screen appear"); this just picks
            // up whatever the OS decided.
            isAuthorized = AVCaptureDevice.authorizationStatus(for: .video) == .authorized
        }
    }

    private func handleScanned(_ string: String) {
        guard LANRemotePairingPayload(qrString: string) != nil else {
            // Not an iHymns pairing code — ignored silently (spec §4.3:
            // "people WILL scan the venue Wi-Fi poster by mistake").
            return
        }
        onScanned(string)
    }

    /// Camera access was denied/restricted — pairing is never dead-ended by
    /// this (spec §4.3, Decision D-12): dismissing returns to
    /// `PairingPayloadEntrySheet`, where the paste field is always present.
    private var deniedView: some View {
        VStack(spacing: 16) {
            Image(systemName: "camera.fill")
                .font(.largeTitle)
                .foregroundStyle(.secondary)
            Text("Camera access is off")
                .font(.headline)
            Text("Allow camera access in Settings, or go back and paste the pairing code instead.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 32)
            Button("Close") { dismiss() }
                .buttonStyle(.bordered)
        }
        .padding()
    }
}

/// `UIViewRepresentable` wrapping an `AVCaptureSession` + QR metadata output
/// + `AVCaptureVideoPreviewLayer` — the plain, no-dependency way to put a
/// live camera feed with QR detection on screen.
private struct CameraPreview: UIViewRepresentable {
    let onScanned: (String) -> Void

    func makeCoordinator() -> Coordinator {
        Coordinator(onScanned: onScanned)
    }

    func makeUIView(context: Context) -> PreviewUIView {
        let view = PreviewUIView()
        context.coordinator.configure(previewView: view)
        return view
    }

    func updateUIView(_ uiView: PreviewUIView, context: Context) {}

    static func dismantleUIView(_ uiView: PreviewUIView, coordinator: Coordinator) {
        coordinator.stop()
    }

    /// Owns the capture session and starts/stops it on a background
    /// (utility) queue — Apple's own guidance: never start/stop
    /// `AVCaptureSession` on the main thread.
    ///
    /// ELI5: this class touches a camera from more than one thread, so we
    /// have to PROVE to Swift 6 that it's doing so safely — `@unchecked
    /// Sendable` is that proof, backed by the discipline documented below,
    /// not a way of skipping the check.
    ///
    /// DETAILED (#1422 review fix 5): under Swift 6 strict concurrency,
    /// `DispatchQueue.async(execute:)` takes an `@escaping @Sendable`
    /// closure — capturing `self` (a plain `NSObject` subclass, not
    /// `Sendable`) in either `sessionQueue.async { [weak self] … }` below
    /// (`configure(previewView:)`'s `startSession()` kickoff, `stop()`'s
    /// `stopRunning()`) produced a "capture of non-Sendable type in a
    /// `@Sendable` closure" warning. The underlying design was already
    /// safe, just not provably so to the compiler: EVERY mutable property
    /// here (`session`'s own configuration/state, `hasDelivered`) is
    /// touched ONLY from `sessionQueue` — a dedicated serial queue — after
    /// construction; `session` itself is a `let` (the reference never
    /// changes), and the ONE MainActor touch, `configure(previewView:)`'s
    /// `previewView.videoPreviewLayer.session = session`, only hands that
    /// same immutable reference to the preview layer (assigning
    /// `AVCaptureVideoPreviewLayer.session` from the main thread while
    /// `startRunning()`/`stopRunning()`/`addInput`/`addOutput` run on a
    /// background queue is Apple's own documented, blessed pattern — no
    /// property of THIS type is ever read or written from two different
    /// queues at once, which is exactly what `Sendable` verification exists
    /// to prove and exactly what the compiler cannot see through a
    /// hand-rolled `DispatchQueue` confinement discipline). Hence
    /// `@unchecked` — this silences the warnings without changing any
    /// runtime behaviour; a future change that starts mutating `session`'s
    /// configuration or `hasDelivered` from anywhere OTHER than
    /// `sessionQueue` would break the discipline this comment documents.
    final class Coordinator: NSObject, AVCaptureMetadataOutputObjectsDelegate, @unchecked Sendable {
        private let onScanned: (String) -> Void
        private let session = AVCaptureSession()
        private let sessionQueue = DispatchQueue(label: "app.ihymns.lanremote.scan", qos: .userInitiated)
        private var hasDelivered = false

        init(onScanned: @escaping (String) -> Void) {
            self.onScanned = onScanned
        }

        func configure(previewView: PreviewUIView) {
            previewView.videoPreviewLayer.session = session
            sessionQueue.async { [weak self] in
                self?.startSession()
            }
        }

        private func startSession() {
            guard let device = AVCaptureDevice.default(for: .video),
                  let input = try? AVCaptureDeviceInput(device: device),
                  session.canAddInput(input) else { return }
            session.addInput(input)

            let output = AVCaptureMetadataOutput()
            guard session.canAddOutput(output) else { return }
            session.addOutput(output)
            output.setMetadataObjectsDelegate(self, queue: sessionQueue)
            output.metadataObjectTypes = [.qr]

            session.startRunning()
        }

        func stop() {
            sessionQueue.async { [weak self] in
                self?.session.stopRunning()
            }
        }

        func metadataOutput(
            _ output: AVCaptureMetadataOutput, didOutput metadataObjects: [AVMetadataObject], from connection: AVCaptureConnection
        ) {
            guard !hasDelivered,
                  let match = metadataObjects.first as? AVMetadataMachineReadableCodeObject,
                  match.type == .qr,
                  let string = match.stringValue else { return }
            hasDelivered = true
            DispatchQueue.main.async { [onScanned] in
                onScanned(string)
            }
        }
    }

    /// A plain `UIView` whose backing layer IS the preview layer — the
    /// standard `AVCaptureVideoPreviewLayer` hosting idiom.
    final class PreviewUIView: UIView {
        override static var layerClass: AnyClass { AVCaptureVideoPreviewLayer.self }
        var videoPreviewLayer: AVCaptureVideoPreviewLayer {
            // swiftlint:disable:next force_cast
            layer as! AVCaptureVideoPreviewLayer
        }
    }
}
#endif

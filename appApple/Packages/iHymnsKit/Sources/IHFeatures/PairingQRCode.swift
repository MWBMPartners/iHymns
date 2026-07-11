// PairingQRCode.swift
// IHFeatures
//
// ELI5: Turns a short text string (the pairing payload) into a QR-code
// picture a phone's camera can scan — using ONLY the drawing tools already
// built into every Apple platform, no extra library to pull in.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §6.3/§6.4, Decision
// D-9). `CIFilter.qrCodeGenerator()` (CoreImage's built-in QR encoder,
// https://developer.apple.com/documentation/coreimage/ciqrcodegenerator) is
// the ENTIRE implementation — no vendored/external QR-generation package,
// matching this PR's "no new external package" scope tripwire (spec §8
// D-11). `#if canImport(CoreImage)` guards the whole file's real body:
// `IHFeatures` compiles for watchOS too (Package.swift's platform list),
// and CoreImage is not available there — the fallback is a clean `nil`
// (never a crash), which `TVPairingOverlayView`/`TVSettingsRemoteView`
// handle by falling back to code/fingerprint TEXT only (spec: "views show
// code/fingerprint text only").
import CoreGraphics
#if canImport(CoreImage)
import CoreImage
import CoreImage.CIFilterBuiltins
#endif

/// Renders a pairing payload string as a scannable QR code image.
///
/// ELI5: "Turn this text into a QR code picture."
enum PairingQRCode {
    /// - Parameters:
    ///   - string: The exact payload to encode (`LANRemotePairingPayload
    ///     .encodeToQRString()`'s output) — `nil` input (e.g. DER encoding
    ///     somehow failed) is handled the same as any other failure: a
    ///     clean `nil` result, never a crash.
    ///   - scale: How much to enlarge CoreImage's native (very small,
    ///     one-point-per-module) QR bitmap before rasterizing — the
    ///     default renders crisply at the overlay's ~320pt display size
    ///     without any blurry upscaling later.
    static func image(from string: String?, scale: CGFloat = 10) -> CGImage? {
        #if canImport(CoreImage)
        guard let string, let data = string.data(using: .utf8) else { return nil }

        let filter = CIFilter.qrCodeGenerator()
        filter.message = data
        // "M" (~15% error correction) — comfortably scannable even with a
        // little glare/off-angle on a projector-adjacent screen, without
        // the visual density of "H".
        filter.correctionLevel = "M"

        guard let outputImage = filter.outputImage else { return nil }
        let scaled = outputImage.transformed(by: CGAffineTransform(scaleX: scale, y: scale))

        let context = CIContext()
        return context.createCGImage(scaled, from: scaled.extent)
        #else
        return nil
        #endif
    }
}

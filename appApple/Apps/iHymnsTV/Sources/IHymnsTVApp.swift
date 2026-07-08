// IHymnsTVApp.swift
// iHymnsTV (tvOS shell)
//
// ELI5: What the Apple TV app shows the moment it launches.
//
// DETAILED: A SEPARATE Xcode target from `iHymns` (not a `supportedDestinations`
// entry alongside iOS/macOS/visionOS) even though it shares the exact same
// bundle id `app.ihymns` — strategy §1.1's deliberate split: tvOS needs its
// own asset catalog (Top Shelf images, focus-engine-tuned imagery) and a
// "focus-first, full-bleed" scene (strategy §2.2's projector role) that
// doesn't belong mixed into the touch/pointer-driven multiplatform target.
// Sharing the bundle id is what makes this ONE universal-purchase App
// Store listing rather than a second, separate app (strategy §1.1/§3.1).
//
// Named `IHymnsTVApp` (capital "I") to satisfy SwiftLint's `type_name`
// rule and stay consistent with the other shells — see `IHymnsApp.swift`'s
// header comment for the full rationale.
//
// #1412 UPDATE — this shell still renders only `PhaseZeroSkeletonView` (no
// lyric text yet), but it already depends on `IHDesign` here, so
// `IHFonts.registerBundledFonts()` is wired in now (a trivial `init()`
// call) rather than left as a follow-up someone has to remember when the
// real tvOS lyric-projection screen (strategy §2.2) eventually lands — see
// `IHymnsApp.swift`'s matching `init()` for the full rationale.
import IHDesign
import IHFeatures
import SwiftUI

@main
struct IHymnsTVApp: App {
    init() {
        IHFonts.registerBundledFonts()
    }

    var body: some Scene {
        WindowGroup {
            NavigationStack {
                PhaseZeroSkeletonView(shellName: "tvOS")
            }
        }
    }
}

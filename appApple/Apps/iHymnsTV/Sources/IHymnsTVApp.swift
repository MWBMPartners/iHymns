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
import IHDesign
import IHFeatures
import SwiftUI

@main
struct IHymnsTVApp: App {
    var body: some Scene {
        WindowGroup {
            NavigationStack {
                PhaseZeroSkeletonView(shellName: "tvOS")
            }
        }
    }
}

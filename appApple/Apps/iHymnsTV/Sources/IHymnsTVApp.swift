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
// #1412 UPDATE — `IHFonts.registerBundledFonts()` is wired in from `init()`
// (a trivial call) rather than left as a follow-up someone has to remember
// when the real tvOS lyric-projection screen eventually lands — see
// `IHymnsApp.swift`'s matching `init()` for the full rationale.
//
// #1504 UPDATE (Apple Phase-2 PR-5, `.claude/apple-phase2-pr5-spec.md`
// §4.4) — that "eventually" is now: the `PhaseZeroSkeletonView` placeholder
// is replaced with `TVRootView`, the real projection shell (strategy §2.2's
// Project/Songbooks/Search tab set). Builds the ONE `AppRootViewModel` this
// app run uses via `AppRootViewModel.makeLive(environment:)`, copying
// `IHymnsApp.swift`'s EXACT environment expression
// (`IHSettingsStore().apiEnvironmentOverride ?? .defaultForBuild`) rather
// than re-deriving it, so a Debug tvOS build and a Debug iOS/Mac build
// resolve to the identical default (`.defaultForBuild`) and both honour
// the SAME Settings → Developer override the moment tvOS grows a Settings
// screen (PR-6+).
import IHAPI
import IHDesign
import IHFeatures
import SwiftUI

@main
struct IHymnsTVApp: App {
    init() {
        IHFonts.registerBundledFonts()
    }

    /// Built once via `@State`'s default-value expression, exactly like
    /// `IHymnsApp.swift`'s own `rootViewModel` — see that file's header for
    /// why the environment is read once at launch, not re-checked live.
    @State private var rootViewModel = AppRootViewModel.makeLive(
        environment: IHSettingsStore().apiEnvironmentOverride ?? .defaultForBuild
    )

    var body: some Scene {
        WindowGroup {
            TVRootView(viewModel: rootViewModel)
        }
    }
}

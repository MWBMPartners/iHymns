// IHymnsApp.swift
// iHymns (iOS / iPadOS / macOS / visionOS shell)
//
// ELI5: The actual starting point of the app on iPhone, iPad, Mac, and
// Vision Pro — this is what iOS/macOS/visionOS launches first.
//
// DETAILED: One multiplatform target (`supportedDestinations: [iOS, macOS,
// visionOS]`, `project.yml`) — NOT three near-duplicate shells — per
// strategy §1.1/§2.2's "same codepath" principle: iPad is just iOS in a
// bigger window, macOS runs the SAME SwiftUI scene graph natively (no
// Catalyst), and visionOS composes the same content in a glass window.
// This file is intentionally tiny: it composes `IHFeatures`/`IHDesign`
// (Phase 0's `PhaseZeroSkeletonView`) inside a `NavigationStack` — real
// per-platform chrome (iPhone's Liquid Glass `TabView`, iPad/Mac/vision's
// `NavigationSplitView`, strategy §2.2) lands in Phase 1 once there are
// actual screens to navigate between. The per-shell `@main App` is
// EXACTLY the kind of "platform-unique surface" the repo's modularity rule
// (`.claude/CLAUDE.md`) says belongs in the shell, not the Kit: scene
// topology and app lifecycle are shell concerns; everything else
// (`PhaseZeroSkeletonView` itself) is shared.
//
// Named `IHymnsApp` (capital "I," matching `IHModels`/`IHAPI`/etc.'s "IH"
// prefix convention) rather than the more product-name-literal
// `iHymnsApp` — SwiftLint's `type_name` rule requires an uppercase first
// character, and this keeps every shell's `@main` type consistent with
// `IHymnsWidgetsBundle` rather than carving out a per-file lint exception.
import IHDesign
import IHFeatures
import SwiftUI

@main
struct IHymnsApp: App {
    var body: some Scene {
        WindowGroup {
            NavigationStack {
                PhaseZeroSkeletonView(shellName: "iOS / iPadOS / macOS / visionOS")
            }
        }
    }
}

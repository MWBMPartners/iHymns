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
// The per-shell `@main App` is EXACTLY the kind of "platform-unique
// surface" the repo's modularity rule (`.claude/CLAUDE.md`) says belongs in
// the shell, not the Kit: scene topology, app lifecycle, and building the
// one real `AppRootViewModel` instance this run of the app uses are shell
// concerns; everything else (`RootContainerView`, `CatalogueListView`,
// `SongDetailView`, ...) is shared.
//
// #1399 UPDATE — the Phase-0 `PhaseZeroSkeletonView` placeholder is
// replaced by the real E2E slice: `RootContainerView` picks
// `NavigationStack` vs `NavigationSplitView` per platform/size class and
// hosts the real, live-API-backed `CatalogueListView` → `SongDetailView`
// flow. `AppRootViewModel.makeLive(environment:)` (IHFeatures, #1399)
// points at `.dev` for now — strategy §3.1's TestFlight/env mapping
// (Debug→dev, Internal TF→beta, External TF/App Store→prod) is a Phase-1
// concern once there's a build-configuration-aware picker to wire it to.
//
// Named `IHymnsApp` (capital "I," matching `IHModels`/`IHAPI`/etc.'s "IH"
// prefix convention) rather than the more product-name-literal
// `iHymnsApp` — SwiftLint's `type_name` rule requires an uppercase first
// character, and this keeps every shell's `@main` type consistent with
// `IHymnsWidgetsBundle` rather than carving out a per-file lint exception.
import IHAPI
import IHFeatures
import SwiftUI

@main
struct IHymnsApp: App {
    /// The one `AppRootViewModel` this app run uses — built once via
    /// `@State`'s default-value expression (evaluated exactly once, at
    /// scene creation) and handed down to `RootContainerView`, which
    /// composes every screen from it.
    @State private var rootViewModel = AppRootViewModel.makeLive(environment: .dev)

    var body: some Scene {
        WindowGroup {
            RootContainerView(viewModel: rootViewModel)
        }
    }
}

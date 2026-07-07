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
//
// #186 UPDATE (Apple Phase 1, "Sharing & social") — wires Universal Links
// (`.onOpenURL`) and Handoff continuations (`.onContinueUserActivity`)
// straight to `DeepLinkRouter.resolve(_:)` (`IHAppSupport`) and hands the
// result to `RootContainerView`'s `incomingDeepLink` binding. This file
// stays PURE WIRING per the repo's modularity rule — it calls the router,
// it never re-implements URL-shape parsing itself. The one decision made
// HERE (not in the router, which stays a pure `URL → DeepLink?` function)
// is what to do with an unresolved result: `DeepLinkRouter` returns `nil`
// for a `.work` link (no native screen exists yet, see `DeepLink.swift`'s
// header) and for anything the AASA claims but this Phase 1 doesn't route
// yet (`/person/*`, `/live/*`) — in BOTH cases the honest, non-broken
// behaviour is to hand the URL to the system browser (`openURL`) rather
// than silently doing nothing or presenting an empty screen, exactly
// mirroring "Universal Links → open in app if installed, else the PWA"
// when the app IS installed but doesn't (yet) have a screen for this
// specific claimed path.
import IHAPI
import IHAppSupport
import IHFeatures
import SwiftUI

@main
struct IHymnsApp: App {
    /// The one `AppRootViewModel` this app run uses — built once via
    /// `@State`'s default-value expression (evaluated exactly once, at
    /// scene creation) and handed down to `RootContainerView`, which
    /// composes every screen from it.
    ///
    /// #182 UPDATE — the environment is no longer hard-coded to `.dev`: it
    /// reads the user's persisted `IHSettingsStore.apiEnvironmentOverride`
    /// (set from Settings → Developer, `DEBUG`-only) and falls back to
    /// `APIEnvironment.defaultForBuild` (Debug→dev, Release→prod). Read once
    /// at launch because `APIClient` is an immutable-once-built `actor`
    /// (`APIEnvironment` is a `let` on it) — changing the override takes
    /// effect on the NEXT launch, exactly as `IHSettingsStore
    /// .apiEnvironmentOverride`'s own doc comment promises the user.
    @State private var rootViewModel = AppRootViewModel.makeLive(
        environment: IHSettingsStore().apiEnvironmentOverride ?? .defaultForBuild
    )

    /// The most recently resolved inbound deep link — `nil` bound directly
    /// to `RootContainerView.incomingDeepLink`, which presents it and
    /// resets this back to `nil` once consumed (see that type's own doc
    /// comment on why: so tapping the same link twice still re-presents).
    @State private var incomingDeepLink: DeepLink?

    /// Lets `handle(url:)` hand an unresolved/undeep-linkable URL to the
    /// system browser instead of the app silently swallowing it.
    @Environment(\.openURL) private var openURL

    var body: some Scene {
        WindowGroup {
            // `.onOpenURL`/`.onContinueUserActivity` are `View` modifiers
            // (not `Scene` ones) — attached to the content view itself
            // rather than chained after `WindowGroup`, matching Apple's own
            // documented placement for both.
            RootContainerView(viewModel: rootViewModel, incomingDeepLink: $incomingDeepLink)
                .onOpenURL { url in
                    handle(url: url)
                }
                .onContinueUserActivity(NSUserActivityTypeBrowsingWeb) { activity in
                    guard let url = activity.webpageURL else { return }
                    handle(url: url)
                }
        }
    }

    /// Resolves `url` through the router and either presents it in-app
    /// (`incomingDeepLink`) or falls back to the system browser — see this
    /// file's header for why `.work` and any AASA-claimed-but-unrouted
    /// shape take the browser path even though the app itself handled the
    /// tap.
    private func handle(url: URL) {
        guard let link = DeepLinkRouter.resolve(url), !requiresBrowserFallback(link) else {
            openURL(url)
            return
        }
        incomingDeepLink = link
    }

    private func requiresBrowserFallback(_ link: DeepLink) -> Bool {
        if case .work = link { return true }
        return false
    }
}

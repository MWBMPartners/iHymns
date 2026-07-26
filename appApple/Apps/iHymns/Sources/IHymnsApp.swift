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
// is what to do with an unresolved result — `DeepLinkRouter` returns `nil`
// for anything the AASA claims but has no native screen yet (`/live/*` —
// Apple native app epic #895 Phase 2) — the honest, non-broken behaviour is
// to hand the URL to the system browser (`openURL`) rather than silently
// doing nothing or presenting an empty screen, exactly mirroring "Universal
// Links → open in app if installed, else the PWA" when the app IS installed
// but doesn't (yet) have a screen for this specific claimed path.
//
// #1443 UPDATE — every `DeepLink` case `DeepLinkRouter.resolve(_:)` can
// return (`.song`/`.songbook`/`.songbooksList`/`.setlistShare`/`.work`/
// `.person`) now has a real native screen (`DeepLinkDestinationView.swift`'s
// switch), so `requiresBrowserFallback(_:)` — which used to special-case
// `.work` into the browser — is removed entirely; `handle(url:)` now only
// ever falls back to the browser when the router itself returns `nil`
// (i.e. `/live/*`, or a host/path shape it doesn't recognise at all).
//
// #185 UPDATE (Apple Phase 1, navigation & UX consolidation) — owns the one
// `AppNavigationState` this run of the app uses (mirrors how it already
// owns the one `AppRootViewModel`), hands it down to `RootContainerView`,
// and — on macOS only — adds a `.commands` menu-bar block: ⌘1…⌘7 jump
// straight to a section, ⌘F jumps to Search ("Find," the conventional Mac
// verb for "take me to the search field"), and ⌘/ opens the Keyboard
// Shortcuts help sheet. Guarded `#if os(macOS)` per this task's own
// instruction — this is explicitly a MAC MENU BAR feature (iPad/Mac
// in-context shortcuts like `SongPagerView`'s ← → Previous/Next buttons
// live as plain `.keyboardShortcut(_:)` on real, visible controls instead,
// which works everywhere without needing Scene-level `Commands` at all).
//
// #1412 UPDATE — this is the SHELL that renders lyrics + the dyslexia-mode
// toggle, so it's the one place that MUST call
// `IHFonts.registerBundledFonts()` (`IHDesign`) before any lyric view can
// resolve the bundled OpenDyslexic OTFs by PostScript name — see that
// function's own doc comment for why a package resource bundle needs this
// explicit runtime step. Called from `init()` (the earliest point this
// shell runs its own code, before `body` builds any scene/view) so it has
// already happened by the time the first `Font.custom(...)` is resolved.
//
// #1415 UPDATE (App Intents / Siri / Shortcuts / Spotlight,
// `.claude/apple-native-strategy.md` §2.3#4) — `rootViewModel`/
// `navigationState` move from `@State`'s own default-value-expression
// initializers into `init()` itself (`State(initialValue:)`), so `init()`
// can register the EXACT SAME two instances with `AppDependencyManager
// .shared.add(dependency:)` — every App Intent's `@Dependency` then
// resolves to the SAME `AppRootViewModel`/`AppNavigationState` SwiftUI is
// rendering with, never a second, disconnected copy. Also adds two more
// `View` modifiers to `body`: `.onContinueUserActivity(CSSearchableItemActionType)`
// (a tapped Spotlight result) and `.onChange(of: navigationState.pendingDeepLink)`
// (an App Intent's `perform()` requesting navigation) — both funnel into the
// SAME `handle(url:)`/`incomingDeepLink` pipeline a tapped Universal Link
// already uses.
import AppIntents
import CoreSpotlight
import IHAPI
import IHAppSupport
import IHDesign
import IHFeatures
import SwiftUI

@main
struct IHymnsApp: App {
    /// Builds BOTH the one `AppRootViewModel` and the one
    /// `AppNavigationState` this run of the app uses, THEN registers both
    /// with `AppDependencyManager` (#1415) so every App Intent's
    /// `@Dependency` resolves to these SAME instances — see this file's
    /// header, and `rootViewModel`/`navigationState`'s own doc comments
    /// below, for why this replaced two separate `@State` default-value
    /// expressions.
    init() {
        IHFonts.registerBundledFonts()
        // #1423 — pocket-control: installs the WCSession delegate + builds
        // the headless relay driver on EVERY launch path, including a
        // watch-triggered BACKGROUND launch (where scene bodies/`.task`
        // may never run) — called from `init`, not `.task`, because `App`
        // is itself `@MainActor` so this call is isolation-correct, and a
        // background launch needs the delegate installed before anything
        // else runs. Runtime no-op on iPad/Mac/visionOS
        // (`WCSession.isSupported() == false`, `PhoneWatchRelayService`'s
        // own doc comment).
        #if os(iOS)
        PhoneWatchRelayService.shared.activate()
        #endif
        let model = AppRootViewModel.makeLive(
            environment: IHSettingsStore().apiEnvironmentOverride ?? .defaultForBuild
        )
        let navigation = AppNavigationState()
        _rootViewModel = State(initialValue: model)
        _navigationState = State(initialValue: navigation)
        AppDependencyManager.shared.add(dependency: model)
        AppDependencyManager.shared.add(dependency: navigation)
    }

    /// The one `AppRootViewModel` this app run uses — built in `init()`
    /// above (not this property's own default-value expression, #1415) so
    /// `init()` can hand `AppDependencyManager` the EXACT SAME instance
    /// SwiftUI ends up rendering with. #182's environment-override
    /// reasoning (dev/beta/prod, `apiEnvironmentOverride`) is unchanged.
    @State private var rootViewModel: AppRootViewModel

    /// The most recently resolved inbound deep link — `nil` bound directly
    /// to `RootContainerView.incomingDeepLink`, which presents it and
    /// resets this back to `nil` once consumed (see that type's own doc
    /// comment on why: so tapping the same link twice still re-presents).
    @State private var incomingDeepLink: DeepLink?

    /// #185 — the one `AppNavigationState` this app run uses, mirroring how
    /// `rootViewModel` above is the one `AppRootViewModel`; also built in
    /// `init()` now (#1415), for the identical `AppDependencyManager`
    /// reason `rootViewModel` documents. Handed down to `RootContainerView`
    /// AND read/written by this file's own `.commands` block below, so a
    /// Mac menu click and a sidebar/tab tap change the exact same state.
    @State private var navigationState: AppNavigationState

    /// Lets `handle(url:)` hand an unresolved/undeep-linkable URL to the
    /// system browser instead of the app silently swallowing it.
    @Environment(\.openURL) private var openURL

    var body: some Scene {
        WindowGroup {
            // `.onOpenURL`/`.onContinueUserActivity` are `View` modifiers
            // (not `Scene` ones) — attached to the content view itself
            // rather than chained after `WindowGroup`, matching Apple's own
            // documented placement for both.
            RootContainerView(viewModel: rootViewModel, navigationState: navigationState, incomingDeepLink: $incomingDeepLink)
                .onOpenURL { url in
                    handle(url: url)
                }
                .onContinueUserActivity(NSUserActivityTypeBrowsingWeb) { activity in
                    guard let url = activity.webpageURL else { return }
                    handle(url: url)
                }
                // #1415 — a tapped Spotlight search result hands back its
                // `uniqueIdentifier` via this SAME `NSUserActivity`
                // mechanism (`CSSearchableItemActionType`);
                // `SpotlightIndexer.tapURL(fromActivityUserInfo:)`
                // (`IHAppSupport`) recovers the original song URL, which
                // then flows through the EXACT SAME `handle(url:)` a
                // Universal Link tap already uses.
                .onContinueUserActivity(CSSearchableItemActionType) { activity in
                    guard let url = SpotlightIndexer.tapURL(fromActivityUserInfo: activity.userInfo) else { return }
                    handle(url: url)
                }
                // #1415 — an App Intent (running in-process, possibly
                // before any UI was ever shown) sets `AppNavigationState
                // .pendingDeepLink` as its ONE way to say "go here"; this
                // mirrors that into the SAME `incomingDeepLink`
                // presentation pipeline a tapped Universal Link/Spotlight
                // result already uses, then clears it so running the SAME
                // intent twice still re-presents (the identical reset
                // `RootContainerView` already applies to `incomingDeepLink`
                // itself).
                .onChange(of: navigationState.pendingDeepLink) { _, newValue in
                    guard let newValue else { return }
                    incomingDeepLink = newValue
                    navigationState.pendingDeepLink = nil
                }
        }
        #if os(macOS)
        .commands {
            // #185 — "Go" menu: ⌘1…⌘7 jump straight to a top-level section,
            // mirroring `RootSection.keyboardShortcutDigit`'s numbering so
            // the menu and this list can never silently drift apart.
            CommandMenu("Go") {
                sectionCommand(.home)
                sectionCommand(.songbooks)
                sectionCommand(.search)
                sectionCommand(.favorites)
                sectionCommand(.setlists)
                sectionCommand(.live)
                sectionCommand(.settings)
                Divider()
                // "Find" is the conventional Mac verb for "take me to the
                // search field" (Safari, Mail, Xcode all use ⌘F for their
                // own in-window find/search) — here it means "switch to the
                // Search section," the closest equivalent this app has.
                Button("Find") { navigationState.selectedSection = .search }
                    .keyboardShortcut("f", modifiers: .command)
            }
            // `.after(.help)` APPENDS to the existing Help menu rather than
            // replacing it (`.replacing(.help)` would drop the system's own
            // "iHymns Help" item) — see `KeyboardShortcutsOverlayView.swift`
            // for why ⌘/ rather than a bare "?" (typing a literal "?" into
            // the search field must never accidentally open this sheet).
            CommandGroup(after: .help) {
                Button("Keyboard Shortcuts") { navigationState.isPresentingKeyboardShortcutsHelp = true }
                    .keyboardShortcut("/", modifiers: .command)
            }
        }
        #endif
    }

    #if os(macOS)
    /// One "Go" menu row for `section` — labelled with its title, jumping
    /// `navigationState.selectedSection` to it, shortcut ⌘ + its
    /// `keyboardShortcutDigit`. A plain `View`-returning helper (not a
    /// `Commands`-returning one) because `CommandMenu`'s own content
    /// closure is built with the ordinary `@ViewBuilder` (`Button`/
    /// `Divider`/`Menu`), exactly like any other menu's contents — only the
    /// OUTER `.commands { }` scene modifier itself uses `@CommandsBuilder`.
    @ViewBuilder
    private func sectionCommand(_ section: RootSection) -> some View {
        Button(section.title) { navigationState.selectedSection = section }
            .keyboardShortcut(KeyEquivalent(section.keyboardShortcutDigit), modifiers: .command)
    }
    #endif

    /// Resolves `url` through the router and either presents it in-app
    /// (`incomingDeepLink`) or falls back to the system browser — see this
    /// file's header for why any AASA-claimed-but-unrouted shape (`/live/*`
    /// today) takes the browser path even though the app itself handled the
    /// tap.
    private func handle(url: URL) {
        guard let link = DeepLinkRouter.resolve(url) else {
            openURL(url)
            return
        }
        incomingDeepLink = link
    }
}

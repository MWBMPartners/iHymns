// RootContainerView.swift
// IHFeatures
//
// ELI5: Decides "does this window look like a phone (one screen at a time)
// or like an iPad/Mac/Vision (a list + detail side-by-side)?" and builds
// the right container around the shared `HomeView`/`SongbooksView`/
// `CatalogueListView`/`FavoritesView`/`AccountView` screens either way — so
// the app shell itself doesn't have to know the difference. Also gives the
// user a way to REACH every top-level section: a tab on iPhone, a sidebar
// section on iPad/Mac/vision. Also where the app-launch "were we already
// signed in?" restore fires (native login/account UI task, completing
// #1398's noted gap — see `.task` in `body` below).
//
// DETAILED: Strategy §2.2's "same codepath" principle, extended first by
// #1437 (Songbooks), then #183's Home-surface brief: "Rework the app
// shell: give RootContainerView a real tab/section structure — Home ·
// Songbooks · Search... The current catalogue list becomes the 'Search' (or
// 'Browse') destination." `NavigationLink(value:)` + `.navigationDestination(for:)`
// (declared on `HomeView`/`CatalogueListView`/`SongbooksView` themselves)
// work identically whether the hosting view is the root of its OWN
// `NavigationStack` (one per iPhone tab) or the CONTENT column of a
// 3-column `NavigationSplitView` (Apple's WWDC22 "The SwiftUI cookbook for
// navigation": pushing a destination from the content column lands it in
// the DETAIL column) — so this file's ENTIRE job is picking which
// container/section to show, never making a navigation decision of its
// own.
//
// #181 UPDATE (native login/account UI + favourites task) adds two more
// sections — `.favorites` (this task's explicit "surface it in the shell"
// instruction) and `.account` (this task's "An Account surface... in
// Settings or its own" — given this app has no separate Settings screen
// yet, its own top-level section is the more discoverable choice) — using
// the SAME tab-on-iPhone/sidebar-row-elsewhere shape every other section
// already has, so neither needed any container-picking logic of its own.
//
// #182 UPDATE — now that a real Settings screen exists, the standalone
// `.account` section became `.settings` (hosting `SettingsView`), and the
// account UI moved to a row INSIDE it (Settings → Account → `AccountView`).
// This is the conventional iOS "Apple ID at the top of Settings" shape and
// keeps the compact `TabView` at 7 tabs rather than overflowing into a
// system "More" tab. `RootContainerView` also now owns the one
// `SettingsViewModel` and applies its `theme` as `.preferredColorScheme` on
// the whole app.
//
// `.live` WAS a DELIBERATE, honestly-labelled "coming soon" placeholder
// (this task's own brief: "a Live tab/Setlists can be a labelled 'coming
// soon' placeholder for now, don't fake them") — no `IHLive` engine was
// wired to any UI yet in this package, so pretending there was a real Live
// screen here would have been exactly the kind of faked surface this task
// explicitly ruled out.
//
// #181 UPDATE (setlists half) — `.setlists` is now a REAL section
// (`SetlistsView`), split out of what was previously a combined "Live &
// Setlists" placeholder: setlists are fully built (create/rename/delete,
// reorder, add/remove songs, live-share), so continuing to lump them in
// with the still-unbuilt Live placeholder would now be the dishonest
// surface this file's own header warns against. `.live` alone keeps the
// placeholder, relabelled to just "Live."
//
// #1422 UPDATE (Apple Phase-2 PR-7, `.claude/apple-phase2-pr7-spec.md`
// §6.1/D-6) — `.live` is a placeholder no longer: it now hosts
// `LiveHubView`, whose real "TV Remote" card leads to the full LAN
// remote-control screens (`RemoteControlView`/`RemoteControlSurfaceView`).
// `LiveHubView` itself RETAINS the honest "Live Follow & Service Mode —
// coming in a future update" `ContentUnavailableView` copy for the
// server-mediated half that's still unbuilt — this file's own "don't fake
// a screen" rule is satisfied, not violated, by the split: the half that's
// real is real, the half that isn't still says so.
//
// Compiles on every platform `IHFeatures` targets (`Package.swift`'s
// `platforms:` list includes tvOS/watchOS too) even though only the
// iOS/iPadOS/macOS/visionOS `IHymns` app shell (`Apps/iHymns/`) actually
// instantiates this type today — tvOS/watch keep `PhaseZeroSkeletonView`
// per this task's explicit scope ("Keep the tvOS/watch shells compiling").
//
// #186 UPDATE (Apple Phase 1, "Sharing & social") — adds `incomingDeepLink`,
// a `Binding<DeepLink?>` `IHymnsApp.swift` sets from its `.onOpenURL`/
// `.onContinueUserActivity` handlers. Presented as its OWN `.sheet(item:)`
// (see `DeepLinkDestinationView.swift`'s header for why a sheet rather than
// trying to programmatically drive one of the seven independent per-section
// `NavigationStack`s below) — defaults to `.constant(nil)` so every existing
// `RootContainerView(viewModel:)` call site (incl. tvOS/watchOS's own
// `#else` branch, which never receives a real deep link) keeps compiling
// unchanged.
//
// #1441 UPDATE — owns the ONE `NowPlayingViewModel` (mirrors
// `settingsViewModel` below), `.environment(_:)`-injects it, renders
// `NowPlayingBar` via `.safeAreaInset`. Design in those two types' headers.
//
// #185 UPDATE (Apple Phase 1, navigation & UX consolidation) — the former
// local `@State private var selectedSection` and its `private enum
// RootSection` moved OUT into the new shared `AppNavigationState.swift`
// (see that file's header for the full reasoning): this view now takes a
// `navigationState` parameter (defaulted, so every pre-#185 call site keeps
// compiling unchanged) instead of owning that state itself, so
// `IHymnsApp.swift`'s new Mac menu-bar commands can drive section switching
// from OUTSIDE this view too. Also wires the compact `TabView`'s
// `selection:` for the first time — it previously had NONE, meaning nothing
// could ever programmatically switch tabs (a real gap this task's own audit
// found: neither a Mac command NOR any other in-app affordance could jump
// to, say, Search).
import IHAppSupport
import IHDesign
import SwiftUI

/// The shared adaptive root: a Liquid Glass `TabView` on a compact-width
/// window, a 3-column `NavigationSplitView` everywhere with room for a
/// section sidebar.
public struct RootContainerView: View {
    private let viewModel: AppRootViewModel

    /// Which top-level section is showing, plus whether the
    /// keyboard-shortcuts help sheet is open (#185) — see
    /// `AppNavigationState.swift`'s header for why this moved out of a
    /// local `@State` here.
    @Bindable private var navigationState: AppNavigationState

    /// The most recently resolved inbound Universal Link / Handoff
    /// continuation, set by `IHymnsApp.swift`'s `.onOpenURL`/
    /// `.onContinueUserActivity` handlers — `nil` the rest of the time.
    /// Consumed (reset to `nil`) the instant it's turned into a
    /// `presentedDeepLink` below, so tapping the SAME link twice in a row
    /// still re-presents (a `Binding`'s `onChange` only fires on an actual
    /// nil→non-nil transition, not a same-value re-assignment).
    @Binding private var incomingDeepLink: DeepLink?

    /// The `.sheet(item:)` binding that actually shows a deep link's
    /// destination — a `DeepLinkPresentation` (UUID-`Identifiable` wrapper,
    /// see `DeepLinkDestinationView.swift`) rather than `DeepLink` itself,
    /// so re-presenting an identical link is never suppressed by
    /// `Identifiable`-based sheet diffing.
    @State private var presentedDeepLink: DeepLinkPresentation?

    /// The ONE `SettingsViewModel` for this app run (#182) — held here, not
    /// in the app shell, per `SettingsViewModel`'s own header ("`RootContainerView`
    /// holds ONE instance of each … and hands both down"). Its `theme`
    /// drives `.preferredColorScheme` on the whole app below; it is handed to
    /// the `Settings` section's `SettingsView`. `@State` with a default-value
    /// initializer so it's built exactly once at first `body` evaluation,
    /// reading whatever was last persisted to `UserDefaults.standard`.
    @State private var settingsViewModel = SettingsViewModel()

    /// The ONE `NowPlayingViewModel` this app run uses (#1441).
    @State private var nowPlayingViewModel = NowPlayingViewModel()

    /// #190 — whether `OnboardingView` should currently be showing. Seeded
    /// from `IHSettingsStore.hasSeenOnboarding` at first `body` evaluation
    /// (same "read once via `@State`'s default-value expression" shape
    /// `settingsViewModel` above already uses) — `true` (never seen) on a
    /// genuinely fresh install, `false` on every later launch.
    @State private var isPresentingOnboarding = !IHSettingsStore().hasSeenOnboarding

    /// The reading mode to inject into `\.ihReadingMode` (#1412) — derived
    /// from the persisted dyslexia toggle. A tiny mapping helper so the two
    /// `.environment(...)` call sites (compact vs. regular width) never drift.
    private var readingMode: IHReadingMode {
        settingsViewModel.dyslexiaReadingModeEnabled ? .dyslexiaFriendly : .standard
    }

    #if os(iOS)
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass
    #endif

    @Environment(\.scenePhase) private var scenePhase // PR-10: drives viewModel.setLiveScenePhaseActive(_:)

    public init(
        viewModel: AppRootViewModel,
        navigationState: AppNavigationState = AppNavigationState(),
        incomingDeepLink: Binding<DeepLink?> = .constant(nil)
    ) {
        self.viewModel = viewModel
        self.navigationState = navigationState
        _incomingDeepLink = incomingDeepLink
    }

    public var body: some View {
        platformRoot
            // #1441 — every descendant reads the ONE shared player;
            // `NowPlayingBar` renders nothing while idle.
            .environment(nowPlayingViewModel)
            .safeAreaInset(edge: .bottom) { NowPlayingBar(rootViewModel: viewModel) }
            // #186 — a deep link lands in its own sheet regardless of which
            // platform layout is showing underneath (see this file's header
            // for why a sheet rather than a per-section `NavigationPath`).
            .sheet(item: $presentedDeepLink) { presentation in
                DeepLinkDestinationView(link: presentation.link, rootViewModel: viewModel)
            }
            .onChange(of: incomingDeepLink) { _, newValue in
                guard let newValue else { return }
                presentedDeepLink = DeepLinkPresentation(link: newValue)
                incomingDeepLink = nil
            }
            // #185 — the Mac ⌘/ command (and a Settings row) flip this;
            // presented HERE rather than per-section so it works no matter
            // which tab/sidebar section is currently showing.
            .sheet(isPresented: $navigationState.isPresentingKeyboardShortcutsHelp) {
                KeyboardShortcutsOverlayView()
            }
            // #189 — one wiring point covers "screen appears" for every
            // top-level section: `initial: true` fires immediately for the
            // launch screen too (SwiftUI's documented `onChange(of:initial:
            // _:)` behaviour), not just on later tab/sidebar switches. A
            // fresh `IHAnalyticsService()` per change is intentional — see
            // that type's own header for why it's cheap and correct to
            // construct on demand rather than held as long-lived state here.
            .onChange(of: navigationState.selectedSection, initial: true) { _, newSection in
                IHAnalyticsService().screenViewed(newSection.title)
            }
            // #190 — a plain `.sheet` (not `.fullScreenCover`, which has no
            // macOS support — see `OnboardingView`'s own header) gated on
            // `isPresentingOnboarding`. `onDismiss` persists "seen" no
            // matter HOW the sheet closed — `OnboardingView`'s own Skip/Get
            // Started (which flip the binding directly) OR a swipe-to-
            // dismiss on iOS — so onboarding can never accidentally
            // re-appear on the next launch either way.
            .sheet(isPresented: $isPresentingOnboarding, onDismiss: markOnboardingSeen) {
                OnboardingView { isPresentingOnboarding = false }
            }
            .onChange(of: scenePhase) { _, newPhase in viewModel.setLiveScenePhaseActive(newPhase == .active) }
    }
    private var liveStatusBanner: some View { LiveStatusBanner(rootViewModel: viewModel, navigationState: navigationState) }
    /// Persists `IHSettingsStore.hasSeenOnboarding = true` — see the
    /// `.sheet(onDismiss:)` call above for why this fires regardless of
    /// which affordance closed the sheet.
    private func markOnboardingSeen() {
        IHSettingsStore().hasSeenOnboarding = true
    }

    @ViewBuilder
    private var platformRoot: some View {
        #if os(macOS) || os(visionOS)
        splitView
            .task { await restoreAndSync() }
            .preferredColorScheme(settingsViewModel.theme.colorScheme)
            .ihAppearanceEnvironment(readingMode: readingMode, theme: settingsViewModel.theme)
        #elseif os(iOS)
        Group {
            if horizontalSizeClass == .compact {
                tabbedRoot
            } else {
                splitView
            }
        }
        .task { await restoreAndSync() }
        // #182 — the whole app honours the user's chosen theme. `nil`
        // (`.system`) lets SwiftUI follow the OS; `.light`/`.dark`/
        // `.highContrast` force that scheme. Applied at the ROOT so every
        // pushed screen re-themes the instant the picker changes.
        .preferredColorScheme(settingsViewModel.theme.colorScheme)
        // #1412/#1438 — reading mode + theme-derived contrast boost, see
        // IHAppearanceTheme.swift's `ihAppearanceEnvironment` doc comment.
        .ihAppearanceEnvironment(readingMode: readingMode, theme: settingsViewModel.theme)
        #else
        // tvOS/watchOS: not wired to any shell yet (see file header) — a
        // plain stack keeps this FILE compiling on those platforms without
        // pulling in the tab/split-view machinery above, which makes little
        // sense on either.
        NavigationStack {
            CatalogueListView(viewModel: viewModel)
        }
        #endif
    }

    /// Runs once per launch (both `restoreSessionIfNeeded()` and
    /// `loadFavoritesIfNeeded()` are internally one-shot-guarded, so this is
    /// safe to re-trigger on every `RootContainerView` re-appearance — e.g.
    /// rotating a compact iPhone window between `tabbedRoot`/`splitView`
    /// re-evaluates `body`, and therefore this `.task`, but never re-does
    /// the actual work).
    ///
    /// ELI5: "The app just launched — check if we were already signed in,
    /// and load whatever favourites we already know about."
    ///
    /// DETAILED: `restoreSessionIfNeeded()` runs FIRST and is awaited to
    /// completion before `loadFavoritesIfNeeded()` starts — a returning
    /// signed-in user's `syncAfterSignIn()` (triggered from INSIDE
    /// `restoreSessionIfNeeded()` itself, see that method's own doc
    /// comment) already calls `loadFavoritesIfNeeded()` as part of its own
    /// sequence, so this second explicit call is a safe, cheap no-op for
    /// that path (the one-shot guard). For a SIGNED-OUT launch, though, this
    /// second call is what actually populates `favorites` from anything
    /// still locally cached from a previous session (e.g. `signOut()`
    /// hadn't run to completion, or a future "keep last-known favourites
    /// visible read-only while offline-signed-out" affordance) — belt and
    /// braces, not redundant plumbing.
    private func restoreAndSync() async {
        await viewModel.restoreSessionIfNeeded()
        await viewModel.loadFavoritesIfNeeded()
        viewModel.resumeLiveIfPossible()
    }

    /// iPhone/compact-iPad root: one Liquid Glass `TabView` tab per
    /// top-level screen, each hosting its OWN `NavigationStack` so pushing
    /// a song from Home, the catalogue, OR a songbook's song list never
    /// contends with a shared navigation path.
    ///
    /// #185 — now bound to `navigationState.selectedSection` (previously NO
    /// `selection:` binding existed at all, so nothing could ever
    /// programmatically switch tabs); each tab's `.tag(_:)` is what makes
    /// that binding actually route to the right one.
    private var tabbedRoot: some View {
        TabView(selection: $navigationState.selectedSection) {
            NavigationStack {
                HomeView(rootViewModel: viewModel)
            }
            .tabItem { Label(RootSection.home.title, systemImage: RootSection.home.systemImage) }
            .tag(RootSection.home)

            NavigationStack {
                SongbooksView(rootViewModel: viewModel)
            }
            .tabItem { Label(RootSection.songbooks.title, systemImage: RootSection.songbooks.systemImage) }
            .tag(RootSection.songbooks)

            NavigationStack {
                CatalogueListView(viewModel: viewModel)
            }
            .tabItem { Label(RootSection.search.title, systemImage: RootSection.search.systemImage) }
            .tag(RootSection.search)

            NavigationStack {
                FavoritesView(rootViewModel: viewModel)
            }
            .tabItem { Label(RootSection.favorites.title, systemImage: RootSection.favorites.systemImage) }
            .tag(RootSection.favorites)

            NavigationStack {
                SetlistsView(rootViewModel: viewModel)
            }
            .tabItem { Label(RootSection.setlists.title, systemImage: RootSection.setlists.systemImage) }
            .tag(RootSection.setlists)

            NavigationStack {
                LiveHubView(rootViewModel: viewModel)
            }
            .tabItem { Label(RootSection.live.title, systemImage: RootSection.live.systemImage) }
            .tag(RootSection.live)

            NavigationStack {
                SettingsView(rootViewModel: viewModel, settings: settingsViewModel)
            }
            .tabItem { Label(RootSection.settings.title, systemImage: RootSection.settings.systemImage) }
            .tag(RootSection.settings)
        }
        .safeAreaInset(edge: .bottom) { liveStatusBanner }
    }

    /// iPad(regular)/Mac/visionOS root: a 3-column `NavigationSplitView` —
    /// a section-picker SIDEBAR (#1437's "sidebar section" ask), the chosen
    /// section's own list as the CONTENT column, and the pushed song (or
    /// the placeholder) as the DETAIL column.
    private var splitView: some View {
        NavigationSplitView {
            // `List(selection:)` wants an OPTIONAL binding (a sidebar
            // selection can be momentarily empty mid-diff); `navigationState
            // .selectedSection` itself stays non-optional (simpler for
            // `TabView`/the Mac commands, neither of which has a "nothing
            // selected" state) — this small adapter bridges the two, folding
            // a cleared selection straight back to `.home` rather than ever
            // leaving the content column blank.
            let sidebarSelection = Binding<RootSection?>(
                get: { navigationState.selectedSection },
                set: { navigationState.selectedSection = $0 ?? .home }
            )
            List(RootSection.allCases, selection: sidebarSelection) { section in
                Label(section.title, systemImage: section.systemImage).tag(section)
            }
            .navigationTitle("iHymns")
        } content: {
            switch navigationState.selectedSection {
            case .home:
                HomeView(rootViewModel: viewModel)
            case .songbooks:
                SongbooksView(rootViewModel: viewModel)
            case .search:
                CatalogueListView(viewModel: viewModel)
            case .favorites:
                FavoritesView(rootViewModel: viewModel)
            case .setlists:
                SetlistsView(rootViewModel: viewModel)
            case .live:
                LiveHubView(rootViewModel: viewModel)
            case .settings:
                SettingsView(rootViewModel: viewModel, settings: settingsViewModel)
            }
        } detail: {
            ContentUnavailableView(
                "Select a Song",
                systemImage: "music.note.list",
                description: Text("Choose a song from the list to view its lyrics.")
            )
        }
        .safeAreaInset(edge: .bottom) { liveStatusBanner }
    }
}

// `RootSection` (#185: moved to `AppNavigationState.swift`, and made
// `public` there so `IHymnsApp.swift`'s Mac menu commands can reference it
// too) used to live here as a `private enum` — see that file's header for
// the full reasoning.

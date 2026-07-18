// TVRootView.swift
// IHFeatures
//
// ELI5: The tvOS app's actual home screen — a tab bar with "Project" (the
// live projection screen), "Songbooks," "Search," "Live" (PR-15's
// server-following projector), and "Settings" (the trusted-remotes/pairing
// screens), each reusing the EXACT SAME shared screens the iOS/Mac app
// already has (or, for Settings/Live, tvOS-only content).
//
// DETAILED: #1504/#1421/#1428 (`.claude/apple-phase2-pr5-spec.md` §5.3,
// `.claude/apple-phase2-pr6-spec.md` §6.3, `.claude/apple-native-strategy.md`
// §2.4.7). Strategy §2.2's tvOS tab set is Home · Songbooks · Search ·
// Project · Settings — Home remains DEFERRED here (it needs tvOS-specific
// "shelf" layout work nobody has designed yet); Settings is NO LONGER
// deferred as of #1421 — PR-5's own header comment named "PR-6's
// trusted-remotes/pairing screens" as the reason it was waiting, and this
// is that PR. Shipping Project/Songbooks/Search/Settings now — reusing
// `SongbooksView`/`CatalogueListView` completely unmodified — proves the
// shared-screen composition works on tvOS well before Home needs to be
// designed for it. PR-15 (#1428) adds the 5th tab, "Live" — a SEPARATE,
// SECOND `ServiceModeEngine` instance (never the root `viewModel`'s own —
// `TVServiceFollowCoordinator.swift`'s own header explains the
// single-consumer reason why), but driving the SAME SINGLE
// `projectionViewModel` this file already builds — `ProjectionViewModel`
// is deliberately driver-agnostic (its own header: "it can be driven by
// ANYTHING"), so the Siri Remote (`ProjectionSceneView`), the LAN remote
// (`TVRemoteControlCoordinator`), and now the server-following loop
// (`followCoordinator`) all call methods on the ONE canonical instance —
// there is still only ONE "what's actually on screen" truth, whichever
// driver last moved it.
//
// Compiles on EVERY platform this package targets (it's built by the
// macOS `swift test`/build gate too) — only the `iHymnsTV` app shell
// (`IHymnsTVApp.swift`) actually instantiates it, mirroring the posture
// `RootContainerView`'s own header documents for ITS `#else` (tvOS/watchOS)
// branch, which this task does NOT touch (dead code for now — a future PR
// may consolidate the two root views once tvOS gets Home).
import IHAPI
import IHLive
import SwiftUI

/// The tvOS shell's root view: `TabView { Project · Songbooks · Search ·
/// Live · Settings }`, with the pairing overlay layered on top while active.
public struct TVRootView: View {
    private let viewModel: AppRootViewModel

    /// The ONE `ProjectionViewModel` this app run uses — built from
    /// `viewModel.songDetail(id:)` (the SAME "one `APIClient` per app run,
    /// reached through the root view model" pass-through every other
    /// screen already uses), never a second `APIClient`/`AppRootViewModel`.
    /// Shared by EVERY driver, including PR-15's `followCoordinator` below
    /// — see this file's header.
    @State private var projectionViewModel: ProjectionViewModel

    /// The ONE `TVRemoteControlCoordinator` this app run uses (#1421) —
    /// built alongside `projectionViewModel` in `init` (it needs that SAME
    /// instance, not a second one) so the listener ↔ projection bridge
    /// drives the identical view-model the Siri-Remote driver
    /// (`ProjectionSceneView`) does.
    @State private var coordinator: TVRemoteControlCoordinator

    /// PR-15 (#1428): the ONE `TVServiceFollowCoordinator` this app run
    /// uses — its own `ServiceModeEngine(apiClient: viewModel.apiClient)`
    /// is a SECOND, independent engine instance (never the root
    /// `viewModel`'s own — the single-consumer rule this file's header
    /// explains), constructed HERE (never inside the coordinator itself,
    /// same "engines are injected, never self-constructed" rule
    /// `AppRootViewModel.init`'s header documents) so it is visibly this
    /// composition root's responsibility, exactly like `coordinator` above.
    @State private var followCoordinator: TVServiceFollowCoordinator

    /// PR-15: forwards app-level backgrounding to `followCoordinator` —
    /// the `RemoteControlView.swift`/`AppRootViewModel.setLiveScenePhaseActive(_:)`
    /// precedent, applied to this SECOND engine.
    @Environment(\.scenePhase) private var scenePhase

    /// - Parameter viewModel: `AppRootViewModel` is `@MainActor`; the
    ///   `fetchSongDetail` closure below is `@Sendable async` and simply
    ///   `await`s onto it — fine under strict concurrency (the closure
    ///   never touches `viewModel`'s isolated state directly, only calls
    ///   its own `async` method, which is what makes crossing into the
    ///   `@Sendable` closure type sound here).
    public init(viewModel: AppRootViewModel) {
        self.viewModel = viewModel
        let projectionViewModel = ProjectionViewModel(fetchSongDetail: { id in
            try await viewModel.songDetail(id: id)
        })
        _projectionViewModel = State(initialValue: projectionViewModel)
        _coordinator = State(initialValue: TVRemoteControlCoordinator(projectionViewModel: projectionViewModel))
        _followCoordinator = State(initialValue: TVServiceFollowCoordinator(
            projectionViewModel: projectionViewModel, engine: ServiceModeEngine(apiClient: viewModel.apiClient)
        ))
    }

    public var body: some View {
        ZStack {
            // `.tabItem { Label(...) }` + `TabView` (not the newer `Tab(_:
            // systemImage:)` builder) — matches `RootContainerView.tabbedRoot`'s
            // existing convention exactly (this repo's modularity rule: one
            // established pattern, not two competing ones for the same thing).
            TabView {
                ProjectionSceneView(rootViewModel: viewModel, viewModel: projectionViewModel)
                    .tabItem { Label("Project", systemImage: "tv") }

                NavigationStack {
                    SongbooksView(rootViewModel: viewModel)
                }
                .tabItem { Label("Songbooks", systemImage: "books.vertical") }

                NavigationStack {
                    CatalogueListView(viewModel: viewModel)
                }
                .tabItem { Label("Search", systemImage: "magnifyingglass") }

                // PR-15 (#1428) — the server-following projector tab. No
                // `NavigationStack` (matches "Project" above, also a
                // full-bleed surface with no navigation chrome of its own).
                TVServiceFollowView(projectionViewModel: projectionViewModel, coordinator: followCoordinator)
                    .tabItem { Label("Live", systemImage: "dot.radiowaves.left.and.right") }

                NavigationStack {
                    TVSettingsRemoteView(coordinator: coordinator)
                }
                .tabItem { Label("Settings", systemImage: "gear") }
            }

            // The pairing ceremony overlay — a full-bleed layer ABOVE the
            // tabs while a ceremony is running, never a sheet/full-screen-
            // cover (a Settings-tab-triggered "Pair a remote…" should feel
            // like the WHOLE TV pausing to pair, not a modal on one tab).
            if coordinator.isPairingActive {
                TVPairingOverlayView(coordinator: coordinator)
            }
        }
        .task {
            // Same two one-shot-guarded calls `RootContainerView
            // .restoreAndSync()` makes — browse works signed-out either way.
            await viewModel.restoreSessionIfNeeded()
            await viewModel.loadFavoritesIfNeeded()
        }
        .task {
            // Idempotent (`TVRemoteControlCoordinator.start()`'s own guard)
            // — starts the LAN listener + spawns its bridge tasks once.
            await coordinator.start()
        }
        .task {
            // PR-15: idempotent (`TVServiceFollowCoordinator.start()`'s own
            // guard) — spawns the events consumer, then attempts a
            // cold-start resume.
            await followCoordinator.start()
        }
        .onChange(of: scenePhase) { _, newPhase in
            followCoordinator.setScenePhaseActive(newPhase == .active)
        }
    }
}

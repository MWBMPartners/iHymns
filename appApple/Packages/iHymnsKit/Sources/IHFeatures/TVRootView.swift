// TVRootView.swift
// IHFeatures
//
// ELI5: The tvOS app's actual home screen — a tab bar with "Project" (the
// live projection screen), "Songbooks," "Search," and "Settings" (the
// trusted-remotes/pairing screens), each reusing the EXACT SAME shared
// screens the iOS/Mac app already has (or, for Settings, PR-6's new
// tvOS-only content).
//
// DETAILED: #1504/#1421 (`.claude/apple-phase2-pr5-spec.md` §5.3,
// `.claude/apple-phase2-pr6-spec.md` §6.3). Strategy §2.2's tvOS tab set is
// Home · Songbooks · Search · Project · Settings — Home remains DEFERRED
// here (it needs tvOS-specific "shelf" layout work nobody has designed
// yet); Settings is NO LONGER deferred as of #1421 — PR-5's own header
// comment named "PR-6's trusted-remotes/pairing screens" as the reason it
// was waiting, and this is that PR. Shipping Project/Songbooks/Search/
// Settings now — reusing `SongbooksView`/`CatalogueListView` completely
// unmodified — proves the shared-screen composition works on tvOS well
// before Home needs to be designed for it.
//
// Compiles on EVERY platform this package targets (it's built by the
// macOS `swift test`/build gate too) — only the `iHymnsTV` app shell
// (`IHymnsTVApp.swift`) actually instantiates it, mirroring the posture
// `RootContainerView`'s own header documents for ITS `#else` (tvOS/watchOS)
// branch, which this task does NOT touch (dead code for now — a future PR
// may consolidate the two root views once tvOS gets Home).
import IHAPI
import SwiftUI

/// The tvOS shell's root view: `TabView { Project · Songbooks · Search ·
/// Settings }`, with the pairing overlay layered on top while active.
public struct TVRootView: View {
    private let viewModel: AppRootViewModel

    /// The ONE `ProjectionViewModel` this app run uses — built from
    /// `viewModel.songDetail(id:)` (the SAME "one `APIClient` per app run,
    /// reached through the root view model" pass-through every other
    /// screen already uses), never a second `APIClient`/`AppRootViewModel`.
    @State private var projectionViewModel: ProjectionViewModel

    /// The ONE `TVRemoteControlCoordinator` this app run uses (#1421) —
    /// built alongside `projectionViewModel` in `init` (it needs that SAME
    /// instance, not a second one) so the listener ↔ projection bridge
    /// drives the identical view-model the Siri-Remote driver
    /// (`ProjectionSceneView`) does.
    @State private var coordinator: TVRemoteControlCoordinator

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
    }
}

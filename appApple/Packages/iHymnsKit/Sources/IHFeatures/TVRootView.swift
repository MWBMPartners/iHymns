// TVRootView.swift
// IHFeatures
//
// ELI5: The tvOS app's actual home screen — a tab bar with "Project" (the
// live projection screen), "Songbooks," and "Search," each reusing the
// EXACT SAME shared screens the iOS/Mac app already has.
//
// DETAILED: #1504 (`.claude/apple-phase2-pr5-spec.md` §5.3). Strategy
// §2.2's tvOS tab set is Home · Songbooks · Search · Project · Settings —
// Home and Settings are DEFERRED here: Home needs tvOS-specific "shelf"
// layout work nobody has designed yet, and Settings arrives together with
// PR-6's trusted-remotes/pairing screens (no meaningful settings exist for
// a projector shell before pairing is a real concept). Shipping the other
// three now — reusing `SongbooksView`/`CatalogueListView` completely
// unmodified — proves the shared-screen composition works on tvOS well
// before Home/Settings need to be designed for it.
//
// Compiles on EVERY platform this package targets (it's built by the
// macOS `swift test`/build gate too) — only the `iHymnsTV` app shell
// (`IHymnsTVApp.swift`) actually instantiates it, mirroring the posture
// `RootContainerView`'s own header documents for ITS `#else` (tvOS/watchOS)
// branch, which this task does NOT touch (dead code for now — PR-6+ may
// consolidate the two root views once tvOS gets Home/Settings).
import IHAPI
import SwiftUI

/// The tvOS shell's root view: `TabView { Project · Songbooks · Search }`.
public struct TVRootView: View {
    private let viewModel: AppRootViewModel

    /// The ONE `ProjectionViewModel` this app run uses — built from
    /// `viewModel.songDetail(id:)` (the SAME "one `APIClient` per app run,
    /// reached through the root view model" pass-through every other
    /// screen already uses), never a second `APIClient`/`AppRootViewModel`.
    @State private var projectionViewModel: ProjectionViewModel

    /// - Parameter viewModel: `AppRootViewModel` is `@MainActor`; the
    ///   `fetchSongDetail` closure below is `@Sendable async` and simply
    ///   `await`s onto it — fine under strict concurrency (the closure
    ///   never touches `viewModel`'s isolated state directly, only calls
    ///   its own `async` method, which is what makes crossing into the
    ///   `@Sendable` closure type sound here).
    public init(viewModel: AppRootViewModel) {
        self.viewModel = viewModel
        _projectionViewModel = State(initialValue: ProjectionViewModel(fetchSongDetail: { id in
            try await viewModel.songDetail(id: id)
        }))
    }

    public var body: some View {
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
        }
        .task {
            // Same two one-shot-guarded calls `RootContainerView
            // .restoreAndSync()` makes — browse works signed-out either way.
            await viewModel.restoreSessionIfNeeded()
            await viewModel.loadFavoritesIfNeeded()
        }
    }
}

// RootContainerView.swift
// IHFeatures
//
// ELI5: Decides "does this window look like a phone (one screen at a time)
// or like an iPad/Mac/Vision (a list + detail side-by-side)?" and builds
// the right container around the SAME shared `CatalogueListView` either
// way — so the app shell itself doesn't have to know the difference.
//
// DETAILED: Strategy §2.2's "same codepath" principle, and this task's
// explicit ask — "iPhone: NavigationStack; iPad/macOS/visionOS:
// NavigationSplitView" — satisfied from ONE shared view rather than
// per-shell duplication. `NavigationLink(value:)` + `.navigationDestination
// (for:)` (both inside `CatalogueListView`) work identically whether that
// view is the root of a `NavigationStack` or the sidebar column of a
// `NavigationSplitView` — pushing a destination from a split view's sidebar
// column lands it in the DETAIL column (Apple's WWDC22 "The SwiftUI
// cookbook for navigation"), so this file's ENTIRE job is picking which
// container to wrap `CatalogueListView` in; it makes no navigation
// decisions of its own.
//
// Compiles on every platform `IHFeatures` targets (`Package.swift`'s
// `platforms:` list includes tvOS/watchOS too) even though only the
// iOS/iPadOS/macOS/visionOS `IHymns` app shell (`Apps/iHymns/`) actually
// instantiates this type today — tvOS/watch keep `PhaseZeroSkeletonView`
// per this task's explicit scope ("Keep the tvOS/watch shells compiling").
import SwiftUI

/// The shared adaptive root: a `NavigationStack` on a compact-width window,
/// a `NavigationSplitView` everywhere with room for two columns.
public struct RootContainerView: View {
    private let viewModel: AppRootViewModel

    #if os(iOS)
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass
    #endif

    public init(viewModel: AppRootViewModel) {
        self.viewModel = viewModel
    }

    public var body: some View {
        #if os(macOS) || os(visionOS)
        splitView
        #elseif os(iOS)
        if horizontalSizeClass == .compact {
            NavigationStack {
                CatalogueListView(viewModel: viewModel)
            }
        } else {
            splitView
        }
        #else
        // tvOS/watchOS: not wired to any shell yet (see file header) — a
        // plain stack keeps this FILE compiling on those platforms without
        // pulling in `NavigationSplitView`'s two-column layout, which makes
        // little sense on either.
        NavigationStack {
            CatalogueListView(viewModel: viewModel)
        }
        #endif
    }

    private var splitView: some View {
        NavigationSplitView {
            CatalogueListView(viewModel: viewModel)
        } detail: {
            ContentUnavailableView(
                "Select a Song",
                systemImage: "music.note.list",
                description: Text("Choose a song from the list to view its lyrics.")
            )
        }
    }
}

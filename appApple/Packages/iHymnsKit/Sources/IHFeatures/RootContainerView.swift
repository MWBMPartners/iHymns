// RootContainerView.swift
// IHFeatures
//
// ELI5: Decides "does this window look like a phone (one screen at a time)
// or like an iPad/Mac/Vision (a list + detail side-by-side)?" and builds
// the right container around the shared `CatalogueListView`/`SongbooksView`
// screens either way — so the app shell itself doesn't have to know the
// difference. Also gives the user a way to REACH Songbooks alongside the
// catalogue: a tab on iPhone, a sidebar section on iPad/Mac/vision.
//
// DETAILED: Strategy §2.2's "same codepath" principle, extended by #1437
// ("give `RootContainerView` a way to reach Songbooks — a tab on iPhone /
// a sidebar section on iPad/Mac/vision"). `NavigationLink(value:)` +
// `.navigationDestination(for:)` (declared on `CatalogueListView`/
// `SongbooksView` themselves) work identically whether the hosting view is
// the root of its OWN `NavigationStack` (one per iPhone tab) or the CONTENT
// column of a 3-column `NavigationSplitView` (Apple's WWDC22 "The SwiftUI
// cookbook for navigation": pushing a destination from the content column
// lands it in the DETAIL column) — so this file's ENTIRE job is picking
// which container/section to show, never making a navigation decision of
// its own.
//
// Compiles on every platform `IHFeatures` targets (`Package.swift`'s
// `platforms:` list includes tvOS/watchOS too) even though only the
// iOS/iPadOS/macOS/visionOS `IHymns` app shell (`Apps/iHymns/`) actually
// instantiates this type today — tvOS/watch keep `PhaseZeroSkeletonView`
// per this task's explicit scope ("Keep the tvOS/watch shells compiling").
import SwiftUI

/// The shared adaptive root: a Liquid Glass `TabView` on a compact-width
/// window, a 3-column `NavigationSplitView` everywhere with room for a
/// section sidebar.
public struct RootContainerView: View {
    private let viewModel: AppRootViewModel

    /// Which top-level section the iPad(regular)/Mac/visionOS sidebar is
    /// currently showing in its CONTENT column — `nil` only if the sidebar
    /// `List`'s selection is ever cleared by the user; `splitView` treats
    /// that the same as `.songs` (see `content:` below) so the content
    /// column is never left blank.
    @State private var selectedSection: RootSection? = .songs

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
            tabbedRoot
        } else {
            splitView
        }
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

    /// iPhone/compact-iPad root (#1437): one Liquid Glass `TabView` tab per
    /// top-level screen, each hosting its OWN `NavigationStack` so pushing
    /// a song from either the catalogue OR a songbook's song list never
    /// contends with a shared navigation path.
    private var tabbedRoot: some View {
        TabView {
            NavigationStack {
                CatalogueListView(viewModel: viewModel)
            }
            .tabItem { Label(RootSection.songs.title, systemImage: RootSection.songs.systemImage) }

            NavigationStack {
                SongbooksView(rootViewModel: viewModel)
            }
            .tabItem { Label(RootSection.songbooks.title, systemImage: RootSection.songbooks.systemImage) }
        }
    }

    /// iPad(regular)/Mac/visionOS root: a 3-column `NavigationSplitView` —
    /// a section-picker SIDEBAR (#1437's "sidebar section" ask), the chosen
    /// section's own list as the CONTENT column, and the pushed song (or
    /// the placeholder) as the DETAIL column.
    private var splitView: some View {
        NavigationSplitView {
            List(RootSection.allCases, selection: $selectedSection) { section in
                Label(section.title, systemImage: section.systemImage).tag(section)
            }
            .navigationTitle("iHymns")
        } content: {
            switch selectedSection ?? .songs {
            case .songs:
                CatalogueListView(viewModel: viewModel)
            case .songbooks:
                SongbooksView(rootViewModel: viewModel)
            }
        } detail: {
            ContentUnavailableView(
                "Select a Song",
                systemImage: "music.note.list",
                description: Text("Choose a song from the list to view its lyrics.")
            )
        }
    }
}

/// The top-level screens `RootContainerView` switches between — a tab on
/// iPhone, a sidebar row on iPad/Mac/vision (#1437).
private enum RootSection: String, CaseIterable, Identifiable, Hashable {
    case songs
    case songbooks

    var id: String { rawValue }

    var title: String {
        switch self {
        case .songs: "Songs"
        case .songbooks: "Songbooks"
        }
    }

    var systemImage: String {
        switch self {
        case .songs: "music.note.list"
        case .songbooks: "books.vertical"
        }
    }
}

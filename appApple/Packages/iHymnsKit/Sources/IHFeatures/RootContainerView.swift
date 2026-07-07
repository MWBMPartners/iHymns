// RootContainerView.swift
// IHFeatures
//
// ELI5: Decides "does this window look like a phone (one screen at a time)
// or like an iPad/Mac/Vision (a list + detail side-by-side)?" and builds
// the right container around the shared `HomeView`/`SongbooksView`/
// `CatalogueListView` screens either way — so the app shell itself doesn't
// have to know the difference. Also gives the user a way to REACH every
// top-level section: a tab on iPhone, a sidebar section on iPad/Mac/vision.
//
// DETAILED: Strategy §2.2's "same codepath" principle, extended first by
// #1437 (Songbooks) and now by #183's Home-surface brief: "Rework the app
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
// The fourth section, `.live`, is a DELIBERATE, honestly-labelled "coming
// soon" placeholder (this task's own brief: "a Live tab/Setlists can be a
// labelled 'coming soon' placeholder for now, don't fake them") — no
// `IHLive` engine is wired to any UI yet in this package, so pretending
// there's a real Live/Setlists screen here would be exactly the kind of
// faked surface this task explicitly rules out. `ContentUnavailableView` is
// the same "genuinely nothing here yet" idiom the rest of this package
// already uses for empty/error states, reused here for "not built yet"
// instead.
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
    /// that the same as `.home` (see `content:` below) so the content
    /// column is never left blank. Defaults to `.home` — the Home surface
    /// (#183) is now the app's flagship landing screen, not the catalogue
    /// list.
    @State private var selectedSection: RootSection? = .home

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

    /// iPhone/compact-iPad root: one Liquid Glass `TabView` tab per
    /// top-level screen, each hosting its OWN `NavigationStack` so pushing
    /// a song from Home, the catalogue, OR a songbook's song list never
    /// contends with a shared navigation path.
    private var tabbedRoot: some View {
        TabView {
            NavigationStack {
                HomeView(rootViewModel: viewModel)
            }
            .tabItem { Label(RootSection.home.title, systemImage: RootSection.home.systemImage) }

            NavigationStack {
                SongbooksView(rootViewModel: viewModel)
            }
            .tabItem { Label(RootSection.songbooks.title, systemImage: RootSection.songbooks.systemImage) }

            NavigationStack {
                CatalogueListView(viewModel: viewModel)
            }
            .tabItem { Label(RootSection.search.title, systemImage: RootSection.search.systemImage) }

            NavigationStack {
                liveComingSoonView
            }
            .tabItem { Label(RootSection.live.title, systemImage: RootSection.live.systemImage) }
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
            switch selectedSection ?? .home {
            case .home:
                HomeView(rootViewModel: viewModel)
            case .songbooks:
                SongbooksView(rootViewModel: viewModel)
            case .search:
                CatalogueListView(viewModel: viewModel)
            case .live:
                liveComingSoonView
            }
        } detail: {
            ContentUnavailableView(
                "Select a Song",
                systemImage: "music.note.list",
                description: Text("Choose a song from the list to view its lyrics.")
            )
        }
    }

    /// The honestly-labelled "coming soon" placeholder for Live Follow /
    /// Service Mode / Setlists — see this file's header for why this is a
    /// real, explicit placeholder rather than a faked screen. Shared by
    /// both `tabbedRoot`'s Live tab and `splitView`'s `.live` section so
    /// the wording only lives in one place.
    private var liveComingSoonView: some View {
        ContentUnavailableView(
            "Live & Setlists",
            systemImage: "dot.radiowaves.left.and.right",
            description: Text("Live Follow, Service Mode, and Setlists are coming in a future update.")
        )
    }
}

/// The top-level screens `RootContainerView` switches between — a tab on
/// iPhone, a sidebar row on iPad/Mac/vision.
private enum RootSection: String, CaseIterable, Identifiable, Hashable {
    case home
    case songbooks
    case search
    case live

    var id: String { rawValue }

    var title: String {
        switch self {
        case .home: "Home"
        case .songbooks: "Songbooks"
        case .search: "Search"
        case .live: "Live"
        }
    }

    var systemImage: String {
        switch self {
        case .home: "house"
        case .songbooks: "books.vertical"
        case .search: "magnifyingglass"
        case .live: "dot.radiowaves.left.and.right"
        }
    }
}

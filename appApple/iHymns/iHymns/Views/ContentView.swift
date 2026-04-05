// ContentView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - Color Hex Extension
/// Extends SwiftUI's Color to support initialisation from hexadecimal colour strings.
/// This is used throughout the app to match the amber-based colour palette of the web app.
extension Color {
    /// Creates a Color from a hex string (e.g. "b45309").
    /// Supports 6-character (RGB) and 8-character (ARGB) hex strings.
    /// The leading "#" is stripped if present.
    init(hex: String) {
        // Strip the hash prefix if the caller included it
        let sanitised = hex.trimmingCharacters(in: CharacterSet.alphanumerics.inverted)
        var hexValue: UInt64 = 0
        Scanner(string: sanitised).scanHexInt64(&hexValue)

        // Determine whether an alpha channel was included (8 chars) or not (6 chars)
        let red, green, blue, alpha: Double
        if sanitised.count == 8 {
            alpha = Double((hexValue >> 24) & 0xFF) / 255.0
            red   = Double((hexValue >> 16) & 0xFF) / 255.0
            green = Double((hexValue >>  8) & 0xFF) / 255.0
            blue  = Double( hexValue        & 0xFF) / 255.0
        } else {
            alpha = 1.0
            red   = Double((hexValue >> 16) & 0xFF) / 255.0
            green = Double((hexValue >>  8) & 0xFF) / 255.0
            blue  = Double( hexValue        & 0xFF) / 255.0
        }

        self.init(.sRGB, red: red, green: green, blue: blue, opacity: alpha)
    }
}

// MARK: - Amber Theme Constants
/// Centralised colour constants matching the iHymns web-app amber palette.
/// These are referenced by every view that needs branded colouring.
enum AmberTheme {
    /// Primary amber accent (dark amber, used for tint / leading bars)
    static let accent = Color(hex: "b45309")
    /// Lighter amber for gradients and backgrounds
    static let light  = Color(hex: "f59e0b")
    /// Very light amber used for subtle background washes
    static let wash   = Color(hex: "fef3c7")
}

// MARK: - ContentView
/// The root view of the iHymns app.
///
/// Layout strategy per platform:
/// - **iPhone**: A `TabView` with four tabs (Songbooks, Favourites, Search, Help),
///   each wrapping its content in a `NavigationStack`.
/// - **iPad / Mac**: A `NavigationSplitView` with a sidebar listing songbooks and a
///   detail pane that shows the selected content.
/// - **tvOS**: A focus-based layout with larger text optimised for television screens.
/// - **watchOS**: A compact `NavigationStack` with a simple list of songbooks.
struct ContentView: View {

    // MARK: Environment
    /// The shared song store that provides songbook and song data to the entire view hierarchy.
    @EnvironmentObject var songStore: SongStore

    // MARK: State
    /// Tracks the currently selected tab on compact (iPhone) layouts.
    @State private var selectedTab: AppTab = .songbooks

    /// Text entered into the global `.searchable` modifier (iPad / Mac sidebar search).
    @State private var globalSearchText: String = ""

    /// The currently selected songbook identifier used in the split-view detail column.
    @State private var selectedSongbookId: String? = nil

    // MARK: Tab Enumeration
    /// Represents each tab available in the iPhone tab bar.
    enum AppTab: Hashable {
        case songbooks, favourites, search, help
    }

    // MARK: Body
    var body: some View {
        #if os(watchOS)
        // ── watchOS: Compact NavigationStack ──────────────────────────────
        // watchOS devices have very limited screen real-estate, so we present
        // a single NavigationStack with a simple list of songbooks that drills
        // down into song lists.
        NavigationStack {
            SongbookListView()
                .navigationTitle("iHymns")
        }
        .tint(AmberTheme.accent)

        #elseif os(tvOS)
        // ── tvOS: Focus-based navigation with large text ─────────────────
        // tvOS uses the Siri Remote for focus-based navigation. We use a
        // NavigationStack with enlarged text so the interface is readable
        // from across a room.
        NavigationStack {
            VStack(spacing: 40) {
                // App title displayed prominently at the top of the screen
                Text("iHymns")
                    .font(.system(size: 64, weight: .bold))
                    .foregroundStyle(AmberTheme.accent)

                // Songbook grid laid out for television viewing distance
                SongbookListView()
            }
            .padding(60)
        }
        .tint(AmberTheme.accent)

        #else
        // ── iOS / iPadOS / macOS / visionOS ──────────────────────────────
        // We check the horizontal size class to decide between a tab-based
        // compact layout (iPhone) and a split-view layout (iPad, Mac, visionOS).
        AdaptiveNavigationView(
            selectedTab: $selectedTab,
            globalSearchText: $globalSearchText,
            selectedSongbookId: $selectedSongbookId
        )
        .tint(AmberTheme.accent)
        #endif
    }
}

// MARK: - AdaptiveNavigationView
/// A helper view that switches between `NavigationSplitView` (regular width)
/// and `TabView` (compact width) depending on the current horizontal size class.
/// This is separated from `ContentView` so that the `@Environment` reading happens
/// inside the correct platform-conditional branch.
#if !os(watchOS) && !os(tvOS)
private struct AdaptiveNavigationView: View {

    // MARK: Environment
    @EnvironmentObject var songStore: SongStore

    /// The current horizontal size class, used to decide compact vs regular layout.
    @Environment(\.horizontalSizeClass) private var sizeClass

    // MARK: Bindings
    @Binding var selectedTab: ContentView.AppTab
    @Binding var globalSearchText: String
    @Binding var selectedSongbookId: String?

    // MARK: Body
    var body: some View {
        if sizeClass == .compact {
            // ── Compact (iPhone / narrow iPad slide-over) ────────────────
            // Present a TabView with four dedicated tabs for quick access.
            compactTabView
        } else {
            // ── Regular (iPad / Mac / visionOS) ─────────────────────────
            // Present a NavigationSplitView with sidebar + detail columns.
            regularSplitView
        }
    }

    // MARK: Compact Layout (TabView)
    /// A `TabView` containing four tabs, each wrapping its content inside a
    /// `NavigationStack` so that drill-down navigation works within each tab.
    private var compactTabView: some View {
        TabView(selection: $selectedTab) {
            // ── Songbooks Tab ────────────────────────────────────────────
            // The primary tab showing all available songbooks.
            NavigationStack {
                SongbookListView()
                    .navigationTitle("Songbooks")
            }
            .tabItem {
                Label("Songbooks", systemImage: "books.vertical")
            }
            .tag(ContentView.AppTab.songbooks)

            // ── Favourites Tab ───────────────────────────────────────────
            // Shows songs the user has starred for quick access.
            NavigationStack {
                FavoritesView()
                    .navigationTitle("Favourites")
            }
            .tabItem {
                Label("Favourites", systemImage: "star.fill")
            }
            .tag(ContentView.AppTab.favourites)

            // ── Search Tab ───────────────────────────────────────────────
            // Full-text search across all songs, titles, lyrics and writers.
            NavigationStack {
                SearchView()
                    .navigationTitle("Search")
            }
            .tabItem {
                Label("Search", systemImage: "magnifyingglass")
            }
            .tag(ContentView.AppTab.search)

            // ── Help Tab ─────────────────────────────────────────────────
            // In-app help and information about the app.
            NavigationStack {
                HelpView()
                    .navigationTitle("Help")
            }
            .tabItem {
                Label("Help", systemImage: "questionmark.circle")
            }
            .tag(ContentView.AppTab.help)
        }
    }

    // MARK: Regular Layout (NavigationSplitView)
    /// A two-column `NavigationSplitView` with a songbook sidebar and a detail area.
    /// The `.searchable` modifier is attached to the sidebar for global filtering.
    private var regularSplitView: some View {
        NavigationSplitView {
            // ── Sidebar Column ───────────────────────────────────────────
            // Lists all songbooks. Tapping one sets `selectedSongbookId`
            // which drives the detail column.
            SongbookListView(selectedSongbookId: $selectedSongbookId)
                .navigationTitle("iHymns")
                .searchable(text: $globalSearchText, prompt: "Search all songs...")
                .toolbar {
                    // Favourites button in the sidebar toolbar
                    ToolbarItem(placement: .automatic) {
                        NavigationLink(destination: FavoritesView()) {
                            Label("Favourites", systemImage: "star.fill")
                        }
                    }
                    // Help button in the sidebar toolbar
                    ToolbarItem(placement: .automatic) {
                        NavigationLink(destination: HelpView()) {
                            Label("Help", systemImage: "questionmark.circle")
                        }
                    }
                }
        } detail: {
            // ── Detail Column ────────────────────────────────────────────
            // Shows the song list for the selected songbook, or a placeholder
            // prompt if no songbook has been selected yet.
            if let songbookId = selectedSongbookId {
                SongListView(songbookId: songbookId)
            } else if !globalSearchText.isEmpty {
                // When the user types into the global search, show search results
                SearchView(initialSearchText: globalSearchText)
            } else {
                // Default placeholder when nothing is selected
                ContentUnavailableView(
                    "Select a Songbook",
                    systemImage: "books.vertical",
                    description: Text("Choose a songbook from the sidebar to view its hymns.")
                )
            }
        }
    }
}
#endif

// MARK: - Preview
#if DEBUG
#Preview {
    ContentView()
        .environmentObject(SongStore())
}
#endif

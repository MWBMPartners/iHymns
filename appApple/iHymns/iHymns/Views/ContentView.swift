// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  ContentView.swift
//  iHymns
//
//  The root view of the iHymns app using Liquid Glass design language.
//  Provides platform-adaptive navigation with:
//  - iPhone: TabView with Liquid Glass tab bar
//  - iPad/Mac/visionOS: NavigationSplitView with glass sidebar
//  - tvOS: Focus-based navigation with large display
//  - watchOS: Compact NavigationStack
//

import SwiftUI

// MARK: - ContentView

/// Root view that orchestrates navigation across all platforms.
struct ContentView: View {

    @EnvironmentObject var songStore: SongStore

    /// Deep-linked song ID to navigate to.
    @Binding var deepLinkedSongId: String?

    /// Quick action from Home Screen shortcut (not on watchOS).
    #if !os(watchOS)
    @Binding var quickAction: iHymnsApp.QuickAction?
    #endif

    @State private var selectedTab: AppTab = .songbooks
    @State private var globalSearchText: String = ""
    @State private var selectedSongbookId: String?
    @State private var showingDeepLinkedSong: Bool = false

    enum AppTab: Hashable {
        case songbooks, favourites, search, setLists, settings, help
    }

    // MARK: Init helpers for platform differences

    #if os(watchOS)
    init(deepLinkedSongId: Binding<String?>) {
        self._deepLinkedSongId = deepLinkedSongId
    }
    #else
    init(deepLinkedSongId: Binding<String?>, quickAction: Binding<iHymnsApp.QuickAction?>) {
        self._deepLinkedSongId = deepLinkedSongId
        self._quickAction = quickAction
    }
    #endif

    var body: some View {
        ZStack {
            #if os(watchOS)
            watchOSLayout
            #elseif os(tvOS)
            tvOSLayout
            #else
            adaptiveLayout
            #endif

            // Offline banner overlay
            offlineBanner
        }
        .onChange(of: deepLinkedSongId) { _, newValue in
            if newValue != nil {
                showingDeepLinkedSong = true
            }
        }
        #if !os(watchOS)
        .onChange(of: quickAction) { _, action in
            handleQuickAction(action)
        }
        #endif
        .sheet(isPresented: $showingDeepLinkedSong) {
            if let songId = deepLinkedSongId,
               let song = songStore.song(byId: songId) {
                NavigationStack {
                    SongDetailView(song: song)
                        .environmentObject(songStore)
                        .toolbar {
                            ToolbarItem(placement: .cancellationAction) {
                                Button("Done") {
                                    showingDeepLinkedSong = false
                                    deepLinkedSongId = nil
                                }
                            }
                        }
                }
            }
        }
    }

    // MARK: - watchOS Layout

    #if os(watchOS)
    private var watchOSLayout: some View {
        NavigationStack {
            SongbookListView()
                .navigationTitle("iHymns")
        }
        .tint(AmberTheme.accent)
    }
    #endif

    // MARK: - tvOS Layout

    #if os(tvOS)
    private var tvOSLayout: some View {
        NavigationStack {
            VStack(spacing: 40) {
                Text("iHymns")
                    .font(.system(size: 64, weight: .bold))
                    .foregroundStyle(AmberTheme.accent)

                SongbookListView()
            }
            .padding(60)
        }
        .tint(AmberTheme.accent)
    }
    #endif

    // MARK: - Adaptive Layout (iOS / iPadOS / macOS / visionOS)

    #if !os(watchOS) && !os(tvOS)
    private var adaptiveLayout: some View {
        AdaptiveNavigationView(
            selectedTab: $selectedTab,
            globalSearchText: $globalSearchText,
            selectedSongbookId: $selectedSongbookId
        )
        .tint(AmberTheme.accent)
    }
    #endif

    // MARK: - Offline Banner

    @Environment(NetworkMonitor.self) private var networkMonitor: NetworkMonitor?

    private var offlineBanner: some View {
        VStack {
            if let monitor = networkMonitor, !monitor.isConnected {
                HStack(spacing: Spacing.sm) {
                    Image(systemName: "wifi.slash")
                    Text("Offline — using cached data")
                        .font(.caption.weight(.medium))
                }
                .foregroundStyle(.white)
                .padding(.horizontal, Spacing.lg)
                .padding(.vertical, Spacing.sm)
                .background(
                    Capsule()
                        .fill(.ultraThinMaterial)
                        .overlay(
                            Capsule()
                                .fill(Color.orange.opacity(0.3))
                        )
                )
                .padding(.top, Spacing.sm)
                .transition(.move(edge: .top).combined(with: .opacity))
            }
            Spacer()
        }
        .animation(.liquidGlassSpring, value: networkMonitor?.isConnected)
    }

    // MARK: - Quick Action Handling

    #if !os(watchOS)
    private func handleQuickAction(_ action: iHymnsApp.QuickAction?) {
        guard let action else { return }
        switch action {
        case .search:    selectedTab = .search
        case .favorites: selectedTab = .favourites
        case .random:    break  // Handled by the songbook view
        case .setlist:   selectedTab = .setLists
        }
        quickAction = nil
    }
    #endif
}

// MARK: - AdaptiveNavigationView

#if !os(watchOS) && !os(tvOS)
private struct AdaptiveNavigationView: View {

    @EnvironmentObject var songStore: SongStore
    @Environment(\.horizontalSizeClass) private var sizeClass

    @Binding var selectedTab: ContentView.AppTab
    @Binding var globalSearchText: String
    @Binding var selectedSongbookId: String?

    var body: some View {
        if sizeClass == .compact {
            compactTabView
        } else {
            regularSplitView
        }
    }

    // MARK: Compact Layout (TabView)

    private var compactTabView: some View {
        TabView(selection: $selectedTab) {
            NavigationStack {
                SongbookListView()
                    .navigationTitle("Songbooks")
            }
            .tabItem {
                Label("Songbooks", systemImage: "books.vertical")
            }
            .tag(ContentView.AppTab.songbooks)

            NavigationStack {
                FavoritesView()
                    .navigationTitle("Favourites")
            }
            .tabItem {
                Label("Favourites", systemImage: "star.fill")
            }
            .tag(ContentView.AppTab.favourites)

            NavigationStack {
                SearchView()
                    .navigationTitle("Search")
            }
            .tabItem {
                Label("Search", systemImage: "magnifyingglass")
            }
            .tag(ContentView.AppTab.search)

            NavigationStack {
                SetListsView()
                    .navigationTitle("Set Lists")
            }
            .tabItem {
                Label("Set Lists", systemImage: "list.bullet.rectangle")
            }
            .tag(ContentView.AppTab.setLists)

            NavigationStack {
                SettingsView()
                    .navigationTitle("Settings")
            }
            .tabItem {
                Label("Settings", systemImage: "gear")
            }
            .tag(ContentView.AppTab.settings)
        }
    }

    // MARK: Regular Layout (NavigationSplitView)

    private var regularSplitView: some View {
        NavigationSplitView {
            SongbookListView(selectedSongbookId: $selectedSongbookId)
                .navigationTitle("iHymns")
                .searchable(text: $globalSearchText, prompt: "Search all songs...")
                .toolbar {
                    ToolbarItem(placement: .automatic) {
                        NavigationLink(destination: FavoritesView()) {
                            Label("Favourites", systemImage: "star.fill")
                        }
                    }
                    ToolbarItem(placement: .automatic) {
                        NavigationLink(destination: SetListsView()) {
                            Label("Set Lists", systemImage: "list.bullet.rectangle")
                        }
                    }
                    ToolbarItem(placement: .automatic) {
                        NavigationLink(destination: SettingsView()) {
                            Label("Settings", systemImage: "gear")
                        }
                    }
                    ToolbarItem(placement: .automatic) {
                        NavigationLink(destination: HelpView()) {
                            Label("Help", systemImage: "questionmark.circle")
                        }
                    }
                }
        } detail: {
            if let songbookId = selectedSongbookId {
                SongListView(songbookId: songbookId)
            } else if !globalSearchText.isEmpty {
                SearchView(initialSearchText: globalSearchText)
            } else {
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
    #if os(watchOS)
    ContentView(deepLinkedSongId: .constant(nil))
        .environmentObject(SongStore())
    #else
    ContentView(deepLinkedSongId: .constant(nil), quickAction: .constant(nil))
        .environmentObject(SongStore())
    #endif
}
#endif

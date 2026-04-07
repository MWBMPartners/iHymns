// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  iHymnsApp.swift
//  iHymns
//
//  The main entry point for the iHymns universal Apple app.
//  Bootstraps the SwiftUI application across all Apple platforms
//  (iOS, iPadOS, macOS, tvOS, watchOS, visionOS), initialises the
//  central data store, configures platform-specific features, and
//  handles deep linking / URL routing.
//

import SwiftUI

/// The root application struct — single entry point for all platforms.
@main
struct iHymnsApp: App {

    // MARK: - State

    /// Shared data store injected into the entire view hierarchy.
    @StateObject private var songStore = SongStore()

    /// Network connectivity monitor.
    @State private var networkMonitor = NetworkMonitor()

    /// The deep-linked song ID to navigate to, if any.
    @State private var deepLinkedSongId: String?

    /// Shared set list ID from a deep link.
    @State private var deepLinkedSetListId: String?
    @State private var showingSharedSetList = false

    /// Whether the first-launch disclaimer has been accepted.
    @State private var disclaimerAccepted = DisclaimerManager.isAccepted

    /// Quick action from Home Screen shortcut.
    @State private var quickAction: QuickAction?

    /// Scene phase for lifecycle events.
    @Environment(\.scenePhase) private var scenePhase

    /// CloudKit sync manager.
    @State private var cloudKitSync = CloudKitSyncManager()

    // MARK: - Quick Action Types

    enum QuickAction: String {
        case search = "ltd.mwbmpartners.ihymns.search"
        case favorites = "ltd.mwbmpartners.ihymns.favorites"
        case random = "ltd.mwbmpartners.ihymns.random"
        case setlist = "ltd.mwbmpartners.ihymns.setlist"
    }

    // MARK: - Body

    var body: some Scene {
        WindowGroup {
            #if os(watchOS)
            // watchOS: Minimal NavigationStack
            NavigationStack {
                ContentView(deepLinkedSongId: $deepLinkedSongId)
                    .environmentObject(songStore)
                    .environment(networkMonitor)
            }
            #else
            // All other platforms
            if disclaimerAccepted {
                ContentView(deepLinkedSongId: $deepLinkedSongId, quickAction: $quickAction)
                    .environmentObject(songStore)
                    .environment(networkMonitor)
                    .preferredColorScheme(songStore.preferences.theme.colorScheme)
                    .environment(\.dynamicTypeSize, .large)
                    .onOpenURL { url in
                        handleDeepLink(url)
                    }
                    .checkForSongUpdates()
                    .sheet(isPresented: $showingSharedSetList) {
                        if let setListId = deepLinkedSetListId {
                            SharedSetListView(shareId: setListId)
                                .environmentObject(songStore)
                        }
                    }
                    .task {
                        await setupPlatformFeatures()
                    }
            } else {
                NavigationStack {
                    FirstLaunchDisclaimerView {
                        DisclaimerManager.markAccepted()
                        disclaimerAccepted = true
                    }
                }
                .environmentObject(songStore)
            }
            #endif
        }
        .tint(AmberTheme.accent)
        #if os(tvOS)
        .defaultSize(width: 1920, height: 1080)
        #endif
        #if os(visionOS)
        .defaultSize(width: 1280, height: 720)
        #endif
        .onChange(of: scenePhase) { _, newPhase in
            switch newPhase {
            case .background:
                // Flush analytics and sync to CloudKit on background
                AnalyticsService.shared.flushPendingEvents()
                AnalyticsService.shared.stopSessionHeartbeat()
                cloudKitSync.pushToCloud(songStore: songStore)
            case .active:
                // Restart heartbeat and pull CloudKit changes
                if AnalyticsService.shared.isTrackingEnabled {
                    AnalyticsService.shared.startSessionHeartbeat()
                }
                cloudKitSync.pullFromCloud(songStore: songStore)
            default:
                break
            }
        }

        #if os(macOS)
        // macOS: Settings window
        Settings {
            SettingsView()
                .environmentObject(songStore)
        }

        // macOS: Additional window for presentation mode
        WindowGroup("Presentation", id: "presentation") {
            PresentationView()
                .environmentObject(songStore)
        }
        #endif
    }

    // MARK: - Deep Linking

    /// Handles incoming URLs for deep linking, Handoff, and Universal Links.
    /// Supports custom scheme (ihymns://) and all ihymns.app URL patterns.
    /// Normalises short song IDs (e.g., "MP-1" → "MP-0001").
    private func handleDeepLink(_ url: URL) {
        // Custom scheme: ihymns://song/{songId}
        if url.scheme == "ihymns" {
            let host = url.host ?? ""
            let path = url.pathComponents.dropFirst()

            switch host {
            case "song":
                if let songId = path.first { deepLinkedSongId = normaliseSongId(songId) }
            case "search":
                // ihymns://search?q=query
                if let query = URLComponents(url: url, resolvingAgainstBaseURL: false)?
                    .queryItems?.first(where: { $0.name == "q" })?.value {
                    UserDefaults.standard.set(query, forKey: "ihymns_intent_search_query")
                    quickAction = .search
                }
            case "favorites", "favourites":
                quickAction = .favorites
            case "setlist":
                quickAction = .setlist
            default:
                break
            }
            return
        }

        // Universal Links: https://ihymns.app/*
        guard url.host == "ihymns.app" || url.host == "www.ihymns.app" else { return }
        let components = url.pathComponents  // ["/" , "song", "CP-0001"]

        guard components.count >= 2 else {
            // Root URL: ihymns.app/ — open home
            return
        }

        switch components[1] {
        case "song" where components.count >= 3:
            deepLinkedSongId = normaliseSongId(components[2])

        case "songbook" where components.count >= 3:
            // /songbook/{id} — open songbook songs list
            UserDefaults.standard.set(components[2], forKey: "ihymns_deeplink_songbook")
            quickAction = .search  // Navigate to songbooks area

        case "songbooks":
            // /songbooks — home/songbooks tab (default)
            break

        case "search":
            quickAction = .search

        case "favorites", "favourites":
            quickAction = .favorites

        case "setlist":
            if components.count >= 4 && components[2] == "shared" {
                deepLinkedSetListId = components[3]
                showingSharedSetList = true
            } else {
                quickAction = .setlist
            }

        case "settings":
            // Navigate to settings (store flag for ContentView to read)
            UserDefaults.standard.set(true, forKey: "ihymns_deeplink_settings")

        case "help":
            UserDefaults.standard.set(true, forKey: "ihymns_deeplink_help")

        case "clip":
            // App Clip URLs: /clip/song/{id}, /clip/setlist/{id}, /clip/sotd
            if components.count >= 3 {
                switch components[2] {
                case "song" where components.count >= 4:
                    deepLinkedSongId = normaliseSongId(components[3])
                case "setlist" where components.count >= 4:
                    deepLinkedSetListId = components[3]
                    showingSharedSetList = true
                case "sotd":
                    UserDefaults.standard.set("songOfTheDay", forKey: "ihymns_intent_action")
                default: break
                }
            }

        default:
            break
        }
    }

    /// Normalises a song ID to the canonical format (e.g., "MP-1" → "MP-0001").
    private func normaliseSongId(_ rawId: String) -> String {
        let parts = rawId.split(separator: "-")
        guard parts.count == 2, let number = Int(parts[1]) else { return rawId }
        return "\(parts[0])-\(String(format: "%04d", number))"
    }

    // MARK: - Platform Setup

    /// Initialises platform-specific features after launch.
    private func setupPlatformFeatures() async {
        // Start network monitoring
        networkMonitor.start()

        // Register and schedule background refresh
        BackgroundRefreshManager.shared.registerBackgroundTask()
        BackgroundRefreshManager.shared.scheduleBackgroundRefresh()

        let platformManager = PlatformFeatureManager.shared

        // Setup notifications (Song of the Day)
        await platformManager.setupNotifications()

        // Setup Home Screen quick actions
        platformManager.setupQuickActions()

        // Index songs in Spotlight
        if let songs = songStore.songData?.songs {
            await platformManager.indexSongsInSpotlight(songs: songs)
        }

        // Setup CloudKit sync
        cloudKitSync.setup()
        cloudKitSync.pullFromCloud(songStore: songStore)

        // Configure TipKit (iOS 17+)
        #if swift(>=5.9)
        if #available(iOS 17.0, macOS 14.0, watchOS 10.0, *) {
            TipKitConfiguration.configure()
        }
        #endif

        // Sync song data from API
        await songStore.syncFromAPI()

        // Start analytics session heartbeat if consent granted
        if AnalyticsService.shared.isTrackingEnabled {
            AnalyticsService.shared.startSessionHeartbeat()
        }
    }
}

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

    /// Handles incoming URLs for deep linking and Handoff.
    /// Supports both custom scheme (ihymns://) and universal links.
    /// Normalises short song IDs (e.g., "MP-1" → "MP-0001").
    private func handleDeepLink(_ url: URL) {
        // Handle ihymns://song/{songId}
        if url.scheme == "ihymns" {
            if url.host == "song", let songId = url.pathComponents.dropFirst().first {
                deepLinkedSongId = normaliseSongId(songId)
            }
        }

        // Handle https://ihymns.app/song/{songId}
        if url.host == "ihymns.app" {
            let components = url.pathComponents
            if components.count >= 3 && components[1] == "song" {
                deepLinkedSongId = normaliseSongId(components[2])
            }
            // Handle shared set lists: /setlist/shared/{id}
            if components.count >= 4 && components[1] == "setlist" && components[2] == "shared" {
                deepLinkedSetListId = components[3]
                showingSharedSetList = true
            }
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

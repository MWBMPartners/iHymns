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

    /// Quick action from Home Screen shortcut.
    @State private var quickAction: QuickAction?

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
            ContentView(deepLinkedSongId: $deepLinkedSongId, quickAction: $quickAction)
                .environmentObject(songStore)
                .environment(networkMonitor)
                .onOpenURL { url in
                    handleDeepLink(url)
                }
                .task {
                    await setupPlatformFeatures()
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
    private func handleDeepLink(_ url: URL) {
        // Handle ihymns://song/{songId}
        if url.scheme == "ihymns" {
            if url.host == "song", let songId = url.pathComponents.dropFirst().first {
                deepLinkedSongId = songId
            }
        }

        // Handle https://ihymns.app/song/{songId}
        if url.host == "ihymns.app" {
            let components = url.pathComponents
            if components.count >= 3 && components[1] == "song" {
                deepLinkedSongId = components[2]
            }
        }
    }

    // MARK: - Platform Setup

    /// Initialises platform-specific features after launch.
    private func setupPlatformFeatures() async {
        // Start network monitoring
        networkMonitor.start()

        let platformManager = PlatformFeatureManager.shared

        // Setup notifications (Song of the Day)
        await platformManager.setupNotifications()

        // Setup Home Screen quick actions
        platformManager.setupQuickActions()

        // Index songs in Spotlight
        if let songs = songStore.songData?.songs {
            await platformManager.indexSongsInSpotlight(songs: songs)
        }

        // Sync song data from API
        await songStore.syncFromAPI()
    }
}

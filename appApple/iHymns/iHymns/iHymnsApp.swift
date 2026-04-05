// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  iHymnsApp.swift
//  iHymns
//
//  The main entry point for the iHymns universal Apple app.
//  This file bootstraps the SwiftUI application, initialises the
//  central data store, configures platform-specific appearance, and
//  injects shared state into the view hierarchy via environment objects.
//

import SwiftUI

/// The root application struct, marked with `@main` so the system
/// knows this is the single entry point for every supported platform.
@main
struct iHymnsApp: App {

    // MARK: - State

    /// The shared data store that holds all songs, songbooks, metadata,
    /// and user favourites. Created once here and injected into the
    /// entire view tree via `.environmentObject`.
    @StateObject private var songStore = SongStore()

    // MARK: - Body

    /// The scene declaration that defines the app's window hierarchy.
    /// A single `WindowGroup` is used across all platforms; platform-
    /// specific modifiers are applied where appropriate.
    var body: some Scene {

        // A `WindowGroup` provides the main window on every platform.
        // On iPadOS it also enables multi-window support automatically.
        WindowGroup {

            #if os(watchOS)
            // watchOS uses a slimmer navigation stack because the screen
            // is very small. We skip the full sidebar / split-view layout
            // and jump straight into a minimal list-based navigation.
            NavigationStack {
                // Pass the shared store into the view hierarchy so every
                // child view can read songs, favourites, etc.
                ContentView()
                    .environmentObject(songStore)
            }
            #else
            // All other platforms (iOS, iPadOS, macOS, tvOS, visionOS)
            // use the full ContentView which internally decides its own
            // navigation strategy (e.g. NavigationSplitView on iPad).
            ContentView()
                .environmentObject(songStore)
            #endif
        }
        // Apply the brand tint colour (amber #b45309) globally so that
        // interactive controls such as buttons, toggles, and navigation
        // links pick it up automatically across every platform.
        .tint(Color(red: 180.0 / 255.0, green: 83.0 / 255.0, blue: 9.0 / 255.0))

        #if os(tvOS)
        // On tvOS the default window can be quite large; we request a
        // sensible starting size so the UI does not stretch excessively
        // on very large displays. `.defaultSize` is available from
        // tvOS 17+ and visionOS 1+.
        .defaultSize(width: 1920, height: 1080)
        #endif

        #if os(visionOS)
        // On visionOS we also set a reasonable default window size so
        // the app opens at a comfortable reading width in the user's
        // spatial environment.
        .defaultSize(width: 1280, height: 720)
        #endif
    }
}

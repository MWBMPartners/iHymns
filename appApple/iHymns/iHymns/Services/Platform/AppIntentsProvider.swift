// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  AppIntentsProvider.swift
//  iHymns
//
//  App Intents for Siri voice commands and Shortcuts automation.
//  Enables: "Show me Mission Praise 742", "Play Song of the Day",
//  "Open my favourites", "Search for Amazing Grace"
//

import Foundation
import AppIntents

// MARK: - Search Songs Intent

@available(iOS 16.0, macOS 13.0, watchOS 9.0, *)
struct SearchSongsIntent: AppIntent {
    static var title: LocalizedStringResource = "Search Songs"
    static var description: IntentDescription = "Search for hymns by title, lyrics, writer, or number"
    static var openAppWhenRun: Bool = true

    @Parameter(title: "Query")
    var query: String

    func perform() async throws -> some IntentResult & ProvidesDialog {
        .result(dialog: "Searching for \"\(query)\" in iHymns...")
    }
}

// MARK: - View Song Intent

@available(iOS 16.0, macOS 13.0, watchOS 9.0, *)
struct ViewSongIntent: AppIntent {
    static var title: LocalizedStringResource = "View Song"
    static var description: IntentDescription = "Open a specific song by ID (e.g., MP-0742)"
    static var openAppWhenRun: Bool = true

    @Parameter(title: "Song ID")
    var songId: String

    func perform() async throws -> some IntentResult & ProvidesDialog {
        .result(dialog: "Opening song \(songId)...")
    }
}

// MARK: - Random Song Intent

@available(iOS 16.0, macOS 13.0, watchOS 9.0, *)
struct RandomSongIntent: AppIntent {
    static var title: LocalizedStringResource = "Random Song"
    static var description: IntentDescription = "Discover a random hymn from the collection"
    static var openAppWhenRun: Bool = true

    @Parameter(title: "Songbook", default: nil)
    var songbook: String?

    func perform() async throws -> some IntentResult & ProvidesDialog {
        let bookName = songbook ?? "all songbooks"
        return .result(dialog: "Finding a random song from \(bookName)...")
    }
}

// MARK: - View Favorites Intent

@available(iOS 16.0, macOS 13.0, watchOS 9.0, *)
struct ViewFavoritesIntent: AppIntent {
    static var title: LocalizedStringResource = "View Favourites"
    static var description: IntentDescription = "Open your favourite hymns"
    static var openAppWhenRun: Bool = true

    func perform() async throws -> some IntentResult & ProvidesDialog {
        .result(dialog: "Opening your favourites...")
    }
}

// MARK: - Song of the Day Intent

@available(iOS 16.0, macOS 13.0, watchOS 9.0, *)
struct SongOfTheDayIntent: AppIntent {
    static var title: LocalizedStringResource = "Song of the Day"
    static var description: IntentDescription = "View today's featured hymn"
    static var openAppWhenRun: Bool = true

    func perform() async throws -> some IntentResult & ProvidesDialog {
        .result(dialog: "Opening the Song of the Day...")
    }
}

// MARK: - App Shortcuts Provider

@available(iOS 16.0, macOS 13.0, watchOS 9.0, *)
struct iHymnsShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(
            intent: SearchSongsIntent(),
            phrases: [
                "Search for \(\.$query) in \(.applicationName)",
                "Find \(\.$query) in \(.applicationName)",
                "Look up \(\.$query) in \(.applicationName)"
            ],
            shortTitle: "Search Songs",
            systemImageName: "magnifyingglass"
        )

        AppShortcut(
            intent: RandomSongIntent(),
            phrases: [
                "Show me a random hymn in \(.applicationName)",
                "Discover a song in \(.applicationName)",
                "Shuffle songs in \(.applicationName)"
            ],
            shortTitle: "Random Song",
            systemImageName: "shuffle"
        )

        AppShortcut(
            intent: ViewFavoritesIntent(),
            phrases: [
                "Open my favourites in \(.applicationName)",
                "Show my favourite hymns in \(.applicationName)"
            ],
            shortTitle: "Favourites",
            systemImageName: "star.fill"
        )

        AppShortcut(
            intent: SongOfTheDayIntent(),
            phrases: [
                "What's today's hymn in \(.applicationName)",
                "Song of the Day in \(.applicationName)"
            ],
            shortTitle: "Song of the Day",
            systemImageName: "sparkles"
        )
    }
}

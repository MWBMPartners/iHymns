// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  InteractiveWidgets.swift
//  iHymns
//
//  Interactive widget intents for iOS 17+ WidgetKit features.
//  Enables tap-to-favourite and navigation from widgets.
//

import Foundation
import AppIntents

// MARK: - Toggle Favourite from Widget

@available(iOS 17.0, macOS 14.0, *)
struct ToggleFavouriteFromWidgetIntent: AppIntent {
    static var title: LocalizedStringResource = "Toggle Favourite"
    static var description: IntentDescription = "Add or remove a song from favourites"

    @Parameter(title: "Song ID")
    var songId: String

    init() {}

    init(songId: String) {
        self.songId = songId
    }

    func perform() async throws -> some IntentResult {
        // Toggle via UserDefaults (widget can't access SongStore directly)
        var favorites = Set(UserDefaults(suiteName: "group.com.mwbm.ihymns")?
            .stringArray(forKey: "ihymns_favorites") ?? [])

        if favorites.contains(songId) {
            favorites.remove(songId)
        } else {
            favorites.insert(songId)
        }

        UserDefaults(suiteName: "group.com.mwbm.ihymns")?
            .set(Array(favorites), forKey: "ihymns_favorites")
        UserDefaults.standard.set(Array(favorites), forKey: "ihymns_favorites")

        return .result()
    }
}

// MARK: - Next Song of the Day from Widget

@available(iOS 17.0, macOS 14.0, *)
struct RefreshSongOfTheDayIntent: AppIntent {
    static var title: LocalizedStringResource = "Refresh Song of the Day"
    static var description: IntentDescription = "Get a new Song of the Day suggestion"

    func perform() async throws -> some IntentResult {
        // Force widget timeline refresh
        return .result()
    }
}

// MARK: - Lock Screen Widget Entries

/// Data for Lock Screen accessory widgets.
struct LockScreenWidgetEntry {

    /// Accessory circular: songbook badge with count
    struct CircularData {
        let songbookId: String
        let favouriteCount: Int
    }

    /// Accessory rectangular: Song of the Day preview
    struct RectangularData {
        let songTitle: String
        let songbook: String
        let themeName: String?
    }

    /// Accessory inline: "Song of the Day: Amazing Grace"
    struct InlineData {
        let songTitle: String
    }
}

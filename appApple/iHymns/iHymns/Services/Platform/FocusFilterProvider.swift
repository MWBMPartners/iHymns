// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  FocusFilterProvider.swift
//  iHymns
//
//  iOS Focus Filters for contextual song filtering per Focus mode.
//

import Foundation
import AppIntents

// MARK: - iHymns Focus Filter

@available(iOS 16.0, *)
struct iHymnsFocusFilter: SetFocusFilterIntent {

    static var title: LocalizedStringResource = "iHymns Filter"
    static var description: IntentDescription? = "Filter songs and features based on your current Focus mode."

    @Parameter(title: "Songbook Filter", description: "Only show songs from this songbook")
    var songbookFilter: String?

    @Parameter(title: "Tag Filter", description: "Only show favourites with this tag")
    var tagFilter: String?

    @Parameter(title: "Minimal Mode", description: "Hide settings, statistics, and other non-essential features")
    var minimalMode: Bool

    func perform() async throws -> some IntentResult {
        // Store the active focus filter for the app to read
        UserDefaults.standard.set(songbookFilter, forKey: "ihymns_focus_songbook")
        UserDefaults.standard.set(tagFilter, forKey: "ihymns_focus_tag")
        UserDefaults.standard.set(minimalMode, forKey: "ihymns_focus_minimal")
        return .result()
    }
}

// MARK: - Focus Filter Reader

/// Reads the active Focus filter settings.
enum FocusFilterReader {

    static var activeSongbookFilter: String? {
        UserDefaults.standard.string(forKey: "ihymns_focus_songbook")
    }

    static var activeTagFilter: String? {
        UserDefaults.standard.string(forKey: "ihymns_focus_tag")
    }

    static var isMinimalMode: Bool {
        UserDefaults.standard.bool(forKey: "ihymns_focus_minimal")
    }

    /// Clears all focus filter settings (called when Focus deactivates).
    static func clearFilters() {
        UserDefaults.standard.removeObject(forKey: "ihymns_focus_songbook")
        UserDefaults.standard.removeObject(forKey: "ihymns_focus_tag")
        UserDefaults.standard.removeObject(forKey: "ihymns_focus_minimal")
    }
}

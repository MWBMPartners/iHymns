// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  SetList.swift
//  iHymns
//
//  Defines the data models for worship set lists / playlists.
//  Set lists allow users to create ordered collections of songs
//  for worship services, which can be reordered, shared, and
//  navigated sequentially.
//

import Foundation

// MARK: - SetList

/// Represents a user-created worship set list containing an ordered
/// collection of song references. Persisted locally to UserDefaults
/// and optionally shared via the iHymns API.
struct SetList: Codable, Identifiable, Hashable {

    /// Unique local identifier for this set list.
    let id: UUID

    /// The user-assigned name for this set list (e.g., "Sunday Service").
    var name: String

    /// Ordered array of song IDs included in this set list.
    var songIds: [String]

    /// The date this set list was created.
    let createdAt: Date

    /// The date this set list was last modified.
    var updatedAt: Date

    /// Optional server-side share ID (8-character hex string).
    /// Non-nil when the set list has been shared via the API.
    var shareId: String?

    // MARK: Initialiser

    /// Creates a new set list with the given name and optional song IDs.
    init(
        id: UUID = UUID(),
        name: String,
        songIds: [String] = [],
        createdAt: Date = Date(),
        updatedAt: Date = Date(),
        shareId: String? = nil
    ) {
        self.id = id
        self.name = name
        self.songIds = songIds
        self.createdAt = createdAt
        self.updatedAt = updatedAt
        self.shareId = shareId
    }

    // MARK: Mutations

    /// Adds a song ID to the end of the set list.
    mutating func addSong(_ songId: String) {
        songIds.append(songId)
        updatedAt = Date()
    }

    /// Removes the song at the specified index.
    mutating func removeSong(at index: Int) {
        guard songIds.indices.contains(index) else { return }
        songIds.remove(at: index)
        updatedAt = Date()
    }

    /// Moves a song from one position to another.
    mutating func moveSong(from source: IndexSet, to destination: Int) {
        songIds.move(fromOffsets: source, toOffset: destination)
        updatedAt = Date()
    }
}

// MARK: - SearchHistory

/// A single search history entry, stored in the recents list.
struct SearchHistoryEntry: Codable, Identifiable, Hashable {

    /// Unique identifier for this entry.
    let id: UUID

    /// The search query text.
    let query: String

    /// When the search was performed.
    let timestamp: Date

    init(query: String) {
        self.id = UUID()
        self.query = query
        self.timestamp = Date()
    }
}

// MARK: - ViewHistory

/// A single entry in the song view history.
struct ViewHistoryEntry: Codable, Identifiable, Hashable {

    /// Unique identifier for this entry.
    let id: UUID

    /// The ID of the viewed song.
    let songId: String

    /// The title of the viewed song (cached for display without lookup).
    let songTitle: String

    /// The songbook abbreviation.
    let songbook: String

    /// When the song was viewed.
    var timestamp: Date

    init(songId: String, songTitle: String, songbook: String) {
        self.id = UUID()
        self.songId = songId
        self.songTitle = songTitle
        self.songbook = songbook
        self.timestamp = Date()
    }
}

// MARK: - UserPreferences

/// Centralised user preferences stored in UserDefaults.
/// Mirrors the localStorage keys from the web PWA.
struct UserPreferences: Codable {

    /// The user's preferred colour scheme.
    var theme: AppTheme = .system

    /// Lyrics font size multiplier (0.5x to 5.0x).
    var lyricsFontScale: Double = 1.0

    /// Line spacing preference.
    var lineSpacing: LineSpacingOption = .normal

    /// Whether to show verse/chorus labels.
    var showComponentLabels: Bool = true

    /// Whether to highlight chorus sections with an accent bar.
    var highlightChorus: Bool = true

    /// Whether to include lyrics content in search results.
    var searchLyrics: Bool = true

    /// The default songbook to pre-select in number lookup.
    var defaultSongbook: String?

    /// Whether to auto-update song data from the API.
    var autoUpdateSongs: Bool = true

    /// Whether to reduce motion/animations.
    var reduceMotion: Bool = false

    /// Auto-scroll speed in pixels per second (0 = disabled).
    var autoScrollSpeed: Double = 0

    // MARK: Theme Enum

    enum AppTheme: String, Codable, CaseIterable {
        case system = "system"
        case light = "light"
        case dark = "dark"
        case highContrast = "highContrast"

        var displayName: String {
            switch self {
            case .system:       return "System"
            case .light:        return "Light"
            case .dark:         return "Dark"
            case .highContrast: return "High Contrast"
            }
        }

        var colorScheme: ColorScheme? {
            switch self {
            case .system:       return nil
            case .light:        return .light
            case .dark:         return .dark
            case .highContrast: return .dark
            }
        }
    }

    // MARK: Line Spacing Enum

    enum LineSpacingOption: String, Codable, CaseIterable {
        case compact = "compact"
        case normal = "normal"
        case spacious = "spacious"

        var displayName: String {
            switch self {
            case .compact:  return "Compact"
            case .normal:   return "Normal"
            case .spacious: return "Spacious"
            }
        }

        var value: CGFloat {
            switch self {
            case .compact:  return 2
            case .normal:   return 6
            case .spacious: return 12
            }
        }
    }
}

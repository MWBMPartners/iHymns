// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  Songbook.swift
//  iHymns
//
//  Defines the `Songbook` model which represents one hymnal / song
//  collection. Each songbook entry in songs.json contains a short
//  identifier, a human-readable name, and the total number of songs
//  it contains.
//

import Foundation

// MARK: - Songbook

/// Represents a single songbook (hymnal) in the iHymns catalogue.
/// Conforms to `Codable` for JSON decoding, `Identifiable` for use
/// in SwiftUI lists, and `Hashable` so it can be used as a
/// navigation destination value or in a `Set`.
struct Songbook: Codable, Identifiable, Hashable {

    /// A short unique identifier for the songbook.
    /// Examples: "CP" (Carol Praise), "JP" (Junior Praise),
    /// "MP" (Mission Praise), "SDAH" (Seventh-day Adventist Hymnal),
    /// "CH" (The Church Hymnal).
    let id: String

    /// The full human-readable display name of the songbook.
    /// Examples: "Carol Praise", "Mission Praise".
    let name: String

    /// The total number of songs contained in this songbook.
    /// Used for display purposes in songbook listing views
    /// (e.g. "Mission Praise — 1,355 songs").
    let songCount: Int
}

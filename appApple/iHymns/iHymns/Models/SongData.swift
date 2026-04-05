// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  SongData.swift
//  iHymns
//
//  Defines the top-level JSON wrapper structures that mirror the
//  root shape of songs.json. The file contains:
//    - `SongData`  — the outermost container with meta, songbooks, and songs.
//    - `SongMeta`  — generation metadata embedded in the "meta" key.
//
//  When the bundled songs.json is decoded, the decoder produces a
//  single `SongData` instance from which the app can access every
//  songbook and every song.
//

import Foundation

// MARK: - SongData

/// The top-level container decoded from songs.json. It wraps three
/// sections: metadata about the data file itself, the list of
/// songbooks, and the full catalogue of songs.
struct SongData: Codable {

    /// Metadata about when and how the songs.json file was generated.
    /// Includes timestamps, generator version, and summary counts.
    let meta: SongMeta

    /// The ordered array of all songbooks (hymnals) available in the
    /// data set. Each entry contains the songbook's id, name, and
    /// song count.
    let songbooks: [Songbook]

    /// The ordered array of every song across all songbooks. Songs
    /// reference their parent songbook via the `songbook` property.
    let songs: [Song]
}

// MARK: - SongMeta

/// Contains metadata about the songs.json generation process.
/// This information is useful for debugging, cache invalidation,
/// and displaying data freshness to the user.
struct SongMeta: Codable {

    /// An ISO-8601 date-time string indicating when the JSON file
    /// was generated (e.g. "2026-04-05T18:08:32.577Z").
    let generatedAt: String

    /// The semantic version of the generator tool that produced the
    /// JSON file (e.g. "1.0.0").
    let generatorVersion: String

    /// The total number of individual songs across all songbooks
    /// contained in this data file.
    let totalSongs: Int

    /// The total number of distinct songbooks contained in this
    /// data file.
    let totalSongbooks: Int
}

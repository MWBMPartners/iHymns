// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  Song.swift
//  iHymns
//
//  Defines the `Song` data model and its nested `SongComponent` type.
//  These structures mirror the shape of each song object in songs.json
//  and conform to `Codable` for JSON decoding, `Identifiable` for use
//  in SwiftUI lists, and `Hashable` for use in navigation destinations
//  and sets.
//

import Foundation

// MARK: - Song

/// Represents a single hymn / song entry as stored in the bundled
/// songs.json data file. Every property maps 1-to-1 with the
/// corresponding JSON key.
struct Song: Codable, Identifiable, Hashable {

    // MARK: Identification

    /// A unique string identifier for this song, formatted as
    /// "{songbook}-{paddedNumber}" (e.g. "CP-0001", "MP-0742").
    let id: String

    /// The song's number within its songbook (1-based).
    let number: Int

    /// The human-readable title of the song (e.g. "Amazing Grace").
    let title: String

    // MARK: Songbook association

    /// The short identifier of the songbook this song belongs to
    /// (e.g. "CP", "JP", "MP", "SDAH", "CH").
    let songbook: String

    /// The full display name of the songbook
    /// (e.g. "Carol Praise", "Mission Praise").
    let songbookName: String

    // MARK: Credits

    /// An array of writer / lyricist names associated with this song.
    /// May be empty if the writer is unknown.
    let writers: [String]

    /// An array of composer names associated with this song.
    /// May be empty if the composer is unknown.
    let composers: [String]

    /// Copyright information string for this song.
    /// May be empty if no copyright data is available.
    let copyright: String

    /// The CCLI (Christian Copyright Licensing International) number
    /// for this song as a string. May be empty.
    let ccli: String

    // MARK: Media availability flags

    /// Whether an audio recording is available for this song.
    let hasAudio: Bool

    /// Whether sheet music (PDF / image) is available for this song.
    let hasSheetMusic: Bool

    // MARK: Lyrics

    /// The ordered list of lyrical components (verses, choruses, etc.)
    /// that make up this song's full text.
    let components: [SongComponent]

    // MARK: - Computed Properties

    /// A short preview of the song's lyrics, built by taking the first
    /// two lines of the very first component and joining them with a
    /// space. Useful for search result previews and list subtitles.
    var lyricsPreview: String {
        // Guard against songs that have no components or empty lines.
        guard let firstComponent = components.first else {
            // Return an empty string when no lyrics are available.
            return ""
        }
        // Take at most the first 2 lines and join with a single space.
        return firstComponent.lines.prefix(2).joined(separator: " ")
    }

    /// All lyrics from every component concatenated into a single
    /// string, separated by spaces. This flattened representation is
    /// intended for full-text search — callers can simply check
    /// whether this string contains a search query.
    var allLyrics: String {
        // Flat-map every component's lines array into one sequence,
        // then join with spaces so words across lines are separated.
        return components
            .flatMap { $0.lines }  // Flatten all line arrays into one sequence.
            .joined(separator: " ") // Combine into a single searchable string.
    }

    /// A display-friendly string listing all writers separated by
    /// commas (e.g. "Charles Wesley, Isaac Watts"). Returns an empty
    /// string if the writers array is empty.
    var writersDisplay: String {
        // Join the writers array with a comma and space delimiter.
        return writers.joined(separator: ", ")
    }
}

// MARK: - SongComponent

/// Represents a single structural part of a song's lyrics, such as a
/// verse, chorus, or bridge. The JSON data does not include an `id`
/// field, so we generate a stable `UUID` at decode time and exclude
/// it from the `CodingKeys` so the decoder does not look for it.
struct SongComponent: Codable, Identifiable, Hashable {

    // MARK: Properties

    /// A locally-generated unique identifier used by SwiftUI to
    /// differentiate components in `ForEach` and `List` views.
    /// This is NOT decoded from JSON — it is created automatically
    /// when the struct is initialised.
    let id: UUID

    /// The type of this component as a lowercase string.
    /// Common values: "verse", "chorus", "bridge", "pre-chorus".
    let type: String

    /// The optional ordinal number for this component within the
    /// song (e.g. verse 1, verse 2). Choruses and bridges often
    /// have `nil` here because they are not numbered.
    let number: Int?

    /// The ordered array of text lines that make up this component.
    /// Each element is one line of lyrics as it should be displayed.
    let lines: [String]

    // MARK: CodingKeys

    /// We explicitly list the keys that exist in the JSON payload.
    /// `id` is omitted so the `Decodable` synthesised init does not
    /// attempt to decode it from the JSON (it would fail since the
    /// JSON has no "id" key on components).
    private enum CodingKeys: String, CodingKey {
        case type    // Maps to "type" in JSON.
        case number  // Maps to "number" in JSON.
        case lines   // Maps to "lines" in JSON.
    }

    // MARK: Initialiser

    /// Creates a new `SongComponent` from a decoder. Because `id` is
    /// excluded from `CodingKeys`, we generate a fresh UUID here.
    /// All other properties are decoded normally from the JSON.
    init(from decoder: Decoder) throws {
        // Create a keyed container scoped to our CodingKeys.
        let container = try decoder.container(keyedBy: CodingKeys.self)

        // Generate a new UUID since the JSON does not supply one.
        self.id = UUID()

        // Decode the component type string (e.g. "verse", "chorus").
        self.type = try container.decode(String.self, forKey: .type)

        // Decode the optional number — nil for unnumbered components.
        self.number = try container.decodeIfPresent(Int.self, forKey: .number)

        // Decode the array of lyric lines.
        self.lines = try container.decode([String].self, forKey: .lines)
    }

    /// A convenience memberwise initialiser for creating components
    /// programmatically (e.g. in previews or tests). A UUID is
    /// generated automatically if none is supplied.
    init(id: UUID = UUID(), type: String, number: Int?, lines: [String]) {
        self.id = id       // Use the provided UUID or auto-generate one.
        self.type = type   // Set the component type.
        self.number = number // Set the optional ordinal number.
        self.lines = lines   // Set the lyric lines.
    }
}

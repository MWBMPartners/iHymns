// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  FuzzySearch.swift
//  iHymns
//
//  A lightweight fuzzy search engine for song matching. Provides
//  weighted scoring across multiple fields with typo tolerance,
//  matching the Fuse.js behaviour used in the PWA.
//
//  Scoring weights (matching PWA Fuse.js config):
//  - Title:     weight 3.0 (highest priority)
//  - Writers:   weight 2.0
//  - Composers: weight 2.0
//  - Songbook:  weight 1.5
//  - Number:    weight 1.5
//  - Lyrics:    weight 1.0 (optional, toggled by preference)
//

import Foundation

// MARK: - FuzzySearchEngine

/// A fuzzy search engine that scores songs against a query string
/// using weighted field matching and Levenshtein distance tolerance.
struct FuzzySearchEngine {

    // MARK: - Configuration

    /// Maximum Levenshtein distance for a token to be considered a fuzzy match.
    /// Higher values = more typo tolerance, but slower and noisier results.
    static let maxDistance: Int = 2

    /// Minimum query length before fuzzy matching activates.
    /// Shorter queries use exact substring matching only.
    static let fuzzyMinLength: Int = 3

    /// Field weights matching the PWA Fuse.js configuration.
    private static let weights: [(keyPath: KeyPath<Song, String>, weight: Double)] = [
        (\.title, 3.0),
        (\.writersDisplay, 2.0),
        (\.composersDisplay, 2.0),
        (\.songbookName, 1.5),
    ]

    // MARK: - Search

    /// Searches songs with weighted fuzzy scoring.
    ///
    /// - Parameters:
    ///   - query: The search text entered by the user.
    ///   - songs: The full catalogue of songs to search.
    ///   - includeLyrics: Whether to include lyrics content in the search.
    /// - Returns: Songs sorted by descending match score, filtered to those with score > 0.
    static func search(
        query: String,
        in songs: [Song],
        includeLyrics: Bool
    ) -> [Song] {
        let trimmed = query.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return [] }

        let queryLower = trimmed.lowercased()
        let queryTokens = queryLower.split(separator: " ").map(String.init)

        // Score each song
        var scored: [(song: Song, score: Double)] = []

        for song in songs {
            var totalScore: Double = 0

            // Score each weighted field
            for (keyPath, weight) in weights {
                let fieldValue = song[keyPath: keyPath].lowercased()
                let fieldScore = scoreField(query: queryLower, tokens: queryTokens, field: fieldValue)
                totalScore += fieldScore * weight
            }

            // Score song number (exact prefix match)
            let numberStr = String(song.number)
            if numberStr.hasPrefix(trimmed) || trimmed == numberStr {
                totalScore += 1.5 * (trimmed == numberStr ? 2.0 : 1.0)
            }

            // Score songbook abbreviation
            if song.songbook.lowercased().contains(queryLower) {
                totalScore += 1.5
            }

            // Score lyrics (optional)
            if includeLyrics {
                let lyricsScore = scoreLyrics(tokens: queryTokens, song: song)
                totalScore += lyricsScore * 1.0
            }

            if totalScore > 0 {
                scored.append((song, totalScore))
            }
        }

        // Sort by score descending, then by title for tie-breaking
        scored.sort { lhs, rhs in
            if lhs.score != rhs.score { return lhs.score > rhs.score }
            return lhs.song.title < rhs.song.title
        }

        return scored.map(\.song)
    }

    // MARK: - Field Scoring

    /// Scores a single field against the query.
    /// Returns a value between 0.0 (no match) and 2.0 (perfect match).
    private static func scoreField(query: String, tokens: [String], field: String) -> Double {
        // Exact match
        if field == query { return 2.0 }

        // Starts with query
        if field.hasPrefix(query) { return 1.8 }

        // Contains query as substring
        if field.contains(query) { return 1.5 }

        // Token-based matching: check if all query tokens appear in the field
        let allTokensMatch = tokens.allSatisfy { token in
            field.contains(token) || fuzzyContains(field: field, token: token)
        }
        if allTokensMatch && !tokens.isEmpty { return 1.2 }

        // Any single token match
        let matchingTokens = tokens.filter { token in
            field.contains(token) || fuzzyContains(field: field, token: token)
        }
        if !matchingTokens.isEmpty {
            return Double(matchingTokens.count) / Double(max(tokens.count, 1)) * 1.0
        }

        return 0
    }

    /// Scores lyrics content against query tokens.
    private static func scoreLyrics(tokens: [String], song: Song) -> Double {
        let allLyrics = song.allLyrics.lowercased()

        // Check for full query match in lyrics
        let fullQuery = tokens.joined(separator: " ")
        if allLyrics.contains(fullQuery) { return 1.0 }

        // Token-level matching
        let matchCount = tokens.filter { allLyrics.contains($0) }.count
        guard matchCount > 0 else { return 0 }

        return Double(matchCount) / Double(tokens.count) * 0.8
    }

    // MARK: - Fuzzy Matching

    /// Checks if a field fuzzy-contains a token using Levenshtein distance.
    /// Only activates for tokens >= `fuzzyMinLength` characters.
    private static func fuzzyContains(field: String, token: String) -> Bool {
        guard token.count >= fuzzyMinLength else { return false }

        // Slide a window of token.count ± 1 over the field
        let fieldChars = Array(field)
        let tokenLen = token.count

        for windowSize in [tokenLen, tokenLen - 1, tokenLen + 1] {
            guard windowSize > 0 && windowSize <= fieldChars.count else { continue }

            for start in 0...(fieldChars.count - windowSize) {
                let substring = String(fieldChars[start..<(start + windowSize)])
                if levenshteinDistance(substring, token) <= maxDistance {
                    return true
                }
            }
        }

        return false
    }

    /// Computes the Levenshtein edit distance between two strings.
    /// Used for typo tolerance in fuzzy matching.
    private static func levenshteinDistance(_ s1: String, _ s2: String) -> Int {
        let m = s1.count
        let n = s2.count

        if m == 0 { return n }
        if n == 0 { return m }

        let s1Arr = Array(s1)
        let s2Arr = Array(s2)

        // Use two rows instead of full matrix for O(n) space
        var prevRow = Array(0...n)
        var currRow = Array(repeating: 0, count: n + 1)

        for i in 1...m {
            currRow[0] = i
            for j in 1...n {
                let cost = s1Arr[i - 1] == s2Arr[j - 1] ? 0 : 1
                currRow[j] = min(
                    prevRow[j] + 1,       // deletion
                    currRow[j - 1] + 1,   // insertion
                    prevRow[j - 1] + cost  // substitution
                )
            }
            swap(&prevRow, &currRow)
        }

        return prevRow[n]
    }
}

// MARK: - Song Extension for Search

extension Song {
    /// Display-friendly string of composers, used for search scoring.
    var composersDisplay: String {
        composers.joined(separator: ", ")
    }
}

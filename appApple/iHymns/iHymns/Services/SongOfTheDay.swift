// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  SongOfTheDay.swift
//  iHymns
//
//  Deterministic Song of the Day engine with Christian calendar theme
//  awareness. Matches the PWA's `js/modules/song-of-the-day.js`.
//
//  Features:
//  - 16 Christian calendar themes with date-range calculations
//  - Easter date via Anonymous Gregorian algorithm (computus)
//  - Keyword matching on titles (weighted 2x) and lyrics (1x)
//  - Deterministic fallback for non-themed days
//  - Same song shown to all users on the same day
//

import Foundation

// MARK: - SongOfTheDayEngine

struct SongOfTheDayEngine {

    // MARK: - Calendar Theme

    struct CalendarTheme {
        let name: String
        let keywords: [String]
        let dateRange: (start: (Int, Int), end: (Int, Int))? // (month, day) static ranges
        let easterOffset: ClosedRange<Int>?                  // days relative to Easter
    }

    // MARK: - Easter Calculation (Anonymous Gregorian Algorithm)

    /// Computes the date of Easter Sunday for a given year using the
    /// Anonymous Gregorian algorithm (Meeus/Jones/Butcher).
    static func easterDate(year: Int) -> (month: Int, day: Int) {
        let a = year % 19
        let b = year / 100
        let c = year % 100
        let d = b / 4
        let e = b % 4
        let f = (b + 8) / 25
        let g = (b - f + 1) / 3
        let h = (19 * a + b - d - g + 15) % 30
        let i = c / 4
        let k = c % 4
        let l = (32 + 2 * e + 2 * i - h - k) % 7
        let m = (a + 11 * h + 22 * l) / 451
        let month = (h + l - 7 * m + 114) / 31
        let day = ((h + l - 7 * m + 114) % 31) + 1
        return (month, day)
    }

    /// Returns the Easter Sunday Date for a given year.
    static func easterSunday(year: Int) -> Date {
        let (month, day) = easterDate(year: year)
        var components = DateComponents()
        components.year = year
        components.month = month
        components.day = day
        return Calendar.current.date(from: components)!
    }

    // MARK: - Theme Definitions (16 themes)

    /// All 16 Christian calendar themes with their keywords and date ranges.
    static func themes(for year: Int) -> [CalendarTheme] {
        [
            // Fixed-date themes
            CalendarTheme(name: "New Year", keywords: ["new year", "beginning", "fresh", "renew"],
                          dateRange: (start: (1, 1), end: (1, 3)), easterOffset: nil),
            CalendarTheme(name: "Epiphany", keywords: ["epiphany", "wise men", "magi", "star", "kings"],
                          dateRange: (start: (1, 6), end: (1, 6)), easterOffset: nil),
            CalendarTheme(name: "Advent", keywords: ["advent", "come", "prepare", "waiting", "expectation"],
                          dateRange: (start: (12, 1), end: (12, 24)), easterOffset: nil),
            CalendarTheme(name: "Christmas", keywords: ["christmas", "bethlehem", "manger", "born", "nativity", "silent night", "baby", "shepherds", "angels"],
                          dateRange: (start: (12, 25), end: (12, 31)), easterOffset: nil),
            CalendarTheme(name: "Reformation", keywords: ["reformation", "faith", "grace", "scripture", "mighty fortress"],
                          dateRange: (start: (10, 31), end: (10, 31)), easterOffset: nil),
            CalendarTheme(name: "Harvest", keywords: ["harvest", "thanksgiving", "gather", "plough", "sow", "reap", "fruit"],
                          dateRange: (start: (9, 20), end: (10, 15)), easterOffset: nil),
            CalendarTheme(name: "Remembrance", keywords: ["remember", "peace", "sacrifice", "fallen", "memorial"],
                          dateRange: (start: (11, 8), end: (11, 14)), easterOffset: nil),

            // Easter-relative themes
            CalendarTheme(name: "Lent", keywords: ["lent", "repent", "fasting", "cross", "sacrifice", "forgive"],
                          dateRange: nil, easterOffset: -46 ... -7),
            CalendarTheme(name: "Palm Sunday", keywords: ["palm", "hosanna", "triumphal", "donkey", "jerusalem"],
                          dateRange: nil, easterOffset: -7 ... -7),
            CalendarTheme(name: "Holy Week", keywords: ["holy week", "passion", "suffering", "gethsemane", "calvary"],
                          dateRange: nil, easterOffset: -6 ... -2),
            CalendarTheme(name: "Good Friday", keywords: ["cross", "crucified", "calvary", "blood", "lamb", "suffering", "death"],
                          dateRange: nil, easterOffset: -2 ... -2),
            CalendarTheme(name: "Easter", keywords: ["easter", "risen", "resurrection", "alive", "tomb", "hallelujah", "victory"],
                          dateRange: nil, easterOffset: 0...7),
            CalendarTheme(name: "Ascension", keywords: ["ascension", "ascend", "heaven", "throne", "exalted", "reign"],
                          dateRange: nil, easterOffset: 39...39),
            CalendarTheme(name: "Pentecost", keywords: ["pentecost", "spirit", "fire", "wind", "tongues", "holy spirit", "power"],
                          dateRange: nil, easterOffset: 49...50),
            CalendarTheme(name: "Trinity Sunday", keywords: ["trinity", "three in one", "triune", "father son", "godhead"],
                          dateRange: nil, easterOffset: 56...56),

            // General fallback for seasons
            CalendarTheme(name: "General", keywords: [], dateRange: nil, easterOffset: nil),
        ]
    }

    // MARK: - Active Theme Detection

    /// Determines which calendar theme is active for a given date.
    /// Returns the theme name and keywords, or nil if no themed day.
    static func activeTheme(for date: Date) -> CalendarTheme? {
        let calendar = Calendar.current
        let year = calendar.component(.year, from: date)
        let month = calendar.component(.month, from: date)
        let day = calendar.component(.day, from: date)
        let easter = easterSunday(year: year)

        for theme in themes(for: year) {
            guard theme.name != "General" else { continue }

            // Check fixed date range
            if let range = theme.dateRange {
                let startMonth = range.start.0, startDay = range.start.1
                let endMonth = range.end.0, endDay = range.end.1

                if month == startMonth && month == endMonth {
                    if day >= startDay && day <= endDay { return theme }
                } else if month >= startMonth && month <= endMonth {
                    if (month == startMonth && day >= startDay) ||
                       (month == endMonth && day <= endDay) ||
                       (month > startMonth && month < endMonth) {
                        return theme
                    }
                }
            }

            // Check Easter-relative offset
            if let offset = theme.easterOffset {
                let daysFromEaster = calendar.dateComponents([.day], from: easter, to: date).day ?? 0
                if offset.contains(daysFromEaster) { return theme }
            }
        }

        return nil
    }

    // MARK: - Song Selection

    /// Selects the Song of the Day from the catalogue.
    /// 1. Check for an active calendar theme
    /// 2. Search for a matching song by keywords (title weighted 2x)
    /// 3. Fall back to deterministic index selection
    static func selectSong(from songs: [Song], for date: Date) -> (song: Song, themeName: String?)? {
        guard !songs.isEmpty else { return nil }

        // Check for active theme
        if let theme = activeTheme(for: date), !theme.keywords.isEmpty {
            if let match = findThemedSong(songs: songs, keywords: theme.keywords, date: date) {
                return (match, theme.name)
            }
        }

        // Deterministic fallback
        let calendar = Calendar.current
        let dayOfYear = calendar.ordinality(of: .day, in: .year, for: date) ?? 1
        let hour = calendar.component(.hour, from: date)
        let windowIndex = hour / 6
        let index = ((dayOfYear * 4) + windowIndex) % songs.count
        return (songs[index], nil)
    }

    /// Searches for a song matching theme keywords.
    /// Title matches weighted 2x over lyrics matches.
    /// Uses a deterministic seed so the same song is selected all day.
    private static func findThemedSong(songs: [Song], keywords: [String], date: Date) -> Song? {
        let calendar = Calendar.current
        let dayOfYear = calendar.ordinality(of: .day, in: .year, for: date) ?? 1

        var scored: [(song: Song, score: Int)] = []

        for song in songs {
            let titleLower = song.title.lowercased()
            let lyricsLower = song.allLyrics.lowercased()
            var score = 0

            for keyword in keywords {
                if titleLower.contains(keyword) { score += 2 }  // Title weighted 2x
                if lyricsLower.contains(keyword) { score += 1 }
            }

            if score > 0 {
                scored.append((song, score))
            }
        }

        guard !scored.isEmpty else { return nil }

        // Sort by score descending, then deterministically pick based on day
        scored.sort { $0.score > $1.score }

        // Take top candidates and select deterministically
        let topScore = scored[0].score
        let topCandidates = scored.filter { $0.score == topScore }
        let selectedIndex = dayOfYear % topCandidates.count
        return topCandidates[selectedIndex].song
    }
}

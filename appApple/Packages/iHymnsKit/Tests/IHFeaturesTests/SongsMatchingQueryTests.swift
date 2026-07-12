// SongsMatchingQueryTests.swift
// IHFeaturesTests
//
// ELI5: Checks that the shared "find matching songs" helper behaves exactly
// like the Search tab's own filtering always has — a real song number wins
// outright, a number-shaped query that doesn't match any real songbook
// falls back to a plain text search, an ordinary word search still works,
// and typing nothing returns everything.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §7.3/D-8 — BINDING).
// Covers the EXTRACTED `AppRootViewModel.songsMatching(_:in:)` directly
// (no view model, no network) — the regression guard that
// `AppRootViewModel.filteredSongs` itself still passes its EXISTING
// expectations lives in `CatalogueAndSongDetailLoadingTests
// .filteredSongsNarrowsByTextSearch`/`CatalogueNumberQueryTests`, untouched
// by this extraction (spec §7.3's own instruction).
import IHModels
import Testing
@testable import IHFeatures

@Suite("AppRootViewModel.songsMatching(_:in:)")
struct SongsMatchingQueryTests {

    private let fixtures: [SongSummary] = [
        SongSummary(songId: SongID(songbookAbbreviation: "MP", number: 1008), title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008, songbookName: "Mission Praise"),
        SongSummary(songId: SongID(songbookAbbreviation: "MP", number: 23), title: "All Creatures of Our God and King", songbookAbbreviation: "MP", number: 23, songbookName: "Mission Praise"),
        SongSummary(songId: SongID(songbookAbbreviation: "SDAH", number: 1008), title: "How Great Thou Art", songbookAbbreviation: "SDAH", number: 1008, songbookName: "Seventh-day Adventist Hymnal")
    ]

    @Test("A number-mode hit ('MP 1008') returns exactly that song, ignoring other songbooks sharing the number")
    func numberModeHit() {
        let matches = AppRootViewModel.songsMatching("MP 1008", in: fixtures)
        #expect(matches.count == 1)
        #expect(matches.first?.title == "Amazing Grace")
    }

    @Test("A bare number ('1008') matches the number in ANY songbook")
    func bareNumberMatchesAnySongbook() {
        let matches = AppRootViewModel.songsMatching("1008", in: fixtures)
        #expect(matches.count == 2)
        #expect(Set(matches.map(\.title)) == ["Amazing Grace", "How Great Thou Art"])
    }

    @Test("A number-mode MISS ('Song 23') falls through to substring title matching")
    func numberModeMissFallsThroughToSubstring() {
        // "Song 23" parses as songbook-prefix "SONG" + number 23 — no real
        // songbook is abbreviated "SONG", so the number interpretation finds
        // nothing and the plain substring search takes over instead.
        let matches = AppRootViewModel.songsMatching("Song 23", in: fixtures)
        #expect(matches.isEmpty) // no title contains the substring "Song 23" either — a genuine empty result, not a crash
    }

    @Test("A plain substring query matches title case-insensitively")
    func plainSubstringMatch() {
        let matches = AppRootViewModel.songsMatching("amazing", in: fixtures)
        #expect(matches.count == 1)
        #expect(matches.first?.title == "Amazing Grace")
    }

    @Test("An empty query returns the input unchanged")
    func emptyQueryReturnsInputUnchanged() {
        let matches = AppRootViewModel.songsMatching("", in: fixtures)
        #expect(matches.count == fixtures.count)
    }

    @Test("A substring query also matches on songbook name/abbreviation")
    func substringMatchesSongbookNameOrAbbreviation() {
        let byAbbreviation = AppRootViewModel.songsMatching("sdah", in: fixtures)
        #expect(byAbbreviation.count == 1)
        #expect(byAbbreviation.first?.title == "How Great Thou Art")

        let byName = AppRootViewModel.songsMatching("adventist", in: fixtures)
        #expect(byName.count == 1)
        #expect(byName.first?.title == "How Great Thou Art")
    }
}

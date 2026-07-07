// CatalogueNumberQueryTests.swift
// IHFeaturesTests
//
// ELI5: Checks that typing a song number (with or without a book prefix,
// like "1008" or "MP 1008") in the search box is understood as "find song
// number N", and that an ordinary title search is NOT mistaken for a number.
//
// DETAILED: Covers #1436's by-number search parsing (`CatalogueNumberQuery`).
// Two concerns: the parse (does the right raw text become a number query?)
// and the match (does a parsed query select the right SongSummary?).
import Testing
import IHModels
@testable import IHFeatures

@Suite("CatalogueNumberQuery")
struct CatalogueNumberQueryTests {

    @Test("A bare number parses with no songbook prefix")
    func bareNumber() throws {
        let query = try #require(CatalogueNumberQuery(rawQuery: "1008"))
        #expect(query.number == 1008)
        #expect(query.songbookAbbreviation == nil)
    }

    @Test("Prefixed forms all parse (space / hyphen / joined), prefix upper-cased")
    func prefixedForms() throws {
        // Space, hyphen, no-separator, and lower-case prefix all resolve to
        // the same intent — the prefix is always upper-cased for the
        // case-insensitive songbook compare.
        for raw in ["MP 1008", "MP-1008", "MP1008", "mp 1008"] {
            let query = try #require(CatalogueNumberQuery(rawQuery: raw), "\(raw) should parse")
            #expect(query.number == 1008)
            #expect(query.songbookAbbreviation == "MP")
        }
    }

    @Test("Non-numeric and trailing-word queries are NOT number queries")
    func rejectsNonNumbers() {
        // "Song 23 is great" must NOT parse — the pattern is anchored at both
        // ends, so trailing words after the digits disqualify it. A lone book
        // abbreviation with no digits ("MP") is likewise a text search.
        for raw in ["Amazing grace", "Song 23 is great", "23 skidoo", "", "   ", "MP"] {
            #expect(CatalogueNumberQuery(rawQuery: raw) == nil, "\(raw) should be a text search, not a number query")
        }
    }

    @Test("matches: a bare number matches that number in ANY songbook")
    func matchesBareNumber() throws {
        let query = try #require(CatalogueNumberQuery(rawQuery: "1008"))
        let mpSong = SongSummary(songId: try #require(SongID(rawValue: "MP-1008")),
                             title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)
        let cpSong = SongSummary(songId: try #require(SongID(rawValue: "CP-1008")),
                             title: "Another", songbookAbbreviation: "CP", number: 1008)
        #expect(query.matches(mpSong))
        #expect(query.matches(cpSong))   // no prefix → any book with number 1008
    }

    @Test("matches: a prefixed query is scoped to that songbook AND the exact number")
    func matchesPrefixed() throws {
        let query = try #require(CatalogueNumberQuery(rawQuery: "mp 1008"))
        let mpSong = SongSummary(songId: try #require(SongID(rawValue: "MP-1008")),
                             title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)
        let cpSameNumber = SongSummary(songId: try #require(SongID(rawValue: "CP-1008")),
                                       title: "Another", songbookAbbreviation: "CP", number: 1008)
        let mpWrongNumber = SongSummary(songId: try #require(SongID(rawValue: "MP-100")),
                                        title: "Different", songbookAbbreviation: "MP", number: 100)
        #expect(query.matches(mpSong))
        #expect(!query.matches(cpSameNumber))  // right number, wrong book
        #expect(!query.matches(mpWrongNumber)) // right book, wrong number
    }
}

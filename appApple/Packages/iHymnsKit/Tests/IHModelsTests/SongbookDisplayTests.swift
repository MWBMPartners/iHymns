// SongbookDisplayTests.swift
// IHModelsTests
//
// ELI5: Checks the "what should the book-code badge say, and is it even
// worth showing?" rules (#1332/#1437) behave exactly like the web's own
// `ihymns_songbook_abbr_label()`/`ihymns_songbook_show_abbr()` helpers.
import Foundation
import Testing
@testable import IHModels

@Suite("Songbook+Display (#1437)")
struct SongbookDisplayTests {

    /// Builds a minimal `Songbook` via the (internal, `@testable`-visible)
    /// synthesized memberwise initializer — every field this suite doesn't
    /// care about is filled with a harmless placeholder.
    private static func makeSongbook(id: String, name: String, displayAbbr: String? = nil) -> Songbook {
        Songbook(
            id: id,
            name: name,
            songCount: 0,
            colour: nil,
            isOfficial: false,
            publisher: nil,
            publicationYear: nil,
            copyright: nil,
            affiliation: nil,
            displayAbbr: displayAbbr,
            language: "en",
            websiteUrl: nil,
            internetArchiveUrl: nil,
            wikipediaUrl: nil,
            wikidataId: nil,
            oclcNumber: nil,
            ocnNumber: nil,
            lcpNumber: nil,
            isbn: nil,
            arkId: nil,
            isniId: nil,
            viafId: nil,
            lccn: nil,
            lcClass: nil,
            parent: nil,
            series: [],
            compilers: [],
            alternativeNames: [],
            links: [],
            languages: []
        )
    }

    @Test("abbreviationLabel falls back to id when displayAbbr is nil or blank")
    func abbreviationLabelFallsBackToId() {
        let noDisplayAbbr = Self.makeSongbook(id: "MP", name: "Mission Praise")
        #expect(noDisplayAbbr.abbreviationLabel == "MP")

        let blankDisplayAbbr = Self.makeSongbook(id: "MP", name: "Mission Praise", displayAbbr: "   ")
        #expect(blankDisplayAbbr.abbreviationLabel == "MP")
    }

    @Test("abbreviationLabel prefers a non-blank displayAbbr over id")
    func abbreviationLabelPrefersDisplayAbbr() {
        let songbook = Self.makeSongbook(id: "PSALTYOLD", name: "Psalty (old edition)", displayAbbr: "Psalty:Kids")
        #expect(songbook.abbreviationLabel == "Psalty:Kids")
    }

    @Test("showsAbbreviationLabel hides the badge when the label equals the title, case-insensitively")
    func hidesBadgeWhenLabelEqualsTitle() {
        // The real unofficial "Psalty" case #1328 exists to handle — the
        // abbreviation IS the title, so the badge would just repeat it.
        let songbook = Self.makeSongbook(id: "Psalty", name: "psalty")
        #expect(songbook.showsAbbreviationLabel == false)
    }

    @Test("showsAbbreviationLabel is true when the label genuinely differs from the title")
    func showsBadgeWhenLabelDiffersFromTitle() {
        let songbook = Self.makeSongbook(id: "MP", name: "Mission Praise")
        #expect(songbook.showsAbbreviationLabel == true)
    }
}

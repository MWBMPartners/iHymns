// SpotlightIndexEntryTests.swift
// IHAppSupportTests
//
// ELI5: Checks the pure "song → Spotlight-ready row" builder — the id/title/
// description/keywords come out right, an empty number never becomes a
// blank keyword, and the change-detection fingerprint behaves exactly like
// a fingerprint should (same input → same output, sort-stable, and changes
// the instant the underlying content does).
//
// #1415 (App Intents / Siri / Shortcuts / Spotlight).
import Foundation
import Testing
@testable import IHAppSupport
@testable import IHModels

@Suite("SpotlightIndexEntry (#1415)")
struct SpotlightIndexEntryTests {

    private func summary(
        id: String = "MP-1008",
        title: String = "Amazing Grace",
        songbookAbbreviation: String = "MP",
        number: Int? = 1008,
        songbookName: String = "Mission Praise"
    ) -> SongSummary {
        SongSummary(
            songId: SongID(rawValue: id) ?? SongID(songbookAbbreviation: songbookAbbreviation, number: number ?? 0),
            title: title,
            songbookAbbreviation: songbookAbbreviation,
            number: number,
            songbookName: songbookName
        )
    }

    @Test("Builds an entry from a full summary")
    func buildsEntryFromFullSummary() throws {
        let entry = try #require(SpotlightIndexEntry(summary: summary()))
        #expect(entry.uniqueIdentifier == "https://ihymns.app/song/MP-1008")
        #expect(entry.title == "Amazing Grace")
        #expect(entry.contentDescription == "Mission Praise")
        #expect(entry.keywords == ["Amazing Grace", "1008", "MP", "Mission Praise"])
    }

    @Test("Falls back to the songbook abbreviation when the songbook name is unset")
    func fallsBackToAbbreviationWhenNameIsEmpty() throws {
        let entry = try #require(SpotlightIndexEntry(summary: summary(songbookName: "")))
        #expect(entry.contentDescription == "MP")
    }

    @Test("Builds an entry from a number-less summary, dropping the empty keyword rather than indexing a blank")
    func buildsEntryFromNumberLessSummary() throws {
        let entry = try #require(SpotlightIndexEntry(summary: summary(id: "Misc-1", number: nil)))
        #expect(!entry.keywords.contains(""))
        #expect(entry.keywords == ["Amazing Grace", "MP", "Mission Praise"])
    }

    @Test("fingerprint is deterministic across repeated calls over the same content")
    func fingerprintIsDeterministic() {
        let summaries = [summary(), summary(id: "MP-2", title: "Second", number: 2)]
        #expect(SpotlightIndexEntry.fingerprint(of: summaries) == SpotlightIndexEntry.fingerprint(of: summaries))
    }

    @Test("fingerprint is stable regardless of input order — sorted by songId internally")
    func fingerprintIsSortStable() {
        let first = summary(id: "MP-1", title: "A", number: 1)
        let second = summary(id: "MP-2", title: "B", number: 2)
        #expect(SpotlightIndexEntry.fingerprint(of: [first, second]) == SpotlightIndexEntry.fingerprint(of: [second, first]))
    }

    @Test("fingerprint changes when a song's title changes")
    func fingerprintChangesOnTitleChange() {
        let original = [summary()]
        let changed = [summary(title: "Amazing Grace (Revised)")]
        #expect(SpotlightIndexEntry.fingerprint(of: original) != SpotlightIndexEntry.fingerprint(of: changed))
    }

    @Test("fingerprint differs between two genuinely different corpora")
    func fingerprintDiffersPerCorpus() {
        let corpusA = [summary()]
        let corpusB = [summary(), summary(id: "MP-2", title: "Second", number: 2)]
        #expect(SpotlightIndexEntry.fingerprint(of: corpusA) != SpotlightIndexEntry.fingerprint(of: corpusB))
    }
}

// SongOfTheDayTests.swift
// IHModelsTests
//
// ELI5: Checks the "featured hymn for today" shape (#183) decodes the real
// field names/types the API sends, and that the one genuinely tricky case —
// "no song at all" — decodes cleanly instead of throwing. See
// `ContractTests.swift` for the live-fixture-backed version of the happy
// path.
import Foundation
import Testing
@testable import IHModels

@Suite("SongOfTheDay (#183)")
struct SongOfTheDayTests {

    @Test("Decodes the real song_of_the_day JSON shape")
    func decodesRealShape() throws {
        // Field names/casing match a real `?action=song_of_the_day` pull
        // exactly: `id` (not `songId`), `songbook` (not
        // `songbookAbbreviation`) — see `Tests/Fixtures/song_of_the_day.json`
        // for the live-recorded original this mirrors.
        let json = Data("""
        {
            "song": {
                "id": "CP-0202",
                "number": 202,
                "title": "Amazing Grace",
                "songbook": "CP",
                "songbookName": "Christian Praise",
                "verified": true
            },
            "themeLabel": "Song of the Day",
            "firstLine": "Amazing grace! how sweet the sound"
        }
        """.utf8)

        let sotd = try JSONDecoder().decode(SongOfTheDay.self, from: json)
        let song = try #require(sotd.song)
        #expect(song.songId.rawValue == "CP-0202")
        #expect(song.songId.number == 202)
        #expect(song.title == "Amazing Grace")
        #expect(song.songbookAbbreviation == "CP")
        #expect(song.songbookName == "Christian Praise")
        #expect(song.verified == true)
        #expect(sotd.themeLabel == "Song of the Day")
        #expect(sotd.firstLine == "Amazing grace! how sweet the sound")
    }

    @Test("A themed date's heading decodes distinctly from the neutral default")
    func decodesThemedHeading() throws {
        // Real, live-verified behaviour (this task's own dev-API survey,
        // `&date=2026-12-25` against `ihymns.app`) — `themeLabel` changes
        // per calendar date; nothing else about the envelope shape does.
        let json = Data("""
        {
            "song": { "id": "CH-0100", "number": 100, "title": "To Us a Child of Hope Is Born", "songbook": "CH", "songbookName": "The Church Hymnal", "verified": false },
            "themeLabel": "Christmas Song of the Day",
            "firstLine": "To us a Child of hope is born;"
        }
        """.utf8)

        let sotd = try JSONDecoder().decode(SongOfTheDay.self, from: json)
        #expect(sotd.themeLabel == "Christmas Song of the Day")
    }

    @Test("A null song (empty catalogue) decodes to nil, not a decode failure")
    func decodesNullSong() throws {
        // `api-docs.yaml`: "song is null only if the catalogue is empty" —
        // a real, if rare, deployment state this type must tolerate.
        let json = Data("""
        { "song": null, "themeLabel": "", "firstLine": "" }
        """.utf8)

        let sotd = try JSONDecoder().decode(SongOfTheDay.self, from: json)
        #expect(sotd.song == nil)
        #expect(sotd.themeLabel.isEmpty)
        #expect(sotd.firstLine.isEmpty)
    }

    @Test("A null number decodes to nil, not a decode failure")
    func decodesNullNumber() throws {
        // Same manually-catalogued-row nullability as `SongSummary.number`/
        // `SongDetail.number` — the day's pick could in principle land on
        // one of those rows.
        let json = Data("""
        {
            "song": { "id": "Misc-0001", "number": null, "title": "Here To Stay", "songbook": "Misc", "songbookName": "Miscellaneous", "verified": false },
            "themeLabel": "Song of the Day",
            "firstLine": ""
        }
        """.utf8)

        let sotd = try JSONDecoder().decode(SongOfTheDay.self, from: json)
        #expect(sotd.song?.number == nil)
    }
}

// SongSummaryTests.swift
// IHModelsTests
//
// ELI5: Makes sure a `SongSummary`'s `id` really is its `SongID` (so SwiftUI
// lists key off the right thing), and that it survives a JSON round-trip
// in the REAL shape the `songs_index` API sends (not a guessed shape —
// see `ContractTests.swift` for the live-fixture-backed version of this).
import Foundation
import Testing
@testable import IHModels

@Suite("SongSummary")
struct SongSummaryTests {

    @Test("Identifiable id mirrors the parsed SongID")
    func identifiableMirrorsSongID() throws {
        let songId = try #require(SongID(rawValue: "MP-1008"))
        let summary = SongSummary(
            songId: songId,
            title: "Amazing Grace",
            songbookAbbreviation: "MP",
            number: 1008
        )
        #expect(summary.id == songId)
        // displayNumber is derived from `number`, not stored separately.
        #expect(summary.displayNumber == "1008")
    }

    @Test("Decodes the real songs_index JSON row shape (#1396)")
    func decodesIndexRowShape() throws {
        // Field names/casing match a real `?action=songs_index` row
        // exactly: `id` (not `songId`), `songbook` (not
        // `songbookAbbreviation`), `number` as a JSON integer (not a
        // display string) — see `Tests/Fixtures/songs_index.json` for the
        // live-recorded original this mirrors.
        let json = Data("""
        {
            "id": "MP-1008",
            "number": 1008,
            "title": "Amazing Grace",
            "songbook": "MP",
            "songbookName": "Mission Praise",
            "language": "en",
            "hasAudio": true,
            "hasSheetMusic": true,
            "publicId": "ABC123XYZ0"
        }
        """.utf8)

        let decoded = try JSONDecoder().decode(SongSummary.self, from: json)
        #expect(decoded.title == "Amazing Grace")
        #expect(decoded.songId.number == 1008)
        #expect(decoded.songbookName == "Mission Praise")
        #expect(decoded.language == "en")
        #expect(decoded.hasAudio == true)
        #expect(decoded.hasSheetMusic == true)
        #expect(decoded.publicId == "ABC123XYZ0")
    }

    @Test("A null number decodes to nil, not a decode failure")
    func decodesNullNumber() throws {
        // A handful of real catalogue rows (the live "Misc" songbook) carry
        // `"number": null` — must decode cleanly, not throw.
        let json = Data("""
        {
            "id": "Misc-0001",
            "number": null,
            "title": "Here To Stay",
            "songbook": "Misc",
            "songbookName": "Miscellaneous",
            "language": "en",
            "hasAudio": false,
            "hasSheetMusic": false
        }
        """.utf8)

        let decoded = try JSONDecoder().decode(SongSummary.self, from: json)
        #expect(decoded.number == nil)
        #expect(decoded.displayNumber == "")
        // publicId was omitted entirely above — must default to nil, not throw.
        #expect(decoded.publicId == nil)
    }
}

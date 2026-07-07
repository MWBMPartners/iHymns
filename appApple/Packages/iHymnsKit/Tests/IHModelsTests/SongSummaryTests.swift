// SongSummaryTests.swift
// IHModelsTests
//
// ELI5: Makes sure a `SongSummary`'s `id` really is its `SongID` (so SwiftUI
// lists key off the right thing), and that it survives a JSON round-trip
// the same shape the `songs_index` API sends.
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
            displayNumber: "1008"
        )
        #expect(summary.id == songId)
    }

    @Test("Decodes the songs_index JSON row shape")
    func decodesIndexRowShape() throws {
        let json = Data("""
        {
            "songId": "MP-1008",
            "title": "Amazing Grace",
            "songbookAbbreviation": "MP",
            "displayNumber": "1008"
        }
        """.utf8)

        let decoded = try JSONDecoder().decode(SongSummary.self, from: json)
        #expect(decoded.title == "Amazing Grace")
        #expect(decoded.songId.number == 1008)
    }
}

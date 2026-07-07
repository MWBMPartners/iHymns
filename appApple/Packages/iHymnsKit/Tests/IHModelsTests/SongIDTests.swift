// SongIDTests.swift
// IHModelsTests
//
// ELI5: Checks that `SongID` really does understand `MP-1008`-shaped codes,
// and really does refuse garbage.
//
// DETAILED: Uses Swift Testing (`import Testing`, WWDC24/Xcode 16+), the
// modern replacement for XCTest that reads as plain assertions
// (`#expect`) rather than `XCTAssert*` boilerplate — see
// https://developer.apple.com/documentation/testing. Kept intentionally
// small for the Phase-0 skeleton (strategy §3.4 "Phase 0 — Skeleton:
// everything builds, one real screen"); Phase 1 grows this into full
// contract-fixture coverage per strategy §3.3.
import Foundation
import Testing
@testable import IHModels

@Suite("SongID parsing")
struct SongIDTests {

    @Test("Parses a well-formed <letters>-<digits> id")
    func parsesValidID() {
        let id = SongID(rawValue: "MP-1008")
        #expect(id?.songbookAbbreviation == "MP")
        #expect(id?.number == 1008)
        #expect(id?.rawValue == "MP-1008")
    }

    @Test("Normalizes the songbook prefix to uppercase")
    func normalizesCase() {
        // Web treats the prefix case-insensitively; SongID should agree so
        // two differently-cased URLs resolve to the SAME song identity.
        let id = SongID(rawValue: "mp-1008")
        #expect(id?.songbookAbbreviation == "MP")
    }

    @Test("Rejects strings that don't match <letters>-<digits>", arguments: [
        // Note: "xMP-1008" is deliberately NOT in this list — it IS a
        // structurally valid <letters>-<digits> id (letters="xMP",
        // digits="1008"); whether "xMP" is a REAL songbook abbreviation is
        // a semantic question for the API/catalogue, not something SongID
        // parsing itself is responsible for rejecting.
        "", "MP", "1008", "MP1008", "MP-", "-1008", "MP-1008-extra", "MP--1008"
    ])
    func rejectsMalformedInput(candidate: String) {
        #expect(SongID(rawValue: candidate) == nil)
    }

    @Test("Round-trips through Codable as a plain JSON string")
    func codableRoundTrip() throws {
        let id = try #require(SongID(rawValue: "SDAH-42"))
        let encoded = try JSONEncoder().encode(id)
        #expect(String(data: encoded, encoding: .utf8) == "\"SDAH-42\"")

        let decoded = try JSONDecoder().decode(SongID.self, from: encoded)
        #expect(decoded == id)
    }

    @Test("Decoding an invalid JSON string throws instead of crashing")
    func codableRejectsInvalidJSON() {
        let badJSON = Data("\"not-a-song-id\"".utf8)
        #expect(throws: (any Error).self) {
            _ = try JSONDecoder().decode(SongID.self, from: badJSON)
        }
    }
}

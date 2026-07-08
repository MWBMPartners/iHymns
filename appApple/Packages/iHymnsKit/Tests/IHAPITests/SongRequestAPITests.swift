// SongRequestAPITests.swift
// IHAPITests
//
// ELI5: Checks "submit a missing-song request" (#1447) builds the right
// POST body and decodes the confirmation response.
//
// DETAILED: `song_request` is an ALREADY-SHIPPED, working endpoint (#280)
// this task's backend survey discovered — its request/response shapes are
// verified against the LIVE `api.php` `case 'song_request':` handler
// (2026-07-08), not a guess.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHModels

@Suite("SongRequest Endpoint request building")
struct SongRequestEndpointTests {

    @Test("Endpoint.songRequest POSTs a {title, songbook, song_number, language, details, contact_email} body")
    func buildsSongRequestEndpoint() throws {
        let endpoint = try Endpoint.songRequest(
            title: "Blessed Assurance",
            songbook: "MP",
            songNumber: "42",
            language: "en",
            details: "First line: Blessed assurance, Jesus is mine",
            contactEmail: "user@example.com"
        )
        #expect(endpoint.action == "song_request")
        #expect(endpoint.requiresAuth == false)
        #expect(endpoint.httpMethod == "POST")

        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["title"] as? String == "Blessed Assurance")
        #expect(json["songbook"] as? String == "MP")
        #expect(json["song_number"] as? String == "42")
        #expect(json["language"] as? String == "en")
        #expect(json["details"] as? String == "First line: Blessed assurance, Jesus is mine")
        #expect(json["contact_email"] as? String == "user@example.com")
    }

    @Test("Endpoint.songRequest defaults every optional field to an empty string, never an omitted key")
    func buildsSongRequestEndpointWithDefaults() throws {
        let endpoint = try Endpoint.songRequest(title: "Unknown Hymn")
        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["title"] as? String == "Unknown Hymn")
        #expect(json["songbook"] as? String == "")
        #expect(json["language"] as? String == "en")
        #expect(json["contact_email"] as? String == "")
    }
}

@Suite("SongRequest decoding")
struct SongRequestDecodingTests {

    @Test("Decodes a song_request success response")
    func decodesSongRequestResult() throws {
        let json = Data("""
        { "ok": true, "id": 123 }
        """.utf8)

        let result = try APIClient.decodeSongRequestResult(from: json)
        #expect(result.wasAccepted == true)
        #expect(result.id == 123)
    }
}

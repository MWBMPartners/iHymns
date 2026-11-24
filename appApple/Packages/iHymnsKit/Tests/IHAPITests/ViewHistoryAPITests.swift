// ViewHistoryAPITests.swift
// IHAPITests
//
// ELI5: Checks the three viewing-history calls build the right requests and
// decode the right answers, including skipping a row it cannot read.
//
// DETAILED: PR 3b step I3 (#2180). Shapes modelled from the live `api.php`
// cases `song_view`, `song_history` and `my_most_viewed` (read 2026-11-24).
// The request body is checked at the request-building level (a body captured
// through `MockURLProtocol` is not reliable); the URL, method and headers are
// checked end to end.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHModels

@Suite("View history endpoints and decoding")
struct ViewHistoryEndpointTests {

    @Test("song_view is an optional-sign-in POST carrying song_id and platform apple, and no device_id when none is given")
    func buildsSongView() throws {
        let songId = try #require(SongID(rawValue: "MP-1008"))
        let endpoint = try Endpoint.songView(songId: songId)
        #expect(endpoint.action == "song_view")
        #expect(endpoint.httpMethod == "POST")
        #expect(endpoint.requiresAuth == false)
        #expect(endpoint.optionalAuth == true)
        let jsonBody = try #require(endpoint.httpBody)
        let jsonObject = try JSONSerialization.jsonObject(with: jsonBody)
        let json = try #require(jsonObject as? [String: Any])
        #expect(json["song_id"] as? String == "MP-1008")
        #expect(json["platform"] as? String == "apple")
        #expect(json["device_id"] == nil)
    }

    @Test("song_view carries the install id as device_id when one is given (anonymous)")
    func buildsAnonymousSongView() throws {
        let songId = try #require(SongID(rawValue: "MP-1008"))
        let endpoint = try Endpoint.songView(songId: songId, deviceId: "install-1234-abcd")
        let jsonBody = try #require(endpoint.httpBody)
        let jsonObject = try JSONSerialization.jsonObject(with: jsonBody)
        let json = try #require(jsonObject as? [String: Any])
        #expect(json["device_id"] as? String == "install-1234-abcd")
        #expect(json["song_id"] as? String == "MP-1008")
    }

    @Test("optionalAuth sends the bearer when there is one and builds without one; requiresAuth stays strict")
    func optionalAuthRequestShape() throws {
        let songId = try #require(SongID(rawValue: "MP-1008"))
        let endpoint = try Endpoint.songView(songId: songId)
        let withToken = APIClient.makeURLRequest(for: endpoint, in: .dev, bearerToken: "t")
        #expect(withToken.value(forHTTPHeaderField: "Authorization") == "Bearer t")
        let anonymous = APIClient.makeURLRequest(for: endpoint, in: .dev)
        #expect(anonymous.value(forHTTPHeaderField: "Authorization") == nil)
        #expect(anonymous.httpMethod == "POST")
        let plain = Endpoint(action: "songs_index")
        #expect(plain.optionalAuth == false)
        #expect(APIClient.makeURLRequest(for: plain, in: .dev, bearerToken: "t").value(forHTTPHeaderField: "Authorization") == nil)
    }

    @Test("song_history and my_most_viewed are signed-in GETs; the period is sent only when asked for")
    func buildsReads() {
        #expect(Endpoint.songHistory.action == "song_history")
        #expect(Endpoint.songHistory.requiresAuth == true)
        let plain = Endpoint.myMostViewed()
        #expect(plain.queryItems.isEmpty)
        #expect(plain.capturesRefusalReason == true)
        let withPeriod = Endpoint.myMostViewed(period: .ninetyDays)
        #expect(withPeriod.queryItems.contains { $0.name == "period" && $0.value == "90d" })
    }

    @Test("The period words are the server's four")
    func periodWords() {
        #expect(Set(MostViewedPeriod.allCases.map(\.rawValue)) == ["30d", "90d", "12m", "all"])
    }

    @Test("decodeSongHistory reads the real rows and SKIPS one whose id is not a letters-digits song id")
    func decodesHistoryLossily() throws {
        let json = Data(#"""
        { "history": [
            { "songId": "MP-1008", "title": "Amazing Grace", "number": 1008, "songbook": "MP", "songbookName": "Mission Praise", "language": "en", "viewedAt": "2026-11-24 09:00:00", "viewedAtEpoch": 1795600000 },
            { "songId": "song-1700000000-ab12", "title": "A synthetic one", "number": null, "songbook": "PSALTY", "songbookName": null, "language": "en", "viewedAt": "2026-11-24 08:00:00" },
            { "songId": "CP-0202", "title": "How Great Thou Art", "number": 202, "songbook": "CP", "songbookName": null, "language": null, "viewedAt": null }
        ] }
        """#.utf8)
        let rows = try APIClient.decodeSongHistory(from: json)
        #expect(rows.map(\.songId.rawValue) == ["MP-1008", "CP-0202"])
        #expect(rows[0].songbookAbbreviation == "MP")
        #expect(rows[0].viewedAt == "2026-11-24 09:00:00")
        #expect(rows[1].language == nil)
    }

    @Test("decodeMostViewed reads views and the period the server used")
    func decodesMostViewed() throws {
        let json = Data(#"""
        { "songs": [ { "songId": "MP-1008", "title": "Amazing Grace", "number": 1008, "songbook": "MP", "songbookName": "Mission Praise", "language": "en", "views": 14 } ], "period": "90d" }
        """#.utf8)
        let result = try APIClient.decodeMostViewed(from: json)
        #expect(result.period == "90d")
        #expect(result.songs.first?.views == 14)
    }

    @Test("A malformed history envelope throws .decoding")
    func malformedThrows() {
        #expect(throws: APIError.decoding) { _ = try APIClient.decodeSongHistory(from: Data(#"{"nothing":[]}"#.utf8)) }
        #expect(throws: APIError.decoding) { _ = try APIClient.decodeMostViewed(from: Data("not json".utf8)) }
    }
}

@Suite("View history networked calls (mocked transport)", .serialized)
struct ViewHistoryNetworkedTests {

    private static func response(for request: URLRequest, status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    @Test("songView works with no sign-in: no Authorization header, still a POST, and a 429 is thrown for the caller to ignore")
    func songViewAnonymous() async throws {
        try await MockTransportLock.shared.withLock {
            let seen = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seen.set(request)
                return (Self.response(for: request, status: 200), Data(#"{"ok":true,"data":{"ok":true}}"#.utf8))
            }
            defer { MockURLProtocol.requestHandler = nil }
            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let songId = try #require(SongID(rawValue: "MP-1008"))

            try await client.songView(songId: songId, deviceId: "install-1234-abcd")
            #expect(seen.current?.url?.query?.contains("action=song_view") == true)
            #expect(seen.current?.httpMethod == "POST")
            #expect(seen.current?.value(forHTTPHeaderField: "Authorization") == nil)

            MockURLProtocol.requestHandler = { request in (Self.response(for: request, status: 429), Data()) }
            await #expect(throws: APIError.self) { try await client.songView(songId: songId, deviceId: "install-1234-abcd") }
        }
    }

    @Test("songView POSTs signed in and a 429 surfaces as .rateLimited for the caller to ignore")
    func songViewEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let seen = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seen.set(request)
                return (Self.response(for: request, status: 200), Data(#"{"ok":true,"data":{"ok":true}}"#.utf8))
            }
            defer { MockURLProtocol.requestHandler = nil }
            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "t")
            let songId = try #require(SongID(rawValue: "MP-1008"))

            try await client.songView(songId: songId)
            #expect(seen.current?.url?.query?.contains("action=song_view") == true)
            #expect(seen.current?.httpMethod == "POST")
            #expect(seen.current?.value(forHTTPHeaderField: "Authorization") == "Bearer t")

            MockURLProtocol.requestHandler = { request in (Self.response(for: request, status: 429), Data()) }
            await #expect(throws: APIError.self) { try await client.songView(songId: songId) }
        }
    }

    @Test("myMostViewed surfaces 409 profile_sync_disabled as a server error with the reason word")
    func mostViewedDisabled() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { request in
                (Self.response(for: request, status: 409),
                 Data(#"{"ok":false,"error":{"code":"http_409","message":"x","reason":"profile_sync_disabled"}}"#.utf8))
            }
            defer { MockURLProtocol.requestHandler = nil }
            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "t")
            await #expect(throws: APIError.server(status: 409, message: "profile_sync_disabled")) {
                _ = try await client.myMostViewed()
            }
        }
    }
}

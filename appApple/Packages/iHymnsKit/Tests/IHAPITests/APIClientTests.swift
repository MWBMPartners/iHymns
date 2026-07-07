// APIClientTests.swift
// IHAPITests
//
// ELI5: Checks that when we ask for "the songs index," the app writes the
// correct web address and the correct headers on the request — and that a
// sample response really turns into song objects.
import Foundation
import Testing
@testable import IHAPI
@testable import IHModels

@Suite("APIClient request building")
struct APIClientTests {

    @Test("Builds the expected URL for an unauthenticated endpoint")
    func buildsPublicEndpointURL() {
        let endpoint = Endpoint(action: "songs_index")
        let request = APIClient.makeURLRequest(for: endpoint, in: .dev)

        #expect(request.url?.host == "dev.ihymns.app")
        #expect(request.url?.path == "/api")
        #expect(request.url?.query?.contains("action=songs_index") == true)
        #expect(request.value(forHTTPHeaderField: "X-Requested-With") == "XMLHttpRequest")
        #expect(request.value(forHTTPHeaderField: "Authorization") == nil)
    }

    @Test("Adds the Bearer header for an authenticated endpoint")
    func addsBearerHeaderWhenRequired() {
        let endpoint = Endpoint(action: "favorites_sync", requiresAuth: true)
        let request = APIClient.makeURLRequest(for: endpoint, in: .prod, bearerToken: "deadbeef")

        #expect(request.value(forHTTPHeaderField: "Authorization") == "Bearer deadbeef")
        #expect(request.url?.host == "ihymns.app")
    }

    @Test("Appends extra query items alongside action")
    func appendsExtraQueryItems() {
        let endpoint = Endpoint(action: "songs", queryItems: [("abbr", "MP")])
        let request = APIClient.makeURLRequest(for: endpoint, in: .beta)

        #expect(request.url?.query?.contains("abbr=MP") == true)
        #expect(request.url?.host == "beta.ihymns.app")
    }

    @Test("Classifies a 503 as .maintenance with the Retry-After hint")
    func classifiesMaintenance() {
        let error = APIClient.classify(httpStatus: 503, retryAfterSeconds: 30)
        #expect(error == .maintenance(retryAfterSeconds: 30))
    }

    @Test("Classifies 200 as no error")
    func classifiesSuccess() {
        #expect(APIClient.classify(httpStatus: 200, retryAfterSeconds: nil) == nil)
    }

    @Test("Decodes a songs_index JSON array into SongSummary values")
    func decodesSongsIndex() throws {
        let json = Data("""
        [
            { "songId": "MP-1", "title": "Song One", "songbookAbbreviation": "MP", "displayNumber": "1" },
            { "songId": "MP-2", "title": "Song Two", "songbookAbbreviation": "MP", "displayNumber": "2" }
        ]
        """.utf8)

        let songs = try APIClient.decodeSongsIndex(from: json)
        #expect(songs.count == 2)
        #expect(songs[0].title == "Song One")
    }
}

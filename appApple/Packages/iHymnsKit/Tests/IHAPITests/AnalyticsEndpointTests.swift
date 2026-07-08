// AnalyticsEndpointTests.swift
// IHAPITests
//
// ELI5: Checks "send a batch of anonymous usage notes" (#1448) builds the
// right POST body — no networking, just the pure request-shaping logic.
//
// DETAILED: Mirrors `SongRequestAPITests.swift`'s exact shape (build an
// `Endpoint`, inspect its `action`/`httpMethod`/decoded JSON body) for the
// same reason: `Endpoint.analyticsIngest(events:platform:)` is a pure,
// side-effect-free factory (see that file's own header), independently
// testable with zero `URLSession`/actor involvement.
import Foundation
import Testing
@testable import IHAPI

@Suite("Analytics Endpoint request building")
struct AnalyticsEndpointTests {

    @Test("Endpoint.analyticsIngest POSTs a {platform, events:[{name, parameters, clientTimestamp}]} body")
    func buildsAnalyticsIngestEndpoint() throws {
        let timestamp = Date(timeIntervalSince1970: 1_800_000_000)
        let events = [
            AnalyticsIngestEventPayload(name: "song_opened", parameters: ["song_id": "MP-1008"], clientTimestamp: timestamp),
            AnalyticsIngestEventPayload(name: "screen_view", parameters: ["screen": "Home"], clientTimestamp: timestamp)
        ]

        let endpoint = try Endpoint.analyticsIngest(events: events, platform: "apple")

        #expect(endpoint.action == "analytics_ingest")
        #expect(endpoint.requiresAuth == false)
        #expect(endpoint.httpMethod == "POST")

        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["platform"] as? String == "apple")

        let wireEvents = try #require(json["events"] as? [[String: Any]])
        #expect(wireEvents.count == 2)
        #expect(wireEvents[0]["name"] as? String == "song_opened")
        #expect((wireEvents[0]["parameters"] as? [String: String])?["song_id"] == "MP-1008")
        #expect(wireEvents[1]["name"] as? String == "screen_view")

        // `clientTimestamp` is ISO-8601, not a raw epoch double — the
        // backend's `analyticsIngestValidateTimestamp()` (#1448) parses
        // via PHP's `DateTimeImmutable`, which expects a standard date
        // string, not a bare number.
        let iso = try #require(wireEvents[0]["clientTimestamp"] as? String)
        #expect(ISO8601DateFormatter().date(from: iso) != nil)
    }

    @Test("Endpoint.analyticsIngest with a single event still produces a one-element events array")
    func buildsSingleEventBatch() throws {
        let endpoint = try Endpoint.analyticsIngest(
            events: [AnalyticsIngestEventPayload(name: "offline_save", parameters: [:], clientTimestamp: Date())],
            platform: "apple"
        )
        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        let wireEvents = try #require(json["events"] as? [[String: Any]])
        #expect(wireEvents.count == 1)
        #expect((wireEvents[0]["parameters"] as? [String: String]) == [:])
    }
}

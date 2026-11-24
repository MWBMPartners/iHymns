// SetlistsAPITests.swift
// IHAPITests
//
// ELI5: Checks the four setlist calls (`user_setlists`/`user_setlists_sync`/
// `setlist_share`/`setlist_get`) build the right requests and decode the
// right responses — both in isolation (pure request building / pure
// decoding) and end-to-end through the mocked network, mirroring
// `FavoritesAndAuthAPITests.swift`'s exact conventions.
//
// DETAILED: #181's setlists half. Every response shape asserted here is
// modelled directly from the LIVE `appWeb/public_html/api.php` handlers
// (`case 'user_setlists'`/`'user_setlists_sync'`/`'setlist_share'`/
// `'setlist_get'`, read 2026-07-07) — no live test account was available to
// curl an AUTHENTICATED response directly (same caveat
// `FavoritesAndAuthAPITests.swift`'s header already flags for favourites),
// so these fixtures are modelled from the PHP source's `sendJson([...])`
// calls, not a real HTTP capture.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHModels

@Suite("Setlists Endpoint request building")
struct SetlistsEndpointTests {

    @Test("Endpoint.userSetlists is a GET that requires auth")
    func buildsUserSetlistsEndpoint() {
        let endpoint = Endpoint.userSetlists
        #expect(endpoint.action == "user_setlists")
        #expect(endpoint.requiresAuth == true)
        #expect(endpoint.httpMethod == "GET")
    }

    @Test("Endpoint.setlistsSync(mode:setlists:) POSTs a {mode, setlists: [...]} body")
    func buildsSetlistsSyncEndpoint() throws {
        let songId = try #require(SongID(rawValue: "MP-1008"))
        let setlist = Setlist(
            id: "slist-abc123",
            name: "Evening Worship",
            songs: [SetlistSongEntry(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)]
        )
        let endpoint = try Endpoint.setlistsSync(mode: .replace, setlists: [setlist])

        #expect(endpoint.action == "user_setlists_sync")
        #expect(endpoint.requiresAuth == true)
        #expect(endpoint.httpMethod == "POST")

        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["mode"] as? String == "replace")
        let setlists = try #require(json["setlists"] as? [[String: Any]])
        #expect(setlists.count == 1)
        #expect(setlists[0]["id"] as? String == "slist-abc123")
        let songs = try #require(setlists[0]["songs"] as? [[String: Any]])
        #expect(songs[0]["id"] as? String == "MP-1008")
    }

    @Test("Endpoint.setlistsSync sends since and deleted when given, and always sends deleted (even empty) so the server sees protocol 2")
    func buildsProtocol2SetlistsSyncEndpoint() throws {
        let withBoth = try Endpoint.setlistsSync(
            mode: .replace, setlists: [], since: "2026-11-24 09:30:00", deleted: ["slist-gone1", "slist-gone2"]
        )
        let bothJsonBody = try #require(withBoth.httpBody)
        let bothJsonObject = try JSONSerialization.jsonObject(with: bothJsonBody)
        let bothJson = try #require(bothJsonObject as? [String: Any])
        #expect(bothJson["since"] as? String == "2026-11-24 09:30:00")
        #expect(bothJson["deleted"] as? [String] == ["slist-gone1", "slist-gone2"])

        // An EMPTY deleted list must still be present: the key's mere presence marks protocol 2.
        let emptyDeleted = try Endpoint.setlistsSync(mode: .replace, setlists: [], since: nil, deleted: [])
        let emptyJsonBody = try #require(emptyDeleted.httpBody)
        let emptyJsonObject = try JSONSerialization.jsonObject(with: emptyJsonBody)
        let emptyJson = try #require(emptyJsonObject as? [String: Any])
        #expect((emptyJson["deleted"] as? [Any])?.isEmpty == true)
        // No watermark yet: the key is left OUT, never sent as null.
        #expect(emptyJson.keys.contains("since") == false)

        // The old request (neither given) leaves both keys out.
        let legacy = try Endpoint.setlistsSync(mode: .merge, setlists: [])
        let legacyJsonBody = try #require(legacy.httpBody)
        let legacyJsonObject = try JSONSerialization.jsonObject(with: legacyJsonBody)
        let legacyJson = try #require(legacyJsonObject as? [String: Any])
        #expect(legacyJson.keys.contains("deleted") == false)
        #expect(legacyJson.keys.contains("since") == false)
    }

    @Test("decodeSetlistSyncResult reads syncedAt, tombstones and conflicts")
    func decodesSetlistSyncResultExtras() throws {
        let json = Data(#"""
        { "setlists": [ { "id": "slist-a", "name": "Kept", "songs": [] } ],
          "syncedAt": "2026-11-24 09:31:02", "truncated": false, "cap": null,
          "tombstones": [ { "id": "slist-gone1", "deletedAt": "2026-11-23 18:00:00", "reason": "user" } ],
          "conflicts": [ { "id": "slist-a", "reason": "newer_on_server", "serverUpdatedAt": "2026-11-24 09:30:59" } ] }
        """#.utf8)
        let result = try APIClient.decodeSetlistSyncResult(from: json)
        #expect(result.setlists.map(\.id) == ["slist-a"])
        #expect(result.syncedAt == "2026-11-24 09:31:02")
        #expect(result.tombstones.map(\.id) == ["slist-gone1"])
        #expect(result.conflicts.first?.reason == "newer_on_server")
    }

    @Test("decodeSetlistSyncResult tolerates a paused site's read-only answer (syncedAt null)")
    func decodesReadOnlySetlistSyncResult() throws {
        let json = Data(#"{ "setlists": [], "syncedAt": null, "tombstones": [], "conflicts": [], "readOnly": true }"#.utf8)
        let result = try APIClient.decodeSetlistSyncResult(from: json)
        #expect(result.syncedAt == nil)
        #expect(result.setlists.isEmpty)
    }

    @Test("Endpoint.shareSetlist(_:ownerId:existingShareId:) POSTs name/songs/owner/setlistId, and omits id when creating fresh")
    func buildsShareSetlistEndpointForCreate() throws {
        let songId = try #require(SongID(rawValue: "CP-0202"))
        let setlist = Setlist(
            id: "slist-abc123",
            name: "Sunday Morning Service",
            songs: [SetlistSongEntry(songId: songId, title: "How Great Thou Art", songbookAbbreviation: "CP", number: 202, arrangement: [0, 1, 2, 1, 3])]
        )
        let endpoint = try Endpoint.shareSetlist(setlist, ownerId: "a1b2c3d4-e5f6-7890-abcd-ef1234567890")

        #expect(endpoint.action == "setlist_share")
        #expect(endpoint.requiresAuth == true)
        #expect(endpoint.httpMethod == "POST")

        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["name"] as? String == "Sunday Morning Service")
        #expect(json["songs"] as? [String] == ["CP-0202"])
        #expect(json["owner"] as? String == "a1b2c3d4-e5f6-7890-abcd-ef1234567890")
        #expect(json["setlistId"] as? String == "slist-abc123")
        // No existing share to update yet — `id` must be OMITTED, never a
        // literal `null` (the server treats a present-but-null `id` key
        // differently from an absent one in PHP's `!empty($body['id'])`).
        #expect(json["id"] == nil)
        let arrangements = try #require(json["arrangements"] as? [String: [Int]])
        #expect(arrangements["CP-0202"] == [0, 1, 2, 1, 3])
    }

    @Test("Endpoint.shareSetlist(_:ownerId:existingShareId:) includes id when updating, and omits arrangements when none exist")
    func buildsShareSetlistEndpointForUpdate() throws {
        let songId = try #require(SongID(rawValue: "MP-1008"))
        let setlist = Setlist(
            id: "slist-abc123",
            name: "Evening Worship",
            songs: [SetlistSongEntry(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)]
        )
        let endpoint = try Endpoint.shareSetlist(setlist, ownerId: "some-uuid", existingShareId: "1a2b3c4d")

        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["id"] as? String == "1a2b3c4d")
        #expect(json["arrangements"] == nil)
    }

    @Test("Endpoint.sharedSetlist(shareId:) is an unauthenticated GET with the id as a query item")
    func buildsSharedSetlistEndpoint() {
        let endpoint = Endpoint.sharedSetlist(shareId: "1a2b3c4d")
        #expect(endpoint.action == "setlist_get")
        #expect(endpoint.requiresAuth == false)
        #expect(endpoint.queryItems.contains { $0.name == "id" && $0.value == "1a2b3c4d" })
    }
}

@Suite("Setlists decoding")
struct SetlistsDecodingTests {

    @Test("decodeSetlists decodes the real {setlists: [...]} envelope")
    func decodesSetlists() throws {
        let json = Data("""
        { "setlists": [
            { "id": "slist-abc123", "name": "Evening Worship", "songs": [], "createdAt": "2026-07-01 09:00:00", "updatedAt": "2026-07-07 12:34:56" }
        ] }
        """.utf8)

        let setlists = try APIClient.decodeSetlists(from: json)
        #expect(setlists.count == 1)
        #expect(setlists[0].id == "slist-abc123")
        #expect(setlists[0].name == "Evening Worship")
    }

    @Test("decodeSetlists decodes an empty {setlists: []} envelope (a brand-new account)")
    func decodesEmptySetlists() throws {
        let json = Data(#"{ "setlists": [] }"#.utf8)
        #expect(try APIClient.decodeSetlists(from: json).isEmpty)
    }

    @Test("decodeShareSetlistResult decodes {id, url}")
    func decodesShareSetlistResult() throws {
        let json = Data(#"{ "id": "1a2b3c4d", "url": "/setlist/shared/1a2b3c4d" }"#.utf8)
        let result = try APIClient.decodeShareSetlistResult(from: json)
        #expect(result.id == "1a2b3c4d")
        #expect(result.canonicalURL?.absoluteString == "https://ihymns.app/setlist/shared/1a2b3c4d")
    }

    @Test("decodeSharedSetlist decodes the setlist_get response, including live")
    func decodesSharedSetlist() throws {
        let json = Data("""
        { "id": "1a2b3c4d", "name": "Sunday Morning Service", "songs": ["CP-0202", "MP-1008"], "live": true, "created": null, "updated": null }
        """.utf8)
        let shared = try APIClient.decodeSharedSetlist(from: json)
        #expect(shared.songs == ["CP-0202", "MP-1008"])
        #expect(shared.live == true)
    }

    @Test("A malformed setlists envelope throws .decoding")
    func decodeFailureThrowsDecodingError() throws {
        let json = Data(#"{ "not_setlists": [] }"#.utf8)
        #expect(throws: APIError.decoding) {
            _ = try APIClient.decodeSetlists(from: json)
        }
    }
}

@Suite("Setlists networked calls (mocked transport)", .serialized)
struct SetlistsNetworkedTests {

    private static func response(for request: URLRequest, status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    @Test("userSetlists() sends the Bearer token and decodes the response")
    func userSetlistsEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let json = Data(#"{ "setlists": [ { "id": "slist-abc123", "name": "Evening Worship", "songs": [] } ] }"#.utf8)
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), json)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "test-token")
            let setlists = try await client.userSetlists()

            #expect(seenRequest.current?.url?.query?.contains("action=user_setlists") == true)
            #expect(seenRequest.current?.value(forHTTPHeaderField: "Authorization") == "Bearer test-token")
            #expect(setlists.count == 1)
        }
    }

    @Test("syncSetlists(_:mode:) POSTs and decodes the resulting authoritative list")
    func syncSetlistsEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let json = Data(#"{ "setlists": [ { "id": "slist-abc123", "name": "Evening Worship", "songs": [] } ] }"#.utf8)
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), json)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "test-token")
            let setlist = Setlist(id: "slist-abc123", name: "Evening Worship")
            let result = try await client.syncSetlists([setlist], mode: .replace, since: nil, deleted: [])

            #expect(seenRequest.current?.httpMethod == "POST")
            #expect(result.setlists.count == 1)
            // An older-style answer with none of the protocol-2 extras still decodes.
            #expect(result.syncedAt == nil)
            #expect(result.tombstones.isEmpty)
            #expect(result.conflicts.isEmpty)
        }
    }

    @Test("shareSetlist(_:existingShareId:) POSTs authenticated and decodes {id, url}")
    func shareSetlistEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let json = Data(#"{ "id": "1a2b3c4d", "url": "/setlist/shared/1a2b3c4d" }"#.utf8)
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), json)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "test-token")
            let songId = try #require(SongID(rawValue: "MP-1008"))
            let setlist = Setlist(id: "slist-abc123", name: "Evening Worship", songs: [SetlistSongEntry(songId: songId, title: "Amazing Grace", songbookAbbreviation: "MP", number: 1008)])
            let result = try await client.shareSetlist(setlist)

            #expect(seenRequest.current?.httpMethod == "POST")
            #expect(seenRequest.current?.value(forHTTPHeaderField: "Authorization") == "Bearer test-token")
            #expect(result.id == "1a2b3c4d")
        }
    }

    @Test("sharedSetlist(shareId:) sends NO Authorization header and decodes the public response")
    func sharedSetlistEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let json = Data(#"{ "id": "1a2b3c4d", "name": "Sunday Morning Service", "songs": ["CP-0202"], "live": true, "created": null, "updated": null }"#.utf8)
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), json)
            }
            defer { MockURLProtocol.requestHandler = nil }

            // No bearer token supplied AT ALL — proves this call genuinely
            // works unauthenticated, matching the real `setlist_get`
            // handler having no `getAuthenticatedUser()` gate.
            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let shared = try await client.sharedSetlist(shareId: "1a2b3c4d")

            #expect(seenRequest.current?.value(forHTTPHeaderField: "Authorization") == nil)
            #expect(shared.name == "Sunday Morning Service")
        }
    }

    @Test("userSetlists() throws .unauthorized on a 401, without retrying")
    func userSetlistsUnauthorized() async throws {
        try await MockTransportLock.shared.withLock {
            let callCount = LockedCounter()
            MockURLProtocol.requestHandler = { request in
                callCount.increment()
                return (Self.response(for: request, status: 401), Data())
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "dead-token")
            await #expect(throws: APIError.unauthorized) {
                _ = try await client.userSetlists()
            }
            #expect(callCount.current == 1)
        }
    }
}

// `LockedBox` (a small, manually-synchronized single-slot box) is already
// declared, module-visible, in `NetworkedAPIClientTests.swift` within this
// SAME `IHAPITests` target — reused directly here rather than redeclared,
// per the repo's modularity rule.

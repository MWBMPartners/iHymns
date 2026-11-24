// ProfileSyncAPITests.swift
// IHAPITests
//
// ELI5: Checks the two `profile_sync` calls build the right requests and read the
// server's answers, including a value the app cannot map and the refusals it acts on.
//
// DETAILED: PR 3b step I4 (#2180). Shapes modelled from `api.php`'s
// `case 'profile_sync'` and `includes/profile_sync.php` (read 2026-11-24).
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHModels

@Suite("profile_sync endpoints and decoding")
struct ProfileSyncAPITests {

    @Test("The read is a signed-in GET carrying the cursor")
    func buildsRead() {
        let endpoint = Endpoint.profileSyncRead(cursor: 42)
        #expect(endpoint.action == "profile_sync")
        #expect(endpoint.httpMethod == "GET")
        #expect(endpoint.requiresAuth == true)
        #expect(endpoint.capturesRefusalReason == true)
        #expect(endpoint.queryItems.contains { $0.name == "cursor" && $0.value == "42" })
        #expect(Endpoint.profileSyncRead(cursor: -5).queryItems.contains { $0.name == "cursor" && $0.value == "0" })
    }

    @Test("The send carries schemaVersion, client.platform apple, the cursor, and set/delete changes (a delete has no value)")
    func buildsSend() throws {
        let request = ProfileSyncRequest(
            schemaVersion: 2, appVersion: "1.2.3", cursor: 7,
            changes: [
                ProfileSyncChange(section: "appearance", key: "theme", operation: .set, value: .string("dark")),
                ProfileSyncChange(section: "appearance", key: "cvd", operation: .delete)
            ]
        )
        let endpoint = try Endpoint.profileSyncSend(request)
        #expect(endpoint.httpMethod == "POST")
        #expect(endpoint.requiresAuth == true)
        let jsonBody = try #require(endpoint.httpBody)
        let jsonObject = try JSONSerialization.jsonObject(with: jsonBody)
        let json = try #require(jsonObject as? [String: Any])
        #expect(json["schemaVersion"] as? Int == 2)
        #expect(json["cursor"] as? Int == 7)
        let client = try #require(json["client"] as? [String: Any])
        #expect(client["platform"] as? String == "apple")
        #expect(client["appVersion"] as? String == "1.2.3")
        let changes = try #require(json["changes"] as? [[String: Any]])
        #expect(changes.count == 2)
        #expect(changes[0]["op"] as? String == "set")
        #expect(changes[0]["value"] as? String == "dark")
        #expect(changes[1]["op"] as? String == "delete")
        #expect(changes[1].keys.contains("value") == false)
    }

    @Test("A reply decodes, and a value that is not a plain scalar reads as nil instead of failing")
    func decodesReply() throws {
        let json = Data(#"""
        {"ok":true,"cursor":9,"more":false,"resetCursor":false,"schemaVersion":2,
         "changes":[
           {"section":"appearance","key":"theme","value":"dark","deleted":false,"version":8},
           {"section":"reading","key":"display","value":{"fontSize":1.5},"deleted":false,"version":9},
           {"section":"appearance","key":"cvd","value":null,"deleted":true,"version":10}],
         "rejected":[{"section":"x","key":"y","reason":"unknown_key"}],"applied":2}
        """#.utf8)
        let reply = try APIClient.decodeProfileSyncReply(from: json)
        #expect(reply.cursor == 9)
        #expect(reply.changes.count == 3)
        #expect(reply.changes[0].value == .string("dark"))
        #expect(reply.changes[1].value == nil)      // an object: not mappable, not a failure
        #expect(reply.changes[2].deleted)
        #expect(reply.rejected.first?.reason == "unknown_key")
        #expect(reply.applied == 2)
    }

    @Test("Only the cursor is required in a reply")
    func minimalReply() throws {
        let reply = try APIClient.decodeProfileSyncReply(from: Data(#"{"cursor":0}"#.utf8))
        #expect(reply.changes.isEmpty && reply.rejected.isEmpty && reply.more == false && reply.resetCursor == false)
        #expect(throws: APIError.decoding) { _ = try APIClient.decodeProfileSyncReply(from: Data(#"{"changes":[]}"#.utf8)) }
    }
}

@Suite("profile_sync networked refusals (mocked transport)", .serialized)
struct ProfileSyncNetworkedTests {

    private static func response(for request: URLRequest, status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    @Test("A 409 carries the reason word for both the read and the send; a 413 carries its word too")
    func refusalsCarryReasonWords() async throws {
        try await MockTransportLock.shared.withLock {
            defer { MockURLProtocol.requestHandler = nil }
            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "t", retryBaseDelaySeconds: 0.001)

            MockURLProtocol.requestHandler = { request in
                (Self.response(for: request, status: 409), Data(#"{"ok":false,"error":{"code":"http_409","message":"x","reason":"profile_sync_disabled"}}"#.utf8))
            }
            await #expect(throws: APIError.server(status: 409, message: "profile_sync_disabled")) {
                _ = try await client.profileSyncRead(cursor: 0)
            }

            MockURLProtocol.requestHandler = { request in
                (Self.response(for: request, status: 413), Data(#"{"ok":false,"error":{"code":"http_413","message":"x","reason":"too_many_changes"}}"#.utf8))
            }
            await #expect(throws: APIError.server(status: 413, message: "too_many_changes")) {
                _ = try await client.profileSyncSend(ProfileSyncRequest(schemaVersion: 2, appVersion: nil, cursor: 0, changes: []))
            }
        }
    }
}

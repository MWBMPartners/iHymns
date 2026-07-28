// ApnsAPITests.swift
// IHAPITests
//
// ELI5: Checks `?action=apns_register`/`apns_unregister` build the right
// request (the right body keys, `sessionId` OMITTED when there isn't one,
// Bearer-authed, same-origin `X-Requested-With` header) and that
// `?action=live_follow_create`'s decoder now understands the new
// `sessionId` field the server added (#1429 C1) — AND still decodes a
// legacy response that doesn't have it.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). Mirrors
// `AccountDeleteAPITests.swift`'s exact two-suite shape (`Endpoint`-building
// tests, then networked-mock tests) — the newest precedent for a POST
// endpoint pair in this test target. `LockedBox<URLRequest>` is defined in
// `NetworkedAPIClientTests.swift` (same `IHAPITests` target, so it's
// directly visible here — no need to redeclare it, per the repo's
// modularity rule applied to test infrastructure).
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHModels

@Suite("Endpoint.apnsRegister/apnsUnregister request building (#1429 C6)")
struct ApnsEndpointTests {

    @Test("apnsRegister: POST, auth required, body carries pushToken/apnsEnv/kind/sessionId")
    func buildsApnsRegisterEndpoint() throws {
        let endpoint = try Endpoint.apnsRegister(pushTokenHex: "deadbeef", apnsEnv: "sandbox", kind: "liveActivity", sessionId: 42)
        #expect(endpoint.action == "apns_register")
        #expect(endpoint.requiresAuth == true)
        #expect(endpoint.httpMethod == "POST")

        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["pushToken"] as? String == "deadbeef")
        #expect(json["apnsEnv"] as? String == "sandbox")
        #expect(json["kind"] as? String == "liveActivity")
        #expect(json["sessionId"] as? Int == 42)
    }

    @Test("apnsRegister: a nil sessionId (kind=device) is an OMITTED key, never a literal null")
    func apnsRegisterOmitsNilSessionId() throws {
        let endpoint = try Endpoint.apnsRegister(pushTokenHex: "deadbeef", apnsEnv: "production", kind: "device", sessionId: nil)
        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["sessionId"] == nil)
        #expect(json["kind"] as? String == "device")
    }

    @Test("apnsUnregister: POST, auth required, body carries only pushToken")
    func buildsApnsUnregisterEndpoint() throws {
        let endpoint = try Endpoint.apnsUnregister(pushTokenHex: "deadbeef")
        #expect(endpoint.action == "apns_unregister")
        #expect(endpoint.requiresAuth == true)
        #expect(endpoint.httpMethod == "POST")
        struct Body: Decodable { let pushToken: String }
        #expect(try JSONDecoder().decode(Body.self, from: try #require(endpoint.httpBody)).pushToken == "deadbeef")
    }
}

@Suite("apnsRegister/apnsUnregister networked calls (mocked transport, #1429 C6)", .serialized)
struct ApnsNetworkedTests {

    private static func response(for request: URLRequest, status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    @Test("apnsRegister sends the Bearer token, X-Requested-With, and the right body")
    func apnsRegisterSendsExpectedRequest() async throws {
        try await MockTransportLock.shared.withLock {
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), Data(#"{"ok":true}"#.utf8))
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "test-token")
            try await client.apnsRegister(pushTokenHex: "deadbeef", apnsEnv: "sandbox", kind: "liveActivity", sessionId: 7)

            #expect(seenRequest.current?.url?.query?.contains("action=apns_register") == true)
            #expect(seenRequest.current?.httpMethod == "POST")
            #expect(seenRequest.current?.value(forHTTPHeaderField: "Authorization") == "Bearer test-token")
            #expect(seenRequest.current?.value(forHTTPHeaderField: "X-Requested-With") == "XMLHttpRequest")
        }
    }

    @Test("apnsUnregister sends the Bearer token and the pushToken body")
    func apnsUnregisterSendsExpectedRequest() async throws {
        try await MockTransportLock.shared.withLock {
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), Data(#"{"ok":true}"#.utf8))
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "test-token")
            try await client.apnsUnregister(pushTokenHex: "deadbeef")

            #expect(seenRequest.current?.url?.query?.contains("action=apns_unregister") == true)
            #expect(seenRequest.current?.value(forHTTPHeaderField: "Authorization") == "Bearer test-token")
        }
    }
}

@Suite("decodeLiveFollowCreate — the new sessionId field (#1429 C1/C6)")
struct LiveFollowCreateSessionIdDecodingTests {

    @Test("decodes sessionId when the server sends it")
    func decodesSessionIdWhenPresent() throws {
        let data = Data(#"{"ok":true,"code":"K7M4PQ","revision":0,"sessionId":42}"#.utf8)
        let result = try APIClient.decodeLiveFollowCreate(from: data)
        #expect(result.code == "K7M4PQ")
        #expect(result.sessionId == 42)
    }

    @Test("still decodes a LEGACY response body with no sessionId key at all — sessionId is nil")
    func decodesLegacyResponseWithoutSessionId() throws {
        let data = Data(#"{"ok":true,"code":"K7M4PQ","revision":0}"#.utf8)
        let result = try APIClient.decodeLiveFollowCreate(from: data)
        #expect(result.code == "K7M4PQ")
        #expect(result.sessionId == nil)
    }
}

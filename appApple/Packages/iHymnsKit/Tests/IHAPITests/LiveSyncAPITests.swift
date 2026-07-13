// LiveSyncAPITests.swift
// IHAPITests
//
// ELI5: Checks that each of the nine Live Follow / Service Mode "phone
// calls" writes the correct web address, method, and login-required flag,
// that the presence-token Cookie (spec §4.3/D-6) is attached exactly when
// it should be and NEVER otherwise, and that every one of the nine
// committed fixtures decodes into the shape `APIClient` promises.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §7.2). Mirrors `APIClientTests.swift`'s existing request-building +
// fixture-decode pattern.
import Foundation
import Testing
import IHTestFixtures
@testable import IHAPI
@testable import IHModels

@Suite("LiveSync — the 9 endpoint factories")
struct LiveSyncEndpointFactoryTests {

    @Test("liveFollowCreate: POST, auth required, action + optional body fields")
    func liveFollowCreateShape() throws {
        let endpoint = try Endpoint.liveFollowCreate(songId: "MP-0031", componentIndex: 2)
        #expect(endpoint.action == "live_follow_create")
        #expect(endpoint.httpMethod == "POST")
        #expect(endpoint.requiresAuth)
        struct Body: Decodable { let songId: String; let componentIndex: Int }
        let body = try JSONDecoder().decode(Body.self, from: try #require(endpoint.httpBody))
        #expect(body.songId == "MP-0031")
        #expect(body.componentIndex == 2)
    }

    @Test("liveFollowCreate: nil songId/componentIndex are OMITTED keys, not null")
    func liveFollowCreateOmitsNilFields() throws {
        let endpoint = try Endpoint.liveFollowCreate(songId: nil, componentIndex: nil)
        let json = try JSONSerialization.jsonObject(with: try #require(endpoint.httpBody)) as? [String: Any]
        #expect(json?.isEmpty == true)
    }

    @Test("liveFollowUpdate: POST, auth required, code + REQUIRED songId + optional componentIndex")
    func liveFollowUpdateShape() throws {
        let endpoint = try Endpoint.liveFollowUpdate(code: "K7M4PQ", songId: "MP-0031", componentIndex: 1)
        #expect(endpoint.action == "live_follow_update")
        #expect(endpoint.httpMethod == "POST")
        #expect(endpoint.requiresAuth)
        struct Body: Decodable { let code: String; let songId: String; let componentIndex: Int? }
        let body = try JSONDecoder().decode(Body.self, from: try #require(endpoint.httpBody))
        #expect(body.code == "K7M4PQ")
        #expect(body.songId == "MP-0031")
        #expect(body.componentIndex == 1)
    }

    @Test("liveFollowHeartbeat: POST, auth required, body carries only code")
    func liveFollowHeartbeatShape() throws {
        let endpoint = try Endpoint.liveFollowHeartbeat(code: "K7M4PQ")
        #expect(endpoint.action == "live_follow_heartbeat")
        #expect(endpoint.httpMethod == "POST")
        #expect(endpoint.requiresAuth)
        struct Body: Decodable { let code: String }
        #expect(try JSONDecoder().decode(Body.self, from: try #require(endpoint.httpBody)).code == "K7M4PQ")
    }

    @Test("liveFollowLeave: POST, auth required, body carries only code")
    func liveFollowLeaveShape() throws {
        let endpoint = try Endpoint.liveFollowLeave(code: "K7M4PQ")
        #expect(endpoint.action == "live_follow_leave")
        #expect(endpoint.httpMethod == "POST")
        #expect(endpoint.requiresAuth)
    }

    @Test("liveFollowJoin: GET, no auth, code as a query item")
    func liveFollowJoinShape() {
        let endpoint = Endpoint.liveFollowJoin(code: "K7M4PQ")
        #expect(endpoint.action == "live_follow_join")
        #expect(endpoint.httpMethod == "GET")
        #expect(!endpoint.requiresAuth)
        #expect(endpoint.queryItems.contains { $0.name == "code" && $0.value == "K7M4PQ" })
    }

    @Test("liveFollowPoll: GET, no auth, code + since always present; token present only when supplied")
    func liveFollowPollShape() {
        let withToken = Endpoint.liveFollowPoll(code: "K7M4PQ", since: 3, token: "abc123")
        #expect(withToken.action == "live_follow_poll")
        #expect(!withToken.requiresAuth)
        #expect(withToken.queryItems.contains { $0.name == "since" && $0.value == "3" })
        #expect(withToken.queryItems.contains { $0.name == "token" && $0.value == "abc123" })

        let withoutToken = Endpoint.liveFollowPoll(code: "K7M4PQ", since: 0, token: nil)
        #expect(!withoutToken.queryItems.contains { $0.name == "token" })
    }

    @Test("serviceJoin: POST, no auth, code + presenceDeviceId + role")
    func serviceJoinShape() throws {
        let endpoint = try Endpoint.serviceJoin(code: "ABC123", presenceDeviceId: "device-1", role: "congregant")
        #expect(endpoint.action == "service_join")
        #expect(endpoint.httpMethod == "POST")
        #expect(!endpoint.requiresAuth)
        struct Body: Decodable { let code: String; let presenceDeviceId: String; let role: String }
        let body = try JSONDecoder().decode(Body.self, from: try #require(endpoint.httpBody))
        #expect(body.presenceDeviceId == "device-1")
        #expect(body.role == "congregant")
    }

    @Test("servicePoll: GET, no auth, presenceToken + since as query items")
    func servicePollShape() {
        let endpoint = Endpoint.servicePoll(presenceToken: "tok-1", since: 5)
        #expect(endpoint.action == "service_poll")
        #expect(!endpoint.requiresAuth)
        #expect(endpoint.queryItems.contains { $0.name == "presenceToken" && $0.value == "tok-1" })
        #expect(endpoint.queryItems.contains { $0.name == "since" && $0.value == "5" })
    }

    @Test("serviceLeave: POST, no auth, body carries only presenceToken")
    func serviceLeaveShape() throws {
        let endpoint = try Endpoint.serviceLeave(presenceToken: "tok-1")
        #expect(endpoint.action == "service_leave")
        #expect(endpoint.httpMethod == "POST")
        #expect(!endpoint.requiresAuth)
        struct Body: Decodable { let presenceToken: String }
        #expect(try JSONDecoder().decode(Body.self, from: try #require(endpoint.httpBody)).presenceToken == "tok-1")
    }
}

@Suite("LiveSync — presence-Cookie transport (spec §4.3, D-6)")
struct LiveSyncPresenceCookieTests {
    private static let validToken = "ye82rBJlEBjg0EPn7Yax1xMs0o5xQ9CuHt7kmkbsoOE"

    @Test("A valid 43-char token attaches the Cookie header and disables cookie handling")
    func attachesCookieForValidToken() {
        let request = APIClient.makeURLRequest(for: .songsIndex, in: .dev, presenceToken: Self.validToken)
        #expect(request.value(forHTTPHeaderField: "Cookie") == "ihymns_sf_presence_token=\(Self.validToken)")
        #expect(request.httpShouldHandleCookies == false)
    }

    @Test("nil presenceToken leaves the request byte-identical to pre-PR-10 behaviour")
    func nilTokenIsANoOp() {
        let request = APIClient.makeURLRequest(for: .songsIndex, in: .dev)
        #expect(request.value(forHTTPHeaderField: "Cookie") == nil)
        #expect(request.httpShouldHandleCookies == true)
    }

    @Test("A malformed token (wrong length) is dropped silently — never reaches the wire")
    func malformedLengthTokenIsDropped() {
        let request = APIClient.makeURLRequest(for: .songsIndex, in: .dev, presenceToken: "too-short")
        #expect(request.value(forHTTPHeaderField: "Cookie") == nil)
        #expect(request.httpShouldHandleCookies == true)
    }

    @Test("A malformed token (bad alphabet) is dropped silently")
    func malformedAlphabetTokenIsDropped() {
        // 43 characters, but one is a space — never a legal base64url char.
        let badToken = String(repeating: "a", count: 42) + " "
        let request = APIClient.makeURLRequest(for: .songsIndex, in: .dev, presenceToken: badToken)
        #expect(request.value(forHTTPHeaderField: "Cookie") == nil)
    }

    @Test("Presence cookie and Bearer header coexist on an auth endpoint")
    func presenceAndBearerCoexist() {
        let endpoint = Endpoint(action: "song_detail", requiresAuth: true)
        let request = APIClient.makeURLRequest(for: endpoint, in: .dev, bearerToken: "deadbeef", presenceToken: Self.validToken)
        #expect(request.value(forHTTPHeaderField: "Authorization") == "Bearer deadbeef")
        #expect(request.value(forHTTPHeaderField: "Cookie") == "ihymns_sf_presence_token=\(Self.validToken)")
    }
}

/// PR-10 Opus-review FIX 3 — the D-6 residual: a cross-host 3xx must never
/// carry the presence Cookie forward. Exercises `RedirectCookieGuard`'s
/// pure decision function directly (see that type's own doc comment for
/// why a real `URLSessionTask` isn't practical to construct here) rather
/// than the delegate method itself.
@Suite("LiveSync — redirect-guard host comparison (PR-10 review FIX 3, D-6 residual)")
struct RedirectCookieGuardTests {

    @Test("Same host (any case) keeps the cookie")
    func sameHostKeepsCookie() {
        #expect(RedirectCookieGuard.shouldStripCookie(originalHost: "app.ihymns.com", redirectHost: "app.ihymns.com") == false)
        #expect(RedirectCookieGuard.shouldStripCookie(originalHost: "App.iHymns.com", redirectHost: "app.ihymns.com") == false)
    }

    @Test("A different host strips the cookie")
    func differentHostStripsCookie() {
        #expect(RedirectCookieGuard.shouldStripCookie(originalHost: "app.ihymns.com", redirectHost: "attacker.example.com") == true)
    }

    @Test("An indeterminate comparison (either host missing) fails CLOSED — strips the cookie")
    func indeterminateHostFailsClosed() {
        #expect(RedirectCookieGuard.shouldStripCookie(originalHost: nil, redirectHost: "app.ihymns.com") == true)
        #expect(RedirectCookieGuard.shouldStripCookie(originalHost: "app.ihymns.com", redirectHost: nil) == true)
        #expect(RedirectCookieGuard.shouldStripCookie(originalHost: nil, redirectHost: nil) == true)
    }
}

@Suite("LiveSync — decoding the 9 fixtures")
struct LiveSyncDecodingTests {

    @Test("live_follow_join_not_found.json throws a clear failure, never crashes an unrelated decode")
    func liveFollowJoinNotFoundIsNotASuccessShape() throws {
        let data = try ContractFixtures.liveFollowJoinNotFound()
        #expect(throws: APIError.self) {
            _ = try APIClient.decodeLiveFollowJoin(from: data)
        }
    }

    @Test("live_follow_poll_inactive.json decodes active:false, no end signal misread")
    func decodesLiveFollowPollInactive() throws {
        let update = try APIClient.decodeLivePollUpdate(from: ContractFixtures.liveFollowPollInactive())
        #expect(update.active == false)
        #expect(update.revision == nil)
    }

    @Test("live_follow_join.json (code-derived) decodes into LiveFollowJoinResult with the engine-only followToken")
    func decodesLiveFollowJoinFixture() throws {
        let result = try APIClient.decodeLiveFollowJoin(from: ContractFixtures.liveFollowJoin())
        #expect(result.info.code == "K7M4PQ")
        #expect(result.info.hostDisplayName == "Pastor Sam")
        #expect(result.info.initial.revision == 3)
        #expect(result.followToken == "a1b2c3d4e5f60718293a4b5c6d7e8f90")
    }

    @Test("live_follow_poll_changed.json (code-derived) decodes changed:true with the new snapshot fields")
    func decodesLiveFollowPollChangedFixture() throws {
        let update = try APIClient.decodeLivePollUpdate(from: ContractFixtures.liveFollowPollChanged())
        #expect(update.changed == true)
        #expect(update.revision == 4)
        #expect(update.currentSongId == "MP-0031")
        #expect(update.componentIndex == 2)
        #expect(update.state?.resolvedDisplayState == .live)
    }

    @Test("service_join_not_active.json throws — the opaque 404 body is not a success shape")
    func serviceJoinNotActiveIsNotASuccessShape() throws {
        let data = try ContractFixtures.serviceJoinNotActive()
        #expect(throws: APIError.self) {
            _ = try APIClient.decodeServiceJoin(from: data)
        }
    }

    @Test("service_poll_inactive.json decodes active:false")
    func decodesServicePollInactive() throws {
        let update = try APIClient.decodeLivePollUpdate(from: ContractFixtures.servicePollInactive())
        #expect(update.active == false)
    }

    @Test("service_join.json (code-derived) decodes into ServiceJoinResult with the engine-only presenceToken")
    func decodesServiceJoinFixture() throws {
        let result = try APIClient.decodeServiceJoin(from: ContractFixtures.serviceJoin())
        #expect(result.presenceToken.count == 43)
        #expect(result.info.pollIntervalMs == 2500)
        #expect(result.info.initial.songId == "MP-0031")
    }

    @Test("service_poll_changed.json (code-derived) decodes active:true, changed:true")
    func decodesServicePollChangedFixture() throws {
        let update = try APIClient.decodeLivePollUpdate(from: ContractFixtures.servicePollChanged())
        #expect(update.active == true)
        #expect(update.changed == true)
        #expect(update.revision == 2)
    }

    @Test("service_poll_unchanged.json (code-derived) decodes active:true, changed:false, revision only")
    func decodesServicePollUnchangedFixture() throws {
        let update = try APIClient.decodeLivePollUpdate(from: ContractFixtures.servicePollUnchanged())
        #expect(update.active == true)
        #expect(update.changed == false)
        #expect(update.revision == 1)
        #expect(update.currentSongId == nil)
    }
}

// AppStatusAndCaptchaAPITests.swift
// IHAPITests
//
// ELI5: Checks the `app_status` read (request shape + decoding), the
// body-aware CAPTCHA classifier's truth table, and — end to end through the
// mocked network — that a solved token actually reaches `auth_login`/
// `auth_email_login_request`'s request body and that a real CAPTCHA-refusal
// 403 comes back out of `APIClient` as `.captchaRequired`.
//
// DETAILED: #947/#340 native scaffold (`.claude/captcha-native-and-outage-plan.md`
// §2.8). Mirrors `FavoritesAndAuthAPITests.swift`'s three-suite shape
// (request-building / decoding / networked) exactly, and reuses its SAME
// test-target-scoped `LockedBox<Value>` helper (`NetworkedAPIClientTests.swift`
// — `internal` visibility, so any file in this `IHAPITests` target can use
// it without redefining it, exactly as `FavoritesAndAuthAPITests.swift`
// already does).
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHModels

@Suite("Endpoint.appStatus request building")
struct AppStatusEndpointTests {

    @Test("Endpoint.appStatus is a bodyless GET requiring no auth")
    func buildsAppStatusEndpoint() {
        let endpoint = Endpoint.appStatus
        #expect(endpoint.action == "app_status")
        #expect(endpoint.requiresAuth == false)
        #expect(endpoint.httpMethod == "GET")
        #expect(endpoint.httpBody == nil)
    }
}

@Suite("AppStatus decoding (APIClient layer)")
struct AppStatusDecodingTests {

    @Test("decodeAppStatus decodes a dormant install (no captcha key) to captcha == nil")
    func decodesDormant() throws {
        let json = Data(#"{ "maintenance": false, "motd": "" }"#.utf8)
        let status = try APIClient.decodeAppStatus(from: json)
        #expect(status.captcha == nil)
    }

    @Test("decodeAppStatus decodes a configured install's real captcha shape")
    func decodesConfigured() throws {
        let json = Data("""
        { "captcha": { "provider": "turnstile", "siteKey": "k", "scriptUrl": "https://challenges.cloudflare.com/turnstile/v0/api.js", "renderGlobal": "turnstile", "field": "cf-turnstile-response", "forms": ["login"] } }
        """.utf8)
        let status = try APIClient.decodeAppStatus(from: json)
        #expect(status.captcha?.provider == "turnstile")
        #expect(status.captcha?.isRequired(for: "login") == true)
    }

    @Test("decodeAppStatus throws .decoding on genuinely non-JSON bytes")
    func throwsOnGarbage() {
        let garbage = Data("not json at all".utf8)
        #expect(throws: APIError.decoding) {
            _ = try APIClient.decodeAppStatus(from: garbage)
        }
    }
}

@Suite("classifyMachineRefusal truth table")
struct ClassifyMachineRefusalTests {

    @Test("A v2 envelope 403 with error.reason == captcha_required classifies as .captchaRequired")
    func v2EnvelopeReason() {
        // The shape THIS client actually receives TODAY (before the
        // server-side `code` companion change lands, plan §2.1/§0-8): `code`
        // is still the generic `http_403`, only `reason` carries the signal.
        let body = Data(#"{"ok":false,"error":{"code":"http_403","message":"Please complete the verification challenge and try again.","reason":"captcha_required"}}"#.utf8)
        #expect(APIClient.classifyMachineRefusal(httpStatus: 403, body: body) == .captchaRequired)
    }

    @Test("A v2 envelope 403 with error.code == captcha_required classifies as .captchaRequired (post server-side N1)")
    func v2EnvelopeCode() {
        let body = Data(#"{"ok":false,"error":{"code":"captcha_required","message":"…","reason":"captcha_required"}}"#.utf8)
        #expect(APIClient.classifyMachineRefusal(httpStatus: 403, body: body) == .captchaRequired)
    }

    @Test("A v1 flat 403 body (defensive — this client never actually sends v1) also classifies")
    func v1Flat() {
        let body = Data(#"{"error":"Please complete the verification challenge and try again.","reason":"captcha_required"}"#.utf8)
        #expect(APIClient.classifyMachineRefusal(httpStatus: 403, body: body) == .captchaRequired)
    }

    @Test("A 403 with no reason/code at all returns nil — falls back to classify(...)")
    func plain403ReturnsNil() {
        let body = Data(#"{"ok":false,"error":{"code":"http_403","message":"CSRF token invalid"}}"#.utf8)
        #expect(APIClient.classifyMachineRefusal(httpStatus: 403, body: body) == nil)
    }

    @Test("A 403 with a DIFFERENT machine reason returns nil — never matched as captcha")
    func differentReasonReturnsNil() {
        let body = Data(#"{"ok":false,"error":{"code":"http_403","reason":"some_other_reason"}}"#.utf8)
        #expect(APIClient.classifyMachineRefusal(httpStatus: 403, body: body) == nil)
    }

    @Test("A NON-403 status with the captcha reason present returns nil — status gates everything")
    func nonStatusReturnsNil() {
        let body = Data(#"{"ok":false,"error":{"code":"http_401","reason":"captcha_required"}}"#.utf8)
        #expect(APIClient.classifyMachineRefusal(httpStatus: 401, body: body) == nil)
    }

    @Test("Garbage (non-JSON) bytes on a 403 return nil, never throw or crash")
    func garbageBodyReturnsNil() {
        let body = Data("<html>not json</html>".utf8)
        #expect(APIClient.classifyMachineRefusal(httpStatus: 403, body: body) == nil)
    }
}

@Suite("APIError.captchaRequired — logging-safe descriptors (#1505 retrofit)")
struct APIErrorCaptchaTests {

    @Test(".captchaRequired names itself \"captchaRequired\" for .public-privacy logging")
    func caseNameIsCaptchaRequired() {
        #expect(APIError.captchaRequired.caseName == "captchaRequired")
    }

    @Test(".captchaRequired maps to HTTP 403 for logging, mirroring classify(httpStatus:)'s own reverse mapping")
    func httpStatusForLoggingIs403() {
        #expect(APIError.captchaRequired.httpStatusForLogging == 403)
    }
}

@Suite("Auth endpoints — captchaToken plumbing (request building)")
struct AuthEndpointsCaptchaTests {

    @Test("authLogin(captchaToken: nil) sends a body with NO captcha_token key — byte-identical to before this parameter existed")
    func authLoginOmitsTokenWhenNil() throws {
        let endpoint = try Endpoint.authLogin(username: "jane", password: "hunter2")
        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["username"] as? String == "jane")
        #expect(json["password"] as? String == "hunter2")
        #expect(json["captcha_token"] == nil)
    }

    @Test("authLogin(captchaToken:) sends captcha_token in the body")
    func authLoginIncludesToken() throws {
        let endpoint = try Endpoint.authLogin(username: "jane", password: "hunter2", captchaToken: "solved-token-1")
        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["captcha_token"] as? String == "solved-token-1")
    }

    @Test("authEmailLoginRequest(captchaToken: nil) omits captcha_token")
    func authEmailLoginRequestOmitsTokenWhenNil() throws {
        let endpoint = try Endpoint.authEmailLoginRequest(email: "user@example.com")
        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["captcha_token"] == nil)
    }

    @Test("authEmailLoginRequest(captchaToken:) sends captcha_token in the body")
    func authEmailLoginRequestIncludesToken() throws {
        let endpoint = try Endpoint.authEmailLoginRequest(email: "user@example.com", captchaToken: "solved-token-2")
        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["email"] as? String == "user@example.com")
        #expect(json["captcha_token"] as? String == "solved-token-2")
    }
}

@Suite("Auth + CAPTCHA networked calls (mocked transport)", .serialized)
struct AuthCaptchaNetworkedTests {

    private static func response(for request: URLRequest, status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    @Test("A CAPTCHA-refusal 403 from auth_login surfaces as APIError.captchaRequired, end to end")
    func authLoginCaptchaRefusalEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let body = Data(#"{"ok":false,"error":{"code":"http_403","message":"Please complete the verification challenge and try again.","reason":"captcha_required"}}"#.utf8)
            MockURLProtocol.requestHandler = { request in
                (Self.response(for: request, status: 403), body)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            await #expect(throws: APIError.captchaRequired) {
                _ = try await client.authLogin(username: "jane", password: "wrong-or-no-token")
            }
        }
    }

    @Test("A plain (non-captcha) 403 from auth_login still surfaces as .server(status: 403, _), unchanged")
    func authLoginPlainForbiddenUnaffected() async throws {
        try await MockTransportLock.shared.withLock {
            let body = Data(#"{"ok":false,"error":{"code":"http_403","message":"CSRF token invalid"}}"#.utf8)
            MockURLProtocol.requestHandler = { request in
                (Self.response(for: request, status: 403), body)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            do {
                _ = try await client.authLogin(username: "jane", password: "x")
                Issue.record("expected a thrown APIError")
            } catch let error as APIError {
                #expect(error == .server(status: 403, message: nil))
            }
        }
    }

    @Test("The captchaToken passed to authLogin(...) reaches the wire, unmodified")
    func authLoginTokenReachesWire() async throws {
        try await MockTransportLock.shared.withLock {
            let seenRequest = LockedBox<URLRequest>()
            let responseBody = Data(#"{"token":"session-token","user":{"id":1,"username":"jane","display_name":"Jane","role":"user"}}"#.utf8)
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), responseBody)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            _ = try await client.authLogin(username: "jane", password: "hunter2", captchaToken: "wire-token-abc")

            let request = try #require(seenRequest.current)
            let token = try Self.captchaToken(from: request)
            #expect(token == "wire-token-abc")
        }
    }

    @Test("A RETRY after a captcha refusal must supply a FRESH token — the client never caches/reuses the previous one")
    func retryUsesFreshToken() async throws {
        try await MockTransportLock.shared.withLock {
            let seenRequests = LockedBox<[URLRequest]>()
            seenRequests.set([])
            let refusalBody = Data(#"{"ok":false,"error":{"code":"http_403","reason":"captcha_required"}}"#.utf8)
            let successBody = Data(#"{"token":"session-token","user":{"id":1,"username":"jane","display_name":"Jane","role":"user"}}"#.utf8)
            // `LockedCounter` (`IHAPITestSupport`), not a captured `var` —
            // Swift 6 strict concurrency correctly refuses a bare mutable
            // `var` captured by this `@Sendable requestHandler` closure
            // (`MockURLProtocol.swift`'s own doc comment on `LockedCounter`).
            let attempt = LockedCounter()
            MockURLProtocol.requestHandler = { request in
                let thisAttempt = attempt.increment()
                seenRequests.set((seenRequests.current ?? []) + [request])
                // First attempt (a stale/spent token attached): refused.
                // Second attempt (a fresh token attached): accepted — the
                // server can tell them apart only by the TOKEN VALUE
                // itself, which is exactly what this test asserts on below.
                return thisAttempt == 1 ? (Self.response(for: request, status: 403), refusalBody)
                                        : (Self.response(for: request, status: 200), successBody)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())

            // Attempt 1: a stale token — refused as .captchaRequired. This
            // client NEVER auto-retries a POST (`APIClient+Auth.swift`'s own
            // header: "never auto-retry non-idempotent POSTs"), so the
            // caller (here, the test itself — `LoginView`/`SessionController`
            // in production) is responsible for supplying a fresh token on
            // the NEXT explicit call, exactly like a human re-solving the
            // widget after `reset()`.
            await #expect(throws: APIError.captchaRequired) {
                _ = try await client.authLogin(username: "jane", password: "hunter2", captchaToken: "stale-token")
            }

            // Attempt 2: a FRESH token — succeeds.
            let session = try await client.authLogin(username: "jane", password: "hunter2", captchaToken: "fresh-token")
            #expect(session.token == "session-token")

            let requests = try #require(seenRequests.current)
            #expect(requests.count == 2)
            let firstToken = try Self.captchaToken(from: requests[0])
            let secondToken = try Self.captchaToken(from: requests[1])
            #expect(firstToken == "stale-token")
            #expect(secondToken == "fresh-token")
            #expect(firstToken != secondToken)
        }
    }

    /// Reads `captcha_token` out of `request`'s body — checking
    /// `.httpBodyStream` as well as `.httpBody`, since `URLSession`
    /// frequently moves a POST's body into the STREAM once the request is
    /// actually handed to a transport (even a mocked one). Mirrors
    /// `IHFeaturesTests/IHAnalyticsNetworkSinkTests.swift`'s
    /// `analyticsSinkTestsRequestBodyData(_:)` helper — the SAME
    /// `URLSession`/`URLProtocol` quirk `AppRootViewModelSetlistsTests.swift`'s
    /// header documents (`FavoritesAndAuthAPITests.swift`'s own networked
    /// tests avoid asserting on a captured request's body for exactly this
    /// reason — this suite reads BOTH possible locations instead, so the
    /// assertion is robust either way).
    private static func captchaToken(from request: URLRequest) throws -> String? {
        let data = Self.requestBodyData(request)
        let json = try #require(JSONSerialization.jsonObject(with: data) as? [String: Any])
        return json["captcha_token"] as? String
    }

    private static func requestBodyData(_ request: URLRequest) -> Data {
        if let body = request.httpBody { return body }
        guard let stream = request.httpBodyStream else { return Data() }
        stream.open()
        defer { stream.close() }
        var data = Data()
        let bufferSize = 4096
        var buffer = [UInt8](repeating: 0, count: bufferSize)
        while stream.hasBytesAvailable {
            let bytesRead = stream.read(&buffer, maxLength: bufferSize)
            guard bytesRead > 0 else { break }
            data.append(buffer, count: bytesRead)
        }
        return data
    }
}

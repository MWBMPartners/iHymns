// SessionControllerCaptchaTests.swift
// IHAuthTests
//
// ELI5: Proves `SessionController.signIn(username:password:captchaToken:)`
// (#947/#340 native scaffold) is a thin, honest pass-through: the token
// reaches `auth_login`'s wire body, is OMITTED entirely when `nil` (byte
// -identical to before this parameter existed), and a retry with a
// DIFFERENT token sends that different token — SessionController itself
// caches/reuses nothing.
//
// DETAILED: Split out of `SessionControllerTests.swift` rather than growing
// that already-large file further — mirrors `SessionControllerDeleteAccountTests.swift`'s
// exact "own copy of `makeController()`/`response(status:)`" convention
// (that file's own header explains why: `private` is file-scoped in Swift,
// so a same-MODULE, different-file suite needs its own copies regardless).
// `.claude/captcha-native-and-outage-plan.md` §2.8's `AuthEndpointsCaptchaTests`
// coverage lives at the `IHAPI` layer (`AppStatusAndCaptchaAPITests.swift`)
// — THIS file proves the SAME contract survives one layer up, through
// `SessionController`, which is what `LoginView` actually calls.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth

@Suite("SessionController — captchaToken plumbing (#947/#340)", .serialized)
struct SessionControllerCaptchaTests {

    private static func response(status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: URL(string: "https://dev.ihymns.app/api")!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    /// Identical shape to `SessionControllerTests.makeController()` — see
    /// that file's own doc comment.
    private func makeController() -> (controller: SessionController, store: InMemoryTokenStore) {
        let apiClient = APIClient(
            environment: .dev,
            session: MockURLProtocol.makeSession(),
            retryBaseDelaySeconds: 0.001
        )
        let store = InMemoryTokenStore()
        return (SessionController(tokenStore: store, apiClient: apiClient), store)
    }

    /// Reads a POSTed JSON body out of `request` — checking
    /// `.httpBodyStream` as well as `.httpBody` (the same `URLSession`
    /// quirk `AppStatusAndCaptchaAPITests.swift`'s matching helper
    /// documents in full).
    private static func bodyJSON(from request: URLRequest) throws -> [String: Any] {
        var data = request.httpBody ?? Data()
        if data.isEmpty, let stream = request.httpBodyStream {
            stream.open()
            defer { stream.close() }
            let bufferSize = 4096
            var buffer = [UInt8](repeating: 0, count: bufferSize)
            while stream.hasBytesAvailable {
                let bytesRead = stream.read(&buffer, maxLength: bufferSize)
                guard bytesRead > 0 else { break }
                data.append(buffer, count: bytesRead)
            }
        }
        return try #require(JSONSerialization.jsonObject(with: data) as? [String: Any])
    }

    @Test("signIn(username:password:) with NO captchaToken sends a body with no captcha_token key — byte-identical to before this parameter existed")
    func signInOmitsTokenByDefault() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, _) = makeController()
            let seenRequest = LockedRequestBox()
            let responseBody = Data("""
            {"token": "abc123", "user": {"id": 1, "username": "jane", "display_name": "Jane Doe", "role": "user"}}
            """.utf8)
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(status: 200), responseBody)
            }
            defer { MockURLProtocol.requestHandler = nil }

            // Deliberately the OLD, pre-scaffold call shape (no captchaToken
            // argument at all) — proves every EXISTING call site still
            // compiles and behaves identically.
            try await controller.signIn(username: "jane", password: "hunter2")

            let request = try #require(seenRequest.current)
            let json = try Self.bodyJSON(from: request)
            #expect(json["captcha_token"] == nil)
        }
    }

    @Test("signIn(username:password:captchaToken:) attaches the token to auth_login's body")
    func signInAttachesToken() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, _) = makeController()
            let seenRequest = LockedRequestBox()
            let responseBody = Data("""
            {"token": "abc123", "user": {"id": 1, "username": "jane", "display_name": "Jane Doe", "role": "user"}}
            """.utf8)
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(status: 200), responseBody)
            }
            defer { MockURLProtocol.requestHandler = nil }

            try await controller.signIn(username: "jane", password: "hunter2", captchaToken: "solved-abc")

            let request = try #require(seenRequest.current)
            let json = try Self.bodyJSON(from: request)
            #expect(json["captcha_token"] as? String == "solved-abc")
        }
    }

    @Test("A .captchaRequired refusal propagates unchanged, and persists nothing locally")
    func captchaRequiredPropagatesAndPersistsNothing() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            let refusalBody = Data(#"{"ok":false,"error":{"code":"http_403","reason":"captcha_required"}}"#.utf8)
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 403), refusalBody) }
            defer { MockURLProtocol.requestHandler = nil }

            await #expect(throws: APIError.captchaRequired) {
                try await controller.signIn(username: "jane", password: "hunter2", captchaToken: "missing-or-stale")
            }
            #expect(await controller.state == .signedOut)
            #expect(try await store.load() == nil)
        }
    }

    @Test("A retry with a FRESH token after a .captchaRequired refusal succeeds and sends the NEW token, not the old one")
    func retrySendsFreshTokenNotStaleOne() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            let refusalBody = Data(#"{"ok":false,"error":{"code":"http_403","reason":"captcha_required"}}"#.utf8)
            let successBody = Data("""
            {"token": "abc123", "user": {"id": 1, "username": "jane", "display_name": "Jane Doe", "role": "user"}}
            """.utf8)
            let seenRequests = LockedRequestListBox()
            let attempt = LockedCounter()
            MockURLProtocol.requestHandler = { request in
                seenRequests.append(request)
                return attempt.increment() == 1
                    ? (Self.response(status: 403), refusalBody)
                    : (Self.response(status: 200), successBody)
            }
            defer { MockURLProtocol.requestHandler = nil }

            // Attempt 1 — stale token, refused. `SessionController` does NOT
            // auto-retry (the underlying POST never does — `APIClient+Auth
            // .swift`'s own header); the CALLER supplies a fresh token on
            // the next explicit call, exactly like `LoginView` does after
            // its own `resetCaptcha()`.
            await #expect(throws: APIError.captchaRequired) {
                try await controller.signIn(username: "jane", password: "hunter2", captchaToken: "stale-token")
            }
            #expect(await controller.state == .signedOut)

            // Attempt 2 — a genuinely DIFFERENT token — succeeds.
            try await controller.signIn(username: "jane", password: "hunter2", captchaToken: "fresh-token")
            #expect(await controller.state == .signedIn(token: "abc123"))
            #expect(try await store.load() == "abc123")

            let requests = seenRequests.all
            #expect(requests.count == 2)
            let firstJSON = try Self.bodyJSON(from: requests[0])
            let secondJSON = try Self.bodyJSON(from: requests[1])
            #expect(firstJSON["captcha_token"] as? String == "stale-token")
            #expect(secondJSON["captcha_token"] as? String == "fresh-token")
        }
    }
}

/// Mirrors `SessionControllerTests.LockedRequestBox` — its own, separate
/// copy (see this file's header for why).
private final class LockedRequestBox: @unchecked Sendable {
    private let lock = NSLock()
    private var value: URLRequest?

    func set(_ newValue: URLRequest) {
        lock.lock()
        defer { lock.unlock() }
        value = newValue
    }

    var current: URLRequest? {
        lock.lock()
        defer { lock.unlock() }
        return value
    }
}

/// A small, manually-synchronized APPEND-ONLY list of every request seen —
/// `retrySendsFreshTokenNotStaleOne` needs BOTH attempts, not just the most
/// recent (`LockedRequestBox` above only ever remembers one).
private final class LockedRequestListBox: @unchecked Sendable {
    private let lock = NSLock()
    private var values: [URLRequest] = []

    func append(_ newValue: URLRequest) {
        lock.lock()
        defer { lock.unlock() }
        values.append(newValue)
    }

    var all: [URLRequest] {
        lock.lock()
        defer { lock.unlock() }
        return values
    }
}

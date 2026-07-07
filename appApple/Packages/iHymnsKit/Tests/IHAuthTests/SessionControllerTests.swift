// SessionControllerTests.swift
// IHAuthTests
//
// ELI5: Signs a fake user in (both "here's an already-obtained token" and
// "here's a username/password — go get one"), checks the state flips to
// "signed in," signs them out, checks the server gets told to revoke the
// token BEFORE the local copy is deleted — using an in-memory fake token
// store + a mocked network transport, so no real Keychain or network is
// ever touched (per this task's "do NOT hit the network by default" rule).
//
// #1398 UPDATE — `SessionController` now composes a real `APIClient` (to
// call `auth_login`/`auth_logout`), so every test below builds one wired to
// `IHAPITestSupport.MockURLProtocol` rather than the bare in-memory-only
// setup Phase 0 used. Every test body — even the ones that never touch the
// network themselves — runs inside `MockTransportLock.shared.withLock` so
// this suite can never race a DIFFERENT suite (e.g. `IHAPITests`'
// `NetworkedAPIClientTests`) that also drives the same shared
// `MockURLProtocol.requestHandler`; see that lock's own header for why
// `.serialized` alone isn't enough once more than one suite shares it.
//
// Native login/account UI task UPDATE — `restoreFromStorage()` now validates
// a stored token via `auth_me` instead of trusting it blindly (see that
// method's own doc comment for the full success/`.unauthorized`/transient
// -failure decision tree); the tests below cover all three outcomes, plus
// the new `signIn(email:code:)` entry point. `makeController()`'s
// `APIClient` now passes a tiny `retryBaseDelaySeconds` — `.offline`/
// `.maintenance` are BOTH retryable (`APIClient.isRetryable(_:)`), and a
// couple of the new tests deliberately exercise those paths through
// `authMe()`'s retrying `performIdempotentGET`; the default half-second
// base delay would have made this whole `.serialized` suite measurably
// slower for zero additional coverage (the backoff-TIMING behaviour itself
// is already covered by `IHAPITests/NetworkedAPIClientTests`).
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth

@Suite("SessionController", .serialized)
struct SessionControllerTests {

    /// Builds a definitely-valid `HTTPURLResponse` for a fixed dev URL —
    /// same convention `NetworkedAPIClientTests` uses for values the test
    /// itself controls (never untrusted input).
    private static func response(status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: URL(string: "https://dev.ihymns.app/api")!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    /// A fresh `SessionController` + the in-memory store backing it, wired
    /// to a `MockURLProtocol`-backed `APIClient` — no real Keychain, no real
    /// network, ever.
    private func makeController() -> (controller: SessionController, store: InMemoryTokenStore) {
        let apiClient = APIClient(
            environment: .dev,
            session: MockURLProtocol.makeSession(),
            retryBaseDelaySeconds: 0.001
        )
        let store = InMemoryTokenStore()
        return (SessionController(tokenStore: store, apiClient: apiClient), store)
    }

    @Test("Starts signed out when no token is stored")
    func startsSignedOut() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, _) = makeController()
            try await controller.restoreFromStorage()
            #expect(await controller.state == .signedOut)
        }
    }

    @Test("Restores signedIn state from an already-stored token")
    func restoresExistingToken() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            try await store.save("existing-token")

            try await controller.restoreFromStorage()

            #expect(await controller.state == .signedIn(token: "existing-token"))
        }
    }

    @Test("signIn(token:) persists the token and flips state to signedIn")
    func signInWithTokenPersistsToken() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()

            try await controller.signIn(token: "fresh-token")

            #expect(await controller.state == .signedIn(token: "fresh-token"))
            #expect(try await store.load() == "fresh-token")
        }
    }

    @Test("signIn(username:password:) calls auth_login and adopts the returned token")
    func signInWithCredentialsCallsAuthLogin() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            let responseBody = Data("""
            {"token": "abc123def456", "user": {"id": 1, "username": "jane", "display_name": "Jane Doe", "role": "user"}}
            """.utf8)
            MockURLProtocol.requestHandler = { _ in
                (Self.response(status: 200), responseBody)
            }
            defer { MockURLProtocol.requestHandler = nil }

            try await controller.signIn(username: "jane", password: "hunter2")

            #expect(await controller.state == .signedIn(token: "abc123def456"))
            #expect(try await store.load() == "abc123def456")
        }
    }

    @Test("signIn(username:password:) sends the request as a POST with a JSON body")
    func signInWithCredentialsSendsExpectedRequestShape() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, _) = makeController()
            let seenRequest = LockedRequestBox()
            let responseBody = Data("""
            {"token": "abc123def456", "user": {"id": 1, "username": "jane", "display_name": "Jane Doe", "role": "user"}}
            """.utf8)
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(status: 200), responseBody)
            }
            defer { MockURLProtocol.requestHandler = nil }

            try await controller.signIn(username: "jane", password: "hunter2")

            #expect(seenRequest.current?.url?.query?.contains("action=auth_login") == true)
            #expect(seenRequest.current?.httpMethod == "POST")
            #expect(seenRequest.current?.value(forHTTPHeaderField: "Content-Type") == "application/json")
        }
    }

    @Test("signIn(username:password:) surfaces .unauthorized on bad credentials without persisting anything")
    func signInWithCredentialsFailure() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let (controller, store) = makeController()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 401), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            await #expect(throws: APIError.unauthorized) {
                try await controller.signIn(username: "jane", password: "wrong")
            }
            #expect(await controller.state == .signedOut)
            #expect(try await store.load() == nil)
        }
    }

    @Test("signOut calls auth_logout (as an authenticated POST), then clears the local token")
    func signOutRevokesThenDeletes() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            try await controller.signIn(token: "fresh-token")

            let seenRequest = LockedRequestBox()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(status: 200), Data())
            }
            defer { MockURLProtocol.requestHandler = nil }

            try await controller.signOut()

            #expect(seenRequest.current?.url?.query?.contains("action=auth_logout") == true)
            #expect(seenRequest.current?.value(forHTTPHeaderField: "Authorization") == "Bearer fresh-token")
            #expect(await controller.state == .signedOut)
            #expect(try await store.load() == nil)
        }
    }

    @Test("signOut treats an already-revoked (401) token as done and still clears locally")
    func signOutTreatsUnauthorizedAsAlreadyRevoked() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            try await controller.signIn(token: "fresh-token")
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 401), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            try await controller.signOut()

            #expect(await controller.state == .signedOut)
            #expect(try await store.load() == nil)
        }
    }

    @Test("signOut does NOT delete the local token when the server revoke genuinely fails")
    func signOutDoesNotDeleteWhenRevokeFails() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            try await controller.signIn(token: "fresh-token")
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 503), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            await #expect(throws: APIError.self) {
                try await controller.signOut()
            }

            // The whole point of "revoke first, delete second" (strategy
            // §3.2): a genuine revoke failure must leave the local copy —
            // and the signed-in state — untouched, never silently sign out
            // locally while the token is still valid server-side.
            #expect(await controller.state == .signedIn(token: "fresh-token"))
            #expect(try await store.load() == "fresh-token")
        }
    }

    @Test("signOut on an already-signed-out controller is a no-op and never touches the network")
    func signOutWhenAlreadySignedOutIsANoOp() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, _) = makeController()
            // `requestHandler` deliberately left `nil` — if `signOut()`
            // incorrectly attempted a network call here, `MockURLProtocol`
            // would fail it with `.unknown`, surfacing as a thrown
            // `.offline` and failing this test.
            try await controller.signOut()
            #expect(await controller.state == .signedOut)
        }
    }

    @Test("stateUpdates publishes every transition in order")
    func stateUpdatesPublishesTransitions() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, _) = makeController()
            var iterator = controller.stateUpdates.makeAsyncIterator()

            try await controller.signIn(token: "a")
            let first = await iterator.next()
            #expect(first == .signedIn(token: "a"))

            MockURLProtocol.requestHandler = { _ in (Self.response(status: 200), Data()) }
            defer { MockURLProtocol.requestHandler = nil }
            try await controller.signOut()
            let second = await iterator.next()
            #expect(second == .signedOut)
        }
    }

    // MARK: - restoreFromStorage validation (native login/account UI task)

    @Test("restoreFromStorage validates a stored token via auth_me and stays signed in on success")
    func restoreValidatesTokenAndStaysSignedIn() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            try await store.save("good-token")
            let seenRequest = LockedRequestBox()
            let responseBody = Data("""
            {"user": {"id": 1, "username": "jane", "display_name": "Jane Doe", "role": "user"}}
            """.utf8)
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(status: 200), responseBody)
            }
            defer { MockURLProtocol.requestHandler = nil }

            try await controller.restoreFromStorage()

            #expect(await controller.state == .signedIn(token: "good-token"))
            #expect(seenRequest.current?.url?.query?.contains("action=auth_me") == true)
            #expect(seenRequest.current?.value(forHTTPHeaderField: "Authorization") == "Bearer good-token")
        }
    }

    @Test("restoreFromStorage self-heals: a token auth_me rejects as unauthorized is cleared, state stays signedOut")
    func restoreClearsARevokedToken() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            try await store.save("dead-token")
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 401), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            try await controller.restoreFromStorage()

            #expect(await controller.state == .signedOut)
            #expect(try await store.load() == nil)
        }
    }

    @Test("restoreFromStorage fails OPEN on a transient auth_me failure — still signs in")
    func restoreFailsOpenOnTransientValidationFailure() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            try await store.save("good-token")
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 503), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            try await controller.restoreFromStorage()

            // A maintenance window / connectivity blip at the exact moment
            // of a launch-time validation says NOTHING about whether the
            // token itself is good — this codebase's "never punitive on a
            // transient failure" posture means the user stays signed in
            // locally, matching `restoreFromStorage()`'s own doc comment.
            #expect(await controller.state == .signedIn(token: "good-token"))
            #expect(try await store.load() == "good-token")
        }
    }

    // MARK: - Email-code sign-in (native login/account UI task)

    @Test("signIn(email:code:) calls auth_email_login_verify and adopts the returned token")
    func signInWithEmailCodeCallsVerify() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            let seenRequest = LockedRequestBox()
            let responseBody = Data("""
            {"token": "email-token-1", "user": {"id": 2, "username": "sam", "display_name": "Sam Doe", "role": "user"}}
            """.utf8)
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(status: 200), responseBody)
            }
            defer { MockURLProtocol.requestHandler = nil }

            try await controller.signIn(email: "sam@example.com", code: "123456")

            #expect(await controller.state == .signedIn(token: "email-token-1"))
            #expect(try await store.load() == "email-token-1")
            #expect(seenRequest.current?.url?.query?.contains("action=auth_email_login_verify") == true)
            #expect(seenRequest.current?.httpMethod == "POST")
        }
    }

    @Test("signIn(email:code:) surfaces .unauthorized on an invalid code, without persisting anything")
    func signInWithEmailCodeFailure() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            let (controller, store) = makeController()
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 401), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            await #expect(throws: APIError.unauthorized) {
                try await controller.signIn(email: "sam@example.com", code: "000000")
            }
            #expect(await controller.state == .signedOut)
            #expect(try await store.load() == nil)
        }
    }

    // MARK: - SessionState.isSignedIn convenience

    @Test("SessionState.isSignedIn reflects each case")
    func isSignedInConvenience() {
        #expect(SessionState.signedOut.isSignedIn == false)
        #expect(SessionState.signedIn(token: "x").isSignedIn == true)
    }
}

/// A small, manually-synchronized single-slot box — captures the most
/// recent `URLRequest` a mock handler observed, for assertions made safely
/// back on the test's own task after the `await` completes (mirrors
/// `NetworkedAPIClientTests.LockedBox`; kept as its own tiny type here
/// rather than sharing that one across test TARGETS, since a `.testTarget`
/// cannot depend on another `.testTarget`'s sources in SwiftPM).
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

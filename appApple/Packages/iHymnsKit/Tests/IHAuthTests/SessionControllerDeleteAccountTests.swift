// SessionControllerDeleteAccountTests.swift
// IHAuthTests
//
// ELI5: Signs a fake user in, then proves `deleteAccount(reauth:)` (#1477,
// App Review §5.1.1(v)) does the right thing: on success it clears the
// local token and flips to signed-out — exactly like `signOut()` — but on
// EVERY failure (wrong password/code, rate-limited, a transient server
// error, or even calling it while already signed out) it leaves local state
// completely untouched, since none of those mean the account was actually
// deleted.
//
// DETAILED: Split out of `SessionControllerTests.swift` (rather than growing
// that file further, which was already at the repo's LOC-budget tripwire,
// `Scripts/loc-budget.sh`) — a same-target file split, mirroring how
// `APIClient+Auth.swift` was split out of `APIClient.swift` for the
// identical reason. Deliberately re-declares its own `makeController()`/
// `response(status:)`/`LockedRequestBox` rather than sharing
// `SessionControllerTests.swift`'s private copies — `private` is file
// -scoped in Swift, so a same-MODULE, different-file test suite needs its
// own copies regardless (the same reasoning `FavoritesAndAuthAPITests
// .swift`'s `LockedBox` and `SessionControllerTests.swift`'s
// `LockedRequestBox` already document for each other).
//
// Every test body runs inside `MockTransportLock.shared.withLock` — see
// `SessionControllerTests.swift`'s own header for why that's needed even for
// suites that only touch this ONE `MockURLProtocol.requestHandler` slot.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth

@Suite("SessionController deleteAccount (#1477)", .serialized)
struct SessionControllerDeleteAccountTests {

    private static func response(status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: URL(string: "https://dev.ihymns.app/api")!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    /// A fresh `SessionController` + the in-memory store backing it, wired
    /// to a `MockURLProtocol`-backed `APIClient` — no real Keychain, no real
    /// network, ever. Identical shape to `SessionControllerTests
    /// .makeController()`.
    private func makeController() -> (controller: SessionController, store: InMemoryTokenStore) {
        let apiClient = APIClient(
            environment: .dev,
            session: MockURLProtocol.makeSession(),
            retryBaseDelaySeconds: 0.001
        )
        let store = InMemoryTokenStore()
        return (SessionController(tokenStore: store, apiClient: apiClient), store)
    }

    @Test("deleteAccount(reauth:) calls account_delete (as an authenticated POST), then clears the local token")
    func deleteAccountSucceedsThenClearsLocally() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            try await controller.signIn(token: "fresh-token")

            let seenRequest = LockedRequestBox()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(status: 200), Data(#"{ "ok": true, "deleted": true }"#.utf8))
            }
            defer { MockURLProtocol.requestHandler = nil }

            try await controller.deleteAccount(reauth: .password("hunter2"))

            #expect(seenRequest.current?.url?.query?.contains("action=account_delete") == true)
            #expect(seenRequest.current?.httpMethod == "POST")
            #expect(seenRequest.current?.value(forHTTPHeaderField: "Authorization") == "Bearer fresh-token")
            #expect(await controller.state == .signedOut)
            #expect(try await store.load() == nil)
        }
    }

    @Test("deleteAccount(reauth:) surfaces .unauthorized on a wrong re-auth credential and does NOT clear local state")
    func deleteAccountWrongCredentialLeavesSessionIntact() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            try await controller.signIn(token: "fresh-token")
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 401), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            await #expect(throws: APIError.unauthorized) {
                try await controller.deleteAccount(reauth: .password("wrong"))
            }

            // UNLIKE `signOut()`'s `.unauthorized` handling, a bad RE-AUTH
            // credential must NEVER be treated as "already done" — the
            // account still exists and the token is still perfectly valid,
            // so local state must stay exactly as it was, letting the
            // caller re-present the re-auth step.
            #expect(await controller.state == .signedIn(token: "fresh-token"))
            #expect(try await store.load() == "fresh-token")
        }
    }

    @Test("deleteAccount(reauth:) does NOT clear local state when the server genuinely fails (e.g. 503)")
    func deleteAccountDoesNotClearOnTransientFailure() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, store) = makeController()
            try await controller.signIn(token: "fresh-token")
            MockURLProtocol.requestHandler = { _ in (Self.response(status: 503), Data()) }
            defer { MockURLProtocol.requestHandler = nil }

            await #expect(throws: APIError.self) {
                try await controller.deleteAccount(reauth: .emailCode("482917"))
            }

            #expect(await controller.state == .signedIn(token: "fresh-token"))
            #expect(try await store.load() == "fresh-token")
        }
    }

    @Test("deleteAccount(reauth:) throws .unauthorized (and never touches the network) when already signed out")
    func deleteAccountWhenAlreadySignedOutThrows() async throws {
        try await MockTransportLock.shared.withLock {
            let (controller, _) = makeController()
            // `requestHandler` deliberately left `nil` — if this incorrectly
            // attempted a network call, `MockURLProtocol` would fail it with
            // `.unknown` (surfacing as `.offline`), not `.unauthorized`,
            // failing this test — mirrors `SessionControllerTests
            // .signOutWhenAlreadySignedOutIsANoOp`'s own "never touches the
            // network" assertion.
            await #expect(throws: APIError.unauthorized) {
                try await controller.deleteAccount(reauth: .password("anything"))
            }
            #expect(await controller.state == .signedOut)
        }
    }
}

/// A small, manually-synchronized single-slot box — captures the most
/// recent `URLRequest` a mock handler observed. `private` is file-scoped in
/// Swift, so this mirrors `SessionControllerTests.swift`'s identical copy
/// rather than sharing it (see this file's own header).
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

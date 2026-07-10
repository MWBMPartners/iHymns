// AccountDeleteAPITests.swift
// IHAPITests
//
// ELI5: Checks `?action=account_delete` (in-app account deletion, #1477,
// App Review §5.1.1(v)) builds the right request — a `{password}` OR
// `{email_code}` POST body, bearer-authed — and that the client correctly
// turns each response status into the right `APIError`: 401 for a wrong or
// expired re-auth credential, 429 for too many attempts, and 409 when the
// account is the last remaining Global Admin.
//
// DETAILED: Split out of `FavoritesAndAuthAPITests.swift` (rather than
// growing that file past the repo's LOC-budget tripwire,
// `Scripts/loc-budget.sh`) — a same-target file split, mirroring how
// `APIClient+Auth.swift` split out of `APIClient.swift` for the identical
// reason. Verified against the LIVE `appWeb/public_html/api.php` handler
// (`case 'account_delete'`, read 2026-07-10) — see `AuthEndpoints.swift`'s
// own header for the exact re-auth body shapes it accepts.
// `account_delete`'s success response (`{"ok": true, "deleted": true}`) is
// never decoded by the client — like `authLogout()`, a 2xx status alone is
// the signal — so there is no dedicated decode test for it, only the
// request-shape + status-mapping tests below.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHModels

@Suite("Endpoint.accountDelete(reauth:) request building (#1477)")
struct AccountDeleteEndpointTests {

    @Test("Endpoint.accountDelete(reauth: .password) POSTs a {password} body, requires auth")
    func buildsAccountDeletePasswordEndpoint() throws {
        let endpoint = try Endpoint.accountDelete(reauth: .password("hunter2"))
        #expect(endpoint.action == "account_delete")
        #expect(endpoint.requiresAuth == true)
        #expect(endpoint.httpMethod == "POST")

        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["password"] as? String == "hunter2")
        // Swift's synthesized `Encodable` uses `encodeIfPresent` for
        // `Optional` stored properties — a `nil` field is OMITTED from the
        // JSON entirely, never encoded as a literal `null`.
        #expect(json["email_code"] == nil)
    }

    @Test("Endpoint.accountDelete(reauth: .emailCode) POSTs an {email_code} body, requires auth")
    func buildsAccountDeleteEmailCodeEndpoint() throws {
        let endpoint = try Endpoint.accountDelete(reauth: .emailCode("482917"))
        #expect(endpoint.action == "account_delete")
        #expect(endpoint.requiresAuth == true)
        #expect(endpoint.httpMethod == "POST")

        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["email_code"] as? String == "482917")
        #expect(json["password"] == nil)
    }
}

@Suite("accountDelete(reauth:) networked calls (mocked transport, #1477)", .serialized)
struct AccountDeleteNetworkedTests {

    private static func response(for request: URLRequest, status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    @Test("accountDelete(reauth: .password) sends the Bearer token and a {password} POST body")
    func accountDeletePasswordEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), Data(#"{ "ok": true, "deleted": true }"#.utf8))
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "test-token")
            try await client.accountDelete(reauth: .password("hunter2"))

            #expect(seenRequest.current?.url?.query?.contains("action=account_delete") == true)
            #expect(seenRequest.current?.httpMethod == "POST")
            #expect(seenRequest.current?.value(forHTTPHeaderField: "Authorization") == "Bearer test-token")
        }
    }

    @Test("accountDelete(reauth:) throws .unauthorized on a 401 (wrong/expired re-auth credential), without retrying")
    func accountDeleteUnauthorized() async throws {
        try await MockTransportLock.shared.withLock {
            let callCount = LockedCounter()
            MockURLProtocol.requestHandler = { request in
                callCount.increment()
                return (Self.response(for: request, status: 401), Data())
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "test-token")
            await #expect(throws: APIError.unauthorized) {
                try await client.accountDelete(reauth: .password("wrong"))
            }
            // A POST goes through the non-retrying `performOnce` — exactly
            // one attempt, mirroring `FavoritesAndAuthAPITests
            // .authEmailLoginVerifyRateLimited`'s identical reasoning.
            #expect(callCount.current == 1)
        }
    }

    @Test("accountDelete(reauth:) surfaces .rateLimited on a 429 (too many attempts)")
    func accountDeleteRateLimited() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { request in
                (Self.response(for: request, status: 429), Data())
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "test-token")
            await #expect(throws: APIError.rateLimited(retryAfterSeconds: nil)) {
                try await client.accountDelete(reauth: .emailCode("000000"))
            }
        }
    }

    @Test("accountDelete(reauth:) surfaces a .server(status: 409) when this account is the last Global Admin")
    func accountDeleteLastGlobalAdminConflict() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { request in
                (Self.response(for: request, status: 409), Data())
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "test-token")
            await #expect(throws: APIError.server(status: 409, message: nil)) {
                try await client.accountDelete(reauth: .password("correct-horse"))
            }
        }
    }
}

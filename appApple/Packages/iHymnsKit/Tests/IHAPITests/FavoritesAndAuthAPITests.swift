// FavoritesAndAuthAPITests.swift
// IHAPITests
//
// ELI5: Checks the favourites calls (`favorites`/`favorites_sync`/
// `favorites_remove`) and the new auth calls (`auth_me`/
// `auth_email_login_request`/`auth_email_login_verify`) build the right
// requests and decode the right responses — both in isolation (pure request
// building / pure decoding, `Testing` conventions `APIClientTests.swift`
// already establishes) and end-to-end through the mocked network
// (`NetworkedAPIClientTests.swift`'s conventions).
//
// DETAILED: Native login/account UI + favourites task. Every response shape
// asserted here is verified against the LIVE `appWeb/public_html/api.php`
// handlers (`case 'favorites'`/`'favorites_sync'`/`'favorites_remove'`/
// `'auth_me'`/`'auth_email_login_request'`/`'auth_email_login_verify'`,
// read 2026-07-07) — see `FavoritesEndpoints.swift`/`AuthEndpoints.swift`'s
// own header comments for the exact `curl`-equivalent cross-checks. No live
// test account was available to curl an AUTHENTICATED response directly
// (`favorites`/`favorites_sync`/`favorites_remove`/`auth_me` all require a
// bearer token), so these fixtures are modelled from the PHP source's
// `sendJson([...])` calls rather than a real HTTP capture — flagged here
// explicitly per this task's "note assumptions" instruction.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHModels

@Suite("Favorites + Auth Endpoint request building")
struct FavoritesAndAuthEndpointTests {

    @Test("Endpoint.favorites is a GET that requires auth")
    func buildsFavoritesEndpoint() {
        let endpoint = Endpoint.favorites
        #expect(endpoint.action == "favorites")
        #expect(endpoint.requiresAuth == true)
        #expect(endpoint.httpMethod == "GET")
    }

    @Test("Endpoint.favoritesSync(mode:favorites:) POSTs a {mode, favorites: [{id, tags}]} body")
    func buildsFavoritesSyncEndpoint() throws {
        let songId = try #require(SongID(rawValue: "MP-1008"))
        let endpoint = try Endpoint.favoritesSync(mode: .merge, favorites: [FavoriteEntry(songId: songId, tags: ["Easter"])])

        #expect(endpoint.action == "favorites_sync")
        #expect(endpoint.requiresAuth == true)
        #expect(endpoint.httpMethod == "POST")

        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["mode"] as? String == "merge")
        let favorites = try #require(json["favorites"] as? [[String: Any]])
        #expect(favorites.count == 1)
        #expect(favorites[0]["id"] as? String == "MP-1008")
        #expect(favorites[0]["tags"] as? [String] == ["Easter"])
    }

    @Test("Endpoint.favoritesRemove(songId:) POSTs a {song_id} body")
    func buildsFavoritesRemoveEndpoint() throws {
        let songId = try #require(SongID(rawValue: "CP-0202"))
        let endpoint = try Endpoint.favoritesRemove(songId: songId)

        #expect(endpoint.action == "favorites_remove")
        #expect(endpoint.requiresAuth == true)
        #expect(endpoint.httpMethod == "POST")

        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["song_id"] as? String == "CP-0202")
    }

    @Test("Endpoint.authMe is a GET that requires auth")
    func buildsAuthMeEndpoint() {
        #expect(Endpoint.authMe.action == "auth_me")
        #expect(Endpoint.authMe.requiresAuth == true)
        #expect(Endpoint.authMe.httpMethod == "GET")
    }

    @Test("Endpoint.authEmailLoginRequest POSTs a bare {email} body, no auth")
    func buildsAuthEmailLoginRequestEndpoint() throws {
        let endpoint = try Endpoint.authEmailLoginRequest(email: "user@example.com")
        #expect(endpoint.action == "auth_email_login_request")
        #expect(endpoint.requiresAuth == false)

        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["email"] as? String == "user@example.com")
    }

    @Test("Endpoint.authEmailLoginVerify(email:code:) sends ONLY email+code, no token key")
    func buildsAuthEmailLoginVerifyCodeModeEndpoint() throws {
        let endpoint = try Endpoint.authEmailLoginVerify(email: "user@example.com", code: "482917")
        #expect(endpoint.action == "auth_email_login_verify")
        #expect(endpoint.requiresAuth == false)

        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["email"] as? String == "user@example.com")
        #expect(json["code"] as? String == "482917")
        // Swift's synthesized `Encodable` uses `encodeIfPresent` for
        // `Optional` stored properties — a `nil` field is OMITTED from the
        // JSON entirely, never encoded as a literal `null`.
        #expect(json["token"] == nil)
    }

    @Test("Endpoint.authEmailLoginVerify(token:) — modelled for a future deep-link handler, sends ONLY token")
    func buildsAuthEmailLoginVerifyTokenModeEndpoint() throws {
        // Not wired to any UI yet (see `AuthEndpoints.swift`'s doc comment)
        // — this test proves the factory itself is real and correct, ready
        // for whichever future call site consumes a Universal Link.
        let endpoint = try Endpoint.authEmailLoginVerify(token: "a1b2c3d4e5f6")
        #expect(endpoint.action == "auth_email_login_verify")

        let body = try #require(endpoint.httpBody)
        let json = try #require(JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["token"] as? String == "a1b2c3d4e5f6")
        #expect(json["email"] == nil)
        #expect(json["code"] == nil)
    }
}

@Suite("Favorites + Auth decoding")
struct FavoritesAndAuthDecodingTests {

    @Test("decodeFavorites decodes the real {favorites: [{id, tags}]} envelope")
    func decodesFavorites() throws {
        let json = Data("""
        { "favorites": [
            { "id": "MP-1008", "tags": ["Easter", "Communion"] },
            { "id": "CP-0202", "tags": [] }
        ] }
        """.utf8)

        let favorites = try APIClient.decodeFavorites(from: json)
        #expect(favorites.count == 2)
        #expect(favorites[0].songId.rawValue == "MP-1008")
        #expect(favorites[0].tags == ["Easter", "Communion"])
        #expect(favorites[1].tags == [])
    }

    @Test("decodeFavorites defaults a missing tags key to []")
    func decodesFavoritesMissingTags() throws {
        let json = Data(#"{ "favorites": [ { "id": "MP-1008" } ] }"#.utf8)
        let favorites = try APIClient.decodeFavorites(from: json)
        #expect(favorites.count == 1)
        #expect(favorites[0].tags == [])
    }

    @Test("decodeAuthUser decodes the real {user: {...}} envelope from auth_me")
    func decodesAuthUser() throws {
        let json = Data("""
        { "user": { "id": 1, "username": "jane", "display_name": "Jane Doe", "role": "user", "avatar_service": "gravatar" } }
        """.utf8)
        let user = try APIClient.decodeAuthUser(from: json)
        #expect(user.username == "jane")
        #expect(user.avatarService == "gravatar")
    }

    @Test("decodeEmailLoginRequestMessage decodes {ok, message}")
    func decodesEmailLoginRequestMessage() throws {
        let json = Data("""
        { "ok": true, "message": "A login code has been sent to your email address. It expires in 10 minutes." }
        """.utf8)
        let message = try APIClient.decodeEmailLoginRequestMessage(from: json)
        #expect(message.contains("login code"))
    }

    @Test("A auth_email_login_verify response (extra `email`, no `avatar_service`) still decodes as AuthSession")
    func decodesAuthSessionFromEmailVerifyShape() throws {
        // Mirrors `_completeEmailLoginTxn`'s literal return shape
        // (`manage/includes/auth.php`) — `email` is a field `auth_login`'s
        // response never carries, and `avatar_service` is ABSENT (not even
        // `null`) — both must decode fine against the SAME `AuthSession`
        // type `auth_login` uses.
        let json = Data("""
        { "token": "abc123", "user": { "id": 7, "username": "user_ab12cd", "display_name": "User_ab12cd", "email": "user@example.com", "role": "user" } }
        """.utf8)
        let session = try APIClient.decodeAuthSession(from: json)
        #expect(session.token == "abc123")
        #expect(session.user.username == "user_ab12cd")
        #expect(session.user.avatarService == nil)
    }
}

@Suite("Favorites + Auth networked calls (mocked transport)", .serialized)
struct FavoritesAndAuthNetworkedTests {

    private static func response(for request: URLRequest, status: Int) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: nil)!
    }

    @Test("favorites() sends the Bearer token and decodes the response")
    func favoritesEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let json = Data(#"{ "favorites": [ { "id": "MP-1008", "tags": [] } ] }"#.utf8)
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), json)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "test-token")
            let favorites = try await client.favorites()

            #expect(seenRequest.current?.url?.query?.contains("action=favorites") == true)
            #expect(seenRequest.current?.value(forHTTPHeaderField: "Authorization") == "Bearer test-token")
            #expect(favorites.count == 1)
        }
    }

    @Test("favoritesSync(mode:favorites:) POSTs and decodes the resulting authoritative list")
    func favoritesSyncEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let json = Data(#"{ "favorites": [ { "id": "MP-1008", "tags": [] }, { "id": "CP-0202", "tags": ["Easter"] } ] }"#.utf8)
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), json)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "test-token")
            let songId = try #require(SongID(rawValue: "MP-1008"))
            let favorites = try await client.favoritesSync(mode: .merge, favorites: [FavoriteEntry(songId: songId)])

            #expect(seenRequest.current?.httpMethod == "POST")
            #expect(favorites.count == 2)
        }
    }

    @Test("favoritesRemove(songId:) POSTs the expected body and completes without throwing on 200")
    func favoritesRemoveEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), Data(#"{ "ok": true }"#.utf8))
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "test-token")
            let songId = try #require(SongID(rawValue: "MP-1008"))
            try await client.favoritesRemove(songId: songId)

            #expect(seenRequest.current?.url?.query?.contains("action=favorites_remove") == true)
        }
    }

    @Test("authMe() sends the Bearer token and decodes the profile")
    func authMeEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let json = Data(#"{ "user": { "id": 1, "username": "jane", "display_name": "Jane Doe", "role": "user" } }"#.utf8)
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), json)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "test-token")
            let user = try await client.authMe()

            #expect(seenRequest.current?.value(forHTTPHeaderField: "Authorization") == "Bearer test-token")
            #expect(user.username == "jane")
        }
    }

    @Test("authMe() throws .unauthorized on a 401, without retrying")
    func authMeUnauthorized() async throws {
        try await MockTransportLock.shared.withLock {
            let callCount = LockedCounter()
            MockURLProtocol.requestHandler = { request in
                callCount.increment()
                return (Self.response(for: request, status: 401), Data())
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), bearerToken: "dead-token")
            await #expect(throws: APIError.unauthorized) {
                _ = try await client.authMe()
            }
            #expect(callCount.current == 1)
        }
    }

    @Test("authEmailLoginRequest(email:) POSTs and returns the confirmation message")
    func authEmailLoginRequestEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let json = Data(#"{ "ok": true, "message": "A login code has been sent to your email address. It expires in 10 minutes." }"#.utf8)
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), json)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let message = try await client.authEmailLoginRequest(email: "user@example.com")

            #expect(seenRequest.current?.httpMethod == "POST")
            #expect(seenRequest.current?.value(forHTTPHeaderField: "Authorization") == nil)
            #expect(message.contains("login code"))
        }
    }

    @Test("authEmailLoginVerify(email:code:) POSTs and decodes a bearer token")
    func authEmailLoginVerifyEndToEnd() async throws {
        try await MockTransportLock.shared.withLock {
            let json = Data(#"{ "token": "abc123", "user": { "id": 7, "username": "user_ab12cd", "display_name": "User_ab12cd", "email": "user@example.com", "role": "user" } }"#.utf8)
            MockURLProtocol.requestHandler = { request in
                (Self.response(for: request, status: 200), json)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let session = try await client.authEmailLoginVerify(email: "user@example.com", code: "482917")

            #expect(session.token == "abc123")
        }
    }

    @Test("authEmailLoginVerify(email:code:) surfaces .rateLimited after too many failed code attempts")
    func authEmailLoginVerifyRateLimited() async throws {
        try await MockTransportLock.shared.withLock {
            MockURLProtocol.requestHandler = { request in
                (Self.response(for: request, status: 429), Data())
            }
            defer { MockURLProtocol.requestHandler = nil }

            // `authEmailLoginVerify` is a POST, so it goes through the
            // non-retrying `performOnce` (`APIClient+Auth.swift`'s header:
            // "never auto-retry non-idempotent POSTs") — the single 429
            // response should surface immediately as `.rateLimited`, with
            // no retry delay to wait out.
            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            await #expect(throws: APIError.self) {
                _ = try await client.authEmailLoginVerify(email: "user@example.com", code: "000000")
            }
        }
    }
}

// NetworkedAPIClientTests.swift
// IHAPITests
//
// ELI5: Proves the WHOLE round trip — build the request, "send" it (to the
// fake network from `MockURLProtocol.swift`), get bytes back, decode them —
// works end-to-end for all three catalogue reads, using the SAME real
// fixture bytes `IHModelsTests/ContractTests.swift` decodes directly. Also
// proves the retry policy actually retries a 503 and actually does NOT
// retry a 401.
//
// DETAILED: `.serialized` on the `@Suite` below only guarantees THIS file's
// own tests run one at a time relative to EACH OTHER — it does NOT prevent
// a DIFFERENT suite (e.g. `IHAuthTests`' `SessionControllerTests`, which
// also drives `MockURLProtocol` for #1398) from running concurrently and
// stomping on the same shared `requestHandler` slot. Every test below
// additionally wraps its handler-setting body in
// `MockTransportLock.shared.withLock { ... }` (`IHAPITestSupport`) for that
// cross-suite exclusivity — see that file's header for why. `#expect`/
// `#require` are deliberately NOT used inside a `requestHandler` closure
// itself — `URLProtocol.startLoading()` can run on an arbitrary
// URLSession-internal queue outside the test's own structured-concurrency
// `Task`, and Swift Testing's issue-recording macros rely on a task-local
// pointing back to the current test. Every request the mock receives is
// instead captured into a `LockedBox` and asserted on AFTER `await`-ing the
// client call, back on the test's own task.
import Foundation
import Testing
import IHAPITestSupport
import IHTestFixtures
@testable import IHAPI
@testable import IHModels

/// A small, manually-synchronized single-slot box — captures the most
/// recent `URLRequest` a mock handler observed, for assertions made safely
/// back on the test's own task after the `await` completes (see this
/// file's header for why assertions can't happen inside the handler
/// itself).
final class LockedBox<Value: Sendable>: @unchecked Sendable {
    private let lock = NSLock()
    private var value: Value?

    func set(_ newValue: Value) {
        lock.lock()
        defer { lock.unlock() }
        value = newValue
    }

    var current: Value? {
        lock.lock()
        defer { lock.unlock() }
        return value
    }
}

@Suite("APIClient networked reads (mocked transport)", .serialized)
struct NetworkedAPIClientTests {

    /// Builds a definitely-valid 200 `HTTPURLResponse` for `request` — the
    /// force-unwrap mirrors this package's existing convention for
    /// constructing responses/URLs from values the test itself controls
    /// (see `APIClient.swift`'s `APIEnvironment.baseURL`), never from
    /// untrusted input.
    private static func response(for request: URLRequest, status: Int, headers: [String: String]? = nil) -> HTTPURLResponse {
        // swiftlint:disable:next force_unwrapping
        HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: headers)!
    }

    @Test("songsIndex() decodes the real fixture end-to-end through the client")
    func songsIndexDecodesThroughClient() async throws {
        try await MockTransportLock.shared.withLock {
            let fixture = try ContractFixtures.songsIndex()
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), fixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let songs = try await client.songsIndex()

            #expect(seenRequest.current?.url?.query?.contains("action=songs_index") == true)
            // 84 rows in the fixture, 10 of which have a legacy id `SongID`
            // can't parse — see `Tests/Fixtures/README.md`.
            #expect(songs.count == 74)
        }
    }

    @Test("songDetail(id:) decodes the real fixture end-to-end through the client")
    func songDetailDecodesThroughClient() async throws {
        try await MockTransportLock.shared.withLock {
            let fixture = try ContractFixtures.songDetail()
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), fixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let detail = try await client.songDetail(id: songId)

            #expect(seenRequest.current?.url?.query?.contains("action=song_detail") == true)
            #expect(seenRequest.current?.url?.query?.contains("id=MP-0031") == true)
            #expect(detail.title == "Amazing grace")
            #expect(detail.components.count == 4)
        }
    }

    @Test("songDetail(id:) requests the #180 opt-in enrichment blocks")
    func songDetailRequestsEnrichmentBlocks() async throws {
        try await MockTransportLock.shared.withLock {
            let fixture = try ContractFixtures.songDetail()
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), fixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let songId = try #require(SongID(rawValue: "MP-0031"))
            _ = try await client.songDetail(id: songId)

            // Every song-display screen fetch should ask for the three
            // enrichment blocks (#180) — proves `APIClient.songDetail(id:)`
            // isn't just a bare `?action=song_detail&id=…` request anymore.
            let query = try #require(seenRequest.current?.url?.query)
            #expect(query.contains("include=translations,annotations,royaltyIds"))
        }
    }

    @Test("songLinks(id:) decodes the real (empty) counterpart-group fixture end-to-end")
    func songLinksDecodesThroughClient() async throws {
        try await MockTransportLock.shared.withLock {
            let fixture = try ContractFixtures.songLinks()
            let seenRequest = LockedBox<URLRequest>()
            MockURLProtocol.requestHandler = { request in
                seenRequest.set(request)
                return (Self.response(for: request, status: 200), fixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let group = try await client.songLinks(id: songId)

            #expect(seenRequest.current?.url?.query?.contains("action=song_links") == true)
            #expect(group.hasCounterparts == false)
        }
    }

    @Test("relatedSongs(id:) decodes the real fixture end-to-end through the client")
    func relatedSongsDecodesThroughClient() async throws {
        try await MockTransportLock.shared.withLock {
            let fixture = try ContractFixtures.relatedSongs()
            MockURLProtocol.requestHandler = { request in
                (Self.response(for: request, status: 200), fixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let songId = try #require(SongID(rawValue: "MP-0031"))
            let related = try await client.relatedSongs(id: songId)

            #expect(related.count == 10)
            #expect(related.first?.reason == "Same writer: John Newton")
        }
    }

    @Test("songbooks() decodes the real fixture end-to-end through the client")
    func songbooksDecodesThroughClient() async throws {
        try await MockTransportLock.shared.withLock {
            let fixture = try ContractFixtures.songbooks()
            MockURLProtocol.requestHandler = { request in
                (Self.response(for: request, status: 200), fixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession())
            let books = try await client.songbooks()
            #expect(books.count == 54)
        }
    }

    @Test("A 503 with Retry-After retries, then succeeds")
    func retriesOnMaintenanceThenSucceeds() async throws {
        try await MockTransportLock.shared.withLock {
            let fixture = try ContractFixtures.songbooks()
            let callCount = LockedCounter()
            MockURLProtocol.requestHandler = { request in
                let attempt = callCount.increment()
                if attempt < 2 {
                    return (Self.response(for: request, status: 503, headers: ["Retry-After": "0"]), Data())
                }
                return (Self.response(for: request, status: 200), fixture)
            }
            defer { MockURLProtocol.requestHandler = nil }

            // Tiny base delay: the `Retry-After: 0` header makes the retry
            // near-instant anyway, but a small base keeps this test fast
            // even if the retry policy's exponential-backoff branch is
            // ever hit.
            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), retryBaseDelaySeconds: 0.001)
            let books = try await client.songbooks()
            #expect(books.count == 54)
            #expect(callCount.current == 2)
        }
    }

    @Test("A 401 is never retried — surfaces immediately as .unauthorized")
    func doesNotRetryUnauthorized() async throws {
        try await MockTransportLock.shared.withLock {
            let callCount = LockedCounter()
            MockURLProtocol.requestHandler = { request in
                callCount.increment()
                return (Self.response(for: request, status: 401), Data())
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), retryBaseDelaySeconds: 0.001)
            await #expect(throws: APIError.unauthorized) {
                _ = try await client.songbooks()
            }
            #expect(callCount.current == 1)
        }
    }

    @Test("A persistent 503 exhausts retries and still surfaces .maintenance")
    func exhaustsRetriesOnPersistentMaintenance() async throws {
        try await MockTransportLock.shared.withLock {
            let callCount = LockedCounter()
            MockURLProtocol.requestHandler = { request in
                callCount.increment()
                return (Self.response(for: request, status: 503, headers: ["Retry-After": "0"]), Data())
            }
            defer { MockURLProtocol.requestHandler = nil }

            let client = APIClient(environment: .dev, session: MockURLProtocol.makeSession(), retryBaseDelaySeconds: 0.001)
            await #expect(throws: APIError.maintenance(retryAfterSeconds: 0)) {
                _ = try await client.songbooks()
            }
            // maxAttempts is 3 — the first try plus two retries, never more.
            #expect(callCount.current == 3)
        }
    }

    // MARK: - Optional live-integration test (guarded, off by default)

    @Test(
        "LIVE: songsIndex() decodes a real dev response (opt-in only)",
        .enabled(if: ProcessInfo.processInfo.environment["IHYMNS_LIVE_API_TESTS"] == "1")
    )
    func liveSongsIndexIntegration() async throws {
        // Never runs in ordinary `swift test`/CI — only when a developer
        // explicitly sets IHYMNS_LIVE_API_TESTS=1, e.g. to sanity-check
        // this client against `dev` by hand. Real network, real assertions.
        let client = APIClient(environment: .dev)
        let songs = try await client.songsIndex()
        #expect(!songs.isEmpty)
    }
}

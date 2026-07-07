// APIClient.swift
// IHAPI
//
// ELI5: The one "phone line" the whole app uses to talk to the iHymns
// server — every other module asks THIS to fetch or send something rather
// than opening its own connection.
//
// DETAILED: `actor APIClient` is the shared network client described in
// strategy §1.5/§1.3: an `actor` (not a plain `class`) so its mutable state
// (the selected `APIEnvironment`, the injected `URLSession`, and the
// current bearer token) can never be mutated from two threads at once —
// Swift 6 strict concurrency (`SWIFT_STRICT_CONCURRENCY = complete`, set
// repo-wide in `Config/Shared.xcconfig` and mirrored in this package's
// `Package.swift`) makes that a compile-time guarantee rather than a
// "please don't race" comment. Every request adds the two headers strategy
// §1.5 calls out: `Authorization: Bearer <64-hex>` (only for endpoints that
// `requiresAuth`) and `X-Requested-With` (same-origin marker consumed by
// the web backend's `validateCsrfRequest()` — CLAUDE.md rule #29 — for any
// state-changing call this client makes; sent on every request, not just
// POSTs, which is harmless for reads and keeps this file's existing,
// already-passing request-building tests green).
//
// #1397 UPDATE — Phase 0 stopped short of a working network round trip
// ("no live dev-API fixtures exist yet to verify against"). That's no
// longer true: `Tests/Fixtures/` now holds real recordings (#1396), and
// this file adds the three typed reads (`songsIndex()`/`songDetail(id:)`/
// `songbooks()`) plus the retry/backoff networking core underneath them.
// `makeURLRequest`/`classify` are UNCHANGED from Phase 0 — still
// `nonisolated`, pure, and independently unit-tested with zero networking.
import Foundation
import IHModels

/// The shared network client for every iHymns API call.
///
/// ELI5: One object the rest of the app hands requests to; it knows which
/// server to talk to, how to format the request correctly, and how many
/// times to politely retry before giving up.
public actor APIClient {

    /// Which backend deployment this client instance targets.
    ///
    /// ELI5: dev, beta, or prod — chosen once when the client is created.
    private let environment: APIEnvironment

    /// The underlying transport. Injectable (rather than always
    /// `URLSession.shared`) so the test suite can substitute a mock
    /// `URLSession` (via `URLProtocol` stubbing, see `IHAPITests`) without
    /// touching real network — see Apple's guidance on testing networking
    /// code: https://developer.apple.com/documentation/foundation/urlprotocol.
    private let session: URLSession

    /// The current session token, if any. `nil` for a signed-out client —
    /// every read this file implements is public and works fine with no
    /// token; `IHAuth.SessionController` is what actually populates this
    /// once a user signs in, via `updateBearerToken(_:)` below.
    private var bearerToken: String?

    /// Base delay (seconds) for the exponential-backoff retry policy below.
    /// A real Double, not a fixed constant, purely so the test suite can
    /// shrink it to a few milliseconds — nobody wants a unit test that
    /// takes several real seconds to prove a retry loop works.
    private let retryBaseDelaySeconds: Double

    /// Maximum number of ATTEMPTS (the first try plus retries) an
    /// idempotent GET makes before surfacing its last error — strategy
    /// §1.5's "max 3".
    private let maxAttempts = 3

    /// Creates a client bound to one environment.
    ///
    /// - Parameters:
    ///   - environment: Which backend to target (strategy §1.5).
    ///   - session: The `URLSession` to use; defaults to `.shared`.
    ///   - bearerToken: An already-known session token, if any. Usually left
    ///     `nil` at construction and set later via `updateBearerToken(_:)`
    ///     once `IHAuth.SessionController` resolves one.
    ///   - retryBaseDelaySeconds: Base for the exponential backoff between
    ///     retried idempotent GETs. Defaults to a real half-second; tests
    ///     override this to keep retry tests fast.
    public init(
        environment: APIEnvironment,
        session: URLSession = .shared,
        bearerToken: String? = nil,
        retryBaseDelaySeconds: Double = 0.5
    ) {
        self.environment = environment
        self.session = session
        self.bearerToken = bearerToken
        self.retryBaseDelaySeconds = retryBaseDelaySeconds
    }

    /// Updates the bearer token this client attaches to `requiresAuth`
    /// endpoints — called by `IHAuth` on sign-in/sign-out/token refresh.
    ///
    /// ELI5: "Here's the new login pass" (or `nil` for "forget it, we're
    /// signed out").
    public func updateBearerToken(_ token: String?) {
        bearerToken = token
    }

    /// Builds the fully-formed `URLRequest` for an `Endpoint`, WITHOUT
    /// sending it.
    ///
    /// ELI5: Writes the envelope and sticks the stamps on — doesn't post it
    /// yet.
    ///
    /// DETAILED: Deliberately `nonisolated` and side-effect-free (pure
    /// function of its three inputs) so it can be unit-tested with zero
    /// actor-hopping, zero networking, and zero async — see
    /// `IHAPITests/APIClientTests.swift`. Query items are appended via
    /// `URLComponents` (never raw string concatenation) so
    /// `title`/user-content containing `&`/`?`/non-ASCII is always
    /// correctly percent-encoded — the native-client equivalent of the web
    /// side's "never string-interpolate into a query" discipline
    /// (`.claude/CLAUDE.md`'s SQL-binding rule #5, same spirit applied to
    /// URL construction instead of SQL).
    ///
    /// - Parameters:
    ///   - endpoint: What to call.
    ///   - bearerToken: The current session token, if any. Required
    ///     (non-nil) when `endpoint.requiresAuth` is `true`; callers
    ///     failing that precondition is a programmer error caught by the
    ///     `precondition` below rather than surfaced as an `APIError`
    ///     (an authenticated call attempted with no token is a bug in the
    ///     calling code, not an expected runtime condition).
    nonisolated public static func makeURLRequest(
        for endpoint: Endpoint,
        in environment: APIEnvironment,
        bearerToken: String? = nil
    ) -> URLRequest {
        precondition(
            !endpoint.requiresAuth || bearerToken != nil,
            "Endpoint '\(endpoint.action)' requires auth but no bearer token was supplied."
        )

        var components = URLComponents(url: environment.baseURL, resolvingAgainstBaseURL: false)
        components?.path = "/api"
        var queryItems = [URLQueryItem(name: "action", value: endpoint.action)]
        queryItems.append(contentsOf: endpoint.queryItems.map { URLQueryItem(name: $0.name, value: $0.value) })
        components?.queryItems = queryItems

        // `components?.url` can only be `nil` if `environment.baseURL`
        // itself were malformed, which it cannot be — `APIEnvironment.baseURL`
        // is built from developer-authored, unit-tested literals. Force
        // unwrap is therefore safe here, matching the same reasoning
        // documented on `APIEnvironment.baseURL` itself.
        // swiftlint:disable:next force_unwrapping
        var request = URLRequest(url: components!.url!)

        // Every request — authenticated or not — carries this header so
        // the backend's `validateCsrfRequest()` same-origin check
        // (CLAUDE.md rule #29) recognises it as a genuine app-originated
        // call for any state-changing action.
        request.setValue("XMLHttpRequest", forHTTPHeaderField: "X-Requested-With")

        if endpoint.requiresAuth, let bearerToken {
            request.setValue("Bearer \(bearerToken)", forHTTPHeaderField: "Authorization")
        }

        return request
    }

    /// Maps a raw `URLResponse`/status code into the `APIError` taxonomy.
    ///
    /// ELI5: Turns "the server said 503" into the ".maintenance" case the
    /// rest of the app already knows how to handle.
    ///
    /// DETAILED: `nonisolated` and pure for the same testability reason as
    /// `makeURLRequest` above. Only classifies; retry POLICY (which of
    /// these are worth retrying, and how long to wait) lives in
    /// `isRetryable(_:)`/`delaySeconds(forAttempt:retryAfterSeconds:base:)`
    /// below.
    nonisolated public static func classify(httpStatus: Int, retryAfterSeconds: Int?) -> APIError? {
        switch httpStatus {
        case 200..<300:
            return nil
        case 401:
            return .unauthorized
        case 429:
            return .rateLimited(retryAfterSeconds: retryAfterSeconds)
        case 503:
            return .maintenance(retryAfterSeconds: retryAfterSeconds)
        default:
            return .server(status: httpStatus, message: nil)
        }
    }

    // MARK: - Typed catalogue reads (#1397)

    /// `?action=songs_index` — the full slim catalogue index.
    ///
    /// ELI5: "Give me the whole song list" (the lightweight version).
    public func songsIndex() async throws -> [SongSummary] {
        let data = try await performIdempotentGET(.songsIndex)
        return try Self.decodeSongsIndex(from: data)
    }

    /// `?action=song_detail&id=…` — one full song record.
    ///
    /// ELI5: "Give me everything about this one song."
    public func songDetail(id: SongID) async throws -> SongDetail {
        let data = try await performIdempotentGET(.songDetail(id: id))
        return try Self.decodeSongDetail(from: data)
    }

    /// `?action=songbooks` — every songbook in the catalogue.
    ///
    /// ELI5: "Give me the list of hymnals."
    public func songbooks() async throws -> [Songbook] {
        let data = try await performIdempotentGET(.songbooks)
        return try Self.decodeSongbooks(from: data)
    }

    // MARK: - Networking core

    /// Sends `endpoint` as a GET, retrying transient failures with
    /// exponential backoff + jitter (honouring a server `Retry-After` when
    /// present), up to `maxAttempts` attempts total.
    ///
    /// ELI5: Try it; if the server hiccupped in a way that trying again
    /// might fix, wait a little (a little longer each time) and try again —
    /// up to twice more — otherwise give up and hand back the error.
    ///
    /// DETAILED: Only ever called for GETs (every catalogue read above is
    /// one) — strategy §1.5 is explicit that non-idempotent POSTs must
    /// NEVER be auto-retried this way (they go through
    /// `IHPersistence`'s pending-sync queue with server-side dedupe
    /// instead, a Phase-1 concern). `isRetryable(_:)` decides WHICH
    /// `APIError` cases are worth another attempt; `.unauthorized` and
    /// `.decoding` never are (retrying either can't possibly succeed —
    /// the token is bad, or the payload is wrong shape).
    private func performIdempotentGET(_ endpoint: Endpoint) async throws -> Data {
        var attempt = 1
        while true {
            do {
                return try await performOnce(endpoint)
            } catch let error as APIError {
                guard attempt < maxAttempts, Self.isRetryable(error) else { throw error }
                let delay = Self.delaySeconds(
                    forAttempt: attempt,
                    retryAfterSeconds: Self.retryAfterSeconds(from: error),
                    base: retryBaseDelaySeconds
                )
                try await Task.sleep(nanoseconds: UInt64(delay * 1_000_000_000))
                attempt += 1
            }
        }
    }

    /// Sends `endpoint` exactly once — no retry — and maps every possible
    /// failure (transport-level `URLError`, non-2xx HTTP status, or any
    /// other thrown error) into the `APIError` taxonomy, so nothing but
    /// `APIError` ever escapes this actor's networking boundary.
    private func performOnce(_ endpoint: Endpoint) async throws -> Data {
        let request = Self.makeURLRequest(
            for: endpoint,
            in: environment,
            bearerToken: endpoint.requiresAuth ? bearerToken : nil
        )

        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await session.data(for: request)
        } catch is URLError {
            // Every transport-level failure (no connectivity, DNS failure,
            // timed out, connection lost mid-flight, ...) is surfaced as
            // `.offline` — from the app's perspective these all mean "the
            // request never reached/returned from the server," which is
            // exactly `.offline`'s documented meaning. Treating them all
            // alike (rather than trying to finely distinguish "definitely
            // no network" from "probably a blip") also keeps this mapping
            // simple and `isRetryable(.offline) == true` below still gives
            // a genuine blip its couple of quick retries — a hard-offline
            // device just fails those retries near-instantly too, so the
            // added latency is negligible.
            throw APIError.offline
        } catch {
            // Anything else (extremely unlikely for `URLSession.data(for:)`,
            // which documents `URLError` as its throw type) still gets
            // folded into the taxonomy rather than escaping as a raw
            // foreign error type.
            throw APIError.server(status: -1, message: String(describing: error))
        }

        guard let http = response as? HTTPURLResponse else {
            throw APIError.server(status: -1, message: "Non-HTTP response")
        }

        if let apiError = Self.classify(httpStatus: http.statusCode, retryAfterSeconds: Self.retryAfterSeconds(from: http)) {
            throw apiError
        }
        return data
    }

    /// Parses the server's `Retry-After` response header (seconds form —
    /// the only form iHymns' backend sends) into an `Int`, if present.
    nonisolated private static func retryAfterSeconds(from response: HTTPURLResponse) -> Int? {
        guard let header = response.value(forHTTPHeaderField: "Retry-After") else { return nil }
        return Int(header)
    }

    /// Extracts the `Retry-After` hint already carried on a classified
    /// `.maintenance`/`.rateLimited` error, if any.
    nonisolated private static func retryAfterSeconds(from error: APIError) -> Int? {
        switch error {
        case .maintenance(let seconds): return seconds
        case .rateLimited(let seconds): return seconds
        default: return nil
        }
    }

    /// Whether a given failure is worth retrying at all.
    ///
    /// ELI5: "Was this the kind of problem that trying again might fix?"
    ///
    /// DETAILED: `nonisolated` + pure + `static` so it's independently unit
    /// -testable (`IHAPITests`) without spinning up the actor or any
    /// networking. `.maintenance`/`.rateLimited`/a 5xx `.server` and
    /// `.offline` (see `performOnce`'s reasoning above) are transient —
    /// worth another attempt. `.unauthorized` (the token itself is bad) and
    /// `.decoding` (the payload shape is wrong) are NOT — no amount of
    /// retrying changes either outcome, so failing fast serves the caller
    /// better than three attempts' worth of wasted latency.
    nonisolated private static func isRetryable(_ error: APIError) -> Bool {
        switch error {
        case .offline, .maintenance, .rateLimited:
            return true
        case .server(let status, _):
            return (500...599).contains(status)
        case .unauthorized, .decoding:
            return false
        }
    }

    /// Computes how long to wait before the next attempt.
    ///
    /// ELI5: "Wait this long" — usually a bit longer each time, with a
    /// small random wobble so a fleet of clients retrying together don't
    /// all hammer the server on the exact same beat (the classic
    /// "thundering herd" problem) — but if the server told us exactly how
    /// long to wait (`Retry-After`), that instruction wins outright.
    ///
    /// - Parameters:
    ///   - attempt: The attempt number that just failed (1-based).
    ///   - retryAfterSeconds: The server's own hint, if it sent one.
    ///   - base: `retryBaseDelaySeconds` — exposed as a parameter (rather
    ///     than reading the actor's stored property directly) purely so
    ///     this stays a `nonisolated static` pure function, independently
    ///     unit-testable with zero actor hops.
    nonisolated private static func delaySeconds(forAttempt attempt: Int, retryAfterSeconds: Int?, base: Double) -> Double {
        if let retryAfterSeconds, retryAfterSeconds > 0 {
            return Double(retryAfterSeconds)
        }
        let exponential = base * pow(2.0, Double(attempt - 1))
        let jitter = Double.random(in: 0...(exponential * 0.25))
        return exponential + jitter
    }
}

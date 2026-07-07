// APIClient.swift
// IHAPI
//
// ELI5: The one "phone line" the whole app uses to talk to the iHymns
// server — every other module asks THIS to fetch or send something rather
// than opening its own connection.
//
// DETAILED: `actor APIClient` is the Phase-0 skeleton of the client
// described in strategy §1.5/§1.3: an `actor` (not a plain `class`) so its
// mutable state (currently just the selected `APIEnvironment` and the
// injected `URLSession`) can never be mutated from two threads at once —
// Swift 6 strict concurrency (`SWIFT_STRICT_CONCURRENCY = complete`, set
// repo-wide in `Config/Shared.xcconfig` and mirrored in this package's
// `Package.swift`) makes that a compile-time guarantee rather than a
// "please don't race" comment. Every request adds the two headers strategy
// §1.5 calls out: `Authorization: Bearer <64-hex>` (only for endpoints that
// `requiresAuth`) and `X-Requested-With` (same-origin marker consumed by
// the web backend's `validateCsrfRequest()` — CLAUDE.md rule #29 — for any
// state-changing call this client makes).
//
// Phase 0 deliberately stops short of a working network round trip (no
// live dev-API fixtures exist yet to verify against — that lands in
// strategy §3.4's Phase-0 item "IHModels + first contract fixtures"). What
// IS real here: (1) environment selection, (2) pure, fully-testable
// `URLRequest` construction with the exact required headers, and (3) the
// `APIError` mapping a real network layer will eventually feed into. This
// demonstrates and locks in the IHAPI→IHModels dependency direction.
import Foundation

/// The shared network client for every iHymns API call.
///
/// ELI5: One object the rest of the app hands requests to; it knows which
/// server to talk to and how to format the request correctly.
public actor APIClient {

    /// Which backend deployment this client instance targets.
    ///
    /// ELI5: dev, beta, or prod — chosen once when the client is created.
    private let environment: APIEnvironment

    /// The underlying transport. Injectable (rather than always
    /// `URLSession.shared`) so a future test suite can substitute a mock
    /// `URLSession` (via `URLProtocol` stubbing) without touching real
    /// network — see Apple's guidance on testing networking code:
    /// https://developer.apple.com/documentation/foundation/urlprotocol.
    private let session: URLSession

    /// Creates a client bound to one environment.
    ///
    /// - Parameters:
    ///   - environment: Which backend to target (strategy §1.5).
    ///   - session: The `URLSession` to use; defaults to `.shared`.
    public init(environment: APIEnvironment, session: URLSession = .shared) {
        self.environment = environment
        self.session = session
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
    /// `IHAPITests/EndpointRequestBuildingTests.swift`. Query items are
    /// appended via `URLComponents` (never raw string concatenation) so
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
    /// `makeURLRequest` above. Only classifies; does NOT decide retry
    /// policy (strategy §1.5's backoff/jitter/Retry-After handling is a
    /// Phase-1 concern once a live server exists to exercise it against).
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
}

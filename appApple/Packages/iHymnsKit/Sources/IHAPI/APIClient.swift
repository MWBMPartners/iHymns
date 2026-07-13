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

    /// Which backend (dev/beta/prod) this client targets. `public
    /// nonisolated` (#184's `mediaURL(forStreamPath:)` reads it
    /// synchronously) is sound: `let` + `Sendable` (SE-0316).
    public nonisolated let environment: APIEnvironment

    /// The underlying transport. Injectable (rather than always
    /// `URLSession.shared`) so the test suite can substitute a mock
    /// `URLSession` (via `URLProtocol` stubbing, see `IHAPITests`) without
    /// touching real network — see Apple's guidance on testing networking
    /// code: https://developer.apple.com/documentation/foundation/urlprotocol.
    ///
    /// `internal` (not `private`) for the same same-target-file-split
    /// reason `performOnce`/`performIdempotentGET`'s own doc comments give
    /// below: the networking core (those two methods + the retry-policy
    /// helpers) now lives in `APIClient+Networking.swift`, split out once
    /// the #1505 `IHLog` instrumentation retrofit pushed this file back
    /// over the repo's 400-line LOC-budget tripwire
    /// (`Scripts/loc-budget.sh`) — `private` is file-scoped in Swift, so a
    /// same-MODULE extension in a different file needs at least `internal`
    /// visibility on every stored property it touches.
    let session: URLSession

    /// The current session token, if any. `nil` for a signed-out client —
    /// every read this file implements is public and works fine with no
    /// token; `IHAuth.SessionController` is what actually populates this
    /// once a user signs in, via `updateBearerToken(_:)` below.
    private(set) var bearerToken: String?

    /// The current Service Mode presence token, if a congregant session is
    /// active — `nil` otherwise. Set/cleared ONLY by `ServiceModeEngine`
    /// (`IHLive`) on join/leave/end (PR-10, #1427; spec §3.5/§4.3, D-6) via
    /// `updateServicePresenceToken(_:)` below, mirroring `bearerToken`'s own
    /// custody shape exactly. `makeURLRequest` attaches this as the
    /// `ihymns_sf_presence_token` Cookie header (never a query param — see
    /// that method's own doc comment) on every request while non-nil, which
    /// is what lets the (dormant, `content_gating_enabled='0'`) CCLI unlock
    /// on `song_detail`/`random` (`api.php:810-815`) work for a native
    /// congregant with zero backend change.
    private(set) var servicePresenceToken: String?

    /// Base delay (seconds) for the exponential-backoff retry policy in
    /// `APIClient+Networking.swift`. A real Double, not a fixed constant,
    /// purely so the test suite can shrink it to a few milliseconds —
    /// nobody wants a unit test that takes several real seconds to prove a
    /// retry loop works.
    let retryBaseDelaySeconds: Double

    /// Maximum number of ATTEMPTS (the first try plus retries) an
    /// idempotent GET makes before surfacing its last error — strategy
    /// §1.5's "max 3".
    let maxAttempts = 3

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

    /// Sets/clears the Service Mode presence token this client attaches as a
    /// Cookie header (PR-10, #1427; spec §3.5/§4.3) — called by
    /// `ServiceModeEngine` on join/leave/end. `nil` = "no active presence,"
    /// which restores byte-identical pre-PR-10 request shapes.
    ///
    /// ELI5: "Here's the venue room-key" (or `nil` for "I've left the room").
    public func updateServicePresenceToken(_ token: String?) {
        servicePresenceToken = token
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
    ///   - presenceToken: The active Service Mode presence token, if any
    ///     (PR-10, #1427; spec §4.3/D-6). DEFAULTED to `nil`, so every
    ///     pre-PR-10 call site builds a byte-identical request. When
    ///     non-nil AND shaped like a genuine 43-char base64url token
    ///     (`^[A-Za-z0-9_-]{43}$`, `api.php:14642`), attaches it as the
    ///     SAME named cookie a browser congregant sends
    ///     (`ihymns_sf_presence_token`) — never a query param, which would
    ///     persist into server access logs (strategy §3.2's "Bearer header
    ///     ... stays out of access logs" rule, applied identically to this
    ///     second secret). A corrupted/malformed persisted value is dropped
    ///     silently rather than ever reaching the wire.
    nonisolated public static func makeURLRequest(
        for endpoint: Endpoint,
        in environment: APIEnvironment,
        bearerToken: String? = nil,
        presenceToken: String? = nil
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
        request.httpMethod = endpoint.httpMethod

        // #1398: attach a pre-encoded JSON body when the endpoint has one
        // (e.g. `auth_login`'s username/password) — every #1397 catalogue
        // read leaves `httpBody` `nil`, so this is a no-op for them.
        if let body = endpoint.httpBody {
            request.httpBody = body
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        }

        // Every request — authenticated or not — carries this header so
        // the backend's `validateCsrfRequest()` same-origin check
        // (CLAUDE.md rule #29) recognises it as a genuine app-originated
        // call for any state-changing action.
        request.setValue("XMLHttpRequest", forHTTPHeaderField: "X-Requested-With")

        if endpoint.requiresAuth, let bearerToken {
            request.setValue("Bearer \(bearerToken)", forHTTPHeaderField: "Authorization")
        }

        // PR-10 (#1427) D-6: attach the presence Cookie on EVERY request
        // while a Service Mode presence is active — mirroring a browser,
        // which sends its cookie jar on every same-origin request; the
        // server ignores it everywhere except the two gating reads
        // (`song_detail`/`random`), so this is zero-drift for every other
        // endpoint. `httpShouldHandleCookies = false` ONLY on these requests
        // (Apple docs: https://developer.apple.com/documentation/foundation/urlrequest/2011591-httpshouldhandlecookies)
        // — determinism: `URLSession`'s shared `HTTPCookieStorage` must
        // never merge/override/persist this explicit header.
        if let presenceToken, Self.isValidPresenceTokenShape(presenceToken) {
            request.setValue("ihymns_sf_presence_token=\(presenceToken)", forHTTPHeaderField: "Cookie")
            request.httpShouldHandleCookies = false
        }

        return request
    }

    /// Whether `token` is shaped like a genuine 43-char base64url presence
    /// token (`api.php:14642`, `^[A-Za-z0-9_-]{43}$`) — the defensive check
    /// `makeURLRequest` runs before ever attaching a Cookie header, so a
    /// corrupted persisted value can never reach the wire.
    nonisolated private static func isValidPresenceTokenShape(_ token: String) -> Bool {
        guard token.count == 43 else { return false }
        return token.allSatisfy { $0.isASCII && ($0.isLetter || $0.isNumber || $0 == "_" || $0 == "-") }
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
        case 423:
            return .accountLocked(retryAfterSeconds: retryAfterSeconds)
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

    /// `?action=song_detail&id=…` — one full song record, always requesting
    /// the #1099 opt-in enrichment blocks a full song-display screen wants
    /// (Apple P1, #180): per-line translations/romanizations, per-line
    /// annotations, and royalty-authority ids. Harmless on a server/song
    /// that has none of these — the blocks are simply omitted from the
    /// response, and `SongDetail`'s three corresponding properties decode
    /// to `nil`, exactly as if `include=` had never been sent.
    ///
    /// ELI5: "Give me everything about this one song — including any
    /// translations, footnotes, or royalty ids it has."
    public func songDetail(id: SongID) async throws -> SongDetail {
        let data = try await performIdempotentGET(.songDetail(id: id, include: Self.songDetailIncludeBlocks))
        return try Self.decodeSongDetail(from: data)
    }

    /// The `include=` block names `songDetail(id:)` always requests — kept
    /// as one named constant (rather than an inline array literal at the
    /// call site) so every caller of this method asks for the exact same
    /// set, and so `IHFeatures`/tests can reference the same list if they
    /// ever need to reason about which blocks a song-display screen expects.
    static let songDetailIncludeBlocks = ["translations", "annotations", "royaltyIds"]

    /// `?action=songbooks` — every songbook in the catalogue.
    ///
    /// ELI5: "Give me the list of hymnals."
    public func songbooks() async throws -> [Songbook] {
        let data = try await performIdempotentGET(.songbooks)
        return try Self.decodeSongbooks(from: data)
    }

    // MARK: - Networking core
    //
    // `performIdempotentGET`/`performOnce` (the retry loop + the single-
    // attempt send/classify), the `retryAfterSeconds`/`isRetryable`/
    // `delaySeconds` retry-policy helpers, AND their #1505 `IHLog`
    // instrumentation (signposts + `.debug`/`.notice`/`.error` breadcrumbs,
    // strategy §2.2) all live in `APIClient+Networking.swift` — split out
    // of this file the same way `APIClient+Auth.swift`/
    // `APIClient+Discovery.swift` already were, once the instrumentation
    // pushed this file back over the repo's 400-line LOC-budget tripwire
    // (`Scripts/loc-budget.sh`). `session`/`bearerToken`/
    // `retryBaseDelaySeconds`/`maxAttempts` above are `internal`, not
    // `private`, for exactly this same-target-file-split reason.
}

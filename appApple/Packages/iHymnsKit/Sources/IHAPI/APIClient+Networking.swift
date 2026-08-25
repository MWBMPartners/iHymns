// APIClient+Networking.swift
// IHAPI
//
// ELI5: The actual "dial the phone and listen" part of talking to the
// server, PLUS a running commentary of what happened — split into its own
// file so `APIClient.swift` doesn't have to hold both the "what can I ask
// for" list AND the low-level plumbing.
//
// DETAILED: Split out of `APIClient.swift` (see that file's own "MARK: -
// Networking core" pointer comment) — a same-target file split, the exact
// pattern `APIClient+Auth.swift`/`APIClient+Discovery.swift` already
// established, enabled here by widening `session`/`bearerToken`/
// `retryBaseDelaySeconds`/`maxAttempts` from `private` to `internal` in the
// primary file.
//
// #1505 UPDATE (`.claude/live-observability-strategy.md` §2.2) — this file
// is ALSO where the client-side observability retrofit for the request/
// retry pipeline lives:
//   - `performOnce(_:)` wraps every single-attempt send in an
//     `OSSignposter` `"api.request"` interval (Instruments time-profiling),
//     and logs exactly one `IHLog.api` line per attempt: `.debug` on
//     success, `.error` on failure — endpoint `action`, `APIError` case
//     name, HTTP status, and duration in milliseconds, ALL `.public`
//     (strategy §4: none of those four things is ever secret). It NEVER
//     logs `request.url` — the query string is the one place a future
//     `presenceToken`/follow-token could appear once the congregant client
//     exists (strategy §0.2's own backend leak finding is exactly this
//     mistake, made server-side).
//   - `performIdempotentGET(_:)` additionally logs a `.notice` each time it
//     DECIDES to retry (before the `Task.sleep`), and a `.notice` "giving
//     up" line the one time it exhausts `maxAttempts` — a different,
//     coarser-grained signal than `performOnce`'s per-attempt `.error`
//     (that logs "this one attempt failed"; this logs "the retry policy
//     itself gave up/kept going").
// `classify(httpStatus:retryAfterSeconds:)` and `makeURLRequest` (both in
// `APIClient.swift`) stay pure/unlogged, exactly as strategy §2.2
// specifies.
import Foundation
import IHLog
import IHModels
import os

extension APIClient {
    /// Sends `endpoint` as a GET, retrying transient failures with
    /// exponential backoff + jitter (honouring a server `Retry-After` when
    /// present), up to `maxAttempts` attempts total.
    ///
    /// ELI5: Try it; if the server hiccupped in a way that trying again
    /// might fix, wait a little (a little longer each time) and try again —
    /// up to twice more — otherwise give up and hand back the error. Each
    /// time it decides to wait-and-retry (or finally gives up), it writes
    /// one line saying so.
    ///
    /// DETAILED: Only ever called for GETs (every catalogue read is one) —
    /// strategy §1.5 is explicit that non-idempotent POSTs must NEVER be
    /// auto-retried this way (they go through `IHPersistence`'s
    /// pending-sync queue with server-side dedupe instead, a Phase-1
    /// concern). Deliberately `internal` (module-visible), not `private` —
    /// `APIClient+Discovery.swift` (#180) calls this directly for its two
    /// idempotent GETs. `isRetryable(_:)` decides WHICH `APIError` cases
    /// are worth another attempt; `.unauthorized` and `.decoding` never are
    /// (retrying either can't possibly succeed — the token is bad, or the
    /// payload is wrong shape).
    func performIdempotentGET(_ endpoint: Endpoint) async throws -> Data {
        var attempt = 1
        while true {
            do {
                return try await performOnce(endpoint)
            } catch let error as APIError {
                guard attempt < maxAttempts, Self.isRetryable(error) else {
                    // Only log "giving up" when the retry BUDGET (not just
                    // this one attempt) is exhausted — a non-retryable
                    // error on the very first attempt already got its own
                    // `.error` line from `performOnce` below, and calling
                    // THAT a "give up after N" would misreport N.
                    if attempt >= maxAttempts {
                        IHLog.api.notice(
                            "api.retry giving up action=\(endpoint.action, privacy: .public) attempts=\(attempt, privacy: .public)"
                        )
                    }
                    throw error
                }
                let delay = Self.delaySeconds(
                    forAttempt: attempt,
                    retryAfterSeconds: Self.retryAfterSeconds(from: error),
                    base: retryBaseDelaySeconds
                )
                IHLog.api.notice(
                    "api.retry action=\(endpoint.action, privacy: .public) attempt=\(attempt, privacy: .public) error=\(error.caseName, privacy: .public) delayMs=\(Int((delay * 1_000).rounded()), privacy: .public)"
                )
                try await Task.sleep(nanoseconds: UInt64(delay * 1_000_000_000))
                attempt += 1
            }
        }
    }

    /// Sends `endpoint` exactly once — no retry — and maps every possible
    /// failure (transport-level `URLError`, non-2xx HTTP status, or any
    /// other thrown error) into the `APIError` taxonomy, so nothing but
    /// `APIError` ever escapes this actor's networking boundary. Wraps the
    /// attempt in an `OSSignposter` interval and writes exactly one
    /// `IHLog.api` line (`.debug` on success, `.error` on failure).
    ///
    /// DETAILED: Deliberately `internal` (module-visible), not `private` —
    /// `APIClient+Auth.swift` (#1398) calls this directly for the
    /// non-idempotent `auth_login`/`auth_logout` POSTs, which must NEVER go
    /// through the retrying `performIdempotentGET` above (strategy §1.5:
    /// "never auto-retry non-idempotent POSTs").
    ///
    /// **Privacy (strategy §4, binding):** the signpost/log messages below
    /// carry only `endpoint.action` (a fixed, developer-authored string
    /// like `"songs_index"` — never a query value), the `APIError` case
    /// NAME (not its associated values — see `APIError.caseName`'s own doc
    /// comment for why), the HTTP status, and the duration. `request.url`
    /// (which DOES include the query string) is never logged.
    func performOnce(_ endpoint: Endpoint) async throws -> Data {
        let signpostID = IHLog.signposter.makeSignpostID()
        let interval = IHLog.signposter.beginInterval(
            "api.request",
            id: signpostID,
            "\(endpoint.action, privacy: .public)"
        )
        let clock = ContinuousClock()
        let start = clock.now
        defer { IHLog.signposter.endInterval("api.request", interval) }

        do {
            let request = Self.makeURLRequest(
                for: endpoint,
                in: environment,
                bearerToken: endpoint.requiresAuth ? bearerToken : nil,
                presenceToken: servicePresenceToken
            )

            let data: Data
            let response: URLResponse
            do {
                (data, response) = try await session.data(for: request, delegate: Self.redirectCookieGuard)
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
                // foreign error type. The underlying error's own description
                // is deliberately NOT carried into `APIError.server`'s
                // `message` here for logging purposes — `caseName`/
                // `httpStatusForLogging` (what actually reaches `IHLog`
                // below) never read `message` at all, so this is unchanged
                // behaviour for every EXISTING caller that inspects the
                // thrown error directly; only the NEW log line is affected.
                throw APIError.server(status: -1, message: String(describing: error))
            }

            guard let http = response as? HTTPURLResponse else {
                throw APIError.server(status: -1, message: "Non-HTTP response")
            }

            // #947/#340 native scaffold (`.claude/captcha-native-and-outage-plan.md`
            // §2.3-3): try the body-aware CAPTCHA classifier FIRST — it
            // returns `nil` for everything except a genuine CAPTCHA-required
            // 403, in which case the generic, body-blind `classify(...)`
            // below never even runs (its own `default:` branch would have
            // folded this into an opaque `.server(status: 403, message: nil)`).
            if let apiError = Self.classifyMachineRefusal(httpStatus: http.statusCode, body: data)
                ?? Self.classify(httpStatus: http.statusCode, retryAfterSeconds: Self.retryAfterSeconds(from: http)) {
                throw apiError
            }

            let elapsedMs = Self.milliseconds(from: start, to: clock.now)
            IHLog.api.debug(
                "api.request ok action=\(endpoint.action, privacy: .public) ms=\(elapsedMs, privacy: .public)"
            )
            return Self.unwrapEnvelope(data)
        } catch let error as APIError {
            let elapsedMs = Self.milliseconds(from: start, to: clock.now)
            IHLog.api.error(
                "api.request failed action=\(endpoint.action, privacy: .public) error=\(error.caseName, privacy: .public) status=\(error.httpStatusForLogging ?? -1, privacy: .public) ms=\(elapsedMs, privacy: .public)"
            )
            throw error
        }
    }

    /// #1201/#1761 — unwrap the v2 uniform response envelope.
    ///
    /// A v2 server (this client sends `X-API-Version: 2`) answers a 2xx with
    /// `{ "ok": true, "data": <payload> }`. This returns the raw bytes of
    /// `<payload>` so every existing `*Decoding.swift` decoder keeps decoding
    /// its own type UNCHANGED from exactly the payload it always saw — the
    /// envelope is invisible above this transport boundary.
    ///
    /// It is a TOLERANT pass-through, never a new failure point: a body that is
    /// not a recognisable success envelope — a legacy/cached bare payload from
    /// a service-worker or HTTP cache written before v2, a streaming/CSV
    /// response, or anything unexpected — is returned VERBATIM. Errors are
    /// already surfaced by the HTTP-status `classify()` above (before this
    /// runs), so this only ever sees 2xx bodies; the `{ ok:false, error }`
    /// error shape is handled there by status, not here.
    ///
    /// Uses `JSONSerialization` (not `Codable`) deliberately: it must inspect
    /// an arbitrary payload of unknown shape and hand its bytes on untouched.
    nonisolated private static func unwrapEnvelope(_ data: Data) -> Data {
        guard
            let obj = try? JSONSerialization.jsonObject(with: data, options: [.fragmentsAllowed]),
            let dict = obj as? [String: Any],
            dict["ok"] as? Bool == true,
            dict.keys.contains("data"),
            let payload = dict["data"],
            let reserialised = try? JSONSerialization.data(
                withJSONObject: payload,
                options: [.fragmentsAllowed]
            )
        else {
            return data
        }
        return reserialised
    }

    /// Converts a `ContinuousClock` interval into whole milliseconds, for
    /// `.public` duration logging (strategy §4) — a plain `Int` is simpler
    /// to read in Console.app than a `Duration`'s own `description`, and
    /// keeps every duration this file logs in the same unit.
    nonisolated private static func milliseconds(from start: ContinuousClock.Instant, to end: ContinuousClock.Instant) -> Int {
        let components = start.duration(to: end).components
        let milliseconds = Double(components.seconds) * 1_000 + Double(components.attoseconds) / 1_000_000_000_000_000
        return Int(milliseconds.rounded())
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
    /// worth another attempt. `.unauthorized` (the token itself is bad),
    /// `.accountLocked` (retrying inside a ~3-attempt window can't outlast a
    /// real lockout), and `.decoding` (the payload shape is wrong) are NOT —
    /// no amount of retrying changes any of those outcomes, so failing fast
    /// serves the caller better than three attempts' worth of wasted
    /// latency. `.captchaRequired` joins that NOT-retryable group (#947/#340
    /// native scaffold): the token that was missing/invalid is exactly as
    /// missing/invalid on attempt 2 as it was on attempt 1 — retrying
    /// without a FRESH token (which only a human re-solving the challenge
    /// can produce) can never succeed, so the caller must surface this
    /// immediately rather than burn `maxAttempts` worth of latency first.
    nonisolated private static func isRetryable(_ error: APIError) -> Bool {
        switch error {
        case .offline, .maintenance, .rateLimited:
            return true
        case .server(let status, _):
            return (500...599).contains(status)
        case .unauthorized, .accountLocked, .decoding, .captchaRequired:
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

    /// PR-10 Opus-review FIX 3 (D-6 residual, spec §4.3): one stateless,
    /// reusable per-task redirect delegate, handed to every `session.data(
    /// for:delegate:)` call above. It holds no state, so a single shared
    /// instance is safe across every request with no actor-isolation
    /// concern — `internal` (not `private`), matching this file's existing
    /// same-target-visibility convention, so `IHAPITests` can unit-test
    /// its pure decision function directly.
    nonisolated static let redirectCookieGuard = RedirectCookieGuard()
}

/// Strips the Service-Mode presence `Cookie` header (`makeURLRequest`,
/// `APIClient.swift`) before `URLSession` follows an HTTP redirect to a
/// DIFFERENT host.
///
/// ELI5: if the server ever says "actually, go ask THIS OTHER website
/// instead," don't hand that other website our venue room-key.
///
/// DETAILED: `makeURLRequest` attaches `Cookie: ihymns_sf_presence_token=…`
/// to every request while a Service Mode presence is active (D-6) — but
/// that's a manually-set header, not one `URLSession`'s cookie-jar
/// machinery manages (`httpShouldHandleCookies = false` on those same
/// requests, spec §4.3), so `URLSession`'s DEFAULT redirect handling would
/// otherwise forward it VERBATIM to whatever host a 3xx response names.
/// **No live hole today** — none of the `/api` endpoints this client calls
/// (`live_follow_*`/`service_*`, `api.php`) issue a cross-host redirect —
/// but this closes the residual defensively rather than depending on that
/// staying true forever. `URLSessionTaskDelegate` conformance requires
/// `Sendable` under Swift 6 strict concurrency; `@unchecked` is sound here
/// because the type carries zero mutable (or any) stored state.
///
/// The host-comparison itself is split into a `nonisolated static` PURE
/// function (`shouldStripCookie(originalHost:redirectHost:)`) precisely so
/// it can be unit-tested with plain strings — constructing a real
/// `URLSessionTask` with a specific `originalRequest` outside of an actual
/// `URLSession` round trip isn't practical, so the delegate method itself
/// stays a thin, obviously-correct wrapper around the tested logic (the
/// same "pure core, thin glue" discipline `LiveFollowerReducer`/
/// `LiveSyncConfiguration` follow elsewhere in this PR).
final class RedirectCookieGuard: NSObject, URLSessionTaskDelegate, @unchecked Sendable {
    func urlSession(
        _ session: URLSession,
        task: URLSessionTask,
        willPerformHTTPRedirection response: HTTPURLResponse,
        newRequest request: URLRequest
    ) async -> URLRequest? {
        guard Self.shouldStripCookie(originalHost: task.originalRequest?.url?.host, redirectHost: request.url?.host) else {
            return request
        }
        var stripped = request
        stripped.setValue(nil, forHTTPHeaderField: "Cookie")
        return stripped
    }

    /// Fail-CLOSED: the cookie is kept ONLY when both hosts are known and
    /// match (case-insensitively — hostnames aren't case-sensitive); an
    /// indeterminate comparison (either host missing — not expected in
    /// practice, since every request this client builds has an absolute
    /// URL with a host) strips it rather than risking a false negative.
    nonisolated static func shouldStripCookie(originalHost: String?, redirectHost: String?) -> Bool {
        guard let originalHost, let redirectHost else { return true }
        return originalHost.caseInsensitiveCompare(redirectHost) != .orderedSame
    }
}

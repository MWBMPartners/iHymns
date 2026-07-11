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

            if let apiError = Self.classify(httpStatus: http.statusCode, retryAfterSeconds: Self.retryAfterSeconds(from: http)) {
                throw apiError
            }

            let elapsedMs = Self.milliseconds(from: start, to: clock.now)
            IHLog.api.debug(
                "api.request ok action=\(endpoint.action, privacy: .public) ms=\(elapsedMs, privacy: .public)"
            )
            return data
        } catch let error as APIError {
            let elapsedMs = Self.milliseconds(from: start, to: clock.now)
            IHLog.api.error(
                "api.request failed action=\(endpoint.action, privacy: .public) error=\(error.caseName, privacy: .public) status=\(error.httpStatusForLogging ?? -1, privacy: .public) ms=\(elapsedMs, privacy: .public)"
            )
            throw error
        }
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
    /// latency.
    nonisolated private static func isRetryable(_ error: APIError) -> Bool {
        switch error {
        case .offline, .maintenance, .rateLimited:
            return true
        case .server(let status, _):
            return (500...599).contains(status)
        case .unauthorized, .accountLocked, .decoding:
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

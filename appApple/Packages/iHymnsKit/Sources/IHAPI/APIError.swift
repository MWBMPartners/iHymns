// APIError.swift
// IHAPI
//
// ELI5: The list of "things that can go wrong" when the app talks to the
// iHymns server, written out by name so the rest of the app can react to
// each one sensibly (e.g. show "offline" instead of a scary crash).
//
// DETAILED: This is the taxonomy specified in strategy §1.5 verbatim.
// Modelling it as a closed `enum` (rather than throwing raw `URLError`/
// `DecodingError` everywhere) means every call site's `catch` gets
// exhaustive, compiler-checked handling — a new case added here forces
// every switch in the codebase to be revisited, which is exactly the
// safety net we want for a taxonomy this consequential (e.g. `.maintenance`
// must never be treated like a generic failure — it's a *designed* 503
// state per `.claude/CLAUDE.md` rule #17 / `includes/maintenance.php`).
import Foundation

/// The complete set of failure modes an `APIClient` request can produce.
///
/// ELI5: One case per "kind of problem," so the app can show the right
/// message instead of a generic "something went wrong."
///
/// DETAILED: `Sendable` so an error thrown inside the `actor APIClient`
/// (background/nonisolated context) can be caught and displayed from a
/// `@MainActor` SwiftUI view without a concurrency-safety warning; `Equatable`
/// purely to make unit-testing call sites straightforward (`#expect(error == .unauthorized)`).
public enum APIError: Error, Sendable, Equatable {
    /// No network reachable — the request never left the device.
    ///
    /// ELI5: "You're offline."
    case offline

    /// The server answered with its designed 503 maintenance response.
    ///
    /// ELI5: "The website itself says 'back soon' — this isn't a bug."
    ///
    /// DETAILED: Mirrors `includes/maintenance.php` + api.php's
    /// `isDbConnectionFailure()` 503 path (CLAUDE.md rule #17): a real,
    /// intentional state, not an error to alarm about. `retryAfterSeconds`
    /// carries the server's `Retry-After` hint when present.
    case maintenance(retryAfterSeconds: Int?)

    /// The Bearer token was rejected — the client should sign the user out.
    ///
    /// ELI5: "You're not logged in (any more)."
    case unauthorized

    /// The account is temporarily locked out after too many failed sign-in
    /// attempts (HTTP 423 Locked) — distinct from `.unauthorized`, which
    /// means "this specific credential/token is wrong/dead," not "wait and
    /// try again." (#1398 — the web backend's brute-force guard today
    /// answers with 429 `.rateLimited` for this case, per
    /// `appWeb/public_html/api.php`'s `auth_login` handler; this case
    /// exists so the native client is ready the moment a dedicated 423
    /// lockout response lands server-side, without another taxonomy
    /// change.)
    ///
    /// ELI5: "Too many wrong tries — this account is on a time-out."
    case accountLocked(retryAfterSeconds: Int?)

    /// The per-token/per-presence-token rate limit was hit.
    ///
    /// ELI5: "Slow down — try again in a bit."
    case rateLimited(retryAfterSeconds: Int?)

    /// A non-2xx, non-401, non-503, non-429 server response.
    ///
    /// ELI5: "The server said no, for some other reason."
    case server(status: Int, message: String?)

    /// The response body didn't decode into the expected `Decodable` type.
    ///
    /// ELI5: "The server sent something we don't understand" — a contract
    /// drift, and worth logging loudly per strategy §1.5.
    case decoding

    /// A gated form's submit was refused because a CAPTCHA challenge is
    /// required (#947/#340 native scaffold,
    /// `.claude/captcha-native-and-outage-plan.md` §2.3-2) — the machine
    /// `code`/`reason: "captcha_required"` classification of an HTTP 403,
    /// recognised by `APIClient.classifyMachineRefusal(httpStatus:body:)`
    /// BEFORE the generic `classify(httpStatus:retryAfterSeconds:)` ever
    /// sees the response (which would otherwise fold every 403 into the
    /// opaque `.server(status: 403, message: nil)`). No associated value:
    /// the server emits ONE reason for both "token missing" and "token
    /// invalid/expired" (telling a bot which failure it hit would only help
    /// the bot — `IHYMNS_CAPTCHA_REASON`'s own doc comment,
    /// `includes/captcha.php:87`), and the client's response is identical
    /// either way (re-render/reset the challenge, let the user retry).
    ///
    /// ELI5: "Prove you're human before I'll let this one through."
    case captchaRequired
}

// MARK: - #1505 logging-safe descriptors
//
// `APIClient+Networking.swift`'s `IHLog.api.error(...)` retrofit (strategy
// §2.2/§4) needs to name WHICH case failed and WHAT HTTP status it maps to,
// without ever interpolating the raw `Error` value itself — `Equatable`'s
// synthesized `String(describing:)` output for `.server`/`.maintenance`/
// etc. would print their associated values too, and `.server`'s `message`
// in particular can carry an arbitrary underlying transport error's
// description (`performOnce`'s generic `catch` branch), which is exactly
// the kind of "we didn't anticipate what's in there" payload strategy §4
// says must never reach a log line, at any privacy level.
extension APIError {
    /// Just the case name (`"offline"`, `"maintenance"`, ...) — safe to log
    /// at `.public` privacy. Associated values are deliberately excluded;
    /// see this section's header comment.
    var caseName: String {
        switch self {
        case .offline: return "offline"
        case .maintenance: return "maintenance"
        case .unauthorized: return "unauthorized"
        case .accountLocked: return "accountLocked"
        case .rateLimited: return "rateLimited"
        case .server: return "server"
        case .decoding: return "decoding"
        case .captchaRequired: return "captchaRequired"
        }
    }

    /// The HTTP status this case corresponds to, mirroring
    /// `APIClient.classify(httpStatus:retryAfterSeconds:)`'s mapping in
    /// reverse — `nil` for `.offline` (the request never reached/returned
    /// from the server at all) and `.decoding` (the HTTP layer succeeded;
    /// only the body's shape was wrong). Safe to log at `.public` privacy:
    /// a bare integer, never the response body it came with.
    var httpStatusForLogging: Int? {
        switch self {
        case .offline, .decoding:
            return nil
        case .maintenance:
            return 503
        case .unauthorized:
            return 401
        case .accountLocked:
            return 423
        case .rateLimited:
            return 429
        case .server(let status, _):
            return status
        case .captchaRequired:
            return 403
        }
    }
}

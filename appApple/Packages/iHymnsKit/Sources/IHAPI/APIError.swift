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
}

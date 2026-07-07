// Endpoint.swift
// IHAPI
//
// ELI5: A little recipe card describing ONE thing you can ask the iHymns
// server for — which `?action=` to call, whether it needs a login token,
// and what extra info (query parameters) to send along.
//
// DETAILED: The web API is a single `?action=` dispatcher (see
// `api-docs.yaml`, the OpenAPI contract referenced throughout
// `.claude/apple-native-strategy.md`), which is why strategy §1.5 rejected
// `swift-openapi-generator` in favour of a small hand-written "typed
// endpoint catalogue": `?action=` query-in-path doesn't map cleanly onto a
// generated REST-style client, but ~60 hand-mapped endpoints is entirely
// tractable and keeps the YAML as the *reference* (feeding contract tests
// that decode live fixtures in CI) rather than as generated-code input.
// `Endpoint` is the shared shape every one of those ~60 mappings will use.
//
// #1398 UPDATE — `auth_login`/`auth_logout` are the first two POST-with-a-
// JSON-body endpoints this catalogue needs (every #1397 catalogue read was
// a bodyless GET). `httpMethod`/`httpBody` are added below with defaults
// (`"GET"` / `nil`) that make every EXISTING call site — every one of them
// still builds a bodyless GET `Endpoint` — behave identically to before;
// only the new `AuthEndpoints.swift` factories opt into the POST shape.
import Foundation

/// Describes one `?action=` call against the iHymns API — everything
/// `APIClient` needs to build a `URLRequest`, independent of which
/// environment (dev/beta/prod) it will ultimately be sent to.
///
/// ELI5: "Call this action, with these extra bits of info, and say whether
/// you need to be logged in."
///
/// DETAILED: Deliberately a plain, `Sendable`, side-effect-free value type
/// (a "recipe," not an "oven") so that building an `Endpoint` and actually
/// firing it are two separable steps — the pure `URLRequest`-building logic
/// in `APIClient.makeURLRequest(for:in:)` can be unit-tested with zero
/// networking, while the actor-isolated `send` method (which touches
/// `URLSession`) is exercised only by integration/contract tests once real
/// fixtures exist (strategy §3.3's "contract tests decoding committed live
/// fixtures").
public struct Endpoint: Sendable, Equatable {
    /// The exact `action` query-parameter value, e.g. `"songs_index"`.
    public let action: String

    /// Additional query parameters beyond `action` itself, e.g.
    /// `[("abbr", "MP")]` for a per-songbook export.
    ///
    /// DETAILED: Kept as an ordered `[(String, String)]` rather than a
    /// `[String: String]` dictionary so query-string construction is
    /// deterministic (stable ordering matters for any future request
    /// signing/caching keyed on the exact URL string, and makes unit-test
    /// assertions on the built URL reproducible rather than dictionary-order
    /// flaky).
    public let queryItems: [(name: String, value: String)]

    /// Whether this call requires the `Authorization: Bearer <token>`
    /// header (strategy §1.5/§1.6). Public, unauthenticated reads (e.g.
    /// `songs_index`) leave this `false`.
    public let requiresAuth: Bool

    /// The HTTP method to send this request with. Every #1397 catalogue
    /// read is a bodyless `"GET"` (the default); `auth_login`/`auth_logout`
    /// (#1398, `AuthEndpoints.swift`) are the first `"POST"` endpoints.
    public let httpMethod: String

    /// A pre-encoded JSON request body, or `nil` for a bodyless request
    /// (every GET). Encoded up front by the `Endpoint` factory (e.g.
    /// `Endpoint.authLogin(username:password:)`) rather than inside
    /// `APIClient`, keeping `APIClient.makeURLRequest` a dumb "attach these
    /// bytes" step with no knowledge of any individual endpoint's payload
    /// shape.
    public let httpBody: Data?

    public init(
        action: String,
        queryItems: [(name: String, value: String)] = [],
        requiresAuth: Bool = false,
        httpMethod: String = "GET",
        httpBody: Data? = nil
    ) {
        self.action = action
        self.queryItems = queryItems
        self.requiresAuth = requiresAuth
        self.httpMethod = httpMethod
        self.httpBody = httpBody
    }

    /// `Equatable` is hand-written (rather than synthesized) only because
    /// tuple-array properties don't get free `Equatable` conformance from
    /// the compiler; this compares element-by-element.
    public static func == (lhs: Endpoint, rhs: Endpoint) -> Bool {
        lhs.action == rhs.action
            && lhs.requiresAuth == rhs.requiresAuth
            && lhs.httpMethod == rhs.httpMethod
            && lhs.httpBody == rhs.httpBody
            && lhs.queryItems.elementsEqual(rhs.queryItems, by: { $0.name == $1.name && $0.value == $1.value })
    }
}

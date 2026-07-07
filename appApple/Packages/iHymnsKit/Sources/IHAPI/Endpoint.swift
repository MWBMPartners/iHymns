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

    public init(action: String, queryItems: [(name: String, value: String)] = [], requiresAuth: Bool = false) {
        self.action = action
        self.queryItems = queryItems
        self.requiresAuth = requiresAuth
    }

    /// `Equatable` is hand-written (rather than synthesized) only because
    /// tuple-array properties don't get free `Equatable` conformance from
    /// the compiler; this compares element-by-element.
    public static func == (lhs: Endpoint, rhs: Endpoint) -> Bool {
        lhs.action == rhs.action
            && lhs.requiresAuth == rhs.requiresAuth
            && lhs.queryItems.elementsEqual(rhs.queryItems, by: { $0.name == $1.name && $0.value == $1.value })
    }
}

// IHLog.swift
// IHLog
//
// ELI5: The one place every other part of the app goes to write down "what
// just happened" so that later — on a Mac's Console app, or in a
// TestFlight tester's exported diagnostics — someone can figure out WHY a
// live-follow session, a remote connection, or a network call failed,
// without that someone needing physical access to the failing device while
// it's failing.
//
// DETAILED: #1505 (P1 of the observability programme,
// `.claude/live-observability-strategy.md` §2.1). Wraps Apple's unified
// logging system (`os.Logger`, https://developer.apple.com/documentation/os/logger)
// behind ONE small enum of pre-built `Logger`/`OSSignposter` instances so
// every call site writes `IHLog.api.debug(...)` instead of constructing its
// own `Logger(subsystem:category:)` — the same "extract first, use second"
// modularity rule this repo applies everywhere else (`.claude/CLAUDE.md`),
// applied here to keep the SUBSYSTEM string (which is what a saved
// Console.app search / `sysdiagnose` grep keys off, strategy §2.6) from
// drifting into two different literals across the codebase.
//
// `subsystem = "app.ihymns"` intentionally matches the existing literal in
// `IHAppSupport/IHAnalyticsSink.swift:29` (which this file does NOT modify,
// per the task brief — that file predates IHLog and has its own narrow
// DEBUG-only `#if DEBUG` logger for analytics-event tracing). Keeping both
// on the same subsystem string means a single Console.app filter
// (`subsystem == "app.ihymns"`) surfaces every iHymns log line on the
// device, regardless of which internal facility wrote it.
//
// WHY a `Logger` per CATEGORY rather than one shared `Logger` for
// everything: Console.app/`sysdiagnose` predicates can filter on
// `category` too (e.g. "show me only `remote` — the LAN TV-connection
// noise, not the API traffic"), and Apple's own guidance recommends one
// category per functional area for exactly this reason. `signposter` is a
// separate `OSSignposter` (not a `Logger`) because Instruments' time-profile
// tooling reads signpost intervals through that distinct API surface — see
// https://developer.apple.com/documentation/os/ossignposter.
//
// Deliberately NOT configurable at runtime (strategy §2.3): `os.Logger` is
// always-on by OS design, `.debug` calls are free when nobody is streaming/
// viewing them, and a custom verbosity gate would only add a "logging was
// off during the one test run that mattered" failure mode. The on-device
// diagnostic overlay (a LATER phase, §2.6) is the only user-facing toggle;
// this file has none.
import Foundation
import os

/// The shared logging facility every iHymnsKit target writes through.
///
/// ELI5: "Where do I write down what just happened?" — pick the `Logger`
/// that matches what you're doing (`api` for a network call, `auth` for a
/// sign-in/out event, ...) and call `.debug`/`.notice`/`.error`/`.fault` on
/// it, exactly like Apple's own `Logger` API.
///
/// DETAILED: A stateless `enum` (never instantiated) holding only `static
/// let` constants — `Logger`/`OSSignposter` are both documented as
/// thread-safe/`Sendable` value-ish wrappers around the unified logging
/// system, so these constants are safe to read from any actor or
/// `@Sendable` closure with no synchronization of our own (verified by
/// `IHLogTests`'s "usable from an actor" case). No call site should ever
/// construct its own `Logger(subsystem:category:)` — that would fork the
/// subsystem string this whole facility exists to keep singular.
public enum IHLog {

    /// The ONE subsystem every iHymns log line (from this facility or from
    /// `IHAnalyticsSink`'s pre-existing DEBUG logger) is tagged with — the
    /// exact string a Console.app/`sysdiagnose` search predicate matches
    /// on. Changing this is a breaking change to every saved filter/
    /// documented debugging recipe (strategy §2.6/§6), so it is asserted
    /// stable by `IHLogTests`.
    public static let subsystem = "app.ihymns"

    /// The exact `category` string each `Logger` below is built with, kept
    /// as named constants (rather than only as literals inline in the
    /// `Logger(...)` calls) purely so `IHLogTests` can assert they're
    /// stable WITHOUT needing to introspect a constructed `Logger` — Apple's
    /// `Logger` type does not expose its own subsystem/category back out
    /// once built, so the only way to guard "these strings never silently
    /// change" is to test the strings themselves, at the point they're
    /// defined. `internal` (not `public`) — this is a Console-predicate
    /// implementation detail, not part of the facility's public contract
    /// (`IHLog.api` etc. are the contract).
    enum Category {
        static let api = "api"
        static let liveFollow = "live-follow"
        static let remote = "remote"
        static let discovery = "discovery"
        static let auth = "auth"
        static let signposts = "spans"
    }

    /// Network-layer events — `IHAPI.APIClient` request/response/retry
    /// lifecycle (§2.2). NEVER carries a URL, query string, request body,
    /// or `Authorization` header value (§4) — only the endpoint `action`
    /// name, the `APIError` case, HTTP status, and duration.
    public static let api = Logger(subsystem: subsystem, category: Category.api)

    /// Live-Follow / Service-Mode session lifecycle — join/poll-health/
    /// freshness/broadcast/session-end transitions (strategy §2.4, a
    /// binding contract for the not-yet-built engine; nothing calls this
    /// yet as of #1505/P1). Category is `"live-follow"` (hyphenated, not
    /// `liveFollow`) to match the exact Console predicate strategy §2.4
    /// names.
    public static let liveFollow = Logger(subsystem: subsystem, category: Category.liveFollow)

    /// LAN remote-control session lifecycle — connect/pair/apply spans for
    /// the not-yet-built `IHLive/LANRemote` engine (strategy §2.5). NEVER
    /// carries a pairing code.
    public static let remote = Logger(subsystem: subsystem, category: Category.remote)

    /// LAN discovery (Bonjour browse results, local-network permission
    /// state) — again for the not-yet-built `LANRemote` engine (strategy
    /// §2.5). Local-network-permission-denied is called out there as "the
    /// single most valuable TestFlight line" this category will ever carry.
    public static let discovery = Logger(subsystem: subsystem, category: Category.discovery)

    /// Sign-in/out session-state transitions — `IHAuth.SessionController`
    /// (§2.2). `.private` for any user/device identifier; NEVER the bearer
    /// token or username at any privacy level (§4).
    public static let auth = Logger(subsystem: subsystem, category: Category.auth)

    /// Signpost intervals (`"api.request"`, and later `"live.join"`/
    /// `"live.poll.gap"`/`"lan.connect"`/`"remote.apply"` per strategy
    /// §2.4/§2.5) — read by Instruments' time-profile tooling, a distinct
    /// API surface from `Logger` (see
    /// https://developer.apple.com/documentation/os/ossignposter). Category
    /// `"spans"` groups every signpost interval this app emits under one
    /// Console/Instruments filter, separate from the plain log-line
    /// categories above.
    public static let signposter = OSSignposter(subsystem: subsystem, category: Category.signposts)
}

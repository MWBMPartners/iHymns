// LiveFollowEngine.swift
// IHLive
//
// ELI5: Keeps track of "is the live worship session still actually live, or
// has it gone quiet?" by watching how long it's been since the last update
// arrived from the server.
//
// DETAILED: This is the Phase-0 skeleton of the server-mediated Live Follow
// / Service Mode engine described in strategy §1.2 ("`LiveFollowEngine` +
// `ServiceModeEngine` actors — polling loops, 30 s heartbeat, 180 s
// freshness, presence-token lifecycle... for the server-mediated Live
// Follow / Service Mode congregant feature") and §1.3's structured-
// concurrency posture ("Live loops = structured `Task` + `Task.sleep`,
// cancelled on scene-phase `.background`... restarted `.active`"). Note
// this module ALSO eventually hosts the entirely separate `IHLive/LANRemote`
// sub-module (strategy §1.2/§2.4 — peer-to-peer TV remote control, unrelated
// to this server-polling engine) — that lands in Phase 2 (strategy §3.4);
// Phase 0 only needs enough of IHLive to prove the IHLive→IHAPI dependency
// direction compiles and one real, useful piece of logic exists.
//
// The one genuinely real piece of logic at this phase is the freshness
// check itself: `.claude/CLAUDE.md` rule #26 and strategy §2.4.6 both cite
// a fixed "180 s freshness" threshold the web/PWA Live Follow already uses
// to decide "is this session still live" independent of the poll cadence —
// the native client must apply the EXACT same threshold so a session never
// appears "live" on the web and "stale" on native (or vice versa) at the
// same wall-clock moment.
import Foundation
import IHAPI

/// Coordinates polling + freshness for a Live Follow / Service Mode
/// congregant session.
///
/// ELI5: The thing that decides "yep, still live" or "nope, gone stale."
public actor LiveFollowEngine {
    /// The freshness window, in seconds, matching the web/PWA's own
    /// constant (strategy §2.4.6, CLAUDE.md rule #26).
    public static let freshnessWindowSeconds: TimeInterval = 180

    private let apiClient: APIClient

    public init(apiClient: APIClient) {
        self.apiClient = apiClient
    }

    /// Pure freshness check: is a session, whose last broadcast update
    /// arrived at `lastUpdatedAt`, still considered "live" at `now`?
    ///
    /// ELI5: If the last update was less than 180 seconds ago, we're still
    /// live; otherwise the session looks stale.
    ///
    /// DETAILED: `nonisolated` and a pure function of its three inputs
    /// (rather than reading `Date()`/actor state internally) so it is
    /// trivially unit-testable with injected clock values — strategy
    /// §3.3 explicitly calls for "injected-clock tests for IHLive cadence/
    /// freshness (no wall-clock sleeps)"; this is the seam that enables
    /// that discipline from day one, before any real polling loop exists to
    /// wrap it.
    ///
    /// - Parameters:
    ///   - lastUpdatedAt: When the most recent broadcast/poll response was
    ///     received.
    ///   - now: The current instant to evaluate freshness against
    ///     (injected, never `Date()` read internally, for testability).
    /// - Returns: `true` if the session is still within the freshness
    ///   window.
    nonisolated public static func isFresh(lastUpdatedAt: Date, now: Date) -> Bool {
        now.timeIntervalSince(lastUpdatedAt) < freshnessWindowSeconds
    }
}

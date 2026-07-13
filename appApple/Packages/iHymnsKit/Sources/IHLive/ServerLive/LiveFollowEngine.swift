// LiveFollowEngine.swift
// IHLive/ServerLive
//
// ELI5: The actor that runs BOTH sides of a native "Live Follow" session —
// hosting one (broadcasting the song you're reading to a shareable code) and
// following one (watching a code someone else shared) — plus the "is this
// still actually live, or has it gone quiet?" freshness check both roles
// rely on.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §3.4). Grown from the Phase-0 skeleton (originally `Sources/IHLive/
// LiveFollowEngine.swift`, moved into this `ServerLive/` subdirectory so the
// hard separation from the peer-to-peer `IHLive/LANRemote` sub-module
// (strategy §2.4.1 — different trust model, different protocol, NOT shared
// code) is legible on disk, mirroring `LANRemote/`'s own directory). `
// freshnessWindowSeconds`/`isFresh` are BYTE-IDENTICAL to the Phase-0
// skeleton — `Tests/IHLiveTests/LiveFollowEngineTests.swift` stays green
// UNTOUCHED, proving this move-then-grow never touched that seam.
//
// Mutual exclusivity is web parity (`live-follow.js:64-69`, "host wins"):
// `goLive` while `.following` throws `LiveSyncError.alreadyFollowing`
// (`+Follower.swift`); `join` while `.hosting` throws `.alreadyHosting`
// (`+Follower.swift`). ONE `loopTask` slot enforces at most one active loop
// (heartbeat OR poll, never both) at a time — see `+Host.swift`/
// `+Follower.swift` for the two loop bodies this file's `setScenePhaseActive`
// starts/stops.
import Foundation
import IHAPI
import IHModels

/// Hosts (broadcasts) or follows a Live Follow session — see this file's
/// header for the full picture.
///
/// ELI5: The thing that either shares what you're reading, or watches what
/// someone else shared — and separately, decides "yep, still live" or
/// "nope, gone stale."
public actor LiveFollowEngine {
    /// The freshness window, in seconds, matching the web/PWA's own
    /// constant (strategy §2.4.6, CLAUDE.md rule #26). UNCHANGED from the
    /// Phase-0 skeleton — see this file's header.
    public static let freshnessWindowSeconds: TimeInterval = 180

    /// Pure freshness check: is a session, whose last broadcast update
    /// arrived at `lastUpdatedAt`, still considered "live" at `now`?
    /// UNCHANGED from the Phase-0 skeleton (`Tests/IHLiveTests/
    /// LiveFollowEngineTests.swift` still exercises exactly this).
    ///
    /// ELI5: If the last update was less than 180 seconds ago, we're still
    /// live; otherwise the session looks stale.
    nonisolated public static func isFresh(lastUpdatedAt: Date, now: Date) -> Bool {
        now.timeIntervalSince(lastUpdatedAt) < freshnessWindowSeconds
    }

    /// What this engine is currently doing. `.idle` = neither hosting nor
    /// following.
    public enum Role: Sendable, Equatable {
        case idle
        case hosting(code: String)
        case following(code: String)
    }

    /// Widened from `private` (Phase-0) to plain (module-internal) —
    /// `+Host.swift`/`+Follower.swift` call `apiClient.liveFollowCreate(...)`/
    /// `.liveFollowJoin(...)`/etc. directly (same cross-file-extension
    /// reasoning `AppRootViewModel.swift`'s own `apiClient` documents).
    let apiClient: APIClient
    let config: LiveSyncConfiguration
    let persistence: LiveSyncPersistence

    /// `internal(set)` (not `private(set)`) — `+Host.swift`/`+Follower.swift`
    /// are DIFFERENT files that mutate this directly (same cross-file-
    /// extension reasoning `AppRootViewModel.swift`'s `internal(set)`
    /// properties document — `private` is file-scoped in Swift, so a
    /// same-module extension in another file needs at least `internal`
    /// write access).
    public internal(set) var role: Role = .idle
    /// The follower reducer's state — meaningful only while `.following`;
    /// left at its default while `.idle`/`.hosting`.
    var followerState = LiveFollowerState()
    /// The poll-rate-bucket token minted at `join` — engine-internal custody
    /// ONLY, never rides `LiveFollowJoinInfo`/an event payload (spec §3.2/§8).
    var followToken: String?
    /// The host's own dedup key (`"songId|componentIndex"`, `live-follow.js
    /// :41,173-174`) — `broadcast(songId:componentIndex:)` no-ops a repeat.
    var lastBroadcastKey: String?
    /// The ONE active loop (heartbeat while hosting, poll while following) —
    /// never both at once.
    var loopTask: Task<Void, Never>?
    var suspended = false

    nonisolated public let events: AsyncStream<LiveFollowEvent>
    let eventContinuation: AsyncStream<LiveFollowEvent>.Continuation

    /// - Parameters:
    ///   - apiClient: The shared network client.
    ///   - configuration: Cadence/clock/sleep literals — defaulted so every
    ///     Phase-0 call site (`AppRootViewModel+Live.swift`'s `makeLive`) and
    ///     every existing test compiles unchanged.
    ///   - persistence: Resume-record storage — defaulted to the real
    ///     UserDefaults-backed store; tests inject a throwaway suite.
    public init(
        apiClient: APIClient,
        configuration: LiveSyncConfiguration = LiveSyncConfiguration(),
        persistence: LiveSyncPersistence = .standard
    ) {
        self.apiClient = apiClient
        self.config = configuration
        self.persistence = persistence
        let (stream, continuation) = AsyncStream.makeStream(of: LiveFollowEvent.self)
        self.events = stream
        self.eventContinuation = continuation
    }

    /// `scenePhase` wiring (spec §3.4/§6.6): background cancels whichever
    /// loop is running outright (no delay, no loop — strategy §1.3);
    /// foreground restarts it, with an immediate "wake" action first (a
    /// heartbeat for a host — the revival trick `live-follow.js:193-201`
    /// documents — or an immediate poll for a follower) so a session that
    /// merely lapsed in freshness while backgrounded un-stales at once
    /// rather than waiting a full cadence tick.
    public func setScenePhaseActive(_ active: Bool) async {
        suspended = !active
        guard active else {
            loopTask?.cancel()
            loopTask = nil
            return
        }
        switch role {
        case .hosting:
            await performHeartbeat()
            startHeartbeatLoopIfNeeded()
        case .following:
            await performFollowerPoll()
            startFollowerLoopIfNeeded()
        case .idle:
            break
        }
    }
}

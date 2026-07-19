// APIClient+LiveSync.swift
// IHAPI
//
// ELI5: The nine actual "phone calls" for Live Follow + Service Mode — thin
// wrappers that build the request, send it, and hand back the decoded
// shape. No cadence/retry/custody logic lives here at all — that's
// `IHLive`'s job (`LiveFollowEngine`/`ServiceModeEngine`).
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §4.2). The four non-idempotent POSTs that MUTATE session state
// (`create`/`update`/`heartbeat`/`leave`, both families) go through the
// non-retrying `performOnce` — the SAME "never auto-retry a non-idempotent
// POST" rule `APIClient+Auth.swift`'s header documents (a client-side retry
// of `live_follow_update` could double-log a song-change breadcrumb
// server-side). The three GETs (`live_follow_join`/`live_follow_poll`/
// `service_poll`) are ordinary idempotent reads, so they go through
// `performIdempotentGET` like every other catalogue read — `IHLive`'s own
// poll-cadence/backoff sits ABOVE this layer and is unaffected by
// `performIdempotentGET`'s few-hundred-millisecond internal retry budget.
import Foundation
import IHModels

extension APIClient {
    /// `?action=live_follow_create` — starts (or restarts) a hosting
    /// session. Returns the new session's code AND numeric id (#1429 C6 —
    /// the id is what `?action=apns_register`'s `kind=liveActivity` branch
    /// needs to scope a Live Activity push token to THIS session).
    public func liveFollowCreate(songId: String?, componentIndex: Int?) async throws -> LiveFollowCreateResult {
        let endpoint = try Endpoint.liveFollowCreate(songId: songId, componentIndex: componentIndex)
        let data = try await performOnce(endpoint)
        return try Self.decodeLiveFollowCreate(from: data)
    }

    /// `?action=live_follow_update` — broadcasts the host's current song +
    /// section. Returns the new revision. Throws whatever `APIClient
    /// .classify` mapped the HTTP status to — notably a 409 (superseded/
    /// ended, `api.php:14026`) surfaces as `.server(status: 409, _)`; the
    /// calling engine (`LiveFollowEngine+Host.swift`) is what turns that into
    /// `.hostingEnded(.superseded)` (spec §4.2 — narrowest mapping lives in
    /// the engine, not a widened `APIError`).
    public func liveFollowUpdate(code: String, songId: String, componentIndex: Int?) async throws -> Int {
        let endpoint = try Endpoint.liveFollowUpdate(code: code, songId: songId, componentIndex: componentIndex)
        let data = try await performOnce(endpoint)
        return try Self.decodeLiveFollowUpdate(from: data)
    }

    /// `?action=live_follow_heartbeat` — keepalive; returns `false` if the
    /// session is already gone (`api.php:14078`).
    public func liveFollowHeartbeat(code: String) async throws -> Bool {
        let endpoint = try Endpoint.liveFollowHeartbeat(code: code)
        let data = try await performOnce(endpoint)
        return try Self.decodeLiveFollowHeartbeat(from: data)
    }

    /// `?action=live_follow_leave` — the host's deliberate "End."
    public func liveFollowLeave(code: String) async throws {
        let endpoint = try Endpoint.liveFollowLeave(code: code)
        _ = try await performOnce(endpoint)
    }

    /// `?action=live_follow_join` — joins a Live Follow session by code.
    /// Returns the public info PLUS the engine-only `followToken`.
    public func liveFollowJoin(code: String) async throws -> LiveFollowJoinResult {
        let data = try await performIdempotentGET(.liveFollowJoin(code: code))
        return try Self.decodeLiveFollowJoin(from: data)
    }

    /// `?action=live_follow_poll` — cheap `?since=` poll for a follower.
    /// `token` is the `followToken` minted at join (poll-rate custody only —
    /// NOT authorisation, `api.php:14163-14167`).
    public func liveFollowPoll(code: String, since: Int, token: String?) async throws -> LivePollUpdate {
        let data = try await performIdempotentGET(.liveFollowPoll(code: code, since: since, token: token))
        return try Self.decodeLivePollUpdate(from: data)
    }

    /// `?action=service_join` — joins a Service Mode congregation by the
    /// venue's rotating code, as EITHER a congregant follower or (Apple
    /// Phase-2 PR-15, #1428) the venue's tvOS projector — `role` is the
    /// server's own `"congregant"`/`"projector"` string (`api.php:14615`).
    /// Returns the public info PLUS the engine-only `presenceToken` (the
    /// CCLI-unlock gate key, §4.3/D-6 — custody stays with `ServiceModeEngine`,
    /// which calls `updateServicePresenceToken(_:)` below the instant this
    /// returns).
    public func serviceJoin(code: String, presenceDeviceId: String, role: String) async throws -> ServiceJoinResult {
        let endpoint = try Endpoint.serviceJoin(code: code, presenceDeviceId: presenceDeviceId, role: role)
        let data = try await performOnce(endpoint)
        return try Self.decodeServiceJoin(from: data)
    }

    /// `?action=service_poll` — cheap `?since=` poll for a congregant.
    public func servicePoll(presenceToken: String, since: Int) async throws -> LivePollUpdate {
        let data = try await performIdempotentGET(.servicePoll(presenceToken: presenceToken, since: since))
        return try Self.decodeLivePollUpdate(from: data)
    }

    /// `?action=service_leave` — always 200s, even for an already-invalid
    /// token (`api.php:14756`) — best-effort immediate presence revocation.
    public func serviceLeave(presenceToken: String) async throws {
        let endpoint = try Endpoint.serviceLeave(presenceToken: presenceToken)
        _ = try await performOnce(endpoint)
    }
}

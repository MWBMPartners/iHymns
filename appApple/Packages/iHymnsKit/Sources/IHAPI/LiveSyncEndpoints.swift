// LiveSyncEndpoints.swift
// IHAPI
//
// ELI5: The ten recipe cards (see `Endpoint.swift`) for "start a live
// session," "tell everyone what I'm looking at now," "still there?," "I'm
// done," "let me follow that code," "anything new?," "let me join this
// service," "anything new for me?," "I'm leaving," and "mirror what's on
// this TV to a running service session."
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §4.1) plus PR-14 (#1425, `.claude/apple-native-strategy.md` §2.4.1's
// "optional Service-Mode mirror"). Verified against the LIVE
// `appWeb/public_html/api.php` handlers (`live_follow_*` `:13870-14239`,
// `service_*` `:14599-14771`, `service_broadcast` `:14478-14597`) on
// 2026-07-13/2026-07-18. `state` is deliberately NEVER sent by any Live
// Follow factory below — the native host broadcasts song/section only,
// exactly like the web host (`live-follow.js:176-179`); writing
// `displayState`/`blank`/... is normally the projector/operator's job
// (PR-15/the deferred operator console, spec §5.3) — `serviceBroadcast`
// below is the ONE deliberate exception, because it's PR-14's own operator
// mirror, and `displayState` is exactly what it exists to send (matching
// the web `service-broadcast.js` driver's identical contract).
// Mirrors `AuthEndpoints.swift`'s pattern: `internal` (not `public`) factory
// members — only `APIClient+LiveSync.swift` (same module) and
// `@testable import IHAPI` tests call these directly.
import Foundation

extension Endpoint {
    /// `?action=live_follow_create` — starts (or restarts, superseding any
    /// prior one) a Live Follow hosting session. POST, auth required
    /// (`api.php:13880`).
    static func liveFollowCreate(songId: String?, componentIndex: Int?) throws -> Endpoint {
        let body = try JSONEncoder().encode(LiveFollowCreateBody(songId: songId, componentIndex: componentIndex))
        return Endpoint(action: "live_follow_create", requiresAuth: true, httpMethod: "POST", httpBody: body)
    }

    /// `?action=live_follow_update` — broadcasts the host's full current
    /// context. POST, auth required (`api.php:13970`). `songId` is REQUIRED
    /// by the server (`:13990`) — the host never broadcasts a "no song"
    /// state via this endpoint.
    static func liveFollowUpdate(code: String, songId: String, componentIndex: Int?) throws -> Endpoint {
        let body = try JSONEncoder().encode(LiveFollowUpdateBody(code: code, songId: songId, componentIndex: componentIndex))
        return Endpoint(action: "live_follow_update", requiresAuth: true, httpMethod: "POST", httpBody: body)
    }

    /// `?action=live_follow_heartbeat` — keepalive only, never bumps the
    /// revision (`api.php:14043-14055`). POST, auth required.
    static func liveFollowHeartbeat(code: String) throws -> Endpoint {
        let body = try JSONEncoder().encode(LiveSyncCodeBody(code: code))
        return Endpoint(action: "live_follow_heartbeat", requiresAuth: true, httpMethod: "POST", httpBody: body)
    }

    /// `?action=live_follow_leave` — the host's deliberate "End" action.
    /// POST, auth required (`api.php:14082`).
    static func liveFollowLeave(code: String) throws -> Endpoint {
        let body = try JSONEncoder().encode(LiveSyncCodeBody(code: code))
        return Endpoint(action: "live_follow_leave", requiresAuth: true, httpMethod: "POST", httpBody: body)
    }

    /// `?action=live_follow_join&code=` — GET, anonymous (`api.php:14116`).
    static func liveFollowJoin(code: String) -> Endpoint {
        Endpoint(action: "live_follow_join", queryItems: [("code", code)])
    }

    /// `?action=live_follow_poll&code=&since=&token=` — GET, anonymous
    /// (`api.php:14184`). `token` (the follow token minted at join) is
    /// OMITTED entirely when absent — never sent as an empty string — so a
    /// stateless-token-less legacy caller shape is preserved exactly.
    static func liveFollowPoll(code: String, since: Int, token: String?) -> Endpoint {
        var items: [(name: String, value: String)] = [("code", code), ("since", String(since))]
        if let token {
            items.append(("token", token))
        }
        return Endpoint(action: "live_follow_poll", queryItems: items)
    }

    /// `?action=service_join` — POST, anonymous (`api.php:14599`).
    /// `presenceDeviceId` is REQUIRED by the server (`:14613`); `role` is
    /// `"congregant"` (the phone/tablet follower clients, `ServiceModeEngine
    /// .join(code:role:)`'s default) or `"projector"` (Apple Phase-2 PR-15,
    /// #1428: the tvOS venue screen, `TVServiceFollowCoordinator`) —
    /// `#1406`'s optional client-declared role (`api.php:14615`) selects the
    /// per-role poll cadence/budget the server hands back
    /// (`pollIntervalMs`, `:14686`; the poll-rate ceiling, `:14711-14712`).
    static func serviceJoin(code: String, presenceDeviceId: String, role: String) throws -> Endpoint {
        let body = try JSONEncoder().encode(ServiceJoinBody(code: code, presenceDeviceId: presenceDeviceId, role: role))
        return Endpoint(action: "service_join", httpMethod: "POST", httpBody: body)
    }

    /// `?action=service_poll&presenceToken=&since=` — GET, anonymous
    /// (`api.php:14695`).
    static func servicePoll(presenceToken: String, since: Int) -> Endpoint {
        Endpoint(action: "service_poll", queryItems: [("presenceToken", presenceToken), ("since", String(since))])
    }

    /// `?action=service_leave` — POST, anonymous, ALWAYS 200s even for a
    /// malformed token (`api.php:14753-14771`) — immediate presence
    /// revocation.
    static func serviceLeave(presenceToken: String) throws -> Endpoint {
        let body = try JSONEncoder().encode(LiveSyncPresenceTokenBody(presenceToken: presenceToken))
        return Endpoint(action: "service_leave", httpMethod: "POST", httpBody: body)
    }

    /// `?action=service_broadcast` — the PR-14 operator mirror: rebroadcasts
    /// THIS TV's current song/section/display state into a Service Mode
    /// session so its congregants stay in sync too (#1425, strategy
    /// §2.4.1's "optional Service-Mode mirror," `api.php:14478-14597` +
    /// `includes/service_mode.php:140-178`). POST, auth required — the
    /// server authorises via `serviceMode_userCanOperate`. `songId` is
    /// REQUIRED (this mirror only ever sends a real song, never an idle/no
    /// -song broadcast — `ServiceMirrorController.mirrorKey(for:)`'s own
    /// `nil`-on-no-song guard is what enforces that before this factory is
    /// ever reached).
    static func serviceBroadcast(sessionId: Int, songId: String, componentIndex: Int?, displayState: String) throws -> Endpoint {
        let body = try JSONEncoder().encode(
            ServiceBroadcastBody(sessionId: sessionId, songId: songId, componentIndex: componentIndex, state: ServiceBroadcastState(displayState: displayState))
        )
        return Endpoint(action: "service_broadcast", requiresAuth: true, httpMethod: "POST", httpBody: body)
    }
}

// MARK: - Request bodies (private — `Optional` fields encode as an absent
// key via Swift's synthesized `encodeIfPresent`, matching
// `AuthEndpoints.swift`'s established "absent key, never a literal `null`"
// convention).

private struct LiveFollowCreateBody: Encodable {
    let songId: String?
    let componentIndex: Int?
}

private struct LiveFollowUpdateBody: Encodable {
    let code: String
    let songId: String
    let componentIndex: Int?
}

private struct LiveSyncCodeBody: Encodable {
    let code: String
}

private struct ServiceJoinBody: Encodable {
    let code: String
    let presenceDeviceId: String
    let role: String
}

private struct LiveSyncPresenceTokenBody: Encodable {
    let presenceToken: String
}

private struct ServiceBroadcastBody: Encodable {
    let sessionId: Int
    let songId: String
    let componentIndex: Int?
    let state: ServiceBroadcastState
}

private struct ServiceBroadcastState: Encodable {
    let displayState: String
}

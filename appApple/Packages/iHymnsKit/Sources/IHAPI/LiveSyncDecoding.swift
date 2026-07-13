// LiveSyncDecoding.swift
// IHAPI
//
// ELI5: Turns the raw bytes the nine `LiveSyncEndpoints` calls get back into
// the `IHModels` shapes the rest of the app uses — plus the two secret
// tokens (`followToken`/`presenceToken`) that stay OUT of those public
// `IHModels` DTOs on purpose (spec §3.2) but still need to reach the ENGINE
// that minted the join, so they travel in a small IHAPI-only wrapper type.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §4.2). Mirrors `SongsIndexDecoding.swift`'s pattern: `nonisolated static`
// decode functions on `APIClient`, independently unit-testable with zero
// networking. `LivePollUpdate` is the ONE tolerant envelope shared by BOTH
// `live_follow_poll` and `service_poll` — their three response shapes
// (§1.3's tables) are compatible unions: `active` is absent on
// `live_follow_poll`'s alive rows (`api.php:14229`) and present (`true`) on
// `service_poll`'s; the DECODE stays dumb about what any of that MEANS
// (`LiveFollowerReducer`, `IHLive`, owns that interpretation) — this file
// only turns bytes into an optional-everywhere struct.
import Foundation
import IHModels

/// The one poll response shape for BOTH `live_follow_poll` and
/// `service_poll` (see file header). `active == false` is the ONLY end
/// signal the reducer honours; `active == nil` means "alive, no field sent"
/// (the `live_follow_poll` shape) — never treat an absent `active` as false.
public struct LivePollUpdate: Sendable, Equatable, Codable {
    public let active: Bool?
    public let changed: Bool?
    public let currentSongId: String?
    public let componentIndex: Int?
    public let state: LiveBroadcastState?
    public let revision: Int?

    public init(active: Bool?, changed: Bool?, currentSongId: String?, componentIndex: Int?, state: LiveBroadcastState?, revision: Int?) {
        self.active = active
        self.changed = changed
        self.currentSongId = currentSongId
        self.componentIndex = componentIndex
        self.state = state
        self.revision = revision
    }
}

/// The generic `{ok, revision?, error?}` shape every plain `live_follow_*`
/// POST answers with (`heartbeat`/`leave`, and `update`'s success case).
struct OkResponse: Decodable, Sendable {
    // swiftlint:disable:next identifier_name
    let ok: Bool?
    let revision: Int?
    let error: String?
}

/// `live_follow_join`'s full result — the public `LiveFollowJoinInfo` PLUS
/// the `followToken` the engine needs for its own poll-rate custody
/// (`LiveFollowJoinInfo` deliberately excludes it, spec §3.2). Only
/// `IHLive.LiveFollowEngine` ever unpacks `followToken`; nothing else in
/// this app should reach for it.
public struct LiveFollowJoinResult: Sendable, Equatable {
    public let info: LiveFollowJoinInfo
    public let followToken: String

    public init(info: LiveFollowJoinInfo, followToken: String) {
        self.info = info
        self.followToken = followToken
    }
}

/// `service_join`'s full result — same "public info + engine-only secret"
/// split as `LiveFollowJoinResult` above, for `presenceToken`.
public struct ServiceJoinResult: Sendable, Equatable {
    public let info: ServiceJoinInfo
    public let presenceToken: String

    public init(info: ServiceJoinInfo, presenceToken: String) {
        self.info = info
        self.presenceToken = presenceToken
    }
}

extension APIClient {
    /// Decodes a `live_follow_create` response body (`{"ok","code","revision"}`)
    /// into just the new session code — `revision` is always `0` on a fresh
    /// create (`api.php:13966`), so no caller needs it back.
    nonisolated public static func decodeLiveFollowCreate(from data: Data) throws -> String {
        struct Response: Decodable { let code: String }
        do {
            return try JSONDecoder().decode(Response.self, from: data).code
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a `live_follow_update` success body (`{"ok":true,"revision"}`)
    /// into the new revision. A non-2xx (incl. the 409 "superseded/ended"
    /// signal, `api.php:14026`) never reaches this — `APIClient.classify`
    /// already turned it into a thrown `APIError` before any body is read;
    /// the calling engine maps THAT thrown error, not this decode (spec §4.2).
    nonisolated public static func decodeLiveFollowUpdate(from data: Data) throws -> Int {
        do {
            guard let revision = try JSONDecoder().decode(OkResponse.self, from: data).revision else {
                throw APIError.decoding
            }
            return revision
        } catch let error as APIError {
            throw error
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a `live_follow_heartbeat` body (`{"ok": Bool}`) into that flag
    /// — `false` means the session is gone (`api.php:14078`).
    nonisolated public static func decodeLiveFollowHeartbeat(from data: Data) throws -> Bool {
        do {
            return try JSONDecoder().decode(OkResponse.self, from: data).ok ?? false
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a `live_follow_join` success body into a `LiveFollowJoinResult`
    /// (public info + the engine-only `followToken`).
    nonisolated public static func decodeLiveFollowJoin(from data: Data) throws -> LiveFollowJoinResult {
        struct Response: Decodable {
            let code: String
            let followToken: String
            let hostDisplayName: String
            let currentSongId: String?
            let componentIndex: Int?
            let state: LiveBroadcastState?
            let revision: Int
        }
        do {
            let response = try JSONDecoder().decode(Response.self, from: data)
            let snapshot = LiveBroadcastSnapshot(
                songId: response.currentSongId,
                componentIndex: response.componentIndex,
                state: response.state,
                revision: response.revision
            )
            let info = LiveFollowJoinInfo(code: response.code, hostDisplayName: response.hostDisplayName, initial: snapshot)
            return LiveFollowJoinResult(info: info, followToken: response.followToken)
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a `live_follow_poll`/`service_poll` body into the shared
    /// tolerant envelope — see this file's header.
    nonisolated public static func decodeLivePollUpdate(from data: Data) throws -> LivePollUpdate {
        do {
            return try JSONDecoder().decode(LivePollUpdate.self, from: data)
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a `service_join` success body into a `ServiceJoinResult`
    /// (public info + the engine-only `presenceToken`).
    nonisolated public static func decodeServiceJoin(from data: Data) throws -> ServiceJoinResult {
        struct Response: Decodable {
            let presenceToken: String
            let pollIntervalMs: Int?
            let currentSongId: String?
            let componentIndex: Int?
            let state: LiveBroadcastState?
            let revision: Int
        }
        do {
            let response = try JSONDecoder().decode(Response.self, from: data)
            let snapshot = LiveBroadcastSnapshot(
                songId: response.currentSongId,
                componentIndex: response.componentIndex,
                state: response.state,
                revision: response.revision
            )
            let info = ServiceJoinInfo(pollIntervalMs: response.pollIntervalMs, initial: snapshot)
            return ServiceJoinResult(info: info, presenceToken: response.presenceToken)
        } catch {
            throw APIError.decoding
        }
    }
}

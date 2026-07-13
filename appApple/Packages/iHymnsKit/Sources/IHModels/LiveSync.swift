// LiveSync.swift
// IHModels
//
// ELI5: The plain, safe-to-pass-around shapes for "who's live right now, on
// which song" — used by BOTH the Live Follow (leader + follower) feature and
// the Service Mode congregant feature, since they share the exact same
// server-side spine (`tblLiveFollowSessions`).
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §3.1-3.2; strategy §2.4.6; plan §2 row 10). These are the SERVER-mediated
// live-sync DTOs — distinct on disk (`IHLive/ServerLive/`) and in vocabulary
// from the peer-to-peer LAN remote's `IHRPDisplayState`
// (`IHRPPayloads.swift:64-68`, values `lyrics/blackout/logo/frozen`): the
// SERVER vocabulary below is `live/blackout/logo` only (`service_mode.php:63`).
// Never conflate the two — importing `IHRPDisplayState` here would be the
// exact cross-subsystem coupling strategy §2.4.1 forbids.
//
// D-13 (songId leniency, spec §3.2): `LiveBroadcastSnapshot.songId` stays a
// raw `String`, never `SongID` — a live catalogue has ~10 legacy ids that
// don't parse as `<letters>-<digits>` (`SongID.swift:120`), and tightening
// this DTO would make one legacy broadcast fail the decode of the WHOLE poll
// response. The UI layer parses `SongID(rawValue:)` at its own edge and
// renders a calm fallback row for the handful that don't.
import Foundation

/// The SERVER's display-intent vocabulary (`service_mode.php:63`) — NOT the
/// LAN remote's `IHRPDisplayState`. See this file's header.
///
/// ELI5: What the venue screen should be showing right now.
public enum ServiceDisplayState: String, Sendable, Codable, Equatable, CaseIterable {
    case live, blackout, logo
}

/// One broadcaster's raw `state` payload, decoded tolerantly — every field
/// is optional because `serviceMode_cleanState()` (`service_mode.php:140-178`)
/// only ever sends the keys that are actually set, and a forward-compat
/// broadcaster may send keys this client doesn't recognise yet.
///
/// ELI5: "Is the screen blanked? Which line? How far scrolled? Transposed by
/// how many steps?" — all optional, because not every broadcaster sets all
/// of them.
public struct LiveBroadcastState: Sendable, Equatable, Codable {
    /// Wire key `"displayState"` — kept RAW (not decoded straight to
    /// `ServiceDisplayState`) because an unrecognised future value must
    /// still decode successfully (spec §3.1) rather than fail the whole
    /// snapshot; `resolvedDisplayState` below is what actually interprets it.
    public let displayStateRaw: String?
    public let blank: Bool?
    public let lineIndex: Int?
    public let scrollPct: Double?
    public let transposeOffset: Int?

    public init(
        displayStateRaw: String? = nil,
        blank: Bool? = nil,
        lineIndex: Int? = nil,
        scrollPct: Double? = nil,
        transposeOffset: Int? = nil
    ) {
        self.displayStateRaw = displayStateRaw
        self.blank = blank
        self.lineIndex = lineIndex
        self.scrollPct = scrollPct
        self.transposeOffset = transposeOffset
    }

    private enum CodingKeys: String, CodingKey {
        case displayStateRaw = "displayState"
        case blank, lineIndex, scrollPct, transposeOffset
    }

    /// The client half of `serviceMode_cleanState()`'s bidirectional
    /// `blank`↔`displayState` bridge (`service_mode.php:118-160`, spec
    /// §3.1): a RECOGNISED `displayState` wins outright; otherwise an absent/
    /// unrecognised one falls back to the legacy `blank` boolean (`true` →
    /// `.blackout` — fail toward HIDDEN, the same direction the server
    /// bridge degrades `logo` for legacy clients); nothing at all → `.live`.
    /// The server ALWAYS writes `blank` alongside `displayState`
    /// (`:154-160`), so this fallback chain is total in practice — but the
    /// theoretical unknown-value/no-`blank` case still resolves sanely.
    public var resolvedDisplayState: ServiceDisplayState {
        if let displayStateRaw, let known = ServiceDisplayState(rawValue: displayStateRaw) {
            return known
        }
        if let blank {
            return blank ? .blackout : .live
        }
        return .live
    }
}

/// One point-in-time broadcast: which song, which section, what display
/// intent, and the monotonic revision it arrived at. NOT `Codable` — built
/// by the IHAPI decode/mapping layer (`LiveSyncDecoding.swift`), never
/// decoded wholesale from the wire itself.
///
/// ELI5: "What's showing right now, and how fresh is that information?"
public struct LiveBroadcastSnapshot: Sendable, Equatable {
    /// RAW server id — see this file's header (D-13).
    public let songId: String?
    public let componentIndex: Int?
    public let state: LiveBroadcastState?
    /// The server's `StateRevision`/`stateVersion` counter — monotonic
    /// per session; a follower never rewinds its display for a
    /// lower-or-equal revision (`LiveFollowerReducer`, `IHLive`).
    public let revision: Int

    public init(songId: String?, componentIndex: Int?, state: LiveBroadcastState?, revision: Int) {
        self.songId = songId
        self.componentIndex = componentIndex
        self.state = state
        self.revision = revision
    }
}

/// The public-facing result of a successful Live Follow `join` — everything
/// a follower screen needs. Deliberately does NOT carry the minted
/// `followToken` — that stays engine-internal custody (`LiveFollowEngine`,
/// spec §3.4-F), never a UI-facing type.
public struct LiveFollowJoinInfo: Sendable, Equatable {
    public let code: String
    public let hostDisplayName: String
    public let initial: LiveBroadcastSnapshot

    public init(code: String, hostDisplayName: String, initial: LiveBroadcastSnapshot) {
        self.code = code
        self.hostDisplayName = hostDisplayName
        self.initial = initial
    }
}

/// The public-facing result of a successful Service Mode `join`. Deliberately
/// does NOT carry the `presenceToken` — that stays engine-internal custody
/// (`ServiceModeEngine`, spec §3.5/D-5), never a UI-facing type.
public struct ServiceJoinInfo: Sendable, Equatable {
    /// The server-declared poll cadence (`api.php:14686`), if it sent one —
    /// `nil` on an un-migrated/older backend, in which case the follower
    /// spine's fallback literal applies (`LiveSyncConfiguration`).
    public let pollIntervalMs: Int?
    public let initial: LiveBroadcastSnapshot

    public init(pollIntervalMs: Int?, initial: LiveBroadcastSnapshot) {
        self.pollIntervalMs = pollIntervalMs
        self.initial = initial
    }
}

/// Why a hosting/following/presence session ended — drives the follower
/// screen's/status banner's copy (spec §6.3/§6.4).
///
/// ELI5: "Why did this live session stop?"
public enum LiveSyncEndReason: String, Sendable, Equatable {
    /// The user tapped Leave/End themselves.
    case userLeft
    /// The server said the session is gone (ended, expired, revoked).
    case serverEnded
    /// A NEW host session (this device or another) superseded this one
    /// (`live_follow_update`'s 409, `api.php:14026` — "one active session per
    /// host").
    case superseded
    /// The session's own freshness/expiry lapsed beyond recovery.
    case expired
    /// The signed-in host signed out — an unauthenticated host can't
    /// heartbeat (`live-follow.js:75-79` parity).
    case signedOut
}

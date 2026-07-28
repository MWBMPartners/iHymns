// LiveSyncEvents.swift
// IHLive/ServerLive
//
// ELI5: The two lists of "things that just happened" the Live Follow engine
// and the Service Mode engine each announce on their own stream — and the
// list of "why didn't that work" errors both engines can throw.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §3.4/§3.5). `AsyncStream` vocabularies — never Combine (repo-wide Swift 6
// posture). Neither enum EVER carries a raw join code or a raw token as a
// payload (D-15's "a token/code in any log/event" tripwire): `LiveFollowEvent
// .hostingStarted(code:)`/`.followingStarted(code:...)` carry the LIVE
// FOLLOW code (a deliberately-shared, on-screen 6-char value — showing it IS
// the feature, per `ShareLink` in `LiveHostControlsView`), which is NOT the
// same secrecy class as the two high-entropy tokens
// (`followToken`/`presenceToken`) — those never appear on ANY event, full
// stop; only `ServiceModeEngine.presenceTokenFingerprint()` may ever
// externalise a trace of the presence token, and only as an
// `IHLogSanitize.tokenFingerprint`.
import Foundation
import IHModels

/// Everything `LiveFollowEngine` (host + follower) announces on its
/// `events` stream.
public enum LiveFollowEvent: Sendable, Equatable {
    /// `goLive` succeeded — `code` is the shareable, on-screen host code.
    /// `sessionId` is `tblLiveFollowSessions.Id` (#1429 C6/C7 — `nil` only
    /// against a LEGACY backend that hasn't shipped `live_follow_create`'s
    /// `sessionId` field yet), the same id `LiveFollowEngine.hostSessionId`
    /// carries for the life of this hosting session — surfaced here too so
    /// `AppRootViewModel+LiveActivity.swift` (`IHFeatures`) can start a Live
    /// Activity + register its push token WITHOUT reaching back into the
    /// actor-isolated engine for it.
    case hostingStarted(code: String, sessionId: Int?)
    case hostingEnded(LiveSyncEndReason)
    /// `join` succeeded.
    case followingStarted(code: String, hostDisplayName: String, initial: LiveBroadcastSnapshot)
    case followingEnded(LiveSyncEndReason)
    /// A follower poll produced a genuinely new, higher-revision snapshot.
    case snapshot(LiveBroadcastSnapshot)
    /// Edge-triggered (fires only on a true/false TRANSITION, never per-tick)
    /// freshness change while following.
    case freshnessChanged(Bool)
}

/// Everything `ServiceModeEngine` announces on its `events` stream.
public enum ServiceModeEvent: Sendable, Equatable {
    case joined(initial: LiveBroadcastSnapshot)
    case snapshot(LiveBroadcastSnapshot)
    case left(LiveSyncEndReason)
    case freshnessChanged(Bool)
    /// Fired alongside `.joined`/`.left` whenever presence CUSTODY changes
    /// (set or cleared) — carries NO payload (the UI only ever needs "am I
    /// following," which `.joined`/`.left` already convey); exists as the
    /// one generic hook a future consumer could react to without ever being
    /// tempted to add a token-carrying case here.
    case presenceChanged
}

/// Failure modes either engine's `join`/`goLive` can throw — distinct from
/// the shared `IHAPI.APIError` taxonomy: these are LIVE-SYNC-specific
/// outcomes the engine derives FROM an `APIError` (or from its own mutual-
/// exclusivity rule), never raw transport/decode errors.
public enum LiveSyncError: Error, Sendable, Equatable {
    /// `goLive` called while already `.following` (web parity, "host wins").
    case alreadyHosting
    /// `join` called while already `.hosting`.
    case alreadyFollowing
    /// The server's opaque 404 — a wrong, expired, or unknown code. NEVER
    /// distinguishes which (anti-probe, matches the server's own opaque
    /// copy) — `api.php:14160`/`:14627`.
    case codeNotActive
    /// A 503 — the site itself is briefly down; distinct copy from
    /// `.codeNotActive` (`service-follow.js:92-95` parity: "the code is
    /// fine, try again in a minute").
    case temporarilyUnavailable
    /// Any other transient failure (offline, rate-limited, decoding, ...).
    case network
}

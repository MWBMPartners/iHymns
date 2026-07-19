// NowSingingActivity.swift
// IHLiveActivity
//
// ELI5: The exact shape of the little "now singing" card iOS shows on the
// Lock Screen / Dynamic Island while someone is hosting a Live Follow
// session — which song, which section, is it live, and a counter so the
// phone can tell a stale push from a fresh one. This file ALSO says how to
// build that shape from a live poll/broadcast snapshot, so there's exactly
// ONE place that turns "what the server just told us" into "what the card
// shows."
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3 "Live Activities +
// Dynamic Island (now-singing; host controls)"). `NowSingingActivityAttributes`
// is the STATIC half of an ActivityKit activity (what never changes across
// the activity's lifetime — who's hosting, under which code/session) and
// `ContentState` is the DYNAMIC half (what changes every broadcast). Apple's
// `ActivityAttributes` protocol conformance is added in a SEPARATE
// `#if canImport(ActivityKit)` extension below rather than on the primary
// declaration — `IHModels`' own charter is "pure DTOs, no framework deps"
// (this file's header note in the plan this implements), and `IHLiveActivity`
// itself must stay buildable on watchOS/tvOS/macOS (`Package.swift`'s
// `platforms:` list) where `ActivityKit` doesn't exist at all; gating the
// conformance keeps the plain struct usable everywhere while only actually
// promising `ActivityAttributes` where the framework is present.
//
// WIRE-SHAPE CONTRACT: `ContentState`'s `Codable` conformance MUST match
// `includes/live_activity_push.php`'s `liveActivityPush_contentState()`
// byte-for-byte — verified against the SAME committed fixture
// (`Tests/Fixtures/live_activity_content_state.json`) both sides read
// (`IHLiveActivityTests/NowSingingContentStateContractTests.swift` here,
// `tests/php/test-apns-live-activity-push.php` there). A `nil` optional
// field MUST encode as an ABSENT key, never a literal JSON `null` —
// `AuthEndpoints.swift`'s own header (`IHAPI`) already established that
// Swift's COMPILER-SYNTHESIZED `Encodable` conformance calls
// `encodeIfPresent(_:forKey:)` for every `Optional`-typed stored property
// automatically, which is exactly this behaviour; no custom `encode(to:)` is
// needed here (confirmed by the round-trip test in this target's contract
// test suite).
//
// CONTENT-GATING SAFETY (`.claude/CLAUDE.md`'s content-access rules):
// `ContentState` carries the song's TITLE and a numeric section INDEX only —
// never lyric text. A Live Activity is visible on the Lock Screen to anyone
// glancing at the phone, including a non-authenticated bystander; leaking
// copyrighted lyric text there would bypass every content-gating check the
// rest of the app enforces.
import Foundation
import IHModels

/// The STATIC half of a "now singing" Live Activity — who's hosting, and
/// under which session. Never changes for the life of one activity (start a
/// NEW activity, don't mutate these fields, if the underlying session
/// changes — see `NowSingingActivityReducer`).
///
/// ELI5: The activity's "ID card" — set once when the card first appears,
/// never edited after that.
public struct NowSingingActivityAttributes: Codable, Hashable, Sendable {
    /// Who this activity represents — today only `.host` is ever
    /// constructed by this client (`AppRootViewModel+LiveActivity.swift`
    /// only ever hosts its OWN Live Activity), but `.follower` is modelled
    /// now so a future "watch the leader's now-singing card" feature needs
    /// no wire-shape change.
    public enum SessionRole: String, Codable, Hashable, Sendable {
        case host
        case follower
    }

    public let role: SessionRole
    /// The shareable, on-screen Live Follow code (`LiveFollowEvent
    /// .hostingStarted(code:sessionId:)`) — `nil` only in the
    /// theoretically-unreachable case an activity is started before a code
    /// exists (defensive optionality, matching `NowSingingHostContext`'s own
    /// `sessionCode: String?`).
    public let sessionCode: String?
    /// `tblLiveFollowSessions.Id` — required by `?action=apns_register`'s
    /// `kind=liveActivity` branch (`api.php`'s own validation, verified
    /// against the live handler) so a minted push token can be scoped to
    /// THIS session. `nil` only before the create response has arrived.
    public let sessionId: Int?

    public init(role: SessionRole, sessionCode: String?, sessionId: Int?) {
        self.role = role
        self.sessionCode = sessionCode
        self.sessionId = sessionId
    }

    /// The DYNAMIC half — what changes every broadcast. See this file's
    /// header for the wire-shape contract this MUST match byte-for-byte.
    ///
    /// ELI5: "What should the card say right now" — which song, which
    /// section, is it live, and a counter so a late-arriving push can never
    /// rewind the card to older content.
    public struct ContentState: Codable, Sendable, Hashable {
        public let songId: String?
        public let songTitle: String?
        public let componentIndex: Int?
        /// Kept RAW (not decoded straight to `ServiceDisplayState`) for the
        /// SAME forward-compat reason `IHModels.LiveBroadcastState
        /// .displayStateRaw` is — an unrecognised future value must still
        /// decode successfully rather than fail the whole push.
        /// `resolvedDisplayState` below is what actually interprets it.
        public let displayState: String
        /// The server's `StateRevision` at push time — mirrors
        /// `LiveBroadcastSnapshot.revision`'s own "never rewind for a
        /// lower-or-equal revision" contract, applied to the ActivityKit
        /// push path instead of the poll path.
        public let revision: Int
        /// `false` only for the terminal "activity is ending" push.
        public let isLive: Bool

        public init(
            songId: String?,
            songTitle: String?,
            componentIndex: Int?,
            displayState: String,
            revision: Int,
            isLive: Bool
        ) {
            self.songId = songId
            self.songTitle = songTitle
            self.componentIndex = componentIndex
            self.displayState = displayState
            self.revision = revision
            self.isLive = isLive
        }

        /// The client-side mirror of `IHModels.LiveBroadcastState
        /// .resolvedDisplayState`'s fallback: an unrecognised/garbage raw
        /// value resolves to `.live` — fail toward "showing the last known
        /// song," the least surprising default for a card nobody has
        /// touched yet (same reasoning `liveActivityPush_resolveDisplayState()`
        /// documents server-side).
        public var resolvedDisplayState: ServiceDisplayState {
            ServiceDisplayState(rawValue: displayState) ?? .live
        }
    }
}

public extension NowSingingActivityAttributes.ContentState {
    /// A convenience mapping from a live poll/broadcast snapshot to a Live
    /// Activity `ContentState`. #1429 Audit-B F11 correction: `AppRootViewModel
    /// +LiveActivity.swift`'s `syncNowSingingActivity()` does NOT call this
    /// — `NowSingingActivityReducer`'s own private `contentState(for:isLive:)`
    /// builds the struct field-by-field from a `NowSingingHostContext`
    /// instead (there is no `LiveBroadcastSnapshot` at that call site). This
    /// initializer's actual callers today are `NowSingingContentStateContractTests
    /// .swift` (the wire-shape fixture round-trip this file's header
    /// describes); it's also the shape the PLANNED follower-side "watch the
    /// leader's now-singing card" activity (#1560, see `NowSingingActivityAttributes
    /// .SessionRole.follower`'s own doc comment) will consume once a
    /// follower has a real `LiveBroadcastSnapshot` to map from — kept for
    /// that reason rather than removed as dead code.
    ///
    /// ELI5: "Turn what the server just told us into what the card should
    /// show" — used by the tests today, and by a follower card that's
    /// planned but not built yet.
    init(snapshot: LiveBroadcastSnapshot, songTitle: String?, isLive: Bool) {
        self.init(
            songId: snapshot.songId,
            songTitle: songTitle,
            componentIndex: snapshot.componentIndex,
            displayState: (snapshot.state?.resolvedDisplayState ?? .live).rawValue,
            revision: snapshot.revision,
            isLive: isLive
        )
    }
}

// `canImport(ActivityKit)` ALONE isn't enough — the `ActivityKit` module is
// importable on macOS (e.g. for a Catalyst-adjacent toolchain resolution),
// but its `ActivityAttributes` PROTOCOL is itself `@available(macOS,
// unavailable)` (confirmed against the real framework interface: `swift
// build`'s plain macOS host build fails on this extension with
// `canImport(ActivityKit)` alone, `'ActivityAttributes' is unavailable in
// macOS`) — `tvOS`/`watchOS` mark the protocol unavailable too. `os(iOS)`
// is the actual gate every C9/C10 ActivityKit/AppIntents/WidgetKit file in
// this target also uses, applied here for the identical reason.
#if os(iOS) && canImport(ActivityKit)
import ActivityKit

/// Promises the real ActivityKit contract ONLY on iOS/iPadOS (see the
/// `#if` above for why `canImport(ActivityKit)` alone isn't the right
/// gate). `ContentState` already satisfies `ActivityAttributes`'s
/// associated-type requirement (`Codable, Hashable`) with zero extra code.
extension NowSingingActivityAttributes: ActivityAttributes {}
#endif

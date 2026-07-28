// NowSingingActivityReducer.swift
// IHLiveActivity
//
// ELI5: Compares "what the host's Live Activity card showed a moment ago"
// with "what it should show now" and decides the ONE right thing to do:
// start a brand-new card, update the existing one, end it, or do nothing at
// all.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). A PURE function
// (`NowSingingActivityReducer.command(previous:current:)`) — no ActivityKit
// import, no side effects, independently unit-testable with zero device/
// simulator dependency (mirrors `LiveFollowerReducer`'s own "reducer owns
// the DECISION, the engine/controller owns the EFFECT" split, `IHLive/
// ServerLive/LiveFollowerReducer.swift`). `AppRootViewModel
// +LiveActivity.swift`'s `syncNowSingingActivity()` is the ONE caller: it
// diffs `nowSingingContext` old→new through this function, then forwards
// whatever `NowSingingActivityCommand` comes back to the injected
// `NowSingingActivityControlling` — this file never touches that protocol
// or any controller directly.
//
// `displayState` on every synthesized `ContentState` here is always
// `.live` — the HOST side of Live Follow (this feature) never tracks a
// blackout/logo display intent locally; that's the Service Mode OPERATOR
// console's concern (a distinct, deferred feature — see
// `AppRootViewModel+LiveSync.swift`'s own "Deviations from spec" note), not
// something a hosting device broadcasts about itself.
import Foundation
import IHModels

/// Everything the reducer needs to know about "what the host's card should
/// currently show" — a plain value type, diffed by `NowSingingActivityReducer
/// .command(previous:current:)` old vs. new. `nil` means "not hosting right
/// now" (no card should exist).
///
/// ELI5: A little snapshot of "session code, which song, which section" —
/// compared against the previous snapshot to decide what changed.
public struct NowSingingHostContext: Sendable, Equatable {
    public var sessionCode: String?
    public var sessionId: Int?
    public var songId: String?
    public var songTitle: String?
    public var componentIndex: Int?
    public var revision: Int

    public init(
        sessionCode: String?,
        sessionId: Int?,
        songId: String?,
        songTitle: String?,
        componentIndex: Int?,
        revision: Int
    ) {
        self.sessionCode = sessionCode
        self.sessionId = sessionId
        self.songId = songId
        self.songTitle = songTitle
        self.componentIndex = componentIndex
        self.revision = revision
    }
}

/// The one thing to do to the Live Activity, as decided by
/// `NowSingingActivityReducer.command(previous:current:)`. `NowSingingActivityControlling
/// .apply(_:)` (`IHFeatures`' `AppRootViewModel+LiveActivity.swift` calls
/// into it) is the ONE consumer.
public enum NowSingingActivityCommand: Sendable, Equatable {
    /// Start a brand-new activity — `nil` context → a real one.
    case start(NowSingingActivityAttributes, NowSingingActivityAttributes.ContentState)
    /// Update the existing activity's dynamic content.
    case update(NowSingingActivityAttributes.ContentState)
    /// End the activity — the FINAL content state to show as it dismisses
    /// (`isLive: false`).
    case end(final: NowSingingActivityAttributes.ContentState)
    /// Nothing changed (or both old and new are `nil`) — no-op.
    case none
}

/// The pure decision function — see this file's header.
public enum NowSingingActivityReducer {
    /// - Parameters:
    ///   - previous: The context BEFORE this event (`nil` = wasn't hosting
    ///     a card).
    ///   - current: The context AFTER this event (`nil` = no longer
    ///     hosting).
    /// - Returns: Exactly one of `.start`/`.update`/`.end`/`.none` — see
    ///   each case's own doc comment.
    public static func command(
        previous: NowSingingHostContext?,
        current: NowSingingHostContext?
    ) -> NowSingingActivityCommand {
        switch (previous, current) {
        case (nil, nil):
            return .none

        case (nil, .some(let context)):
            let attributes = NowSingingActivityAttributes(
                role: .host,
                sessionCode: context.sessionCode,
                sessionId: context.sessionId
            )
            return .start(attributes, contentState(for: context, isLive: true))

        case (.some(let context), nil):
            return .end(final: contentState(for: context, isLive: false))

        case (.some(let old), .some(let new)):
            guard old != new else { return .none }
            /* #1429 Audit-B F1 — a SESSION-IDENTITY change (a fresh
               `goLive`/`live_follow_create` superseding a prior hosting
               session, or the server minting a new sessionCode for the
               SAME sessionId) must RESTART the activity, not `.update` it
               in place: `NowSingingActivityAttributes.sessionCode`/
               `.sessionId` are the STATIC half (this file's header),
               fixed for an activity's whole lifetime, so an in-place
               `.update` could never actually change them even if we tried
               — the OLD session's stale attributes would keep showing.
               `.start` is safe to return unconditionally here (rather than
               `.end`-then-`.start`) because `NowSingingActivityController
               .start(attributes:state:)` (`IHLiveActivity`) ALREADY ends
               any running activity first before starting the new one. */
            if old.sessionId != new.sessionId || old.sessionCode != new.sessionCode {
                let attributes = NowSingingActivityAttributes(
                    role: .host,
                    sessionCode: new.sessionCode,
                    sessionId: new.sessionId
                )
                return .start(attributes, contentState(for: new, isLive: true))
            }
            return .update(contentState(for: new, isLive: true))
        }
    }

    /// Shared `ContentState` assembly — see this file's header for why
    /// `displayState` is always `.live` here.
    private static func contentState(
        for context: NowSingingHostContext,
        isLive: Bool
    ) -> NowSingingActivityAttributes.ContentState {
        NowSingingActivityAttributes.ContentState(
            songId: context.songId,
            songTitle: context.songTitle,
            componentIndex: context.componentIndex,
            displayState: ServiceDisplayState.live.rawValue,
            revision: context.revision,
            isLive: isLive
        )
    }
}

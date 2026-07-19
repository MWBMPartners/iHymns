// NowSingingLiveActivityWidget.swift
// IHLiveActivity
//
// ELI5: What the "now singing" card actually LOOKS like — on the Lock
// Screen and in the Dynamic Island. It shows the song title and which
// section is up, a LIVE pill, and (only for the host) Next/Previous
// buttons — and never, ever the lyrics themselves.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). `#if os(iOS) &&
// canImport(ActivityKit)` — Live Activities/Dynamic Island are iOS/iPadOS-
// only (verified against the real `ActivityKit`/`WidgetKit` module
// interfaces, iOS 26 SDK, via a standalone typecheck), so this whole file
// is a no-op on watchOS/tvOS/macOS, matching every other C9/C10 file in
// this target. `Apps/iHymnsWidgets/Sources/IHymnsWidgetsBundle.swift` adds
// `NowSingingLiveActivityWidget()` to its `WidgetBundle` body — this type
// itself is `public` so that file (a DIFFERENT target) can construct it.
//
// CONTENT-GATING SAFETY (repeats this target's own `NowSingingActivity
// .swift` header, worth restating right next to the actual render code):
// a Live Activity is visible on the Lock Screen to anyone glancing at the
// phone, logged in or not — so this view renders ONLY `state.songTitle`
// (a title, not copyrighted lyric text) and a numeric section index.
// `.claude/CLAUDE.md`'s content-gating rules never apply lower than "the
// song is playing," and this card never crosses that line.
//
// DESIGN: `IHColorTokens.accent` (`IHDesign`) for the LIVE pill/glyph/
// button tint — the SAME accent every other iHymnsKit surface uses, never
// a duplicated colour literal (repo modularity rule). `resolvedDisplayState
// != .live` (blackout/logo — the projector operator's own state, mirrored
// here even though THIS feature's host never sets it locally, see
// `NowSingingActivityReducer.swift`'s header) dims the whole card via
// `.opacity` rather than hiding it outright — the card should still read
// as "a session is happening," just visually quieter.
#if os(iOS) && canImport(ActivityKit)
import ActivityKit
import IHDesign
import SwiftUI
import WidgetKit

/// The Live Activity/Dynamic Island `Widget` itself — see this file's
/// header for who constructs it.
public struct NowSingingLiveActivityWidget: Widget {
    public init() {}

    public var body: some WidgetConfiguration {
        ActivityConfiguration(for: NowSingingActivityAttributes.self) { context in
            NowSingingLockScreenView(attributes: context.attributes, state: context.state, isStale: context.isStale)
                .activityBackgroundTint(Color.black.opacity(0.85))
                .activitySystemActionForegroundColor(IHColorTokens.accent)
        } dynamicIsland: { context in
            DynamicIsland {
                DynamicIslandExpandedRegion(.leading) {
                    // #1429 Audit-B F8 — dim the expanded island's glyph +
                    // title (mirrors the Lock Screen card's own dimming)
                    // when `context.isStale` (ActivityKit-computed from the
                    // `staleDate` `NowSingingActivityController` sets on
                    // every start/update): a dead host's card should read
                    // as visually quieter, not still-confidently "LIVE".
                    NowSingingGlyph(state: context.state)
                        .opacity(context.isStale ? 0.55 : 1)
                }
                DynamicIslandExpandedRegion(.trailing) {
                    NowSingingLivePill(state: context.state, isStale: context.isStale)
                }
                DynamicIslandExpandedRegion(.center) {
                    NowSingingTitleText(state: context.state)
                        .opacity(context.isStale ? 0.55 : 1)
                }
                DynamicIslandExpandedRegion(.bottom) {
                    if context.attributes.role == .host {
                        NowSingingHostControls()
                    }
                }
            } compactLeading: {
                NowSingingGlyph(state: context.state)
            } compactTrailing: {
                NowSingingSectionBadge(state: context.state)
            } minimal: {
                NowSingingGlyph(state: context.state)
            }
        }
    }
}

// MARK: - Lock Screen

/// The full Lock Screen card: glyph + title/section + LIVE pill, and (host
/// only) the Next/Previous row underneath.
private struct NowSingingLockScreenView: View {
    let attributes: NowSingingActivityAttributes
    let state: NowSingingActivityAttributes.ContentState
    /// #1429 Audit-B F8 — `ActivityViewContext.isStale`, `true` once
    /// ActivityKit decides the last pushed `staleDate` has passed (the SAME
    /// 180s window `NowSingingActivityController` sets on every start/
    /// update). Neither side previously READ this — both set the stale
    /// date, but the card kept showing a confident "LIVE" for a host that
    /// had gone dark for hours.
    let isStale: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                NowSingingGlyph(state: state)
                NowSingingTitleText(state: state)
                Spacer()
                NowSingingLivePill(state: state, isStale: isStale)
            }
            if attributes.role == .host {
                NowSingingHostControls()
            }
        }
        .padding()
        // Blackout/logo dims the whole card — see this file's header. A
        // stale card dims the SAME way (F8, above).
        .opacity((state.resolvedDisplayState == .live && !isStale) ? 1 : 0.55)
    }
}

// MARK: - Shared small pieces (reused across Lock Screen + Dynamic Island)

/// Song title + 1-based section number. NEVER lyric text — see this file's
/// header.
private struct NowSingingTitleText: View {
    let state: NowSingingActivityAttributes.ContentState

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(state.songTitle ?? "iHymns")
                .font(.headline)
                .lineLimit(1)
            if let componentIndex = state.componentIndex {
                // Broadcast 0-based (`LiveBroadcastSnapshot.componentIndex`
                // convention, `IHModels`); shown 1-based, matching every
                // other section-number display in this app.
                Text("Section \(componentIndex + 1)")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
    }
}

/// The small "LIVE"/"Reconnecting…" badge — shown while `state.isLive`.
/// #1429 Audit-B F8 — swaps to "Reconnecting…" while `isStale`, rather than
/// keep asserting "LIVE" for a host that's gone dark.
private struct NowSingingLivePill: View {
    let state: NowSingingActivityAttributes.ContentState
    let isStale: Bool

    var body: some View {
        if isStale {
            Text("Reconnecting…")
                .font(.caption2.weight(.semibold))
                .padding(.horizontal, 6)
                .padding(.vertical, 2)
                .background(Color.secondary, in: Capsule())
                .foregroundStyle(.white)
        } else if state.isLive {
            Text("LIVE")
                .font(.caption2.weight(.bold))
                .padding(.horizontal, 6)
                .padding(.vertical, 2)
                .background(IHColorTokens.accent, in: Capsule())
                .foregroundStyle(.white)
                // #1429 Audit-B F7 — redundant with the row itself already
                // being a live, visible "now singing" card (VoiceOver
                // doesn't need "LIVE" spoken on top of the song title it
                // already reads, mirrors the repo's #680/#856 badge-a11y
                // precedent of folding a redundant badge out of the AX
                // tree). The "Reconnecting…" branch above stays fully
                // accessible — it conveys genuinely new information a
                // VoiceOver user has no other way to learn.
                .accessibilityHidden(true)
        }
    }
}

/// A tiny music-note glyph, dimmed the same way the whole card dims for a
/// non-`.live` `displayState`.
private struct NowSingingGlyph: View {
    let state: NowSingingActivityAttributes.ContentState

    var body: some View {
        Image(systemName: "music.note")
            .foregroundStyle(state.resolvedDisplayState == .live ? IHColorTokens.accent : Color.secondary)
            // #1429 Audit-B F7 — purely decorative; `NowSingingTitleText`
            // (the song title + section) is the actual accessible content
            // everywhere this glyph is shown alongside it.
            .accessibilityHidden(true)
    }
}

/// The Dynamic Island's compact-trailing slot — the section number when
/// known, else a bare "LIVE" marker.
private struct NowSingingSectionBadge: View {
    let state: NowSingingActivityAttributes.ContentState

    var body: some View {
        if let componentIndex = state.componentIndex {
            Text("\(componentIndex + 1)")
                .font(.caption2)
        } else if state.isLive {
            Text("LIVE")
                .font(.caption2)
        }
    }
}

/// The Next/Previous row — `LiveActivityIntent`-backed buttons
/// (`NowSingingIntents.swift`) so a tap runs WITHOUT opening the app.
/// Shown only when `attributes.role == .host` (a follower's own future
/// "watch the leader" card, spec'd but not built yet, gets no controls —
/// see `NowSingingActivityAttributes.SessionRole`'s own doc comment).
private struct NowSingingHostControls: View {
    var body: some View {
        HStack {
            Button(intent: NowSingingPreviousSectionIntent()) {
                Image(systemName: "chevron.left")
            }
            // #1429 Audit-B F7 — icon-only button; VoiceOver would
            // otherwise read the SF Symbol's raw name instead of its
            // actual function.
            .accessibilityLabel("Previous section")
            Spacer()
            Button(intent: NowSingingNextSectionIntent()) {
                Image(systemName: "chevron.right")
            }
            .accessibilityLabel("Next section")
        }
        .tint(IHColorTokens.accent)
    }
}
#endif

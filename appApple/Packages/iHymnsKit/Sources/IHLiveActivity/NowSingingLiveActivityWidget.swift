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
            NowSingingLockScreenView(attributes: context.attributes, state: context.state)
                .activityBackgroundTint(Color.black.opacity(0.85))
                .activitySystemActionForegroundColor(IHColorTokens.accent)
        } dynamicIsland: { context in
            DynamicIsland {
                DynamicIslandExpandedRegion(.leading) {
                    NowSingingGlyph(state: context.state)
                }
                DynamicIslandExpandedRegion(.trailing) {
                    NowSingingLivePill(state: context.state)
                }
                DynamicIslandExpandedRegion(.center) {
                    NowSingingTitleText(state: context.state)
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

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                NowSingingGlyph(state: state)
                NowSingingTitleText(state: state)
                Spacer()
                NowSingingLivePill(state: state)
            }
            if attributes.role == .host {
                NowSingingHostControls()
            }
        }
        .padding()
        // Blackout/logo dims the whole card — see this file's header.
        .opacity(state.resolvedDisplayState == .live ? 1 : 0.55)
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

/// The small "LIVE" badge — shown only while `state.isLive`.
private struct NowSingingLivePill: View {
    let state: NowSingingActivityAttributes.ContentState

    var body: some View {
        if state.isLive {
            Text("LIVE")
                .font(.caption2.weight(.bold))
                .padding(.horizontal, 6)
                .padding(.vertical, 2)
                .background(IHColorTokens.accent, in: Capsule())
                .foregroundStyle(.white)
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
            Spacer()
            Button(intent: NowSingingNextSectionIntent()) {
                Image(systemName: "chevron.right")
            }
        }
        .tint(IHColorTokens.accent)
    }
}
#endif

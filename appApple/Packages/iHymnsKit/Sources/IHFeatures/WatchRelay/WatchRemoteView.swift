// WatchRemoteView.swift
// IHFeatures/WatchRelay
//
// ELI5: What the watch actually shows — either the little remote (Prev /
// Next / Lyrics / Black / Logo) if there's a TV to drive, or a plain
// "go do this on your iPhone first" screen if there isn't yet.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §5 —
// BINDING; strategy §2.4.2's "reduced Watch controls (Next/Prev/Blackout/
// Logo/status)"). `#if os(watchOS) && canImport(WatchConnectivity)` —
// MATCHES `WatchRemoteController`'s own gate exactly (this module's
// sibling file); this view only ever exists alongside the controller it
// renders.
//
// **watchOS-clean by construction (fact 13):** `NavigationStack`/
// `ScrollView`/`VStack`/`HStack`/`Button`/`Label`/`Text`/`Image`/
// `ProgressView` only — no `Menu`, no `.pickerStyle(.segmented)`, no
// `DisclosureGroup`, no drag/drop, no `.keyboardShortcut`, no
// `ToolbarItemPlacement.navigation` (all UNAVAILABLE or nonsensical on
// watchOS; `RemoteControlSurfaceView.swift`'s own `#if os(watchOS)`
// fallbacks are the cautionary precedent for what happens when a
// non-watch-clean control leaks onto this platform). No `ihGlassCard()`
// either — that's `RemoteControlSurfaceView`'s phone/tvOS-scale visual
// language, not this screen's.
//
// **Stateless/reflective, one hop longer (§5, `RemoteControlSurfaceView`'s
// contract verbatim):** nothing here toggles locally. Every visible state
// — the header, the transient overlay, the display-row highlight — comes
// ONLY from `controller.snapshot`/`.transientNotice`/`.isWakingPhone`,
// which themselves only change on a reply or a push from the iPhone. A
// button tap sends an intent and waits; it never optimistically redraws.
import IHLive
import SwiftUI
#if os(watchOS) && canImport(WatchConnectivity)

/// The watch's one screen — see this file's header for the full picture.
///
/// ELI5: "Show whatever's true right now: the remote, or what to go do on
/// the iPhone first."
public struct WatchRemoteView: View {
    let controller: WatchRemoteController

    public init(controller: WatchRemoteController) {
        self.controller = controller
    }

    public var body: some View {
        switch controller.snapshot.phase {
        case .controlling, .standby, .connecting:
            remoteScreen
        case .pairing:
            WatchRemoteGuidanceView(
                systemImage: nil,
                title: "Finish pairing on your iPhone",
                caption: "Complete the code or fingerprint step on your iPhone, then this remote takes over.",
                notReachableNotice: notReachableNotice
            )
        case .noSavedTV:
            WatchRemoteGuidanceView(
                systemImage: "appletv",
                title: "No TV paired",
                caption: "Open iHymns on your iPhone and connect to a TV once; after that, this watch can drive it even with the phone in your pocket.",
                notReachableNotice: notReachableNotice
            )
        }
    }

    /// §5's guidance-screen reachability line — reachability drives COPY
    /// only here (never gates `send`, §4.5's own invariant), so reading it
    /// is pure presentation, no side effect.
    private var notReachableNotice: String? {
        guard !controller.isPhoneReachable, controller.isActivated else { return nil }
        return "iPhone not reachable. Bring your iPhone nearby."
    }

    // MARK: - The remote (`.controlling`/`.standby`/`.connecting`)

    /// One non-scrolling screen on 45 mm; `ScrollView` absorbs the overflow
    /// on smaller watches (§5).
    private var remoteScreen: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                statusHeader
                transientOverlay
                navigationRow
                displayRow
            }
            .padding(.horizontal, 2)
        }
    }

    // MARK: - 1. Status header (2 lines)

    private var statusHeader: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(controller.snapshot.tvName ?? "TV")
                .font(.headline)
            statusSubline
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .accessibilityElement(children: .combine)
    }

    /// Line 2's copy is phase-specific (fact 20 parity on `.controlling`;
    /// an honest "Ready"/"Connecting…" tell otherwise).
    @ViewBuilder
    private var statusSubline: some View {
        switch controller.snapshot.phase {
        case .controlling:
            Text(controller.snapshot.songRef ?? "Nothing selected")
                .font(.footnote)
                .foregroundStyle(.secondary)
        case .standby:
            VStack(alignment: .leading, spacing: 2) {
                Text("Ready")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                Text("Taps wake your iPhone to reach the TV.")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }
        case .connecting:
            HStack(spacing: 4) {
                ProgressView()
                Text("Connecting to \(controller.snapshot.tvName ?? "TV")…")
            }
            .font(.footnote)
            .foregroundStyle(.secondary)
        case .pairing, .noSavedTV:
            // Unreachable in practice — `body`'s own switch never routes
            // these two phases into `remoteScreen`; kept here only so this
            // nested switch stays exhaustive over every `Phase` case.
            EmptyView()
        }
    }

    // MARK: - 2. Transient overlay (below the header, `.footnote`)

    /// Auto-cleared on the next reply/push — never cleared by THIS view;
    /// see `WatchRemoteController.swift`'s header for why a push must not
    /// wipe an honest failure reason before it's been read.
    @ViewBuilder
    private var transientOverlay: some View {
        if controller.isWakingPhone {
            HStack(spacing: 4) {
                ProgressView()
                Text("Waking iPhone…")
            }
            .font(.footnote)
        } else if let notice = controller.transientNotice {
            Text(notice)
                .font(.footnote)
                .foregroundStyle(.secondary)
        }
    }

    // MARK: - 3. Prev/Next row

    private var navigationRow: some View {
        HStack(spacing: 8) {
            navigationButton(label: "Previous verse", systemImage: "chevron.left") {
                controller.send(.prevComponent)
            }
            navigationButton(label: "Next verse", systemImage: "chevron.right") {
                controller.send(.nextComponent)
            }
        }
    }

    private func navigationButton(label: String, systemImage: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: systemImage)
                .frame(maxWidth: .infinity, minHeight: 44)
        }
        .buttonStyle(.borderedProminent)
        .accessibilityLabel(label)
    }

    // MARK: - 4. Display row (Lyrics / Black / Logo)

    private var displayRow: some View {
        HStack(spacing: 6) {
            displayButton(title: "Lyrics", isSelected: isDisplaySelected(.lyrics)) {
                controller.send(.showLyrics)
            }
            displayButton(title: "Black", isSelected: isDisplaySelected(.blackout)) {
                controller.send(.showBlackout)
            }
            displayButton(title: "Logo", isSelected: isDisplaySelected(.logo)) {
                controller.send(.showLogo)
            }
        }
    }

    /// `nil`/unknown/`.frozen` (the operator-only fourth state, deliberately
    /// OUT of the watch's reduced control set, D-5) highlight NOTHING — only
    /// an EXACT `rawValue` match to one of the three buttons this remote
    /// actually offers ever lights up.
    private func isDisplaySelected(_ state: IHRPDisplayState) -> Bool {
        controller.snapshot.displayState == state.rawValue
    }

    /// `buttonStyle`'s underlying type differs per branch
    /// (`.borderedProminent` vs. `.bordered`) — an inline ternary can't
    /// choose between two DIFFERENT `ButtonStyle` conformances, so this
    /// `@ViewBuilder` branches on `isSelected` instead.
    @ViewBuilder
    private func displayButton(title: String, isSelected: Bool, action: @escaping () -> Void) -> some View {
        if isSelected {
            Button(action: action) {
                Text(title).frame(maxWidth: .infinity, minHeight: 44)
            }
            .buttonStyle(.borderedProminent)
            .accessibilityAddTraits(.isSelected)
        } else {
            Button(action: action) {
                Text(title).frame(maxWidth: .infinity, minHeight: 44)
            }
            .buttonStyle(.bordered)
        }
    }
}

#Preview {
    WatchRemoteView(controller: WatchRemoteController())
}
#endif

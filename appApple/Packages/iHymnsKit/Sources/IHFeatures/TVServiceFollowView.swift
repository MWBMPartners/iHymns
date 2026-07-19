// TVServiceFollowView.swift
// IHFeatures
//
// ELI5: The actual tvOS "Live" tab screen — type in the venue's code (or
// the operator's), then watch whatever the service is showing appear
// full-bleed, exactly like the projector screen already does for a Siri
// Remote operator, except now driven entirely by the server.
//
// DETAILED: Apple Phase-2 PR-15 (#1428; `.claude/apple-native-strategy.md`
// §2.4.7). Renders `TVServiceFollowCoordinator.phase` — `.idle`/`.joining`
// and `.ended` share ONE join-form layout (differing only in header copy +
// button label), because `TVServiceFollowCoordinator` has NO "dismiss the
// ended screen" API of its own (its `phase(after:current:)` table only ever
// LEAVES `.ended` via a fresh `join(code:)` call succeeding or failing —
// see that type's own header); unifying the two avoids inventing extra
// view-local "am I showing the ended screen or not" state purely to get
// back to a form that looks identical either way. `.following` reuses
// `ProjectionCanvasView` fed from `viewModel.renderedContent` — the SAME
// canvas + "read from `renderedContent`, not a separate copy" convention
// `ProjectionSceneView.canvas` already establishes — deliberately NOT
// `ProjectionSceneView` itself (that view's Siri-Remote driver + local song
// picker have no place here: this surface is server-driven only, per D-4/
// the content-gating-safe rule this file's PR header states — the sync wire
// carries navigation intent only, never a local operator picking songs).
//
// `#if os(tvOS)` guards only the genuinely-tvOS-only modifiers
// (`.onTapGesture`'s reveal gesture starts hidden ONLY on tvOS, mirroring
// `ProjectionSceneView`'s identical `isStripVisible` posture) — this file
// still compiles for the macOS `swift build`/`swift test` gate.
import IHDesign
import SwiftUI

/// The tvOS Live tab: join form → server-following projection surface →
/// ended screen. See this file's header for the phase→layout mapping.
struct TVServiceFollowView: View {
    let projectionViewModel: ProjectionViewModel
    let coordinator: TVServiceFollowCoordinator

    @State private var codeText = ""

    /// #1562 A-2: drives the status-strip auto-reveal below — a VoiceOver
    /// user has no reliable way to discover/trigger the bare `.onTapGesture`
    /// on the `.focusable()` `followingSurface` ZStack (`.focusable()`'s
    /// OWN activation is a Siri Remote click, which VoiceOver intercepts
    /// for its own navigation, and the strip holds the ONLY "Leave" control
    /// on this whole screen).
    @Environment(\.accessibilityVoiceOverEnabled) private var isVoiceOverRunning

    /// Whether the translucent status strip is showing while following —
    /// same "starts hidden on tvOS, a tap reveals it; always visible
    /// elsewhere" posture `ProjectionSceneView.isStripVisible` establishes
    /// (there is no remote-swipe/tap gesture to reveal it with on any other
    /// platform this package also builds for).
    @State private var isStripVisible: Bool

    init(projectionViewModel: ProjectionViewModel, coordinator: TVServiceFollowCoordinator) {
        self.projectionViewModel = projectionViewModel
        self.coordinator = coordinator
        #if os(tvOS)
        _isStripVisible = State(initialValue: false)
        #else
        _isStripVisible = State(initialValue: true)
        #endif
    }

    var body: some View {
        content
            .onAppear { coordinator.setSurfaceVisible(true) }
            .onDisappear { coordinator.setSurfaceVisible(false) }
            // #1562 A-3: a failed `join(code:)` only ever shows up as a red
            // `Text` next to the code field — silent to VoiceOver, which
            // doesn't re-read a view that merely changed its text.
            .onChange(of: coordinator.joinErrorMessage) { _, newValue in
                guard let newValue else { return }
                AccessibilityNotification.Announcement(newValue).post()
            }
    }

    @ViewBuilder
    private var content: some View {
        switch coordinator.phase {
        case .idle, .joining, .ended:
            joinForm
        case .following:
            followingSurface
        }
    }

    // MARK: - .idle / .joining / .ended — one join-form layout

    private var joinForm: some View {
        VStack(spacing: 24) {
            Text("Join a Service").font(.title.bold())
            if case .ended = coordinator.phase {
                Text("The service has ended.").font(.title3).foregroundStyle(.secondary)
            }
            TextField("Code", text: $codeText)
                #if os(iOS) || os(visionOS)
                .textInputAutocapitalization(.characters)
                #endif
                .autocorrectionDisabled()
                .font(.system(.title2, design: .monospaced))
                .frame(maxWidth: 400)
                // #1562 A-4: bare placeholder text ("Code") is all
                // VoiceOver would otherwise read for this field.
                .accessibilityLabel("Join code")
            if let joinErrorMessage = coordinator.joinErrorMessage {
                Text(joinErrorMessage).foregroundStyle(.red)
            } else {
                Text("Enter the code from the service leader.")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            Button(joinButtonTitle) { Task { await coordinator.join(code: codeText) } }
                .disabled(codeText.trimmingCharacters(in: .whitespaces).isEmpty || coordinator.phase == .joining)
        }
        .padding(60)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    private var joinButtonTitle: String {
        if case .ended = coordinator.phase { return "Join Another Code" }
        return "Join"
    }

    // MARK: - .following — the projection canvas + tap-to-reveal status strip

    private var followingSurface: some View {
        ZStack(alignment: .bottom) {
            canvas
            if !coordinator.hasSnapshotSong {
                ContentUnavailableView("Waiting for the first song…", systemImage: "music.note")
            }
            // #1562 A-2: the strip holds the ONLY "Leave" control on this
            // screen — auto-reveal it under VoiceOver rather than relying
            // on a sighted-only tap-to-discover gesture (below).
            if isStripVisible || isVoiceOverRunning { statusStrip }
            if let songNotice = coordinator.songNotice { noticeOverlay(songNotice) }
        }
        #if os(tvOS)
        .focusable()
        .onTapGesture { isStripVisible.toggle() }
        // Defensive backup to the auto-reveal above, for any assistive
        // technology other than VoiceOver that surfaces custom actions
        // instead of raw taps.
        .accessibilityAction(named: "Show controls") { isStripVisible = true }
        .accessibilityHint("Shows the Leave button and connection status.")
        #endif
    }

    /// Mirrors `ProjectionSceneView.canvas` exactly — read `renderedContent`
    /// (never `song`/`position` separately), so a `.frozen` snapshot (should
    /// one ever be set — this surface has no local freeze control of its
    /// own today) renders identically here too.
    private var canvas: some View {
        let rendered = projectionViewModel.renderedContent
        return ProjectionCanvasView(
            song: rendered.song,
            componentIndex: rendered.position.componentIndex,
            lineIndex: rendered.position.lineIndex,
            displayState: projectionViewModel.displayState,
            isLoading: projectionViewModel.isLoadingSong,
            textScale: projectionViewModel.textScale
        )
    }

    private var statusStrip: some View {
        HStack(spacing: 20) {
            Label("Following the service", systemImage: "dot.radiowaves.left.and.right")
                .foregroundStyle(IHColorTokens.accent)
            Text(isFresh ? "Live" : "Reconnecting…")
                .font(.caption)
                .foregroundStyle(.secondary)
                // #1562 A-3: this text silently flips between "Live" and
                // "Reconnecting…" as freshness changes — flag it as
                // continuously updating for assistive tech.
                .accessibilityAddTraits(.updatesFrequently)
            Button("Leave", role: .destructive) { Task { await coordinator.leave() } }
        }
        .padding()
        .ihGlassCard()
        .padding(.bottom, 40)
    }

    private var isFresh: Bool {
        guard case .following(let isFresh) = coordinator.phase else { return false }
        return isFresh
    }

    private func noticeOverlay(_ message: String) -> some View {
        Text(message)
            .foregroundStyle(.white)
            .padding()
            .ihGlassCard()
            .padding(.top, 40)
    }
}

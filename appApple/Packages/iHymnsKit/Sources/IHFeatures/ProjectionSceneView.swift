// ProjectionSceneView.swift
// IHFeatures
//
// ELI5: The actual tvOS "Project" screen — the full-bleed canvas from
// `ProjectionCanvasView`, PLUS the Siri Remote acting as the FIRST driver of
// `ProjectionViewModel` (swipe to move through the song, press Play/Pause to
// panic-blackout), PLUS a translucent control strip and a song picker so an
// operator can actually get a song on screen in the first place.
//
// DETAILED: #1504 (`.claude/apple-phase2-pr5-spec.md` §5.2). This is the
// FIRST of the seam's three eventual drivers (§1: Siri Remote here, the LAN
// bridge in PR-6, a server-poll loop in PR-15) — every intent below calls
// the EXACT SAME `ProjectionViewModel` methods PR-6's bridge will call from
// `TVListenerActor.controlEvents`, proving the seam is genuinely
// driver-agnostic rather than accidentally Siri-Remote-shaped.
//
// The Siri-Remote-only modifiers (`.onMoveCommand`/`.onPlayPauseCommand`,
// tvOS-exclusive SwiftUI APIs) are `#if os(tvOS)`-guarded so this file still
// compiles for the macOS `swift test`/build gate (spec §5.2) — the tap-to-
// reveal-strip gesture is guarded alongside them, since `isStripVisible`
// starts already-`true` on every OTHER platform (there is no remote swipe
// to "reveal" it with there; strategy §2.2's projector role is tvOS-only).
import IHDesign
import IHLive
import IHModels
import SwiftUI

/// The tvOS projection screen: canvas + Siri-Remote driver + control strip
/// + song picker.
public struct ProjectionSceneView: View {
    let rootViewModel: AppRootViewModel
    let viewModel: ProjectionViewModel

    /// Whether the translucent control strip is showing — starts hidden on
    /// tvOS (a Siri-Remote tap reveals it, strategy §2.2), always visible
    /// everywhere else (previews/other platforms have no such gesture).
    @State private var isStripVisible: Bool

    /// Manually opened via the "Choose Song" strip button — `showPicker`
    /// below ALSO forces this true whenever no song is loaded at all, so
    /// the picker can't be dismissed away from a genuinely empty screen.
    @State private var isPickingSong = false

    /// The last `.failed`/`.unavailable` message from `selectSong`, if any
    /// — a transient overlay `Text` (spec §5.2's "fetch-failure surface").
    @State private var selectSongMessage: String?

    public init(rootViewModel: AppRootViewModel, viewModel: ProjectionViewModel) {
        self.rootViewModel = rootViewModel
        self.viewModel = viewModel
        #if os(tvOS)
        _isStripVisible = State(initialValue: false)
        #else
        _isStripVisible = State(initialValue: true)
        #endif
    }

    public var body: some View {
        ZStack(alignment: .bottom) {
            canvas
            if isStripVisible { controlStrip }
            if let selectSongMessage { errorOverlay(selectSongMessage) }
            if showPicker { songPicker }
        }
        .task { await rootViewModel.loadCatalogueIfNeeded() }
        #if os(tvOS)
        .focusable()
        .onMoveCommand(perform: handleMove)
        .onPlayPauseCommand(perform: togglePanicBlackout)
        .onTapGesture { isStripVisible.toggle() }
        #endif
    }

    private var canvas: some View {
        let content = viewModel.renderedContent
        return ProjectionCanvasView(
            song: content.song,
            componentIndex: content.position.componentIndex,
            lineIndex: content.position.lineIndex,
            displayState: viewModel.displayState,
            isLoading: viewModel.isLoadingSong,
            textScale: viewModel.textScale
        )
    }

    /// Auto-shown whenever nothing is projected yet — the real affordance
    /// for "the canvas has no song selected" (`ProjectionCanvasView`'s own
    /// placeholder text just describes that state, it doesn't offer a fix).
    private var showPicker: Bool {
        isPickingSong || viewModel.song == nil
    }

    #if os(tvOS)
    private func handleMove(_ direction: MoveCommandDirection) {
        switch direction {
        case .left: viewModel.prevComponent()
        case .right: viewModel.nextComponent()
        case .up: viewModel.prevLine()
        case .down: viewModel.nextLine()
        @unknown default: break
        }
    }

    /// The presenter's panic-blackout toggle — Play/Pause flips between
    /// showing lyrics and a black screen, regardless of what was showing.
    private func togglePanicBlackout() {
        viewModel.setDisplayState(viewModel.displayState == .blackout ? .lyrics : .blackout)
    }
    #endif

    /// Lyrics · Blackout · Logo · Freeze/Unfreeze · Choose Song — every
    /// button calls straight into `ProjectionViewModel`, the same intents a
    /// future LAN remote (PR-6) would issue.
    private var controlStrip: some View {
        HStack(spacing: 20) {
            Button("Lyrics") { viewModel.setDisplayState(.lyrics) }
            Button("Blackout") { viewModel.setDisplayState(.blackout) }
            Button("Logo") { viewModel.setDisplayState(.logo) }
            Button(viewModel.displayState == .frozen ? "Unfreeze" : "Freeze") {
                viewModel.setDisplayState(viewModel.displayState == .frozen ? .lyrics : .frozen)
            }
            Button("Choose Song") { isPickingSong = true }
            if viewModel.displayState == .frozen {
                IHBadge("Frozen", tint: IHColorTokens.accent)
            }
        }
        .padding()
        .ihGlassCard()
        .padding(.bottom, 40)
    }

    /// A focusable list of the loaded catalogue (`AppRootViewModel
    /// .filteredSongs`, unfiltered here — no search field on a projector
    /// screen) rendered with the shared `SongSummaryRow` — never a
    /// bespoke row.
    private var songPicker: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Choose a Song").font(.title2.bold()).foregroundStyle(.white)
            List(rootViewModel.filteredSongs) { summary in
                Button { selectSong(summary) } label: {
                    SongSummaryRow(summary)
                }
                .buttonStyle(.plain)
            }
            // `.scrollContentBackground` is unavailable on tvOS (D-1,
            // #1504) — its `List` already renders on a transparent/focus-
            // engine-driven background there, so hiding the default
            // background is only meaningful (and only compiles) elsewhere.
            #if !os(tvOS)
            .scrollContentBackground(.hidden)
            #endif
        }
        .padding()
        .background(.black.opacity(0.85))
    }

    private func selectSong(_ summary: SongSummary) {
        Task {
            switch await viewModel.selectSong(songId: summary.songId) {
            case .shown:
                selectSongMessage = nil
                isPickingSong = false
            case .unavailable:
                selectSongMessage = "Sign in on this TV to show this song."
            case .failed(let message):
                selectSongMessage = message
            }
        }
    }

    private func errorOverlay(_ message: String) -> some View {
        Text(message)
            .foregroundStyle(.white)
            .padding()
            .ihGlassCard()
            .padding(.top, 40)
    }
}

// ProjectionCanvasView.swift
// IHFeatures
//
// ELI5: The actual full-screen picture on the TV — black background, then
// either nothing (blackout), the "iHymns" logo, or the current
// verse/chorus/bridge with (maybe) one line glowing to show where the
// congregation should be singing.
//
// DETAILED: #1504 (`.claude/apple-phase2-pr5-spec.md` §5.1). A PURE,
// value-driven `View` — no `ProjectionViewModel` import at all, so it's
// trivially previewable and testable-by-inspection without a fetch/actor
// stack. Every parameter is exactly one of `viewModel.renderedContent`'s
// two fields (`song`/`position`) plus the observed cosmetic properties —
// `ProjectionSceneView` (this same PR) is the one place that reads the
// view-model and hands values in.
//
// Deliberately does NOT reuse `SongComponentView` (Decision D-8): that view
// is a READING screen (long-press enrichment, chords, diff highlighting,
// scroll-list combined-label a11y) — the wrong affordances for a clean
// 10-foot projector surface. This canvas reuses the LOWER shared seams
// instead: `ihLyricLineStyle` (`IHDesign`, the one lyric typography rule),
// `SongDetail.orderedComponents` (the one arrangement-honouring read),
// `IHColorTokens`. Chords/enrichment/diff affordances are deliberately
// ABSENT — mirroring `SongComponentView`'s own "chord symbols are a
// visual-only reading aid... VoiceOver users get the clean lyric text"
// reasoning, taken one step further here: a projector shows clean lyric
// text and nothing else.
import IHDesign
import IHLive
import IHModels
import SwiftUI

/// The full-bleed 10-foot display surface — lyrics, blackout, logo, or a
/// frozen snapshot (frozen-ness is already baked into the values the
/// caller passed in from `renderedContent`; this view has no frozen
/// branch of its own beyond the accessibility label below).
public struct ProjectionCanvasView: View {
    let song: SongDetail?
    let componentIndex: Int?
    let lineIndex: Int?
    let displayState: IHRPDisplayState
    let isLoading: Bool
    let textScale: Double

    /// 10-foot legibility multiplier layered on top of the body-derived
    /// lyric font size (`ihLyricLineStyle`'s own base) — tune in device
    /// testing; kept as ONE named constant rather than a scattered magic
    /// number (spec §5.1).
    private static let projectionBaseScale: CGFloat = 2.6

    public init(
        song: SongDetail?, componentIndex: Int?, lineIndex: Int?,
        displayState: IHRPDisplayState, isLoading: Bool, textScale: Double
    ) {
        self.song = song
        self.componentIndex = componentIndex
        self.lineIndex = lineIndex
        self.displayState = displayState
        self.isLoading = isLoading
        self.textScale = textScale
    }

    public var body: some View {
        let core = ZStack {
            // Always a black full-bleed (Decision D-6) — never themed.
            Color.black.ignoresSafeArea()
            content
        }
        // Only override the natural accessibility reading when there's
        // something to REPLACE it with (blackout/logo/frozen have nothing
        // — or stale content — worth reading verbatim); the plain `.lyrics`
        // case leaves the component's own `.combine`d label untouched,
        // mirroring `SongComponentView`'s identical "only override when
        // there's something to add" posture.
        if let accessibilityOverride {
            core.accessibilityLabel(accessibilityOverride)
        } else {
            core
        }
    }

    @ViewBuilder
    private var content: some View {
        switch displayState {
        case .blackout:
            EmptyView()
        case .logo:
            logo
        case .lyrics, .frozen:
            lyricsOrPlaceholder
        }
    }

    private var logo: some View {
        Text("iHymns")
            .font(.system(size: 120, weight: .bold))
            .foregroundStyle(IHColorTokens.accent)
    }

    @ViewBuilder
    private var lyricsOrPlaceholder: some View {
        if let song, let componentIndex, song.orderedComponents.indices.contains(componentIndex) {
            lyrics(song: song, component: song.orderedComponents[componentIndex])
        } else if isLoading {
            ProgressView().tint(.white)
        } else {
            Text("No song selected")
                .font(.title)
                .foregroundStyle(.white.opacity(0.4))
        }
    }

    private func lyrics(song: SongDetail, component: SongComponent) -> some View {
        VStack(alignment: .leading, spacing: 24) {
            Text("\(song.songId.rawValue) · \(song.title)")
                .font(.title3)
                .foregroundStyle(.white.opacity(0.6))

            VStack(alignment: .leading, spacing: 4) {
                Text(componentLabel(component))
                    .font(.caption.smallCaps().bold())
                    .foregroundStyle(IHColorTokens.accent)

                ForEach(component.lines.indices, id: \.self) { index in
                    lineView(component.lines[index], componentType: component.type, isCurrent: lineIndex == index)
                }
            }
            // One accessible unit per component — mirrors
            // `SongComponentView`'s identical `.combine` posture.
            .accessibilityElement(children: .combine)
            .animation(.easeInOut(duration: 0.25), value: lineIndex)
        }
        .padding(60)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
    }

    /// `"Verse 1"`/`"Chorus"` — mirrors `SongComponentView.label`'s exact
    /// "type.capitalized [number if > 0]" rule (that view is a reading
    /// view this canvas deliberately doesn't reuse, Decision D-8; this
    /// three-line rule is small enough to mirror directly rather than
    /// extracting a shared helper for one caller on each side).
    private func componentLabel(_ component: SongComponent) -> String {
        component.number > 0 ? "\(component.type.capitalized) \(component.number)" : component.type.capitalized
    }

    /// One lyric line — full opacity/no tint in component mode
    /// (`lineIndex == nil`); the karaoke-current line glows in accent, every
    /// other line dims to 0.35 opacity, when in line mode.
    @ViewBuilder
    private func lineView(_ line: String, componentType: String, isCurrent: Bool) -> some View {
        let inLineMode = lineIndex != nil
        Text(line)
            .ihLyricLineStyle(componentType: componentType, textScale: Self.projectionBaseScale * CGFloat(textScale))
            .minimumScaleFactor(0.4)
            .lineLimit(nil)
            .foregroundStyle(inLineMode && isCurrent ? IHColorTokens.accent : .white)
            .opacity(inLineMode && !isCurrent ? 0.35 : 1.0)
            .shadow(color: inLineMode && isCurrent ? IHColorTokens.accent.opacity(0.6) : .clear, radius: 12)
    }

    /// "Blackout"/"Logo"/"Frozen" — the three cases spec §5.1 says a
    /// screen-reader user needs told explicitly when lyrics aren't being
    /// read verbatim; `nil` for plain `.lyrics` (nothing to override).
    private var accessibilityOverride: String? {
        switch displayState {
        case .blackout: "Blackout"
        case .logo: "Logo"
        case .frozen: "Frozen"
        case .lyrics: nil
        }
    }
}

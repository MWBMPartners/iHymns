// SongComponentView.swift
// IHFeatures
//
// ELI5: Draws one verse/chorus/bridge — a small label ("Verse 1", "Chorus")
// followed by its lyric lines, slanted (italic) automatically when it's a
// chorus/refrain/bridge, matching the web site. Optionally shows a chord
// symbol above any line that has one, and long-press any line that carries
// a translation/annotation to see it.
//
// DETAILED: One `SongComponent` (IHModels) rendered as its own small view —
// split out of `SongDetailView` purely to keep that file's body short (this
// repo's LOC-budget discipline, `Scripts/loc-budget.sh`) and so a future
// screen that also needs to render one component (e.g. a Phase-1 "jump to
// chorus" quick-nav) can reuse it.
//
// #180 UPDATE — chords (`SongComponent.chords`, intentionally NOT rendered
// by #1399's minimal slice) now render when `showChords` is `true` AND this
// component actually has at least one non-nil chord; the per-line
// long-press enrichment affordance (`enrichmentIndex.hasEnrichment(forLineId:)`)
// only attaches when that exact line genuinely has something to show — no
// dead "long-press anywhere" UX on the vast majority of songs that (per
// this task's dev-API survey) have neither chords nor enrichment yet.
//
// #1445 UPDATE — two ADDITIVE, DEFAULTED parameters for the song comparison
// screen (`SongComparisonView`), which reuses this view for each half of a
// paired row rather than forking a second lyric renderer (the repo's
// modularity rule): `highlightedLineIndices` tints + marks a set of line
// indices as "differs from the compared version" (NOT colour-only — see
// `lineView(atIndex:)` below), and `accessibilityLabelPrefix` lets the
// comparison screen announce WHICH song a component belongs to ("Mission
// Praise version. Verse 1. …"). Both default to their pre-#1445 no-op
// values (`[]`/`nil`), so every existing call site (`SongDetailView`)
// compiles and renders unchanged.
import IHDesign
import IHModels
import SwiftUI

/// Renders one lyric component (a verse, chorus, bridge, ...): its label,
/// then its lines in order, each optionally chord-annotated and
/// long-press-enrichable.
struct SongComponentView: View {
    let component: SongComponent
    let textScale: CGFloat
    let showChords: Bool
    let enrichmentIndex: LineEnrichmentIndex

    /// Called with a line's stable `tblLyricLines.Id` when the user
    /// long-presses (or activates the VoiceOver equivalent action on) a
    /// line that `enrichmentIndex` confirms has something to show.
    let onSelectLine: (Int) -> Void

    /// Line indices (into `component.lines`) to render as "differs from the
    /// compared version" — #1445's diff highlight. Empty (the default) is a
    /// verified no-op for every pre-#1445 call site.
    var highlightedLineIndices: Set<Int> = []

    /// A short label prepended to this component's combined VoiceOver
    /// announcement (e.g. `"Mission Praise version"`) so a comparison
    /// screen's two panes are distinguishable by ear, not just by sight.
    /// `nil` (the default) leaves the announcement exactly as it was before
    /// #1445.
    var accessibilityLabelPrefix: String?

    var body: some View {
        content
    }

    @ViewBuilder
    private var content: some View {
        let core = VStack(alignment: .leading, spacing: 4) {
            Text(label)
                .font(.caption.smallCaps().bold())
                .foregroundStyle(IHColorTokens.accent)

            ForEach(component.lines.indices, id: \.self) { index in
                lineView(atIndex: index)
            }
        }
        // One accessible unit per component ("Chorus. Amazing grace...")
        // rather than one VoiceOver stop per line — mirrors the repo's
        // existing a11y posture of folding structural context into a
        // single accessible name (CLAUDE.md rule #24's badge pattern).
        .accessibilityElement(children: .combine)

        // #1445 — only override the auto-combined label when there's
        // actually something to add (a side prefix and/or a diff-count
        // suffix); every pre-#1445 caller passes neither, so `core` renders
        // with its original, unmodified accessibility label.
        if let comparisonAccessibilityLabel {
            core.accessibilityLabel(comparisonAccessibilityLabel)
        } else {
            core
        }
    }

    /// The combined "<prefix>. <label>. <lines…>. <N lines differ…>" string
    /// that REPLACES the automatic `.combine`-derived label — `nil` when
    /// neither a prefix nor any highlighted lines were supplied, in which
    /// case `content` above leaves the default combined label untouched.
    private var comparisonAccessibilityLabel: String? {
        guard accessibilityLabelPrefix != nil || !highlightedLineIndices.isEmpty else { return nil }
        var parts: [String] = []
        if let accessibilityLabelPrefix { parts.append(accessibilityLabelPrefix) }
        parts.append(label)
        parts.append(contentsOf: component.lines)
        if !highlightedLineIndices.isEmpty {
            let count = highlightedLineIndices.count
            parts.append(count == 1 ? "1 line differs from the compared version" : "\(count) lines differ from the compared version")
        }
        return parts.joined(separator: ". ")
    }

    @ViewBuilder
    private func lineView(atIndex index: Int) -> some View {
        let line = component.lines[index]
        let lineId = index < component.lineIds.count ? component.lineIds[index] : nil
        let hasEnrichment = lineId.map(enrichmentIndex.hasEnrichment(forLineId:)) ?? false
        let isHighlighted = highlightedLineIndices.contains(index)

        VStack(alignment: .leading, spacing: 0) {
            if showChords, let chord = chord(atIndex: index) {
                Text(chord)
                    .font(.system(.caption, design: .monospaced).bold())
                    .foregroundStyle(IHColorTokens.accent)
                    // Chord symbols are a visual-only reading aid layered
                    // above the lyric line; VoiceOver users get the clean
                    // lyric text without an interspersed "G, Am7, ..."
                    // reading — the enrichment `.accessibilityAction` below
                    // is the equivalent, INTENTIONAL surface for anything
                    // that genuinely needs announcing per line.
                    .accessibilityHidden(true)
            }
            HStack(alignment: .firstTextBaseline, spacing: 4) {
                if isHighlighted {
                    // #1445 — the diff marker is NEVER colour-only (WCAG
                    // 1.4.1): a glyph here, a background tint below, AND the
                    // spoken suffix `comparisonAccessibilityLabel` appends —
                    // three independent signals for the one difference.
                    Image(systemName: "asterisk")
                        .font(.caption2.bold())
                        .foregroundStyle(IHColorTokens.accent)
                        .accessibilityHidden(true)
                }
                Text(line)
                    .ihLyricLineStyle(componentType: component.type, textScale: textScale)
            }
        }
        .padding(isHighlighted ? 4 : 0)
        .background {
            if isHighlighted {
                RoundedRectangle(cornerRadius: 6, style: .continuous)
                    .fill(IHColorTokens.accent.opacity(0.12))
            }
        }
        .contentShape(Rectangle())
        .onLongPressGesture {
            guard hasEnrichment, let lineId else { return }
            onSelectLine(lineId)
        }
        .accessibilityActions {
            if hasEnrichment, let lineId {
                Button("Show Line Details") { onSelectLine(lineId) }
            }
        }
    }

    /// The chord string for `index`, if `component.chords` both exists and
    /// is long enough (defensive against a hypothetical malformed payload
    /// where `chords.count != lines.count`) and that specific line has one.
    private func chord(atIndex index: Int) -> String? {
        guard let chords = component.chords, index < chords.count else { return nil }
        return chords[index]
    }

    /// e.g. `"Verse 1"`, `"Chorus"` (component number `0` — the common case
    /// for a chorus that never repeats with a different number — omits the
    /// number entirely rather than showing a meaningless "Chorus 0").
    private var label: String {
        component.number > 0
            ? "\(component.type.capitalized) \(component.number)"
            : component.type.capitalized
    }
}

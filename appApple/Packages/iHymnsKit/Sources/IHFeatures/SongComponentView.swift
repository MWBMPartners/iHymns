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
//
// #1454 UPDATE — a THIRD additive, defaulted parameter, `wordDiffs`, carries
// `SongComparisonEngine.wordDiff(primaryLine:secondaryLine:)`'s per-line
// output (keyed by the SAME index `highlightedLineIndices` uses) for the
// finer "which WORDS changed" grain. A line index present in `wordDiffs`
// renders word-by-word (only the `.changed` tokens tinted+bold+underlined —
// still NOT colour-only, mirroring #1445's own line-level rule); a line
// index that's highlighted but ABSENT from `wordDiffs` (an overflow line
// with no counterpart to word-diff against, or any caller that simply
// doesn't pass this parameter) falls back to #1445's original whole-line
// tint. Empty (the default) is a verified no-op for every pre-#1454 call
// site, including #1445's own original `SongComparisonView` cells until
// that file is updated in this same change.
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

    /// Per-line word-level diff segments for THIS side (#1454), keyed by the
    /// same line index `highlightedLineIndices` uses. See this file's
    /// header for the fallback-to-whole-line rule when a highlighted index
    /// has no entry here. Empty (the default) is a verified no-op.
    var wordDiffs: [Int: [WordDiffSegment]] = [:]

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
        // #1454 — one SUMMARY count, not a per-word announcement (the
        // header's own "too noisy" reasoning) — deliberately appended after
        // the line-count sentence, not interleaved with it.
        if changedWordCount > 0 {
            parts.append(changedWordCount == 1 ? "1 word changed" : "\(changedWordCount) words changed")
        }
        return parts.joined(separator: ". ")
    }

    /// Total `.changed` tokens across every line in `wordDiffs` — the
    /// VoiceOver summary count `comparisonAccessibilityLabel` appends.
    private var changedWordCount: Int {
        wordDiffs.values.reduce(0) { total, segments in
            total + segments.filter {
                if case .changed = $0 { return true }
                return false
            }.count
        }
    }

    @ViewBuilder
    private func lineView(atIndex index: Int) -> some View {
        let line = component.lines[index]
        let lineId = index < component.lineIds.count ? component.lineIds[index] : nil
        let hasEnrichment = lineId.map(enrichmentIndex.hasEnrichment(forLineId:)) ?? false
        let isHighlighted = highlightedLineIndices.contains(index)
        // #1454 — only a NON-EMPTY, genuinely-changed segment list opts
        // this line into word-level rendering; a `wordDiffs[index]` entry
        // with nothing `.changed` in it (shouldn't occur in practice — see
        // `SongComparisonView`'s own caller comment — but defensive rather
        // than silently rendering an all-plain "highlighted" line with no
        // visible cue at all) still falls back to the whole-line tint.
        let wordSegments = wordDiffs[index]
        let hasWordLevelDiff = wordSegments?.contains { if case .changed = $0 { return true }; return false } ?? false

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
                    // 1.4.1): a glyph here, a background tint below (or,
                    // #1454, per-word bold+underline instead), AND the
                    // spoken suffix `comparisonAccessibilityLabel` appends —
                    // three independent signals for the one difference.
                    Image(systemName: "asterisk")
                        .font(.caption2.bold())
                        .foregroundStyle(IHColorTokens.accent)
                        .accessibilityHidden(true)
                }
                if hasWordLevelDiff, let wordSegments {
                    wordDiffText(wordSegments)
                        .ihLyricLineStyle(componentType: component.type, textScale: textScale)
                } else {
                    Text(line)
                        .ihLyricLineStyle(componentType: component.type, textScale: textScale)
                }
            }
        }
        // #1454 — the whole-line background tint is SKIPPED when a word-
        // level diff is already drawing attention to the specific changed
        // tokens; showing both at once would double-signal the same line
        // and read as noisier, not clearer.
        .padding(isHighlighted && !hasWordLevelDiff ? 4 : 0)
        .background {
            if isHighlighted, !hasWordLevelDiff {
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

    /// Composes `segments` into one `Text` via `AttributedString` runs —
    /// `.changed` tokens get `.stronglyEmphasized` (bold) + an underline +
    /// a tint (three signals stacked on top of the line-level asterisk/
    /// spoken-summary cues `lineView(atIndex:)` already adds, none of them
    /// colour-only), `.unchanged` tokens carry no attributes at all. Deliberately
    /// `AttributedString` runs rather than `Text` concatenation (`+`) —
    /// SwiftUI's own `Text.+` is deprecated on this SDK in favour of exactly
    /// this pattern, and per-run attributes (bold/underline/tint) compose
    /// cleanly with the BASE font `.ihLyricLineStyle` sets on the whole
    /// returned `Text`, rather than each run hard-coding its own font/size
    /// and silently stopping honouring the user's text-scale setting.
    /// Single spaces are re-inserted between tokens (`tokenize(_:)` split
    /// them out) so the rejoined line reads naturally.
    private func wordDiffText(_ segments: [WordDiffSegment]) -> Text {
        var attributed = AttributedString()
        for (offset, segment) in segments.enumerated() {
            if offset > 0 { attributed += AttributedString(" ") }
            switch segment {
            case .unchanged(let word):
                attributed += AttributedString(word)
            case .changed(let word):
                var run = AttributedString(word)
                run.inlinePresentationIntent = .stronglyEmphasized
                run.underlineStyle = .single
                run.foregroundColor = IHColorTokens.accent
                attributed += run
            }
        }
        return Text(attributed)
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

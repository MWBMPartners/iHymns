// SongComponentView.swift
// IHFeatures
//
// ELI5: Draws one verse/chorus/bridge — a small label ("Verse 1", "Chorus")
// followed by its lyric lines, slanted (italic) automatically when it's a
// chorus/refrain/bridge, matching the web site.
//
// DETAILED: One `SongComponent` (IHModels) rendered as its own small view —
// split out of `SongDetailView` purely to keep that file's body short (this
// repo's LOC-budget discipline, `Scripts/loc-budget.sh`) and so a future
// screen that also needs to render one component (e.g. a Phase-1 "jump to
// chorus" quick-nav) can reuse it. Chords (`SongComponent.chords`) are
// intentionally NOT rendered yet — this task's brief is explicit that
// "text-size is enough for now"; the chords toggle is strategy §2.1's
// separate, later feature.
import IHDesign
import IHModels
import SwiftUI

/// Renders one lyric component (a verse, chorus, bridge, ...): its label,
/// then its lines in order.
struct SongComponentView: View {
    let component: SongComponent
    let textScale: CGFloat

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label)
                .font(.caption.smallCaps().bold())
                .foregroundStyle(IHColorTokens.accent)

            ForEach(Array(component.lines.enumerated()), id: \.offset) { _, line in
                Text(line)
                    .ihLyricLineStyle(componentType: component.type, textScale: textScale)
            }
        }
        // One accessible unit per component ("Chorus. Amazing grace...")
        // rather than one VoiceOver stop per line — mirrors the repo's
        // existing a11y posture of folding structural context into a
        // single accessible name (CLAUDE.md rule #24's badge pattern).
        .accessibilityElement(children: .combine)
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

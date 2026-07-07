// SongSummaryRow.swift
// IHFeatures
//
// ELI5: One row in a song list — the number, the title, which book it's
// from — styled with the shared glass-card look. Any screen that shows a
// list of songs (search results, a songbook's contents, favourites, ...)
// reuses THIS row instead of drawing its own.
//
// DETAILED: The first genuinely shared screen-building-block in IHFeatures,
// composing `IHModels.SongSummary` (the domain data) with
// `IHDesign.ihGlassCard()` (the shared visual treatment) — proving the
// IHFeatures→IHModels and IHFeatures→IHDesign dependency edges compile
// together, not just in isolation. Per the repo's modularity rule
// (`.claude/CLAUDE.md`): "When a piece of UI... exists in more than one
// place, extract it into a shared module" — `SongSummaryRow` IS that shared
// module for song-list rows, from Phase 0 onward, so Phase 1's catalogue/
// search/songbook/favourites screens (strategy §3.4) never each grow their
// own copy.
import IHDesign
import IHModels
import SwiftUI

/// A single reusable song-list row: number, title, songbook.
///
/// ELI5: Draws one line like "1008 — Amazing Grace (MP)".
public struct SongSummaryRow: View {
    private let summary: SongSummary

    public init(_ summary: SongSummary) {
        self.summary = summary
    }

    public var body: some View {
        HStack(alignment: .firstTextBaseline, spacing: 12) {
            Text(summary.displayNumber)
                .font(.headline)
                .foregroundStyle(IHColorTokens.accent)
                .frame(minWidth: 44, alignment: .trailing)

            VStack(alignment: .leading, spacing: 2) {
                Text(summary.title)
                    .font(.body)
                Text(summary.songbookAbbreviation)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            Spacer(minLength: 0)
        }
        .ihGlassCard()
        // Accessibility: expose the whole row as one element reading
        // naturally ("1008, Amazing Grace, MP") rather than three separate
        // stops for VoiceOver — mirrors the web/PWA's own a11y posture of
        // folding badge/number context into one accessible name (strategy
        // §3.3, CLAUDE.md rule #24's a11y pattern for badges).
        .accessibilityElement(children: .combine)
    }
}

#Preview {
    SongSummaryRow(
        SongSummary(
            songId: SongID(rawValue: "MP-1008") ?? SongID(songbookAbbreviation: "MP", number: 1008),
            title: "Amazing Grace",
            songbookAbbreviation: "MP",
            displayNumber: "1008"
        )
    )
    .padding()
}

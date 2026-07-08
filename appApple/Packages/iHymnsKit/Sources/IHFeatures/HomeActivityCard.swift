// HomeActivityCard.swift
// IHFeatures
//
// ELI5: The little "Your Activity" card on the Home screen — how many songs
// you've read this week and your current reading streak, with a tiny bar
// chart of the last 7 days if this device supports Swift Charts. Tap it to
// see the full "Your Activity" screen.
//
// DETAILED: #1457. Presentation-only — `HomeView` supplies an
// already-computed, non-optional `HomeActivitySummary` (its own `nil` case
// means "don't render this card at all," decided at the `HomeView` call
// site, matching that file's existing `resumeSection`/`browseSongbooksCard`
// "render nothing when nothing to show" idiom) and wraps this view in a
// `NavigationLink` pushing `UsageStatsView` — the SAME "NavigationLink {
// Destination } label: { card content }" shape `HomeView.browseSongbooksCard`
// already establishes, so this file owns no navigation of its own.
//
// Swift Charts is genuinely OPTIONAL here (`#if canImport(Charts)`) per this
// task's own "keep it small and optional/graceful" instruction — the
// deployment target (OS 26 everywhere, `Package.swift`) comfortably clears
// Charts' actual minimum (iOS/macOS/tvOS/watchOS 16+), so in practice this
// always compiles in, but the guard keeps the plain two-stat card a
// self-sufficient fallback shape rather than a hard Charts dependency baked
// into the layout.
import IHDesign
import IHModels
import SwiftUI
#if canImport(Charts)
import Charts
#endif

/// The Home "Your Activity" stat-strip card's content — see this file's
/// header for why navigation/visibility both live in `HomeView`, not here.
struct HomeActivityCard: View {
    let summary: HomeActivitySummary

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Text("Your Activity")
                    .font(.headline)
                Spacer(minLength: 0)
                Image(systemName: "chevron.right")
                    .foregroundStyle(.secondary)
                    // Decorative — `HomeView`'s own `.accessibilityLabel`
                    // on the wrapping `NavigationLink` already announces
                    // "opens Your Activity" via the link's button trait.
                    .accessibilityHidden(true)
            }
            HStack(alignment: .bottom, spacing: 20) {
                statColumn(value: "\(summary.songsReadThisWeek)", label: weekLabel)
                statColumn(value: streakDisplay, label: "Streak")
                #if canImport(Charts)
                Spacer(minLength: 0)
                sparkline
                #endif
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .ihGlassCard()
    }

    private var weekLabel: String {
        summary.songsReadThisWeek == 1 ? "Song This Week" : "Songs This Week"
    }

    private var streakDisplay: String {
        summary.currentStreak == 1 ? "1 Day" : "\(summary.currentStreak) Days"
    }

    private func statColumn(value: String, label: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(value)
                .font(.title3.bold())
            Text(label)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
    }

    #if canImport(Charts)
    /// A tiny, purely-decorative 7-day bar chart — the SAME numbers
    /// `HomeView`'s accessibility label already speaks as plain text, so
    /// this is hidden from VoiceOver rather than announced a second time in
    /// chart form.
    private var sparkline: some View {
        Chart(Array(summary.recentDailyCounts.enumerated()), id: \.offset) { index, count in
            BarMark(x: .value("Day", index), y: .value("Songs Read", count))
        }
        .foregroundStyle(IHColorTokens.accent)
        .chartXAxis(.hidden)
        .chartYAxis(.hidden)
        .frame(width: 64, height: 36)
        .accessibilityHidden(true)
    }
    #endif
}

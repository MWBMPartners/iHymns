// PhaseZeroSkeletonView.swift
// IHFeatures
//
// ELI5: The one screen every app shell (iPhone/iPad/Mac, tvOS, watchOS)
// shows right now — it just says "iHymns" and explains this is the
// Phase-0 skeleton. Real screens (song browsing, search, live worship...)
// replace this in Phase 1.
//
// DETAILED: Strategy §3.4's Phase-0 exit criterion is "everything builds,
// ONE real screen." Rather than four app shells each drawing their own
// placeholder (a repo-wide modularity violation per `.claude/CLAUDE.md`'s
// "when UI exists in more than one place, extract it"), this ONE shared
// view lives in IHFeatures and every shell's `@main App` composes it,
// customizing only the platform-appropriate navigation container around
// it (`NavigationStack` on phone/watch, a `TabView`/`NavigationSplitView`
// shell elsewhere — strategy §2.2's "same codepath" principle). It
// exercises `IHDesign.ihGlassCard()` + `IHColorTokens.accent`, so it also
// serves as a live, on-device smoke test that the Liquid Glass wrapper
// really renders (not just type-checks) once run in Xcode/Simulator.
import IHDesign
import SwiftUI

/// The shared Phase-0 placeholder screen shown by every app shell.
///
/// ELI5: "iHymns — Phase 0 skeleton" on a glass card.
public struct PhaseZeroSkeletonView: View {
    /// A short, platform-specific note shown under the headline (e.g. the
    /// shell that's rendering this — "iOS/iPadOS/macOS", "tvOS", "watchOS").
    private let shellName: String

    public init(shellName: String) {
        self.shellName = shellName
    }

    public var body: some View {
        VStack(spacing: 16) {
            Text("iHymns")
                .font(.largeTitle.bold())
                .foregroundStyle(IHColorTokens.accent)

            Text("Phase‑0 skeleton — \(shellName) shell")
                .font(.subheadline)
                .foregroundStyle(.secondary)

            Text("iHymnsKit compiles, links, and runs. Real screens land in Phase 1.")
                .font(.footnote)
                .multilineTextAlignment(.center)
                .foregroundStyle(.secondary)
                .padding(.horizontal, 24)
        }
        .padding(32)
        .ihGlassCard(cornerRadius: 28)
        .accessibilityElement(children: .combine)
        .accessibilityLabel("iHymns, Phase zero skeleton, \(shellName) shell")
    }
}

#Preview {
    PhaseZeroSkeletonView(shellName: "iOS/iPadOS/macOS")
}

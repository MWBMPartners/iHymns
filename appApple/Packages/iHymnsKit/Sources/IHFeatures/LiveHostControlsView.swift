// LiveHostControlsView.swift
// IHFeatures
//
// ELI5: The "I'm leading a live session" console — the big shareable code,
// what you're currently broadcasting, and a button to end it.
//
// DETAILED: Apple Phase-2 PR-10 (#1426; `.claude/apple-phase2-pr10-spec.md`
// §6.1). `ShareLink(item: code)` covers "copy/share the code" on every
// platform this targets, including watchOS 9+ (#1549-safe — no custom
// pasteboard code needed). The footer states the honest scenePhase
// limitation (§6.6): iHymns keeps the session alive only while the app is
// open; a longer background stretch may briefly desync followers until the
// 180 s freshness window's wake-beat (`LiveFollowEngine.setScenePhaseActive`)
// catches back up.
import SwiftUI

struct LiveHostControlsView: View {
    let rootViewModel: AppRootViewModel
    @State private var isPresentingEndConfirm = false

    var body: some View {
        if case .hosting(let code, let currentSongTitle) = rootViewModel.liveState {
            Section {
                VStack(alignment: .leading, spacing: 12) {
                    Text(code)
                        .font(.system(.largeTitle, design: .monospaced).bold())
                        .accessibilityLabel("Your live code is \(code)")
                    // `ShareLink` is UNAVAILABLE on tvOS (D-1, #1504's
                    // tvOS-build gate) — this whole screen is unreachable
                    // there anyway (spec §6.6: "tvOS: NO UI this PR").
                    #if !os(tvOS)
                    ShareLink(item: code) {
                        Label("Share Code", systemImage: "square.and.arrow.up")
                    }
                    #endif
                    Text(currentSongTitle.map { "Broadcasting: \($0)" } ?? "Open a song to broadcast it.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
                Button("End Live Session", role: .destructive) {
                    isPresentingEndConfirm = true
                }
                .confirmationDialog(
                    "End this live session?",
                    isPresented: $isPresentingEndConfirm,
                    titleVisibility: .visible
                ) {
                    Button("End Session", role: .destructive) {
                        Task { await rootViewModel.leaveLive() }
                    }
                    Button("Cancel", role: .cancel) {}
                }
            } header: {
                Text("Now Hosting")
            } footer: {
                Text(
                    "iHymns keeps your session alive while the app is open. If you leave the app "
                        + "for more than a few minutes, followers may briefly lose sync until you return."
                )
            }
        }
    }
}

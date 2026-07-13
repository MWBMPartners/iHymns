// LiveStatusBanner.swift
// IHFeatures
//
// ELI5: The thin bar across the bottom of the app while you're hosting or
// following a live session — tap it to jump to the Live tab, or use its own
// Leave/End button without leaving whatever screen you're on.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §6.4). The SwiftUI equivalent of the web's `position:fixed;bottom:0`
// banner (`service-follow.js:213-217`) — hosted via `.safeAreaInset(edge:
// .bottom)` on `RootContainerView`'s BOTH root variants, which PUSHES
// content up rather than covering it. Deliberately TWO separate `Button`s
// (the label + the action) rather than a tap-gesture on the whole capsule —
// a `Button` embedded inside a view carrying its own `.onTapGesture` is a
// known SwiftUI gesture-conflict trap; two independent buttons sidesteps it
// entirely. Colours are semantic SwiftUI tints (`.orange`/`IHColorTokens
// .accent`), never hex literals.
import IHDesign
import SwiftUI

struct LiveStatusBanner: View {
    let rootViewModel: AppRootViewModel
    let navigationState: AppNavigationState

    var body: some View {
        if rootViewModel.liveState != .idle {
            HStack(spacing: 12) {
                Button {
                    navigationState.selectedSection = .live
                } label: {
                    Label(label, systemImage: "dot.radiowaves.left.and.right")
                        .font(.subheadline.weight(.semibold))
                }
                .buttonStyle(.plain)
                Spacer(minLength: 12)
                Button(actionLabel, role: .destructive) {
                    Task { await rootViewModel.leaveLive() }
                }
                .font(.caption.bold())
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 10)
            .background(tint, in: Capsule())
            .foregroundStyle(.white)
            .padding(.horizontal)
            .accessibilityElement(children: .combine)
        }
    }

    private var label: String {
        switch rootViewModel.liveState {
        case .hosting(let code, _): "LIVE · \(code)"
        case .followingLeader, .followingService: "Following live"
        case .idle: ""
        }
    }

    private var actionLabel: String {
        if case .hosting = rootViewModel.liveState { "End" } else { "Leave" }
    }

    private var tint: Color {
        if case .hosting = rootViewModel.liveState { .orange } else { IHColorTokens.accent }
    }
}

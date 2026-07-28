// WatchRemoteGuidanceView.swift
// IHFeatures/WatchRelay
//
// ELI5: The plain "not ready yet" screen the watch shows when there's
// nothing to control — either you still need to finish pairing on your
// iPhone, or you haven't connected a TV at all yet. It also mentions if
// your iPhone itself isn't answering right now.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §5 —
// BINDING). Split out of `WatchRemoteView.swift` (this module's sibling
// file) purely to keep BOTH files well under the LOC budget and each
// single-purpose (`.claude/CLAUDE.md`'s modularity rule) — `WatchRemoteView`
// owns the phase switch + the live remote; this file is the ONE shared
// shape for its two dead-end phases (`.pairing`/`.noSavedTV`). `internal`
// (no `public`), since only `WatchRemoteView` in this same module ever
// builds one.
import SwiftUI
#if os(watchOS) && canImport(WatchConnectivity)

/// The `.pairing`/`.noSavedTV` guidance screen — see this file's header.
///
/// ELI5: "Here's the one thing you need to go do on your iPhone first."
struct WatchRemoteGuidanceView: View {
    /// SF Symbol name, or `nil` for the `.pairing` screen (spec §5's table
    /// gives `.noSavedTV` an icon and `.pairing` none).
    let systemImage: String?
    let title: String
    let caption: String
    /// Set by the caller ONLY when `!controller.isPhoneReachable &&
    /// controller.isActivated` — reachability drives COPY, never gates
    /// anything (§4.5); this line dominates ABOVE the caption when present
    /// (spec §5's guidance-screen row).
    let notReachableNotice: String?

    var body: some View {
        ScrollView {
            VStack(spacing: 12) {
                if let notReachableNotice {
                    Text(notReachableNotice)
                        .font(.footnote)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                }
                if let systemImage {
                    Image(systemName: systemImage)
                        .font(.largeTitle)
                        .foregroundStyle(.secondary)
                        .accessibilityHidden(true)
                }
                Text(title)
                    .font(.headline)
                    .multilineTextAlignment(.center)
                Text(caption)
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
            }
            .padding()
        }
        // One accessible unit — a screen reader user hears the whole
        // guidance message as a single coherent sentence rather than four
        // disjoint fragments.
        .accessibilityElement(children: .combine)
    }
}

#Preview("No TV paired") {
    WatchRemoteGuidanceView(
        systemImage: "appletv",
        title: "No TV paired",
        caption: "Open iHymns on your iPhone and connect to a TV once; after that, this watch can drive it even with the phone in your pocket.",
        notReachableNotice: nil
    )
}

#Preview("Finish pairing") {
    WatchRemoteGuidanceView(
        systemImage: nil,
        title: "Finish pairing on your iPhone",
        caption: "Complete the code or fingerprint step on your iPhone, then this remote takes over.",
        notReachableNotice: "iPhone not reachable. Bring your iPhone nearby."
    )
}
#endif

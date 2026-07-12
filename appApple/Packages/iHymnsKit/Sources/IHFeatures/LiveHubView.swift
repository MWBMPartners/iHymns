// LiveHubView.swift
// IHFeatures
//
// ELI5: The real `.live` section screen — a "TV Remote" card that takes you
// to the actual remote-control screen, plus an honest "Live Follow / Service
// Mode — coming in a future update" note underneath, so this section is
// truthful about what's built and what isn't.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §6.1, Decision D-6).
// Fills `RootContainerView`'s `.live` case (both `tabbedRoot` and
// `splitView`), replacing what was purely `liveComingSoonView` — that
// `ContentUnavailableView` copy MOVES here verbatim rather than being
// rewritten, so the "don't fake a screen" honesty this repo insists on
// (`RootContainerView.swift`'s own header) survives the refactor unchanged
// for the half that's still unbuilt. Placed in `.live` rather than a new
// tab/Settings row because a control surface is a DESTINATION (strategy
// §2.4.1's remote-control feature), not a preference, and `RootSection`
// deliberately caps at 7 tabs (`AppNavigationState.swift`) — see spec §6.1
// for the full reasoning this header summarises.
import IHDesign
import SwiftUI

/// The `.live` section's real content: a card leading to the LAN TV remote,
/// plus the still-honest "coming soon" note for the server-mediated half.
public struct LiveHubView: View {
    private let rootViewModel: AppRootViewModel

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
    }

    public var body: some View {
        List {
            Section {
                NavigationLink {
                    RemoteControlView(rootViewModel: rootViewModel)
                } label: {
                    Label("TV Remote", systemImage: "appletv")
                }
            } footer: {
                Text("Control iHymns on your Apple TV from this device.")
            }

            Section {
                ContentUnavailableView(
                    "Live Follow & Service Mode",
                    systemImage: "dot.radiowaves.left.and.right",
                    description: Text("Live Follow and Service Mode are coming in a future update.")
                )
            }
        }
        .navigationTitle("Live")
    }
}

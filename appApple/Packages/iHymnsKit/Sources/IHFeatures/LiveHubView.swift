// LiveHubView.swift
// IHFeatures
//
// ELI5: The real `.live` section screen — a "TV Remote" card (unchanged),
// plus the real Live Follow + Service Mode entries: join with a code,
// lead your own session, and (while either is active) a "Now" row into it.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §6.1, Decision D-2). Fills the honest "coming in a future update"
// `ContentUnavailableView` PR-7 (#1422) left here — that placeholder is
// deleted because the thing it was honest about now exists. The TV Remote
// section stays BYTE-IDENTICAL and FIRST (it shipped first, users know it).
// The native Service-Mode OPERATOR console (start/rotate/end a venue
// session) is deliberately OUT of scope here — D-2's three reasons
// (spec §5.3): it needs org-admin venue/schedule data with no native
// endpoint yet, the web already serves it responsively, and its native
// consumers are already scheduled (PR-14/#1425, PR-15/#1428).
import IHDesign
import SwiftUI

/// The `.live` section's real content — see this file's header.
public struct LiveHubView: View {
    private let rootViewModel: AppRootViewModel
    @State private var isPresentingJoinSheet = false

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
    }

    public var body: some View {
        List {
            LiveHostControlsView(rootViewModel: rootViewModel)
            followingRow

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
                Button {
                    isPresentingJoinSheet = true
                } label: {
                    Label("Join with a Code…", systemImage: "person.wave.2")
                }
            } footer: {
                Text("Enter the code from the venue screen or your worship leader to follow the service live.")
            }

            leadSection
        }
        .navigationTitle("Live")
        .sheet(isPresented: $isPresentingJoinSheet) {
            LiveJoinSheet(rootViewModel: rootViewModel, onJoined: {})
        }
    }

    /// The "Now" row for a FOLLOWER (host controls render themselves via
    /// `LiveHostControlsView` above, which self-hides unless hosting) —
    /// self-hides unless currently following either namespace.
    @ViewBuilder
    private var followingRow: some View {
        switch rootViewModel.liveState {
        case .followingLeader(let hostDisplayName, let isFresh):
            Section("Now") {
                NavigationLink {
                    LiveFollowingView(rootViewModel: rootViewModel)
                } label: {
                    statusLabel("Following \(hostDisplayName)", isFresh: isFresh)
                }
            }
        case .followingService(let isFresh):
            Section("Now") {
                NavigationLink {
                    LiveFollowingView(rootViewModel: rootViewModel)
                } label: {
                    statusLabel("Following the service", isFresh: isFresh)
                }
            }
        case .hosting, .idle:
            EmptyView()
        }
    }

    private func statusLabel(_ text: String, isFresh: Bool) -> some View {
        HStack {
            Label(text, systemImage: "dot.radiowaves.left.and.right")
            Spacer()
            Text(isFresh ? "Live" : "Reconnecting…")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
    }

    /// Signed-in users can lead a session; signed-out users get a single
    /// honest footer line instead of a dead button.
    @ViewBuilder
    private var leadSection: some View {
        if rootViewModel.sessionState.isSignedIn {
            Section {
                Button {
                    Task { try? await rootViewModel.goLive() }
                } label: {
                    Label("Go Live", systemImage: "dot.radiowaves.left.and.right")
                }
                .disabled(isHostingOrFollowing)
            } footer: {
                Text("Broadcast the songs you open to everyone following your code.")
            }
        } else {
            Section {
                Text("Sign in to lead a live session.")
                    .foregroundStyle(.secondary)
            }
        }
    }

    private var isHostingOrFollowing: Bool {
        rootViewModel.liveState != .idle
    }
}

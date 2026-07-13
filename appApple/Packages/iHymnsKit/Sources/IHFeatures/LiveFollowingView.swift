// LiveFollowingView.swift
// IHFeatures
//
// ELI5: The "I'm following along" screen — shows who/what you're following,
// scrolls to whichever verse the leader/service is currently on, and lets
// you leave.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §5.1/§6.3, strategy §2.4.6, plan §2 PR-10). Content is API-FETCHED, never
// wire-carried (D-12): this view maps `rootViewModel.liveSnapshot?.songId`
// through `SongID(rawValue:)` and fetches via the EXISTING
// `rootViewModel.songDetail(id:)` — the same read every other song screen
// uses, so offline cache/retry/(dormant) content gating all apply for free.
// Renders the fetched song's components via the SHARED `SongComponentView`
// (the `SongComparisonView` precedent for reusing it outside
// `SongDetailView`) inside a `ScrollViewReader`, never forking a second
// lyric renderer. D-13: an unparsable legacy songId renders a calm row
// instead of crashing or silently dropping the follow. D-4: `state` is
// decoded but only surfaced as a passive status line — song + section is
// all that's applied, matching BOTH web follower clients exactly
// (`service-follow.js:120,169`, `live-follow.js:271,327-329`).
import IHAPI
import IHDesign
import IHModels
import SwiftUI

struct LiveFollowingView: View {
    let rootViewModel: AppRootViewModel
    @State private var songDetail: SongDetail?
    @State private var loadError: String?

    var body: some View {
        ScrollViewReader { proxy in
            ScrollView {
                content
                    .padding()
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            .onChange(of: rootViewModel.liveSnapshot?.componentIndex) { _, newIndex in
                guard let newIndex else { return }
                withAnimation { proxy.scrollTo(newIndex, anchor: .top) }
            }
        }
        .navigationTitle("Following")
        .safeAreaInset(edge: .top) { header }
        .task(id: rootViewModel.liveSnapshot?.songId) { await load() }
        .toolbar {
            ToolbarItem(placement: .primaryAction) {
                Button("Leave", role: .destructive) { Task { await rootViewModel.leaveLive() } }
            }
        }
    }

    @ViewBuilder
    private var header: some View {
        VStack(alignment: .leading, spacing: 2) {
            switch rootViewModel.liveState {
            case .followingLeader(let hostDisplayName, let isFresh):
                sourceLine("Following \(hostDisplayName)", tint: IHColorTokens.accent, isFresh: isFresh)
            case .followingService(let isFresh):
                sourceLine("Following the service", tint: .green, isFresh: isFresh)
            default:
                EmptyView()
            }
            if let resolved = rootViewModel.liveSnapshot?.state?.resolvedDisplayState, resolved != .live {
                Text("The venue display is paused.")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
        .padding(.horizontal)
        .padding(.vertical, 8)
        .background(.thinMaterial)
    }

    private func sourceLine(_ label: String, tint: Color, isFresh: Bool) -> some View {
        HStack(spacing: 6) {
            Label(label, systemImage: "dot.radiowaves.left.and.right")
                .foregroundStyle(tint)
            Text(isFresh ? "Live" : "Reconnecting…")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .accessibilityElement(children: .combine)
    }

    @ViewBuilder
    private var content: some View {
        if let loadError {
            IHLoadErrorView(title: "Couldn't Load Song", message: loadError) { await load() }
        } else if let songDetail {
            VStack(alignment: .leading, spacing: 20) {
                Text(songDetail.title).font(.title.bold())
                ForEach(Array(songDetail.orderedComponents.enumerated()), id: \.offset) { index, component in
                    SongComponentView(
                        component: component,
                        textScale: 1.0,
                        showChords: false,
                        enrichmentIndex: .empty,
                        onSelectLine: { _ in }
                    )
                    .id(index)
                }
            }
        } else {
            ContentUnavailableView("Waiting for the first song…", systemImage: "music.note")
        }
    }

    /// Fetches the currently-broadcast song, if any. D-13: a raw `songId`
    /// that doesn't parse as a `SongID` renders a calm explanatory row
    /// rather than crashing or silently doing nothing.
    private func load() async {
        guard let rawSongId = rootViewModel.liveSnapshot?.songId else {
            songDetail = nil
            loadError = nil
            return
        }
        guard let songId = SongID(rawValue: rawSongId) else {
            songDetail = nil
            loadError = "This song can't be displayed on this device yet."
            return
        }
        do {
            songDetail = try await rootViewModel.songDetail(id: songId)
            loadError = nil
        } catch APIError.unauthorized {
            songDetail = nil
            loadError = "This song isn't available on your account."
        } catch {
            loadError = "Couldn't load this song. Please try again."
        }
    }
}

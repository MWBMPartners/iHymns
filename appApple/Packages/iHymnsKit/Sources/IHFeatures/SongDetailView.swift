// SongDetailView.swift
// IHFeatures
//
// ELI5: The "read this one song" screen — title, songbook, then every
// verse/chorus/bridge in order, with a toolbar to make the text bigger or
// smaller.
//
// DETAILED: The #1399 E2E slice's detail half — reached by tapping a row
// in `CatalogueListView` (via `NavigationLink(value: song.id)` +
// `.navigationDestination(for: SongID.self)`). Owns a fresh
// `SongDetailViewModel` per song (constructed in `init`, held via `@State`
// per the standard "this VIEW owns this per-screen `@Observable` object"
// pattern — as opposed to `AppRootViewModel`, which the app shell owns and
// hands down). The toolbar's text-size control is deliberately the ONLY
// Liquid-Glass chrome this task's brief asks for ("a Liquid-Glass toolbar
// (text-size is enough for now)") — a `ToolbarItemGroup` automatically
// renders with the system's Liquid Glass toolbar treatment on OS 26 with no
// extra code here (see `IHGlassCard.swift`'s header for why bespoke glass,
// as opposed to system-provided glass like this, is the exception not the
// rule).
import IHModels
import SwiftUI

/// Displays one song's full lyrics, fetched live from `?action=song_detail`.
public struct SongDetailView: View {
    @State private var viewModel: SongDetailViewModel
    @State private var textScale: CGFloat = 1.0

    public init(songId: SongID, rootViewModel: AppRootViewModel) {
        _viewModel = State(initialValue: SongDetailViewModel(songId: songId, rootViewModel: rootViewModel))
    }

    public var body: some View {
        ScrollView {
            content
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .navigationTitle(navigationTitleText)
        .task { await viewModel.loadIfNeeded() }
        .toolbar { textSizeToolbar }
    }

    /// The nav-bar/window title — the real song title once loaded, a
    /// neutral placeholder otherwise (never blank, so the back button/
    /// window titlebar never flashes empty while the fetch is in flight).
    private var navigationTitleText: String {
        if case .loaded(let detail) = viewModel.loadState {
            detail.title
        } else {
            "Song"
        }
    }

    @ViewBuilder
    private var content: some View {
        switch viewModel.loadState {
        case .idle, .loading:
            ProgressView("Loading song…")
                .frame(maxWidth: .infinity, minHeight: 200)

        case .error(let message):
            ContentUnavailableView(
                "Couldn't Load Song",
                systemImage: "wifi.exclamationmark",
                description: Text(message)
            )

        case .loaded(let detail):
            VStack(alignment: .leading, spacing: 20) {
                header(for: detail)
                ForEach(Array(detail.components.enumerated()), id: \.offset) { _, component in
                    SongComponentView(component: component, textScale: textScale)
                }
            }
        }
    }

    private func header(for detail: SongDetail) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(detail.title)
                .font(.largeTitle.bold())

            HStack(spacing: 8) {
                if let number = detail.number {
                    Text(String(number))
                }
                Text(detail.songbookName.isEmpty ? detail.songbookAbbreviation : detail.songbookName)
            }
            .font(.subheadline)
            .foregroundStyle(.secondary)
        }
    }

    /// The one bit of chrome this task's minimal slice asks for: bigger/
    /// smaller lyric text, clamped to a sane range so it can never shrink
    /// to unreadable or grow off-screen.
    private var textSizeToolbar: some ToolbarContent {
        ToolbarItemGroup(placement: .primaryAction) {
            Button {
                textScale = max(0.75, textScale - 0.1)
            } label: {
                Label("Smaller Text", systemImage: "textformat.size.smaller")
            }
            Button {
                textScale = min(2.0, textScale + 0.1)
            } label: {
                Label("Larger Text", systemImage: "textformat.size.larger")
            }
        }
    }
}

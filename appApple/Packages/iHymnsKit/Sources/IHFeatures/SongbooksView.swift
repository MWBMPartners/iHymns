// SongbooksView.swift
// IHFeatures
//
// ELI5: The "browse hymn books" screen — every songbook as a tile, grouped
// into named series where the catalogue has one (e.g. all six "Songs Of
// Fellowship" books together), with an "Unofficial" pill on curator-made
// groupings. Tap a tile to see that book's songs, tap a song to read it.
//
// DETAILED: #1437 (Apple P1 catalogue-nav songbooks browse). Fetches
// `APIClient.songbooks()` through `AppRootViewModel.songbooks()` (the same
// "expose the operation, not the engine" pass-through `songDetail(id:)`/
// `songLinks(id:)`/`relatedSongs(id:)` already use) via its own
// `SongbooksViewModel`, mirroring `SongDetailView`'s "this VIEW owns its
// own per-screen `@Observable` object" shape rather than growing
// `AppRootViewModel` itself with a second, unrelated load state.
//
// Registers its OWN `.navigationDestination` for both `Songbook` (→
// `SongbookSongsView`) and `SongID` (→ `SongDetailView`) — necessary
// because this screen can be hosted in a DIFFERENT navigation container
// from `CatalogueListView` (its own `NavigationStack` tab on iPhone,
// `RootContainerView`'s #1437 section-switcher): a `.navigationDestination`
// modifier only applies to pushes made from WITHIN its own stack, so each
// stack that might push a `SongID` needs its own copy of that modifier even
// though the destination VIEW (`SongDetailView`) is fully shared.
//
// Never filters `Songbook.isOfficial == false` books out (CLAUDE.md rule
// #24) — every songbook the server returns gets a tile, official or not,
// distinguished only by `IHUnofficialBadge`.
import IHDesign
import IHModels
import SwiftUI

/// The songbooks browse screen: every songbook as a tile, grouped by series
/// where the catalogue defines one.
public struct SongbooksView: View {
    @State private var viewModel: SongbooksViewModel
    private let rootViewModel: AppRootViewModel

    private static let columns = [GridItem(.adaptive(minimum: 160), spacing: 12)]

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
        _viewModel = State(initialValue: SongbooksViewModel(rootViewModel: rootViewModel))
    }

    public var body: some View {
        content
            .navigationTitle("Songbooks")
            .navigationDestination(for: Songbook.self) { songbook in
                SongbookSongsView(songbook: songbook, rootViewModel: rootViewModel)
            }
            // Two SongID-family destinations, kept SIDE BY SIDE (#185): a
            // bare `SongID` still lands on a plain `SongDetailView` (nothing
            // inside `SongbookSongsView` pushes that anymore, but
            // `SongMetadataView`/`RelatedSongsShelfView`/`SongWorksSection`
            // INSIDE whichever `SongDetailView` is currently showing still
            // push bare `SongID`s of their own — e.g. tapping a "Related
            // Songs" shelf item — and those pushes bubble up to THIS stack's
            // nearest registered destination, so removing this would silently
            // break them). `SongPagerRequest` is the new swipe/keyboard
            // -paged destination `SongbookSongsView`'s own rows push instead.
            .navigationDestination(for: SongID.self) { songId in
                SongDetailView(songId: songId, rootViewModel: rootViewModel)
            }
            .navigationDestination(for: SongPagerRequest.self) { request in
                SongPagerView(initialSongId: request.songId, context: request.context, rootViewModel: rootViewModel)
            }
            .task { await viewModel.loadIfNeeded() }
    }

    @ViewBuilder
    private var content: some View {
        switch viewModel.loadState {
        case .idle, .loading:
            ProgressView("Loading songbooks…")
                .frame(maxWidth: .infinity, maxHeight: .infinity)

        case .error(let message):
            // #185 — shared retry-capable error card; `viewModel.load()` is
            // the exact "future manual retry" hook its own doc comment
            // already anticipated.
            IHLoadErrorView(title: "Couldn't Load Songbooks", message: message) {
                await viewModel.load()
            }

        case .loaded(let songbooks):
            if songbooks.isEmpty {
                ContentUnavailableView(
                    "No Songbooks Yet",
                    systemImage: "books.vertical",
                    description: Text("No songbooks are published yet.")
                )
            } else {
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 24) {
                        ForEach(SongbookGroup.grouped(from: songbooks)) { group in
                            groupSection(group)
                        }
                    }
                    .padding()
                }
                // #185 — pull-to-refresh re-fetches the songbook list.
                .refreshable { await viewModel.load() }
            }
        }
    }

    private func groupSection(_ group: SongbookGroup) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(group.title)
                .font(.title3.bold())
                // #188 — a heading so VoiceOver's heading rotor can jump
                // between songbook groups (Official / Unofficial / Series)
                // instead of swiping every tile (WCAG 1.3.1 / 2.4.1).
                .accessibilityAddTraits(.isHeader)

            LazyVGrid(columns: Self.columns, spacing: 12) {
                ForEach(group.songbooks) { book in
                    NavigationLink(value: book) {
                        SongbookTileView(songbook: book)
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }
}

// SetlistCatalogueBrowserView.swift
// IHFeatures
//
// ELI5: A little searchable song list, just for picking songs to add to a
// setlist — tap a row (or, on iPad/Mac, drag it) to add it, with a checkmark
// on songs already in the setlist. Reused by BOTH the iPhone "Add Songs"
// sheet AND the iPad/Mac setlist workbench's side pane, per the repo's
// modularity rule ("if a shared module already exists, reuse it").
//
// DETAILED: #181's setlists half, strategy §2.2's iPad "setlist workbench"
// ("drag songs catalogue→setlist"). Deliberately does NOT reuse
// `AppRootViewModel.searchText`/`filteredSongs` — those drive the app's MAIN
// Search tab, and this browser can be open (as a sheet, or as the
// workbench's side pane) WHILE that tab's own search state is completely
// unrelated; clobbering `searchText` here would corrupt whatever the user
// had typed in the real Search tab. Instead this view owns its OWN local
// `@State searchText` and does a simple case-insensitive substring match
// against title/songbook/number — good enough for "find a song to add,"
// without pulling in `AppRootViewModel+Search.swift`'s fuller
// number-mode/facet-filter machinery, which is scoped to the main browse
// experience.
//
// `.draggable(_:)` on each row (regular-width workbench use) carries the
// plain `SongID.rawValue` string — a `Transferable String`, not a custom
// `Transferable` song type, keeping the drag payload tiny and avoiding a new
// conformance just for this one interaction; `SetlistDetailView`'s
// `.dropDestination(for: String.self)` re-parses it back into a `SongID` and
// resolves the matching `SongSummary` to build the `SetlistSongEntry`. Tap
// -to-add always works too, on every platform/size class — drag is a native
// -enhanced EXTRA, never the only way to add a song (strategy §2.3's "native
// -enhanced edge, not a replacement for the reliable baseline" posture).
import IHDesign
import IHModels
import SwiftUI

/// A searchable, filtered view of the catalogue index for picking songs to
/// add to a setlist.
public struct SetlistCatalogueBrowserView: View {
    @Bindable private var rootViewModel: AppRootViewModel
    private let existingSongIds: Set<SongID>
    private let onAdd: (SongSummary) -> Void

    @State private var searchText = ""

    public init(rootViewModel: AppRootViewModel, existingSongIds: Set<SongID>, onAdd: @escaping (SongSummary) -> Void) {
        self.rootViewModel = rootViewModel
        self.existingSongIds = existingSongIds
        self.onAdd = onAdd
    }

    public var body: some View {
        content
            .searchable(text: $searchText, prompt: "Search songs to add")
            .task { await rootViewModel.loadCatalogueIfNeeded() }
    }

    @ViewBuilder
    private var content: some View {
        switch rootViewModel.catalogueLoadState {
        case .idle, .loading:
            ProgressView("Loading songs…")
                .frame(maxWidth: .infinity, maxHeight: .infinity)

        case .error(let message):
            ContentUnavailableView(
                "Couldn't Load Songs",
                systemImage: "wifi.exclamationmark",
                description: Text(message)
            )

        case .loaded(let songs):
            if matchingSongs(in: songs).isEmpty {
                ContentUnavailableView.search(text: searchText)
            } else {
                List(matchingSongs(in: songs)) { song in
                    row(for: song)
                }
                .listStyle(.plain)
            }
        }
    }

    private func row(for song: SongSummary) -> some View {
        Button {
            onAdd(song)
        } label: {
            HStack {
                SongSummaryRow(song)
                if existingSongIds.contains(song.songId) {
                    Image(systemName: "checkmark.circle.fill")
                        .foregroundStyle(IHColorTokens.accent)
                }
            }
        }
        .buttonStyle(.plain)
        .draggable(song.songId.rawValue)
    }

    /// Case-insensitive substring match against title/songbook/number — see
    /// this file's header for why this is a deliberately simpler filter
    /// than the main Search tab's.
    private func matchingSongs(in songs: [SongSummary]) -> [SongSummary] {
        let query = searchText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !query.isEmpty else { return songs }
        return songs.filter { song in
            song.title.localizedCaseInsensitiveContains(query)
                || song.songbookAbbreviation.localizedCaseInsensitiveContains(query)
                || song.displayNumber.localizedCaseInsensitiveContains(query)
        }
    }
}

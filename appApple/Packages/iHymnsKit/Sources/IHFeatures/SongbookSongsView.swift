// SongbookSongsView.swift
// IHFeatures
//
// ELI5: The list of every song inside ONE songbook — tap a tile on the
// Songbooks screen and land here, then tap a song to read it.
//
// DETAILED: #1437 (Apple P1 Songbooks browse). Filters the SAME cached
// `songs_index` `AppRootViewModel` already loads (or loads on demand, via
// `loadCatalogueIfNeeded()`) rather than making a second, per-book network
// call — the local index already carries `songbookAbbreviation` for every
// row, so this is a pure client-side filter, matching #1436's own
// local-first search philosophy and never re-fetching data the app already
// has (the web API's `?action=songbook&abbr=` equivalent exists, but
// there's no reason to hit the network again for data already sitting in
// `catalogueLoadState`). Reuses `SongSummaryRow` — the shared song-list row
// — exactly like `CatalogueListView` does, per the repo's modularity rule.
//
// #186 UPDATE (Apple Phase 1, "Sharing & social") — adds the same "Share"
// toolbar affordance `SongDetailToolbarContent`/`SetlistDetailView` already
// have, so a songbook is one of the "every shareable entity" surfaces this
// task's brief lists. Built via `IHAppSupport.CanonicalURL.songbook(abbreviation:)`
// — the ONE shared URL-builder, never a second hand-rolled string here.
//
// #1439 UPDATE — adds a "Save All for Offline" toolbar action alongside
// Share, presenting `SongbookBulkSaveView` (its own file — see that file's
// header for why the confirmation/progress/result UI isn't inlined here)
// with exactly this screen's own `songsInBook` filter, so the sheet never
// re-fetches or re-derives what songs belong to this book.
import IHAppSupport
import IHDesign
import IHModels
import SwiftUI

/// Every song in one songbook, reusing the shared `SongSummaryRow`.
struct SongbookSongsView: View {
    let songbook: Songbook
    let rootViewModel: AppRootViewModel

    @State private var isPresentingBulkSaveSheet = false

    var body: some View {
        content
            .navigationTitle(songbook.name)
            .task { await rootViewModel.loadCatalogueIfNeeded() }
            .toolbar {
                ToolbarItem(placement: .primaryAction) {
                    Button {
                        isPresentingBulkSaveSheet = true
                    } label: {
                        Label("Save All for Offline", systemImage: "arrow.down.circle")
                    }
                    .disabled(songsInBook.isEmpty)
                }
                // `ShareLink`/`SharePreview` are UNAVAILABLE on tvOS (D-1,
                // #1504's tvOS-build gate) — there's no share-sheet
                // destination on that platform, so the whole toolbar item
                // is simply omitted there rather than forking a second
                // toolbar.
                #if !os(tvOS)
                ToolbarItem(placement: .primaryAction) {
                    if let shareURL = CanonicalURL.songbook(abbreviation: songbook.id) {
                        ShareLink(item: shareURL, preview: SharePreview(songbook.name)) {
                            Label("Share", systemImage: "square.and.arrow.up")
                        }
                    }
                }
                #endif
            }
            .sheet(isPresented: $isPresentingBulkSaveSheet) {
                SongbookBulkSaveView(songbookName: songbook.name, songs: songsInBook, rootViewModel: rootViewModel)
            }
    }

    /// This book's songs, filtered from the shared catalogue index — the
    /// SAME filter `content`'s `.loaded` branch already applies, hoisted up
    /// so BOTH that branch and the toolbar's "Save All" button (which needs
    /// the count/emptiness even while `catalogueLoadState` is still
    /// `.loading`, hence the `[]` default) can read it without duplicating
    /// the filter predicate.
    private var songsInBook: [SongSummary] {
        guard case .loaded(let songs) = rootViewModel.catalogueLoadState else { return [] }
        return songs.filter { $0.songbookAbbreviation.caseInsensitiveCompare(songbook.id) == .orderedSame }
    }

    @ViewBuilder
    private var content: some View {
        switch rootViewModel.catalogueLoadState {
        case .idle, .loading:
            ProgressView("Loading songs…")
                .frame(maxWidth: .infinity, maxHeight: .infinity)

        case .error(let message):
            // #185 — shared retry-capable error card; this screen filters
            // the SAME shared `catalogueLoadState` `CatalogueListView`/
            // `SetlistCatalogueBrowserView` read, so its retry re-fetches
            // that one shared index, same as theirs.
            IHLoadErrorView(title: "Couldn't Load Songs", message: message) {
                await rootViewModel.loadCatalogue()
            }

        case .loaded:
            let booksSongs = songsInBook
            if booksSongs.isEmpty {
                ContentUnavailableView(
                    "No Songs Yet",
                    systemImage: "music.note.list",
                    description: Text("This songbook has no songs in the catalogue yet.")
                )
            } else {
                // #185 — every row pushes a `SongPagerRequest` (not a bare
                // `SongID`) carrying this WHOLE book's song order, so the
                // reading screen can swipe/arrow-key through the rest of the
                // hymnal — see `SongNavigationContext.swift`'s header.
                let context = SongNavigationContext(songIds: booksSongs.map(\.id))
                List(booksSongs) { song in
                    NavigationLink(value: SongPagerRequest(songId: song.id, context: context)) {
                        SongSummaryRow(song)
                    }
                }
                .listStyle(.plain)
                // #185 — pull-to-refresh re-fetches the shared catalogue
                // index this book's song list is filtered from.
                .refreshable { await rootViewModel.loadCatalogue() }
            }
        }
    }
}

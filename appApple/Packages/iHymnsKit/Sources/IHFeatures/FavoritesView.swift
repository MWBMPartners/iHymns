// FavoritesView.swift
// IHFeatures
//
// ELI5: The "songs I've favourited" screen — tap one to read it, swipe to
// un-favourite. Signed out shows a sign-in prompt instead (favourites need
// an account); freshly signed-in-but-nothing-favourited-yet shows a plain
// "no favourites" message instead of an empty list.
//
// DETAILED: #181's list surface. Reads `AppRootViewModel.favorites`
// directly — same "the root view model IS the state, the view just
// renders it" shape `CatalogueListView` already uses for
// `catalogueLoadState`/`filteredSongs`. Reuses the shared `SongSummaryRow`
// (via `FavoriteSong.summary`) rather than drawing its own row, per the
// repo's modularity rule. Registers its OWN `.navigationDestination(for:
// SongID.self)` for the same reason `HomeView`/`SongbooksView`/
// `CatalogueListView` each do — this screen can be hosted in its own
// `NavigationStack` (iPhone tab) or as a `NavigationSplitView` content
// column (iPad/Mac/vision), and a `.navigationDestination` only applies to
// pushes made from WITHIN its own stack.
import IHAuth
import IHModels
import SwiftUI

/// The favourites list: every song the signed-in user has favourited,
/// most-recently-added first.
public struct FavoritesView: View {
    private let rootViewModel: AppRootViewModel

    @State private var isPresentingLogin = false

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
    }

    public var body: some View {
        content
            .navigationTitle("Favourites")
            .navigationDestination(for: SongID.self) { songId in
                SongDetailView(songId: songId, rootViewModel: rootViewModel)
            }
            .sheet(isPresented: $isPresentingLogin) {
                LoginView(rootViewModel: rootViewModel)
            }
            .task {
                await rootViewModel.loadFavoritesIfNeeded()
                await rootViewModel.refreshFavoritesFromServer()
            }
    }

    @ViewBuilder
    private var content: some View {
        if !rootViewModel.sessionState.isSignedIn {
            signedOutView
        } else if rootViewModel.favorites.isEmpty {
            emptyView
        } else {
            List(rootViewModel.favorites) { favorite in
                NavigationLink(value: favorite.songId) {
                    SongSummaryRow(displaySummary(for: favorite))
                }
                .swipeActions(edge: .trailing) {
                    Button(role: .destructive) {
                        Task { await remove(favorite) }
                    } label: {
                        Label("Remove", systemImage: "heart.slash")
                    }
                }
            }
            .listStyle(.plain)
            // #185 — pull-to-refresh re-syncs favourites with the server
            // (favourites has no `LoadState`/error surface by design — see
            // `AppRootViewModel+Favorites.swift`'s header — so there's no
            // error-retry card to add here, just a manual sync trigger).
            .refreshable { await rootViewModel.refreshFavoritesFromServer() }
        }
    }

    private var signedOutView: some View {
        ContentUnavailableView {
            Label("Sign In to See Favourites", systemImage: "heart")
        } description: {
            Text("Favourites are synced to your account across every device.")
        } actions: {
            Button("Sign In") { isPresentingLogin = true }
                .buttonStyle(.borderedProminent)
        }
    }

    private var emptyView: some View {
        ContentUnavailableView(
            "No Favourites Yet",
            systemImage: "heart",
            description: Text("Tap the heart on any song to add it here.")
        )
    }

    /// The favourite as a `SongSummary` for `SongSummaryRow`, with a
    /// fallback title when this device has never actually seen this song's
    /// metadata (a favourite pulled from another device via first-login
    /// backfill — see `FavoriteSong`'s own header for why `title` can be
    /// `""`). Showing the raw `SongID` is honest about "we know you
    /// favourited this, we just don't have its title cached yet" rather
    /// than rendering a blank row.
    private func displaySummary(for favorite: FavoriteSong) -> SongSummary {
        guard favorite.title.isEmpty else { return favorite.summary }
        return SongSummary(
            songId: favorite.songId,
            title: favorite.songId.rawValue,
            songbookAbbreviation: favorite.songbookAbbreviation,
            number: favorite.number
        )
    }

    private func remove(_ favorite: FavoriteSong) async {
        await rootViewModel.toggleFavorite(
            songId: favorite.songId,
            title: favorite.title,
            songbookAbbreviation: favorite.songbookAbbreviation,
            number: favorite.number
        )
    }
}

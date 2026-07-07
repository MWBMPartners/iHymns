// HomeView.swift
// IHFeatures
//
// ELI5: The app's front page — today's featured hymn up top, a "Continue
// Reading" card if you were partway through one, a quick way to browse the
// hymnals, and a shelf of songs you've looked at recently.
//
// DETAILED: #183 (Apple P1 Home surface). Strategy §2.2 describes the
// iPhone Home as "SOTD hero + resume + live banner + favourites shelf" —
// this task's own brief narrows that to what's honestly buildable THIS
// phase: a SOTD hero card, a resume card, a "Browse Songbooks" quick-access
// card, and a "Recently Viewed" shelf IN PLACE OF a favourites shelf
// (favourites aren't wired yet — showing a fake/empty favourites shelf
// would be exactly the "faked" surface this task explicitly says not to
// build). No live banner either, for the identical reason: Live Follow/
// Service Mode client engines don't exist yet in this package
// (`RootContainerView`'s new "Live" tab is its own honest "coming soon"
// placeholder instead — see that file's header).
//
// Registers its OWN `.navigationDestination(for: SongID.self)` — necessary
// because Home is hosted in its OWN `NavigationStack` (iPhone's Home tab,
// or `RootContainerView`'s `.home` section on iPad/Mac/vision), the exact
// same reasoning `SongbooksView`'s own header comment already documents for
// why every stack that might push a `SongID` needs its own copy of that
// modifier even though the destination VIEW (`SongDetailView`) is fully
// shared.
import IHDesign
import IHModels
import SwiftUI

/// The Home screen: Song of the Day, resume-last-song, browse songbooks,
/// and a recently-viewed shelf.
public struct HomeView: View {
    @State private var viewModel: HomeViewModel
    private let rootViewModel: AppRootViewModel

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
        _viewModel = State(initialValue: HomeViewModel(rootViewModel: rootViewModel))
    }

    public var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {
                songOfTheDaySection
                resumeSection
                browseSongbooksCard
                recentlyViewedSection
            }
            .padding()
        }
        .navigationTitle("Home")
        .navigationDestination(for: SongID.self) { songId in
            SongDetailView(songId: songId, rootViewModel: rootViewModel)
        }
        .task { await viewModel.loadIfNeeded() }
    }

    // MARK: - Song of the Day

    @ViewBuilder
    private var songOfTheDaySection: some View {
        switch viewModel.songOfTheDayState {
        case .idle, .loading:
            ProgressView("Loading today's song…")
                .frame(maxWidth: .infinity, minHeight: 140)
                .ihGlassCard(cornerRadius: 28)

        case .error(let message):
            ContentUnavailableView(
                "Couldn't Load Song of the Day",
                systemImage: "wifi.exclamationmark",
                description: Text(message)
            )

        case .loaded(let sotd):
            if let song = sotd.song {
                NavigationLink(value: song.songId) {
                    SongOfTheDayHeroCard(sotd: sotd, song: song)
                }
                .buttonStyle(.plain)
            }
            // `sotd.song == nil` only when the whole catalogue is empty
            // (`SongOfTheDay.swift`'s own doc comment) — nothing sensible
            // to show, so render nothing, matching `SongDetailView`'s own
            // "just hide it" posture for a secondary section with no
            // content, rather than a scary error card for something that
            // isn't really an error.
        }
    }

    // MARK: - Resume ("Continue Reading")

    @ViewBuilder
    private var resumeSection: some View {
        if let resume = viewModel.resumeSong {
            NavigationLink(value: resume.songId) {
                HStack(spacing: 12) {
                    Image(systemName: "arrow.uturn.forward.circle.fill")
                        .font(.title2)
                        .foregroundStyle(IHColorTokens.accent)

                    VStack(alignment: .leading, spacing: 2) {
                        Text("Continue Reading")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Text(resume.title)
                            .font(.headline)
                        Text(shelfSubtitle(for: resume))
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }

                    Spacer(minLength: 0)
                    Image(systemName: "chevron.right")
                        .foregroundStyle(.secondary)
                }
                .ihGlassCard()
            }
            .buttonStyle(.plain)
            .accessibilityElement(children: .combine)
        }
        // No `resumeSong` yet (a fresh install, or `RecentlyViewedStore`
        // cleared) → render nothing. There is genuinely nothing to
        // "continue" — an empty-state card here would just be noise above
        // the "Browse Songbooks" card that already invites the user to
        // start reading something.
    }

    // MARK: - Browse Songbooks

    /// "Browse Songbooks" quick-access card — a plain destination push
    /// (not a `NavigationLink(value:)`) since Home's own
    /// `.navigationDestination` above only registers `SongID`, and
    /// `SongbooksView` already registers its OWN `Songbook`/`SongID`
    /// destinations for whatever the user does once inside it (see that
    /// file's header for why every hosting stack needs its own copy).
    private var browseSongbooksCard: some View {
        NavigationLink {
            SongbooksView(rootViewModel: rootViewModel)
        } label: {
            HStack(spacing: 12) {
                Image(systemName: "books.vertical.fill")
                    .font(.title2)
                    .foregroundStyle(IHColorTokens.accent)
                Text("Browse Songbooks")
                    .font(.headline)
                Spacer(minLength: 0)
                Image(systemName: "chevron.right")
                    .foregroundStyle(.secondary)
            }
            .ihGlassCard()
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .combine)
    }

    // MARK: - Recently Viewed

    /// The "Recently Viewed" shelf — reuses `RelatedSongsShelfView`
    /// (`SongDetailView`'s own shared shelf, see that file's header) rather
    /// than a fourth hand-rolled horizontal row list; hidden entirely when
    /// there's nothing to show yet (a fresh install, or every recent entry
    /// is already featured as "Continue Reading" above).
    @ViewBuilder
    private var recentlyViewedSection: some View {
        if !viewModel.recentlyViewedShelfSongs.isEmpty {
            RelatedSongsShelfView(
                title: "Recently Viewed",
                items: viewModel.recentlyViewedShelfSongs.map(RelatedShelfItem.init(recentlyViewed:))
            )
        }
    }

    private func shelfSubtitle(for song: RecentlyViewedSong) -> String {
        let numberText = song.number.map(String.init) ?? ""
        return numberText.isEmpty ? song.songbookAbbreviation : "\(song.songbookAbbreviation) \(numberText)"
    }
}

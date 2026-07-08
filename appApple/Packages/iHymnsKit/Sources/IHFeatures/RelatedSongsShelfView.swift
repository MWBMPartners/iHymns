// RelatedSongsShelfView.swift
// IHFeatures
//
// ELI5: One reusable "here are some other songs" list — used for "Also
// appears as" (the exact same hymn in a different book) and "Related Songs"
// (same writer/composer/neighbourhood) on the song screen, PLUS (#183)
// Home's "Recently Viewed" shelf — so there's only ONE shelf
// implementation, not three near-identical copies.
//
// DETAILED: The repo's modularity rule applied to this Swift layer, same as
// `SongSummaryRow`'s own header explains — `SongDetailView` (#180) needs
// TWO shelves that only differ in title + row content, so this file is the
// ONE shared shelf, parameterized by a small `RelatedShelfItem` the caller
// builds from `SongLinkGroup.songs`, `[RelatedSongSummary]`, or (#183)
// `[RecentlyViewedSong]` (see the `init` overloads below). `HomeView`
// reusing this exact type for its "Recently Viewed" shelf — rather than
// hand-rolling a fourth near-identical horizontally-scrolling row list — is
// precisely the "if a shared module already exists, reuse it" rule this
// package's other screens already follow. `NavigationLink(value: SongID.self)`
// reuses `CatalogueListView`'s existing `.navigationDestination(for:
// SongID.self)` — pushing from here lands in the SAME stack the hosting
// screen registered that destination on (`SongDetailView`'s own stack, or
// `HomeView`'s own — see that file's header for why each hosting stack
// needs its own copy of the modifier), so tapping a row navigates straight
// to it with no extra wiring in this file.
//
// #1455 UPDATE — an additive, defaulted `onCompare` closure adds a
// "Compare with This" context-menu action per row, reusing #1445's
// `SongComparisonView` push. SHELF-LEVEL (not per-`RelatedShelfItem`,
// despite this feature's own GitHub issue sketching it as a per-item
// property): every row in one shelf instance compares against the SAME
// "current song" the hosting screen anchors to, so one closure taking the
// tapped row's id is simpler than threading an identical callback onto
// every item. `nil` (the default, and `HomeView`'s own "Recently Viewed"
// call site) omits the context menu entirely — Home's shelf has no natural
// "current song" to compare FROM (this feature's issue explicitly flags
// that as a separate, undecided "pick both sides" design), so it
// deliberately stays out of scope here rather than shipping a half-built
// picker.
import IHDesign
import IHModels
import SwiftUI

/// One row's worth of "another song" content — title, subtitle (songbook
/// abbreviation + number, or a match reason), and its `SongID` to navigate
/// to.
struct RelatedShelfItem: Identifiable {
    let id: SongID
    let title: String
    let subtitle: String

    /// Builds a row from a `song_links` counterpart.
    init(counterpart song: SongLinkedSong) {
        id = song.songId
        title = song.title
        let numberText = song.number.map(String.init) ?? ""
        subtitle = numberText.isEmpty ? song.songbookAbbreviation : "\(song.songbookAbbreviation) \(numberText)"
    }

    /// Builds a row from a `related_songs` match, preferring its
    /// human-readable `reason` as the subtitle since that's the whole point
    /// of showing it (e.g. "Same writer: John Newton").
    init(related song: RelatedSongSummary) {
        id = song.songId
        title = song.title
        subtitle = song.reason ?? song.songbookAbbreviation
    }

    /// Builds a row from a `RecentlyViewedStore` entry (#183, `HomeView`'s
    /// "Recently Viewed" shelf) — same songbook-abbreviation-plus-number
    /// subtitle shape as `init(counterpart:)` above, since both are "just a
    /// plain song from the catalogue," not a search-match with a reason.
    init(recentlyViewed song: RecentlyViewedSong) {
        id = song.songId
        title = song.title
        let numberText = song.number.map(String.init) ?? ""
        subtitle = numberText.isEmpty ? song.songbookAbbreviation : "\(song.songbookAbbreviation) \(numberText)"
    }
}

/// A titled, horizontally-scrolling shelf of other songs.
struct RelatedSongsShelfView: View {
    let title: String
    let items: [RelatedShelfItem]

    /// #1455 — see this file's header for why this is shelf-level and why
    /// `nil` (the default) is a real, intentional state, not deferred work.
    var onCompare: ((SongID) -> Void)?

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(title)
                .font(.headline)
                // #188 — marks this as a heading so VoiceOver's heading rotor
                // can jump straight to each shelf (WCAG 1.3.1 Info &
                // Relationships / 2.4.1 Bypass Blocks).
                .accessibilityAddTraits(.isHeader)

            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 12) {
                    ForEach(items) { item in
                        NavigationLink(value: item.id) {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(item.title)
                                    .font(.subheadline.bold())
                                    .lineLimit(2)
                                Text(item.subtitle)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                                    .lineLimit(1)
                            }
                            .frame(width: 160, alignment: .leading)
                            .ihGlassCard()
                        }
                        .buttonStyle(.plain)
                        .contextMenu {
                            if let onCompare {
                                Button {
                                    onCompare(item.id)
                                } label: {
                                    Label("Compare with This", systemImage: "rectangle.split.2x1")
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

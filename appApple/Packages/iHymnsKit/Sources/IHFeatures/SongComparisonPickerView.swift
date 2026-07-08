// SongComparisonPickerView.swift
// IHFeatures
//
// ELI5: The "pick a second song to compare against" sheet — shows the
// current song's known counterparts and related songs first (since those
// are the songs most likely to actually BE "the same hymn, different
// hymnal"), then lets you search the whole catalogue if neither has what
// you're after.
//
// DETAILED: #1445. Suggestions reuse data `SongDetailView` already fetched
// (`SongLinkGroup.songs` via `?action=song_links`, `[RelatedSongSummary]`
// via `?action=related_songs`) — the picker never issues a second network
// call for either, mirroring the comparison plan's own "no second fetch"
// instruction. The catalogue-search half deliberately reuses
// `SetlistCatalogueBrowserView`'s exact local-filter idiom (own `@State
// searchText`, simple case-insensitive substring match against title/
// songbook/number over `rootViewModel.catalogueLoadState`) rather than
// `AppRootViewModel.searchText`/`filteredSongs` — clobbering the Search
// tab's own in-progress query from inside a sheet would be a confusing
// side effect the user never asked for. This is NOT the same view as
// `SetlistCatalogueBrowserView` itself, though: that view's `.draggable`
// payload and "checkmark for already-added" semantics are setlist-specific
// and would contort awkwardly around this screen's "pick exactly one"
// single-select job plus its two suggestion sections above the search
// list.
//
// Every row — suggestion or search result — renders via the shared
// `SongSummaryRow` (the repo's modularity rule: "if a shared module
// already exists, reuse it"), so a `SongLinkedSong`/`RelatedSongSummary`
// row first gets folded into a plain `SongSummary` (`Self.summary(for:)`
// below) rather than growing a second, bespoke row layout just for this
// screen.
import IHDesign
import IHModels
import SwiftUI

/// A sheet for picking the SECOND song to compare the current one against —
/// suggested counterparts/related songs first, then a searchable catalogue.
public struct SongComparisonPickerView: View {
    @Bindable private var rootViewModel: AppRootViewModel
    private let currentSongId: SongID
    private let counterparts: [SongLinkedSong]
    private let relatedSongs: [RelatedSongSummary]
    private let onSelect: (SongID) -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var searchText = ""

    public init(
        rootViewModel: AppRootViewModel,
        currentSongId: SongID,
        counterparts: [SongLinkedSong],
        relatedSongs: [RelatedSongSummary],
        onSelect: @escaping (SongID) -> Void
    ) {
        self.rootViewModel = rootViewModel
        self.currentSongId = currentSongId
        self.counterparts = counterparts
        self.relatedSongs = relatedSongs
        self.onSelect = onSelect
    }

    public var body: some View {
        NavigationStack {
            List {
                if !counterparts.isEmpty {
                    Section("Also Appears As") {
                        ForEach(counterparts) { song in
                            row(for: Self.summary(for: song))
                        }
                    }
                }
                if !relatedSongs.isEmpty {
                    Section("Related Songs") {
                        ForEach(relatedSongs) { song in
                            row(for: Self.summary(for: song))
                        }
                    }
                }
                Section("All Songs") {
                    catalogueContent
                }
            }
            .searchable(text: $searchText, prompt: "Search songs to compare")
            .navigationTitle("Compare With…")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
            .task { await rootViewModel.loadCatalogueIfNeeded() }
        }
    }

    @ViewBuilder
    private var catalogueContent: some View {
        switch rootViewModel.catalogueLoadState {
        case .idle, .loading:
            ProgressView("Loading songs…")

        case .error(let message):
            // #185 — shared retry-capable error card; `loadCatalogue()` is
            // the SAME shared-index force-refetch every other catalogue
            // consumer in this package retries into.
            IHLoadErrorView(title: "Couldn't Load Songs", message: message) {
                await rootViewModel.loadCatalogue()
            }

        case .loaded(let songs):
            let matches = matchingSongs(in: songs)
            if matches.isEmpty {
                ContentUnavailableView.search(text: searchText)
            } else {
                ForEach(matches) { song in
                    row(for: song)
                }
            }
        }
    }

    private func row(for song: SongSummary) -> some View {
        Button {
            onSelect(song.songId)
            dismiss()
        } label: {
            SongSummaryRow(song)
        }
        .buttonStyle(.plain)
    }

    /// Case-insensitive substring match against title/songbook/number, and
    /// excludes `currentSongId` (comparing a song with itself is
    /// meaningless) — same filter shape
    /// `SetlistCatalogueBrowserView.matchingSongs(in:)` uses, minus its
    /// "already added" concept, which doesn't apply to a single-select
    /// picker.
    private func matchingSongs(in songs: [SongSummary]) -> [SongSummary] {
        let candidates = songs.filter { $0.songId != currentSongId }
        let query = searchText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !query.isEmpty else { return candidates }
        return candidates.filter { song in
            song.title.localizedCaseInsensitiveContains(query)
                || song.songbookAbbreviation.localizedCaseInsensitiveContains(query)
                || song.displayNumber.localizedCaseInsensitiveContains(query)
        }
    }

    /// Folds a `song_links` counterpart into the plain `SongSummary` shape
    /// `SongSummaryRow` renders, so every row on this screen shares ONE row
    /// implementation (see this file's header).
    private static func summary(for song: SongLinkedSong) -> SongSummary {
        SongSummary(
            songId: song.songId,
            title: song.title,
            songbookAbbreviation: song.songbookAbbreviation,
            number: song.number,
            songbookName: song.songbookName,
            language: song.language
        )
    }

    /// Folds a `related_songs` match into the same `SongSummary` shape.
    /// `RelatedSongSummary` has no `songbookName`/`language` of its own (see
    /// that type's header — the live payload never carried them), so those
    /// default to `SongSummary`'s own neutral defaults.
    private static func summary(for song: RelatedSongSummary) -> SongSummary {
        SongSummary(
            songId: song.songId,
            title: song.title,
            songbookAbbreviation: song.songbookAbbreviation,
            number: song.number
        )
    }
}

// SongDetailView.swift
// IHFeatures
//
// ELI5: The "read this one song" screen — title, songbook, then every
// verse/chorus/bridge in order, plus everything else a full song page
// needs: chords you can toggle on, credits you can tap, a footnote sheet
// for lines that have one, songs it's part of, and other songs like it —
// all under a floating glass toolbar for text size/chords/favourite/share.
//
// DETAILED: #1399's E2E slice's detail half, upgraded to the FULL parity
// screen (#180, Apple P1). Owns a fresh `SongDetailViewModel` per song
// (constructed in `init`, held via `@State` per the standard "this VIEW
// owns this per-screen `@Observable` object" pattern — as opposed to
// `AppRootViewModel`, which the app shell owns and hands down).
//
// `textScale`/`showChords` are `@AppStorage`-backed (not plain `@State`) —
// this task's brief explicitly asks the text-size choice to persist across
// launches; chords-visibility is persisted the same way for the same
// reason (a reader who turns chords on/off once almost certainly wants that
// choice to stick, not reset every time they open a song). Every other new
// section (`SongMetadataView`, `SongWorksSection`, the two
// `RelatedSongsShelfView`s, `LyricLineEnrichmentSheet`) is its own file —
// this file's job is purely composing them in order and owning the
// handful of `@State`/`@AppStorage` values they share.
import IHDesign
import IHModels
import SwiftUI

/// Displays one song's full record: lyrics, metadata/credits, works
/// membership, and related/counterpart shelves, fetched live from
/// `?action=song_detail` (+ `song_links`/`related_songs`).
public struct SongDetailView: View {
    @State private var viewModel: SongDetailViewModel
    private let rootViewModel: AppRootViewModel

    @AppStorage("ihLyricsTextScale") private var textScale: Double = 1.0
    @AppStorage("ihLyricsShowChords") private var showChords: Bool = true

    /// The line currently shown in the enrichment sheet — `nil` when no
    /// sheet is presented. `Identifiable` via its own `Int` value (a
    /// `tblLyricLines.Id` is already a unique, stable identity) so
    /// `.sheet(item:)` can present directly from it.
    private struct SelectedLine: Identifiable {
        let id: Int
    }
    @State private var selectedLine: SelectedLine?

    public init(songId: SongID, rootViewModel: AppRootViewModel) {
        _viewModel = State(initialValue: SongDetailViewModel(songId: songId, rootViewModel: rootViewModel))
        self.rootViewModel = rootViewModel
    }

    public var body: some View {
        ScrollView {
            content
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .navigationTitle(navigationTitleText)
        .task { await viewModel.loadIfNeeded() }
        .toolbar {
            SongDetailToolbarContent(
                textScale: $textScale,
                showChords: $showChords,
                hasChords: hasChords,
                shareURL: shareURL
            )
        }
        .sheet(item: $selectedLine) { line in
            LyricLineEnrichmentSheet(
                lineText: lineText(forLineId: line.id) ?? "",
                translations: enrichmentIndex.translations(forLineId: line.id),
                annotations: enrichmentIndex.annotations(forLineId: line.id)
            )
        }
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
            loadedContent(detail)
        }
    }

    @ViewBuilder
    private func loadedContent(_ detail: SongDetail) -> some View {
        let index = LineEnrichmentIndex(detail: detail)

        VStack(alignment: .leading, spacing: 20) {
            header(for: detail)
            SongMetadataView(detail: detail, relatedSongs: relatedSongsIfLoaded, rootViewModel: rootViewModel)

            ForEach(Array(detail.orderedComponents.enumerated()), id: \.offset) { _, component in
                SongComponentView(
                    component: component,
                    textScale: CGFloat(textScale),
                    showChords: showChords,
                    enrichmentIndex: index,
                    onSelectLine: { lineId in selectedLine = SelectedLine(id: lineId) }
                )
            }

            if !detail.works.isEmpty {
                SongWorksSection(works: detail.works, currentSongId: detail.songId)
            }

            counterpartsShelf
            relatedSongsShelf
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

    /// "Also appears as" — this song's cross-book counterparts
    /// (`song_links`, #807). Renders nothing at all while loading/failed/
    /// genuinely empty — a SECONDARY shelf is never worth an error card or
    /// a permanent empty-state placeholder on every song's screen.
    @ViewBuilder
    private var counterpartsShelf: some View {
        if case .loaded(let group) = viewModel.songLinksState, group.hasCounterparts {
            RelatedSongsShelfView(
                title: "Also Appears As",
                items: group.songs.map(RelatedShelfItem.init(counterpart:))
            )
        }
    }

    /// "Related Songs" — shared writer/composer/vicinity matches
    /// (`related_songs`). Same "just hide it" treatment as
    /// `counterpartsShelf` above.
    @ViewBuilder
    private var relatedSongsShelf: some View {
        if case .loaded(let related) = viewModel.relatedSongsState, !related.isEmpty {
            RelatedSongsShelfView(
                title: "Related Songs",
                items: related.map(RelatedShelfItem.init(related:))
            )
        }
    }

    /// `related_songs`, once loaded — `[]` otherwise. Handed to
    /// `SongMetadataView` for its credit "more by X" lookups, which degrade
    /// gracefully to an empty result set while this is still loading.
    private var relatedSongsIfLoaded: [RelatedSongSummary] {
        if case .loaded(let related) = viewModel.relatedSongsState {
            related
        } else {
            []
        }
    }

    /// Whether ANY component in the loaded song carries at least one chord
    /// — the check `SongDetailToolbarContent` uses to decide whether the
    /// chords-toggle button even appears (no dead toggle on the songs that,
    /// per this task's dev-API survey, have no chords at all today).
    private var hasChords: Bool {
        guard case .loaded(let detail) = viewModel.loadState else { return false }
        return detail.components.contains { ($0.chords ?? []).contains { $0 != nil } }
    }

    /// The canonical web URL for this song, e.g.
    /// `https://ihymns.app/song/MP-0031` — what `ShareLink` shares, per this
    /// task's explicit instruction (never a custom scheme — strategy §1.7:
    /// "every share surface must emit the canonical web URL"). `nil` while
    /// still loading/errored, which hides the Share button entirely rather
    /// than sharing a broken/placeholder link.
    private var shareURL: URL? {
        guard case .loaded(let detail) = viewModel.loadState else { return nil }
        return URL(string: "https://ihymns.app/song/\(detail.songId.rawValue)")
    }

    /// Looks up one line's plain text by its stable `tblLyricLines.Id`, for
    /// the enrichment sheet's heading — `nil` only if `selectedLine` somehow
    /// outlived the loaded song (e.g. a slow sheet dismissal race), in which
    /// case the sheet shows an empty heading rather than crashing.
    private func lineText(forLineId lineId: Int) -> String? {
        guard case .loaded(let detail) = viewModel.loadState else { return nil }
        for component in detail.components {
            if let index = component.lineIds.firstIndex(of: lineId), index < component.lines.count {
                return component.lines[index]
            }
        }
        return nil
    }

    /// The current song's `LineEnrichmentIndex` — recomputed from
    /// `viewModel.loadState` on demand (cheap: at most a few dozen lines per
    /// song) rather than cached in `@State`, since it has no identity of
    /// its own worth tracking across re-renders.
    private var enrichmentIndex: LineEnrichmentIndex {
        guard case .loaded(let detail) = viewModel.loadState else { return .empty }
        return LineEnrichmentIndex(detail: detail)
    }
}

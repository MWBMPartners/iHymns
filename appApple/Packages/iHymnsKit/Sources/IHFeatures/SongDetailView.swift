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
//
// #181 UPDATE (setlists half) — owns `isPresentingAddToSetlist` (flipped by
// `SongDetailToolbarContent`'s new "Add to Setlist" button) and presents
// `AddToSetlistSheet` from it, the SAME "toolbar flips a bound `Bool`, the
// hosting view owns the `.sheet`" split `isPresentingLogin`/`LoginView`
// already establishes on `FavoritesView`. `setlistEntry` derives a
// `SetlistSongEntry` from the currently-loaded `SongDetail` so the sheet
// never needs its own second fetch.
//
// #187 UPDATE (offline support + data management) — wires the toolbar's new
// save/saved button straight to `SongDetailViewModel.isSavedOffline`/
// `toggleOfflineSave()`, and renders `offlineCopyBanner` above the header
// whenever `viewModel.isServingCachedCopy` is true — the ONLY user-visible
// sign that a page was served from the on-device cache rather than the
// network, per this task's "unobtrusive... indicator" brief.
//
// #184 UPDATE (audio & sheet music) — inserts `SongMediaSection` (a new
// "Media" card: play/pause a recording, view sheet music, download MIDI/
// MusicXML) right after `SongMetadataView`, `if SongMediaSection
// .hasAnyMedia(detail)` — the same "just don't render the section" treatment
// `counterpartsShelf`/`relatedSongsShelf` already use for optional content,
// so a song with none of these four media kinds shows an unchanged page.
//
// #186 UPDATE (Apple Phase 1, "Sharing & social") — two changes: (1)
// `shareURL` now goes through `IHAppSupport.CanonicalURL.song(_:)` (the ONE
// shared web-URL builder, per this task's explicit "extract it if it's
// currently duplicated" instruction) instead of hand-building the string
// inline, and a new `shareTitle` feeds `SongDetailToolbarContent`'s
// `SharePreview`; (2) advertises the loaded song as a Handoff activity
// (`.userActivity(NSUserActivityTypeBrowsingWeb)`) so a reader can pick the
// SAME song back up on another Apple device, or hand off straight into
// Safari — kept to song-detail only (this task's own "highest-value case…
// don't over-reach" scoping; setlists/songbooks don't get one). Guarded to
// iOS/macOS/visionOS only: `IHFeatures` also compiles for tvOS/watchOS
// (this file's own header, `RootContainerView.swift`'s header), and Handoff
// is not a meaningful concept on either — matching the task's explicit
// "guard any iOS-only Handoff… API" instruction with the SAME three-platform
// split `RootContainerView`'s own `#if os(macOS) || os(visionOS) /
// #elseif os(iOS)` already establishes. The `handoffActivity(webpageURL:title:)`
// modifier itself now lives in `SongDetailView+Handoff.swift` (a pure move,
// purely for the LOC-budget tripwire — see that file's own header).
//
// #1445 UPDATE (song comparison) — applies `SongCompareEntryModifier`,
// which owns the toolbar's new "Compare With…" button, the picker sheet,
// and the push to `SongComparisonView`. `songId` is now a stored property
// (previously only threaded into `viewModel`'s own init) purely so this
// entry point has a stable id to compare FROM even before the primary load
// completes (the button itself stays disabled until it does).
import Foundation
import IHAppSupport
import IHAuth
import IHDesign
import IHModels
import SwiftUI

/// Displays one song's full record: lyrics, metadata/credits, works
/// membership, and related/counterpart shelves, fetched live from
/// `?action=song_detail` (+ `song_links`/`related_songs`).
public struct SongDetailView: View {
    @State private var viewModel: SongDetailViewModel
    private let rootViewModel: AppRootViewModel

    /// #1445 — kept as its own stored property (rather than re-deriving it
    /// from `viewModel.loadState` every time) so `SongCompareEntryModifier`
    /// always has a stable "compare FROM" id, even before the primary load
    /// completes (its own toolbar button stays `.disabled` until then).
    private let songId: SongID

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

    /// #181 setlists half — flipped by the toolbar's "Add to Setlist"
    /// button; see this file's header.
    @State private var isPresentingAddToSetlist = false

    /// #1455 — owned here (not inside `SongCompareEntryModifier`) so BOTH
    /// that modifier's toolbar/picker AND a shelf row's "Compare with This"
    /// context menu can drive the same comparison push; see that modifier's
    /// own header.
    @State private var comparisonTargetId: SongID?

    public init(songId: SongID, rootViewModel: AppRootViewModel) {
        _viewModel = State(initialValue: SongDetailViewModel(songId: songId, rootViewModel: rootViewModel))
        self.rootViewModel = rootViewModel
        self.songId = songId
    }

    public var body: some View {
        ScrollView {
            content
                .padding()
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .navigationTitle(navigationTitleText)
        .task { await viewModel.loadIfNeeded() }
        // PR-10 (#1426) — the native `live-follow.js initSongPage` broadcast
        // hook: a no-op unless this device is currently hosting.
        .task { rootViewModel.liveSongViewed(songId) }
        .handoffActivity(webpageURL: shareURL, title: navigationTitleText)
        .toolbar {
            SongDetailToolbarContent(
                textScale: $textScale,
                showChords: $showChords,
                hasChords: hasChords,
                shareURL: shareURL,
                shareTitle: shareTitle,
                isFavorite: viewModel.isFavorite,
                isSignedIn: rootViewModel.sessionState.isSignedIn,
                onToggleFavorite: { Task { await viewModel.toggleFavorite() } },
                isPresentingAddToSetlist: $isPresentingAddToSetlist,
                isSavedOffline: viewModel.isSavedOffline,
                onToggleOfflineSave: { Task { await viewModel.toggleOfflineSave() } }
            )
        }
        .sheet(item: $selectedLine) { line in
            LyricLineEnrichmentSheet(
                lineText: lineText(forLineId: line.id) ?? "",
                translations: enrichmentIndex.translations(forLineId: line.id),
                annotations: enrichmentIndex.annotations(forLineId: line.id)
            )
        }
        .sheet(isPresented: $isPresentingAddToSetlist) {
            if let entry = setlistEntry {
                AddToSetlistSheet(rootViewModel: rootViewModel, song: entry)
            }
        }
        // #1445 — the "Compare With…" toolbar button + picker + push;
        // owns its OWN toolbar/sheet/navigationDestination, so this is the
        // entire wiring cost on this file (see that modifier's own header).
        .modifier(SongCompareEntryModifier(
            songId: songId,
            isPrimaryLoaded: isPrimaryLoaded,
            counterparts: counterpartsForCompare,
            relatedSongs: relatedSongsForCompare,
            rootViewModel: rootViewModel,
            comparisonTargetId: $comparisonTargetId
        ))
    }

    /// Whether the primary load has succeeded — `SongCompareEntryModifier`'s
    /// "nothing to compare FROM yet" gate, mirroring `shareURL`'s own
    /// `.loaded`-only guard.
    private var isPrimaryLoaded: Bool {
        if case .loaded = viewModel.loadState { return true }
        return false
    }

    /// This song's counterparts, already fetched by `viewModel
    /// .songLinksState` — handed to the picker so it never issues a second
    /// fetch for suggestions it already has.
    private var counterpartsForCompare: [SongLinkedSong] {
        if case .loaded(let group) = viewModel.songLinksState { return group.songs }
        return []
    }

    /// Same "already fetched, no second call" reasoning as
    /// `counterpartsForCompare` above, for `viewModel.relatedSongsState`.
    private var relatedSongsForCompare: [RelatedSongSummary] {
        if case .loaded(let related) = viewModel.relatedSongsState { return related }
        return []
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
            // #185 — shared retry-capable error card (`IHDesign`);
            // `viewModel.load()` is the existing hook `SongDetailViewModel`
            // already exposes for exactly this.
            IHLoadErrorView(title: "Couldn't Load Song", message: message) {
                await viewModel.load()
            }

        case .loaded(let detail):
            loadedContent(detail)
        }
    }

    @ViewBuilder
    private func loadedContent(_ detail: SongDetail) -> some View {
        let index = LineEnrichmentIndex(detail: detail)

        VStack(alignment: .leading, spacing: 20) {
            if viewModel.isServingCachedCopy {
                offlineCopyBanner
            }
            header(for: detail)
            SongMetadataView(detail: detail, rootViewModel: rootViewModel)

            if SongMediaSection.hasAnyMedia(detail) {
                SongMediaSection(detail: detail, rootViewModel: rootViewModel)
            }

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
                SongWorksSection(works: detail.works, currentSongId: detail.songId, rootViewModel: rootViewModel)
            }

            counterpartsShelf
            relatedSongsShelf
        }
    }

    /// The "served from the offline cache, not the network" indicator
    /// (#187) — unobtrusive by design (a small, secondary-styled capsule
    /// rather than a banner that competes with the lyrics below it), shown
    /// only when `viewModel.isServingCachedCopy` is true.
    ///
    /// ELI5: "Heads up — you're offline, so this is the copy you saved
    /// earlier, not a fresh download."
    private var offlineCopyBanner: some View {
        Label("Offline — showing saved copy", systemImage: "wifi.slash")
            .font(.footnote)
            .foregroundStyle(.secondary)
            .padding(.vertical, 4)
            .padding(.horizontal, 10)
            .background(.thinMaterial, in: Capsule())
    }

    private func header(for detail: SongDetail) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(detail.title)
                .font(.largeTitle.bold())

            // #1752 Slice A — disambiguation directly under the title (a
            // short parenthetical distinguishing same-named songs, e.g.
            // "(Christmas version)"), mirroring web `song.php` §2.2's
            // small-text-after-the-<h1> treatment; SwiftUI has no
            // `<small>`-inside-`<h1>` equivalent worth forcing, so this is
            // its own `Text` line instead.
            if let disambiguation = detail.disambiguation, !disambiguation.isEmpty {
                Text("(\(disambiguation))")
                    .font(.title3)
                    .foregroundStyle(.secondary)
            }

            // Subtitle — above the existing number/songbook line, mirroring
            // web `song.php` §2.3.
            if let subtitle = detail.subtitle, !subtitle.isEmpty {
                Text(subtitle)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

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
                items: group.songs.map(RelatedShelfItem.init(counterpart:)),
                onCompare: { comparisonTargetId = $0 }
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
                items: related.map(RelatedShelfItem.init(related:)),
                onCompare: { comparisonTargetId = $0 }
            )
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
    ///
    /// #186 — now built via `IHAppSupport.CanonicalURL.song(_:)` (the ONE
    /// shared URL-builder) rather than an inline `URL(string:)` — see this
    /// file's header.
    private var shareURL: URL? {
        guard case .loaded(let detail) = viewModel.loadState else { return nil }
        return CanonicalURL.song(detail.songId)
    }

    /// "Title — Songbook #Number" for the `ShareLink`'s `SharePreview` —
    /// `nil` while still loading/errored, mirroring `shareURL`'s own guard
    /// (`SongDetailToolbarContent` only builds a `SharePreview` when both
    /// are non-`nil`).
    private var shareTitle: String? {
        guard case .loaded(let detail) = viewModel.loadState else { return nil }
        var title = "\(detail.title) — \(detail.songbookName.isEmpty ? detail.songbookAbbreviation : detail.songbookName)"
        if let number = detail.number {
            title += " #\(number)"
        }
        return title
    }

    /// This song as a `SetlistSongEntry`, for `AddToSetlistSheet` — `nil`
    /// while still loading/errored, which is also why the toolbar's "Add to
    /// Setlist" sheet content guards on it (this file's `body` above): there
    /// is nothing meaningful to add yet.
    private var setlistEntry: SetlistSongEntry? {
        guard case .loaded(let detail) = viewModel.loadState else { return nil }
        return SetlistSongEntry(
            songId: detail.songId,
            title: detail.title,
            songbookAbbreviation: detail.songbookAbbreviation,
            number: detail.number ?? 0
        )
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

// `handoffActivity(webpageURL:title:)` (#186) moved to
// `SongDetailView+Handoff.swift` — a pure move, purely for the LOC-budget
// tripwire now that #1445 added the "Compare With…" entry point above; see
// that file's own header.

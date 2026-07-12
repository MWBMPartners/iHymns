// SetlistDetailView.swift
// IHFeatures
//
// ELI5: One setlist's own screen — its songs, in order. Drag to reorder,
// swipe to remove, tap "+" to add more (search or, on iPad/Mac, drag a song
// straight in from a side panel), and a Share button to publish a public
// link.
//
// DETAILED: #181's setlists half, strategy §2.2's "Setlist workbench (drag
// songs catalogue→setlist)" brief. Reads `rootViewModel.setlists` directly
// (never a locally-cached copy) so a mutation made from ANYWHERE — this
// screen's own actions, `AddToSetlistSheet` on a different song's detail
// screen, another device's sync landing via `refreshSetlistsFromServer()` —
// is reflected the instant it happens, the same "root view model IS the
// state" posture every other screen in this package takes.
//
// Layout: iPhone/compact-iPad gets a single reorderable list plus a
// sheet-presented `SetlistCatalogueBrowserView` for adding songs (compact
// windows have no room for a permanent side panel). iPad(regular)/Mac/vision
// get the WORKBENCH: the reorderable list alongside a live catalogue browser
// pane, so songs can be dragged straight across (`SetlistCatalogueBrowserView`
// makes each row `.draggable`; the list below is the `.dropDestination`) —
// tapping still works everywhere too, drag is a bonus, never the only path.
import IHAPI
import IHModels
import SwiftUI

/// One setlist's songs, in order — reorderable, removable, with an "add
/// songs" path and a public-share action.
public struct SetlistDetailView: View {
    private let setlistId: String
    private let rootViewModel: AppRootViewModel

    #if os(iOS)
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass
    #endif

    @State private var isPresentingAddSongsSheet = false
    @State private var shareResult: ShareSetlistResult?
    @State private var isPreparingShare = false
    @State private var shareErrorMessage: String?

    public init(setlistId: String, rootViewModel: AppRootViewModel) {
        self.setlistId = setlistId
        self.rootViewModel = rootViewModel
    }

    public var body: some View {
        content
            .navigationTitle(setlist?.name ?? "Setlist")
            // Two SongID-family destinations, kept SIDE BY SIDE (#185) —
            // see `SongbooksView.swift`'s identical comment for why the
            // bare-`SongID` destination stays registered even though this
            // screen's own rows now push `SongPagerRequest` below: anything
            // pushed from WITHIN a `SongDetailView` reached via either path
            // (e.g. its "Related Songs" shelf) still needs somewhere to land.
            .navigationDestination(for: SongID.self) { songId in
                SongDetailView(songId: songId, rootViewModel: rootViewModel)
            }
            .navigationDestination(for: SongPagerRequest.self) { request in
                SongPagerView(initialSongId: request.songId, context: request.context, rootViewModel: rootViewModel)
            }
            .toolbar { toolbarContent }
            .sheet(isPresented: $isPresentingAddSongsSheet) {
                addSongsSheet
            }
            .alert(
                "Couldn't Share Setlist",
                isPresented: Binding(get: { shareErrorMessage != nil }, set: { if !$0 { shareErrorMessage = nil } })
            ) {
                Button("OK", role: .cancel) {}
            } message: {
                Text(shareErrorMessage ?? "")
            }
    }

    /// The live setlist this screen displays — `nil` only if it's been
    /// deleted (e.g. from another device) since this screen was pushed;
    /// `content` below shows a "not found" state rather than crashing on a
    /// stale reference.
    private var setlist: Setlist? {
        rootViewModel.setlists.first { $0.id == setlistId }
    }

    @ViewBuilder
    private var content: some View {
        if let setlist {
            #if os(macOS) || os(visionOS)
            workbenchLayout(setlist)
            #elseif os(iOS)
            if horizontalSizeClass == .compact {
                songsList(setlist)
            } else {
                workbenchLayout(setlist)
            }
            #else
            songsList(setlist)
            #endif
        } else {
            ContentUnavailableView(
                "Setlist Not Found",
                systemImage: "list.bullet.rectangle.portrait",
                description: Text("This setlist may have been deleted.")
            )
        }
    }

    /// The regular-width "workbench": the reorderable list alongside a live
    /// catalogue browser pane you can tap OR drag songs from.
    private func workbenchLayout(_ setlist: Setlist) -> some View {
        HStack(spacing: 0) {
            songsList(setlist)
                .frame(minWidth: 280, maxWidth: .infinity)
            Divider()
            SetlistCatalogueBrowserView(
                rootViewModel: rootViewModel,
                existingSongIds: Set(setlist.songs.map(\.songId))
            ) { summary in
                Task { await rootViewModel.addSong(SetlistSongEntry(summary: summary), to: setlistId) }
            }
            .frame(minWidth: 320, maxWidth: .infinity)
        }
    }

    @ViewBuilder
    private func songsList(_ setlist: Setlist) -> some View {
        if setlist.songs.isEmpty {
            ContentUnavailableView {
                Label("No Songs Yet", systemImage: "music.note.list")
            } description: {
                Text("Add songs from the catalogue to build this setlist.")
            } actions: {
                Button("Add Songs") { isPresentingAddSongsSheet = true }
                    .buttonStyle(.borderedProminent)
            }
        } else {
            // #185 — the whole setlist's current song order, so each row's
            // push carries "page through the rest of this setlist" context.
            // Recomputed here (not cached) so it always reflects the LIVE
            // `setlist.songs` order, including a reorder/add/remove that
            // just happened — the exact same "root view model IS the state"
            // freshness every other read in this screen already relies on.
            let context = SongNavigationContext(songIds: setlist.songs.map(\.songId))
            List {
                // Indexed by `.offset` (not `SetlistSongEntry.id`/`songId`)
                // so a legacy/cross-device setlist that happens to contain
                // the same song twice never trips SwiftUI's "duplicate
                // identity" diffing hazard — mirrors `SongDetailView`'s own
                // `ForEach(Array(detail.orderedComponents.enumerated()),
                // id: \.offset)` precedent. `fromOffsets`/`toOffset`,
                // `IndexSet` deletions, and drop insertion below all key off
                // this SAME array-index space, so it's consistent
                // end-to-end even with a duplicate present.
                ForEach(Array(setlist.songs.enumerated()), id: \.offset) { _, entry in
                    // `.swipeActions` is UNAVAILABLE on tvOS (D-1, #1504's
                    // tvOS-build gate) — no touch/swipe gesture there, so an
                    // explicit, always-visible destructive button replaces
                    // the swipe-revealed one instead of forking a second row.
                    #if os(tvOS)
                    HStack {
                        NavigationLink(value: SongPagerRequest(songId: entry.songId, context: context)) {
                            SongSummaryRow(entry.summary)
                        }
                        Button(role: .destructive) {
                            Task { await rootViewModel.removeSong(songId: entry.songId, from: setlistId) }
                        } label: {
                            Label("Remove", systemImage: "minus.circle")
                        }
                    }
                    #else
                    NavigationLink(value: SongPagerRequest(songId: entry.songId, context: context)) {
                        SongSummaryRow(entry.summary)
                    }
                    .swipeActions(edge: .trailing) {
                        Button(role: .destructive) {
                            Task { await rootViewModel.removeSong(songId: entry.songId, from: setlistId) }
                        } label: {
                            Label("Remove", systemImage: "minus.circle")
                        }
                    }
                    #endif
                }
                .onMove { offsets, destination in
                    Task { await rootViewModel.moveSongs(in: setlistId, fromOffsets: offsets, toOffset: destination) }
                }
            }
            .listStyle(.plain)
            // `.dropDestination` is UNAVAILABLE on tvOS (D-1, #1504) — its
            // counterpart `.draggable` on `SetlistCatalogueBrowserView`'s row
            // is ALSO tvOS-omitted, so nothing would ever drag INTO this
            // drop target there anyway. Also UNAVAILABLE on watchOS (#1549,
            // same reasoning) — this screen is never shown on the watch
            // anyway (`iHymnsWatch` links the whole IHFeatures target, so it
            // must still COMPILE there).
            #if !os(tvOS) && !os(watchOS)
            .dropDestination(for: String.self) { droppedIds, _ in
                return handleDrop(droppedIds, into: setlist)
            }
            #endif
        }
    }

    /// Handles a `.draggable(songId.rawValue)` row dropped from
    /// `SetlistCatalogueBrowserView`'s workbench pane — resolves each
    /// dropped id back to a `SongSummary` via the already-loaded catalogue
    /// index (so the added entry gets real title/songbook/number, not just
    /// a bare id) and adds it. Any id that doesn't parse as a `SongID`, or
    /// doesn't match a currently-loaded catalogue song, is silently
    /// skipped — a drop payload should only ever contain ids THIS view's
    /// own browser pane produced, so this is defensive rather than an
    /// expected path.
    private func handleDrop(_ droppedIds: [String], into setlist: Setlist) -> Bool {
        guard case .loaded(let songs) = rootViewModel.catalogueLoadState else { return false }
        var handledAny = false
        for rawId in droppedIds {
            guard let songId = SongID(rawValue: rawId), let summary = songs.first(where: { $0.songId == songId }) else { continue }
            handledAny = true
            Task { await rootViewModel.addSong(SetlistSongEntry(summary: summary), to: setlistId) }
        }
        return handledAny
    }

    private var addSongsSheet: some View {
        NavigationStack {
            SetlistCatalogueBrowserView(
                rootViewModel: rootViewModel,
                existingSongIds: Set(setlist?.songs.map(\.songId) ?? [])
            ) { summary in
                Task { await rootViewModel.addSong(SetlistSongEntry(summary: summary), to: setlistId) }
            }
            .navigationTitle("Add Songs")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") { isPresentingAddSongsSheet = false }
                }
            }
        }
    }

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        #if os(iOS)
        ToolbarItem(placement: .primaryAction) { EditButton() }
        #endif

        ToolbarItem(placement: .primaryAction) {
            addSongsToolbarButton
        }

        ToolbarItem(placement: .primaryAction) {
            shareToolbarButton
        }
    }

    /// Only rendered on compact windows — the workbench's side pane already
    /// gives regular-width windows a permanent, always-visible way to add
    /// songs, so a redundant "+" there would just be clutter.
    @ViewBuilder
    private var addSongsToolbarButton: some View {
        #if os(iOS)
        if horizontalSizeClass == .compact {
            Button {
                isPresentingAddSongsSheet = true
            } label: {
                Label("Add Songs", systemImage: "plus")
            }
        }
        #elseif os(tvOS) || os(watchOS)
        Button {
            isPresentingAddSongsSheet = true
        } label: {
            Label("Add Songs", systemImage: "plus")
        }
        #endif
    }

    /// Before the first successful share, a plain button that mints the
    /// LIVE share link (one server write, user-gesture-gated — never
    /// prefetched on appearance, so merely opening a setlist never silently
    /// publishes it). Once `shareResult` exists, the SAME toolbar slot
    /// becomes a real `ShareLink` presenting the canonical URL — no second
    /// mint is ever needed afterwards, because this IS a live link: the
    /// owner's later edits to `setlist` keep reflecting through it
    /// automatically (the whole point of the live-share design, per this
    /// task's brief), so re-tapping "Share" again would only waste a
    /// redundant server round trip for no behavioural change.
    @ViewBuilder
    private var shareToolbarButton: some View {
        if isPreparingShare {
            ProgressView()
        } else if let shareResult, let url = shareResult.canonicalURL {
            // `ShareLink` is UNAVAILABLE on tvOS (D-1, #1504's tvOS-build
            // gate) — no share-sheet destination on that platform; the link
            // has already been minted server-side, so the URL itself is
            // shown as plain text there instead of forking a second toolbar.
            #if os(tvOS)
            Text(url.absoluteString)
                .font(.caption)
                .lineLimit(1)
                .truncationMode(.middle)
            #else
            ShareLink(item: url) {
                Label("Share", systemImage: "square.and.arrow.up")
            }
            #endif
        } else {
            Button {
                Task { await prepareShare() }
            } label: {
                Label("Share", systemImage: "square.and.arrow.up")
            }
            .disabled(setlist?.songs.isEmpty ?? true)
        }
    }

    private func prepareShare() async {
        isPreparingShare = true
        defer { isPreparingShare = false }
        do {
            shareResult = try await rootViewModel.shareSetlist(setlistId)
        } catch let error as APIError {
            shareErrorMessage = error.userFacingMessage
        } catch {
            shareErrorMessage = "Something went wrong publishing this setlist's share link. Please try again."
        }
    }
}

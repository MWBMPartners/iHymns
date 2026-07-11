// SharedSetlistView.swift
// IHFeatures
//
// ELI5: The screen you land on when you tap someone ELSE's "here's my set
// list" link — read-only browsing of their songs (tap one to read it),
// a "Share" button to pass the same link along, and (if you're signed in)
// an "Import to My Setlists" button that saves your own private copy.
//
// DETAILED: #186 (Apple Phase 1, "Sharing & social") — the screen
// `AppRootViewModel+Setlists.swift`'s own `sharedSetlist(shareId:)` doc
// comment flagged as "not yet wired to a dedicated 'view someone else's
// shared setlist' screen (a future Universal Link handler's job)" — this IS
// that future handler landing. Reads `SharedSetlist` (IHModels) — the
// PUBLIC-SAFE, owner-anonymous projection `?action=setlist_get` returns —
// which is DELIBERATELY different from the private `Setlist`/
// `SetlistDetailView` pair: this screen never mutates the setlist it's
// viewing (it isn't the owner's), it can be opened while SIGNED OUT
// (`SetlistsEndpoints.sharedSetlist(shareId:)`'s own header: "the server has
// NO auth check at all" for a read), and importing creates a BRAND NEW
// private setlist rather than editing anything.
//
// SONG-ROW RESOLUTION: `SharedSetlist.songs` is `[String]` of raw ids, not
// `[SetlistSongEntry]` — the wire shape carries no title/songbook/number at
// all (`SharedSetlist.swift`'s own header). This screen resolves each id
// against the ALREADY-LOADED catalogue index (`rootViewModel.catalogueLoadState`)
// — the exact same "resolve a bare id against the local index" pattern
// `SetlistDetailView.handleDrop(_:into:)` already established for its own
// drag-and-drop id resolution — rather than making one network round trip
// per song. An id that doesn't parse as a `SongID` (the `song-XXXXXX`
// unsaved-draft shape `SharedSetlist.swift`'s header documents) or isn't in
// the loaded index yet renders as a plain, non-tappable row instead of
// being silently dropped — a shared list is someone ELSE's content, so
// hiding an entry outright would misrepresent what they actually shared.
import IHAppSupport
import IHAPI
import IHDesign
import IHModels
import SwiftUI

/// Read-only viewer + importer for a link-shared set list.
public struct SharedSetlistView: View {
    private let shareId: String
    private let rootViewModel: AppRootViewModel

    @State private var loadState: LoadState<SharedSetlist> = .idle
    @State private var isPresentingLogin = false
    @State private var isImporting = false
    @State private var importResultMessage: String?

    public init(shareId: String, rootViewModel: AppRootViewModel) {
        self.shareId = shareId
        self.rootViewModel = rootViewModel
    }

    public var body: some View {
        content
            .navigationTitle(navigationTitleText)
            .navigationDestination(for: SongID.self) { songId in
                SongDetailView(songId: songId, rootViewModel: rootViewModel)
            }
            .toolbar { toolbarContent }
            .task {
                await load()
                await rootViewModel.loadCatalogueIfNeeded()
            }
            .sheet(isPresented: $isPresentingLogin) {
                LoginView(rootViewModel: rootViewModel)
            }
            .alert(
                "Shared Set List",
                isPresented: Binding(get: { importResultMessage != nil }, set: { if !$0 { importResultMessage = nil } })
            ) {
                Button("OK", role: .cancel) {}
            } message: {
                Text(importResultMessage ?? "")
            }
    }

    private var navigationTitleText: String {
        if case .loaded(let shared) = loadState { shared.name } else { "Shared Set List" }
    }

    @ViewBuilder
    private var content: some View {
        switch loadState {
        case .idle, .loading:
            ProgressView("Loading set list…")
                .frame(maxWidth: .infinity, minHeight: 200)

        case .error(let message):
            // #185 — shared retry-capable error card; `load()` below is
            // this screen's own existing force-refetch method.
            IHLoadErrorView(title: "Couldn't Load Set List", message: message) {
                await load()
            }

        case .loaded(let shared):
            loadedContent(shared)
        }
    }

    private func loadedContent(_ shared: SharedSetlist) -> some View {
        List {
            Section(songCountText(shared)) {
                if shared.songs.isEmpty {
                    Text("This set list has no songs yet.")
                        .foregroundStyle(.secondary)
                } else {
                    ForEach(shared.songs, id: \.self) { rawSongId in
                        songRow(rawSongId)
                    }
                }
            }

            // Browsing is always allowed (the endpoint needs no auth — see
            // this file's header); importing needs an account, same gate
            // `SongDetailToolbarContent`'s favourite/setlist buttons use.
            if !rootViewModel.sessionState.isSignedIn {
                Section {
                    signInPrompt
                }
            }
        }
        .listStyle(.plain)
        // #185 — pull-to-refresh re-fetches this shared set list.
        .refreshable { await load() }
    }

    @ViewBuilder
    private func songRow(_ rawSongId: String) -> some View {
        if let songId = SongID(rawValue: rawSongId), let summary = resolvedSummary(for: songId) {
            NavigationLink(value: songId) {
                SongSummaryRow(summary)
            }
        } else {
            // Either an unofficial draft id (`SharedSetlist.swift`'s header)
            // or a real SongID the local catalogue index hasn't loaded yet —
            // shown plainly rather than hidden, since this is someone else's
            // content and dropping a row silently would misrepresent it.
            Text(rawSongId)
                .foregroundStyle(.secondary)
        }
    }

    private func resolvedSummary(for songId: SongID) -> SongSummary? {
        guard case .loaded(let songs) = rootViewModel.catalogueLoadState else { return nil }
        return songs.first { $0.songId == songId }
    }

    private var signInPrompt: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Sign in to save this to your own setlists.")
                .foregroundStyle(.secondary)
            Button("Sign In") { isPresentingLogin = true }
        }
    }

    private func songCountText(_ shared: SharedSetlist) -> String {
        switch shared.songs.count {
        case 0: "No songs yet"
        case 1: "1 song"
        default: "\(shared.songs.count) songs"
        }
    }

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        // `ShareLink`/`SharePreview` are UNAVAILABLE on tvOS (D-1, #1504's
        // tvOS-build gate) — no share-sheet destination there, so the whole
        // toolbar item is omitted rather than forking a second toolbar.
        #if !os(tvOS)
        ToolbarItem(placement: .primaryAction) {
            if let shareURL = CanonicalURL.setlistShare(id: shareId) {
                ShareLink(item: shareURL, preview: SharePreview(navigationTitleText)) {
                    Label("Share", systemImage: "square.and.arrow.up")
                }
            }
        }
        #endif
        ToolbarItem(placement: .primaryAction) {
            importButton
        }
    }

    @ViewBuilder
    private var importButton: some View {
        if isImporting {
            ProgressView()
        } else {
            Button {
                Task { await importSetlist() }
            } label: {
                Label("Import to My Setlists", systemImage: "square.and.arrow.down")
            }
            .disabled(!rootViewModel.sessionState.isSignedIn || !isLoaded)
            .help(
                rootViewModel.sessionState.isSignedIn
                    ? "Save a copy of this set list to your own setlists."
                    : "Sign in to save this set list."
            )
        }
    }

    private var isLoaded: Bool {
        if case .loaded = loadState { true } else { false }
    }

    private func load() async {
        loadState = .loading
        do {
            let shared = try await rootViewModel.sharedSetlist(shareId: shareId)
            loadState = .loaded(shared)
        } catch let error as APIError {
            loadState = .error(error.userFacingMessage)
        } catch {
            loadState = .error("Something went wrong opening this shared set list. Please try again.")
        }
    }

    /// Creates a brand-new private setlist named after the shared one and
    /// bulk-adds every song this device can resolve — songs that don't
    /// parse as a `SongID`, or aren't (yet) in the local catalogue index,
    /// are silently skipped from the IMPORT itself (they're still visible,
    /// un-tappable, in the list above) and counted into the closing summary
    /// so the user knows the import wasn't silently partial.
    private func importSetlist() async {
        guard case .loaded(let shared) = loadState else { return }
        isImporting = true
        defer { isImporting = false }

        await rootViewModel.loadCatalogueIfNeeded()
        let entries: [SetlistSongEntry] = shared.songs.compactMap { rawSongId in
            guard let songId = SongID(rawValue: rawSongId), let summary = resolvedSummary(for: songId) else {
                return nil
            }
            return SetlistSongEntry(summary: summary)
        }

        let created = await rootViewModel.createSetlist(name: shared.name)
        for entry in entries {
            await rootViewModel.addSong(entry, to: created.id)
        }

        let skipped = shared.songs.count - entries.count
        importResultMessage = skipped > 0
            ? "Imported \(entries.count) of \(shared.songs.count) songs to \"\(created.name)\". "
                + "\(skipped) song(s) couldn't be matched and were left out."
            : "Imported \"\(created.name)\" with \(entries.count) song(s)."
    }
}

// OfflineStorageView.swift
// IHFeatures
//
// ELI5: The "Storage & Offline" screen, reachable from Settings — how much
// space your saved-for-offline songs are using, the list of what you've
// saved, swipe to forget one, or clear everything at once.
//
// DETAILED: #187 (offline support + data management)'s "Data management UI"
// requirement. A pure renderer of `OfflineStorageViewModel`, mirroring
// `FavoritesView`'s exact "List + `.swipeActions(edge: .trailing)` +
// `ContentUnavailableView` empty state" shape — this screen owns no
// persistence or network logic of its own, same as every other screen in
// this package. The bulk "Remove All Downloads" action is
// `.alert(...)`-confirmed (not a bare, instantly-destructive button) since
// it can discard many songs' cached lyrics at once and — unlike a single
// swipe-to-delete, which is a small, easily-repeated action — has no
// individual undo.
//
// #1440 UPDATE (offline media caching) — `summarySection` gains a "Media
// Downloads" row alongside "Total Size" (now the COMBINED lyrics+media
// figure, `OfflineStorageViewModel.totalSizeDisplay`), and the "Remove All
// Downloads" confirmation copy now mentions media explicitly — this screen
// stays a pure renderer of the view model either way, no new logic here.
import IHModels
import IHPersistence
import SwiftUI

/// Lists every song saved for offline reading, plus their combined size,
/// and lets the user remove one or everything.
public struct OfflineStorageView: View {
    @State private var viewModel: OfflineStorageViewModel
    @State private var isPresentingRemoveAllConfirmation = false

    public init(rootViewModel: AppRootViewModel) {
        _viewModel = State(initialValue: OfflineStorageViewModel(rootViewModel: rootViewModel))
    }

    public var body: some View {
        content
            .navigationTitle("Storage & Offline")
            .task { await viewModel.loadIfNeeded() }
            .alert("Remove All Downloads?", isPresented: $isPresentingRemoveAllConfirmation) {
                Button("Cancel", role: .cancel) {}
                Button("Remove All", role: .destructive) {
                    Task { await viewModel.removeAll() }
                }
            } message: {
                Text("Every song you've saved for offline reading, and any downloaded audio/sheet-music/MIDI/MusicXML files, will be removed from this device. This can't be undone.")
            }
    }

    /// Switches the WHOLE screen between "nothing saved yet" and the real
    /// list — same "the view just switches on the view model's state, no
    /// overlay layering" shape `SongbookSongsView.content` already uses,
    /// rather than showing an empty-state overlay ON TOP of a list that's
    /// still rendering a (zero-valued) summary section underneath it.
    @ViewBuilder
    private var content: some View {
        if viewModel.savedSongs.isEmpty {
            emptyView
        } else {
            List {
                summarySection
                savedSongsSection
                removeAllSection
            }
        }
    }

    /// The headline "Total Size" / "Media Downloads" / "Saved Songs"
    /// figures — "Total Size" is lyrics+media COMBINED
    /// (`OfflineStorageViewModel.totalSizeDisplay`); "Media Downloads"
    /// breaks out just the cached audio/sheet-music/MIDI/MusicXML portion
    /// of it (#1440), so the split is visible rather than hidden inside one
    /// number.
    private var summarySection: some View {
        Section {
            LabeledContent("Total Size", value: viewModel.totalSizeDisplay)
            LabeledContent("Media Downloads", value: viewModel.mediaSizeDisplay)
            LabeledContent("Saved Songs", value: "\(viewModel.savedSongs.count)")
        }
    }

    private var savedSongsSection: some View {
        Section("Saved Songs") {
            ForEach(viewModel.savedSongs) { song in
                savedSongRow(song)
                    .swipeActions(edge: .trailing) {
                        Button(role: .destructive) {
                            Task { await viewModel.remove(song.songId) }
                        } label: {
                            Label("Remove", systemImage: "trash")
                        }
                    }
            }
        }
    }

    private func savedSongRow(_ song: SavedSongInfo) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(song.title.isEmpty ? song.songId.rawValue : song.title)
            Text(song.songbookAbbreviation)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
    }

    private var removeAllSection: some View {
        Section {
            Button("Remove All Downloads", role: .destructive) {
                isPresentingRemoveAllConfirmation = true
            }
        }
    }

    /// Shown whenever nothing is saved — no separate "loading" branch
    /// (unlike `SongbookSongsView`'s network-backed `content`) since, per
    /// `OfflineStorageViewModel`'s own header, a local cache read has no
    /// meaningful transient "loading" moment worth its own spinner state.
    private var emptyView: some View {
        ContentUnavailableView(
            "No Saved Songs",
            systemImage: "arrow.down.circle",
            description: Text("Tap the download button on any song to read it without an internet connection.")
        )
    }
}

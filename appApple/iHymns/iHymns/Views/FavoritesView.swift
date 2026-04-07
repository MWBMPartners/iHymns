// FavoritesView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - FavoritesView
/// Favourites management with tag filtering, batch selection,
/// and export/import capabilities.
struct FavoritesView: View {

    @EnvironmentObject var songStore: SongStore
    @State private var searchText: String = ""
    @State private var selectedTag: String?
    @State private var isSelectionMode: Bool = false
    @State private var selectedSongIds: Set<String> = []
    @State private var showingTagPicker: Bool = false
    @State private var showingNewTag: Bool = false
    @State private var newTagName: String = ""
    @State private var showingExportSheet: Bool = false
    @State private var showingImportSheet: Bool = false

    private var displayedFavorites: [Song] {
        var songs = songStore.favoriteSongs

        // Filter by tag
        if let tag = selectedTag {
            let taggedIds = Set(songStore.tagAssignments.songIds(for: tag))
            songs = songs.filter { taggedIds.contains($0.id) }
        }

        // Filter by search text
        if !searchText.isEmpty {
            let query = searchText.lowercased()
            songs = songs.filter {
                $0.title.lowercased().contains(query) ||
                $0.songbook.lowercased().contains(query) ||
                String($0.number).contains(query)
            }
        }

        return songs
    }

    /// All tags that have at least one favourite assigned.
    private var usedTags: [String] {
        let allFavIds = songStore.favorites
        var tags = Set<String>()
        for songId in allFavIds {
            for tag in songStore.tagsForSong(songId) {
                tags.insert(tag)
            }
        }
        return tags.sorted()
    }

    var body: some View {
        Group {
            if songStore.favoriteSongs.isEmpty {
                emptyState
            } else {
                favouritesContent
            }
        }
        .navigationTitle("Favourites")
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.large)
        .toolbar { toolbarContent }
        #endif
        .searchable(text: $searchText, prompt: "Filter favourites...")
        .alert("New Tag", isPresented: $showingNewTag) {
            TextField("Tag name", text: $newTagName)
            Button("Create") {
                songStore.createCustomTag(newTagName)
                newTagName = ""
            }
            Button("Cancel", role: .cancel) { newTagName = "" }
        }
    }

    // MARK: - Empty State

    private var emptyState: some View {
        ContentUnavailableView {
            Label("No Favourites Yet", systemImage: "star")
        } description: {
            Text("Tap the star icon on any song to add it to your favourites for quick access.")
        }
    }

    // MARK: - Content

    private var favouritesContent: some View {
        VStack(spacing: 0) {
            // Tag filter strip
            if !usedTags.isEmpty || !songStore.customTags.isEmpty {
                tagFilterStrip
            }

            // Batch action bar
            if isSelectionMode && !selectedSongIds.isEmpty {
                batchActionBar
            }

            // Song list
            List {
                Section {
                    Text("\(displayedFavorites.count) favourite\(displayedFavorites.count == 1 ? "" : "s")\(selectedTag.map { " tagged \"\($0)\"" } ?? "")")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .listRowBackground(Color.clear)
                }

                ForEach(displayedFavorites, id: \.id) { song in
                    if isSelectionMode {
                        selectionRow(song: song)
                    } else {
                        NavigationLink(destination: SongDetailView(song: song)) {
                            favouriteRow(song: song)
                        }
                        .swipeActions(edge: .trailing) {
                            Button(role: .destructive) {
                                songStore.toggleFavorite(song.id)
                            } label: {
                                Label("Remove", systemImage: "star.slash")
                            }

                            Button {
                                showingTagPicker = true
                            } label: {
                                Label("Tag", systemImage: "tag")
                            }
                            .tint(AmberTheme.accent)
                        }
                    }
                }
            }
            .listStyle(.plain)
        }
    }

    // MARK: - Tag Filter Strip

    private var tagFilterStrip: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: Spacing.sm) {
                // "All" chip
                Button {
                    selectedTag = nil
                    HapticManager.selectionChanged()
                } label: {
                    Text("All")
                        .font(.caption.bold())
                        .foregroundStyle(selectedTag == nil ? .white : .primary)
                        .padding(.horizontal, 12)
                        .padding(.vertical, 6)
                        .background(
                            selectedTag == nil
                            ? AnyShapeStyle(AmberTheme.accent) : AnyShapeStyle(.regularMaterial)
                        )
                        .clipShape(Capsule())
                }
                .buttonStyle(.plain)

                ForEach(songStore.allTags, id: \.name) { tag in
                    let isActive = selectedTag == tag.name
                    Button {
                        selectedTag = isActive ? nil : tag.name
                        HapticManager.selectionChanged()
                    } label: {
                        Text(tag.name)
                            .font(.caption.bold())
                            .foregroundStyle(isActive ? .white : .primary)
                            .padding(.horizontal, 12)
                            .padding(.vertical, 6)
                            .background(
                                isActive ? AnyShapeStyle(AmberTheme.accent) : AnyShapeStyle(.regularMaterial)
                            )
                            .clipShape(Capsule())
                    }
                    .buttonStyle(.plain)
                }

                // Add tag button
                Button { showingNewTag = true } label: {
                    Image(systemName: "plus")
                        .font(.caption)
                        .foregroundStyle(AmberTheme.accent)
                        .padding(6)
                        .background(.regularMaterial, in: Circle())
                }
                .buttonStyle(.plain)
            }
            .padding(.horizontal)
            .padding(.vertical, Spacing.sm)
        }
    }

    // MARK: - Batch Action Bar

    private var batchActionBar: some View {
        HStack(spacing: Spacing.md) {
            Text("\(selectedSongIds.count) selected")
                .font(.subheadline.bold())

            Spacer()

            // Batch tag
            Menu {
                ForEach(songStore.allTags, id: \.name) { tag in
                    Button(tag.name) {
                        songStore.batchAddTag(tag.name, to: selectedSongIds)
                        HapticManager.success()
                    }
                }
            } label: {
                Label("Tag", systemImage: "tag")
                    .font(.caption.bold())
            }

            // Batch remove
            Button(role: .destructive) {
                songStore.removeFavorites(selectedSongIds)
                selectedSongIds.removeAll()
                isSelectionMode = false
            } label: {
                Label("Remove", systemImage: "trash")
                    .font(.caption.bold())
            }
        }
        .padding(.horizontal)
        .padding(.vertical, Spacing.sm)
        .background(.regularMaterial)
    }

    // MARK: - Row Views

    private func favouriteRow(song: Song) -> some View {
        HStack(spacing: 14) {
            SongRow(song: song, songStore: songStore)

            // Tag badges
            let tags = songStore.tagsForSong(song.id)
            if !tags.isEmpty {
                HStack(spacing: 2) {
                    ForEach(tags.prefix(2), id: \.self) { tag in
                        Text(tag)
                            .font(.system(size: 9, weight: .medium))
                            .foregroundStyle(.secondary)
                            .padding(.horizontal, 4)
                            .padding(.vertical, 1)
                            .background(.regularMaterial, in: Capsule())
                    }
                    if tags.count > 2 {
                        Text("+\(tags.count - 2)")
                            .font(.system(size: 9))
                            .foregroundStyle(.secondary)
                    }
                }
            }
        }
    }

    private func selectionRow(song: Song) -> some View {
        Button {
            if selectedSongIds.contains(song.id) {
                selectedSongIds.remove(song.id)
            } else {
                selectedSongIds.insert(song.id)
            }
        } label: {
            HStack {
                Image(systemName: selectedSongIds.contains(song.id) ? "checkmark.circle.fill" : "circle")
                    .foregroundStyle(selectedSongIds.contains(song.id) ? AmberTheme.accent : .secondary)

                SongRow(song: song, songStore: songStore)
            }
        }
    }

    // MARK: - Toolbar

    #if !os(watchOS) && !os(tvOS)
    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItem(placement: .primaryAction) {
            Menu {
                Button {
                    isSelectionMode.toggle()
                    if !isSelectionMode { selectedSongIds.removeAll() }
                } label: {
                    Label(isSelectionMode ? "Cancel Selection" : "Select", systemImage: "checkmark.circle")
                }

                if isSelectionMode {
                    Button {
                        selectedSongIds = Set(displayedFavorites.map(\.id))
                    } label: {
                        Label("Select All", systemImage: "checkmark.circle.fill")
                    }
                }

                Divider()

                ShareLink(
                    item: exportFavoritesText(),
                    subject: Text("My iHymns Favourites"),
                    message: Text("Shared from iHymns")
                ) {
                    Label("Export as Text", systemImage: "doc.text")
                }

                if let jsonData = songStore.exportBackupJSON(),
                   let jsonString = String(data: jsonData, encoding: .utf8) {
                    ShareLink(
                        item: jsonString,
                        subject: Text("iHymns Backup"),
                        message: Text("iHymns data backup")
                    ) {
                        Label("Export Backup (JSON)", systemImage: "arrow.up.doc")
                    }
                }
            } label: {
                Image(systemName: "ellipsis.circle")
            }
        }
    }
    #endif

    // MARK: - Helpers

    private func exportFavoritesText() -> String {
        var lines = ["My iHymns Favourites", ""]
        for (index, song) in songStore.favoriteSongs.enumerated() {
            let tags = songStore.tagsForSong(song.id)
            let tagStr = tags.isEmpty ? "" : " [\(tags.joined(separator: ", "))]"
            lines.append("\(index + 1). \(song.title) (\(song.songbook) #\(song.number))\(tagStr)")
        }
        lines.append("\nExported from iHymns — ihymns.app")
        return lines.joined(separator: "\n")
    }
}

// MARK: - Preview
#if DEBUG
#Preview {
    NavigationStack {
        FavoritesView()
            .environmentObject(SongStore())
    }
}
#endif

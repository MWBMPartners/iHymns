// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  SetListsView.swift
//  iHymns
//
//  Worship set list management view. Allows users to create, edit,
//  reorder, share, and navigate through ordered song collections
//  for worship services.
//

import SwiftUI

// MARK: - SetListsView

/// Main set lists screen showing all user-created set lists.
struct SetListsView: View {

    @EnvironmentObject var songStore: SongStore

    @State private var showingNewSetListSheet = false
    @State private var newSetListName = ""

    var body: some View {
        Group {
            if songStore.setLists.isEmpty {
                emptyState
            } else {
                setListsList
            }
        }
        .navigationTitle("Set Lists")
        .toolbar {
            ToolbarItem(placement: .primaryAction) {
                Button(action: { showingNewSetListSheet = true }) {
                    Label("New Set List", systemImage: "plus")
                }
            }
        }
        .alert("New Set List", isPresented: $showingNewSetListSheet) {
            TextField("Set list name", text: $newSetListName)
            Button("Create") {
                let name = newSetListName.trimmingCharacters(in: .whitespacesAndNewlines)
                if !name.isEmpty {
                    songStore.createSetList(name: name)
                    HapticManager.success()
                }
                newSetListName = ""
            }
            Button("Cancel", role: .cancel) {
                newSetListName = ""
            }
        } message: {
            Text("Enter a name for your new worship set list.")
        }
    }

    // MARK: - Empty State

    private var emptyState: some View {
        ContentUnavailableView {
            Label("No Set Lists", systemImage: "list.bullet.rectangle")
        } description: {
            Text("Create a set list to organise songs for your worship service.")
        } actions: {
            LiquidGlassButton("Create Set List", systemImage: "plus") {
                showingNewSetListSheet = true
            }
        }
    }

    // MARK: - Set Lists List

    private var setListsList: some View {
        List {
            ForEach(songStore.setLists) { setList in
                NavigationLink(destination: SetListDetailView(setList: setList)) {
                    setListRow(setList)
                }
            }
            .onDelete { offsets in
                songStore.deleteSetLists(at: offsets)
            }
        }
    }

    // MARK: - Set List Row

    private func setListRow(_ setList: SetList) -> some View {
        VStack(alignment: .leading, spacing: Spacing.xs) {
            HStack {
                Text(setList.name)
                    .font(.headline)

                Spacer()

                if setList.shareId != nil {
                    Image(systemName: "link")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            Text("\(setList.songIds.count) song\(setList.songIds.count == 1 ? "" : "s")")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .padding(.vertical, Spacing.xs)
    }
}

// MARK: - SetListDetailView

/// Detail view for a single set list showing all songs with
/// reordering, sharing, and sequential navigation.
struct SetListDetailView: View {

    let setList: SetList
    @EnvironmentObject var songStore: SongStore

    @State private var showingAddSong = false
    @State private var showingShareSheet = false
    @State private var shareURL: String?
    @State private var isSharing = false

    var body: some View {
        Group {
            if setList.songIds.isEmpty {
                emptyState
            } else {
                songList
            }
        }
        .navigationTitle(setList.name)
        .toolbar {
            #if !os(watchOS) && !os(tvOS)
            ToolbarItem(placement: .primaryAction) {
                Menu {
                    Button(action: { showingAddSong = true }) {
                        Label("Add Song", systemImage: "plus")
                    }

                    Button(action: shareSetList) {
                        Label("Share Set List", systemImage: "square.and.arrow.up")
                    }

                    if let text = exportSetListText() {
                        ShareLink(item: text) {
                            Label("Export as Text", systemImage: "doc.text")
                        }
                    }
                } label: {
                    Image(systemName: "ellipsis.circle")
                }
            }
            #endif
        }
        .sheet(isPresented: $showingAddSong) {
            AddSongToSetListView(setListId: setList.id)
                .environmentObject(songStore)
        }
    }

    // MARK: - Song List

    private var songList: some View {
        List {
            ForEach(Array(setList.songIds.enumerated()), id: \.offset) { index, songId in
                if let song = songStore.song(byId: songId) {
                    NavigationLink(destination: SongDetailView(song: song)) {
                        HStack(spacing: Spacing.md) {
                            Text("\(index + 1)")
                                .font(.caption.weight(.bold))
                                .foregroundStyle(.secondary)
                                .frame(width: 24)

                            VStack(alignment: .leading, spacing: 2) {
                                Text(song.title)
                                    .font(.body)
                                    .lineLimit(1)

                                Text("\(song.songbook) #\(song.number)")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }
                }
            }
            .onDelete { offsets in
                songStore.removeSongFromSetList(at: offsets, setListId: setList.id)
            }
            .onMove { source, destination in
                songStore.moveSetListSongs(from: source, to: destination, setListId: setList.id)
            }
        }
    }

    // MARK: - Empty State

    private var emptyState: some View {
        ContentUnavailableView {
            Label("Empty Set List", systemImage: "music.note.list")
        } description: {
            Text("Add songs from any songbook to build your worship set list.")
        } actions: {
            LiquidGlassButton("Add Songs", systemImage: "plus") {
                showingAddSong = true
            }
        }
    }

    // MARK: - Actions

    private func shareSetList() {
        isSharing = true
        Task {
            shareURL = await songStore.shareSetList(setList)
            isSharing = false
            if shareURL != nil {
                HapticManager.success()
            }
        }
    }

    private func exportSetListText() -> String? {
        guard !setList.songIds.isEmpty else { return nil }

        var lines = ["\(setList.name)\n"]
        for (index, songId) in setList.songIds.enumerated() {
            if let song = songStore.song(byId: songId) {
                lines.append("\(index + 1). \(song.title) (\(song.songbook) #\(song.number))")
            }
        }
        lines.append("\nCreated with iHymns — ihymns.app")
        return lines.joined(separator: "\n")
    }
}

// MARK: - AddSongToSetListView

/// Search-based song picker for adding songs to a set list.
struct AddSongToSetListView: View {

    let setListId: UUID
    @EnvironmentObject var songStore: SongStore
    @Environment(\.dismiss) private var dismiss

    @State private var searchText = ""

    private var searchResults: [Song] {
        songStore.searchSongsLocally(query: searchText)
    }

    var body: some View {
        NavigationStack {
            List {
                if searchText.isEmpty {
                    // Show songbooks for browsing
                    if let songbooks = songStore.songData?.songbooks {
                        ForEach(songbooks) { songbook in
                            Section(songbook.name) {
                                ForEach(songStore.songsForSongbook(songbook.id).prefix(10)) { song in
                                    songRow(song)
                                }
                            }
                        }
                    }
                } else {
                    ForEach(searchResults.prefix(50)) { song in
                        songRow(song)
                    }
                }
            }
            .searchable(text: $searchText, prompt: "Search songs to add...")
            .navigationTitle("Add Song")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") { dismiss() }
                }
            }
        }
    }

    private func songRow(_ song: Song) -> some View {
        Button {
            songStore.addSongToSetList(song.id, setListId: setListId)
            HapticManager.lightImpact()
        } label: {
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text(song.title)
                        .font(.body)
                        .foregroundStyle(.primary)
                    Text("\(song.songbook) #\(song.number)")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                Spacer()

                Image(systemName: "plus.circle")
                    .foregroundStyle(AmberTheme.accent)
            }
        }
    }
}

// SongListView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - SongListView
/// Displays songs belonging to a specific songbook with:
/// - Alphabetical jump-to-letter index strip
/// - Sort toggle (by number or A-Z by title)
/// - Local search filtering
/// - Favourite star indicators
struct SongListView: View {

    let songbookId: String

    @EnvironmentObject var songStore: SongStore
    @State private var searchText: String = ""
    @State private var sortMode: SongStore.SongSortMode = .number

    private var filteredSongs: [Song] {
        let allSongs = songStore.songsForSongbook(songbookId, sortedBy: sortMode)

        guard !searchText.isEmpty else { return allSongs }

        let query = searchText.lowercased()
        return allSongs.filter { song in
            song.title.lowercased().contains(query)
            || String(song.number).contains(query)
            || song.writers.contains { $0.lowercased().contains(query) }
        }
    }

    private var songbook: Songbook? {
        songStore.songData?.songbooks.first { $0.id == songbookId }
    }

    /// Available first letters for the alphabetical index strip.
    private var availableLetters: [String] {
        let letters = Set(filteredSongs.compactMap { $0.title.first.map { String($0).uppercased() } })
        return letters.sorted()
    }

    var body: some View {
        ScrollViewReader { proxy in
            ZStack(alignment: .trailing) {
                List {
                    // Sort toggle and count header
                    Section {
                        HStack {
                            Text("\(filteredSongs.count) songs")
                                .font(.caption)
                                .foregroundStyle(.secondary)

                            Spacer()

                            Picker("Sort", selection: $sortMode) {
                                ForEach(SongStore.SongSortMode.allCases, id: \.self) { mode in
                                    Text(mode.rawValue).tag(mode)
                                }
                            }
                            .pickerStyle(.segmented)
                            .frame(width: 140)
                        }
                        .listRowBackground(Color.clear)
                    }

                    // Song rows — when sorted A-Z, group by first letter for scroll targets
                    if sortMode == .title {
                        ForEach(availableLetters, id: \.self) { letter in
                            Section {
                                ForEach(filteredSongs.filter { $0.title.prefix(1).uppercased() == letter }) { song in
                                    NavigationLink(destination: SongDetailView(song: song)) {
                                        SongRow(song: song, songStore: songStore)
                                    }
                                }
                            } header: {
                                Text(letter)
                                    .font(.headline)
                                    .id("letter-\(letter)")
                            }
                        }
                    } else {
                        ForEach(filteredSongs, id: \.id) { song in
                            NavigationLink(destination: SongDetailView(song: song)) {
                                SongRow(song: song, songStore: songStore)
                            }
                        }
                    }
                }
                .listStyle(.plain)

                // Alphabetical index strip (right edge)
                #if !os(watchOS) && !os(tvOS)
                if searchText.isEmpty && sortMode == .title && availableLetters.count > 5 {
                    alphabeticalIndex(proxy: proxy)
                }
                #endif
            }
        }
        .navigationTitle(songbook?.name ?? songbookId)
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.large)
        #endif
        .searchable(text: $searchText, prompt: "Search by title or number...")
        .overlay {
            if filteredSongs.isEmpty && !searchText.isEmpty {
                ContentUnavailableView.search(text: searchText)
            } else if filteredSongs.isEmpty {
                ContentUnavailableView(
                    "No Songs",
                    systemImage: "music.note",
                    description: Text("This songbook does not contain any songs.")
                )
            }
        }
    }

    // MARK: - Alphabetical Index Strip

    #if !os(watchOS) && !os(tvOS)
    private func alphabeticalIndex(proxy: ScrollViewProxy) -> some View {
        VStack(spacing: 1) {
            ForEach(availableLetters, id: \.self) { letter in
                Button {
                    HapticManager.selectionChanged()
                    withAnimation(.liquidGlassQuick) {
                        proxy.scrollTo("letter-\(letter)", anchor: .top)
                    }
                } label: {
                    Text(letter)
                        .font(.system(size: 11, weight: .semibold))
                        .foregroundStyle(AmberTheme.accent)
                        .frame(width: 16, height: 14)
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.vertical, 4)
        .padding(.horizontal, 4)
        .background(
            RoundedRectangle(cornerRadius: 8, style: .continuous)
                .fill(.ultraThinMaterial)
        )
        .padding(.trailing, 4)
    }
    #endif
}

// MARK: - SongRow
/// A single row showing song number, title, preview, and favourite star.
struct SongRow: View {

    let song: Song
    @ObservedObject var songStore: SongStore

    var body: some View {
        HStack(spacing: 14) {
            // Song number badge with songbook colour
            Text("\(song.number)")
                .font(.subheadline.bold().monospacedDigit())
                .foregroundStyle(.white)
                .frame(width: 44, height: 44)
                .background(
                    AmberTheme.songbookColor(song.songbook),
                    in: RoundedRectangle(cornerRadius: 8)
                )

            VStack(alignment: .leading, spacing: 4) {
                Text(song.title)
                    .font(.body.bold())
                    .lineLimit(1)

                if let firstComponent = song.components.first {
                    Text(firstComponent.lines.prefix(2).joined(separator: " "))
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }

            Spacer()

            if songStore.isFavorite(song.id) {
                Image(systemName: "star.fill")
                    .foregroundStyle(AmberTheme.light)
                    .imageScale(.small)
            }
        }
        .padding(.vertical, 4)
    }
}

// MARK: - Preview
#if DEBUG
#Preview {
    NavigationStack {
        SongListView(songbookId: "CP")
            .environmentObject(SongStore())
    }
}
#endif

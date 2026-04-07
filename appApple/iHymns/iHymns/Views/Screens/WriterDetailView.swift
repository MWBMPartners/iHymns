// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - WriterDetailView
/// Shows all songs by a specific writer or composer, grouped by songbook.
struct WriterDetailView: View {

    let writerName: String
    @EnvironmentObject var songStore: SongStore

    private var songsByWriter: [Song] {
        guard let songs = songStore.songData?.songs else { return [] }
        let nameLower = writerName.lowercased()
        return songs.filter {
            $0.writers.contains { $0.lowercased() == nameLower } ||
            $0.composers.contains { $0.lowercased() == nameLower }
        }
        .sorted { $0.title < $1.title }
    }

    /// Songs grouped by songbook for sectioned display.
    private var groupedSongs: [(songbook: String, songs: [Song])] {
        let grouped = Dictionary(grouping: songsByWriter, by: \.songbook)
        return grouped.sorted { $0.key < $1.key }
            .map { (songbook: $0.key, songs: $0.value.sorted { $0.number < $1.number }) }
    }

    /// Whether this person appears as a writer, composer, or both.
    private var role: String {
        let isWriter = songsByWriter.contains { $0.writers.contains { $0.lowercased() == writerName.lowercased() } }
        let isComposer = songsByWriter.contains { $0.composers.contains { $0.lowercased() == writerName.lowercased() } }
        if isWriter && isComposer { return "Writer & Composer" }
        if isComposer { return "Composer" }
        return "Writer"
    }

    var body: some View {
        List {
            // Header section
            Section {
                VStack(alignment: .leading, spacing: Spacing.sm) {
                    Text(writerName)
                        .font(.title2.bold())
                    Text(role)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    Text("\(songsByWriter.count) song\(songsByWriter.count == 1 ? "" : "s") across \(groupedSongs.count) songbook\(groupedSongs.count == 1 ? "" : "s")")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                .listRowBackground(Color.clear)
            }

            // Songs grouped by songbook
            ForEach(groupedSongs, id: \.songbook) { group in
                Section(group.songbook) {
                    ForEach(group.songs) { song in
                        NavigationLink(destination: SongDetailView(song: song)) {
                            SongRow(song: song, songStore: songStore)
                        }
                    }
                }
            }
        }
        .listStyle(.insetGrouped)
        .navigationTitle(writerName)
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.large)
        #endif
    }
}

// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - CompareView
/// Side-by-side (iPad) or tabbed (iPhone) comparison of two songs.
struct CompareView: View {

    let songA: Song
    @EnvironmentObject var songStore: SongStore
    @State private var songB: Song?
    @State private var showingPicker = false

    #if !os(watchOS)
    @Environment(\.horizontalSizeClass) private var sizeClass
    #endif

    var body: some View {
        Group {
            if let songB = songB {
                #if os(watchOS) || os(tvOS)
                tabbedComparison(songA: songA, songB: songB)
                #else
                if sizeClass == .compact {
                    tabbedComparison(songA: songA, songB: songB)
                } else {
                    splitComparison(songA: songA, songB: songB)
                }
                #endif
            } else {
                pickSongPrompt
            }
        }
        .navigationTitle("Compare")
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .primaryAction) {
                Button { showingPicker = true } label: {
                    Label("Pick Song", systemImage: "plus.rectangle.on.rectangle")
                }
            }
        }
        #endif
        .sheet(isPresented: $showingPicker) {
            CompareSongPicker(excludeId: songA.id) { selected in
                songB = selected
            }
            .environmentObject(songStore)
        }
    }

    // MARK: - Prompt

    private var pickSongPrompt: some View {
        ContentUnavailableView {
            Label("Pick a Song to Compare", systemImage: "rectangle.on.rectangle")
        } description: {
            Text("Select a second song to compare side-by-side with \"\(songA.title)\".")
        } actions: {
            LiquidGlassButton("Choose Song", systemImage: "magnifyingglass") {
                showingPicker = true
            }
        }
    }

    // MARK: - Tabbed (iPhone)

    private func tabbedComparison(songA: Song, songB: Song) -> some View {
        TabView {
            ScrollView { songLyricsColumn(song: songA) }
                .tabItem { Text(songA.title) }
            ScrollView { songLyricsColumn(song: songB) }
                .tabItem { Text(songB.title) }
        }
    }

    // MARK: - Split (iPad/Mac)

    private func splitComparison(songA: Song, songB: Song) -> some View {
        HStack(spacing: 0) {
            ScrollView { songLyricsColumn(song: songA) }
                .frame(maxWidth: .infinity)
            Divider()
            ScrollView { songLyricsColumn(song: songB) }
                .frame(maxWidth: .infinity)
        }
    }

    // MARK: - Song Column

    private func songLyricsColumn(song: Song) -> some View {
        VStack(alignment: .leading, spacing: Spacing.lg) {
            // Header
            VStack(alignment: .leading, spacing: 4) {
                Text("\(song.songbook) #\(song.number)")
                    .font(.caption.bold())
                    .foregroundStyle(AmberTheme.songbookColor(song.songbook))
                Text(song.title)
                    .font(.title3.bold())
            }

            // Components
            ForEach(song.arrangedComponents, id: \.offset) { _, component in
                VStack(alignment: .leading, spacing: 4) {
                    Text(component.type.capitalized)
                        .font(Typography.componentLabel)
                        .foregroundStyle(AmberTheme.accent)
                    Text(component.lines.joined(separator: "\n"))
                        .font(.body)
                }
            }
        }
        .padding()
    }
}

// MARK: - CompareSongPicker

struct CompareSongPicker: View {
    let excludeId: String
    let onSelect: (Song) -> Void

    @EnvironmentObject var songStore: SongStore
    @Environment(\.dismiss) private var dismiss
    @State private var searchText = ""

    private var results: [Song] {
        songStore.searchSongsLocally(query: searchText)
            .filter { $0.id != excludeId }
    }

    var body: some View {
        NavigationStack {
            List {
                ForEach(results.prefix(30)) { song in
                    Button {
                        onSelect(song)
                        dismiss()
                    } label: {
                        SongRow(song: song, songStore: songStore)
                    }
                }
            }
            .searchable(text: $searchText, prompt: "Search for a song...")
            .navigationTitle("Compare With")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
        }
    }
}

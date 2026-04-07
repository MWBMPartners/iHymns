// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - StatisticsView
/// Usage statistics dashboard showing songs viewed, favourites,
/// set lists, and activity metrics.
struct StatisticsView: View {

    @EnvironmentObject var songStore: SongStore

    private var totalSongs: Int { songStore.songData?.meta.totalSongs ?? 0 }
    private var totalSongbooks: Int { songStore.songData?.meta.totalSongbooks ?? 0 }
    private var totalFavourites: Int { songStore.favorites.count }
    private var totalSetLists: Int { songStore.setLists.count }
    private var totalSetListSongs: Int { songStore.setLists.reduce(0) { $0 + $1.songIds.count } }
    private var totalViewed: Int { songStore.viewHistory.count }

    /// Favourites grouped by songbook with counts.
    private var favouritesBySongbook: [(songbook: String, count: Int)] {
        let grouped = Dictionary(grouping: songStore.favoriteSongs, by: \.songbook)
        return grouped.map { (songbook: $0.key, count: $0.value.count) }
            .sorted { $0.count > $1.count }
    }

    var body: some View {
        List {
            // Collection stats
            Section("Collection") {
                statRow("Total Songs", value: "\(totalSongs)", icon: "music.note")
                statRow("Songbooks", value: "\(totalSongbooks)", icon: "books.vertical")
            }

            // User stats
            Section("Your Activity") {
                statRow("Favourites", value: "\(totalFavourites)", icon: "star.fill")
                statRow("Set Lists", value: "\(totalSetLists)", icon: "list.bullet.rectangle")
                statRow("Songs in Set Lists", value: "\(totalSetListSongs)", icon: "music.note.list")
                statRow("Recently Viewed", value: "\(totalViewed)", icon: "clock")
                statRow("Searches", value: "\(songStore.searchHistory.count)", icon: "magnifyingglass")
            }

            // Favourites by songbook
            if !favouritesBySongbook.isEmpty {
                Section("Favourites by Songbook") {
                    ForEach(favouritesBySongbook, id: \.songbook) { item in
                        HStack {
                            Text(item.songbook)
                                .font(.subheadline.bold())
                                .foregroundStyle(.white)
                                .frame(width: 36, height: 28)
                                .background(AmberTheme.songbookColor(item.songbook), in: RoundedRectangle(cornerRadius: 6))

                            Text(songStore.songData?.songbooks.first { $0.id == item.songbook }?.name ?? item.songbook)
                                .font(.subheadline)

                            Spacer()

                            Text("\(item.count)")
                                .font(.subheadline.bold())
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            }

            // Most viewed (from history)
            if !songStore.viewHistory.isEmpty {
                Section("Recently Viewed") {
                    ForEach(songStore.viewHistory.prefix(10)) { entry in
                        if let song = songStore.song(byId: entry.songId) {
                            NavigationLink(destination: SongDetailView(song: song)) {
                                HStack {
                                    Text(song.title)
                                        .font(.subheadline)
                                        .lineLimit(1)
                                    Spacer()
                                    Text(entry.timestamp, style: .relative)
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                            }
                        }
                    }
                }
            }
        }
        .listStyle(.insetGrouped)
        .navigationTitle("Statistics")
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.large)
        #endif
    }

    private func statRow(_ label: String, value: String, icon: String) -> some View {
        HStack {
            Label(label, systemImage: icon)
                .font(.subheadline)
            Spacer()
            Text(value)
                .font(.subheadline.bold())
                .foregroundStyle(AmberTheme.accent)
        }
    }
}

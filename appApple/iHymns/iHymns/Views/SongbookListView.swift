// SongbookListView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - SongbookListView
/// Displays all available songbooks with Liquid Glass cards.
///
/// Layout per platform:
/// - **iPad / Mac / visionOS**: Two-column LazyVGrid with glass cards
/// - **iPhone**: Vertical List with compact rows
/// - **tvOS**: Horizontally focusable oversized cards
/// - **watchOS**: Minimal vertical list
struct SongbookListView: View {

    @EnvironmentObject var songStore: SongStore

    #if !os(watchOS)
    @Environment(\.horizontalSizeClass) private var sizeClass
    #endif

    @Binding var selectedSongbookId: String?

    init() {
        _selectedSongbookId = .constant(nil)
    }

    init(selectedSongbookId: Binding<String?>) {
        _selectedSongbookId = selectedSongbookId
    }

    private let gridColumns = [
        GridItem(.adaptive(minimum: 260, maximum: 400), spacing: 20)
    ]

    var body: some View {
        ZStack {
            if songStore.isLoading {
                VStack(spacing: 16) {
                    ProgressView()
                        .controlSize(.large)
                    Text("Loading songbooks...")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
            } else if let songData = songStore.songData {
                songbookContent(songData: songData)
            } else if let error = songStore.errorMessage {
                ContentUnavailableView {
                    Label("Error", systemImage: "exclamationmark.triangle")
                } description: {
                    Text(error)
                }
            }
        }
        #if !os(watchOS) && !os(tvOS)
        .background(AmberTheme.wash.opacity(0.3))
        #endif
    }

    // MARK: - Content Switcher

    @ViewBuilder
    private func songbookContent(songData: SongData) -> some View {
        #if os(watchOS)
        List(songData.songbooks, id: \.id) { songbook in
            NavigationLink(destination: SongListView(songbookId: songbook.id)) {
                watchOSRow(for: songbook)
            }
        }

        #elseif os(tvOS)
        ScrollView {
            LazyVGrid(columns: gridColumns, spacing: 40) {
                ForEach(songData.songbooks, id: \.id) { songbook in
                    NavigationLink(destination: SongListView(songbookId: songbook.id)) {
                        songbookCard(for: songbook)
                            .frame(minHeight: 200)
                    }
                    .buttonStyle(.card)
                }
            }
            .padding(40)
        }

        #else
        if sizeClass == .compact {
            compactLayout(songData: songData)
        } else {
            regularLayout(songData: songData)
        }
        #endif
    }

    // MARK: - Compact Layout (iPhone)

    #if !os(watchOS) && !os(tvOS)
    private func compactLayout(songData: SongData) -> some View {
        List {
            // Recently viewed section
            if !songStore.viewHistory.isEmpty {
                Section("Recently Viewed") {
                    ForEach(songStore.viewHistory.prefix(3)) { entry in
                        if let song = songStore.song(byId: entry.songId) {
                            NavigationLink(destination: SongDetailView(song: song)) {
                                HStack(spacing: Spacing.sm) {
                                    Image(systemName: "clock")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                    VStack(alignment: .leading) {
                                        Text(song.title)
                                            .font(.subheadline)
                                            .lineLimit(1)
                                        Text(song.songbookName)
                                            .font(.caption)
                                            .foregroundStyle(.secondary)
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Song of the Day
            if let sotd = songStore.songOfTheDay {
                Section {
                    NavigationLink(destination: SongDetailView(song: sotd)) {
                        HStack(spacing: Spacing.md) {
                            Image(systemName: "sparkles")
                                .font(.title3)
                                .foregroundStyle(AmberTheme.light)

                            VStack(alignment: .leading, spacing: 2) {
                                Text(sotd.title)
                                    .font(.body.bold())
                                    .lineLimit(1)

                                HStack(spacing: 6) {
                                    Text("\(sotd.songbook) #\(sotd.number)")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)

                                    if let theme = songStore.songOfTheDayTheme {
                                        Text(theme)
                                            .font(.caption2.bold())
                                            .foregroundStyle(.white)
                                            .padding(.horizontal, 6)
                                            .padding(.vertical, 1)
                                            .background(AmberTheme.accent, in: Capsule())
                                    }
                                }
                            }
                        }
                        .padding(.vertical, Spacing.xs)
                    }
                } header: {
                    Label("Song of the Day", systemImage: "sparkles")
                }
            }

            // Songbooks
            Section("Songbooks") {
                ForEach(songData.songbooks, id: \.id) { songbook in
                    NavigationLink(destination: SongListView(songbookId: songbook.id)) {
                        songbookRow(for: songbook)
                    }
                }
            }

            // Quick links
            Section {
                NavigationLink(destination: StatisticsView()) {
                    Label("Statistics", systemImage: "chart.bar")
                }
            }
        }
        .listStyle(.insetGrouped)
    }
    #endif

    // MARK: - Regular Layout (iPad / Mac)

    #if !os(watchOS) && !os(tvOS)
    private func regularLayout(songData: SongData) -> some View {
        ScrollView {
            amberHeader(songData: songData)

            LazyVGrid(columns: gridColumns, spacing: 20) {
                ForEach(songData.songbooks, id: \.id) { songbook in
                    Button {
                        selectedSongbookId = songbook.id
                    } label: {
                        songbookCard(for: songbook)
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal, 24)
            .padding(.bottom, 24)
        }
    }
    #endif

    // MARK: - Amber Header

    #if !os(watchOS) && !os(tvOS)
    private func amberHeader(songData: SongData) -> some View {
        ZStack(alignment: .bottomLeading) {
            LinearGradient(
                colors: [AmberTheme.accent, AmberTheme.light],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            )
            .frame(height: 120)

            VStack(alignment: .leading, spacing: 4) {
                Text("iHymns")
                    .font(.largeTitle.bold())
                    .foregroundStyle(.white)
                Text("\(songData.meta.totalSongs) hymns across \(songData.meta.totalSongbooks) songbooks")
                    .font(.subheadline)
                    .foregroundStyle(.white.opacity(0.85))
            }
            .padding(.horizontal, 24)
            .padding(.bottom, 16)
        }
    }
    #endif

    // MARK: - Songbook Card (Liquid Glass)

    private func songbookCard(for songbook: Songbook) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Text(songbook.id)
                    .font(.headline.bold())
                    .foregroundStyle(.white)
                    .padding(.horizontal, 14)
                    .padding(.vertical, 8)
                    .background(
                        AmberTheme.songbookColor(songbook.id),
                        in: RoundedRectangle(cornerRadius: 8)
                    )
                Spacer()
            }

            Text(songbook.name)
                .font(.title3.bold())
                .foregroundStyle(.primary)
                .lineLimit(2)
                .multilineTextAlignment(.leading)

            Text("\(songbook.songCount) songs")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .liquidGlass(.regular, tint: AmberTheme.songbookColor(songbook.id))
    }

    // MARK: - Songbook Row (iPhone)

    private func songbookRow(for songbook: Songbook) -> some View {
        HStack(spacing: 14) {
            Text(songbook.id)
                .font(.headline.bold())
                .foregroundStyle(.white)
                .frame(width: 50, height: 50)
                .background(
                    AmberTheme.songbookColor(songbook.id),
                    in: RoundedRectangle(cornerRadius: 10)
                )

            VStack(alignment: .leading, spacing: 4) {
                Text(songbook.name)
                    .font(.body.bold())
                Text("\(songbook.songCount) songs")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            Spacer()
        }
        .padding(.vertical, 4)
    }

    // MARK: - watchOS Row

    #if os(watchOS)
    private func watchOSRow(for songbook: Songbook) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(songbook.id)
                .font(.caption2.bold())
                .foregroundStyle(AmberTheme.accent)
            Text(songbook.name)
                .font(.body)
            Text("\(songbook.songCount) songs")
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
    }
    #endif
}

// MARK: - Preview
#if DEBUG
#Preview {
    NavigationStack {
        SongbookListView()
            .environmentObject(SongStore())
    }
}
#endif

// SearchView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - SearchView
/// Full-text search across all songs with search history, API fallback,
/// and Liquid Glass result cards.
struct SearchView: View {

    @EnvironmentObject var songStore: SongStore
    @State var searchText: String
    @State private var isSearchingAPI: Bool = false

    init() {
        _searchText = State(initialValue: "")
    }

    init(initialSearchText: String) {
        _searchText = State(initialValue: initialSearchText)
    }

    /// Local search results from the indexed song store.
    private var searchResults: [Song] {
        songStore.searchSongsLocally(query: searchText)
    }

    var body: some View {
        Group {
            if searchText.isEmpty {
                searchIdleState
            } else if searchResults.isEmpty && !isSearchingAPI {
                ContentUnavailableView.search(text: searchText)
            } else {
                resultsList
            }
        }
        .navigationTitle("Search")
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.large)
        #endif
        .searchable(text: $searchText, prompt: "Title, lyrics, number, writer...")
        .onSubmit(of: .search) {
            songStore.recordSearch(searchText)
        }
    }

    // MARK: - Idle State (Search History + Prompt)

    private var searchIdleState: some View {
        ScrollView {
            VStack(spacing: Spacing.xl) {
                // Search prompt
                VStack(spacing: Spacing.sm) {
                    Image(systemName: "magnifyingglass")
                        .font(.system(size: 48, weight: .light))
                        .foregroundStyle(AmberTheme.light.opacity(0.6))

                    Text("Search Hymns")
                        .font(Typography.sectionHeader)

                    Text("Search across all songbooks by title, lyrics, song number, writer or composer.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                }
                .padding(.top, Spacing.xxl)

                // Search history
                if !songStore.searchHistory.isEmpty {
                    VStack(alignment: .leading, spacing: Spacing.md) {
                        HStack {
                            Text("Recent Searches")
                                .font(.headline)
                            Spacer()
                            Button("Clear") {
                                songStore.clearSearchHistory()
                            }
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        }

                        // History chips
                        FlowLayout(spacing: 8) {
                            ForEach(songStore.searchHistory) { entry in
                                Button {
                                    searchText = entry.query
                                } label: {
                                    HStack(spacing: 4) {
                                        Image(systemName: "clock")
                                            .font(.caption2)
                                        Text(entry.query)
                                            .font(.subheadline)
                                    }
                                    .padding(.horizontal, 12)
                                    .padding(.vertical, 6)
                                    .liquidGlass(.thin, tint: AmberTheme.accent)
                                }
                                .buttonStyle(.plain)
                                .foregroundStyle(.primary)
                            }
                        }
                    }
                    .padding(.horizontal)
                }

                // Song of the Day suggestion
                if let sotd = songStore.songOfTheDay {
                    VStack(alignment: .leading, spacing: Spacing.sm) {
                        Text("Song of the Day")
                            .font(.headline)
                            .padding(.horizontal)

                        NavigationLink(destination: SongDetailView(song: sotd)) {
                            HStack(spacing: Spacing.md) {
                                Image(systemName: "sparkles")
                                    .font(.title2)
                                    .foregroundStyle(AmberTheme.light)

                                VStack(alignment: .leading, spacing: 2) {
                                    Text(sotd.title)
                                        .font(.body.bold())
                                    Text("\(sotd.songbook) #\(sotd.number)")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }

                                Spacer()

                                Image(systemName: "chevron.right")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            .padding()
                            .liquidGlass(.regular, tint: AmberTheme.accent)
                        }
                        .buttonStyle(.plain)
                        .padding(.horizontal)
                    }
                }
            }
        }
    }

    // MARK: - Results List

    private var resultsList: some View {
        List {
            Section {
                Text("\(searchResults.count) result\(searchResults.count == 1 ? "" : "s") for \"\(searchText)\"")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .listRowBackground(Color.clear)
            }

            Section {
                ForEach(searchResults.prefix(100), id: \.id) { song in
                    NavigationLink(destination: SongDetailView(song: song)) {
                        searchResultRow(for: song)
                    }
                }
            }
        }
        .listStyle(.plain)
    }

    // MARK: - Search Result Row

    private func searchResultRow(for song: Song) -> some View {
        HStack(spacing: 14) {
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

                HStack(spacing: 8) {
                    Text(song.songbook)
                        .font(.caption2.bold())
                        .foregroundStyle(.white)
                        .padding(.horizontal, 6)
                        .padding(.vertical, 2)
                        .background(
                            AmberTheme.songbookColor(song.songbook).opacity(0.7),
                            in: Capsule()
                        )

                    if !song.writers.isEmpty {
                        Text(song.writersDisplay)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    } else if let firstComponent = song.components.first {
                        Text(firstComponent.lines.prefix(2).joined(separator: " "))
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    }
                }
            }

            Spacer()
        }
        .padding(.vertical, 4)
    }
}

// MARK: - FlowLayout
/// A simple flow layout that wraps items to the next line when they exceed
/// the available width. Used for search history chips.
struct FlowLayout: Layout {
    var spacing: CGFloat = 8

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let result = layout(proposal: proposal, subviews: subviews)
        return result.size
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        let result = layout(proposal: proposal, subviews: subviews)
        for (index, subview) in subviews.enumerated() {
            guard index < result.positions.count else { break }
            subview.place(
                at: CGPoint(
                    x: bounds.minX + result.positions[index].x,
                    y: bounds.minY + result.positions[index].y
                ),
                proposal: .unspecified
            )
        }
    }

    private func layout(proposal: ProposedViewSize, subviews: Subviews) -> (size: CGSize, positions: [CGPoint]) {
        let maxWidth = proposal.width ?? .infinity
        var positions: [CGPoint] = []
        var x: CGFloat = 0
        var y: CGFloat = 0
        var rowHeight: CGFloat = 0
        var maxX: CGFloat = 0

        for subview in subviews {
            let size = subview.sizeThatFits(.unspecified)
            if x + size.width > maxWidth && x > 0 {
                x = 0
                y += rowHeight + spacing
                rowHeight = 0
            }
            positions.append(CGPoint(x: x, y: y))
            rowHeight = max(rowHeight, size.height)
            x += size.width + spacing
            maxX = max(maxX, x)
        }

        return (CGSize(width: maxX, height: y + rowHeight), positions)
    }
}

// MARK: - Preview
#if DEBUG
#Preview {
    NavigationStack {
        SearchView()
            .environmentObject(SongStore())
    }
}
#endif

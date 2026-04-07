// SearchView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI
import Combine

// MARK: - SearchView
/// Full-text search with fuzzy matching, numpad number lookup,
/// songbook filtering, search history, and random song picker.
struct SearchView: View {

    @EnvironmentObject var songStore: SongStore
    @State var searchText: String
    @State private var debouncedQuery: String = ""
    @State private var searchMode: SearchMode = .text
    @State private var selectedSongbook: String?
    @State private var numberInput: String = ""
    @State private var showingRandomSong: Bool = false
    @State private var randomSong: Song?

    /// Debounce timer for search-as-you-type.
    @State private var debounceTask: Task<Void, Never>?

    enum SearchMode: String, CaseIterable {
        case text = "Text"
        case number = "Number"
    }

    init() {
        _searchText = State(initialValue: "")
    }

    init(initialSearchText: String) {
        _searchText = State(initialValue: initialSearchText)
        _debouncedQuery = State(initialValue: initialSearchText)
    }

    /// Fuzzy-scored search results from the indexed song store.
    private var searchResults: [Song] {
        guard !debouncedQuery.isEmpty else { return [] }
        if let songbook = selectedSongbook {
            // Filter results to selected songbook
            return songStore.searchSongsLocally(query: debouncedQuery)
                .filter { $0.songbook == songbook }
        }
        return songStore.searchSongsLocally(query: debouncedQuery)
    }

    /// Number lookup results within the selected songbook.
    private var numberResults: [Song] {
        guard !numberInput.isEmpty else { return [] }
        let songbook = selectedSongbook ?? songStore.preferences.defaultSongbook ?? ""
        guard !songbook.isEmpty else {
            // Search across all songbooks by number prefix
            guard let songs = songStore.songData?.songs else { return [] }
            return songs.filter { String($0.number).hasPrefix(numberInput) }
                .sorted { $0.songbook < $1.songbook == true ? true : $0.number < $1.number }
        }
        return songStore.searchByNumber(numberInput, songbook: songbook)
    }

    var body: some View {
        Group {
            switch searchMode {
            case .text:
                textSearchBody
            case .number:
                numberLookupBody
            }
        }
        .navigationTitle("Search")
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.large)
        .toolbar {
            ToolbarItem(placement: .automatic) {
                Button {
                    showingRandomSong = true
                    Task {
                        randomSong = await songStore.fetchRandomSong(songbook: selectedSongbook)
                    }
                } label: {
                    Label("Random Song", systemImage: "shuffle")
                }
            }
        }
        #endif
        .onChange(of: searchText) { _, newValue in
            debounceSearch(newValue)
        }
        .sheet(isPresented: $showingRandomSong) {
            if let song = randomSong {
                NavigationStack {
                    SongDetailView(song: song)
                        .environmentObject(songStore)
                        .toolbar {
                            ToolbarItem(placement: .cancellationAction) {
                                Button("Done") { showingRandomSong = false }
                            }
                            ToolbarItem(placement: .primaryAction) {
                                Button {
                                    Task { randomSong = await songStore.fetchRandomSong(songbook: selectedSongbook) }
                                } label: {
                                    Label("Shuffle Again", systemImage: "shuffle")
                                }
                            }
                        }
                }
            } else {
                ProgressView("Finding a song...")
            }
        }
    }

    // MARK: - Debounce

    private func debounceSearch(_ query: String) {
        debounceTask?.cancel()
        debounceTask = Task {
            try? await Task.sleep(for: .milliseconds(300))
            guard !Task.isCancelled else { return }
            await MainActor.run {
                debouncedQuery = query
            }
        }
    }

    // MARK: - Text Search Body

    private var textSearchBody: some View {
        Group {
            if searchText.isEmpty {
                searchIdleState
            } else if searchResults.isEmpty && !debouncedQuery.isEmpty {
                ContentUnavailableView.search(text: searchText)
            } else if !debouncedQuery.isEmpty {
                resultsList
            } else {
                // Typing but debounce hasn't fired yet
                ProgressView()
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .searchable(text: $searchText, prompt: "Title, lyrics, number, writer...")
        .onSubmit(of: .search) {
            debouncedQuery = searchText
            songStore.recordSearch(searchText)
        }
    }

    // MARK: - Number Lookup Body

    private var numberLookupBody: some View {
        VStack(spacing: 0) {
            // Songbook selector
            songbookPicker

            // Number display
            HStack {
                Text(numberInput.isEmpty ? "Enter song number" : numberInput)
                    .font(.system(size: 32, weight: .bold, design: .monospaced))
                    .foregroundStyle(numberInput.isEmpty ? .secondary : .primary)
                    .frame(maxWidth: .infinity)
                    .padding()
                    .liquidGlass(.thin, tint: AmberTheme.accent)
            }
            .padding(.horizontal)
            .padding(.top, Spacing.md)

            // Results
            if !numberResults.isEmpty {
                List {
                    ForEach(numberResults.prefix(20), id: \.id) { song in
                        NavigationLink(destination: SongDetailView(song: song)) {
                            SongRow(song: song, songStore: songStore)
                        }
                    }
                }
                .listStyle(.plain)
            } else if !numberInput.isEmpty {
                ContentUnavailableView(
                    "No Match",
                    systemImage: "number",
                    description: Text("No song starting with \"\(numberInput)\" in the selected songbook.")
                )
            }

            Spacer()

            // Numpad
            numpadGrid
                .padding(.horizontal, Spacing.xl)
                .padding(.bottom, Spacing.lg)
        }
    }

    // MARK: - Numpad Grid

    private var numpadGrid: some View {
        let buttons = [
            ["1", "2", "3"],
            ["4", "5", "6"],
            ["7", "8", "9"],
            ["C", "0", "Go"]
        ]

        return VStack(spacing: Spacing.sm) {
            ForEach(buttons, id: \.self) { row in
                HStack(spacing: Spacing.sm) {
                    ForEach(row, id: \.self) { key in
                        Button {
                            handleNumpadKey(key)
                        } label: {
                            Text(key)
                                .font(.title2.weight(.semibold))
                                .frame(maxWidth: .infinity)
                                .frame(height: 56)
                                .foregroundStyle(
                                    key == "Go" ? .white :
                                    key == "C" ? AmberTheme.accent : .primary
                                )
                                .background(
                                    key == "Go" ? AnyShapeStyle(AmberTheme.accent) :
                                    AnyShapeStyle(.regularMaterial)
                                )
                                .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
    }

    private func handleNumpadKey(_ key: String) {
        switch key {
        case "C":
            if numberInput.isEmpty { return }
            numberInput.removeLast()
        case "Go":
            if let first = numberResults.first {
                // Navigate to first result — handled by the list
                HapticManager.mediumImpact()
                _ = first
            }
        default:
            if numberInput.count < 5 {
                numberInput.append(key)
                HapticManager.selectionChanged()
            }
        }
    }

    // MARK: - Songbook Picker

    private var songbookPicker: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: Spacing.sm) {
                // Search mode toggle
                Picker("Mode", selection: $searchMode) {
                    ForEach(SearchMode.allCases, id: \.self) { mode in
                        Text(mode.rawValue).tag(mode)
                    }
                }
                .pickerStyle(.segmented)
                .frame(width: 150)

                Divider()
                    .frame(height: 24)

                // All songbooks chip
                songbookChip(id: nil, label: "All")

                // Individual songbook chips
                if let songbooks = songStore.songData?.songbooks {
                    ForEach(songbooks, id: \.id) { songbook in
                        songbookChip(id: songbook.id, label: songbook.id)
                    }
                }
            }
            .padding(.horizontal)
            .padding(.vertical, Spacing.sm)
        }
    }

    private func songbookChip(id: String?, label: String) -> some View {
        let isSelected = selectedSongbook == id
        return Button {
            selectedSongbook = id
            HapticManager.selectionChanged()
        } label: {
            Text(label)
                .font(.caption.bold())
                .foregroundStyle(isSelected ? .white : .primary)
                .padding(.horizontal, 12)
                .padding(.vertical, 6)
                .background(
                    isSelected
                    ? AnyShapeStyle(id.map { AmberTheme.songbookColor($0) } ?? AmberTheme.accent)
                    : AnyShapeStyle(.regularMaterial)
                )
                .clipShape(Capsule())
        }
        .buttonStyle(.plain)
    }

    // MARK: - Idle State

    private var searchIdleState: some View {
        ScrollView {
            VStack(spacing: Spacing.xl) {
                // Mode toggle + songbook filter
                songbookPicker

                // Search prompt
                VStack(spacing: Spacing.sm) {
                    Image(systemName: searchMode == .text ? "magnifyingglass" : "number")
                        .font(.system(size: 48, weight: .light))
                        .foregroundStyle(AmberTheme.light.opacity(0.6))

                    Text(searchMode == .text ? "Search Hymns" : "Number Lookup")
                        .font(Typography.sectionHeader)

                    Text(searchMode == .text
                         ? "Search across all songbooks by title, lyrics, song number, writer or composer."
                         : "Enter a song number to find it quickly.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                }
                .padding(.top, Spacing.lg)

                // Search history
                if searchMode == .text && !songStore.searchHistory.isEmpty {
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

                        FlowLayout(spacing: 8) {
                            ForEach(songStore.searchHistory) { entry in
                                Button {
                                    searchText = entry.query
                                    debouncedQuery = entry.query
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

                // Song of the Day
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
                Text("\(searchResults.count) result\(searchResults.count == 1 ? "" : "s") for \"\(debouncedQuery)\"")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .listRowBackground(Color.clear)
            }

            // Songbook filter bar
            Section {
                songbookPicker
                    .listRowInsets(EdgeInsets())
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

            if songStore.isFavorite(song.id) {
                Image(systemName: "star.fill")
                    .foregroundStyle(AmberTheme.light)
                    .imageScale(.small)
            }
        }
        .padding(.vertical, 4)
    }
}

// MARK: - FlowLayout
/// A simple flow layout that wraps items to the next line.
struct FlowLayout: Layout {
    var spacing: CGFloat = 8

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        layout(proposal: proposal, subviews: subviews).size
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        let result = layout(proposal: proposal, subviews: subviews)
        for (index, subview) in subviews.enumerated() {
            guard index < result.positions.count else { break }
            subview.place(
                at: CGPoint(x: bounds.minX + result.positions[index].x, y: bounds.minY + result.positions[index].y),
                proposal: .unspecified
            )
        }
    }

    private func layout(proposal: ProposedViewSize, subviews: Subviews) -> (size: CGSize, positions: [CGPoint]) {
        let maxWidth = proposal.width ?? .infinity
        var positions: [CGPoint] = []
        var x: CGFloat = 0, y: CGFloat = 0, rowHeight: CGFloat = 0, maxX: CGFloat = 0

        for subview in subviews {
            let size = subview.sizeThatFits(.unspecified)
            if x + size.width > maxWidth && x > 0 {
                x = 0; y += rowHeight + spacing; rowHeight = 0
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

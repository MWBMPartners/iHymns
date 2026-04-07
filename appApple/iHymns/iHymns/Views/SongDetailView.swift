// SongDetailView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - SongDetailView
/// Presents the full lyrics and metadata for a single song using
/// Liquid Glass design language for visual depth and translucency.
///
/// Platform adaptations:
/// - **iOS/iPadOS/macOS/visionOS**: Full layout with glass header, credits, toolbar
/// - **tvOS**: Extra-large fonts for congregational reading from a distance
/// - **watchOS**: Compact lyrics-only layout for the small screen
struct SongDetailView: View {

    // MARK: Properties
    let song: Song

    // MARK: Environment
    @EnvironmentObject var songStore: SongStore

    // MARK: State
    @State private var showingAddToSetList = false
    @State private var showingPresentation = false
    @State private var showingCompare = false
    @State private var scrollProgress: CGFloat = 0
    @State private var swipeTargetSong: Song?
    @State private var showSwipeTarget = false

    /// Scaled font size based on user preferences.
    private var scaledLyricsFont: Font {
        let baseSize: CGFloat = 18
        let scaled = baseSize * songStore.preferences.lyricsFontScale
        return .system(size: scaled)
    }

    /// Related songs based on shared writers/composers/songbook.
    private var relatedSongs: [Song] {
        guard let songs = songStore.songData?.songs else { return [] }
        let writerSet = Set(song.writers)
        let composerSet = Set(song.composers)

        var scored: [(Song, Int)] = []
        for candidate in songs where candidate.id != song.id {
            var score = 0
            // Shared writers (highest signal)
            if !writerSet.isEmpty && !Set(candidate.writers).isDisjoint(with: writerSet) { score += 3 }
            // Shared composers
            if !composerSet.isEmpty && !Set(candidate.composers).isDisjoint(with: composerSet) { score += 2 }
            // Same songbook proximity
            if candidate.songbook == song.songbook { score += 1 }
            if score > 0 { scored.append((candidate, score)) }
        }

        return scored
            .sorted { $0.1 > $1.1 }
            .prefix(8)
            .map(\.0)
    }

    // MARK: Body
    var body: some View {
        ZStack(alignment: .top) {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    #if os(watchOS)
                    watchOSContent
                    #elseif os(tvOS)
                    tvOSContent
                    #else
                    standardContent
                    #endif
                }
                .background(
                    GeometryReader { geo in
                        Color.clear.preference(
                            key: ScrollOffsetKey.self,
                            value: -geo.frame(in: .named("scroll")).origin.y
                        )
                    }
                )
            }
            .coordinateSpace(name: "scroll")
            .onPreferenceChange(ScrollOffsetKey.self) { offset in
                // Estimate total height based on component count
                let estimatedHeight = CGFloat(song.components.count * 200)
                scrollProgress = min(1.0, max(0, offset / max(estimatedHeight, 1)))
            }

            // Reading progress bar
            #if !os(watchOS) && !os(tvOS)
            if song.components.count > 2 {
                GeometryReader { geo in
                    Rectangle()
                        .fill(AmberTheme.songbookColor(song.songbook))
                        .frame(width: geo.size.width * scrollProgress, height: 3)
                        .animation(.linear(duration: 0.1), value: scrollProgress)
                }
                .frame(height: 3)
            }
            #endif
        }
        #if !os(watchOS) && !os(tvOS)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar { toolbarActions }
        #endif
        .navigationTitle(song.title)
        .onAppear {
            songStore.recordSongView(song)
        }
        .sheet(isPresented: $showingAddToSetList) {
            AddSongToSetListPicker(songId: song.id)
                .environmentObject(songStore)
        }
        // Swipe gesture for navigating between songs within the same songbook
        #if !os(watchOS) && !os(tvOS)
        .gesture(
            DragGesture(minimumDistance: 50, coordinateSpace: .local)
                .onEnded { value in
                    guard abs(value.translation.width) > abs(value.translation.height) else { return }
                    let velocity = abs(value.predictedEndTranslation.width / max(abs(value.translation.width), 1))
                    guard velocity > 0.3 else { return }

                    let songs = songStore.songsForSongbook(song.songbook)
                    guard let currentIndex = songs.firstIndex(where: { $0.id == song.id }) else { return }

                    if value.translation.width < 0 && currentIndex < songs.count - 1 {
                        // Swipe left = next song
                        swipeTargetSong = songs[currentIndex + 1]
                        showSwipeTarget = true
                    } else if value.translation.width > 0 && currentIndex > 0 {
                        // Swipe right = previous song
                        swipeTargetSong = songs[currentIndex - 1]
                        showSwipeTarget = true
                    }
                }
        )
        .navigationDestination(isPresented: $showSwipeTarget) {
            if let target = swipeTargetSong {
                SongDetailView(song: target)
                    .environmentObject(songStore)
            }
        }
        #endif
    }

    // MARK: - Standard Content (iOS / iPadOS / macOS / visionOS)
    #if !os(watchOS) && !os(tvOS)
    @ViewBuilder
    private var standardContent: some View {
        // Liquid Glass song header
        songHeader

        // Audio player (if available)
        if song.hasAudio {
            AudioPlayerView(song: song)
                .padding(.horizontal, 20)
                .padding(.top, 16)
        }

        // Transpose controls
        TransposeControlView(songId: song.id)
            .padding(.horizontal, 20)
            .padding(.top, song.hasAudio ? 8 : 16)

        // Lyric components in arrangement order, with scaled font
        VStack(alignment: .leading, spacing: 24) {
            ForEach(song.arrangedComponents, id: \.offset) { _, component in
                componentView(for: component)
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 24)

        // Credits section
        creditsSection
            .padding(.horizontal, 20)
            .padding(.top, 32)

        // Related songs section
        if !relatedSongs.isEmpty {
            relatedSongsSection
                .padding(.horizontal, 20)
                .padding(.top, 24)
        }

        Spacer()
            .frame(height: 40)
    }
    #endif

    // MARK: - tvOS Content
    #if os(tvOS)
    @ViewBuilder
    private var tvOSContent: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text("\(song.number). \(song.title)")
                .font(.system(size: 52, weight: .bold))
                .foregroundStyle(AmberTheme.accent)
                .padding(.bottom, 8)

            Text(song.songbookName)
                .font(.system(size: 28))
                .foregroundStyle(.secondary)
        }
        .padding(.horizontal, 60)
        .padding(.top, 40)

        VStack(alignment: .leading, spacing: 40) {
            ForEach(Array(song.components.enumerated()), id: \.offset) { _, component in
                tvOSComponentView(for: component)
            }
        }
        .padding(.horizontal, 60)
        .padding(.top, 40)
        .padding(.bottom, 60)
    }

    private func tvOSComponentView(for component: SongComponent) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(componentLabel(for: component))
                .font(.system(size: 30, weight: .semibold))
                .foregroundStyle(AmberTheme.accent)

            Text(component.lines.joined(separator: "\n"))
                .font(.system(size: 38))
                .lineSpacing(10)
        }
        .padding(.leading, isChorusType(component) ? 40 : 0)
        .overlay(alignment: .leading) {
            if isChorusType(component) {
                AmberTheme.accent
                    .frame(width: 6)
                    .clipShape(RoundedRectangle(cornerRadius: 3))
            }
        }
    }
    #endif

    // MARK: - watchOS Content
    #if os(watchOS)
    @ViewBuilder
    private var watchOSContent: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("#\(song.number)")
                .font(.caption2.bold())
                .foregroundStyle(AmberTheme.accent)
            Text(song.title)
                .font(.headline)
        }
        .padding(.horizontal, 8)
        .padding(.top, 8)

        VStack(alignment: .leading, spacing: 16) {
            ForEach(Array(song.components.enumerated()), id: \.offset) { _, component in
                VStack(alignment: .leading, spacing: 2) {
                    Text(componentLabel(for: component))
                        .font(.caption2.bold())
                        .foregroundStyle(AmberTheme.accent)

                    Text(component.lines.joined(separator: "\n"))
                        .font(.caption)
                        .lineSpacing(2)
                }
                .padding(.leading, isChorusType(component) ? 8 : 0)
            }
        }
        .padding(.horizontal, 8)
        .padding(.top, 12)
        .padding(.bottom, 16)

        Button {
            songStore.toggleFavorite(song.id)
        } label: {
            Label(
                songStore.isFavorite(song.id) ? "Unfavourite" : "Favourite",
                systemImage: songStore.isFavorite(song.id) ? "star.fill" : "star"
            )
        }
        .padding(.horizontal, 8)
        .padding(.bottom, 12)
    }
    #endif

    // MARK: - Song Header (Liquid Glass)
    #if !os(watchOS) && !os(tvOS)
    private var songHeader: some View {
        ZStack(alignment: .bottomLeading) {
            // Gradient background
            LinearGradient(
                colors: [AmberTheme.accent, AmberTheme.light],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            )
            .frame(minHeight: 100)

            // Content overlaid on gradient
            VStack(alignment: .leading, spacing: 6) {
                // Songbook badge with glass effect
                HStack(spacing: 8) {
                    Text(song.songbook)
                        .font(.caption.bold())
                        .foregroundStyle(.white)
                        .padding(.horizontal, 10)
                        .padding(.vertical, 4)
                        .background(
                            Capsule()
                                .fill(.ultraThinMaterial)
                        )

                    Text(song.songbookName)
                        .font(.caption.bold())
                        .foregroundStyle(.white.opacity(0.85))
                        .textCase(.uppercase)
                }

                // Song number and title
                Text("#\(song.number) — \(song.title)")
                    .font(.title2.bold())
                    .foregroundStyle(.white)

                // Media availability badges
                HStack(spacing: 8) {
                    if song.hasAudio {
                        mediaBadge(icon: "music.note", label: "Audio")
                    }
                    if song.hasSheetMusic {
                        mediaBadge(icon: "doc.richtext", label: "Sheet Music")
                    }
                }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 16)
            .padding(.top, 16)
        }
    }

    private func mediaBadge(icon: String, label: String) -> some View {
        HStack(spacing: 4) {
            Image(systemName: icon)
            Text(label)
        }
        .font(.caption2)
        .foregroundStyle(.white.opacity(0.8))
        .padding(.horizontal, 8)
        .padding(.vertical, 3)
        .background(
            Capsule()
                .fill(.white.opacity(0.2))
        )
    }
    #endif

    // MARK: - Component View
    private func componentView(for component: SongComponent) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            // Verse/chorus label — hidden when user toggles off
            if songStore.preferences.showComponentLabels {
                Text(componentLabel(for: component))
                    .font(Typography.componentLabel)
                    .foregroundStyle(AmberTheme.accent)
            }

            // Lyrics with user-scaled font size
            Text(component.lines.joined(separator: "\n"))
                .font(scaledLyricsFont)
                .lineSpacing(songStore.preferences.lineSpacing.value)
        }
        .padding(.leading, isChorusType(component) ? 20 : 0)
        .overlay(alignment: .leading) {
            if isChorusType(component) && songStore.preferences.highlightChorus {
                AmberTheme.accent
                    .frame(width: 4)
                    .clipShape(RoundedRectangle(cornerRadius: 2))
            }
        }
    }

    // MARK: - Component Label
    private func componentLabel(for component: SongComponent) -> String {
        switch component.type {
        case "verse":
            if let number = component.number { return "Verse \(number)" }
            return "Verse"
        case "chorus":     return "Chorus"
        case "refrain":    return "Refrain"
        case "bridge":     return "Bridge"
        case "pre-chorus": return "Pre-Chorus"
        case "tag":        return "Tag"
        case "coda":       return "Coda"
        default:           return component.type.capitalized
        }
    }

    // MARK: - Credits Section
    #if !os(watchOS) && !os(tvOS)
    private var creditsSection: some View {
        VStack(alignment: .leading, spacing: 10) {
            Divider()
                .padding(.bottom, 8)

            if !song.writers.isEmpty {
                tappableCreditRow(label: "Words", names: song.writers)
            }

            if !song.composers.isEmpty {
                tappableCreditRow(label: "Music", names: song.composers)
            }

            if !song.copyright.isEmpty {
                creditRow(label: "Copyright", value: song.copyright)
            }

            if !song.ccli.isEmpty {
                creditRow(label: "CCLI", value: song.ccli)
            }
        }
        .padding()
        .liquidGlass(.thin, tint: AmberTheme.wash)
    }

    private func creditRow(label: String, value: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(label)
                .font(Typography.componentLabel)
                .foregroundStyle(AmberTheme.accent)
            Text(value)
                .font(Typography.metadata)
                .foregroundStyle(.secondary)
        }
    }

    /// Credit row where each name is tappable and navigates to the writer's page.
    private func tappableCreditRow(label: String, names: [String]) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(label)
                .font(Typography.componentLabel)
                .foregroundStyle(AmberTheme.accent)

            FlowLayout(spacing: 4) {
                ForEach(names, id: \.self) { name in
                    NavigationLink(destination: WriterDetailView(writerName: name)) {
                        Text(name)
                            .font(Typography.metadata)
                            .foregroundStyle(AmberTheme.accent.opacity(0.8))
                            .underline()
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }
    #endif

    // MARK: - Toolbar Actions
    #if !os(watchOS) && !os(tvOS)
    @ToolbarContentBuilder
    private var toolbarActions: some ToolbarContent {
        ToolbarItem(placement: .automatic) {
            Button {
                songStore.toggleFavorite(song.id)
            } label: {
                Image(systemName: songStore.isFavorite(song.id) ? "star.fill" : "star")
                    .foregroundStyle(songStore.isFavorite(song.id) ? AmberTheme.light : .secondary)
            }
            .accessibilityLabel(songStore.isFavorite(song.id) ? "Remove from favourites" : "Add to favourites")
        }

        ToolbarItem(placement: .automatic) {
            Button {
                showingAddToSetList = true
            } label: {
                Label("Add to Set List", systemImage: "list.bullet.rectangle")
            }
        }

        if song.hasSheetMusic {
            ToolbarItem(placement: .automatic) {
                NavigationLink(destination: SheetMusicView(song: song)) {
                    Label("Sheet Music", systemImage: "doc.richtext")
                }
            }
        }

        ToolbarItem(placement: .automatic) {
            NavigationLink(destination: CompareView(songA: song)) {
                Label("Compare", systemImage: "rectangle.on.rectangle")
            }
        }

        ToolbarItem(placement: .automatic) {
            ShareLink(
                item: sharableText,
                subject: Text(song.title),
                message: Text("Shared from iHymns")
            ) {
                Label("Share", systemImage: "square.and.arrow.up")
            }
        }

        #if os(macOS)
        ToolbarItem(placement: .automatic) {
            Button {
                printSong()
            } label: {
                Label("Print", systemImage: "printer")
            }
        }
        #endif
    }
    #endif

    // MARK: - Helpers

    private func isChorusType(_ component: SongComponent) -> Bool {
        component.type == "chorus" || component.type == "refrain"
    }

    private var sharableText: String {
        var lines: [String] = []
        lines.append("\(song.title) (#\(song.number))")
        lines.append(song.songbookName)
        lines.append("")

        for component in song.components {
            lines.append(componentLabel(for: component))
            lines.append(contentsOf: component.lines)
            lines.append("")
        }

        if !song.writers.isEmpty {
            lines.append("Words: \(song.writers.joined(separator: ", "))")
        }
        if !song.composers.isEmpty {
            lines.append("Music: \(song.composers.joined(separator: ", "))")
        }
        lines.append("\nShared from iHymns — ihymns.app")
        return lines.joined(separator: "\n")
    }

    // MARK: - Related Songs
    #if !os(watchOS) && !os(tvOS)
    private var relatedSongsSection: some View {
        VStack(alignment: .leading, spacing: Spacing.md) {
            Text("Related Songs")
                .font(Typography.sectionHeader)
                .foregroundStyle(AmberTheme.accent)

            ForEach(relatedSongs.prefix(5), id: \.id) { related in
                NavigationLink(destination: SongDetailView(song: related)) {
                    HStack(spacing: Spacing.md) {
                        Text("\(related.number)")
                            .font(.caption.bold().monospacedDigit())
                            .foregroundStyle(.white)
                            .frame(width: 32, height: 32)
                            .background(
                                AmberTheme.songbookColor(related.songbook),
                                in: RoundedRectangle(cornerRadius: 6)
                            )

                        VStack(alignment: .leading, spacing: 2) {
                            Text(related.title)
                                .font(.subheadline)
                                .lineLimit(1)
                            Text(related.songbookName)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }

                        Spacer()

                        Image(systemName: "chevron.right")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    .padding(.vertical, 4)
                }
                .buttonStyle(.plain)
            }
        }
        .padding()
        .liquidGlass(.thin, tint: AmberTheme.wash)
    }
    #endif

    #if os(macOS)
    private func printSong() {
        let printInfo = NSPrintInfo.shared
        printInfo.horizontalPagination = .fit
        printInfo.verticalPagination = .automatic

        let textView = NSTextView(frame: NSRect(x: 0, y: 0, width: 468, height: 0))
        textView.string = sharableText
        textView.font = NSFont.systemFont(ofSize: 12)
        textView.sizeToFit()

        let printOperation = NSPrintOperation(view: textView, printInfo: printInfo)
        printOperation.showsPrintPanel = true
        printOperation.showsProgressPanel = true
        printOperation.run()
    }
    #endif
}

// MARK: - ScrollOffsetKey
/// Preference key for tracking scroll offset to drive the reading progress bar.
private struct ScrollOffsetKey: PreferenceKey {
    static var defaultValue: CGFloat = 0
    static func reduce(value: inout CGFloat, nextValue: () -> CGFloat) {
        value = nextValue()
    }
}

// MARK: - AddSongToSetListPicker

/// Quick picker for adding a song to an existing set list.
struct AddSongToSetListPicker: View {

    let songId: String
    @EnvironmentObject var songStore: SongStore
    @Environment(\.dismiss) private var dismiss

    @State private var newSetListName = ""
    @State private var showingNewSetList = false

    var body: some View {
        NavigationStack {
            List {
                if songStore.setLists.isEmpty {
                    ContentUnavailableView {
                        Label("No Set Lists", systemImage: "list.bullet.rectangle")
                    } description: {
                        Text("Create a set list first, then add songs to it.")
                    }
                } else {
                    ForEach(songStore.setLists) { setList in
                        Button {
                            songStore.addSongToSetList(songId, setListId: setList.id)
                            HapticManager.success()
                            dismiss()
                        } label: {
                            HStack {
                                VStack(alignment: .leading) {
                                    Text(setList.name)
                                        .font(.body)
                                    Text("\(setList.songIds.count) songs")
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

                Section {
                    Button {
                        showingNewSetList = true
                    } label: {
                        Label("Create New Set List", systemImage: "plus")
                    }
                }
            }
            .navigationTitle("Add to Set List")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
            .alert("New Set List", isPresented: $showingNewSetList) {
                TextField("Set list name", text: $newSetListName)
                Button("Create & Add") {
                    let name = newSetListName.trimmingCharacters(in: .whitespacesAndNewlines)
                    if !name.isEmpty {
                        let setList = songStore.createSetList(name: name)
                        songStore.addSongToSetList(songId, setListId: setList.id)
                        HapticManager.success()
                        dismiss()
                    }
                    newSetListName = ""
                }
                Button("Cancel", role: .cancel) { newSetListName = "" }
            }
        }
    }
}

// MARK: - Preview
#if DEBUG
#Preview {
    NavigationStack {
        SongDetailView(
            song: Song(
                id: "CP-0001",
                number: 1,
                title: "A baby was born in Bethlehem",
                songbook: "CP",
                songbookName: "Carol Praise",
                writers: ["Ivor Golby"],
                composers: ["Noël Tredinnick"],
                copyright: "A & C Black Limited",
                ccli: "",
                hasAudio: true,
                hasSheetMusic: true,
                components: [
                    SongComponent(type: "verse", number: 1, lines: [
                        "A baby was born in Bethlehem,",
                        "a baby was born in Bethlehem,",
                        "a baby was born in Bethlehem –",
                        "it was Jesus Christ, our Lord."
                    ]),
                    SongComponent(type: "chorus", number: nil, lines: [
                        "Gloria, gloria in excelcis Deo;",
                        "Gloria, gloria, sing glory to God on high!"
                    ])
                ]
            )
        )
        .environmentObject(SongStore())
    }
}
#endif

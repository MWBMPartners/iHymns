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

    // MARK: Body
    var body: some View {
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
    }

    // MARK: - Standard Content (iOS / iPadOS / macOS / visionOS)
    #if !os(watchOS) && !os(tvOS)
    @ViewBuilder
    private var standardContent: some View {
        // Liquid Glass song header
        songHeader

        // Lyric components with glass accents
        VStack(alignment: .leading, spacing: 24) {
            ForEach(Array(song.components.enumerated()), id: \.offset) { _, component in
                componentView(for: component)
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 24)

        // Credits section
        creditsSection
            .padding(.horizontal, 20)
            .padding(.top, 32)
            .padding(.bottom, 40)
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
            Text(componentLabel(for: component))
                .font(Typography.componentLabel)
                .foregroundStyle(AmberTheme.accent)

            Text(component.lines.joined(separator: "\n"))
                .font(.body)
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
                creditRow(label: "Words", value: song.writers.joined(separator: ", "))
            }

            if !song.composers.isEmpty {
                creditRow(label: "Music", value: song.composers.joined(separator: ", "))
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

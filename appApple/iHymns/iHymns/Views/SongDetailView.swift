// SongDetailView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - SongDetailView
/// Presents the full lyrics and metadata for a single song.
///
/// The view is structured as a scrollable layout containing:
/// 1. A title header with song number, title and songbook name.
/// 2. Each lyric component (verses, choruses) rendered sequentially.
/// 3. A credits section showing writers, composers and copyright.
/// 4. Action buttons for favouriting, sharing and (on macOS) printing.
///
/// Platform adaptations:
/// - **tvOS**: Font sizes are significantly increased so lyrics are legible
///   from across a room for congregational singing.
/// - **watchOS**: A minimal layout showing only the lyrics text to maximise
///   the usable area on a small screen.
struct SongDetailView: View {

    // MARK: Properties
    /// The song model whose lyrics and metadata are displayed.
    let song: Song

    // MARK: Environment
    /// The shared song store, used to toggle favourite status.
    @EnvironmentObject var songStore: SongStore

    // MARK: Body
    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {

                #if os(watchOS)
                // ── watchOS: Compact lyrics-only layout ──────────────────
                // On watchOS we skip the decorative header and metadata to
                // preserve precious screen space. Only lyrics are shown.
                watchOSContent

                #elseif os(tvOS)
                // ── tvOS: Large congregational display ───────────────────
                // Everything is rendered at increased font sizes so the
                // lyrics can be read from a distance on a television.
                tvOSContent

                #else
                // ── iOS / iPadOS / macOS / visionOS ─────────────────────
                standardContent
                #endif
            }
        }
        #if !os(watchOS) && !os(tvOS)
        .navigationBarTitleDisplayMode(.inline)
        // Toolbar with action buttons (favourite, share, print)
        .toolbar { toolbarActions }
        #endif
        .navigationTitle(song.title)
    }

    // MARK: - Standard Content (iOS / iPadOS / macOS / visionOS)
    /// The full-featured layout for phones, tablets, desktops and spatial computing.
    #if !os(watchOS) && !os(tvOS)
    @ViewBuilder
    private var standardContent: some View {
        // ── Song Header ──────────────────────────────────────────────────
        // An amber gradient banner displaying the song number, title and
        // the songbook it belongs to.
        songHeader

        // ── Lyric Components ─────────────────────────────────────────────
        // Iterate over each component (verse / chorus) and render it with
        // appropriate styling. Choruses receive indentation and a leading
        // amber accent bar to visually distinguish them from verses.
        VStack(alignment: .leading, spacing: 24) {
            ForEach(Array(song.components.enumerated()), id: \.offset) { _, component in
                componentView(for: component)
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 24)

        // ── Credits Section ──────────────────────────────────────────────
        // Displays writer, composer and copyright information at the bottom.
        creditsSection
            .padding(.horizontal, 20)
            .padding(.top, 32)
            .padding(.bottom, 40)
    }
    #endif

    // MARK: - tvOS Content
    /// A large-text layout optimised for television screens and congregational use.
    #if os(tvOS)
    @ViewBuilder
    private var tvOSContent: some View {
        // Song title in extra-large bold text
        VStack(alignment: .leading, spacing: 16) {
            Text("\(song.number). \(song.title)")
                .font(.system(size: 52, weight: .bold))
                .foregroundStyle(AmberTheme.accent)
                .padding(.bottom, 8)

            // Songbook name
            Text(song.songbookName)
                .font(.system(size: 28))
                .foregroundStyle(.secondary)
        }
        .padding(.horizontal, 60)
        .padding(.top, 40)

        // Lyric components at TV-friendly sizes
        VStack(alignment: .leading, spacing: 40) {
            ForEach(Array(song.components.enumerated()), id: \.offset) { _, component in
                tvOSComponentView(for: component)
            }
        }
        .padding(.horizontal, 60)
        .padding(.top, 40)
        .padding(.bottom, 60)
    }

    /// Renders a single lyric component (verse or chorus) with TV-sized fonts.
    /// Choruses are indented and have a prominent leading accent bar.
    private func tvOSComponentView(for component: SongComponent) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            // Component label (e.g. "Verse 1" or "Chorus")
            Text(componentLabel(for: component))
                .font(.system(size: 30, weight: .semibold))
                .foregroundStyle(AmberTheme.accent)

            // Lyric lines joined as a single text block
            Text(component.lines.joined(separator: "\n"))
                .font(.system(size: 38))
                .lineSpacing(10)
        }
        .padding(.leading, component.type == "chorus" ? 40 : 0)
        // Leading amber bar for choruses
        .overlay(alignment: .leading) {
            if component.type == "chorus" {
                AmberTheme.accent
                    .frame(width: 6)
                    .clipShape(RoundedRectangle(cornerRadius: 3))
            }
        }
    }
    #endif

    // MARK: - watchOS Content
    /// A compact lyrics-only layout for watchOS.
    #if os(watchOS)
    @ViewBuilder
    private var watchOSContent: some View {
        // Song number and title in a compact header
        VStack(alignment: .leading, spacing: 4) {
            Text("#\(song.number)")
                .font(.caption2.bold())
                .foregroundStyle(AmberTheme.accent)
            Text(song.title)
                .font(.headline)
        }
        .padding(.horizontal, 8)
        .padding(.top, 8)

        // Lyric components rendered compactly
        VStack(alignment: .leading, spacing: 16) {
            ForEach(Array(song.components.enumerated()), id: \.offset) { _, component in
                VStack(alignment: .leading, spacing: 2) {
                    // Component label
                    Text(componentLabel(for: component))
                        .font(.caption2.bold())
                        .foregroundStyle(AmberTheme.accent)

                    // Lyric lines
                    Text(component.lines.joined(separator: "\n"))
                        .font(.caption)
                        .lineSpacing(2)
                }
                // Indent choruses slightly
                .padding(.leading, component.type == "chorus" ? 8 : 0)
            }
        }
        .padding(.horizontal, 8)
        .padding(.top, 12)
        .padding(.bottom, 16)

        // Favourite toggle button for watchOS
        Button {
            songStore.toggleFavorite(songId: song.id)
        } label: {
            Label(
                songStore.isFavorite(songId: song.id) ? "Unfavourite" : "Favourite",
                systemImage: songStore.isFavorite(songId: song.id) ? "star.fill" : "star"
            )
        }
        .padding(.horizontal, 8)
        .padding(.bottom, 12)
    }
    #endif

    // MARK: - Song Header
    /// An amber gradient banner displaying the song number, title and songbook.
    #if !os(watchOS) && !os(tvOS)
    private var songHeader: some View {
        ZStack(alignment: .bottomLeading) {
            // Gradient background matching the app's amber brand
            LinearGradient(
                colors: [AmberTheme.accent, AmberTheme.light],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            )
            .frame(minHeight: 100)

            // Overlaid text content
            VStack(alignment: .leading, spacing: 6) {
                // Songbook name label
                Text(song.songbookName)
                    .font(.caption.bold())
                    .foregroundStyle(.white.opacity(0.85))
                    .textCase(.uppercase)

                // Song number and title
                Text("#\(song.number) — \(song.title)")
                    .font(.title2.bold())
                    .foregroundStyle(.white)
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 16)
            .padding(.top, 16)
        }
    }
    #endif

    // MARK: - Component View (Standard)
    /// Renders a single lyric component (verse or chorus) for standard platforms.
    ///
    /// Verses are left-aligned with a label such as "Verse 1".
    /// Choruses are indented with an amber leading accent bar to visually separate
    /// them from verses, matching the web app's chorus styling.
    private func componentView(for component: SongComponent) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            // ── Component Label ──────────────────────────────────────────
            // e.g. "Verse 1", "Verse 2", "Chorus"
            Text(componentLabel(for: component))
                .font(.subheadline.bold())
                .foregroundStyle(AmberTheme.accent)

            // ── Lyric Lines ──────────────────────────────────────────────
            // All lines of the component joined with newlines.
            Text(component.lines.joined(separator: "\n"))
                .font(.body)
                .lineSpacing(4)
        }
        // Indent choruses and add a leading amber accent bar
        .padding(.leading, component.type == "chorus" ? 20 : 0)
        .overlay(alignment: .leading) {
            // A thin amber bar on the left edge of chorus blocks
            if component.type == "chorus" {
                AmberTheme.accent
                    .frame(width: 4)
                    .clipShape(RoundedRectangle(cornerRadius: 2))
            }
        }
    }

    // MARK: - Component Label Helper
    /// Generates a human-readable label for a lyric component.
    ///
    /// - Verses are labelled "Verse 1", "Verse 2", etc.
    /// - Choruses are labelled "Chorus" (or "Refrain").
    private func componentLabel(for component: SongComponent) -> String {
        switch component.type {
        case "verse":
            // Include the verse number if it is present and non-nil
            if let number = component.number {
                return "Verse \(number)"
            }
            return "Verse"
        case "chorus":
            return "Chorus"
        default:
            return component.type.capitalized
        }
    }

    // MARK: - Credits Section
    /// A section at the bottom of the lyrics showing writer, composer and
    /// copyright attribution.
    #if !os(watchOS) && !os(tvOS)
    private var creditsSection: some View {
        VStack(alignment: .leading, spacing: 10) {
            // Horizontal divider separating lyrics from credits
            Divider()
                .padding(.bottom, 8)

            // Writers (lyricists)
            if !song.writers.isEmpty {
                creditRow(label: "Words", value: song.writers.joined(separator: ", "))
            }

            // Composers
            if !song.composers.isEmpty {
                creditRow(label: "Music", value: song.composers.joined(separator: ", "))
            }

            // Copyright notice
            if !song.copyright.isEmpty {
                creditRow(label: "Copyright", value: song.copyright)
            }

            // CCLI number if available
            if !song.ccli.isEmpty {
                creditRow(label: "CCLI", value: song.ccli)
            }
        }
    }

    /// A single credit row with a bold label and a secondary-styled value.
    private func creditRow(label: String, value: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(label)
                .font(.caption.bold())
                .foregroundStyle(AmberTheme.accent)
            Text(value)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
    }
    #endif

    // MARK: - Toolbar Actions
    /// Toolbar buttons for favourite toggle, sharing and (macOS-only) printing.
    #if !os(watchOS) && !os(tvOS)
    @ToolbarContentBuilder
    private var toolbarActions: some ToolbarContent {
        // ── Favourite Toggle ─────────────────────────────────────────────
        // Tapping the star adds or removes the song from the user's favourites.
        ToolbarItem(placement: .automatic) {
            Button {
                songStore.toggleFavorite(songId: song.id)
            } label: {
                Image(systemName: songStore.isFavorite(songId: song.id) ? "star.fill" : "star")
                    .foregroundStyle(songStore.isFavorite(songId: song.id) ? AmberTheme.light : .secondary)
            }
            .accessibilityLabel(songStore.isFavorite(songId: song.id) ? "Remove from favourites" : "Add to favourites")
        }

        // ── Share Button ─────────────────────────────────────────────────
        // Uses ShareLink to share the song title and first verse text.
        ToolbarItem(placement: .automatic) {
            ShareLink(
                item: sharableText,
                subject: Text(song.title),
                message: Text("Shared from iHymns")
            ) {
                Label("Share", systemImage: "square.and.arrow.up")
            }
        }

        // ── Print Button (macOS only) ────────────────────────────────────
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

    // MARK: - Sharable Text
    /// Constructs a plain-text representation of the song suitable for sharing.
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

        return lines.joined(separator: "\n")
    }

    // MARK: - Print (macOS)
    /// Triggers the macOS print dialog for the current song. This is a simplified
    /// implementation that prints the sharable text.
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
                    ]),
                    SongComponent(type: "verse", number: 2, lines: [
                        "They laid him in a manger...",
                        "where the oxen feed on hay.",
                        "Gloria, gloria..."
                    ])
                ]
            )
        )
        .environmentObject(SongStore())
    }
}
#endif

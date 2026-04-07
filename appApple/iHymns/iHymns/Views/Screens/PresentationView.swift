// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  PresentationView.swift
//  iHymns
//
//  Full-screen presentation mode for displaying song lyrics during
//  worship services. Optimised for projection on tvOS and external
//  displays. Features:
//  - Large, readable lyrics with configurable font size
//  - Auto-scroll at adjustable speed
//  - Component-by-component navigation (verse/chorus stepping)
//  - Clean, distraction-free display with Liquid Glass controls
//  - Keyboard shortcuts for presentation control
//

import SwiftUI

// MARK: - PresentationView

struct PresentationView: View {

    @EnvironmentObject var songStore: SongStore

    @State private var selectedSongId: String?
    @State private var currentComponentIndex: Int = 0
    @State private var fontSize: CGFloat = 48
    @State private var isAutoScrolling: Bool = false
    @State private var scrollSpeed: Double = 30
    @State private var showControls: Bool = true

    var body: some View {
        ZStack {
            // Background
            Color.black
                .ignoresSafeArea()

            if let songId = selectedSongId,
               let song = songStore.song(byId: songId) {
                // Song display
                presentationContent(song: song)
            } else {
                // Song selection
                songPicker
            }

            // Floating controls
            if showControls {
                presentationControls
            }
        }
        .onTapGesture {
            withAnimation(.liquidGlassQuick) {
                showControls.toggle()
            }
        }
        #if !os(watchOS) && !os(tvOS)
        .onKeyPress(.space) {
            isAutoScrolling.toggle()
            return .handled
        }
        .onKeyPress(.leftArrow) {
            previousComponent()
            return .handled
        }
        .onKeyPress(.rightArrow) {
            nextComponent()
            return .handled
        }
        .onKeyPress(.escape) {
            selectedSongId = nil
            return .handled
        }
        #endif
    }

    // MARK: - Song Picker

    private var songPicker: some View {
        VStack(spacing: Spacing.xl) {
            Text("Presentation Mode")
                .font(.system(size: 36, weight: .bold))
                .foregroundStyle(.white)

            Text("Select a song or set list to begin")
                .font(.title3)
                .foregroundStyle(.white.opacity(0.7))

            // Show set lists if available
            if !songStore.setLists.isEmpty {
                VStack(alignment: .leading, spacing: Spacing.md) {
                    Text("SET LISTS")
                        .font(.caption.weight(.bold))
                        .foregroundStyle(AmberTheme.light)

                    ForEach(songStore.setLists) { setList in
                        Button {
                            if let firstSongId = setList.songIds.first {
                                selectedSongId = firstSongId
                            }
                        } label: {
                            HStack {
                                Image(systemName: "list.bullet.rectangle")
                                Text(setList.name)
                                Spacer()
                                Text("\(setList.songIds.count) songs")
                                    .foregroundStyle(.secondary)
                            }
                            .padding()
                            .liquidGlass(.regular, tint: AmberTheme.accent)
                        }
                        .buttonStyle(.plain)
                        .foregroundStyle(.white)
                    }
                }
                .frame(maxWidth: 500)
            }

            // Recent songs
            if !songStore.viewHistory.isEmpty {
                VStack(alignment: .leading, spacing: Spacing.md) {
                    Text("RECENTLY VIEWED")
                        .font(.caption.weight(.bold))
                        .foregroundStyle(AmberTheme.light)

                    ForEach(songStore.viewHistory.prefix(5)) { entry in
                        Button {
                            selectedSongId = entry.songId
                        } label: {
                            HStack {
                                Text(entry.songTitle)
                                Spacer()
                                Text(entry.songbook)
                                    .foregroundStyle(.secondary)
                            }
                            .padding()
                            .liquidGlass(.thin)
                        }
                        .buttonStyle(.plain)
                        .foregroundStyle(.white)
                    }
                }
                .frame(maxWidth: 500)
            }
        }
        .padding(Spacing.xxl)
    }

    // MARK: - Presentation Content

    private func presentationContent(song: Song) -> some View {
        ScrollViewReader { proxy in
        ScrollView {
            VStack(spacing: Spacing.xl) {
                // Song title
                VStack(spacing: Spacing.sm) {
                    Text("\(song.songbook) #\(song.number)")
                        .font(.system(size: fontSize * 0.4, weight: .medium))
                        .foregroundStyle(AmberTheme.light)

                    Text(song.title)
                        .font(.system(size: fontSize * 0.7, weight: .bold))
                        .foregroundStyle(.white)
                        .multilineTextAlignment(.center)
                }
                .padding(.top, Spacing.xxl)

                // Lyrics components
                ForEach(Array(song.components.enumerated()), id: \.element.id) { index, component in
                    VStack(alignment: .center, spacing: Spacing.sm) {
                        // Component label
                        if let label = componentLabel(component) {
                            Text(label.uppercased())
                                .font(.system(size: fontSize * 0.3, weight: .bold))
                                .foregroundStyle(
                                    component.type == "chorus" || component.type == "refrain"
                                    ? AmberTheme.light
                                    : .white.opacity(0.5)
                                )
                        }

                        // Lyrics lines
                        ForEach(component.lines, id: \.self) { line in
                            Text(line)
                                .font(.system(size: fontSize, weight: .regular))
                                .foregroundStyle(.white)
                                .multilineTextAlignment(.center)
                                .lineSpacing(fontSize * 0.2)
                        }
                    }
                    .padding(.vertical, Spacing.lg)
                    .opacity(index == currentComponentIndex ? 1.0 : 0.5)
                    .id(index)
                }

                Spacer(minLength: 200)
            }
            .frame(maxWidth: .infinity)
            .padding(.horizontal, Spacing.xxl)
        }
        .onChange(of: currentComponentIndex) { _, newIndex in
            withAnimation(.liquidGlassSpring) {
                proxy.scrollTo(newIndex, anchor: .center)
            }
        }
        .onChange(of: isAutoScrolling) { _, scrolling in
            if scrolling { startAutoScroll(proxy: proxy, song: song) }
        }
        } // ScrollViewReader
    }

    /// Starts a timer-based auto-scroll that advances through components.
    private func startAutoScroll(proxy: ScrollViewProxy, song: Song) {
        Task {
            while isAutoScrolling && currentComponentIndex < song.components.count - 1 {
                try? await Task.sleep(for: .seconds(4))
                guard isAutoScrolling else { break }
                currentComponentIndex += 1
                withAnimation(.liquidGlassSpring) {
                    proxy.scrollTo(currentComponentIndex, anchor: .center)
                }
            }
            isAutoScrolling = false
        }
    }

    // MARK: - Presentation Controls

    private var presentationControls: some View {
        VStack {
            Spacer()

            HStack(spacing: Spacing.lg) {
                // Previous
                Button(action: previousComponent) {
                    Image(systemName: "chevron.left")
                        .font(.title2.weight(.semibold))
                        .foregroundStyle(.white)
                        .frame(width: 44, height: 44)
                }
                .liquidGlass(.regular, tint: .white)
                .accessibilityLabel("Previous verse")

                // Font size controls
                Button(action: { fontSize = max(20, fontSize - 4) }) {
                    Image(systemName: "textformat.size.smaller")
                        .foregroundStyle(.white)
                        .frame(width: 44, height: 44)
                }
                .liquidGlass(.thin)
                .accessibilityLabel("Decrease font size")

                Button(action: { fontSize = min(80, fontSize + 4) }) {
                    Image(systemName: "textformat.size.larger")
                        .foregroundStyle(.white)
                        .frame(width: 44, height: 44)
                }
                .liquidGlass(.thin)
                .accessibilityLabel("Increase font size")

                // Auto-scroll toggle
                Button(action: { isAutoScrolling.toggle() }) {
                    Image(systemName: isAutoScrolling ? "pause.fill" : "play.fill")
                        .foregroundStyle(.white)
                        .frame(width: 44, height: 44)
                }
                .liquidGlass(.regular, tint: isAutoScrolling ? AmberTheme.accent : nil)
                .accessibilityLabel(isAutoScrolling ? "Pause auto-scroll" : "Start auto-scroll")

                // Close
                Button(action: { selectedSongId = nil }) {
                    Image(systemName: "xmark")
                        .foregroundStyle(.white)
                        .frame(width: 44, height: 44)
                }
                .liquidGlass(.thin)
                .accessibilityLabel("Close presentation")

                // Next
                Button(action: nextComponent) {
                    Image(systemName: "chevron.right")
                        .font(.title2.weight(.semibold))
                        .foregroundStyle(.white)
                        .frame(width: 44, height: 44)
                }
                .liquidGlass(.regular, tint: .white)
                .accessibilityLabel("Next verse")
            }
            .padding()
            .liquidGlass(.thick)
            .padding()
        }
        .transition(.move(edge: .bottom).combined(with: .opacity))
    }

    // MARK: - Navigation

    private func previousComponent() {
        guard let songId = selectedSongId,
              let song = songStore.song(byId: songId) else { return }
        withAnimation(.liquidGlassSpring) {
            currentComponentIndex = max(0, currentComponentIndex - 1)
        }
        _ = song // Suppress warning
    }

    private func nextComponent() {
        guard let songId = selectedSongId,
              let song = songStore.song(byId: songId) else { return }
        withAnimation(.liquidGlassSpring) {
            currentComponentIndex = min(song.components.count - 1, currentComponentIndex + 1)
        }
    }

    // MARK: - Helpers

    private func componentLabel(_ component: SongComponent) -> String? {
        switch component.type {
        case "verse":
            if let num = component.number { return "Verse \(num)" }
            return "Verse"
        case "chorus":  return "Chorus"
        case "refrain": return "Refrain"
        case "bridge":  return "Bridge"
        case "pre-chorus": return "Pre-Chorus"
        case "tag":     return "Tag"
        case "coda":    return "Coda"
        default:        return nil
        }
    }
}

// SongbookListView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - SongbookListView
/// Displays all available songbooks from the song store.
///
/// Layout strategy per platform:
/// - **iPad / Mac / visionOS** (regular width): A two-column `LazyVGrid` presenting
///   each songbook as a card with its name, abbreviation and song count.
/// - **iPhone / watchOS** (compact width): A vertical `List` with rows.
/// - **tvOS**: A horizontally focusable grid with oversized cards.
///
/// A loading overlay is shown while `songStore.isLoading` is true.
struct SongbookListView: View {

    // MARK: Environment
    /// The shared song store providing songbook metadata and loading state.
    @EnvironmentObject var songStore: SongStore

    /// Horizontal size class used to switch between grid and list layouts.
    /// Not available on watchOS, so it is conditionally compiled.
    #if !os(watchOS)
    @Environment(\.horizontalSizeClass) private var sizeClass
    #endif

    // MARK: Bindings (optional)
    /// On iPad/Mac the parent `NavigationSplitView` may pass a binding so this view
    /// can drive the detail column. On iPhone this is unused.
    @Binding var selectedSongbookId: String?

    // MARK: Initialisers
    /// Convenience initialiser for contexts that do not need the selection binding
    /// (e.g. the compact iPhone tab where NavigationLink handles navigation).
    init() {
        _selectedSongbookId = .constant(nil)
    }

    /// Initialiser that accepts an external selection binding for split-view layouts.
    init(selectedSongbookId: Binding<String?>) {
        _selectedSongbookId = selectedSongbookId
    }

    // MARK: Grid Columns
    /// Adaptive grid columns that produce two columns on iPad and three on Mac.
    private let gridColumns = [
        GridItem(.adaptive(minimum: 260, maximum: 400), spacing: 20)
    ]

    // MARK: Body
    var body: some View {
        ZStack {
            // ── Loading Overlay ───────────────────────────────────────────
            // Shown when the store is still parsing / loading the songs.json
            // resource. A ProgressView communicates ongoing work to the user.
            if songStore.isLoading {
                VStack(spacing: 16) {
                    ProgressView()
                        .controlSize(.large)
                    Text("Loading songbooks…")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
            } else {
                // ── Content ──────────────────────────────────────────────
                songbookContent
            }
        }
        // Apply an amber-gradient header background that echoes the web app
        #if !os(watchOS) && !os(tvOS)
        .background(AmberTheme.wash.opacity(0.3))
        #endif
    }

    // MARK: - Content Switcher
    /// Chooses between grid and list layouts based on platform and size class.
    @ViewBuilder
    private var songbookContent: some View {
        #if os(watchOS)
        // ── watchOS: Compact vertical list ────────────────────────────
        // watchOS has very limited screen space; a simple list suffices.
        List(songStore.songData.songbooks, id: \.id) { songbook in
            NavigationLink(destination: SongListView(songbookId: songbook.id)) {
                watchOSRow(for: songbook)
            }
        }

        #elseif os(tvOS)
        // ── tvOS: Horizontally focusable grid with oversized cards ────
        // Cards are large so they remain legible from a distance.
        ScrollView {
            LazyVGrid(columns: gridColumns, spacing: 40) {
                ForEach(songStore.songData.songbooks, id: \.id) { songbook in
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
        // ── iOS / iPadOS / macOS / visionOS ──────────────────────────
        if sizeClass == .compact {
            // Compact (iPhone): vertical list for easy one-handed use
            List(songStore.songData.songbooks, id: \.id) { songbook in
                NavigationLink(destination: SongListView(songbookId: songbook.id)) {
                    songbookRow(for: songbook)
                }
            }
            .listStyle(.insetGrouped)
        } else {
            // Regular (iPad / Mac): attractive card grid
            ScrollView {
                // Amber gradient header area matching the web app's banner
                amberHeader

                LazyVGrid(columns: gridColumns, spacing: 20) {
                    ForEach(songStore.songData.songbooks, id: \.id) { songbook in
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
    }

    // MARK: - Amber Gradient Header
    /// A decorative gradient banner at the top of the grid view that mirrors the
    /// amber header seen in the iHymns web application.
    private var amberHeader: some View {
        ZStack(alignment: .bottomLeading) {
            // The gradient spans from the darker accent amber to the lighter amber
            LinearGradient(
                colors: [AmberTheme.accent, AmberTheme.light],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            )
            .frame(height: 120)

            // Title text overlaid on the gradient
            VStack(alignment: .leading, spacing: 4) {
                Text("iHymns")
                    .font(.largeTitle.bold())
                    .foregroundStyle(.white)
                Text("\(songStore.songData.meta.totalSongs) hymns across \(songStore.songData.meta.totalSongbooks) songbooks")
                    .font(.subheadline)
                    .foregroundStyle(.white.opacity(0.85))
            }
            .padding(.horizontal, 24)
            .padding(.bottom, 16)
        }
    }

    // MARK: - Songbook Card (iPad / Mac / tvOS)
    /// A visually rich card showing the songbook's abbreviation badge, full name
    /// and the number of songs it contains. Used in grid layouts.
    private func songbookCard(for songbook: Songbook) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            // Abbreviation badge in an amber circle
            HStack {
                Text(songbook.id)
                    .font(.headline.bold())
                    .foregroundStyle(.white)
                    .padding(.horizontal, 14)
                    .padding(.vertical, 8)
                    .background(AmberTheme.accent, in: RoundedRectangle(cornerRadius: 8))
                Spacer()
            }

            // Songbook full name
            Text(songbook.name)
                .font(.title3.bold())
                .foregroundStyle(.primary)
                .lineLimit(2)
                .multilineTextAlignment(.leading)

            // Song count subtitle
            Text("\(songbook.songCount) songs")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(.regularMaterial, in: RoundedRectangle(cornerRadius: 16))
        .overlay(
            RoundedRectangle(cornerRadius: 16)
                .strokeBorder(AmberTheme.accent.opacity(0.2), lineWidth: 1)
        )
    }

    // MARK: - Songbook Row (iPhone)
    /// A compact list row displaying the songbook abbreviation, name and song count.
    /// Designed for the narrower iPhone layout.
    private func songbookRow(for songbook: Songbook) -> some View {
        HStack(spacing: 14) {
            // Abbreviation badge
            Text(songbook.id)
                .font(.headline.bold())
                .foregroundStyle(.white)
                .frame(width: 50, height: 50)
                .background(AmberTheme.accent, in: RoundedRectangle(cornerRadius: 10))

            // Name and count
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
    /// A minimal row for watchOS that shows just the abbreviation and name.
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

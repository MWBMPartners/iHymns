// HelpView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - HelpView
/// An in-app help screen that provides guidance on using iHymns.
///
/// The view is structured as a sectioned `List` where each topic is wrapped
/// in a `DisclosureGroup` so the user can expand only the sections they are
/// interested in. This keeps the screen uncluttered while still providing
/// comprehensive help content.
///
/// Sections:
/// 1. **Searching** — How to find songs by title, lyrics, number or writer.
/// 2. **Songbooks** — Overview of the available songbooks.
/// 3. **Favourites** — How to add, view and manage favourite songs.
/// 4. **Themes** — Information about display customisation (future feature).
/// 5. **About** — App version, credits and legal information.
struct HelpView: View {

    // MARK: State
    /// Tracks which disclosure groups are currently expanded. Using individual
    /// booleans rather than an `OptionSet` so each section operates independently.
    @State private var searchExpanded: Bool = false
    @State private var songbooksExpanded: Bool = false
    @State private var favouritesExpanded: Bool = false
    @State private var themesExpanded: Bool = false
    @State private var aboutExpanded: Bool = true

    // MARK: Body
    var body: some View {
        List {
            // ── App Header Section ───────────────────────────────────────
            // A brief introductory section at the top with the app name
            // and a short description.
            Section {
                VStack(alignment: .leading, spacing: 8) {
                    // App name with amber accent
                    Text("iHymns")
                        .font(.title.bold())
                        .foregroundStyle(AmberTheme.accent)

                    // Tagline describing the app's purpose
                    Text("Your digital hymnal — browse, search and sing from multiple songbooks on any Apple device.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
                .padding(.vertical, 8)
            }

            // ── Searching Section ────────────────────────────────────────
            // Explains the various ways the user can search for songs.
            Section {
                DisclosureGroup(isExpanded: $searchExpanded) {
                    helpContent(items: [
                        "Use the Search tab (iPhone) or the sidebar search bar (iPad/Mac) to find songs across all songbooks.",
                        "You can search by song title, lyrics, song number, writer or composer name.",
                        "When viewing a specific songbook, use the in-list search bar to filter songs within that songbook only.",
                        "Search is case-insensitive — type in any case and matching results will appear.",
                        "Results show the song number, title, songbook badge and a brief lyrics preview to help you identify the right song."
                    ])
                } label: {
                    // Section header with an icon
                    Label("Searching", systemImage: "magnifyingglass")
                        .font(.headline)
                }
            }

            // ── Songbooks Section ────────────────────────────────────────
            // Describes the songbooks available in the app and how to browse them.
            Section {
                DisclosureGroup(isExpanded: $songbooksExpanded) {
                    helpContent(items: [
                        "iHymns includes multiple songbooks: Carol Praise (CP), Junior Praise (JP), Mission Praise (MP), Seventh-day Adventist Hymnal (SDAH) and The Church Hymnal (CH).",
                        "On iPhone, tap the Songbooks tab to see all available songbooks. On iPad and Mac, songbooks appear in the sidebar.",
                        "Tap a songbook to view its complete list of songs, sorted by hymn number.",
                        "Each songbook card shows the songbook abbreviation, full name and the total number of songs it contains.",
                        "Songs include verses and choruses, along with writer, composer and copyright information."
                    ])
                } label: {
                    Label("Songbooks", systemImage: "books.vertical")
                        .font(.headline)
                }
            }

            // ── Favourites Section ───────────────────────────────────────
            // Explains how to add and manage favourite songs.
            Section {
                DisclosureGroup(isExpanded: $favouritesExpanded) {
                    helpContent(items: [
                        "Tap the star icon on any song's detail page to add it to your favourites.",
                        "Access your favourites from the Favourites tab (iPhone) or the sidebar star button (iPad/Mac).",
                        "Favourited songs show a filled star in song lists for easy identification.",
                        "To remove a song from favourites, swipe left on its row in the Favourites list, or tap the star icon again in the detail view.",
                        "Your favourites are saved locally on your device and persist between app launches."
                    ])
                } label: {
                    Label("Favourites", systemImage: "star")
                        .font(.headline)
                }
            }

            // ── Themes Section ───────────────────────────────────────────
            // Information about display customisation options.
            Section {
                DisclosureGroup(isExpanded: $themesExpanded) {
                    helpContent(items: [
                        "iHymns follows your system appearance setting — it supports both Light and Dark modes automatically.",
                        "On macOS and iPadOS, the app respects the system-wide accent colour while maintaining its amber brand identity.",
                        "Text sizes adapt to your Dynamic Type preferences in Settings > Accessibility > Display & Text Size.",
                        "On tvOS, text is rendered at extra-large sizes for congregational reading from a distance.",
                        "On Apple Watch, the layout is condensed to maximise lyrics visibility on the small screen."
                    ])
                } label: {
                    Label("Themes & Display", systemImage: "paintbrush")
                        .font(.headline)
                }
            }

            // ── About Section ────────────────────────────────────────────
            // App version, credits and legal information.
            Section {
                DisclosureGroup(isExpanded: $aboutExpanded) {
                    VStack(alignment: .leading, spacing: 12) {
                        // App version information
                        aboutRow(label: "Version", value: appVersion)

                        // Developer attribution
                        aboutRow(label: "Developer", value: "MWBM Partners Ltd")

                        // Copyright notice
                        aboutRow(label: "Copyright", value: "© 2026 MWBM Partners Ltd. All rights reserved.")

                        // Brief description of the app
                        Text("iHymns is a digital hymnal application providing access to multiple songbooks for personal and congregational worship. Song lyrics and metadata are included under licence from the respective copyright holders.")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .padding(.top, 4)

                        // Proprietary software notice
                        Text("This software is proprietary. Unauthorised distribution or reproduction is prohibited.")
                            .font(.caption2)
                            .foregroundStyle(.tertiary)
                    }
                    .padding(.vertical, 8)
                } label: {
                    Label("About", systemImage: "info.circle")
                        .font(.headline)
                }
            }
        }
        .listStyle(.insetGrouped)
        .navigationTitle("Help")
        #if !os(tvOS) && !os(watchOS)
        .navigationBarTitleDisplayMode(.large)
        #endif
    }

    // MARK: - Help Content Builder
    /// Renders a list of help text items as individual rows with bullet-point
    /// styling. Each item is a separate `Text` view with a leading bullet.
    ///
    /// - Parameter items: An array of help strings to display.
    private func helpContent(items: [String]) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            ForEach(items, id: \.self) { item in
                HStack(alignment: .top, spacing: 8) {
                    // Bullet point character
                    Text("•")
                        .font(.body)
                        .foregroundStyle(AmberTheme.accent)

                    // Help text
                    Text(item)
                        .font(.subheadline)
                        .foregroundStyle(.primary)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
        }
        .padding(.vertical, 8)
    }

    // MARK: - About Row
    /// A key-value row used in the About section to display labelled information.
    ///
    /// - Parameters:
    ///   - label: The bold label text (e.g. "Version").
    ///   - value: The value text (e.g. "1.0.0").
    private func aboutRow(label: String, value: String) -> some View {
        HStack {
            Text(label)
                .font(.subheadline.bold())
            Spacer()
            Text(value)
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
    }

    // MARK: - App Version
    /// Reads the app version and build number from the main bundle's Info.plist.
    /// Falls back to "1.0" if the keys are not present.
    private var appVersion: String {
        let version = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0"
        let build = Bundle.main.infoDictionary?["CFBundleVersion"] as? String ?? "1"
        return "\(version) (\(build))"
    }
}

// MARK: - Preview
#if DEBUG
#Preview {
    NavigationStack {
        HelpView()
    }
}
#endif

// HelpView.swift
// iHymns
//
// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

import SwiftUI

// MARK: - HelpView
/// In-app help screen with expandable sections covering all app features.
struct HelpView: View {

    @State private var searchExpanded: Bool = false
    @State private var songbooksExpanded: Bool = false
    @State private var favouritesExpanded: Bool = false
    @State private var setListsExpanded: Bool = false
    @State private var presentationExpanded: Bool = false
    @State private var themesExpanded: Bool = false
    @State private var aboutExpanded: Bool = true

    var body: some View {
        List {
            // App Header
            Section {
                VStack(alignment: .leading, spacing: 8) {
                    Text("iHymns")
                        .font(.title.bold())
                        .foregroundStyle(AmberTheme.accent)

                    Text("Your digital hymnal — browse, search and sing from multiple songbooks on any Apple device.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
                .padding(.vertical, 8)
            }

            // Searching
            Section {
                DisclosureGroup(isExpanded: $searchExpanded) {
                    helpContent(items: [
                        "Use the Search tab (iPhone) or the sidebar search bar (iPad/Mac) to find songs across all songbooks.",
                        "Search by song title, lyrics, song number, writer or composer name.",
                        "When viewing a songbook, use the in-list search to filter within that book only.",
                        "Recent searches are saved and shown as quick-access chips.",
                        "Results show the song number, title, songbook badge and a lyrics preview."
                    ])
                } label: {
                    Label("Searching", systemImage: "magnifyingglass")
                        .font(.headline)
                }
            }

            // Songbooks
            Section {
                DisclosureGroup(isExpanded: $songbooksExpanded) {
                    helpContent(items: [
                        "iHymns includes: Carol Praise (CP), Junior Praise (JP), Mission Praise (MP), Seventh-day Adventist Hymnal (SDAH) and The Church Hymnal (CH).",
                        "On iPhone, tap the Songbooks tab. On iPad and Mac, songbooks appear in the sidebar.",
                        "Each songbook card shows the abbreviation, full name and song count.",
                        "Songs include verses, choruses, bridges, with writer and copyright credits.",
                        "Song of the Day features a rotating hymn on the home screen."
                    ])
                } label: {
                    Label("Songbooks", systemImage: "books.vertical")
                        .font(.headline)
                }
            }

            // Favourites
            Section {
                DisclosureGroup(isExpanded: $favouritesExpanded) {
                    helpContent(items: [
                        "Tap the star icon on any song to add it to your favourites.",
                        "Access favourites from the Favourites tab or sidebar star button.",
                        "Swipe left on a favourite to remove it, or tap the star again.",
                        "Export your favourites as a shareable text list.",
                        "Favourites are saved locally and sync to Home Screen widgets."
                    ])
                } label: {
                    Label("Favourites", systemImage: "star")
                        .font(.headline)
                }
            }

            // Set Lists
            Section {
                DisclosureGroup(isExpanded: $setListsExpanded) {
                    helpContent(items: [
                        "Create named set lists to organise songs for worship services.",
                        "Add songs from any songbook by tapping the list icon on a song page.",
                        "Drag to reorder songs within a set list.",
                        "Share set lists via a link that anyone can access.",
                        "Export set lists as plain text for printing or sharing."
                    ])
                } label: {
                    Label("Set Lists", systemImage: "list.bullet.rectangle")
                        .font(.headline)
                }
            }

            // Presentation Mode
            Section {
                DisclosureGroup(isExpanded: $presentationExpanded) {
                    helpContent(items: [
                        "Presentation mode displays lyrics full-screen for congregational projection.",
                        "Navigate verse-by-verse with arrow keys or on-screen controls.",
                        "Adjust font size on the fly for different room sizes.",
                        "Auto-scroll with adjustable speed using the play button.",
                        "Press Space to pause auto-scroll, Escape to exit."
                    ])
                } label: {
                    Label("Presentation", systemImage: "tv")
                        .font(.headline)
                }
            }

            // Keyboard Shortcuts
            #if !os(watchOS) && !os(tvOS)
            Section {
                DisclosureGroup {
                    VStack(alignment: .leading, spacing: Spacing.sm) {
                        ForEach(AppKeyboardShortcuts.all) { shortcut in
                            HStack {
                                Text(shortcut.key)
                                    .font(.system(.body, design: .monospaced).bold())
                                    .foregroundStyle(AmberTheme.accent)
                                    .frame(width: 80, alignment: .leading)
                                Text(shortcut.description)
                                    .font(.subheadline)
                                Spacer()
                            }
                        }
                    }
                    .padding(.vertical, 8)
                } label: {
                    Label("Keyboard Shortcuts", systemImage: "keyboard")
                        .font(.headline)
                }
            }
            #endif

            // Themes & Display
            Section {
                DisclosureGroup(isExpanded: $themesExpanded) {
                    helpContent(items: [
                        "Choose from System, Light, Dark, or High Contrast themes in Settings.",
                        "Adjust lyrics font size and line spacing to your preference.",
                        "Toggle chorus highlighting and verse/chorus labels.",
                        "On tvOS, text is extra-large for congregational reading.",
                        "On Apple Watch, the layout is condensed for the small screen."
                    ])
                } label: {
                    Label("Themes & Display", systemImage: "paintbrush")
                        .font(.headline)
                }
            }

            // About
            Section {
                DisclosureGroup(isExpanded: $aboutExpanded) {
                    VStack(alignment: .leading, spacing: 12) {
                        aboutRow(label: "Version", value: AppInfo.Application.Version.bundleVersion)
                        aboutRow(label: "Build", value: AppInfo.Application.Version.buildNumber)
                        aboutRow(label: "Developer", value: AppInfo.Application.Vendor.name)

                        Link(destination: URL(string: AppInfo.Application.websiteURL)!) {
                            HStack {
                                Text("Website")
                                    .font(.subheadline.bold())
                                Spacer()
                                Text("ihymns.app")
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                                Image(systemName: "arrow.up.right")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }

                        Text(AppInfo.Application.Copyright.full)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .padding(.top, 4)

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

    // MARK: - Helpers

    private func helpContent(items: [String]) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            ForEach(items, id: \.self) { item in
                HStack(alignment: .top, spacing: 8) {
                    Text("•")
                        .font(.body)
                        .foregroundStyle(AmberTheme.accent)
                    Text(item)
                        .font(.subheadline)
                        .foregroundStyle(.primary)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
        }
        .padding(.vertical, 8)
    }

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
}

// MARK: - Preview
#if DEBUG
#Preview {
    NavigationStack {
        HelpView()
    }
}
#endif

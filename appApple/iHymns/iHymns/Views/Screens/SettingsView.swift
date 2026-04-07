// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  SettingsView.swift
//  iHymns
//
//  User preferences and app settings screen using Liquid Glass design.
//  Provides controls for:
//  - Theme selection (System / Light / Dark / High Contrast)
//  - Lyrics display options (font size, line spacing, labels)
//  - Search behaviour (include lyrics in search)
//  - Default songbook selection
//  - Data sync controls
//  - Accessibility options
//  - App information and about section
//

import SwiftUI

// MARK: - SettingsView

struct SettingsView: View {

    @EnvironmentObject var songStore: SongStore
    @State private var preferences: UserPreferences = UserPreferences()

    var body: some View {
        #if os(watchOS)
        watchOSSettings
        #else
        fullSettings
        #endif
    }

    // MARK: - Full Settings (iOS / iPadOS / macOS / visionOS)

    #if !os(watchOS)
    private var fullSettings: some View {
        Form {
            // MARK: Appearance
            Section {
                Picker("Theme", selection: $preferences.theme) {
                    ForEach(UserPreferences.AppTheme.allCases, id: \.self) { theme in
                        Text(theme.displayName).tag(theme)
                    }
                }

                Toggle("Reduce Motion", isOn: $preferences.reduceMotion)
                Toggle("Reduce Transparency", isOn: $preferences.reduceTransparency)
            } header: {
                Label("Appearance", systemImage: "paintbrush")
            } footer: {
                Text("Theme applies immediately. Reduce Motion and Reduce Transparency follow your system Accessibility settings by default.")
            }

            // MARK: Lyrics Display
            Section {
                VStack(alignment: .leading, spacing: Spacing.sm) {
                    Text("Font Size: \(String(format: "%.1fx", preferences.lyricsFontScale))")
                    Slider(value: $preferences.lyricsFontScale, in: 0.5...3.0, step: 0.1) {
                        Text("Font Size")
                    }
                    .tint(AmberTheme.accent)
                }

                Picker("Line Spacing", selection: $preferences.lineSpacing) {
                    ForEach(UserPreferences.LineSpacingOption.allCases, id: \.self) { option in
                        Text(option.displayName).tag(option)
                    }
                }

                Toggle("Show Verse/Chorus Labels", isOn: $preferences.showComponentLabels)
                Toggle("Highlight Chorus", isOn: $preferences.highlightChorus)
            } header: {
                Label("Lyrics Display", systemImage: "text.alignleft")
            }

            // MARK: Search
            Section {
                Toggle("Include Lyrics in Search", isOn: $preferences.searchLyrics)

                if let songbooks = songStore.songData?.songbooks {
                    Picker("Default Songbook", selection: $preferences.defaultSongbook) {
                        Text("None").tag(String?.none)
                        ForEach(songbooks) { songbook in
                            Text(songbook.name).tag(Optional(songbook.id))
                        }
                    }
                }
            } header: {
                Label("Search", systemImage: "magnifyingglass")
            }

            // MARK: Data
            Section {
                Toggle("Auto-Update Songs", isOn: $preferences.autoUpdateSongs)

                if let lastSync = songStore.lastSyncDate {
                    HStack {
                        Text("Last Synced")
                        Spacer()
                        Text(lastSync, style: .relative)
                            .foregroundStyle(.secondary)
                    }
                }

                Button("Sync Now") {
                    Task {
                        await songStore.syncFromAPI()
                        HapticManager.success()
                    }
                }
                .disabled(songStore.isSyncing)
            } header: {
                Label("Data", systemImage: "arrow.triangle.2.circlepath")
            }

            // MARK: Privacy & Analytics
            Section {
                let analytics = AnalyticsService.shared

                HStack {
                    Text("Analytics Consent")
                    Spacer()
                    Text(analytics.consent.rawValue.replacingOccurrences(of: "_", with: " ").capitalized)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }

                if analytics.consent == .notAsked || analytics.consent == .denied {
                    Button("Enable Analytics") {
                        Task {
                            await analytics.requestTrackingPermission()
                            analytics.grantConsent()
                        }
                    }
                } else {
                    Button("Disable Analytics") {
                        analytics.denyConsent()
                    }
                    .foregroundStyle(.red)
                }
            } header: {
                Label("Privacy & Analytics", systemImage: "hand.raised")
            } footer: {
                Text("Analytics help us improve iHymns. We use Plausible (privacy-focused, cookieless) and Supabase. No personal data is collected. You can change this at any time.")
            }

            // MARK: History
            Section {
                Button("Clear Search History") {
                    songStore.clearSearchHistory()
                    HapticManager.lightImpact()
                }
                .disabled(songStore.searchHistory.isEmpty)

                Button("Clear View History") {
                    songStore.clearViewHistory()
                    HapticManager.lightImpact()
                }
                .disabled(songStore.viewHistory.isEmpty)
            } header: {
                Label("History", systemImage: "clock")
            }

            // MARK: About
            Section {
                aboutRow("App", value: AppInfo.Application.name)
                aboutRow("Version", value: AppInfo.Application.Version.bundleVersion)
                aboutRow("Build", value: AppInfo.Application.Version.buildNumber)

                if let songData = songStore.songData {
                    aboutRow("Songs", value: "\(songData.meta.totalSongs)")
                    aboutRow("Songbooks", value: "\(songData.meta.totalSongbooks)")
                }

                aboutRow("Developer", value: AppInfo.Application.Vendor.name)

                Link(destination: URL(string: AppInfo.Application.websiteURL)!) {
                    HStack {
                        Text("Website")
                        Spacer()
                        Text("ihymns.app")
                            .foregroundStyle(.secondary)
                        Image(systemName: "arrow.up.right")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }

                Text(AppInfo.Application.Copyright.full)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            } header: {
                Label("About", systemImage: "info.circle")
            }
        }
        .navigationTitle("Settings")
        .onAppear {
            preferences = songStore.preferences
        }
        .onChange(of: preferences) { _, newValue in
            songStore.updatePreferences(newValue)
        }
    }
    #endif

    // MARK: - watchOS Settings

    #if os(watchOS)
    private var watchOSSettings: some View {
        List {
            Section("Display") {
                VStack(alignment: .leading) {
                    Text("Font Size: \(String(format: "%.1fx", preferences.lyricsFontScale))")
                        .font(.caption)
                    Slider(value: $preferences.lyricsFontScale, in: 0.5...2.0, step: 0.1)
                }
            }

            Section("About") {
                Text("\(AppInfo.Application.name) v\(AppInfo.Application.Version.bundleVersion)")
                    .font(.caption)
                Text(AppInfo.Application.Copyright.full)
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }
        }
        .navigationTitle("Settings")
        .onAppear { preferences = songStore.preferences }
        .onChange(of: preferences) { _, newValue in
            songStore.updatePreferences(newValue)
        }
    }
    #endif

    // MARK: - Helpers

    private func aboutRow(_ label: String, value: String) -> some View {
        HStack {
            Text(label)
            Spacer()
            Text(value)
                .foregroundStyle(.secondary)
        }
    }
}

// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  PlatformFeatures.swift
//  iHymns
//
//  Provides platform-specific feature integrations including:
//  - Dynamic Island (Live Activities) on iPhone
//  - Touch Bar on Mac
//  - Spotlight indexing
//  - Notification scheduling (Song of the Day)
//  - Handoff / Universal Links
//  - Quick Actions (Home screen shortcuts)
//  - App Intents for Siri / Shortcuts
//

import Foundation
import SwiftUI

#if canImport(ActivityKit)
import ActivityKit
#endif

#if canImport(CoreSpotlight)
import CoreSpotlight
#endif

#if canImport(UserNotifications)
import UserNotifications
#endif

// MARK: - Platform Feature Manager

/// Coordinates platform-specific features that are available on the
/// current device. Initialise once at app launch and call the
/// appropriate setup methods.
@MainActor
final class PlatformFeatureManager: ObservableObject {

    // MARK: - Singleton

    static let shared = PlatformFeatureManager()

    // MARK: - State

    /// Whether notifications have been authorised by the user.
    @Published var notificationsAuthorised: Bool = false

    /// Whether Spotlight indexing is in progress.
    @Published var isIndexingSpotlight: Bool = false

    // MARK: - Notification Setup

    /// Requests notification permission and schedules the daily
    /// Song of the Day notification if granted.
    func setupNotifications() async {
        #if canImport(UserNotifications) && !os(tvOS)
        let center = UNUserNotificationCenter.current()

        do {
            let granted = try await center.requestAuthorization(options: [.alert, .badge, .sound])
            notificationsAuthorised = granted

            if granted {
                await scheduleSongOfTheDayNotification()
            }
        } catch {
            // Notification permission denied or unavailable — not critical
            notificationsAuthorised = false
        }
        #endif
    }

    /// Schedules a daily notification for the Song of the Day feature.
    /// Fires at 8:00 AM local time each day.
    private func scheduleSongOfTheDayNotification() async {
        #if canImport(UserNotifications) && !os(tvOS)
        let center = UNUserNotificationCenter.current()

        // Remove any existing Song of the Day notifications
        center.removePendingNotificationRequests(withIdentifiers: ["songOfTheDay"])

        let content = UNMutableNotificationContent()
        content.title = "Song of the Day"
        content.body = "Discover today's featured hymn in iHymns"
        content.sound = .default
        content.categoryIdentifier = "SONG_OF_THE_DAY"

        // Trigger at 8:00 AM every day
        var dateComponents = DateComponents()
        dateComponents.hour = 8
        dateComponents.minute = 0

        let trigger = UNCalendarNotificationTrigger(
            dateMatching: dateComponents,
            repeats: true
        )

        let request = UNNotificationRequest(
            identifier: "songOfTheDay",
            content: content,
            trigger: trigger
        )

        try? await center.add(request)
        #endif
    }

    // MARK: - Spotlight Indexing

    /// Indexes all songs in Core Spotlight for system-wide search.
    /// Songs become searchable from the iOS/macOS Spotlight search bar.
    func indexSongsInSpotlight(songs: [Song]) async {
        #if canImport(CoreSpotlight) && !os(tvOS) && !os(watchOS)
        isIndexingSpotlight = true

        let searchableIndex = CSSearchableIndex.default()

        // Build searchable items for each song
        let items: [CSSearchableItem] = songs.map { song in
            let attributes = CSSearchableItemAttributeSet(contentType: .text)
            attributes.title = "\(song.number). \(song.title)"
            attributes.contentDescription = song.lyricsPreview
            attributes.keywords = [
                song.songbookName,
                song.songbook
            ] + song.writers + song.composers

            // Add metadata for rich Spotlight results
            attributes.creator = song.writersDisplay
            attributes.album = song.songbookName

            return CSSearchableItem(
                uniqueIdentifier: "song:\(song.id)",
                domainIdentifier: "ltd.mwbmpartners.ihymns.songs",
                attributeSet: attributes
            )
        }

        // Index in batches of 500 to avoid memory pressure
        let batchSize = 500
        for batchStart in stride(from: 0, to: items.count, by: batchSize) {
            let batchEnd = min(batchStart + batchSize, items.count)
            let batch = Array(items[batchStart..<batchEnd])
            try? await searchableIndex.indexSearchableItems(batch)
        }

        isIndexingSpotlight = false
        #endif
    }

    // MARK: - Quick Actions

    /// Configures the Home Screen quick actions (3D Touch / long-press menu).
    /// These provide shortcuts to key app features from the app icon.
    func setupQuickActions() {
        #if os(iOS)
        UIApplication.shared.shortcutItems = [
            UIApplicationShortcutItem(
                type: "ltd.mwbmpartners.ihymns.search",
                localizedTitle: "Search Songs",
                localizedSubtitle: "Find a hymn by title or lyrics",
                icon: UIApplicationShortcutIcon(systemImageName: "magnifyingglass"),
                userInfo: nil
            ),
            UIApplicationShortcutItem(
                type: "ltd.mwbmpartners.ihymns.favorites",
                localizedTitle: "Favourites",
                localizedSubtitle: "View your saved hymns",
                icon: UIApplicationShortcutIcon(systemImageName: "star.fill"),
                userInfo: nil
            ),
            UIApplicationShortcutItem(
                type: "ltd.mwbmpartners.ihymns.random",
                localizedTitle: "Random Song",
                localizedSubtitle: "Discover a hymn",
                icon: UIApplicationShortcutIcon(systemImageName: "shuffle"),
                userInfo: nil
            ),
            UIApplicationShortcutItem(
                type: "ltd.mwbmpartners.ihymns.setlist",
                localizedTitle: "Set Lists",
                localizedSubtitle: "Manage worship set lists",
                icon: UIApplicationShortcutIcon(systemImageName: "list.bullet.rectangle"),
                userInfo: nil
            )
        ]
        #endif
    }

    // MARK: - Dynamic Island / Live Activities

    /// Starts a Live Activity for the currently playing/viewing song.
    /// This shows the song title and progress on the Dynamic Island
    /// (iPhone 14 Pro and later) and the Lock Screen.
    func startSongLiveActivity(song: Song) {
        #if canImport(ActivityKit) && os(iOS)
        // Live Activity support requires iOS 16.1+
        guard ActivityAuthorizationInfo().areActivitiesEnabled else { return }

        // Live Activity implementation will use SongActivityAttributes
        // defined in the ActivityKit extension target
        #endif
    }

    /// Updates the current Live Activity with new song information.
    func updateSongLiveActivity(song: Song) {
        #if canImport(ActivityKit) && os(iOS)
        // Update the ongoing Live Activity
        #endif
    }

    /// Ends the current song Live Activity.
    func endSongLiveActivity() {
        #if canImport(ActivityKit) && os(iOS)
        // End all song-related Live Activities
        #endif
    }

    // MARK: - Handoff

    /// Creates an NSUserActivity for Handoff support.
    /// Allows the user to continue viewing a song on another Apple device.
    func createHandoffActivity(for song: Song) -> NSUserActivity {
        let activity = NSUserActivity(activityType: "ltd.mwbmpartners.ihymns.viewSong")
        activity.title = song.title
        activity.isEligibleForHandoff = true
        activity.isEligibleForSearch = true
        activity.isEligibleForPublicIndexing = false
        activity.userInfo = [
            "songId": song.id,
            "songTitle": song.title,
            "songbook": song.songbook
        ]
        activity.webpageURL = URL(string: "https://ihymns.app/song/\(song.id)")
        return activity
    }
}

// MARK: - Touch Bar Support (macOS)

#if os(macOS)
/// Provides Touch Bar items for the macOS version of iHymns.
/// Shows playback controls, favorite toggle, and font size adjustment.
struct SongTouchBarView: View {

    let song: Song
    let isFavorite: Bool
    let onToggleFavorite: () -> Void
    let onShare: () -> Void

    var body: some View {
        HStack(spacing: 16) {
            // Song info
            Text(song.title)
                .font(.caption)
                .lineLimit(1)

            Divider()

            // Favourite toggle
            Button(action: onToggleFavorite) {
                Image(systemName: isFavorite ? "star.fill" : "star")
                    .foregroundStyle(isFavorite ? AmberTheme.accent : .secondary)
            }

            // Share button
            Button(action: onShare) {
                Image(systemName: "square.and.arrow.up")
            }
        }
        .padding(.horizontal)
    }
}
#endif

// MARK: - App Intent for Siri / Shortcuts

/// Placeholder for App Intents framework integration.
/// Will allow Siri commands like "Show me Mission Praise 742"
/// and Shortcuts automation for worship planning.
enum AppIntentIdentifiers {
    static let searchSongs = "ltd.mwbmpartners.ihymns.intent.searchSongs"
    static let viewSong = "ltd.mwbmpartners.ihymns.intent.viewSong"
    static let randomSong = "ltd.mwbmpartners.ihymns.intent.randomSong"
    static let viewFavorites = "ltd.mwbmpartners.ihymns.intent.viewFavorites"
}

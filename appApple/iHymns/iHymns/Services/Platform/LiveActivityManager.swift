// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  LiveActivityManager.swift
//  iHymns
//
//  Dynamic Island and Lock Screen Live Activities for displaying
//  the currently viewing/playing song. iPhone 14 Pro+ only.
//

import Foundation
import SwiftUI

#if canImport(ActivityKit) && os(iOS)
import ActivityKit

// MARK: - Song Activity Attributes

/// Defines the data model for a song Live Activity.
struct SongActivityAttributes: ActivityAttributes {

    /// Static data that doesn't change during the activity.
    public struct ContentState: Codable, Hashable {
        var songTitle: String
        var songbook: String
        var songNumber: Int
        var componentLabel: String
        var progress: Double  // 0.0 to 1.0
        var setListPosition: String?  // "3 of 8" or nil
    }

    /// The song ID (constant for the activity lifetime).
    var songId: String
    var songbookColor: String  // hex colour for the badge
}

// MARK: - Live Activity Manager

@MainActor
final class LiveActivityManager {

    static let shared = LiveActivityManager()

    private var currentActivity: Activity<SongActivityAttributes>?

    /// Whether Live Activities are available on this device.
    var isAvailable: Bool {
        ActivityAuthorizationInfo().areActivitiesEnabled
    }

    /// Starts a Live Activity for the given song.
    func startActivity(song: Song, setListPosition: String? = nil) {
        guard isAvailable else { return }

        // End any existing activity
        endActivity()

        let attributes = SongActivityAttributes(
            songId: song.id,
            songbookColor: songbookHexColor(song.songbook)
        )

        let state = SongActivityAttributes.ContentState(
            songTitle: song.title,
            songbook: song.songbook,
            songNumber: song.number,
            componentLabel: "Verse 1",
            progress: 0.0,
            setListPosition: setListPosition
        )

        do {
            let content = ActivityContent(state: state, staleDate: nil)
            currentActivity = try Activity.request(
                attributes: attributes,
                content: content,
                pushType: nil
            )
        } catch {
            // Live Activity request failed — non-critical
        }
    }

    /// Updates the Live Activity with new component/progress.
    func updateActivity(componentLabel: String, progress: Double, setListPosition: String? = nil) {
        guard let activity = currentActivity else { return }

        var state = activity.content.state
        state.componentLabel = componentLabel
        state.progress = progress
        if let pos = setListPosition { state.setListPosition = pos }

        Task {
            let content = ActivityContent(state: state, staleDate: nil)
            await activity.update(content)
        }
    }

    /// Ends the current Live Activity.
    func endActivity() {
        guard let activity = currentActivity else { return }

        Task {
            let finalState = activity.content.state
            let content = ActivityContent(state: finalState, staleDate: nil)
            await activity.end(content, dismissalPolicy: .immediate)
        }
        currentActivity = nil
    }

    /// Returns the hex colour string for a songbook.
    private func songbookHexColor(_ id: String) -> String {
        switch id.uppercased() {
        case "CP":   return "4f46e5"
        case "JP":   return "ec4899"
        case "MP":   return "14b8a6"
        case "SDAH": return "f59e0b"
        case "CH":   return "ef4444"
        case "MISC": return "8b5cf6"
        default:     return "b45309"
        }
    }
}
#endif

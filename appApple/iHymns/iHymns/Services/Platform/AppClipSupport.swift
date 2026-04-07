// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  AppClipSupport.swift
//  iHymns
//
//  App Clip support for instant access to shared songs/set lists
//  via QR codes, NFC tags, or shared links without installing the full app.
//

import Foundation
import SwiftUI

// MARK: - App Clip Configuration

/// Configuration and utilities for the App Clip experience.
enum AppClipConfig {

    /// The base URL domain for App Clip invocations.
    static let domain = "ihymns.app"

    /// App Clip URL patterns:
    /// - Song: https://ihymns.app/clip/song/{songId}
    /// - Set list: https://ihymns.app/clip/setlist/{shareId}
    /// - Song of the Day: https://ihymns.app/clip/sotd

    /// Parses an App Clip invocation URL and returns the route.
    static func parseClipURL(_ url: URL) -> ClipRoute? {
        let components = url.pathComponents
        guard components.count >= 3, components[1] == "clip" else { return nil }

        switch components[2] {
        case "song" where components.count >= 4:
            return .song(id: components[3])
        case "setlist" where components.count >= 4:
            return .setList(shareId: components[3])
        case "sotd":
            return .songOfTheDay
        default:
            return nil
        }
    }

    /// Routes available in the App Clip.
    enum ClipRoute {
        case song(id: String)
        case setList(shareId: String)
        case songOfTheDay
    }
}

// MARK: - App Clip Entry View

/// Lightweight entry point for the App Clip experience.
/// Shows the requested content with a Smart App Banner to install the full app.
struct AppClipEntryView: View {

    let url: URL
    @StateObject private var songStore = SongStore()

    var body: some View {
        NavigationStack {
            Group {
                if let route = AppClipConfig.parseClipURL(url) {
                    switch route {
                    case .song(let id):
                        if let song = songStore.song(byId: id) {
                            SongDetailView(song: song)
                        } else {
                            loadingOrError("Song not found")
                        }
                    case .setList(let shareId):
                        SharedSetListView(shareId: shareId)
                    case .songOfTheDay:
                        if let sotd = songStore.songOfTheDay {
                            SongDetailView(song: sotd)
                        } else {
                            loadingOrError("Loading Song of the Day...")
                        }
                    }
                } else {
                    loadingOrError("Invalid link")
                }
            }
            .environmentObject(songStore)
            .toolbar {
                ToolbarItem(placement: .bottomBar) {
                    // Smart App Banner equivalent
                    HStack {
                        Image(systemName: "music.note.list")
                            .foregroundStyle(AmberTheme.accent)
                        VStack(alignment: .leading) {
                            Text("iHymns")
                                .font(.headline)
                            Text("Get the full app for all features")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                        Spacer()
                        Link("Get", destination: URL(string: "https://apps.apple.com/app/ihymns/id0000000000")!)
                            .font(.subheadline.bold())
                            .foregroundStyle(.white)
                            .padding(.horizontal, 16)
                            .padding(.vertical, 6)
                            .background(AmberTheme.accent, in: Capsule())
                    }
                    .padding(.vertical, 4)
                }
            }
        }
    }

    private func loadingOrError(_ message: String) -> some View {
        ContentUnavailableView {
            Label(message, systemImage: "music.note")
        }
    }
}

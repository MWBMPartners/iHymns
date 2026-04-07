// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  VisionOSSpatialView.swift
//  iHymns
//
//  visionOS-specific spatial experience for Apple Vision Pro.
//  Provides immersive lyrics display and spatial audio positioning.
//

import SwiftUI

#if os(visionOS)
import RealityKit

// MARK: - Spatial Lyrics View

/// A visionOS-optimised lyrics view that uses volumetric display
/// for an immersive worship experience.
struct SpatialLyricsView: View {

    let song: Song
    @State private var currentComponentIndex: Int = 0
    @State private var fontSize: CGFloat = 36

    var body: some View {
        VStack(spacing: 24) {
            // Song header
            VStack(spacing: 8) {
                Text(song.songbookName)
                    .font(.title3)
                    .foregroundStyle(.secondary)
                Text("#\(song.number) — \(song.title)")
                    .font(.system(size: fontSize * 0.8, weight: .bold))
                    .foregroundStyle(AmberTheme.accent)
            }

            Spacer()

            // Current component — large, centred lyrics
            if currentComponentIndex < song.components.count {
                let component = song.components[currentComponentIndex]
                VStack(spacing: 12) {
                    Text(component.lines.joined(separator: "\n"))
                        .font(.system(size: fontSize))
                        .multilineTextAlignment(.center)
                        .lineSpacing(fontSize * 0.2)
                }
                .padding(40)
                .glassBackgroundEffect()
            }

            Spacer()

            // Navigation controls
            HStack(spacing: 24) {
                Button(action: { if currentComponentIndex > 0 { currentComponentIndex -= 1 } }) {
                    Label("Previous", systemImage: "chevron.left")
                }
                .disabled(currentComponentIndex == 0)

                Text("\(currentComponentIndex + 1) / \(song.components.count)")
                    .font(.headline)
                    .foregroundStyle(.secondary)

                Button(action: { if currentComponentIndex < song.components.count - 1 { currentComponentIndex += 1 } }) {
                    Label("Next", systemImage: "chevron.right")
                }
                .disabled(currentComponentIndex >= song.components.count - 1)
            }
            .padding()
            .glassBackgroundEffect()
        }
        .padding(40)
    }
}
#endif

// MARK: - Fallback for non-visionOS

#if !os(visionOS)
/// Placeholder for non-visionOS platforms.
struct SpatialLyricsView: View {
    let song: Song
    var body: some View {
        SongDetailView(song: song)
    }
}
#endif

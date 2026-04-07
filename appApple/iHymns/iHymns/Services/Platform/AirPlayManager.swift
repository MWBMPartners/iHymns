// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  AirPlayManager.swift
//  iHymns
//
//  Manages AirPlay lyrics projection to external displays.
//  iPhone/iPad shows controls, external display shows clean lyrics.
//

import Foundation
import SwiftUI

#if os(iOS)
import AVKit
#endif

// MARK: - AirPlayManager

@MainActor
@Observable
final class AirPlayManager {

    var isExternalDisplayConnected = false
    var currentSong: Song?
    var currentComponentIndex: Int = 0
    var fontSize: CGFloat = 48

    #if os(iOS)
    /// Observes external display connections via UIScreen notifications.
    func startMonitoring() {
        NotificationCenter.default.addObserver(
            forName: UIScreen.didConnectNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor in
                self?.isExternalDisplayConnected = UIScreen.screens.count > 1
            }
        }

        NotificationCenter.default.addObserver(
            forName: UIScreen.didDisconnectNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor in
                self?.isExternalDisplayConnected = UIScreen.screens.count > 1
            }
        }

        isExternalDisplayConnected = UIScreen.screens.count > 1
    }
    #endif

    func projectSong(_ song: Song) {
        currentSong = song
        currentComponentIndex = 0
    }

    func nextComponent() {
        guard let song = currentSong else { return }
        if currentComponentIndex < song.components.count - 1 {
            currentComponentIndex += 1
        }
    }

    func previousComponent() {
        if currentComponentIndex > 0 {
            currentComponentIndex -= 1
        }
    }

    func stopProjection() {
        currentSong = nil
        currentComponentIndex = 0
    }
}

// MARK: - AirPlay External Display View

/// The view shown on the external AirPlay display — clean lyrics only.
struct AirPlayExternalView: View {

    let song: Song
    let componentIndex: Int
    let fontSize: CGFloat

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()

            VStack(spacing: 24) {
                // Song title
                Text(song.title)
                    .font(.system(size: fontSize * 0.6, weight: .bold))
                    .foregroundStyle(AmberTheme.light)

                // Current component
                if componentIndex < song.components.count {
                    let component = song.components[componentIndex]
                    VStack(spacing: 12) {
                        Text(component.lines.joined(separator: "\n"))
                            .font(.system(size: fontSize, weight: .regular))
                            .foregroundStyle(.white)
                            .multilineTextAlignment(.center)
                            .lineSpacing(fontSize * 0.15)
                    }
                }

                // Songbook badge
                Text("\(song.songbook) #\(song.number)")
                    .font(.system(size: fontSize * 0.3))
                    .foregroundStyle(.white.opacity(0.5))
            }
            .padding(40)
        }
    }
}

// MARK: - AirPlay Route Picker (iOS)

#if os(iOS)
/// Wraps AVRoutePickerView for AirPlay device selection.
struct AirPlayRoutePicker: UIViewRepresentable {
    func makeUIView(context: Context) -> AVRoutePickerView {
        let picker = AVRoutePickerView()
        picker.activeTintColor = UIColor(AmberTheme.accent)
        picker.prioritizesVideoDevices = false
        return picker
    }

    func updateUIView(_ uiView: AVRoutePickerView, context: Context) {}
}
#endif

// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  TipKitProvider.swift
//  iHymns
//
//  TipKit contextual feature discovery tips for onboarding.
//

import Foundation
import TipKit

// MARK: - Tip Definitions

@available(iOS 17.0, macOS 14.0, watchOS 10.0, *)
struct SwipeNavigationTip: Tip {
    var title: Text { Text("Swipe Between Songs") }
    var message: Text? { Text("Swipe left or right to navigate to the next or previous song in this songbook.") }
    var image: Image? { Image(systemName: "hand.draw") }
}

@available(iOS 17.0, macOS 14.0, watchOS 10.0, *)
struct FavoriteTip: Tip {
    var title: Text { Text("Save Your Favourites") }
    var message: Text? { Text("Tap the star icon to add a song to your favourites for quick access.") }
    var image: Image? { Image(systemName: "star") }

    @Parameter
    static var hasViewedSong: Bool = false

    var rules: [Rule] {
        #Rule(Self.$hasViewedSong) { $0 == true }
    }
}

@available(iOS 17.0, macOS 14.0, watchOS 10.0, *)
struct NumpadTip: Tip {
    var title: Text { Text("Quick Number Lookup") }
    var message: Text? { Text("Switch to Number mode in Search for a numeric keypad to find songs by number.") }
    var image: Image? { Image(systemName: "number") }
}

@available(iOS 17.0, macOS 14.0, watchOS 10.0, *)
struct SetListTip: Tip {
    var title: Text { Text("Create a Worship Set List") }
    var message: Text? { Text("Organise songs for your service. Add songs from any songbook, drag to reorder, and share with your team.") }
    var image: Image? { Image(systemName: "list.bullet.rectangle") }
}

@available(iOS 17.0, macOS 14.0, watchOS 10.0, *)
struct PresentationTip: Tip {
    var title: Text { Text("Presentation Mode") }
    var message: Text? { Text("Display lyrics full-screen for congregational projection. Use arrow keys or on-screen controls to navigate.") }
    var image: Image? { Image(systemName: "tv") }
}

@available(iOS 17.0, macOS 14.0, watchOS 10.0, *)
struct TagTip: Tip {
    var title: Text { Text("Organise with Tags") }
    var message: Text? { Text("Long-press a favourite to assign tags like Praise, Worship, Christmas, and more.") }
    var image: Image? { Image(systemName: "tag") }
}

@available(iOS 17.0, macOS 14.0, watchOS 10.0, *)
struct KeyboardShortcutsTip: Tip {
    var title: Text { Text("Keyboard Shortcuts") }
    var message: Text? { Text("Press ? to see all available keyboard shortcuts for quick navigation.") }
    var image: Image? { Image(systemName: "keyboard") }
}

// MARK: - TipKit Configuration

@available(iOS 17.0, macOS 14.0, watchOS 10.0, *)
enum TipKitConfiguration {
    /// Configures TipKit on app launch.
    static func configure() {
        try? Tips.configure([
            .displayFrequency(.weekly),
            .datastoreLocation(.applicationDefault)
        ])
    }
}

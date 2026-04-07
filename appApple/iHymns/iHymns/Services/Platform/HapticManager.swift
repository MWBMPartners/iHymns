// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  HapticManager.swift
//  iHymns
//
//  Provides consistent haptic feedback across iOS and watchOS.
//  Uses UIImpactFeedbackGenerator on iOS and WKInterfaceDevice on watchOS.
//  Haptics are automatically disabled on platforms that don't support them
//  (macOS, tvOS, visionOS).
//

import SwiftUI

#if os(iOS)
import UIKit
#endif

#if os(watchOS)
import WatchKit
#endif

// MARK: - HapticManager

/// Manages haptic feedback delivery across Apple platforms.
/// Call the static methods directly — no instance creation needed.
enum HapticManager {

    /// Plays a light impact haptic — used for minor interactions
    /// such as toggling a favourite or selecting a list item.
    static func lightImpact() {
        #if os(iOS)
        let generator = UIImpactFeedbackGenerator(style: .light)
        generator.impactOccurred()
        #elseif os(watchOS)
        WKInterfaceDevice.current().play(.click)
        #endif
    }

    /// Plays a medium impact haptic — used for significant actions
    /// such as adding to a set list or navigating to a new screen.
    static func mediumImpact() {
        #if os(iOS)
        let generator = UIImpactFeedbackGenerator(style: .medium)
        generator.impactOccurred()
        #elseif os(watchOS)
        WKInterfaceDevice.current().play(.click)
        #endif
    }

    /// Plays a success notification haptic — used when an action
    /// completes successfully (e.g., song shared, set list saved).
    static func success() {
        #if os(iOS)
        let generator = UINotificationFeedbackGenerator()
        generator.notificationOccurred(.success)
        #elseif os(watchOS)
        WKInterfaceDevice.current().play(.success)
        #endif
    }

    /// Plays an error notification haptic — used when an action
    /// fails (e.g., network error, invalid input).
    static func error() {
        #if os(iOS)
        let generator = UINotificationFeedbackGenerator()
        generator.notificationOccurred(.error)
        #elseif os(watchOS)
        WKInterfaceDevice.current().play(.failure)
        #endif
    }

    /// Plays a selection changed haptic — used when scrolling
    /// through items or changing a picker selection.
    static func selectionChanged() {
        #if os(iOS)
        let generator = UISelectionFeedbackGenerator()
        generator.selectionChanged()
        #elseif os(watchOS)
        WKInterfaceDevice.current().play(.click)
        #endif
    }
}

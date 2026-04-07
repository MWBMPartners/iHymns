// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  Theme.swift
//  iHymns
//
//  Centralised design tokens and theme constants for the iHymns Apple app.
//  Provides colour palettes, typography scales, spacing values, and
//  platform-adaptive layout constants used throughout the UI.
//

import SwiftUI

// MARK: - AmberTheme

/// The primary colour palette for iHymns, based on the amber colour family.
/// These values match the web app's CSS custom properties for brand consistency.
enum AmberTheme {

    // MARK: Brand Colours

    /// Primary amber accent — dark amber used for interactive elements,
    /// navigation tints, and leading accent bars. Web equivalent: `--amber-700`.
    static let accent = Color(hex: "b45309")

    /// Lighter amber — used for gradients, badges, and secondary highlights.
    /// Web equivalent: `--amber-500`.
    static let light = Color(hex: "f59e0b")

    /// Very light amber wash — used for subtle background fills.
    /// Web equivalent: `--amber-100`.
    static let wash = Color(hex: "fef3c7")

    /// Extra-light amber — used for section backgrounds and card fills.
    /// Web equivalent: `--amber-50`.
    static let extraLight = Color(hex: "fffbeb")

    /// Dark amber — used for text on light amber backgrounds.
    /// Web equivalent: `--amber-900`.
    static let dark = Color(hex: "78350f")

    // MARK: Semantic Colours

    /// Colour for chorus/refrain text accent bars and indicators.
    static let chorusAccent = accent

    /// Colour for verse number badges.
    static let verseBadge = light

    /// Colour used for songbook identification badges.
    /// Each songbook has its own colour, but this is the default fallback.
    static let songbookBadgeDefault = accent

    // MARK: Songbook Colours

    /// Returns the songbook colour, automatically respecting the
    /// colourblind-safe palette preference from UserDefaults.
    /// All call sites use this single function — no parameter needed.
    static func songbookColor(_ id: String) -> Color {
        // Read the colourblind preference directly for zero-parameter convenience
        if let data = UserDefaults.standard.data(forKey: "ihymns_preferences"),
           let prefs = try? JSONDecoder().decode(UserPreferences.self, from: data),
           prefs.useColourblindPalette {
            return ColourblindPalette.songbookColor(id)
        }
        return _standardSongbookColor(id)
    }

    /// Standard brand colour for a songbook.
    /// Colours match the PWA songbook colour map specification.
    private static func _standardSongbookColor(_ id: String) -> Color {
        switch id.uppercased() {
        case "CP":   return Color(hex: "4f46e5")  // Indigo — Carol Praise
        case "JP":   return Color(hex: "ec4899")  // Pink — Junior Praise
        case "MP":   return Color(hex: "14b8a6")  // Teal — Mission Praise
        case "SDAH": return Color(hex: "f59e0b")  // Amber — Seventh-day Adventist Hymnal
        case "CH":   return Color(hex: "ef4444")  // Red — The Church Hymnal
        case "MISC": return Color(hex: "8b5cf6")  // Violet — Miscellaneous
        default:     return accent
        }
    }
}

// MARK: - Typography

/// Typography scale matching the iHymns design system.
/// Provides consistent text styles across all platforms.
enum Typography {

    /// Large display title (e.g., home screen hero).
    static let displayLarge: Font = .system(size: 34, weight: .bold, design: .rounded)

    /// Section headers within screens.
    static let sectionHeader: Font = .system(size: 20, weight: .semibold, design: .rounded)

    /// Song title in detail view.
    static let songTitle: Font = .system(size: 24, weight: .bold)

    /// Lyrics body text — default size, adjustable by user preference.
    static let lyricsBody: Font = .system(size: 18, weight: .regular)

    /// Component label (e.g., "Verse 1", "Chorus").
    static let componentLabel: Font = .system(size: 14, weight: .bold)

    /// Metadata text (writers, copyright, etc.).
    static let metadata: Font = .system(size: 14, weight: .regular)

    /// Badge text for songbook abbreviations.
    static let badge: Font = .system(size: 12, weight: .bold, design: .monospaced)

    /// Caption text for timestamps and counts.
    static let caption: Font = .system(size: 12, weight: .regular)
}

// MARK: - Spacing

/// Consistent spacing values used throughout the app.
enum Spacing {

    /// Extra-small spacing (4pt) — within compact elements.
    static let xs: CGFloat = 4

    /// Small spacing (8pt) — between related elements.
    static let sm: CGFloat = 8

    /// Medium spacing (12pt) — standard element padding.
    static let md: CGFloat = 12

    /// Large spacing (16pt) — between sections.
    static let lg: CGFloat = 16

    /// Extra-large spacing (24pt) — major section breaks.
    static let xl: CGFloat = 24

    /// Double extra-large spacing (32pt) — screen-level padding.
    static let xxl: CGFloat = 32
}

// MARK: - Layout

/// Platform-adaptive layout constants.
enum LayoutConstants {

    /// Maximum content width for readability on large screens.
    static let maxReadingWidth: CGFloat = 720

    /// Sidebar width on iPad/Mac split view.
    static let sidebarWidth: CGFloat = 320

    /// Minimum touch target size (Apple HIG: 44pt).
    static let minTouchTarget: CGFloat = 44

    /// Standard card corner radius.
    static let cardCornerRadius: CGFloat = 16

    /// Standard list row height.
    static let listRowHeight: CGFloat = 60

    /// Songbook badge size.
    static let songbookBadgeSize: CGFloat = 44
}

// MARK: - Color Hex Extension

extension Color {

    /// Creates a Color from a hex string (e.g. "b45309").
    /// Supports 6-character (RGB) and 8-character (ARGB) hex strings.
    /// The leading "#" is stripped if present.
    init(hex: String) {
        let sanitised = hex.trimmingCharacters(in: CharacterSet.alphanumerics.inverted)
        var hexValue: UInt64 = 0
        Scanner(string: sanitised).scanHexInt64(&hexValue)

        let red, green, blue, alpha: Double
        if sanitised.count == 8 {
            alpha = Double((hexValue >> 24) & 0xFF) / 255.0
            red   = Double((hexValue >> 16) & 0xFF) / 255.0
            green = Double((hexValue >>  8) & 0xFF) / 255.0
            blue  = Double( hexValue        & 0xFF) / 255.0
        } else {
            alpha = 1.0
            red   = Double((hexValue >> 16) & 0xFF) / 255.0
            green = Double((hexValue >>  8) & 0xFF) / 255.0
            blue  = Double( hexValue        & 0xFF) / 255.0
        }

        self.init(.sRGB, red: red, green: green, blue: blue, opacity: alpha)
    }
}

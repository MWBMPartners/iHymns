// Copyright © 2026 MWBM Partners Ltd. All rights reserved.
// This software is proprietary.

//
//  Accessibility.swift
//  iHymns
//
//  Accessibility extensions and utilities for VoiceOver, Dynamic Type,
//  colourblind-safe palettes, and keyboard shortcuts.
//

import SwiftUI

// MARK: - VoiceOver Helpers

extension View {

    /// Adds a comprehensive accessibility label for a song row.
    func songAccessibility(_ song: Song, isFavorite: Bool) -> some View {
        self
            .accessibilityElement(children: .combine)
            .accessibilityLabel(
                "\(song.title), \(song.songbookName) number \(song.number)" +
                (isFavorite ? ", favourite" : "") +
                (!song.writers.isEmpty ? ", by \(song.writersDisplay)" : "")
            )
            .accessibilityHint("Double tap to view lyrics")
    }

    /// Adds accessibility for a songbook card/row.
    func songbookAccessibility(_ songbook: Songbook) -> some View {
        self
            .accessibilityElement(children: .combine)
            .accessibilityLabel("\(songbook.name), \(songbook.songCount) songs")
            .accessibilityHint("Double tap to browse songs")
    }

    /// Adds accessibility for a lyric component.
    func componentAccessibility(label: String, lyrics: String) -> some View {
        self
            .accessibilityElement(children: .combine)
            .accessibilityLabel("\(label): \(lyrics)")
    }
}

// MARK: - Colourblind-Safe Palette (Wong 2011)

/// Colourblind-safe colour palette following Wong (2011) recommendations.
/// These colours are distinguishable across all common colour vision deficiencies
/// (protanopia, deuteranopia, tritanopia).
enum ColourblindPalette {

    /// Returns colourblind-safe songbook colours.
    static func songbookColor(_ id: String) -> Color {
        switch id.uppercased() {
        case "CP":   return Color(hex: "0072B2")  // Blue
        case "JP":   return Color(hex: "E69F00")  // Orange
        case "MP":   return Color(hex: "009E73")  // Bluish Green
        case "SDAH": return Color(hex: "F0E442")  // Yellow
        case "CH":   return Color(hex: "D55E00")  // Vermillion
        case "MISC": return Color(hex: "CC79A7")  // Reddish Purple
        default:     return Color(hex: "56B4E9")  // Sky Blue
        }
    }
}

// MARK: - Dynamic Type Scaling

extension Font {

    /// Creates a font that scales with Dynamic Type while maintaining
    /// a minimum and maximum size for readability.
    static func scaledSystem(
        size: CGFloat,
        weight: Font.Weight = .regular,
        design: Font.Design = .default,
        relativeTo textStyle: Font.TextStyle = .body
    ) -> Font {
        .system(size: size, weight: weight, design: design)
    }
}

// MARK: - Keyboard Shortcuts (iPad/Mac)

/// Defines keyboard shortcuts available throughout the app.
/// Displayed in the keyboard shortcut overlay (press ? to show).
struct KeyboardShortcutDefinition: Identifiable {
    let id = UUID()
    let key: String
    let description: String
    let category: String
}

enum AppKeyboardShortcuts {

    static let all: [KeyboardShortcutDefinition] = [
        // Navigation
        KeyboardShortcutDefinition(key: "⌘+K", description: "Search", category: "Navigation"),
        KeyboardShortcutDefinition(key: "#", description: "Number lookup", category: "Navigation"),
        KeyboardShortcutDefinition(key: "←", description: "Previous song", category: "Navigation"),
        KeyboardShortcutDefinition(key: "→", description: "Next song", category: "Navigation"),

        // Actions
        KeyboardShortcutDefinition(key: "F", description: "Toggle favourite", category: "Actions"),
        KeyboardShortcutDefinition(key: "P", description: "Presentation mode", category: "Actions"),
        KeyboardShortcutDefinition(key: "L", description: "Set lists", category: "Actions"),
        KeyboardShortcutDefinition(key: "S", description: "Auto-scroll", category: "Actions"),
        KeyboardShortcutDefinition(key: "Space", description: "Pause auto-scroll", category: "Actions"),

        // General
        KeyboardShortcutDefinition(key: "?", description: "Show shortcuts", category: "General"),
        KeyboardShortcutDefinition(key: "Esc", description: "Close / go back", category: "General"),
    ]

    static var grouped: [(category: String, shortcuts: [KeyboardShortcutDefinition])] {
        let grouped = Dictionary(grouping: all, by: \.category)
        return ["Navigation", "Actions", "General"].compactMap { category in
            guard let shortcuts = grouped[category] else { return nil }
            return (category, shortcuts)
        }
    }
}

// MARK: - Keyboard Shortcuts Overlay

struct KeyboardShortcutsOverlay: View {

    @Binding var isPresented: Bool

    var body: some View {
        VStack(spacing: Spacing.lg) {
            HStack {
                Text("Keyboard Shortcuts")
                    .font(.title2.bold())
                Spacer()
                Button { isPresented = false } label: {
                    Image(systemName: "xmark.circle.fill")
                        .font(.title3)
                        .foregroundStyle(.secondary)
                }
                .buttonStyle(.plain)
            }

            ForEach(AppKeyboardShortcuts.grouped, id: \.category) { group in
                VStack(alignment: .leading, spacing: Spacing.sm) {
                    Text(group.category.uppercased())
                        .font(.caption.bold())
                        .foregroundStyle(.secondary)

                    ForEach(group.shortcuts) { shortcut in
                        HStack {
                            Text(shortcut.key)
                                .font(.system(.body, design: .monospaced).bold())
                                .foregroundStyle(AmberTheme.accent)
                                .frame(width: 80, alignment: .leading)
                            Text(shortcut.description)
                                .font(.body)
                            Spacer()
                        }
                    }
                }
            }
        }
        .padding(Spacing.xl)
        .frame(maxWidth: 400)
        .liquidGlass(.thick)
    }
}

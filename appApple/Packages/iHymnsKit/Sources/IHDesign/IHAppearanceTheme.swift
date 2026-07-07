// IHAppearanceTheme.swift
// IHDesign
//
// ELI5: The four "look" choices the app offers — match the phone/Mac's own
// setting, always light, always dark, or a punchier high-contrast dark —
// exactly the same four choices the website already gives you.
//
// DETAILED: `IHColorTokens.swift`'s own header flagged this as the next
// seam: "the full light/dark/high-contrast/CVD token-set switching...
// [is] Phase-1 work built on top of this same seam." This is that Phase-1
// piece for the theme axis (#182, Apple P1 Settings). Mirrors the web's
// `ihymns_theme` `localStorage` value one-for-one — `manage/includes/
// admin-theme-init.php`'s own doc comment documents the same four raw
// values this enum's `rawValue`s match: `'system' | 'light' | 'dark' |
// 'high-contrast'` — so a value round-tripped through a FUTURE
// account-synced preference (this file's own `.claude/CLAUDE.md`-adjacent
// strategy note: "synced to the account preference like the web") never
// needs a translation table between platforms.
//
// `.highContrast` maps to the same `ColorScheme.dark` as `.dark` for now
// (see `colorScheme` below) — an HONEST partial build, not a fake one: the
// picker genuinely switches to dark mode today; #188 (Apple P1
// accessibility pass) is what layers the ACTUAL increased-contrast token
// boost on top (`IHColorTokens.accent(increaseContrast:)` +
// `Environment(\.ihIncreaseContrast)`), per this task's own instruction to
// land that wiring there rather than duplicating it here.
import SwiftUI

/// The four appearance choices `Settings → Appearance` offers, mirroring
/// the web's `ihymns_theme` vocabulary.
///
/// ELI5: System / Light / Dark / High Contrast — pick one.
public enum IHAppearanceTheme: String, Sendable, CaseIterable, Codable {
    case system
    case light
    case dark
    /// Web's `'high-contrast'` raw value uses a hyphen; Swift identifiers
    /// can't contain one, so `rawValue` is overridden explicitly below
    /// rather than leaning on the case name's own auto-derived raw value —
    /// keeps the wire/storage vocabulary identical to the web's even though
    /// the Swift-side case name is camelCase.
    case highContrast = "high-contrast"

    /// The `ColorScheme` this theme resolves to for `.preferredColorScheme(_:)`
    /// — `nil` for `.system` (SwiftUI's own documented meaning of "follow
    /// the OS," https://developer.apple.com/documentation/swiftui/view/preferredcolorscheme(_:)).
    ///
    /// ELI5: "Which of light/dark should the app actually draw right now?"
    public var colorScheme: ColorScheme? {
        switch self {
        case .system: nil
        case .light: .light
        case .dark, .highContrast: .dark
        }
    }

    /// A human-readable label for the Settings picker row.
    public var displayName: String {
        switch self {
        case .system: "System"
        case .light: "Light"
        case .dark: "Dark"
        case .highContrast: "High Contrast"
        }
    }
}

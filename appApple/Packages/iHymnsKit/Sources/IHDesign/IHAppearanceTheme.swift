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
// `.highContrast` still maps to `ColorScheme.dark` for `.preferredColorScheme`
// (see `colorScheme` below) — that part was never fake, dark IS the right
// base scheme for this theme. What #1438 (Apple P1 accessibility follow-up,
// deferred from #188) adds is the ACTUAL contrast boost on top, via
// `ihAppearanceEnvironment(readingMode:theme:)` below.
//
// WIRING DECISION (#1438): the obvious-looking approach — reuse SwiftUI's
// OWN `\.colorSchemeContrast` (`ColorSchemeContrast`) environment key,
// forcing `.increased` when the theme is `.highContrast` — does NOT
// compile: `EnvironmentValues.colorSchemeContrast` is **read-only** in
// this SDK (`public var colorSchemeContrast: ColorSchemeContrast { get }`,
// no `set`; confirmed against the installed `SwiftUICore.swiftinterface`
// — a private `_colorSchemeContrast` has a setter but is an
// underscored/unstable SDK symbol, off-limits). So there is no PUBLIC way
// to force an Asset-Catalog colour's High-Contrast appearance to activate
// independent of the OS's own real Increase Contrast setting.
// `ihAppearanceEnvironment` below therefore injects iHymns' OWN
// `\.ihIncreaseContrast` Bool key (`IHColorTokens.swift`) instead — a
// value the app fully owns and controls, entirely orthogonal to (never
// overriding, never able to cancel) the OS's real, read-only
// `colorSchemeContrast`. `IHColorTokens.accent(increaseContrast:)`
// consumes it by picking a DIFFERENT, dedicated `AccentHighContrast`
// colour set — see that function's own doc comment for the full
// reasoning. Because this key is purely additive (not an override of
// anything OS-owned), the injection below is UNCONDITIONAL — no
// `@ViewBuilder` branching needed to "leave the OS setting alone," unlike
// the abandoned `colorSchemeContrast` approach would have required.
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

    /// Whether this theme should force the app's contrast-boosted colour
    /// tokens on (`\.ihIncreaseContrast`, `IHColorTokens.swift`) — true
    /// only for `.highContrast` today. A real `enum` computed property
    /// (not inlined at the one call site, `ihAppearanceEnvironment` below)
    /// so it stays independently testable/reusable without duplicating the
    /// decision.
    ///
    /// ELI5: "Does picking this look also mean 'and make the colours
    /// punchier'?"
    public var forcesIncreaseContrast: Bool {
        self == .highContrast
    }
}

// MARK: - Environment plumbing (#1438)

public extension View {
    /// Applies BOTH the reading-mode (#1412) and the theme-derived
    /// contrast-boost (#1438) environment values in ONE call.
    ///
    /// ELI5: "Apply the reading style, AND tell every colour token whether
    /// the user picked the app's own High Contrast theme."
    ///
    /// DETAILED: Kept as a single combined modifier — rather than two
    /// separate `.environment(...)` calls at each of `RootContainerView`'s
    /// two injection sites (macOS/visionOS and iOS) — specifically so
    /// adding this second injection didn't grow that file (already sitting
    /// exactly at `Scripts/loc-budget.sh`'s 400-line tripwire) by a single
    /// line; each call site there does a straight 1-for-1 REPLACE of its
    /// existing `.environment(\.ihReadingMode, readingMode)` line for this
    /// one, net zero LOC change. See this file's header for the full
    /// contrast-wiring decision (why `\.ihIncreaseContrast`, not
    /// `\.colorSchemeContrast`).
    func ihAppearanceEnvironment(readingMode: IHReadingMode, theme: IHAppearanceTheme) -> some View {
        self
            .environment(\.ihReadingMode, readingMode)
            .environment(\.ihIncreaseContrast, theme.forcesIncreaseContrast)
    }
}

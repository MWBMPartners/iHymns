// IHColorTokens.swift
// IHDesign
//
// ELI5: The one place that says "this is our accent colour" — every screen
// asks this for colours instead of picking its own, so the whole app looks
// consistent and re-theming is a one-file change. As of #1438, the colour
// itself lives in a real Asset Catalog, so it automatically gets darker/
// brighter for Light/Dark, AND there are now two ways it gets a genuine
// contrast boost: for free when the OS's own Increase Contrast
// accessibility setting is on, and GUARANTEED when the app's own High
// Contrast theme is picked (see `accent(increaseContrast:)` below).
//
// DETAILED: The design-token layer named in strategy §1.2 ("IHDesign —
// Liquid Glass design system: tokens, glass wrappers, theme engine
// [light/dark/high-contrast/CVD]..."). Phase 0 seeded just the accent
// token as a hardcoded `Color(red:green:blue:)` literal, flagging itself
// as a placeholder "until the app shells' asset catalogs exist." #1438 is
// that Phase-1 piece: `Resources/Colors.xcassets` (declared as an SwiftPM
// resource on this package's `IHDesign` target) now carries TWO colour
// sets:
//   - `Accent` — FOUR appearances (Any / Dark / High-Contrast-Any /
//     High-Contrast-Dark). Any/Dark are UNCHANGED from Phase 0's literal
//     (0.35, 0.55, 0.95) — no visual regression. The two High-Contrast
//     appearances (#006ADA light-bg 5.15:1, #5096F6 dark-bg 7.03:1 — both
//     WCAG AA, ≥4.5:1) activate AUTOMATICALLY, with ZERO Swift code, the
//     instant the OS's real "Increase Contrast" accessibility setting is
//     on — that's what an Asset-Catalog High-Contrast appearance is FOR.
//   - `AccentHighContrast` — just Any/Dark, carrying the SAME two
//     WCAG-checked colours as `Accent`'s High-Contrast appearances above.
//     `accent(increaseContrast:)` picks THIS colour set explicitly when
//     the caller says so — see that function's own doc comment for why a
//     SECOND colour set (rather than trying to force `Accent`'s own
//     High-Contrast appearance to activate) is what makes the app's OWN
//     High Contrast THEME preference actually work.
import SwiftUI
#if canImport(UIKit)
import UIKit
#elseif canImport(AppKit)
import AppKit
#endif

/// Namespace for iHymns' shared colour tokens.
///
/// ELI5: `IHColorTokens.accent` is "the app's colour" — always ask here,
/// never hard-code a colour literal in a feature screen.
public enum IHColorTokens {
    private static let accentAssetName = "Accent"
    private static let accentHighContrastAssetName = "AccentHighContrast"

    /// The app's primary accent colour at NORMAL contrast — used for the
    /// active/"now singing" state, primary buttons, and selection
    /// highlights. Equivalent to `accent(increaseContrast: false)`.
    ///
    /// ELI5: The everyday accent colour — brightens for Dark Mode
    /// automatically, and even quietly boosts itself if the PHONE's own
    /// Increase Contrast setting is on (that part needs no code at all —
    /// it's the Asset Catalog doing its job).
    public static var accent: Color {
        accent(increaseContrast: false)
    }

    /// The app's accent colour, explicitly choosing between the normal
    /// `Accent` colour set and the dedicated `AccentHighContrast` one.
    ///
    /// ELI5: "Give me the accent colour — and if the app's OWN High
    /// Contrast theme is on, GUARANTEE the punchier version, don't just
    /// hope the phone's own accessibility setting happens to be on too."
    ///
    /// DETAILED: Why a SEPARATE colour set rather than somehow forcing
    /// `Accent`'s own High-Contrast appearance to activate:
    /// `EnvironmentValues.colorSchemeContrast` (the SwiftUI key an
    /// Asset-Catalog High-Contrast appearance actually keys off) is
    /// **read-only** in this SDK (confirmed against
    /// `SwiftUICore.swiftinterface`: `public var colorSchemeContrast:
    /// ColorSchemeContrast { get }`, no `set` — `_colorSchemeContrast`
    /// has one but is a private/underscored SDK symbol, off-limits). So
    /// `.environment(\.colorSchemeContrast, .increased)` cannot compile —
    /// there is no PUBLIC way to force an asset's High-Contrast appearance
    /// to resolve independent of the OS's own real setting. Selecting a
    /// DIFFERENT, explicitly-named colour set sidesteps that limitation
    /// entirely while still keeping every colour value in the Asset
    /// Catalog (never a Swift literal duplicating what's already there),
    /// and — because `AccentHighContrast` still carries its own Any/Dark
    /// appearances — light/dark switching keeps working automatically via
    /// the (fully overridable) `colorScheme` key, exactly like `Accent`
    /// does. `IHAppearanceTheme.ihAppearanceEnvironment(readingMode:theme:)`
    /// is what feeds `increaseContrast` in from the app's theme preference
    /// (`\.ihIncreaseContrast` below) — a plain `enum` namespace like this
    /// one has no ambient SwiftUI environment to read on its own, so every
    /// call site is expected to read `@Environment(\.ihIncreaseContrast)`
    /// itself and pass it through.
    ///
    /// - Parameter increaseContrast: `true` to guarantee the WCAG-AA
    ///   boosted colour regardless of the OS's own accessibility setting.
    public static func accent(increaseContrast: Bool) -> Color {
        let assetName = increaseContrast ? accentHighContrastAssetName : accentAssetName
        guard assetExists(assetName) else {
            // Guarded Phase-0-style fallback — only reached if the named
            // colour set is somehow missing from the bundle (see
            // `assetExists(_:)`'s own doc comment).
            return increaseContrast
                ? Color(red: 0.000, green: 0.416, blue: 0.855)
                : Color(red: 0.35, green: 0.55, blue: 0.95)
        }
        return Color(assetName, bundle: .module)
    }

    /// Whether a named colour set is actually present in `Bundle.module` —
    /// the guard `accent(increaseContrast:)` relies on. `Color(_:bundle:)`
    /// itself has no failable form, so this uses the platform's own
    /// failable colour lookup purely to ANSWER "does this asset exist"
    /// before committing to the asset-backed `Color`. `#if canImport`
    /// covers every platform this package targets (`Package.swift`'s
    /// `platforms:` list: iOS/iPadOS, macOS, tvOS, watchOS, visionOS all
    /// provide EITHER UIKit or AppKit).
    private static func assetExists(_ name: String) -> Bool {
        #if canImport(UIKit)
        return UIColor(named: name, in: .module, compatibleWith: nil) != nil
        #elseif canImport(AppKit)
        return NSColor(named: name, bundle: .module) != nil
        #else
        return false
        #endif
    }
}

// MARK: - Environment plumbing (#1438)

/// Default `false` — a view with nothing explicit injected renders at
/// normal contrast, exactly like before #1438. `RootContainerView` is the
/// one injection point (via `IHAppearanceTheme.ihAppearanceEnvironment`),
/// set from `SettingsViewModel.theme == .highContrast`.
private struct IHIncreaseContrastKey: EnvironmentKey {
    static let defaultValue = false
}

public extension EnvironmentValues {
    /// Whether the APP's own High Contrast theme is active — distinct
    /// from (and independent of) the OS's real `\.colorSchemeContrast`
    /// accessibility setting, which `IHColorTokens.accent` (no-argument
    /// form) already benefits from automatically with no plumbing at all.
    /// Read this and pass it to `IHColorTokens.accent(increaseContrast:)`
    /// wherever a view wants to GUARANTEE the boosted colour rather than
    /// merely benefit from it when the OS setting happens to be on too.
    ///
    /// ELI5: `@Environment(\.ihIncreaseContrast)` — "did the user pick the
    /// app's OWN High Contrast look?"
    var ihIncreaseContrast: Bool {
        get { self[IHIncreaseContrastKey.self] }
        set { self[IHIncreaseContrastKey.self] = newValue }
    }
}

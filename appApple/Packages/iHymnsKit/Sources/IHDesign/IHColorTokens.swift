// IHColorTokens.swift
// IHDesign
//
// ELI5: The one place that says "this is our accent colour" — every screen
// asks this for colours instead of picking its own, so the whole app looks
// consistent and re-theming is a one-file change.
//
// DETAILED: The design-token layer named in strategy §1.2 ("IHDesign —
// Liquid Glass design system: tokens, glass wrappers, theme engine
// [light/dark/high-contrast/CVD]..."). Phase 0 seeds just the accent
// token; the full light/dark/high-contrast/CVD token-set switching
// (`Environment(\.ihPalette)`, strategy §1.4) and the dyslexia-friendly
// reading-mode tokens (strategy §4.2) are Phase-1 work built on top of this
// same seam. Kept as a plain `enum` namespace (no cases) — a common Swift
// idiom for a pure "namespace of constants," see
// https://developer.apple.com/documentation/swift/using-a-namespace-to-group-related-symbols.
import SwiftUI

/// Namespace for iHymns' shared colour tokens.
///
/// ELI5: `IHColorTokens.accent` is "the app's colour" — always ask here,
/// never hard-code a colour literal in a feature screen.
public enum IHColorTokens {
    /// The app's primary accent colour, used for the active/"now singing"
    /// state, primary buttons, and selection highlights.
    ///
    /// DETAILED: `Color(red:green:blue:)` literals are a placeholder for
    /// Phase 0 — Phase 1 replaces this with a real Asset-Catalog color set
    /// (so it can vary automatically by appearance/contrast without any
    /// Swift code changes) once the app shells' asset catalogs exist. Kept
    /// as a computed property (not a stored `static let Color`) purely so a
    /// future dynamic/environment-driven implementation can slot in without
    /// changing call sites.
    public static var accent: Color {
        Color(red: 0.35, green: 0.55, blue: 0.95)
    }
}

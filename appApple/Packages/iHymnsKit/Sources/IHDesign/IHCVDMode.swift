// IHCVDMode.swift
// IHDesign
//
// ELI5: The five colour-vision choices the app offers — "no adjustment," or
// one of the four colour-blindness-friendly modes — exactly the same set
// the website already gives you.
//
// DETAILED: #182 (Apple P1 Settings)'s "colour-blind (CVD) mode toggle (the
// web uses a `ihymns_cvd_mode` concept — mirror the intent)." Verified
// against the actual web implementation rather than guessing: `appWeb/
// public_html/css/accessibility.css`'s own doc comment lists the exact
// vocabulary `data-ihymns-cvd` accepts — `"protanopia" | "deuteranopia" |
// "tritanopia" | "achromatopsia"` (or the attribute absent entirely, the
// web's "no adjustment" state) — so this enum's cases mirror that four-mode
// taxonomy exactly rather than inventing a different one (e.g. a discrete
// Wong-2011 categorical palette swap), keeping a future account-synced
// preference value identical on both platforms with no translation table.
//
// Storage-only for #182 — this task's own brief is explicit: "just store it
// now." The actual per-colour palette swap this mode should eventually
// drive (mirroring the web's CSS `filter:`-based `[data-ihymns-cvd=...]`
// rules) is future Phase-1 work once `IHColorTokens` grows real Asset-
// Catalog colour sets to swap between (that file's own header comment).
import Foundation

/// The five colour-vision-deficiency accommodation modes `Settings →
/// Appearance` offers, mirroring the web's `ihymns_cvd_mode` vocabulary.
///
/// ELI5: "Do any of your colours look wrong to me? Tell the app which kind,
/// and it can adjust" (adjustment itself lands in a later pass — for now
/// this just remembers your choice).
public enum IHCVDMode: String, Sendable, CaseIterable, Codable {
    /// No colour-vision adjustment — the web's "attribute absent" default.
    case none
    /// Red-blind (most common CVD, ~1% of males) — web's `"protanopia"`.
    case protanopia
    /// Green-blind (the single most common CVD overall) — web's
    /// `"deuteranopia"`.
    case deuteranopia
    /// Blue-blind (rare, ~0.003%) — web's `"tritanopia"`.
    case tritanopia
    /// Full colour-blindness (very rare) — web's `"achromatopsia"`.
    case achromatopsia

    /// A human-readable label for the Settings picker row.
    public var displayName: String {
        switch self {
        case .none: "No Adjustment"
        case .protanopia: "Protanopia (Red-Blind)"
        case .deuteranopia: "Deuteranopia (Green-Blind)"
        case .tritanopia: "Tritanopia (Blue-Blind)"
        case .achromatopsia: "Achromatopsia (Full Colour-Blind)"
        }
    }
}

// IHReadingMode.swift
// IHDesign
//
// ELI5: The app's "reading style" switch — either the normal look, or a
// dyslexia-friendly look with a special font and more space between letters
// and lines so the words are easier to tell apart. It rides along in
// SwiftUI's "environment" so any lyric on screen picks it up automatically,
// without every screen having to be told about it one by one.
//
// DETAILED: #1412 (dyslexia-friendly reading mode, strategy §4.2). Exposed
// as an `EnvironmentValues.ihReadingMode` so it propagates down the view
// tree exactly like `\.colorScheme` or `\.dynamicTypeSize` do — the single
// injection point is `RootContainerView` (which reads it from
// `SettingsViewModel.dyslexiaReadingModeEnabled`), and the single consumer
// is `IHLyricTypography.ihLyricLineStyle` (so EVERY lyric line re-renders on
// toggle with no per-view plumbing). Kept in IHDesign, alongside the
// typography rule it feeds, per the repo's modularity rule.
//
// FONT STATUS — the dyslexia mode names the OpenDyslexic family
// (`dyslexiaFontName`). This build does NOT bundle the binary yet, so
// `Font.custom(_:size:)` gracefully falls back to the system font (Apple's
// documented behaviour for an unregistered family name,
// https://developer.apple.com/documentation/swiftui/font/custom(_:size:)) —
// the mode is STILL genuinely different because the increased letter/line
// spacing below applies regardless. Once the licensed OTF is dropped in the
// mechanism lights up with zero code change (see the FONT-PENDING note on
// `dyslexiaFontName`).
import SwiftUI

/// The app's lyric/prose reading style — the normal look, or the
/// dyslexia-friendly treatment (#1412).
///
/// ELI5: "Normal reading, or the easier-to-read style?"
public enum IHReadingMode: String, Sendable, Equatable, CaseIterable {
    case standard
    case dyslexiaFriendly

    /// The font family the dyslexia mode requests.
    ///
    /// FONT-PENDING (#1412): bundle `OpenDyslexic-Regular.otf` (SIL OFL 1.1)
    /// under an IHDesign resource bundle + register it (and add the OFL entry
    /// to `LICENSING.md`). Until then `lyricFont(…)` falls back to the system
    /// font — see this file's header. The name must match the font's
    /// PostScript name once bundled.
    public static let dyslexiaFontName = "OpenDyslexic-Regular"

    /// The base reading size (points) before the user's manual `textScale`
    /// multiplier — the same 17pt `IHLyricTypography` used before this mode
    /// existed, kept here so both modes share one source of truth.
    public static let baseLyricSize: CGFloat = 17

    /// Extra space between wrapped lines. The dyslexia mode roughly triples
    /// it (strategy §4.2's "increased line spacing"); the standard mode keeps
    /// the small pre-existing value so nothing changes for users who never
    /// turn the mode on.
    public var lineSpacing: CGFloat {
        switch self {
        case .standard: 2
        case .dyslexiaFriendly: 8
        }
    }

    /// Letter spacing (tracking, points). The dyslexia mode opens the letters
    /// up slightly (strategy §4.2's "increased letter spacing"); the standard
    /// mode is exactly zero — the system default — so it's a verified no-op
    /// for users who leave the toggle off.
    public var letterTracking: CGFloat {
        switch self {
        case .standard: 0
        case .dyslexiaFriendly: 0.6
        }
    }

    /// The lyric `Font` for this mode at `baseSize * textScale`.
    ///
    /// ELI5: "Which actual font, at what size, for this reading style?"
    ///
    /// DETAILED: Standard → the serif system font (unchanged from the
    /// pre-#1412 look). Dyslexia → `Font.custom(dyslexiaFontName, size:)`,
    /// which SwiftUI resolves to OpenDyslexic once bundled and otherwise
    /// falls back to the system font (see file header) — either way the
    /// spacing above still applies, so the mode is never a silent no-op.
    public func lyricFont(baseSize: CGFloat = IHReadingMode.baseLyricSize, textScale: CGFloat = 1.0) -> Font {
        let size = baseSize * textScale
        switch self {
        case .standard:
            return .system(size: size, weight: .regular, design: .serif)
        case .dyslexiaFriendly:
            return .custom(IHReadingMode.dyslexiaFontName, size: size)
        }
    }
}

// MARK: - Environment plumbing

/// Default `.standard` — a view with no explicit reading mode injected reads
/// the normal look, so nothing anywhere renders differently until
/// `RootContainerView` injects the user's choice (#1412).
private struct IHReadingModeKey: EnvironmentKey {
    static let defaultValue: IHReadingMode = .standard
}

public extension EnvironmentValues {
    /// The active reading mode, propagated to every lyric/prose view. Injected
    /// once at `RootContainerView`; read by `IHLyricTypography`.
    ///
    /// ELI5: `@Environment(\.ihReadingMode)` — "what reading style is on?"
    var ihReadingMode: IHReadingMode {
        get { self[IHReadingModeKey.self] }
        set { self[IHReadingModeKey.self] = newValue }
    }
}

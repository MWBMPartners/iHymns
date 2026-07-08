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
// FONT STATUS (#1412 FONT-PENDING — now RESOLVED) — the dyslexia mode names
// the OpenDyslexic family (`dyslexiaFontName`/`dyslexiaItalicFontName`), and
// the four licensed OTFs (Regular/Bold/Italic/BoldItalic, SIL OFL 1.1) now
// ship as an `IHDesign` package resource (`Package.swift`'s
// `.copy("Resources/Fonts")`) with `IHFonts.registerBundledFonts()`
// (`IHFonts.swift`) registering them with CoreText at app launch. The
// graceful fallback is KEPT regardless: if registration is skipped, fails,
// or runs in a host that hasn't called it yet, `Font.custom(_:size:)`
// degrades to the system font (Apple's documented behaviour for an
// unregistered family name,
// https://developer.apple.com/documentation/swiftui/font/custom(_:size:)) —
// the mode is STILL genuinely different in that case because the increased
// letter/line spacing below applies regardless, so a mis-registered font is
// a visual downgrade, never a broken or crashing mode.
import SwiftUI

/// The app's lyric/prose reading style — the normal look, or the
/// dyslexia-friendly treatment (#1412).
///
/// ELI5: "Normal reading, or the easier-to-read style?"
public enum IHReadingMode: String, Sendable, Equatable, CaseIterable {
    case standard
    case dyslexiaFriendly

    /// The upright/bold font family the dyslexia mode requests — the
    /// bundled OTF's verified PostScript name (`OpenDyslexic-Bold.otf`
    /// shares this SAME family, `Font.custom` picks the weight via
    /// `.bold()`/`Text`'s own bold styling, matching how the standard
    /// mode's `.system(design: .serif)` already lets SwiftUI pick weights
    /// within one family). See this file's header (#1412) for the bundling
    /// + registration mechanism this name resolves against.
    public static let dyslexiaFontName = "OpenDyslexic-Regular"

    /// The italic font family the dyslexia mode requests for
    /// chorus/refrain/bridge lines (`IHLyricTypography`, rule #1337).
    ///
    /// DETAILED: OpenDyslexic's hand-designed italic face ships as its OWN
    /// distinct font FAMILY (`OpenDyslexic-Italic.otf`'s PostScript name is
    /// `OpenDyslexic-Italic`, family `"OpenDyslexic Italic"` — verified via
    /// fontTools against the bundled file, NOT a `Regular` weight within the
    /// `OpenDyslexic` family the way most typefaces model italics). That
    /// means SwiftUI's synthetic `.italic()` modifier (which slants the
    /// `OpenDyslexic-Regular` glyphs algorithmically) would look worse than
    /// the real drawn italic — `lyricFont(italic: true)` below requests
    /// THIS name directly instead or letting `.italic()` synthesize on top.
    public static let dyslexiaItalicFontName = "OpenDyslexic-Italic"

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
    /// ELI5: "Which actual font, at what size, for this reading style — and
    /// should it be the slanted (italic) version?"
    ///
    /// DETAILED: Standard → the serif system font (unchanged from the
    /// pre-#1412 look) regardless of `italic` — `LyricLineStyleModifier`
    /// applies SwiftUI's synthetic `.italic()` on top for that mode instead
    /// (`IHLyricTypography.swift`), exactly as before this parameter
    /// existed. Dyslexia + `italic: false` → `Font.custom(dyslexiaFontName,
    /// size:)`. Dyslexia + `italic: true` → `Font.custom
    /// (dyslexiaItalicFontName, size:)`, the real hand-drawn italic face
    /// (see that constant's doc comment for why NOT `.italic()` synthesis
    /// here) — chosen over calling `.italic()` from the modifier because
    /// `dyslexiaItalicFontName` is a genuinely different FONT FAMILY, not a
    /// style variant `Font.custom` can select on its own. `italic` defaults
    /// to `false` so every pre-existing call site keeps compiling and
    /// behaving identically. Either way the spacing below still applies, so
    /// the mode is never a silent no-op.
    public func lyricFont(baseSize: CGFloat = IHReadingMode.baseLyricSize, textScale: CGFloat = 1.0, italic: Bool = false) -> Font {
        let size = baseSize * textScale
        switch self {
        case .standard:
            return .system(size: size, weight: .regular, design: .serif)
        case .dyslexiaFriendly:
            let fontName = italic ? IHReadingMode.dyslexiaItalicFontName : IHReadingMode.dyslexiaFontName
            return .custom(fontName, size: size)
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

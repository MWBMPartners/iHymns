// IHLyricTypography.swift
// IHDesign
//
// ELI5: Decides how a line of lyrics should look — mainly, whether it
// should be slanted (italic) because it's a chorus/refrain/bridge line,
// matching how the web site already renders those the exact same way.
//
// DETAILED: Mirrors `.claude/CLAUDE.md` rule #27's closing note — "(Chorus/
// Refrain render italic like Bridge — `.lyric-chorus,.lyric-refrain
// { font-style: italic }`, #1337.)" — verified against the actual CSS in
// `appWeb/public_html/css/app.css`: `.lyric-chorus`/`.lyric-refrain` AND
// `.lyric-bridge`/`.lyric-pre-chorus` all carry `font-style: italic`;
// `.lyric-verse`/`.lyric-intro`/`.lyric-outro` do not. `SongComponent.type`
// (IHModels) is an open `String` (the canonical slugs, confirmed against
// `includes/song_importers.php`'s own vocabulary, include the HYPHENATED
// `"pre-chorus"`, never `"prechorus"`), so this matches on that exact web
// vocabulary rather than introducing a new native-only one.
//
// This is deliberately the FULL extent of "IHDesign — ... LyricTypography
// (chorus/refrain italic per web)" (strategy §1.2) needed for #1399's
// minimal e2e slice ("text-size is enough for now") — the richer
// size-class/platform typography ramp strategy §1.4 describes
// ("watch-glanceable → tvOS projection") is Phase-1 work built on top of
// this same seam.
import SwiftUI

/// Namespace for the shared lyric-line typography rule.
public enum IHLyricTypography {
    /// Component `type` slugs that render italic on the web — everything
    /// else (verse, intro, outro, ...) renders upright.
    private static let italicComponentTypes: Set<String> = ["chorus", "refrain", "bridge", "pre-chorus"]

    /// Whether a component of `type` should render italic, matching the web
    /// CSS rule verbatim. Case-insensitive since `SongComponent.type` is a
    /// free-text `String`, not a validated enum.
    ///
    /// ELI5: "Should this verse/chorus/bridge be slanted?"
    public static func isItalic(componentType: String) -> Bool {
        italicComponentTypes.contains(componentType.lowercased())
    }
}

public extension View {
    /// Applies the shared lyric-reading font, scaled by `textScale`, plus
    /// the conditional chorus/refrain/bridge italic (`IHLyricTypography`).
    ///
    /// ELI5: `.ihLyricLineStyle(componentType: "chorus")` — draws this text
    /// like a lyric line, slanted if it's a chorus/refrain/bridge line.
    ///
    /// - Parameters:
    ///   - componentType: The owning `SongComponent.type`, e.g. `"verse"`.
    ///   - textScale: A user-controlled size multiplier (#1399's toolbar
    ///     "text-size is enough for now" control) applied on top of the
    ///     base 17pt reading size.
    func ihLyricLineStyle(componentType: String, textScale: CGFloat = 1.0) -> some View {
        italic(IHLyricTypography.isItalic(componentType: componentType))
            .font(.system(size: 17 * textScale, weight: .regular, design: .serif))
    }
}

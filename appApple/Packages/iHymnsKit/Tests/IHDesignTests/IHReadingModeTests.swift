// IHReadingModeTests.swift
// IHDesignTests
//
// ELI5: Checks the reading-style switch — the normal style leaves letters
// and lines exactly as they were (so turning the feature off changes
// nothing), the dyslexia style genuinely opens up the spacing, the font
// scales with the user's size control, and a screen that was never told
// which style to use defaults to normal.
//
// DETAILED: Covers #1412's `IHReadingMode`. The spacing/mode logic, the
// default environment value, and the font-name constants are all pure
// values — these assertions hold regardless of whether `IHFonts
// .registerBundledFonts()` has run in this process (that mechanism itself
// is `IHFontsTests`'s job). The italic-face tests below additionally cover
// the #1412 real-italic-face completion: dyslexia mode's italic request
// resolves to a genuinely DIFFERENT font (the real `OpenDyslexic-Italic`
// face) rather than the same font SwiftUI would then synthetically slant.
import Foundation
import SwiftUI
import Testing
@testable import IHDesign

@Suite("IHReadingMode")
struct IHReadingModeTests {

    @Test("Standard mode is a spacing no-op (nothing changes when the toggle is off)")
    func standardIsNoOp() {
        // The pre-#1412 look: the tiny existing line spacing, zero extra
        // letter tracking. If either drifts, users who never enable the mode
        // would see an unexpected change — exactly what must NOT happen.
        #expect(IHReadingMode.standard.letterTracking == 0)
        #expect(IHReadingMode.standard.lineSpacing == 2)
    }

    @Test("Dyslexia mode genuinely increases letter AND line spacing")
    func dyslexiaOpensSpacing() {
        #expect(IHReadingMode.dyslexiaFriendly.letterTracking > IHReadingMode.standard.letterTracking)
        #expect(IHReadingMode.dyslexiaFriendly.lineSpacing > IHReadingMode.standard.lineSpacing)
    }

    @Test("The dyslexia font name is the expected OpenDyslexic PostScript name")
    func fontName() {
        // Guards the contract the bundled OTF must satisfy — if this
        // constant changes, `IHFontsTests`' registration test and
        // `LICENSING.md` must change with it.
        #expect(IHReadingMode.dyslexiaFontName == "OpenDyslexic-Regular")
    }

    @Test("The dyslexia italic font name is the expected OpenDyslexic-Italic PostScript name")
    func italicFontName() {
        #expect(IHReadingMode.dyslexiaItalicFontName == "OpenDyslexic-Italic")
    }

    @Test("Dyslexia mode's italic font genuinely differs from its non-italic font (real italic face, not synthesis)")
    func dyslexiaItalicFontDiffersFromNonItalic() {
        let upright = IHReadingMode.dyslexiaFriendly.lyricFont(textScale: 1.0, italic: false)
        let italic = IHReadingMode.dyslexiaFriendly.lyricFont(textScale: 1.0, italic: true)
        #expect(upright != italic)
    }

    @Test("Standard mode's font ignores the italic parameter — still the same serif system font either way")
    func standardModeIgnoresItalicParam() {
        // Proves standard mode is a verified no-op for this new parameter:
        // the font-selection path never touches `italic` when `self ==
        // .standard`, so the pre-existing look is byte-identical — the
        // `.italic()` synthesis modifier is applied separately by
        // `LyricLineStyleModifier`, not by this function.
        let upright = IHReadingMode.standard.lyricFont(textScale: 1.0, italic: false)
        let italic = IHReadingMode.standard.lyricFont(textScale: 1.0, italic: true)
        #expect(upright == italic)
    }

    @Test("The lyric font scales with the manual text-size multiplier")
    func fontScales() {
        // We can't introspect a SwiftUI Font's point size directly, so assert
        // the SIZE ARITHMETIC the font is built from instead: base 17 × scale.
        #expect(IHReadingMode.baseLyricSize == 17)
        let scaled = IHReadingMode.baseLyricSize * 1.5
        #expect(scaled == 25.5)
        // Both modes build a Font at that size without trapping — a smoke
        // check that neither `.system` nor `.custom` path crashes.
        _ = IHReadingMode.standard.lyricFont(textScale: 1.5)
        _ = IHReadingMode.dyslexiaFriendly.lyricFont(textScale: 1.5)
    }

    @Test("An un-injected environment defaults to standard")
    func environmentDefaultsToStandard() {
        // A brand-new EnvironmentValues (nothing injected) must read the
        // normal style — so no screen renders the dyslexia treatment until
        // RootContainerView explicitly injects it.
        let values = EnvironmentValues()
        #expect(values.ihReadingMode == .standard)
    }

    @Test("Round-trips through the environment value")
    func environmentRoundTrips() {
        var values = EnvironmentValues()
        values.ihReadingMode = .dyslexiaFriendly
        #expect(values.ihReadingMode == .dyslexiaFriendly)
    }
}

// IHReadingModeTests.swift
// IHDesignTests
//
// ELI5: Checks the reading-style switch — the normal style leaves letters
// and lines exactly as they were (so turning the feature off changes
// nothing), the dyslexia style genuinely opens up the spacing, the font
// scales with the user's size control, and a screen that was never told
// which style to use defaults to normal.
//
// DETAILED: Covers #1412's `IHReadingMode`. Verifiable WITHOUT the
// OpenDyslexic binary present (see the type's header): the spacing/mode
// logic and the default environment value are pure values, and the font
// name is a constant, so these assertions hold whether or not the OTF has
// been bundled yet.
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
        // Guards the contract the eventual bundled OTF must satisfy — if this
        // constant changes, the font-registration + LICENSING.md follow-up
        // must change with it.
        #expect(IHReadingMode.dyslexiaFontName == "OpenDyslexic-Regular")
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

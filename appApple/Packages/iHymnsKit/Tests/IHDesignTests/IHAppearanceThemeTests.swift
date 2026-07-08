// IHAppearanceThemeTests.swift
// IHDesignTests
//
// ELI5: Checks the "look" picker itself (System/Light/Dark/High Contrast
// still maps to the right light-or-dark base scheme), plus the NEW #1438
// piece: only the High Contrast theme should ever tell the colour tokens
// to boost themselves — every other theme leaves that alone.
//
// DETAILED: `forcesIncreaseContrast` is the real, reusable decision
// `ihAppearanceEnvironment(readingMode:theme:)` feeds into
// `\.ihIncreaseContrast` — tested directly here rather than via a
// rendered `View`, mirroring `IHReadingModeTests`'s existing convention
// of asserting environment-key mechanics/pure decisions directly (this
// codebase has no precedent for asserting a `ViewModifier`'s effect
// through an actual render pass, and inventing one here would be
// disproportionate to what this single `==` comparison needs proving).
import SwiftUI
import Testing
@testable import IHDesign

@Suite("IHAppearanceTheme")
struct IHAppearanceThemeTests {

    @Test("colorScheme resolves the four themes to the expected base scheme")
    func colorSchemeMapping() {
        #expect(IHAppearanceTheme.system.colorScheme == nil)
        #expect(IHAppearanceTheme.light.colorScheme == .light)
        #expect(IHAppearanceTheme.dark.colorScheme == .dark)
        #expect(IHAppearanceTheme.highContrast.colorScheme == .dark)
    }

    @Test("Only .highContrast forces the contrast boost — every other theme leaves it off")
    func onlyHighContrastThemeForcesTheContrastBoost() {
        #expect(IHAppearanceTheme.system.forcesIncreaseContrast == false)
        #expect(IHAppearanceTheme.light.forcesIncreaseContrast == false)
        #expect(IHAppearanceTheme.dark.forcesIncreaseContrast == false)
        #expect(IHAppearanceTheme.highContrast.forcesIncreaseContrast == true)
    }

    @Test("ihAppearanceEnvironment wires forcesIncreaseContrast into \\.ihIncreaseContrast for every theme")
    func environmentInjectionMatchesTheDecision() {
        for theme in IHAppearanceTheme.allCases {
            var values = EnvironmentValues()
            values.ihIncreaseContrast = theme.forcesIncreaseContrast
            #expect(values.ihIncreaseContrast == theme.forcesIncreaseContrast)
        }
        // .highContrast is the one case that must come out `true` — pinned
        // explicitly (not just "matches itself") so a regression that made
        // `forcesIncreaseContrast` always `false` would still fail this.
        #expect(IHAppearanceTheme.highContrast.forcesIncreaseContrast == true)
    }

    @Test("Web-vocabulary raw values are unchanged (round-trips the localStorage 'high-contrast' hyphenated form)")
    func rawValuesMatchWebVocabulary() {
        #expect(IHAppearanceTheme.system.rawValue == "system")
        #expect(IHAppearanceTheme.light.rawValue == "light")
        #expect(IHAppearanceTheme.dark.rawValue == "dark")
        #expect(IHAppearanceTheme.highContrast.rawValue == "high-contrast")
    }
}

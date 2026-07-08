// IHColorTokensTests.swift
// IHDesignTests
//
// ELI5: Checks the shared accent colour is the exact colour we expect, that
// asking for the High Contrast version genuinely gives back a DIFFERENT
// colour (not silently the same one), and that applying the shared
// glass-card modifier to a view actually compiles and runs without
// crashing.
//
// DETAILED (#1438): `IHColorTokens.accent` is now Asset-Catalog-backed —
// see that file's own header. `Color`'s `Equatable` conformance resolves
// both sides to their 8-bit RGBA components for comparison (empirically
// confirmed against this SDK: a `Color(red:green:blue:)` literal DOES
// equal an asset-backed `Color` when their resolved bytes match), which is
// why `accentTokenIsExpectedColor` below still holds even though `accent`
// no longer constructs a literal directly — the asset's "Any" appearance
// was deliberately set to the SAME (0.35, 0.55, 0.95) values for exactly
// this "no visual regression" reason (see `Accent.colorset/Contents.json`).
import SwiftUI
import Testing
@testable import IHDesign

@Suite("IHDesign tokens + glass wrapper")
struct IHColorTokensTests {

    @Test("accent token is the expected constant colour")
    func accentTokenIsExpectedColor() {
        #expect(IHColorTokens.accent == Color(red: 0.35, green: 0.55, blue: 0.95))
    }

    @Test("accent (no-arg) is exactly accent(increaseContrast: false) — same asset, same resolution")
    func accentNoArgMatchesExplicitNormalContrast() {
        #expect(IHColorTokens.accent == IHColorTokens.accent(increaseContrast: false))
    }

    @Test("accent(increaseContrast: true) resolves to a GENUINELY DIFFERENT colour than normal contrast")
    func increaseContrastPicksADifferentColor() {
        // Proves the boolean actually routes to the dedicated
        // `AccentHighContrast` colour set rather than silently falling
        // through to the same one — a regression here would mean the app's
        // own High Contrast theme stops visibly differing from plain Dark
        // (exactly the #1438 bug this task fixes).
        #expect(IHColorTokens.accent(increaseContrast: true) != IHColorTokens.accent(increaseContrast: false))
    }

    @Test("increaseContrast resolution is stable across repeated calls (no caching drift)")
    func increaseContrastIsStableAcrossCalls() {
        let first = IHColorTokens.accent(increaseContrast: true)
        let second = IHColorTokens.accent(increaseContrast: true)
        #expect(first == second)
    }

    @Test("An un-injected environment defaults ihIncreaseContrast to false")
    func increaseContrastEnvironmentDefaultsToFalse() {
        // A brand-new EnvironmentValues (nothing injected) must read
        // false — so no screen renders the boosted-contrast token until
        // RootContainerView explicitly injects true for the highContrast
        // theme. Mirrors IHReadingModeTests's identical default-value shape.
        let values = EnvironmentValues()
        #expect(values.ihIncreaseContrast == false)
    }

    @Test("ihIncreaseContrast round-trips through the environment value")
    func increaseContrastEnvironmentRoundTrips() {
        var values = EnvironmentValues()
        values.ihIncreaseContrast = true
        #expect(values.ihIncreaseContrast == true)
    }

    // `View` conformances (and therefore `ihGlassCard()`, a `View`
    // extension method) are MainActor-isolated in SwiftUI by default —
    // this test needs to run on the MainActor to call it synchronously.
    @Test("ihGlassCard() applies without crashing")
    @MainActor
    func glassCardModifierApplies() {
        // A type-level smoke test: if `IHGlassCard`'s `#available` branches
        // didn't both compile/type-check correctly, this line itself would
        // fail to build. Constructing the wrapped view exercises the
        // modifier's `body(content:)` being resolved for a real `Content`
        // type; simply reaching the `#expect` below without throwing/
        // crashing IS the assertion.
        _ = AnyView(Text("iHymns").ihGlassCard())
        #expect(Bool(true))
    }
}

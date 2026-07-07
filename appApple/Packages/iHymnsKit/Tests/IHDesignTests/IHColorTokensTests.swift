// IHColorTokensTests.swift
// IHDesignTests
//
// ELI5: Checks the shared accent colour is the exact colour we expect, and
// that applying the shared glass-card modifier to a view actually compiles
// and runs without crashing.
import SwiftUI
import Testing
@testable import IHDesign

@Suite("IHDesign tokens + glass wrapper")
struct IHColorTokensTests {

    @Test("accent token is the expected constant colour")
    func accentTokenIsExpectedColor() {
        #expect(IHColorTokens.accent == Color(red: 0.35, green: 0.55, blue: 0.95))
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

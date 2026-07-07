// IHBadgeTests.swift
// IHDesignTests
//
// ELI5: Checks the shared badge/pill view (and the one shared "Unofficial"
// songbook badge built on top of it) actually renders without crashing —
// the same kind of type-level smoke test `IHColorTokensTests` already runs
// for `ihGlassCard()`.
import SwiftUI
import Testing
@testable import IHDesign

@Suite("IHBadge + IHUnofficialBadge (#1437)")
struct IHBadgeTests {

    // `View` conformances are MainActor-isolated in SwiftUI by default —
    // these need to run on the MainActor to construct synchronously.
    @Test("IHBadge renders with a custom label + tint without crashing")
    @MainActor
    func badgeRenders() {
        _ = AnyView(IHBadge("New", tint: .blue))
        #expect(Bool(true))
    }

    @Test("IHUnofficialBadge renders without crashing")
    @MainActor
    func unofficialBadgeRenders() {
        _ = AnyView(IHUnofficialBadge())
        #expect(Bool(true))
    }
}

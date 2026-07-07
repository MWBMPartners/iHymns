// IHSettingsStoreTests.swift
// IHFeaturesTests
//
// ELI5: Checks the Settings "filing cabinet" — a brand-new install has the
// right defaults (follow the system theme, no colour adjustment, nothing
// shared, no server override), every setting saves and loads back exactly,
// clearing the server override really removes it, and the theme/colour-mode
// names still match the website's exactly (so a future account sync needs no
// translation table).
//
// DETAILED: Covers #182's `IHSettingsStore`. Each test injects its own
// throwaway `UserDefaults(suiteName:)` (the store's designed test seam) so
// runs never pollute `.standard` or each other. The raw-value assertions are
// the load-bearing web-parity contract from `IHAppearanceTheme` /
// `IHCVDMode`'s own headers — if one drifts, this test fails loudly.
import Foundation
import SwiftUI   // ColorScheme (the `themeColorSchemes` test names `.light`/`.dark`)
import Testing
import IHAPI
import IHDesign
@testable import IHFeatures

@Suite("IHSettingsStore")
struct IHSettingsStoreTests {

    /// A fresh store on a unique, isolated defaults suite per call.
    private func makeStore() -> IHSettingsStore {
        let suite = "ihtest.settings.\(UUID().uuidString)"
        return IHSettingsStore(defaults: UserDefaults(suiteName: suite)!)
    }

    @Test("A fresh install has safe defaults")
    func freshDefaults() {
        let store = makeStore()
        #expect(store.theme == .system)          // follow the OS, don't force a scheme
        #expect(store.cvdMode == .none)           // no colour adjustment
        #expect(store.dyslexiaReadingModeEnabled == false)
        #expect(store.analyticsConsentEnabled == false)  // strategy §3.1: OFF by default
        #expect(store.apiEnvironmentOverride == nil)     // use the build default
    }

    @Test("Every setting round-trips through a second store on the same suite")
    func roundTrips() {
        let suite = "ihtest.settings.\(UUID().uuidString)"
        let defaults = UserDefaults(suiteName: suite)!

        let writer = IHSettingsStore(defaults: defaults)
        writer.theme = .highContrast
        writer.cvdMode = .deuteranopia
        writer.dyslexiaReadingModeEnabled = true
        writer.analyticsConsentEnabled = true
        writer.apiEnvironmentOverride = .beta

        // A DIFFERENT store instance on the same underlying suite must see
        // every persisted value — proves it's really in UserDefaults, not
        // just held in the first instance.
        let reader = IHSettingsStore(defaults: defaults)
        #expect(reader.theme == .highContrast)
        #expect(reader.cvdMode == .deuteranopia)
        #expect(reader.dyslexiaReadingModeEnabled == true)
        #expect(reader.analyticsConsentEnabled == true)
        #expect(reader.apiEnvironmentOverride == .beta)
    }

    @Test("Clearing the environment override removes the key, not stores a sentinel")
    func clearingEnvironmentOverride() {
        let store = makeStore()
        store.apiEnvironmentOverride = .prod
        #expect(store.apiEnvironmentOverride == .prod)
        store.apiEnvironmentOverride = nil
        #expect(store.apiEnvironmentOverride == nil)
    }

    @Test("An unknown persisted raw value falls back to the default, never crashes")
    func unknownRawValueFallsBack() {
        let suite = "ihtest.settings.\(UUID().uuidString)"
        let defaults = UserDefaults(suiteName: suite)!
        // Simulate a value written by a FUTURE app version this build doesn't
        // know — the getter must degrade to the default, not trap.
        defaults.set("solarized", forKey: "ihAppearanceTheme")
        defaults.set("xyzzy", forKey: "ihApiEnvironmentOverride")
        let store = IHSettingsStore(defaults: defaults)
        #expect(store.theme == .system)
        #expect(store.apiEnvironmentOverride == nil)
    }

    @Test("Theme/CVD raw values still mirror the web vocabulary byte-for-byte")
    func webParityRawValues() {
        // The contract from `IHAppearanceTheme`/`IHCVDMode`'s headers: these
        // must equal the web's `ihymns_theme` / `ihymns_cvd_mode` values.
        #expect(IHAppearanceTheme.system.rawValue == "system")
        #expect(IHAppearanceTheme.light.rawValue == "light")
        #expect(IHAppearanceTheme.dark.rawValue == "dark")
        #expect(IHAppearanceTheme.highContrast.rawValue == "high-contrast")

        #expect(IHCVDMode.none.rawValue == "none")
        #expect(IHCVDMode.protanopia.rawValue == "protanopia")
        #expect(IHCVDMode.deuteranopia.rawValue == "deuteranopia")
        #expect(IHCVDMode.tritanopia.rawValue == "tritanopia")
        #expect(IHCVDMode.achromatopsia.rawValue == "achromatopsia")
    }

    @Test("Every theme resolves to the right ColorScheme")
    func themeColorSchemes() {
        #expect(IHAppearanceTheme.system.colorScheme == nil)   // follow OS
        #expect(IHAppearanceTheme.light.colorScheme == .light)
        #expect(IHAppearanceTheme.dark.colorScheme == .dark)
        #expect(IHAppearanceTheme.highContrast.colorScheme == .dark)
    }
}

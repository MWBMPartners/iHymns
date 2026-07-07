// LocaleRegionSignalsTests.swift
// IHAPITests
//
// ELI5: Checks the "roughly where in the world is this device set up for?"
// resolver (#183) picks the right hemisphere/country for a few real
// `Locale`s, and — just as importantly — falls back sanely (never crashes,
// never guesses wildly) for a `Locale` with no resolvable region at all.
//
// DETAILED: Every test injects a fixed `Locale(identifier:)` rather than
// reading `Locale.current` — the whole point of `LocaleRegionSignals`
// taking `locale:` as a parameter (see that file's own doc comment) is that
// this suite never depends on whatever region the CI machine or a
// developer's Mac happens to be configured for.
import Foundation
import Testing
@testable import IHAPI

@Suite("LocaleRegionSignals (#183)")
struct LocaleRegionSignalsTests {

    @Test("A known Southern-Hemisphere region resolves hemisphere 's'")
    func resolvesSouthernHemisphere() {
        let locale = Locale(identifier: "en_AU")
        #expect(LocaleRegionSignals.hemisphere(for: locale) == "s")
        #expect(LocaleRegionSignals.country(for: locale) == "AU")
    }

    @Test("A Northern-Hemisphere region resolves hemisphere 'n' — the server's own default")
    func resolvesNorthernHemisphere() {
        let locale = Locale(identifier: "en_GB")
        #expect(LocaleRegionSignals.hemisphere(for: locale) == "n")
        #expect(LocaleRegionSignals.country(for: locale) == "GB")
    }

    @Test("An equator-straddling country (population centre north) is NOT classified Southern")
    func equatorStraddlingCountryDefaultsNorth() {
        // Brazil's landmass extends well south of the equator, but its
        // population centre sits north of it — this file's own doc comment
        // on `southernHemisphereRegions` explains why it's deliberately
        // excluded from the allow-list (a "sane default," not precise
        // geodesy).
        let locale = Locale(identifier: "pt_BR")
        #expect(LocaleRegionSignals.hemisphere(for: locale) == "n")
        #expect(LocaleRegionSignals.country(for: locale) == "BR")
    }

    @Test("A locale with no resolvable region falls back to hemisphere 'n' and country ''")
    func fallsBackSanelyForUnresolvableRegion() {
        // A bare language-only identifier (no region subtag) is exactly
        // the "no signal at all" case `Endpoint.songOfTheDay` needs to
        // degrade gracefully from — never a crash, never a guessed region.
        let locale = Locale(identifier: "en")
        #expect(LocaleRegionSignals.hemisphere(for: locale) == "n")
        #expect(LocaleRegionSignals.country(for: locale) == "")
    }

    @Test("hemisphere(for:) and country(for:) both derive from the SAME region — they never disagree")
    func hemisphereAndCountryShareOneSignal() {
        // New Zealand: Southern hemisphere AND a real country code — proves
        // both resolvers read the same underlying `Locale.region`, not two
        // independently-fallible lookups that could drift apart.
        let locale = Locale(identifier: "en_NZ")
        #expect(LocaleRegionSignals.hemisphere(for: locale) == "s")
        #expect(LocaleRegionSignals.country(for: locale) == "NZ")
    }
}

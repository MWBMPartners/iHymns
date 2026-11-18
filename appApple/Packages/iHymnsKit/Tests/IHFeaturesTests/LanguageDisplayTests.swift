// LanguageDisplayTests.swift
// IHFeaturesTests
//
// ELI5: Checks that a language code is shown to a person as a name in their
// language ("pt-BR" → "Portuguese (Brazil)"), and that language lists read A
// to Z by name with "not known" and "no language" at the end.
//
// DETAILED: #2136 (part of #2137, the shared MWBM language policy — UI-010,
// UI-040, LANG-025). Every check pins the interface language to US English
// so the expected names do not depend on the machine running the tests.
import Foundation
import Testing
@testable import IHFeatures

@Suite("LanguageDisplay")
struct LanguageDisplayTests {
    private let english = Locale(identifier: "en_US")

    @Test("A regional or script form is named with its qualifier (UI-010)")
    func namesCarryTheQualifier() {
        #expect(LanguageDisplay.name(for: "pt-BR", locale: english) == "Portuguese (Brazil)")
        #expect(LanguageDisplay.name(for: "de", locale: english) == "German")
        #expect(LanguageDisplay.name(for: "", locale: english) == "")
    }

    @Test("Two script forms of one language get two different names (no collapsing)")
    func scriptFormsStayDistinct() {
        let simplified = LanguageDisplay.name(for: "zh-Hans", locale: english)
        let traditional = LanguageDisplay.name(for: "zh-Hant", locale: english)
        #expect(simplified != traditional)
        #expect(simplified.hasPrefix("Chinese"))
        #expect(traditional.hasPrefix("Chinese"))
    }

    @Test("Lists read A to Z by name, special codes last in the policy's order (UI-040, LANG-025)")
    func listOrder() {
        let sorted = LanguageDisplay.sortedForDisplay(["zxx", "fr", "und", "de", "en", "mul"], locale: english)
        #expect(sorted == ["en", "fr", "de", "mul", "und", "zxx"])
    }

    @Test("The order never depends on the order the tags arrived in")
    func orderIsStable() {
        let tags = ["es", "en-GB", "en", "af"]
        let once = LanguageDisplay.sortedForDisplay(tags, locale: english)
        let reversed = LanguageDisplay.sortedForDisplay(tags.reversed(), locale: english)
        #expect(once == reversed)
        #expect(once.first == "af")
    }
}

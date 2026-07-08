// OnboardingPageTests.swift
// IHFeaturesTests
//
// ELI5: Checks the "welcome tour" content itself — exactly four pages,
// each with a real title/message/icon and covering browse/search/offline/
// setlists, matching #190's "don't over-build" brief.
import Testing
@testable import IHFeatures

@Suite("OnboardingPage")
struct OnboardingPageTests {

    @Test("Exactly four pages — browse, search, offline, setlists, in that order")
    func fourPagesInOrder() {
        #expect(OnboardingPage.all.map(\.id) == ["browse", "search", "offline", "setlists"])
    }

    @Test("Every page has real, non-empty content")
    func everyPageIsWellFormed() {
        for page in OnboardingPage.all {
            #expect(!page.title.isEmpty)
            #expect(!page.message.isEmpty)
            #expect(!page.systemImage.isEmpty)
        }
    }

    @Test("Page icons reuse the SAME SF Symbols the real tabs/rows already use — no mismatched second iconography")
    func iconsMatchRealNavigation() {
        let iconsByID = Dictionary(uniqueKeysWithValues: OnboardingPage.all.map { ($0.id, $0.systemImage) })
        #expect(iconsByID["browse"] == "books.vertical")       // RootSection.songbooks
        #expect(iconsByID["search"] == "magnifyingglass")      // RootSection.search
        #expect(iconsByID["offline"] == "arrow.down.circle")   // SettingsView.storageSection
        #expect(iconsByID["setlists"] == "list.bullet.rectangle.portrait") // RootSection.setlists
    }
}

// CanonicalURLTests.swift
// IHAppSupportTests
//
// ELI5: Checks the "build a shareable web address" helper produces exactly
// the URL shape the app's own router (and the live web backend) expects —
// and that feeding that address straight back into the router lands back on
// the same typed destination it started from.
//
// #186 (Apple Phase 1, "Sharing & social").
import Foundation
import Testing
@testable import IHAppSupport
@testable import IHModels

@Suite("CanonicalURL")
struct CanonicalURLTests {

    @Test("Builds the canonical song URL")
    func buildsSongURL() throws {
        let id = try #require(SongID(rawValue: "MP-1008"))
        #expect(CanonicalURL.song(id)?.absoluteString == "https://ihymns.app/song/MP-1008")
    }

    @Test("Builds the canonical songbook URL")
    func buildsSongbookURL() {
        #expect(CanonicalURL.songbook(abbreviation: "MP")?.absoluteString == "https://ihymns.app/songbook/MP")
    }

    @Test("Builds the canonical songbooks-index URL")
    func buildsSongbooksListURL() {
        #expect(CanonicalURL.songbooksList?.absoluteString == "https://ihymns.app/songbooks")
    }

    @Test("Builds the canonical shared-set-list URL")
    func buildsSetlistShareURL() {
        #expect(
            CanonicalURL.setlistShare(id: "1a2b3c4d")?.absoluteString
                == "https://ihymns.app/setlist/shared/1a2b3c4d"
        )
    }

    @Test("Builds the canonical work URL")
    func buildsWorkURL() {
        #expect(CanonicalURL.work(slug: "amazing-grace")?.absoluteString == "https://ihymns.app/work/amazing-grace")
    }

    @Test("Builds the canonical credit-person URL — now the /musician/ path (#1752 Slice B, #1741 P2-B)")
    func buildsPersonURL() {
        // Asserting the literal string (not just "resolves to something")
        // is deliberate — a silent revert back to `/person/` would
        // otherwise slip past every other test here (rule #34).
        #expect(CanonicalURL.person(slug: "fanny-crosby")?.absoluteString == "https://ihymns.app/musician/fanny-crosby")
    }

    @Test("Builds the canonical compare URL")
    func buildsCompareURL() throws {
        let primaryId = try #require(SongID(rawValue: "MP-1008"))
        let secondaryId = try #require(SongID(rawValue: "SDAH-0042"))
        #expect(CanonicalURL.compare(primaryId: primaryId, secondaryId: secondaryId)?.absoluteString == "https://ihymns.app/compare/MP-1008/SDAH-0042")
    }

    // MARK: - #190 (help / legal) — these are NOT deep-linkable app
    // destinations (they open in the system browser), so unlike every URL
    // above they have no `DeepLinkRouter.resolve(_:)` round trip to prove.

    @Test("Builds the canonical help URL")
    func buildsHelpURL() {
        #expect(CanonicalURL.help?.absoluteString == "https://ihymns.app/help")
    }

    @Test("Builds the canonical privacy policy URL")
    func buildsPrivacyPolicyURL() {
        #expect(CanonicalURL.privacyPolicy?.absoluteString == "https://ihymns.app/privacy")
    }

    @Test("Builds the canonical terms of use URL")
    func buildsTermsOfUseURL() {
        #expect(CanonicalURL.termsOfUse?.absoluteString == "https://ihymns.app/terms")
    }

    // MARK: - Round trip: CanonicalURL → DeepLinkRouter must always agree

    @Test("A built song URL resolves back to the exact same DeepLink")
    func songURLRoundTrips() throws {
        let id = try #require(SongID(rawValue: "CP-0202"))
        let url = try #require(CanonicalURL.song(id))
        #expect(DeepLinkRouter.resolve(url) == .song(id))
    }

    @Test("A built songbook URL resolves back to the exact same DeepLink")
    func songbookURLRoundTrips() throws {
        let url = try #require(CanonicalURL.songbook(abbreviation: "SDAH"))
        #expect(DeepLinkRouter.resolve(url) == .songbook(abbreviation: "SDAH"))
    }

    @Test("A built songbooks-list URL resolves back to the exact same DeepLink")
    func songbooksListURLRoundTrips() throws {
        let url = try #require(CanonicalURL.songbooksList)
        #expect(DeepLinkRouter.resolve(url) == .songbooksList)
    }

    @Test("A built shared-set-list URL resolves back to the exact same DeepLink")
    func setlistShareURLRoundTrips() throws {
        let url = try #require(CanonicalURL.setlistShare(id: "deadbeef"))
        #expect(DeepLinkRouter.resolve(url) == .setlistShare(shareId: "deadbeef"))
    }

    @Test("A built work URL resolves back to the exact same DeepLink")
    func workURLRoundTrips() throws {
        let url = try #require(CanonicalURL.work(slug: "how-great-thou-art"))
        #expect(DeepLinkRouter.resolve(url) == .work(slug: "how-great-thou-art"))
    }

    @Test("A built credit-person URL resolves back to the exact same DeepLink")
    func personURLRoundTrips() throws {
        let url = try #require(CanonicalURL.person(slug: "fanny-crosby"))
        #expect(DeepLinkRouter.resolve(url) == .person(slug: "fanny-crosby"))
    }

    @Test("A built compare URL resolves back to the exact same DeepLink — see DeepLink.swift's header for why this is app-internal only today")
    func compareURLRoundTrips() throws {
        let primaryId = try #require(SongID(rawValue: "MP-1008"))
        let secondaryId = try #require(SongID(rawValue: "SDAH-0042"))
        let url = try #require(CanonicalURL.compare(primaryId: primaryId, secondaryId: secondaryId))
        #expect(DeepLinkRouter.resolve(url) == .compare(primaryId: primaryId, secondaryId: secondaryId))
    }
}

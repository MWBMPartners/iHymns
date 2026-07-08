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

    @Test("Builds the canonical credit-person URL")
    func buildsPersonURL() {
        #expect(CanonicalURL.person(slug: "fanny-crosby")?.absoluteString == "https://ihymns.app/person/fanny-crosby")
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
}

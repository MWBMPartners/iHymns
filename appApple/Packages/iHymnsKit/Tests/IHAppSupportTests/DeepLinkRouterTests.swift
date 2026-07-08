// DeepLinkRouterTests.swift
// IHAppSupportTests
//
// ELI5: Feeds the router a handful of URLs — good ones and bad ones — and
// checks it only ever "goes somewhere" for the good ones.
//
// #186 UPDATE (Apple Phase 1, "Sharing & social") — extends Phase 0's
// song-only coverage to every shape `DeepLink.swift`'s header now documents
// (songbook/songbooksList/setlistShare/work), each verified against the
// LIVE web route regex it mirrors (`appWeb/public_html/index.php`, read
// 2026-07-07) — every "rejects a malformed X" case below uses an input that
// the WEB router itself would also 404 on, not an arbitrary invalid string.
import Foundation
import Testing
@testable import IHAppSupport
@testable import IHModels

@Suite("DeepLinkRouter")
struct DeepLinkRouterTests {

    @Test("Resolves a valid song Universal Link")
    func resolvesValidSongLink() throws {
        let url = try #require(URL(string: "https://ihymns.app/song/MP-1008"))
        let link = DeepLinkRouter.resolve(url)
        #expect(link == .song(try #require(SongID(rawValue: "MP-1008"))))
    }

    @Test(
        "Resolves on every allow-listed host",
        arguments: ["ihymns.app", "www.ihymns.app", "beta.ihymns.app", "dev.ihymns.app"]
    )
    func resolvesOnEveryAllowedHost(host: String) throws {
        let url = try #require(URL(string: "https://\(host)/song/MP-1008"))
        #expect(DeepLinkRouter.resolve(url) != nil)
    }

    @Test("Rejects a host that isn't allow-listed (host-header-style spoof)")
    func rejectsDisallowedHost() throws {
        let url = try #require(URL(string: "https://ihymns.app.evil.example/song/MP-1008"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    @Test("Rejects an unrecognised path shape")
    func rejectsUnrecognisedPath() throws {
        let url = try #require(URL(string: "https://ihymns.app/does-not-exist"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    @Test("Rejects a malformed SongID in an otherwise well-shaped path")
    func rejectsMalformedSongID() throws {
        let url = try #require(URL(string: "https://ihymns.app/song/not-a-song-id-1008"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    @Test("Rejects a bare /song with no id — a missing trailing segment is malformed, not 'close enough'")
    func rejectsBareSongPath() throws {
        let url = try #require(URL(string: "https://ihymns.app/song"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    // MARK: - Songbook

    @Test("Resolves a valid songbook Universal Link")
    func resolvesValidSongbookLink() throws {
        let url = try #require(URL(string: "https://ihymns.app/songbook/MP"))
        #expect(DeepLinkRouter.resolve(url) == .songbook(abbreviation: "MP"))
    }

    @Test("Rejects a songbook abbreviation with digits — the web route is letters-only")
    func rejectsSongbookAbbreviationWithDigits() throws {
        let url = try #require(URL(string: "https://ihymns.app/songbook/MP1"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    @Test("Resolves the songbooks browse index")
    func resolvesSongbooksList() throws {
        let url = try #require(URL(string: "https://ihymns.app/songbooks"))
        #expect(DeepLinkRouter.resolve(url) == .songbooksList)
    }

    // MARK: - Shared set list

    @Test("Resolves a valid shared-set-list Universal Link")
    func resolvesValidSetlistShareLink() throws {
        let url = try #require(URL(string: "https://ihymns.app/setlist/shared/1a2b3c4d"))
        #expect(DeepLinkRouter.resolve(url) == .setlistShare(shareId: "1a2b3c4d"))
    }

    @Test("Rejects an uppercase-hex share id — the web route is lowercase-only")
    func rejectsUppercaseShareId() throws {
        let url = try #require(URL(string: "https://ihymns.app/setlist/shared/1A2B3C4D"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    @Test("Rejects a non-hex share id")
    func rejectsNonHexShareId() throws {
        let url = try #require(URL(string: "https://ihymns.app/setlist/shared/not-hex-at-all"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    @Test("Rejects a bare /setlist/shared with no id")
    func rejectsBareSetlistSharedPath() throws {
        let url = try #require(URL(string: "https://ihymns.app/setlist/shared"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    // MARK: - Work

    @Test("Resolves a valid work Universal Link")
    func resolvesValidWorkLink() throws {
        let url = try #require(URL(string: "https://ihymns.app/work/amazing-grace"))
        #expect(DeepLinkRouter.resolve(url) == .work(slug: "amazing-grace"))
    }

    @Test("Rejects an uppercase work slug — the web route is lowercase-only")
    func rejectsUppercaseWorkSlug() throws {
        let url = try #require(URL(string: "https://ihymns.app/work/Amazing-Grace"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    // MARK: - Credit person (#1443)

    @Test("Resolves a valid credit-person Universal Link")
    func resolvesValidPersonLink() throws {
        let url = try #require(URL(string: "https://ihymns.app/person/some-writer"))
        #expect(DeepLinkRouter.resolve(url) == .person(slug: "some-writer"))
    }

    @Test("Rejects an uppercase credit-person slug — the web route is lowercase-only")
    func rejectsUppercasePersonSlug() throws {
        let url = try #require(URL(string: "https://ihymns.app/person/Some-Writer"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    // MARK: - Compare (#1455) — app-internal only, see DeepLink.swift's header

    @Test("Resolves a valid compare Universal Link shape")
    func resolvesValidCompareLink() throws {
        let url = try #require(URL(string: "https://ihymns.app/compare/MP-1008/SDAH-0042"))
        let link = DeepLinkRouter.resolve(url)
        #expect(link == .compare(
            primaryId: try #require(SongID(rawValue: "MP-1008")),
            secondaryId: try #require(SongID(rawValue: "SDAH-0042"))
        ))
    }

    @Test("Rejects a compare link with a malformed primary id")
    func rejectsCompareLinkWithMalformedPrimaryId() throws {
        let url = try #require(URL(string: "https://ihymns.app/compare/not-a-song-id/SDAH-0042"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    @Test("Rejects a compare link with a malformed secondary id")
    func rejectsCompareLinkWithMalformedSecondaryId() throws {
        let url = try #require(URL(string: "https://ihymns.app/compare/MP-1008/not-a-song-id"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    @Test("Rejects a bare /compare with only one id — a missing trailing segment is malformed, not 'close enough'")
    func rejectsCompareLinkWithOnlyOneId() throws {
        let url = try #require(URL(string: "https://ihymns.app/compare/MP-1008"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    // MARK: - Not-yet-native shapes (AASA-claimed, correctly un-routed)

    @Test("Does not resolve a Live Follow join link — no native screen exists for it yet")
    func doesNotResolveLiveLink() throws {
        let url = try #require(URL(string: "https://ihymns.app/live/abc123"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    @Test("Never crashes on a URL with no path at all")
    func neverCrashesOnBareHost() throws {
        let url = try #require(URL(string: "https://ihymns.app/"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    @Test("Resolves an uppercased host case-insensitively (Phase-1 review fix)")
    func resolvesUppercasedHost() throws {
        // A Universal Link delivered with an uppercased host must still route
        // in-app, not fail-closed to Safari (DNS hosts are case-insensitive).
        let url = try #require(URL(string: "https://IHYMNS.APP/song/MP-1008"))
        #expect(DeepLinkRouter.resolve(url) != nil)
    }
}

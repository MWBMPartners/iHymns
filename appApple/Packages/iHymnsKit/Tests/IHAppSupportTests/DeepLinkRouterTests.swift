// DeepLinkRouterTests.swift
// IHAppSupportTests
//
// ELI5: Feeds the router a handful of URLs — good ones and bad ones — and
// checks it only ever "goes somewhere" for the good ones.
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
        let url = try #require(URL(string: "https://ihymns.app/songbooks"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }

    @Test("Rejects a malformed SongID in an otherwise well-shaped path")
    func rejectsMalformedSongID() throws {
        let url = try #require(URL(string: "https://ihymns.app/song/not-a-song-id-1008"))
        #expect(DeepLinkRouter.resolve(url) == nil)
    }
}

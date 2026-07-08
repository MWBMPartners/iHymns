// APIEnvironmentMediaTests.swift
// IHAPITests
//
// ELI5: Proves `APIEnvironment.mediaURL(forStreamPath:)` (#184) turns a
// server-relative `/song-media/123` path into the correct full web address
// for whichever environment asked, and degrades sanely on a malformed path.
import Foundation
import Testing
@testable import IHAPI

@Suite("APIEnvironment.mediaURL(forStreamPath:) (#184)")
struct APIEnvironmentMediaTests {

    @Test("Resolves a server-relative streamUrl against the dev host")
    func resolvesAgainstDevHost() {
        let url = APIEnvironment.dev.mediaURL(forStreamPath: "/song-media/123")
        #expect(url?.absoluteString == "https://dev.ihymns.app/song-media/123")
    }

    @Test("Resolves against beta/prod hosts independently")
    func resolvesAgainstOtherEnvironments() {
        #expect(APIEnvironment.beta.mediaURL(forStreamPath: "/song-media/9")?.host == "beta.ihymns.app")
        #expect(APIEnvironment.prod.mediaURL(forStreamPath: "/song-media/9")?.host == "ihymns.app")
    }

    @Test("Two different asset ids on the same environment resolve to two distinct URLs")
    func distinctIdsResolveDistinctly() {
        let first = APIEnvironment.dev.mediaURL(forStreamPath: "/song-media/1")
        let second = APIEnvironment.dev.mediaURL(forStreamPath: "/song-media/2")
        #expect(first != second)
    }

    @Test("An empty path returns nil rather than crashing — Foundation can't resolve a blank relative string")
    func emptyPathReturnsNilRatherThanCrashing() {
        // Defensive coverage only — the real backend never sends an empty
        // `streamUrl`. `URL(string:relativeTo:)` itself returns `nil` for a
        // blank relative component (verified here rather than assumed), so
        // this proves the function degrades gracefully (a `nil` a caller
        // treats as "nothing to play/download," per its own doc comment)
        // instead of force-unwrapping into a trap.
        let url = APIEnvironment.dev.mediaURL(forStreamPath: "")
        #expect(url == nil)
    }
}

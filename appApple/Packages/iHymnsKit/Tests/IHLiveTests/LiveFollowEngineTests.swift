// LiveFollowEngineTests.swift
// IHLiveTests
//
// ELI5: Feeds `isFresh` some made-up clock times (never the real clock) and
// checks it agrees a session is "live" just under 180 seconds and "stale"
// just over — matching the web/PWA's own freshness rule.
import Foundation
import Testing
@testable import IHLive

@Suite("LiveFollowEngine freshness")
struct LiveFollowEngineTests {

    @Test("Fresh just under the 180s window")
    func freshJustUnderWindow() {
        let last = Date(timeIntervalSince1970: 0)
        let now = last.addingTimeInterval(179)
        #expect(LiveFollowEngine.isFresh(lastUpdatedAt: last, now: now))
    }

    @Test("Stale at exactly the 180s boundary")
    func staleAtBoundary() {
        let last = Date(timeIntervalSince1970: 0)
        let now = last.addingTimeInterval(180)
        #expect(!LiveFollowEngine.isFresh(lastUpdatedAt: last, now: now))
    }

    @Test("Stale well past the window")
    func staleWellPastWindow() {
        let last = Date(timeIntervalSince1970: 0)
        let now = last.addingTimeInterval(600)
        #expect(!LiveFollowEngine.isFresh(lastUpdatedAt: last, now: now))
    }
}

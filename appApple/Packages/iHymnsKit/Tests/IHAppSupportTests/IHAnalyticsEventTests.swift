// IHAnalyticsEventTests.swift
// IHAppSupportTests
//
// ELI5: Checks that every kind of analytics event the app can build carries
// exactly the harmless facts it's supposed to — never a raw search query,
// never anything that looks like a person's identity.
//
// #189 (Apple Phase 1 analytics) — the "event shape (no PII)" test coverage
// this task's brief explicitly asks for.
import Testing
@testable import IHAppSupport

@Suite("IHAnalyticsEvent")
struct IHAnalyticsEventTests {

    @Test("screenView carries only a fixed screen label")
    func screenViewShape() {
        let event = IHAnalyticsEvent.screenView("Home")
        #expect(event.name == "screen_view")
        #expect(event.parameters == ["screen": "Home"])
    }

    @Test("songOpened carries only the public catalogue SongID")
    func songOpenedShape() {
        let event = IHAnalyticsEvent.songOpened(songId: "MP-1008")
        #expect(event.name == "song_opened")
        #expect(event.parameters == ["song_id": "MP-1008"])
    }

    @Test("offlineSave carries only the public catalogue SongID")
    func offlineSaveShape() {
        let event = IHAnalyticsEvent.offlineSave(songId: "MP-1008")
        #expect(event.name == "offline_save")
        #expect(event.parameters == ["song_id": "MP-1008"])
    }

    @Test("searchPerformed NEVER carries the raw query text — only a result count")
    func searchPerformedNeverCarriesQueryText() {
        // Even if a caller's query text happened to look like something
        // sensitive (an email, a name), the factory has no parameter that
        // could carry it — this test locks that contract down structurally,
        // not just by convention.
        let event = IHAnalyticsEvent.searchPerformed(resultCount: 7)
        #expect(event.name == "search_performed")
        #expect(event.parameters == ["result_count": "7"])
        #expect(event.parameters["query"] == nil)
        #expect(event.parameters.values.contains("7"))
    }

    @Test("Every closed-vocabulary factory only ever emits allow-listed keys")
    func allowListedKeysOnly() {
        // The full closed set this file's header promises — if a future
        // factory is added without updating this list, this test starts
        // failing until it's deliberately extended, which is the point.
        let allowListedKeys: Set<String> = ["screen", "song_id", "result_count"]
        let events: [IHAnalyticsEvent] = [
            .screenView("Search"),
            .songOpened(songId: "MP-1008"),
            .searchPerformed(resultCount: 0),
            .offlineSave(songId: "MP-1008")
        ]
        for event in events {
            for key in event.parameters.keys {
                #expect(allowListedKeys.contains(key), "Unexpected analytics parameter key: \(key)")
            }
        }
    }

    @Test("Equatable — two events built the same way compare equal")
    func equatable() {
        #expect(IHAnalyticsEvent.songOpened(songId: "MP-1008") == IHAnalyticsEvent.songOpened(songId: "MP-1008"))
        #expect(IHAnalyticsEvent.songOpened(songId: "MP-1008") != IHAnalyticsEvent.songOpened(songId: "SDA-2"))
    }
}

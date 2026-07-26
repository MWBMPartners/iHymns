// NowSingingContentStateContractTests.swift
// IHLiveActivityTests
//
// ELI5: Proves the Swift `ContentState` shape agrees, byte-for-byte, with
// what the PHP backend actually sends — decoding the SAME committed fixture
// file both sides read, checking nil fields never round-trip as a literal
// `null`, and checking an unrecognised `displayState` string doesn't blow up
// the card.
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). Mirrors
// `IHModelsTests`/`IHAPITests`' own "decode the live-recorded fixture,
// assert every field" contract-test pattern (`.claude/CLAUDE.md`'s test-
// discipline convention). `Tests/Fixtures/live_activity_content_state.json`
// is the SAME file `includes/live_activity_push.php`'s PHP-side test reads
// (`tests/php/test-apns-live-activity-push.php`) — see that file's header.
import Foundation
import Testing
import IHTestFixtures
@testable import IHLiveActivity
@testable import IHModels

@Suite("NowSingingActivityAttributes.ContentState — wire contract (#1429)")
struct NowSingingContentStateContractTests {

    @Test("decodes the committed fixture with every field intact")
    func decodesFixture() throws {
        let data = try ContractFixtures.liveActivityContentState()
        let state = try JSONDecoder().decode(NowSingingActivityAttributes.ContentState.self, from: data)

        #expect(state.songId == "MP-1008")
        #expect(state.songTitle == "Shine Jesus Shine")
        #expect(state.componentIndex == 2)
        #expect(state.displayState == "live")
        #expect(state.revision == 7)
        #expect(state.isLive == true)
        #expect(state.resolvedDisplayState == .live)
    }

    @Test("encode -> decode round-trips a full ContentState identically")
    func roundTripsFullState() throws {
        let original = NowSingingActivityAttributes.ContentState(
            songId: "MP-1008",
            songTitle: "Shine Jesus Shine",
            componentIndex: 2,
            displayState: "live",
            revision: 7,
            isLive: true
        )
        let data = try JSONEncoder().encode(original)
        let decoded = try JSONDecoder().decode(NowSingingActivityAttributes.ContentState.self, from: data)
        #expect(decoded == original)
    }

    @Test("nil optional fields encode as ABSENT keys, never a literal null — matches the PHP fixture shape")
    func nilFieldsAreOmittedNotNull() throws {
        let state = NowSingingActivityAttributes.ContentState(
            songId: nil,
            songTitle: nil,
            componentIndex: nil,
            displayState: "logo",
            revision: 0,
            isLive: false
        )
        let data = try JSONEncoder().encode(state)
        let json = try #require(JSONSerialization.jsonObject(with: data) as? [String: Any])

        #expect(json["songId"] == nil)
        #expect(json["songTitle"] == nil)
        #expect(json["componentIndex"] == nil)
        // The three always-present fields ARE there.
        #expect(json["displayState"] as? String == "logo")
        #expect(json["revision"] as? Int == 0)
        #expect(json["isLive"] as? Bool == false)

        // And it still round-trips back to the exact same `nil`s.
        let decoded = try JSONDecoder().decode(NowSingingActivityAttributes.ContentState.self, from: data)
        #expect(decoded == state)
    }

    @Test("resolvedDisplayState: known raw values map correctly")
    func resolvedDisplayStateKnownValues() {
        for (raw, expected) in [("live", ServiceDisplayState.live), ("blackout", .blackout), ("logo", .logo)] {
            let state = NowSingingActivityAttributes.ContentState(
                songId: nil, songTitle: nil, componentIndex: nil, displayState: raw, revision: 0, isLive: true
            )
            #expect(state.resolvedDisplayState == expected)
        }
    }

    @Test("resolvedDisplayState: an unrecognised raw value falls back to .live (mirrors LiveBroadcastState)")
    func resolvedDisplayStateUnknownFallsBackToLive() {
        let state = NowSingingActivityAttributes.ContentState(
            songId: nil, songTitle: nil, componentIndex: nil, displayState: "some-future-state", revision: 0, isLive: true
        )
        #expect(state.resolvedDisplayState == .live)
    }

    @Test("init(snapshot:songTitle:isLive:) maps a LiveBroadcastSnapshot to the wire shape")
    func mapsFromSnapshot() {
        let snapshot = LiveBroadcastSnapshot(
            songId: "MP-0031",
            componentIndex: 1,
            state: LiveBroadcastState(displayStateRaw: "blackout"),
            revision: 4
        )
        let state = NowSingingActivityAttributes.ContentState(snapshot: snapshot, songTitle: "Amazing Grace", isLive: true)

        #expect(state.songId == "MP-0031")
        #expect(state.songTitle == "Amazing Grace")
        #expect(state.componentIndex == 1)
        #expect(state.displayState == "blackout")
        #expect(state.revision == 4)
        #expect(state.isLive == true)
    }

    @Test("init(snapshot:songTitle:isLive:) with no state at all defaults displayState to live")
    func mapsFromSnapshotWithNoState() {
        let snapshot = LiveBroadcastSnapshot(songId: nil, componentIndex: nil, state: nil, revision: 0)
        let state = NowSingingActivityAttributes.ContentState(snapshot: snapshot, songTitle: nil, isLive: false)
        #expect(state.displayState == "live")
        #expect(state.isLive == false)
    }
}

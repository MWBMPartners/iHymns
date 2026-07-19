// NowSingingActivityReducerTests.swift
// IHLiveActivityTests
//
// ELI5: Checks the reducer picks the right action (start/update/end/none)
// for every combination of "what the card showed before" and "what it
// should show now."
//
// DETAILED: Apple Phase-2 PR-16 (#1429; strategy §2.3). Table-driven over
// the reducer's 4 real decision rows (start/update/end/none — `nil→nil` is
// the trivial "both are none" case folded into the `none` row).
import Foundation
import Testing
@testable import IHLiveActivity

@Suite("NowSingingActivityReducer.command(previous:current:)")
struct NowSingingActivityReducerTests {

    private static let context = NowSingingHostContext(
        sessionCode: "K7M4PQ", sessionId: 42, songId: "MP-0031", songTitle: "Amazing Grace", componentIndex: 1, revision: 3
    )

    @Test("nil -> nil is .none")
    func nilToNilIsNone() {
        #expect(NowSingingActivityReducer.command(previous: nil, current: nil) == .none)
    }

    @Test("nil -> context is .start, with a .host attributes struct and isLive true")
    func nilToContextIsStart() {
        let command = NowSingingActivityReducer.command(previous: nil, current: Self.context)
        guard case .start(let attributes, let state) = command else {
            Issue.record("expected .start, got \(command)")
            return
        }
        #expect(attributes.role == .host)
        #expect(attributes.sessionCode == "K7M4PQ")
        #expect(attributes.sessionId == 42)
        #expect(state.songId == "MP-0031")
        #expect(state.songTitle == "Amazing Grace")
        #expect(state.componentIndex == 1)
        #expect(state.revision == 3)
        #expect(state.isLive == true)
        #expect(state.displayState == "live")
    }

    @Test("context -> equal context is .none (no redundant update)")
    func equalContextsAreNone() {
        let command = NowSingingActivityReducer.command(previous: Self.context, current: Self.context)
        #expect(command == .none)
    }

    @Test("context -> a DIFFERENT context is .update, carrying the NEW values")
    func differentContextIsUpdate() {
        var next = Self.context
        next.componentIndex = 2
        next.revision = 4
        let command = NowSingingActivityReducer.command(previous: Self.context, current: next)
        guard case .update(let state) = command else {
            Issue.record("expected .update, got \(command)")
            return
        }
        #expect(state.componentIndex == 2)
        #expect(state.revision == 4)
        #expect(state.isLive == true)
    }

    @Test("context -> nil is .end(final:), isLive false, carrying the LAST known values")
    func contextToNilIsEnd() {
        let command = NowSingingActivityReducer.command(previous: Self.context, current: nil)
        guard case .end(let final) = command else {
            Issue.record("expected .end, got \(command)")
            return
        }
        #expect(final.songId == "MP-0031")
        #expect(final.isLive == false)
    }
}

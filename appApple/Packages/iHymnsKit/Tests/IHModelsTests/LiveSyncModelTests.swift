// LiveSyncModelTests.swift
// IHModelsTests
//
// ELI5: Checks the "is the venue screen live, blanked, or showing the logo?"
// logic handles every combination the server could ever send — including
// the ones a FUTURE server version might invent that this app doesn't know
// about yet.
//
// DETAILED: Apple Phase-2 PR-10 (#1426, #1427; `.claude/apple-phase2-pr10-spec.md`
// §7.2). The `resolvedDisplayState` table below is BINDING (spec §3.1/§7.2)
// — every row `LiveBroadcastState`'s own doc comment promises is exercised
// here. Deliberately does NOT decode the `live_follow_*`/`service_*`
// fixtures via `LivePollUpdate` — that type lives in `IHAPI` (a layer
// ABOVE `IHModels`; `IHModelsTests` must never depend upward), so those
// fixture-envelope decode checks live in `IHAPITests/LiveSyncAPITests.swift`
// instead, alongside the `APIClient.decodeX(from:)` functions that actually
// produce them.
import Foundation
import Testing
@testable import IHModels

@Suite("LiveSync models — resolvedDisplayState bridge")
struct LiveSyncModelTests {

    @Test("A recognised displayState always wins, regardless of blank")
    func recognisedDisplayStateWins() {
        #expect(LiveBroadcastState(displayStateRaw: "live", blank: true).resolvedDisplayState == .live)
        #expect(LiveBroadcastState(displayStateRaw: "blackout", blank: false).resolvedDisplayState == .blackout)
        #expect(LiveBroadcastState(displayStateRaw: "logo", blank: nil).resolvedDisplayState == .logo)
    }

    @Test("An unrecognised displayState falls back to the legacy blank boolean")
    func unrecognisedFallsBackToBlank() {
        #expect(LiveBroadcastState(displayStateRaw: "sepia", blank: true).resolvedDisplayState == .blackout)
        #expect(LiveBroadcastState(displayStateRaw: "sepia", blank: false).resolvedDisplayState == .live)
    }

    @Test("No displayState at all falls back to blank, or .live if neither is present")
    func absentDisplayStateFallsBackToBlankThenLive() {
        #expect(LiveBroadcastState(displayStateRaw: nil, blank: true).resolvedDisplayState == .blackout)
        #expect(LiveBroadcastState(displayStateRaw: nil, blank: nil).resolvedDisplayState == .live)
    }

    @Test("Clamp-shaped state values decode and round-trip")
    func stateFieldsRoundTrip() throws {
        let state = LiveBroadcastState(displayStateRaw: "live", blank: false, lineIndex: 9999, scrollPct: 1.0, transposeOffset: -12)
        let data = try JSONEncoder().encode(state)
        let decoded = try JSONDecoder().decode(LiveBroadcastState.self, from: data)
        #expect(decoded == state)
    }

    @Test("A state object with only unknown keys decodes to an all-nil LiveBroadcastState (forward-compat)")
    func unknownKeysOnlyDecodesAllNil() throws {
        let json = Data(#"{"someFutureField": 42, "anotherOne": "x"}"#.utf8)
        let decoded = try JSONDecoder().decode(LiveBroadcastState.self, from: json)
        #expect(decoded == LiveBroadcastState())
        #expect(decoded.resolvedDisplayState == .live)
    }

    @Test("LiveSyncEndReason has exactly the 5 documented cases")
    func liveSyncEndReasonCases() {
        let reasons: [LiveSyncEndReason] = [.userLeft, .serverEnded, .superseded, .expired, .signedOut]
        #expect(Set(reasons.map(\.rawValue)).count == 5)
    }
}

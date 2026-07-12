// RemoteControlUIStateTests.swift
// IHFeaturesTests
//
// ELI5: Checks that "here's what just happened to the connection" always
// turns into the RIGHT thing on screen — connecting shows a spinner with
// the TV's name, a code prompt shows the code sheet, a drop shows
// "reconnecting," and so on — without needing a real session, actor, or
// network anywhere.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §7.3 — BINDING).
// Exercises the PURE `RemoteControlCoordinator.uiPhase(after:current:tvName:)`
// mapping directly — no coordinator instance, no `RemoteControlSession`, no
// actor, no network.
import IHLive
import Testing
@testable import IHFeatures

@Suite("RemoteControlCoordinator.uiPhase(after:current:tvName:)")
struct RemoteControlUIStateTests {

    private let tvName = "Fellowship Hall TV"

    @Test("connecting(attempt:) always shows .connecting with the TV name")
    func connectingMapsToConnecting() {
        let result = RemoteControlCoordinator.uiPhase(after: .connecting(attempt: 2), current: .browsing, tvName: tvName)
        #expect(result == .connecting(tvName: tvName, attempt: 2))
    }

    @Test("awaitingCodeEntry(failedAttempts:) always shows .codeEntry with the TV name")
    func awaitingCodeEntryMapsToCodeEntry() {
        let result = RemoteControlCoordinator.uiPhase(
            after: .awaitingCodeEntry(failedAttempts: 1), current: .connecting(tvName: tvName, attempt: 0), tvName: tvName
        )
        #expect(result == .codeEntry(tvName: tvName, failedAttempts: 1))
    }

    @Test("paired(token:resolved:) does not change the current UI phase — .controlling always follows on the wire")
    func pairedLeavesCurrentPhaseUnchanged() {
        let current = RemoteControlCoordinator.UIPhase.codeEntry(tvName: tvName, failedAttempts: 2)
        let result = RemoteControlCoordinator.uiPhase(
            after: .paired(token: "t", resolved: nil), current: current, tvName: tvName
        )
        #expect(result == current)
    }

    @Test("controlling(state) always shows .controlling with the TV name and the exact state")
    func controllingMapsToControlling() {
        let state = IHRPState(songId: nil, componentIndex: nil, lineIndex: nil, displayState: .lyrics, revision: 3)
        let result = RemoteControlCoordinator.uiPhase(
            after: .controlling(state), current: .connecting(tvName: tvName, attempt: 0), tvName: tvName
        )
        #expect(result == .controlling(tvName: tvName, state: state))
    }

    @Test("detached WHILE .controlling shows .reconnecting(attempt: 0) immediately, not a stale .controlling")
    func detachedWhileControllingShowsReconnecting() {
        let state = IHRPState(songId: nil, componentIndex: nil, lineIndex: nil, displayState: .lyrics, revision: 0)
        let current = RemoteControlCoordinator.UIPhase.controlling(tvName: tvName, state: state)
        let result = RemoteControlCoordinator.uiPhase(after: .detached, current: current, tvName: tvName)
        #expect(result == .reconnecting(tvName: tvName, attempt: 0))
    }

    @Test("reconnecting(attempt:delay:) always shows .reconnecting with the TV name and attempt number")
    func reconnectingMapsToReconnecting() {
        let result = RemoteControlCoordinator.uiPhase(
            after: .reconnecting(attempt: 3, delay: 8), current: .reconnecting(tvName: tvName, attempt: 2), tvName: tvName
        )
        #expect(result == .reconnecting(tvName: tvName, attempt: 3))
    }

    @Test("suspended always shows .suspended with the TV name, even from .controlling")
    func suspendedMapsToSuspended() {
        let state = IHRPState(songId: nil, componentIndex: nil, lineIndex: nil, displayState: .lyrics, revision: 0)
        let current = RemoteControlCoordinator.UIPhase.controlling(tvName: tvName, state: state)
        let result = RemoteControlCoordinator.uiPhase(after: .suspended, current: current, tvName: tvName)
        #expect(result == .suspended(tvName: tvName))
    }

    @Test("resuming from .suspended (a fresh .connecting) shows .connecting again")
    func resumeFromSuspendedShowsConnecting() {
        let current = RemoteControlCoordinator.UIPhase.suspended(tvName: tvName)
        let result = RemoteControlCoordinator.uiPhase(after: .connecting(attempt: 0), current: current, tvName: tvName)
        #expect(result == .connecting(tvName: tvName, attempt: 0))
    }

    @Test("pairingEnded(.cancelled) returns to .browsing")
    func pairingEndedCancelledReturnsToBrowsing() {
        let current = RemoteControlCoordinator.UIPhase.codeEntry(tvName: tvName, failedAttempts: 1)
        let result = RemoteControlCoordinator.uiPhase(after: .pairingEnded(.cancelled), current: current, tvName: tvName)
        #expect(result == .browsing)
    }

    @Test("pairingEnded(.connectionTornDown) also returns to .browsing (the operator-guidance notice is a separate side effect)")
    func pairingEndedTornDownReturnsToBrowsing() {
        let current = RemoteControlCoordinator.UIPhase.codeEntry(tvName: tvName, failedAttempts: 3)
        let result = RemoteControlCoordinator.uiPhase(after: .pairingEnded(.connectionTornDown), current: current, tvName: tvName)
        #expect(result == .browsing)
    }

    @Test("ended (deliberate disconnect) returns to .browsing from .controlling")
    func endedReturnsToBrowsing() {
        let state = IHRPState(songId: nil, componentIndex: nil, lineIndex: nil, displayState: .lyrics, revision: 0)
        let current = RemoteControlCoordinator.UIPhase.controlling(tvName: tvName, state: state)
        let result = RemoteControlCoordinator.uiPhase(after: .ended, current: current, tvName: tvName)
        #expect(result == .browsing)
    }
}

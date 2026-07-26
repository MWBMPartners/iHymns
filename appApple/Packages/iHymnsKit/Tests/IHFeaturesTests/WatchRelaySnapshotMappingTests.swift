// WatchRelaySnapshotMappingTests.swift
// IHFeaturesTests
//
// ELI5: Checks that turning "what's the phone's remote screen showing"
// into "the tiny picture the watch is allowed to see" always gets it right
// — and never leaks a fingerprint into that picture.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §7.2 —
// BINDING). Exercises the PURE `RemoteControlCoordinator.relaySnapshot(
// from:)` mapping directly — no coordinator instance, no session, no hub.
import Foundation
import IHLive
import IHModels
import Testing
@testable import IHFeatures

@Suite("RemoteControlCoordinator.relaySnapshot(from:)")
struct WatchRelaySnapshotMappingTests {

    private let tvName = "Fellowship Hall TV"

    @Test(".browsing maps to .standby(tvName: nil) — the list screen has no 'current' TV in mind")
    func browsingMapsToStandbyNil() {
        let result = RemoteControlCoordinator.relaySnapshot(from: .browsing)
        #expect(result == WatchRelaySnapshot.standby(tvName: nil))
    }

    @Test(".connecting/.reconnecting both map to .connecting, carrying the TV name")
    func connectingAndReconnectingMapToConnecting() {
        let connecting = RemoteControlCoordinator.relaySnapshot(from: .connecting(tvName: tvName, attempt: 0))
        #expect(connecting.phase == .connecting)
        #expect(connecting.tvName == tvName)

        let reconnecting = RemoteControlCoordinator.relaySnapshot(from: .reconnecting(tvName: tvName, attempt: 2))
        #expect(reconnecting.phase == .connecting)
        #expect(reconnecting.tvName == tvName)
    }

    @Test(".codeEntry/.confirmingFingerprint both map to .pairing, carrying the TV name")
    func codeEntryAndConfirmingFingerprintMapToPairing() {
        let codeEntry = RemoteControlCoordinator.relaySnapshot(from: .codeEntry(tvName: tvName, failedAttempts: 1))
        #expect(codeEntry.phase == .pairing)
        #expect(codeEntry.tvName == tvName)

        let fingerprintHex = String(repeating: "a", count: 64)
        let confirming = RemoteControlCoordinator.relaySnapshot(
            from: .confirmingFingerprint(tvName: tvName, fingerprintHex: fingerprintHex)
        )
        #expect(confirming.phase == .pairing)
        #expect(confirming.tvName == tvName)
    }

    @Test(".controlling maps to .controlling, propagating songRef/displayState/revision from the IHRPState")
    func controllingPropagatesStateFields() {
        let songId = SongID(songbookAbbreviation: "MP", number: 1008)
        let state = IHRPState(songId: songId, componentIndex: 2, lineIndex: 0, displayState: .blackout, revision: 7)
        let result = RemoteControlCoordinator.relaySnapshot(from: .controlling(tvName: tvName, state: state))
        #expect(result.phase == .controlling)
        #expect(result.tvName == tvName)
        #expect(result.songRef == songId.rawValue)
        #expect(result.displayState == "blackout")
        #expect(result.revision == 7)
    }

    @Test(".suspended maps to .standby, carrying the TV it was suspended from — the pocket redesign's key row, no more .phoneBackgrounded dead-end")
    func suspendedMapsToStandby() {
        let result = RemoteControlCoordinator.relaySnapshot(from: .suspended(tvName: tvName))
        #expect(result.phase == .standby)
        #expect(result.tvName == tvName)
    }

    @Test("A .confirmingFingerprint snapshot's ENCODED bytes never contain the fingerprint hex — spec §8 row 8")
    func confirmingFingerprintNeverLeaksTheHexIntoTheSnapshot() {
        let fingerprintHex = String(repeating: "f", count: 64)
        let snapshot = RemoteControlCoordinator.relaySnapshot(
            from: .confirmingFingerprint(tvName: tvName, fingerprintHex: fingerprintHex)
        )
        let data = WatchRelayCodec.encode(snapshot: snapshot)
        let json = String(bytes: data, encoding: .utf8) ?? ""
        #expect(!json.contains(fingerprintHex))
    }
}

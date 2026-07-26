// WatchRelayCodecTests.swift
// IHLiveTests
//
// ELI5: Proves the watch↔iPhone wire format actually round-trips — every
// button press, every reply, every status snapshot comes back out exactly
// as it went in — and that garbage bytes or a mismatched version never
// crash the decoder, just answer honestly instead.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §7.1 —
// BINDING). Pure — no WCSession, no actor, no network.
import Foundation
import Testing
@testable import IHLive

@Suite("WatchRelayCodec — pure wire round-trips")
struct WatchRelayCodecTests {

    // MARK: - Commands

    @Test("Every WatchRelayCommand round-trips through encode/decodeCommand", arguments: WatchRelayCommand.allCases)
    func commandRoundTrips(_ command: WatchRelayCommand) {
        let data = WatchRelayCodec.encode(command: command)
        #expect(WatchRelayCodec.decodeCommand(data) == .success(command))
    }

    // MARK: - Snapshots

    @Test("A full .controlling snapshot round-trips with every field populated")
    func fullSnapshotRoundTrips() {
        let snapshot = WatchRelaySnapshot(
            phase: .controlling, tvName: "Fellowship Hall TV", songRef: "MP-1008",
            displayState: "lyrics", revision: 42
        )
        let data = WatchRelayCodec.encode(snapshot: snapshot)
        guard case .success(let decoded) = WatchRelayCodec.decodeSnapshot(data) else {
            Issue.record("expected a successful decode")
            return
        }
        #expect(decoded == snapshot)
    }

    @Test("The minimal .noSavedTV snapshot round-trips")
    func minimalSnapshotRoundTrips() {
        let data = WatchRelayCodec.encode(snapshot: .noSavedTV)
        guard case .success(let decoded) = WatchRelayCodec.decodeSnapshot(data) else {
            Issue.record("expected a successful decode")
            return
        }
        #expect(decoded == WatchRelaySnapshot.noSavedTV)
    }

    @Test("A full .controlling snapshot's encoded bytes stay comfortably under 512 (field-creep guard)")
    func fullSnapshotStaysUnderSizeGuard() {
        let snapshot = WatchRelaySnapshot(
            phase: .controlling, tvName: "A Reasonably Long Fellowship Hall Auditorium TV Name",
            songRef: "MP-1008", displayState: "blackout", revision: 999_999
        )
        let data = WatchRelayCodec.encode(snapshot: snapshot)
        #expect(data.count < 512)
    }

    // MARK: - Replies (outcome × reason, incl. nil reason)

    private static let allReasons: [WatchRelayReply.Reason?] = [nil] + WatchRelayReply.Reason.allCases

    @Test(
        "Every outcome/reason combination — including a nil reason — round-trips",
        arguments: WatchRelayReply.Outcome.allCases, allReasons
    )
    func replyRoundTrips(outcome: WatchRelayReply.Outcome, reason: WatchRelayReply.Reason?) {
        let reply = WatchRelayReply(outcome: outcome, reason: reason, snapshot: .standby(tvName: "Fellowship Hall TV"))
        let data = WatchRelayCodec.encode(reply: reply)
        guard case .success(let decoded) = WatchRelayCodec.decodeReply(data) else {
            Issue.record("expected a successful decode")
            return
        }
        #expect(decoded == reply)
    }

    // MARK: - Version handling

    @Test("The encoded JSON literally carries \"v\":1 (sortedKeys — deterministic byte order)")
    func encodedJSONCarriesVersionLiteral() {
        let data = WatchRelayCodec.encode(command: .nextComponent)
        let json = String(bytes: data, encoding: .utf8) ?? ""
        #expect(json.contains("\"v\":1"))
    }

    @Test("A v:99 envelope decodes to .unsupportedVersion(99), never a crash")
    func unsupportedVersionIsDetected() {
        let data = Data("{\"p\":\"nextComponent\",\"v\":99}".utf8)
        #expect(WatchRelayCodec.decodeCommand(data) == .failure(.unsupportedVersion(99)))
    }

    // MARK: - Malformed input

    @Test("Garbage bytes decode to .malformed")
    func garbageIsMalformed() {
        #expect(WatchRelayCodec.decodeCommand(Data("not json at all".utf8)) == .failure(.malformed))
    }

    @Test("Empty data decodes to .malformed")
    func emptyIsMalformed() {
        #expect(WatchRelayCodec.decodeCommand(Data()) == .failure(.malformed))
    }

    // MARK: - Tolerant reason decoding

    @Test("An unrecognized reason string decodes to a nil Reason — a tolerant decode, never a thrown/failed one")
    func unknownReasonDecodesTolerantly() {
        let json = """
        {"v":1,"p":{"outcome":"failed","reason":"aFutureReasonThisBuildDoesNotKnow","snapshot":{"phase":"noSavedTV"}}}
        """
        guard case .success(let decoded) = WatchRelayCodec.decodeReply(Data(json.utf8)) else {
            Issue.record("expected a successful (tolerant) decode")
            return
        }
        #expect(decoded.outcome == .failed)
        #expect(decoded.reason == nil)
    }
}

// IHRPMessageCodecTests.swift
// IHLiveTests
//
// ELI5: Checks that every kind of IHRP message survives a round trip —
// "turn it into JSON bytes, then read those bytes back" — and comes out
// EXACTLY the same, plus checks that garbage bytes never crash the decoder.
//
// DETAILED: #1420 (task brief: "IHRP encode/decode round-trip + a
// malformed/oversize-frame fuzz set"). The fuzz set here targets the JSON/
// `IHRPFrame.Decodable` layer specifically (garbage that decoded past the
// TLV framer's own length-cap, i.e. what `TVListenerActor.handleIncomingFrameData`
// hands to `JSONDecoder`); `IHRPFramerTests.swift` covers the TLV/length-cap
// layer's own fuzz set separately.
import Foundation
import IHModels
import Testing
@testable import IHLive

@Suite("IHRPFrame encode/decode round-trip")
struct IHRPMessageCodecTests {

    private static let sampleSongId = SongID(songbookAbbreviation: "MP", number: 1008)

    /// One representative frame per `IHRPMessage` case — every case,
    /// including every "no associated value" case, and BOTH the
    /// present/absent shape of every optional field.
    private static let sampleMessages: [IHRPMessage] = [
        .hello(token: "a-real-token", kind: .phone),
        .hello(token: nil, kind: .watchRelay),
        .prepare(songId: sampleSongId),
        .selectSong(songId: sampleSongId, componentIndex: nil, lineIndex: nil),
        .selectSong(songId: sampleSongId, componentIndex: 2, lineIndex: 5),
        .nextComponent,
        .prevComponent,
        .nextLine,
        .prevLine,
        .jumpLine(index: 7),
        .setDisplayState(.blackout),
        .setDisplayState(.frozen),
        .scroll(delta: -3),
        .setAppearance(theme: "dark", textScale: 1.25),
        .setAppearance(theme: nil, textScale: nil),
        .endControl,
        .ping,
        .state(IHRPState(songId: sampleSongId, componentIndex: 1, lineIndex: 0, displayState: .lyrics, revision: 42)),
        .state(.initial),
        .ack(ackSeq: 99),
        .error(.contentUnavailable, message: "song is gated"),
        .error(.protocolVersionMismatch, message: nil),
        .error(.pairingRejected, message: nil),
        .pong,
        .capabilities(IHRPCapabilities(currentState: .initial)),
        // #1421 (PR-6 spec §4) — the pairing sub-protocol's own cases,
        // including BOTH the present/absent shape of `pairConfirm`'s
        // optional `deviceName` (the same "every optional field, both
        // ways" discipline this array's own doc comment establishes).
        .pairChallenge(nonce: "0123456789abcdef0123456789abcdef"),
        .pairConfirm(proof: String(repeating: "ab", count: 32), deviceName: "Lance's iPhone"),
        .pairConfirm(proof: String(repeating: "ab", count: 32), deviceName: nil),
        .pairSuccess(token: String(repeating: "cd", count: 32))
    ]

    @Test("Every message case round-trips through JSON unchanged", arguments: sampleMessages)
    func roundTrip(message: IHRPMessage) throws {
        let original = IHRPFrame(seq: 12, timestamp: Date(timeIntervalSince1970: 1_700_000_000.5), message: message)
        let encoded = try JSONEncoder().encode(original)
        let decoded = try JSONDecoder().decode(IHRPFrame.self, from: encoded)
        #expect(decoded == original)
    }

    @Test("Every message case exposes a stable, non-empty caseName")
    func caseNamesAreStable() {
        for message in Self.sampleMessages {
            #expect(!message.caseName.isEmpty)
        }
    }

    @Test("Control intents and responses are correctly classified")
    func isControlIntentClassification() {
        #expect(IHRPMessage.hello(token: nil, kind: .phone).isControlIntent)
        #expect(IHRPMessage.ping.isControlIntent)
        #expect(IHRPMessage.nextComponent.isControlIntent)
        #expect(!IHRPMessage.pong.isControlIntent)
        #expect(!IHRPMessage.ack(ackSeq: 1).isControlIntent)
        #expect(!IHRPMessage.state(.initial).isControlIntent)
        #expect(!IHRPMessage.error(.internalError, message: nil).isControlIntent)
        #expect(!IHRPMessage.capabilities(IHRPCapabilities(currentState: .initial)).isControlIntent)
        // #1421 — `.pairConfirm` is the ONE remote→TV case in the pairing
        // trio (a control intent); `.pairChallenge`/`.pairSuccess` are
        // TV→remote responses, mirroring `.capabilities`'s classification.
        #expect(IHRPMessage.pairConfirm(proof: "ab", deviceName: nil).isControlIntent)
        #expect(!IHRPMessage.pairChallenge(nonce: "ab").isControlIntent)
        #expect(!IHRPMessage.pairSuccess(token: "ab").isControlIntent)
    }

    @Test("A frame with a mismatched protocol version is rejected")
    func rejectsWrongVersion() throws {
        let json = """
        {"v":"IHRP/999","seq":1,"ts":0,"t":"ping"}
        """
        #expect(throws: IHRPDecodingError.self) {
            _ = try JSONDecoder().decode(IHRPFrame.self, from: Data(json.utf8))
        }
    }

    @Test("An unknown message-type discriminator is rejected")
    func rejectsUnknownType() throws {
        let json = """
        {"v":"IHRP/1","seq":1,"ts":0,"t":"selfDestruct"}
        """
        #expect(throws: IHRPDecodingError.self) {
            _ = try JSONDecoder().decode(IHRPFrame.self, from: Data(json.utf8))
        }
    }

    @Test("A required field missing for its message type is rejected")
    func rejectsMissingRequiredField() throws {
        let json = """
        {"v":"IHRP/1","seq":1,"ts":0,"t":"jumpLine"}
        """
        #expect(throws: IHRPDecodingError.self) {
            _ = try JSONDecoder().decode(IHRPFrame.self, from: Data(json.utf8))
        }
    }

    @Test("Fuzzed garbage JSON never crashes the decoder — it only ever throws")
    func fuzzGarbageJSONNeverCrashes() {
        var rng = LANRemoteFuzzRNG(seed: 0xC0FFEE)
        for _ in 0..<300 {
            let length = Int(rng.next() % 80)
            let bytes = (0..<length).map { _ in UInt8(truncatingIfNeeded: rng.next()) }
            do {
                _ = try JSONDecoder().decode(IHRPFrame.self, from: Data(bytes))
                // A tiny fraction of random byte sequences might coincidentally
                // decode to something valid-shaped — not expected in practice
                // for this schema, but not a failure if it somehow did; the
                // property under test is "never crashes/traps," proven either
                // way by reaching this line.
            } catch {
                // Any thrown error (DecodingError, IHRPDecodingError, ...) is
                // an acceptable, safe outcome for malformed input.
            }
        }
    }
}

/// A tiny deterministic linear-congruential PRNG — Swift's stdlib
/// `RandomNumberGenerator`s have no seedable, reproducible variant, and a
/// reproducible seed is worth more here than true randomness (a fuzz test
/// that fails should fail the SAME way on every re-run while debugging it).
struct LANRemoteFuzzRNG: RandomNumberGenerator {
    private var state: UInt64
    init(seed: UInt64) { state = seed == 0 ? 0xdead_beef : seed }
    mutating func next() -> UInt64 {
        state = state &* 6_364_136_223_846_793_005 &+ 1_442_695_040_888_963_407
        return state
    }
}

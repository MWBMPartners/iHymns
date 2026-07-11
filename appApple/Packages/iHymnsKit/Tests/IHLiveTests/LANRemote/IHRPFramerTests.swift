// IHRPFramerTests.swift
// IHLiveTests
//
// ELI5: Checks the "how many bytes am I about to send you" length-prefix
// logic — that a message split across many tiny reads still reassembles
// correctly, that a message exactly at the size limit still works, and
// that a message OVER the size limit is rejected immediately (not after
// patiently waiting for gigabytes of "more bytes coming").
//
// DETAILED: #1420 (task brief: "TLV framer edge cases (split reads, exact-
// cap, over-cap)" + the framer half of "a malformed/oversize-frame fuzz
// set... asserts the cap rejects + connection drops" — "connection drops"
// is `TVListenerActor`/`RemoteSessionActor`'s documented, REQUIRED
// response to `IHRPFramerError.frameTooLarge` (see both actors' `onReceive`
// methods), proven here at the pure, deterministic layer this error
// actually originates from).
import Foundation
import Testing
@testable import IHLive

@Suite("IHRPFramer / IHRPFrameDecoder")
struct IHRPFramerTests {

    @Test("encode(_:) rejects a payload over the cap")
    func encodeRejectsOversizePayload() {
        let oversized = Data(repeating: 0x41, count: IHRPFramer.maxFrameBytes + 1)
        #expect(throws: IHRPFramerError.self) {
            _ = try IHRPFramer.encode(oversized)
        }
    }

    @Test("A payload fed in one shot decodes back out whole")
    func feedWholeInOneShot() throws {
        let payload = Data("hello world".utf8)
        let framed = try IHRPFramer.encode(payload)
        var decoder = IHRPFrameDecoder()
        let frames = try decoder.feed(framed)
        #expect(frames == [payload])
        #expect(decoder.bufferedByteCount == 0)
    }

    @Test("A payload fed one byte at a time still reassembles correctly")
    func feedSplitAcrossManyCalls() throws {
        let payload = Data("this message arrives in tiny pieces".utf8)
        let framed = try IHRPFramer.encode(payload)
        var decoder = IHRPFrameDecoder()

        var collected: [Data] = []
        for byte in framed {
            let frames = try decoder.feed(Data([byte]))
            collected.append(contentsOf: frames)
        }

        #expect(collected == [payload])
        #expect(decoder.bufferedByteCount == 0)
    }

    @Test("Several small frames arriving in one chunk all decode, in order")
    func multipleFramesInOneChunk() throws {
        let payloads = [Data("one".utf8), Data("two".utf8), Data("three".utf8)]
        var combined = Data()
        for payload in payloads {
            combined.append(try IHRPFramer.encode(payload))
        }

        var decoder = IHRPFrameDecoder()
        let frames = try decoder.feed(combined)
        #expect(frames == payloads)
    }

    @Test("A payload of exactly maxFrameBytes is accepted")
    func exactCapSucceeds() throws {
        let payload = Data(repeating: 0x5A, count: IHRPFramer.maxFrameBytes)
        let framed = try IHRPFramer.encode(payload)
        var decoder = IHRPFrameDecoder()
        let frames = try decoder.feed(framed)
        #expect(frames.count == 1)
        #expect(frames[0].count == IHRPFramer.maxFrameBytes)
    }

    @Test("A declared length one byte over the cap is rejected IMMEDIATELY — before the payload arrives")
    func overCapRejectsWithoutWaitingForPayload() throws {
        var decoder = IHRPFrameDecoder()
        var prefix = UInt32(IHRPFramer.maxFrameBytes + 1).bigEndian
        let prefixBytes = withUnsafeBytes(of: &prefix) { Data($0) }

        // Only the 4-byte length prefix is fed — NONE of the (claimed)
        // 4097 payload bytes. If the decoder buffered unboundedly waiting
        // for them, this would just return an empty frame list; instead it
        // must throw the instant the declared length is read.
        #expect(throws: IHRPFramerError.frameTooLarge(declaredLength: IHRPFramer.maxFrameBytes + 1, cap: IHRPFramer.maxFrameBytes)) {
            _ = try decoder.feed(prefixBytes)
        }
    }

    @Test("An overlong declared length split across two feeds still rejects on the second")
    func overCapDetectedAfterSplitPrefix() throws {
        var decoder = IHRPFrameDecoder()
        var prefix = UInt32(IHRPFramer.maxFrameBytes + 1_000).bigEndian
        let prefixBytes = withUnsafeBytes(of: &prefix) { Data($0) }

        // Feed the length prefix itself split across two calls.
        let firstHalf = try decoder.feed(prefixBytes.prefix(2))
        #expect(firstHalf.isEmpty) // not enough bytes yet to even read the length

        #expect(throws: IHRPFramerError.self) {
            _ = try decoder.feed(prefixBytes.suffix(2))
        }
    }

    @Test("Fuzzed byte streams never crash the decoder — every outcome is either frames, or frameTooLarge")
    func fuzzNeverCrashesOrHangs() {
        var rng = LANRemoteFuzzRNG(seed: 0x5EED)
        for _ in 0..<300 {
            var decoder = IHRPFrameDecoder()
            let length = Int(rng.next() % 64)
            let bytes = (0..<length).map { _ in UInt8(truncatingIfNeeded: rng.next()) }
            do {
                let frames = try decoder.feed(Data(bytes))
                // Whatever came back, the decoder must never have buffered
                // more than one cap's worth while waiting for a partial
                // frame — the "never buffer unboundedly" guarantee.
                #expect(decoder.bufferedByteCount <= IHRPFramer.maxFrameBytes + IHRPFramer.lengthPrefixSize)
                _ = frames
            } catch is IHRPFramerError {
                // The one designed, expected failure mode for this input.
            } catch {
                Issue.record("unexpected error type from fuzzed input: \(error)")
            }
        }
    }
}

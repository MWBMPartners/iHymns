// IHRPFramer.swift
// IHLive/LANRemote
//
// ELI5: TCP just gives you a stream of bytes with no "start here, stop
// there" markers — this file draws those markers: before every message, it
// writes down exactly how many bytes are coming, so the reading side always
// knows where one message ends and the next begins.
//
// DETAILED: #1420 (`.claude/apple-phase2-implementation-plan.md` §2 PR-4:
// "TLV framer — length-prefixed framing over a byte stream with HARD length
// caps (≈4 KB/frame); a frame exceeding the cap is a protocol error that
// drops the connection (never buffer unboundedly). Pure, unit-testable.").
// "TLV" here is the length-prefix half of Type-Length-Value — the "Type" is
// carried INSIDE the JSON payload itself (`IHRPFrame`'s `t` discriminator),
// so this layer only has to solve Length+Value framing over the raw byte
// stream `NWConnection.receive(...)` hands back in arbitrary-sized chunks.
//
// Two pieces, deliberately kept PURE (no Network.framework, no actors, no
// I/O) so `LANRemoteTests` can exercise every edge case — split reads,
// exact-cap, over-cap, garbage bytes — with plain `Data` values and zero
// networking:
//   - `IHRPFramer` — stateless encode: wrap one payload with its 4-byte
//     big-endian length prefix.
//   - `IHRPFrameDecoder` — a small stateful accumulator: `feed(_:)` newly-
//     arrived bytes, get back zero-or-more COMPLETE frame payloads. The cap
//     is enforced the MOMENT the 4-byte length prefix is fully read — before
//     a single byte of an oversize payload is ever appended to the internal
//     buffer, which is what makes "never buffer unboundedly" true: the
//     buffer's worst-case size is always `maxFrameBytes + lengthPrefixSize`
//     bytes, never more, no matter how large a malicious/buggy peer CLAIMS
//     its next frame will be.
import Foundation

/// Thrown when a declared frame length exceeds `IHRPFramer.maxFrameBytes` —
/// always fatal to the connection (`TVListenerActor`/`RemoteSessionActor`
/// MUST drop the connection on this, never retry framing on the same
/// stream — the byte offsets are no longer trustworthy once a declared
/// length this large has been seen).
public enum IHRPFramerError: Error, Sendable, Equatable {
    case frameTooLarge(declaredLength: Int, cap: Int)
}

/// Stateless TLV (length-prefix) encoding for one IHRP payload.
///
/// ELI5: Puts a 4-byte "here's how many bytes follow" stamp in front of a
/// message before it goes on the wire.
public enum IHRPFramer {
    /// The hard per-frame cap — strategy's "≈4 KB/frame". Chosen as a
    /// generous multiple of the "~200-byte INTENT" every real IHRP payload
    /// actually needs (this file's `IHRPMessage.swift` INVARIANT comment),
    /// while still being small enough that even a fully adversarial peer
    /// claiming the maximum every single frame can only ever force this
    /// process to hold ~4 KB of pending buffer per connection — nowhere
    /// near a meaningful memory-exhaustion vector even with the
    /// `TVListenerActor`'s bounded number of concurrent connections.
    public static let maxFrameBytes = 4096

    /// Big-endian `UInt32` length prefix — 4 bytes, comfortably large enough
    /// to represent any length up to `maxFrameBytes` with room to spare
    /// (`UInt32.max` ≈ 4 GB), so the prefix width itself is never the
    /// limiting factor.
    public static let lengthPrefixSize = 4

    /// Wraps `payload` as `[4-byte big-endian length][payload bytes]`.
    ///
    /// - Throws: `IHRPFramerError.frameTooLarge` if `payload.count` exceeds
    ///   `maxFrameBytes` — callers (`IHRPFrame`'s JSON encoder result, in
    ///   practice) should treat this as a programmer-error-shaped bug (a
    ///   real IHRP message should never approach the cap) rather than a
    ///   condition to silently truncate around.
    public static func encode(_ payload: Data) throws -> Data {
        guard payload.count <= maxFrameBytes else {
            throw IHRPFramerError.frameTooLarge(declaredLength: payload.count, cap: maxFrameBytes)
        }
        var prefix = UInt32(payload.count).bigEndian
        var out = Data(capacity: lengthPrefixSize + payload.count)
        withUnsafeBytes(of: &prefix) { out.append(contentsOf: $0) }
        out.append(payload)
        return out
    }
}

/// A small, stateful byte accumulator that turns an arbitrary-chunked TCP
/// byte stream back into whole IHRP frame payloads.
///
/// ELI5: Keep a scratchpad of "bytes I've received but haven't made sense of
/// yet"; every time more bytes arrive, check the scratchpad for any whole
/// messages that are now complete, hand those back, and keep whatever's
/// left (a partial message) for next time.
///
/// DETAILED: A `struct` (value type) — NOT an actor or class — so it can be
/// stored as ordinary mutable state inside whichever actor owns a given
/// connection (`TVListenerActor`/`RemoteSessionActor`) and mutated inline
/// under THAT actor's isolation, with zero extra synchronization of its
/// own. `Sendable` because `Data` is `Sendable` and there is no reference
/// semantics to worry about — a `struct` composed entirely of `Sendable`
/// stored properties is automatically `Sendable` (SE-0302).
public struct IHRPFrameDecoder: Sendable {
    private var buffer = Data()

    public init() {}

    /// How many bytes are currently buffered (a partial frame, or nothing).
    /// Exposed for tests to assert the "never buffer unboundedly" property
    /// directly, rather than only inferring it from behaviour.
    public var bufferedByteCount: Int { buffer.count }

    /// Feeds newly-received bytes in and returns every COMPLETE frame
    /// payload now available (zero, one, or several — a single `receive`
    /// callback can straddle multiple small messages, or none at all if the
    /// next message is still arriving).
    ///
    /// - Throws: `IHRPFramerError.frameTooLarge` the instant a declared
    ///   length exceeds the cap — the caller MUST drop the connection and
    ///   MUST NOT call `feed(_:)` again on this same decoder afterwards
    ///   (the byte stream's framing is no longer trustworthy once a
    ///   declared length this large has been seen; there is no "resync"
    ///   recovery for a length-prefixed protocol).
    public mutating func feed(_ data: Data) throws -> [Data] {
        buffer.append(data)
        var frames: [Data] = []

        while buffer.count >= IHRPFramer.lengthPrefixSize {
            let prefixEnd = buffer.index(buffer.startIndex, offsetBy: IHRPFramer.lengthPrefixSize)
            let prefixBytes = buffer[buffer.startIndex..<prefixEnd]
            let length = Int(UInt32(bigEndian: prefixBytes.withUnsafeBytes { rawBuffer in
                rawBuffer.loadUnaligned(as: UInt32.self)
            }))

            guard length <= IHRPFramer.maxFrameBytes else {
                throw IHRPFramerError.frameTooLarge(declaredLength: length, cap: IHRPFramer.maxFrameBytes)
            }

            // **Load-bearing ordering:** this count check MUST happen BEFORE
            // computing `frameEnd` via `buffer.index(prefixEnd, offsetBy:
            // length)` below. `Collection.index(_:offsetBy:)` (the non-
            // `limitedBy:` overload) TRAPS if the resulting index would land
            // past `endIndex` — exactly what happens here whenever the
            // payload hasn't fully arrived yet (the whole point of this
            // early-exit path). Computing the index first and checking
            // second (an earlier version of this method did that) crashes
            // on the very first split-read with a still-partial payload;
            // caught by `LANRemoteTests`' `feedSplitAcrossManyCalls` test.
            guard buffer.count >= IHRPFramer.lengthPrefixSize + length else {
                // The declared length is within budget, but the rest of the
                // payload hasn't arrived yet — stop here and wait for the
                // next `feed(_:)`. This is the ONLY early-exit path that
                // isn't an error; everything buffered so far is bounded by
                // `maxFrameBytes + lengthPrefixSize`.
                break
            }

            let frameEnd = buffer.index(prefixEnd, offsetBy: length)
            frames.append(Data(buffer[prefixEnd..<frameEnd]))
            buffer.removeSubrange(buffer.startIndex..<frameEnd)
        }

        return frames
    }
}

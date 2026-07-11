// IHRPFrame.swift
// IHLive/LANRemote
//
// ELI5: The "envelope" every IHRP message travels inside — a version stamp,
// a running number, a timestamp, and the message itself — plus the rule for
// "if two remotes said different things at almost the same moment, whose
// wins?"
//
// DETAILED: #1420 (`.claude/apple-native-strategy.md` §2.4.4: "Each control
// message carries a monotonic `seq` + timestamp (for last-writer-wins)").
// Applied uniformly to EVERY frame (not just control intents) so the TV's
// `ack`/`state`/`error` responses can cite which `seq` they're answering
// without a second, differently-shaped envelope type. `Codable`
// conformance is hand-written (this file) rather than synthesized — Swift
// does not synthesize `Codable` for a type containing an enum with
// associated values (`IHRPMessage`) — as a single flat JSON object with one
// discriminator field `t`, matching `IHModels/SongID.swift`'s existing
// "one explicit field, not Swift's internal layout" custom-`Codable`
// convention in this same package.
import Foundation
import IHModels

/// The wire envelope for one IHRP/1 message.
///
/// ELI5: "Version IHRP/1, message #42, sent at this exact moment, saying:
/// ...".
public struct IHRPFrame: Sendable, Equatable {
    public let version: String
    public let seq: UInt64
    public let timestamp: Date
    public let message: IHRPMessage

    public init(seq: UInt64, timestamp: Date, message: IHRPMessage, version: String = IHRPProtocolVersion.current) {
        self.version = version
        self.seq = seq
        self.timestamp = timestamp
        self.message = message
    }
}

/// Thrown by `IHRPFrame`'s `Decodable` conformance when a frame cannot be
/// interpreted as a valid IHRP/1 message — always fatal to the connection
/// (`TVListenerActor`/`RemoteSessionActor` drop the connection on any of
/// these, the same "fail loud, fail closed" posture `IHRPProtocolVersion`'s
/// doc comment describes for the version tag specifically).
public enum IHRPDecodingError: Error, Sendable, Equatable {
    /// The frame's `v` field didn't match `IHRPProtocolVersion.current`.
    case unsupportedVersion(String)
    /// The `t` discriminator wasn't one of the known message-kind strings.
    case unknownMessageType(String)
    /// A required field for the named message kind was missing or the wrong
    /// shape (e.g. `selectSong` with no `songId`).
    case missingField(String, forType: String)
}

// MARK: - Codable
extension IHRPFrame: Codable {
    private enum CodingKeys: String, CodingKey {
        case version = "v"
        case seq
        case timestamp = "ts"
        case type = "t"
        case token, kind, songId, componentIndex, lineIndex, index, delta
        case displayState, theme, textScale, ackSeq, errorCode, message
        case state, capabilities
    }

    // One `case` per `IHRPMessage` kind is the whole point of this custom
    // Codable conformance (this file's header comment) — an exhaustive
    // switch over 18 flat, single-purpose cases is high CYCLOMATIC
    // complexity by the metric's definition, but each branch is a short,
    // independent, easy-to-verify one-liner; splitting it into several
    // smaller functions would only relocate the same complexity behind
    // extra indirection; a wrong-branch decoding bug would still show up
    // immediately in `LANRemoteTests`' per-case round-trip coverage.
    // swiftlint:disable:next cyclomatic_complexity function_body_length
    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        let version = try container.decode(String.self, forKey: .version)
        guard version == IHRPProtocolVersion.current else {
            throw IHRPDecodingError.unsupportedVersion(version)
        }
        self.version = version
        self.seq = try container.decode(UInt64.self, forKey: .seq)
        self.timestamp = Date(timeIntervalSince1970: try container.decode(Double.self, forKey: .timestamp))

        let type = try container.decode(String.self, forKey: .type)
        switch type {
        case "hello":
            let kind = try container.decode(IHRPRemoteKind.self, forKey: .kind)
            let token = try container.decodeIfPresent(String.self, forKey: .token)
            self.message = .hello(token: token, kind: kind)
        case "prepare":
            self.message = .prepare(songId: try container.decode(SongID.self, forKey: .songId))
        case "selectSong":
            let songId = try container.decode(SongID.self, forKey: .songId)
            let componentIndex = try container.decodeIfPresent(Int.self, forKey: .componentIndex)
            let lineIndex = try container.decodeIfPresent(Int.self, forKey: .lineIndex)
            self.message = .selectSong(songId: songId, componentIndex: componentIndex, lineIndex: lineIndex)
        case "nextComponent":
            self.message = .nextComponent
        case "prevComponent":
            self.message = .prevComponent
        case "nextLine":
            self.message = .nextLine
        case "prevLine":
            self.message = .prevLine
        case "jumpLine":
            guard let index = try container.decodeIfPresent(Int.self, forKey: .index) else {
                throw IHRPDecodingError.missingField("index", forType: type)
            }
            self.message = .jumpLine(index: index)
        case "setDisplayState":
            self.message = .setDisplayState(try container.decode(IHRPDisplayState.self, forKey: .displayState))
        case "scroll":
            guard let delta = try container.decodeIfPresent(Int.self, forKey: .delta) else {
                throw IHRPDecodingError.missingField("delta", forType: type)
            }
            self.message = .scroll(delta: delta)
        case "setAppearance":
            let theme = try container.decodeIfPresent(String.self, forKey: .theme)
            let textScale = try container.decodeIfPresent(Double.self, forKey: .textScale)
            self.message = .setAppearance(theme: theme, textScale: textScale)
        case "endControl":
            self.message = .endControl
        case "ping":
            self.message = .ping
        case "state":
            self.message = .state(try container.decode(IHRPState.self, forKey: .state))
        case "ack":
            guard let ackSeq = try container.decodeIfPresent(UInt64.self, forKey: .ackSeq) else {
                throw IHRPDecodingError.missingField("ackSeq", forType: type)
            }
            self.message = .ack(ackSeq: ackSeq)
        case "error":
            let code = try container.decode(IHRPErrorCode.self, forKey: .errorCode)
            let text = try container.decodeIfPresent(String.self, forKey: .message)
            self.message = .error(code, message: text)
        case "pong":
            self.message = .pong
        case "capabilities":
            self.message = .capabilities(try container.decode(IHRPCapabilities.self, forKey: .capabilities))
        default:
            throw IHRPDecodingError.unknownMessageType(type)
        }
    }

    // Mirror of `init(from:)`'s own disable comment above — same shape,
    // same reasoning.
    // swiftlint:disable:next cyclomatic_complexity
    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(version, forKey: .version)
        try container.encode(seq, forKey: .seq)
        try container.encode(timestamp.timeIntervalSince1970, forKey: .timestamp)
        try container.encode(message.caseName, forKey: .type)

        switch message {
        case .hello(let token, let kind):
            try container.encodeIfPresent(token, forKey: .token)
            try container.encode(kind, forKey: .kind)
        case .prepare(let songId):
            try container.encode(songId, forKey: .songId)
        case .selectSong(let songId, let componentIndex, let lineIndex):
            try container.encode(songId, forKey: .songId)
            try container.encodeIfPresent(componentIndex, forKey: .componentIndex)
            try container.encodeIfPresent(lineIndex, forKey: .lineIndex)
        case .nextComponent, .prevComponent, .nextLine, .prevLine, .endControl, .ping, .pong:
            break
        case .jumpLine(let index):
            try container.encode(index, forKey: .index)
        case .setDisplayState(let state):
            try container.encode(state, forKey: .displayState)
        case .scroll(let delta):
            try container.encode(delta, forKey: .delta)
        case .setAppearance(let theme, let textScale):
            try container.encodeIfPresent(theme, forKey: .theme)
            try container.encodeIfPresent(textScale, forKey: .textScale)
        case .state(let state):
            try container.encode(state, forKey: .state)
        case .ack(let ackSeq):
            try container.encode(ackSeq, forKey: .ackSeq)
        case .error(let code, let text):
            try container.encode(code, forKey: .errorCode)
            try container.encodeIfPresent(text, forKey: .message)
        case .capabilities(let capabilities):
            try container.encode(capabilities, forKey: .capabilities)
        }
    }
}

// MARK: - Last-writer-wins ordering (strategy §2.4.4)
extension IHRPFrame {
    /// Whether `self` should win over `other` under IHRP/1's last-writer-
    /// wins conflict rule for concurrent control messages from multiple
    /// remotes.
    ///
    /// ELI5: "If two remotes both just pressed a button, which one does the
    /// TV listen to?"
    ///
    /// DETAILED: `timestamp` (each remote's own device clock) is the PRIMARY
    /// comparison — strategy §2.4.4 lists "seq+timestamp," and `timestamp`
    /// is the only one of the two that's meaningfully comparable ACROSS
    /// different remotes (a `seq` is monotonic only within one connection —
    /// two different remotes both start counting from a low number, so
    /// comparing raw `seq` values across connections would be meaningless).
    /// `seq` is the tiebreaker for the (extremely unlikely, but possible on
    /// well-synced LAN clocks) case of an exact `timestamp` collision — at
    /// that point ANY deterministic, total-ordering rule is equally
    /// defensible, and comparing `seq` at least keeps a single remote's own
    /// OUT-OF-ORDER-delivered messages (rare on LAN TCP, but not provably
    /// impossible with retries) resolved by the order it actually sent them
    /// in.
    public func isMoreRecent(than other: IHRPFrame) -> Bool {
        if timestamp != other.timestamp {
            return timestamp > other.timestamp
        }
        return seq > other.seq
    }
}

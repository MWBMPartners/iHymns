// WatchRelayMessages.swift
// IHLive/WatchRelay
//
// ELI5: The small "language" a watch and an iPhone speak to each other over
// WatchConnectivity — five buttons the watch can press ("next," "previous,"
// "lyrics," "black," "logo"), the one-paragraph summary of "what's on the TV
// right now" the watch is allowed to see, and the honest yes/no/why answer
// the phone sends back after every tap.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §3.1/§3.2 —
// BINDING). A NEW sibling folder beside `Sources/IHLive/LANRemote/` in the
// SAME `IHLive` target (so this file can reference `IHRPMessage`/
// `IHRPDisplayState`/`PairedTVRecord` directly) but a DISTINCT folder — this
// whole family never imports `Network`, never touches `RemoteSessionActor`/
// `TVListenerActor`, and `Sources/IHLive/LANRemote/` carries ZERO diff for
// this PR (spec §1, D-19 — a Network import anywhere under `WatchRelay/`
// would be a review-reject tripwire).
//
// **v1 IS the pocket design** — the foreground-only vocabulary the
// superseded `.claude/apple-phase2-pr11-spec.md` sketched never shipped, so
// there is no "v0" to stay compatible with. `.phoneBackgrounded` — that
// design's confession that backgrounding killed the relay — is deliberately
// ABSENT here: the pocket design's whole point is that a backgrounded phone
// is `.standby` (or transiently `.connecting`/`.controlling` mid-burst).
//
// **Content invariant, restated from `IHRPMessage.swift`'s own header:**
// every field below is ~200-byte navigation intent or its display-parity
// mirror — never rendered lyric text, never a fingerprint/token/nonce/code
// (spec §8 row 8's executable assertion lives in
// `WatchRelaySnapshotMappingTests`, IHFeaturesTests).
import Foundation

/// The watch↔iPhone relay protocol version this file's wire shapes ARE.
/// Bumped only on a breaking wire-shape change; `WatchRelayCodec`'s strict
/// equality probe turns install skew (a watch app lagging an iPhone update
/// by hours) into honest "update" copy on the watch (D-13), never a decode
/// crash.
///
/// ELI5: "Which version of the watch-remote language are we speaking?"
public enum WatchRelayProtocolVersion {
    public static let current = 1
}

/// The REDUCED control set a watch can send — strategy §2.4.2 exactly
/// (Decision D-5): Next/Prev component, Lyrics-restore, Blackout, Logo, and
/// a status pull. Flat `String` raw values — trivially `Codable`, a
/// self-evident wire shape a Console.app log line can show verbatim.
///
/// ELI5: The five things you can ask the TV to do from your wrist.
public enum WatchRelayCommand: String, Sendable, Codable, Equatable, CaseIterable {
    case nextComponent
    case prevComponent
    case showLyrics
    case showBlackout
    case showLogo
    /// Answered straight from the hub's current merged snapshot — never
    /// forwarded to a session or the driver (`WatchRelayRules.route(_:
    /// coordinatorPhase:)` routes this `.replyOnly` unconditionally).
    case requestState
}

/// The compact projection of the phone's remote-control world — the ONLY
/// thing the watch ever knows. Carries nothing the venue screen isn't
/// already showing publicly.
///
/// ELI5: "Here's everything your watch needs to know about the TV right
/// now" — which phase things are in, which TV, what song, what it's
/// showing.
public struct WatchRelaySnapshot: Sendable, Codable, Equatable {
    /// ELI5: "What's the big-picture situation right now?"
    public enum Phase: String, Sendable, Codable, Equatable, CaseIterable {
        /// Nothing to drive — open iHymns on the iPhone and pair a TV first.
        case noSavedTV
        /// A saved TV is known; a tap wakes the phone + reconnects. THE
        /// pocket-control headline state — the remote is FULLY ACTIVE here
        /// (spec §5), not a guidance dead-end.
        case standby
        /// A ceremony/TOFU confirm is mid-flight ON THE PHONE — finish
        /// there; never reachable from the watch itself (D-19).
        case pairing
        /// A foreground connect/reconnect OR a headless wake-connect is in
        /// flight.
        case connecting
        case controlling
    }

    public var phase: Phase
    public var tvName: String?
    /// `SongID.rawValue` — display parity with the phone header
    /// (`RemoteControlSurfaceView.swift`'s `state.songId?.rawValue`, D-6);
    /// never parsed, never fetched-for. No API call ever originates from
    /// this value.
    public var songRef: String?
    /// `IHRPDisplayState.rawValue` as a TOLERANT plain `String` (not the
    /// enum itself) — an unknown value (a newer phone build's future
    /// `IHRPDisplayState` case, or `frozen`, which the watch UI doesn't
    /// render specially) highlights nothing on the watch, never fails
    /// decode.
    public var displayState: String?
    public var revision: UInt64?

    public init(
        phase: Phase, tvName: String? = nil, songRef: String? = nil,
        displayState: String? = nil, revision: UInt64? = nil
    ) {
        self.phase = phase
        self.tvName = tvName
        self.songRef = songRef
        self.displayState = displayState
        self.revision = revision
    }

    /// Nothing paired yet — the driver's baseline before any TV has ever
    /// been paired, or a Keychain read failed (BFU, spec §1.3).
    public static let noSavedTV = WatchRelaySnapshot(phase: .noSavedTV)

    /// The at-rest, saved-but-not-connected baseline — `tvName` is the most
    /// recently connected saved TV's name, or `nil` if it isn't known yet.
    public static func standby(tvName: String?) -> WatchRelaySnapshot {
        WatchRelaySnapshot(phase: .standby, tvName: tvName)
    }
}

/// The synchronous answer to every watch command — always attached, even on
/// failure, so a failure self-heals the watch's picture of the world.
///
/// ELI5: "Here's exactly what happened when you tapped that button."
public struct WatchRelayReply: Sendable, Equatable {
    /// ELI5: "Did it work, and if not, why not?"
    public enum Outcome: String, Sendable, Codable, Equatable, CaseIterable {
        /// The intent reached the TV on a live session — including after a
        /// headless wake-connect.
        case forwarded
        /// `.requestState` — answered straight from the hub's snapshot.
        case replyOnly
        /// An honest failure — `reason` says why; `snapshot` still shows
        /// the resulting state.
        case failed
    }

    /// A tolerant vocabulary — see `WatchRelayCodec`'s custom decode of
    /// this type on `WatchRelayReply`: an unrecognized wire string decodes
    /// to `nil` rather than throwing (future-build skew safety), which is
    /// exactly why the watch's own reason-copy map (spec §5) treats
    /// `.failed` + a `nil` reason as the generic "iPhone couldn't complete
    /// that" fallback — this build's OWN encode path never produces that
    /// combination itself, so `nil` unambiguously means "the OTHER side
    /// said something we don't recognize."
    ///
    /// ELI5: "The short, specific reason something didn't work."
    public enum Reason: String, Sendable, Codable, Equatable, CaseIterable {
        /// Nothing paired (or the Keychain record was unreadable — BFU).
        case noSavedTV
        /// The phone is mid-ceremony/TOFU — finish on the iPhone.
        case busyPairing
        /// The phone's foreground screen is mid-connect — momentary, tap
        /// again.
        case busyConnecting
        /// The wake-connect failed or timed out (TV off, network changed,
        /// the 4 s connect deadline).
        case couldNotReachTV
        /// The TV revoked our saved token — re-pair on the iPhone.
        case repairNeeded
    }

    public var outcome: Outcome
    public var reason: Reason?
    /// ALWAYS attached — the watch renders from this, so even a failure
    /// self-heals the watch's picture of the world.
    public var snapshot: WatchRelaySnapshot

    public init(outcome: Outcome, reason: Reason? = nil, snapshot: WatchRelaySnapshot) {
        self.outcome = outcome
        self.reason = reason
        self.snapshot = snapshot
    }
}

// MARK: - `WatchRelayReply`'s custom `Codable` — tolerant `reason` decode

extension WatchRelayReply: Codable {
    private enum CodingKeys: String, CodingKey { case outcome, reason, snapshot }

    /// Custom (not synthesized) so an unrecognized `reason` wire string —
    /// e.g. a future build adds a `Reason` case without a protocol version
    /// bump — decodes to `nil` instead of throwing and taking the WHOLE
    /// reply down with it. `outcome`/`snapshot` stay strict: both are this
    /// protocol's own closed, version-locked vocabulary, so a mismatch
    /// there is a genuine wire-shape problem `WatchRelayCodec`'s version
    /// probe should have already caught.
    public init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        outcome = try container.decode(Outcome.self, forKey: .outcome)
        if let rawReason = try container.decodeIfPresent(String.self, forKey: .reason) {
            reason = Reason(rawValue: rawReason)
        } else {
            reason = nil
        }
        snapshot = try container.decode(WatchRelaySnapshot.self, forKey: .snapshot)
    }

    public func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(outcome, forKey: .outcome)
        try container.encodeIfPresent(reason, forKey: .reason)
        try container.encode(snapshot, forKey: .snapshot)
    }
}

// MARK: - The codec — the ONLY serializer either WCSession shell may use

/// Why a `WatchRelayCodec` decode could not produce a value.
public enum WatchRelayCodecError: Error, Sendable, Equatable {
    case malformed
    case unsupportedVersion(Int)
}

/// Single-key `[String: Data]` dictionary envelopes (built by the SHELLS,
/// not this type — see `WatchRelayReplyBox`'s doc comment for why the reply
/// path builds its dictionary INSIDE the box) under `commandKey`/
/// `replyKey`/`snapshotKey`; wire shape `{"v": 1, "p": <payload>}`.
///
/// ELI5: "Turn a command/reply/snapshot into bytes to send, and bytes back
/// into a command/reply/snapshot — safely, even from a stranger."
public enum WatchRelayCodec {
    public static let commandKey = "c"
    public static let replyKey = "r"
    public static let snapshotKey = "s"

    public static func encode(command: WatchRelayCommand) -> Data { encodeEnvelope(command) }
    public static func encode(reply: WatchRelayReply) -> Data { encodeEnvelope(reply) }
    public static func encode(snapshot: WatchRelaySnapshot) -> Data { encodeEnvelope(snapshot) }

    public static func decodeCommand(_ data: Data) -> Result<WatchRelayCommand, WatchRelayCodecError> {
        decodeEnvelope(data)
    }

    public static func decodeReply(_ data: Data) -> Result<WatchRelayReply, WatchRelayCodecError> {
        decodeEnvelope(data)
    }

    public static func decodeSnapshot(_ data: Data) -> Result<WatchRelaySnapshot, WatchRelayCodecError> {
        decodeEnvelope(data)
    }

    /// `sortedKeys` — deterministic byte order, so `WatchRelayCodecTests`'s
    /// field-creep size assertion is stable across runs.
    private static func encodeEnvelope<T: Codable>(_ payload: T) -> Data {
        let envelope = Envelope(version: WatchRelayProtocolVersion.current, payload: payload)
        let encoder = JSONEncoder()
        encoder.outputFormatting = .sortedKeys
        return (try? encoder.encode(envelope)) ?? Data()
    }

    /// Probe `{"v": Int}` FIRST (a decode failure here means the bytes
    /// aren't even shaped like an envelope ⇒ `.malformed`); a version
    /// MISMATCH is reported distinctly (`.unsupportedVersion`) before the
    /// payload itself is ever decoded — both ends ship from the same repo,
    /// so a strict equality check (never "handle N versions forever") is
    /// the right posture, mirroring `IHRPFrame`'s own protocol-version gate.
    private static func decodeEnvelope<T: Codable>(_ data: Data) -> Result<T, WatchRelayCodecError> {
        guard let probe = try? JSONDecoder().decode(VersionProbe.self, from: data) else {
            return .failure(.malformed)
        }
        guard probe.version == WatchRelayProtocolVersion.current else {
            return .failure(.unsupportedVersion(probe.version))
        }
        guard let envelope = try? JSONDecoder().decode(Envelope<T>.self, from: data) else {
            return .failure(.malformed)
        }
        return .success(envelope.payload)
    }
}

/// The `{"v": ..., "p": ...}` shape itself — `private`, an implementation
/// detail of `WatchRelayCodec` alone. Property names are spelled out
/// (`version`/`payload`, not the bare wire letters) purely to clear
/// SwiftLint's `identifier_name` minimum-length rule; `CodingKeys` is what
/// actually pins the wire shape to the single-letter `"v"`/`"p"` keys the
/// binding spec (§3.2) specifies.
private struct Envelope<T: Codable>: Codable {
    let version: Int
    let payload: T

    private enum CodingKeys: String, CodingKey {
        case version = "v"
        case payload = "p"
    }
}

/// Reads only the `v` field — lets `decodeEnvelope` tell "not JSON at all"
/// apart from "valid envelope, wrong version" before ever touching `T`.
private struct VersionProbe: Codable {
    let version: Int

    private enum CodingKeys: String, CodingKey {
        case version = "v"
    }
}

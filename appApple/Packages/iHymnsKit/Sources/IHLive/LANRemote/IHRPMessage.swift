// IHRPMessage.swift
// IHLive/LANRemote
//
// ELI5: The list of every "thing you can say" over a LAN TV-remote
// connection — "go to the next line," "show the logo," "I'm still here" —
// and the handful of things the TV can say back.
//
// DETAILED: #1420 (`.claude/apple-phase2-implementation-plan.md` §2 PR-4,
// `.claude/apple-native-strategy.md` §2.4.4 "Protocol IHRP/1"). This is the
// wire vocabulary for the LAN-DIRECT TV remote — a completely separate
// concern from `LiveFollowEngine.swift`'s server-mediated Live Follow/
// Service Mode polling in this SAME `IHLive` target (strategy §1.2: "These
// are TWO distinct subsystems in one module").
//
// **INVARIANT (binding — repeated on every payload type in this file and
// `IHRPPayloads.swift`): a message carries only ~200-byte NAVIGATION INTENT
// — a song id, a component/line index, a display-state enum case — and
// NEVER rendered lyric text.** Content is API-driven: the TV fetches
// `song_detail` (or serves its own offline cache) under its own auth the
// moment a `selectSong`/`prepare` names a song; strategy §2.4.4: "the TV
// fetches `song_detail`... itself." A future contributor tempted to add a
// `lyricLine: String` field to `IHRPState` for "efficiency" would be
// reintroducing the exact anti-pattern strategy §2.4 exists to avoid — the
// LAN link's whole design point is that it's a thin, fast REMOTE CONTROL,
// not a second content-delivery channel.
//
// `Sendable` because every value here crosses the `TVListenerActor`/
// `RemoteSessionActor` isolation boundary via Network.framework's queue-
// driven callbacks (Swift 6 strict concurrency, strategy §1.3). `Equatable`
// for straightforward round-trip assertions in `LANRemoteTests`. Codable
// conformance is CUSTOM (not synthesized — Swift does not synthesize
// `Codable` for an enum with associated values) via `IHRPFrame`'s
// `Codable` implementation in `IHRPFrame.swift`, which reads/writes a flat
// `{ "t": "<caseName>", ...fields }` JSON object — the same "one explicit
// discriminator field, not Swift's own enum layout" approach
// `IHModels/SongID.swift` already uses for ITS custom `Codable`
// conformance, chosen for the identical reason: the wire shape must stay
// stable independent of how Swift happens to lay out the enum internally.
import Foundation
import IHModels

/// The IHRP protocol version tag every `IHRPFrame` carries — strategy
/// §2.4.4: "Protocol IHRP/1 (versioned Sendable enum...)".
///
/// ELI5: "Which version of the remote-control language are we speaking?"
///
/// DETAILED: A version MISMATCH (the TV and the remote were built from
/// different, incompatible protocol revisions — e.g. a TestFlight rollout
/// mid-flight) is rejected outright by `IHRPFrame`'s decoder as
/// `.protocolVersionMismatch` (`IHRPErrorCode`) rather than attempting any
/// partial interpretation — an intentionally strict "fail loud, fail
/// closed" posture for a protocol with no independent-deployment-lifecycle
/// pressure (both ends are the SAME app; a bump here always means "these
/// two builds must be reconciled," never "handle N versions forever" the
/// way the web API's `?action=` catalogue does).
public enum IHRPProtocolVersion {
    public static let current = "IHRP/1"
}

/// One IHRP/1 message — either a control intent a remote sends, or a
/// response the TV sends back. Always carried inside an `IHRPFrame`
/// envelope (`seq`/`timestamp`/`version`), never bare.
///
/// ELI5: "Here's exactly what I want the TV to do" (if you're a remote), or
/// "here's what just happened" (if you're the TV answering).
public enum IHRPMessage: Sendable, Equatable {

    // MARK: Remote → TV (control intents, strategy §2.4.4)

    /// First message on every connection (paired reconnect OR a brand-new
    /// unpaired remote entering the pairing sub-protocol) — `token` is `nil`
    /// for a remote that has never paired with this TV before;
    /// `TVListenerActor`'s `.unpaired`/`.pairing` state machine (this
    /// module's `TVListenerActor.swift`) is what decides what happens next.
    /// **Never logged verbatim** — `token` is a high-entropy pairing
    /// credential (strategy §2.4.3); `IHLog`-facing code must reference only
    /// `caseName` below, never this case's associated value.
    case hello(token: String?, kind: IHRPRemoteKind)

    /// "I'm about to `selectSong` this — start fetching it now" (strategy
    /// §2.4.4: "a cold select adds one API RTT which `prepare` hides").
    case prepare(songId: SongID)

    /// "Show this song," optionally jumping straight to a specific
    /// component/line within it (both `nil` = "just show the song from the
    /// top").
    case selectSong(songId: SongID, componentIndex: Int?, lineIndex: Int?)

    /// TV resolves "next" against the SONG's OWN arrangement order (strategy
    /// §2.4.4: "TV resolves arrangement order") — the remote never needs to
    /// know how many components a song has.
    case nextComponent
    case prevComponent
    case nextLine
    case prevLine

    /// Jump directly to a specific line index within the current component.
    case jumpLine(index: Int)

    /// Change what the TV's canonical display is showing — `IHRPDisplayState`.
    case setDisplayState(IHRPDisplayState)

    /// A relative scroll nudge (e.g. a Siri Remote swipe or a scroll-wheel
    /// tick relayed from a remote) — `delta` is signed, unitless (the TV
    /// decides how far one unit moves).
    case scroll(delta: Int)

    /// Cosmetic, TV-local presentation hints — never content. `theme`/
    /// `textScale` are both optional so a remote can adjust just one.
    case setAppearance(theme: String?, textScale: Double?)

    /// "I'm done controlling — release me" (a clean, voluntary disconnect
    /// signal distinct from simply closing the socket, so the TV can log a
    /// deliberate hand-off rather than treating it as a dropped connection).
    case endControl

    /// Keepalive — strategy §2.4.4: "`ping`(5s)". Answered with `.pong`.
    case ping

    // MARK: TV → remotes (responses, strategy §2.4.4 — "broadcast to all connected")

    /// The TV's full current canonical state — sent on every state-changing
    /// control message's acceptance, AND broadcast unprompted to every OTHER
    /// paired remote so none of them ever needs to poll.
    case state(IHRPState)

    /// "Got it" — a lightweight acknowledgement for a control message that
    /// changed nothing display-visible enough to warrant a full `state`
    /// (e.g. `ping`→`pong` already covers keepalive; `ack` covers things
    /// like `endControl`). `ackSeq` echoes the envelope `seq` of the message
    /// being acknowledged.
    case ack(ackSeq: UInt64)

    /// A control message was rejected — see `IHRPErrorCode`.
    case error(IHRPErrorCode, message: String?)

    /// Keepalive reply to `ping`.
    case pong

    /// Sent once, right after a `hello` is accepted (paired or freshly
    /// completes pairing) — strategy §2.4.4's `capabilities` response.
    case capabilities(IHRPCapabilities)
}

// MARK: - #1420 logging-safe case name (mirrors `IHAPI/APIError.swift`'s
// `caseName` pattern exactly, for the identical reason: `IHLog` must be able
// to say WHICH message kind was sent/received without ever interpolating
// the message's associated values — `.hello`'s `token` most of all, but
// really every case, per this file's own header "never logged verbatim"
// note and the task's binding instrumentation contract ("IHRP message
// send/recv (`.debug`, type name only, never payload)").
extension IHRPMessage {
    /// Just the case name — safe to log at `.public` privacy, any level.
    public var caseName: String {
        switch self {
        case .hello: return "hello"
        case .prepare: return "prepare"
        case .selectSong: return "selectSong"
        case .nextComponent: return "nextComponent"
        case .prevComponent: return "prevComponent"
        case .nextLine: return "nextLine"
        case .prevLine: return "prevLine"
        case .jumpLine: return "jumpLine"
        case .setDisplayState: return "setDisplayState"
        case .scroll: return "scroll"
        case .setAppearance: return "setAppearance"
        case .endControl: return "endControl"
        case .ping: return "ping"
        case .state: return "state"
        case .ack: return "ack"
        case .error: return "error"
        case .pong: return "pong"
        case .capabilities: return "capabilities"
        }
    }

    /// Whether this case is one of the "Remote → TV control intents" listed
    /// in strategy §2.4.4, as opposed to a TV → remotes response.
    ///
    /// ELI5: "Is this something a remote ASKS for, or something the TV
    /// ANSWERS with?"
    ///
    /// DETAILED: `TVListenerActor`'s pairing-state confinement (an
    /// `.unpaired`/`.pairing` connection may only send `hello`/`ping` —
    /// this file's `IHRPMessage.hello`/`.ping` doc comments) needs to tell
    /// "is this a response the TV itself would send" apart from "is this a
    /// control command a remote is trying to issue" WITHOUT hand-rolling a
    /// second switch at every call site — this is that one switch, reused.
    public var isControlIntent: Bool {
        switch self {
        case .hello, .prepare, .selectSong, .nextComponent, .prevComponent,
             .nextLine, .prevLine, .jumpLine, .setDisplayState, .scroll,
             .setAppearance, .endControl, .ping:
            return true
        case .state, .ack, .error, .pong, .capabilities:
            return false
        }
    }
}

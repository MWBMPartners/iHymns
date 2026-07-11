// IHRPPayloads.swift
// IHLive/LANRemote
//
// ELI5: The small, named "shapes" an IHRP/1 message can carry — e.g. "which
// screen state is the TV in right now" or "what kind of remote is this" —
// kept in their own file so `IHRPMessage.swift` only has to hold the list of
// message KINDS, not every supporting type too.
//
// DETAILED: #1420 (`.claude/apple-phase2-implementation-plan.md` §2 PR-4,
// `.claude/apple-native-strategy.md` §2.4.4). Every type here is a small,
// closed, `Sendable`+`Codable`+`Equatable` value — the same shape discipline
// `IHAPI/APIError.swift` and `IHModels/SongID.swift` already establish in
// this package (`.claude/CLAUDE.md`'s "extract first, use second" rule
// applied to Swift, not just PHP). Split out of `IHRPMessage.swift` purely
// to keep both files under the repo's LOC-budget tripwire
// (`Scripts/loc-budget.sh`).
import Foundation
import IHModels

/// Which kind of device is on the other end of an IHRP connection.
///
/// ELI5: "Is this an iPhone, an iPad, a Mac, a Vision Pro, or an Apple Watch
/// talking through someone's iPhone?"
///
/// DETAILED: Strategy §2.4.4's `hello(token/kind)` message carries this so
/// the TV can (later, PR-6/PR-7) render "connected: Lance's iPhone" in its
/// trusted-remotes settings, and so a `.watchRelay` connection can be shown
/// with the reduced control set strategy §2.4.2 documents ("Watch → relays
/// through the paired iPhone... reduced Watch controls"). A closed `String`-
/// raw-value enum (not `VARCHAR`-style open vocabulary, unlike the web
/// backend's ENUM-vs-VARCHAR rule #20) is correct here: IHRP/1 is a
/// peer-to-peer protocol between two builds of the SAME app compiled from
/// the SAME `iHymnsKit` — there is no independent-deployment-lifecycle
/// concern, so a protocol-version bump (not a data migration) is the right
/// tool if this set ever needs to grow.
public enum IHRPRemoteKind: String, Sendable, Codable, Equatable, CaseIterable {
    case phone
    case pad
    case mac
    case vision
    /// Connected via WatchConnectivity relay through a paired iPhone's own
    /// `RemoteSessionActor` (strategy §2.4.2) — the watch itself never opens
    /// a direct `NWConnection`.
    case watchRelay
}

/// What the TV's canonical display is currently showing — strategy §2.4.4's
/// `setDisplayState(.lyrics/.blackout/.logo/.frozen)`.
///
/// ELI5: "Show the words," "black screen," "show the logo," or "freeze
/// whatever's on screen right now."
///
/// DETAILED: Deliberately DISTINCT from the web/PHP backend's Service-Mode
/// `displayState ∈ {live, blackout, logo}` vocabulary (`.claude/apple-phase2-implementation-plan.md`
/// §3 commit 1) — these are the TWO DISTINCT FEATURES strategy §2.4.1 draws
/// a hard line between (LAN remote vs. server-mediated Service Mode), each
/// with its own vocabulary evolving independently. `.frozen` (pause the
/// current projection without going blank — e.g. mid-announcement) has no
/// web/Service-Mode equivalent at all. If a venue ALSO runs a Service Mode
/// session for remote congregants, PR-6/PR-7's operator-console mirror
/// (strategy §2.4.1 "mirror-on-ack via the EXISTING `service_broadcast`")
/// is responsible for translating this into the web vocabulary — never the
/// other way around, and never by unifying the two enums.
public enum IHRPDisplayState: String, Sendable, Codable, Equatable, CaseIterable {
    case lyrics
    case blackout
    case logo
    case frozen
}

/// The TV's single canonical `(songId, componentIndex, lineIndex,
/// displayState)` state — strategy §2.4.1: "the tvOS app is the single
/// display authority owning one canonical" state; broadcast to every paired
/// remote as an IHRP `state` message any time it changes.
///
/// ELI5: "Here's exactly what's on the TV screen right now" — which song,
/// which section/line of it, and whether it's showing lyrics, black, the
/// logo, or frozen.
///
/// DETAILED: **INVARIANT (binding, `.claude/apple-phase2-implementation-plan.md`
/// §2 PR-4): this struct — like every other IHRP payload — carries only
/// ~200-byte NAVIGATION INTENT, never rendered lyric text.** `songId` is
/// `nil` before any song has ever been selected (TV just launched, showing
/// its idle/home screen). `revision` is the TV's own monotonic counter,
/// bumped every time `TVListenerActor` accepts a state-changing control
/// message — remotes use it to detect "did I miss an update" without a
/// wall-clock comparison (their own `hello`/reconnect flow can log a jump
/// in `revision` as a `.notice`, PR-6/PR-7's concern).
public struct IHRPState: Sendable, Codable, Equatable {
    public let songId: SongID?
    public let componentIndex: Int?
    public let lineIndex: Int?
    public let displayState: IHRPDisplayState
    public let revision: UInt64

    public init(songId: SongID?, componentIndex: Int?, lineIndex: Int?, displayState: IHRPDisplayState, revision: UInt64) {
        self.songId = songId
        self.componentIndex = componentIndex
        self.lineIndex = lineIndex
        self.displayState = displayState
        self.revision = revision
    }

    /// The state before anything has ever been selected — what a freshly
    /// started `TVListenerActor` starts with, and what a brand-new
    /// `hello`/`capabilities` exchange reports if no remote has ever driven
    /// this TV.
    public static let initial = IHRPState(songId: nil, componentIndex: nil, lineIndex: nil, displayState: .lyrics, revision: 0)
}

/// What a TV reports back on `hello`/`capabilities` — strategy §2.4.4's
/// `capabilities` response, and the confirmation strategy §2.4.3's pairing
/// success step (PR-6) will piggy-back on.
///
/// ELI5: "Here's what this TV can do, and here's exactly what it's showing
/// right now."
///
/// DETAILED: `maxFrameBytes` lets a remote (or a future protocol-debugging
/// tool) confirm it agrees with the TV's framing cap WITHOUT the two ends
/// needing to already trust a shared constant baked into both builds
/// separately — belt-and-braces, since both sides of a same-repo protocol
/// SHOULD already agree (`IHRPFramer.maxFrameBytes` is the one source of
/// truth either side reads), but a mismatched TV/remote build (e.g. TestFlight
/// skew during a rollout) is exactly the scenario worth being defensive
/// about.
public struct IHRPCapabilities: Sendable, Codable, Equatable {
    public let protocolVersion: String
    public let maxFrameBytes: Int
    public let currentState: IHRPState

    public init(
        protocolVersion: String = IHRPProtocolVersion.current,
        maxFrameBytes: Int = IHRPFramer.maxFrameBytes,
        currentState: IHRPState
    ) {
        self.protocolVersion = protocolVersion
        self.maxFrameBytes = maxFrameBytes
        self.currentState = currentState
    }
}

/// The closed set of reasons an IHRP `error` response can cite — strategy
/// §2.4.4's `error` response case.
///
/// ELI5: "Here's specifically why that last thing you asked for didn't
/// work."
///
/// DETAILED: `.contentUnavailable` is the EXACT case name strategy §2.4.3
/// specifies for the content-gating handoff: "a gated song → `error(.contentUnavailable)`,
/// answer = sign the TV in" — the LAN link never carries lyric content
/// (INVARIANT, this file's `IHRPState` doc comment), so the TV always
/// fetches `song_detail` under its OWN auth; if THAT fetch is denied, this
/// is what it reports back to the controlling remote rather than silently
/// failing to change the display.
public enum IHRPErrorCode: String, Sendable, Codable, Equatable, CaseIterable {
    /// The frame's `v` (protocol version tag) didn't match `IHRP/1` — the two
    /// builds disagree about the protocol itself; never worth retrying
    /// without a new build on one side.
    case protocolVersionMismatch
    /// The connection hasn't completed pairing (`TVListenerActor`'s
    /// `.unpaired`/`.pairing` states) and sent a control message anyway —
    /// this file's `IHRPMessage` doc comment.
    case unauthorized
    /// The TV's own `song_detail` fetch for the requested song was denied by
    /// content gating (`.claude/CLAUDE.md` rule #28) — strategy §2.4.3.
    case contentUnavailable
    /// The frame decoded, but its fields don't make sense together (e.g. a
    /// negative `componentIndex`).
    case malformedRequest
    /// Too many messages from this remote too quickly — a defensive ceiling
    /// PR-6/PR-7 may wire up; reserved here so the taxonomy doesn't need a
    /// protocol bump later to add it.
    case rateLimited
    /// Anything else — a bug, not a designed rejection.
    case internalError
}

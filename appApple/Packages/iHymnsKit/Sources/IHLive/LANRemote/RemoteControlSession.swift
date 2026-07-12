// RemoteControlSession.swift
// IHLive/LANRemote
//
// ELI5: The remote's own "supervisor" — the one thing the UI actually talks
// to. It owns a single connection to one TV, runs the code-pairing dance
// when needed, keeps sending little "are you still there?" pings, and — if
// the connection ever drops — waits a bit and tries again by itself,
// automatically, without the person having to do anything. Everything the
// UI needs to know ("we're connecting," "type the code," "we're in
// control," "we got disconnected, trying again in 4 seconds…") comes out of
// its ONE `events` stream.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §3.4 — BINDING shape).
// This is the remote-side mirror of the TV's own composition split
// (`TVListenerActor` = transport + pairing state; `TVRemoteControlCoordinator`
// = the `@MainActor` UI glue built ON TOP of it). Here the split is: transport
// stays entirely inside `RemoteSessionActor` (PR-4, untouched by this PR);
// supervision — the ceremony client, the ping/reconnect ladder — lives HERE,
// in a plain `IHLive` `actor`, NOT the `@MainActor` coordinator
// (`RemoteControlCoordinator`, `IHFeatures`, commit 3), because: (a) the
// `RemoteControlSessionLoopbackTests` suite (§7.5) must drive the WHOLE
// ladder headlessly, with no `@MainActor` UI ever attached; (b) a future
// Watch-relay PR reuses exactly this supervision on the iPhone with no UI
// at all; (c) the coordinator would blow the LOC budget; (d)
// `RemoteSessionActor`'s own header already assigns reconnect/replay policy
// to something OUTSIDE the transport actor — this is that something.
//
// **The single-consumer rule (§1.2, BINDING):** `RemoteSessionActor
// .phaseUpdates`/`.incomingMessages` are single-consumer `AsyncStream`s (the
// exact class of bug the PR-5 review caught). This type is the ONLY
// consumer of both — the two `for await` loops exist EXACTLY ONCE, in
// `RemoteControlSession+Link.swift`. The UI/coordinator instead consumes
// ONLY this type's OWN `events` stream. A second `for await` on either
// `RemoteSessionActor` stream anywhere else in the app silently steals
// frames from this actor — a review reject (spec §8 D-15).
import Foundation
import IHLog
import Network

/// The supervising actor that drives ONE `RemoteSessionActor` through the
/// full pairing ceremony, the 5 s ping/3-miss link-health check, and the
/// jittered exponential-backoff reconnect ladder — see this file's header
/// for why supervision lives here, not in the `@MainActor` coordinator.
///
/// ELI5: "Connect to this TV, run whatever pairing dance is needed, keep the
/// connection healthy, and automatically reconnect if it drops — and tell me
/// everything that happens along the way."
public actor RemoteControlSession {

    /// Tunables — every interval an injected value, so
    /// `RemoteControlSessionLoopbackTests` can run the ENTIRE ladder in tens
    /// of milliseconds (spec §7.5: `health.pingInterval = .milliseconds(40)`,
    /// `backoff = .init(baseDelay: 0.05, maxDelay: 0.2)`).
    public struct Configuration: Sendable {
        public var remoteKind: IHRPRemoteKind
        public var deviceName: String?
        public var health: RemoteLinkHealthState.Configuration = .init()
        public var backoff: RemoteReconnectBackoffState.Configuration = .init()
        public var clock: any LANRemoteClock = SystemLANRemoteClock()
        /// #1424 Opus-review fix M2 — how long `attachByAddress`'s
        /// Trust-On-First-Use connect (`RemoteSessionActor
        /// .connectTrustingFirstUse(to:timeout:)`, `+TOFU.swift`) waits
        /// before giving up on an address stuck in `.waiting`. Production
        /// default `10s` is unchanged; this exists so the loopback test
        /// suites (`RemoteControlSessionLoopbackTests.makeSession()`, shared
        /// by `ManualConnectLoopbackTests`) can inject a SHORT value instead
        /// — a fixed 10 s wait on the "nothing listening" case made the
        /// mandated combined 4-suite loopback run flaky/slow under
        /// concurrent load, exactly the class of tunable `health`/`backoff`
        /// above already solve the same way.
        public var tofuConnectTimeout: Duration = .seconds(10)

        public init(remoteKind: IHRPRemoteKind, deviceName: String? = nil) {
            self.remoteKind = remoteKind
            self.deviceName = deviceName
        }
    }

    /// Everything needed to (re)connect to ONE TV — `RemotePairingEntryResolver
    /// .swift`'s `RemoteConnectTarget` is the concrete type this aliases to;
    /// see that file's header for why the concrete shape lives there.
    public typealias Target = RemoteConnectTarget

    /// Everything the UI needs to know, in the order it can happen.
    public enum Event: Sendable, Equatable {
        /// `0` = the very first connect attempt; `≥1` = the ladder.
        case connecting(attempt: Int)
        /// Show/refresh the code-entry sheet — `0` = first ask.
        case awaitingCodeEntry(failedAttempts: Int)
        /// A pairing attempt ended without a token — back to browsing.
        case pairingEnded(RemotePairingFlowState.RemotePairingFlowFailure)
        /// A ceremony JUST completed — the coordinator persists `token`
        /// (Keychain) alongside `resolved` (the address that actually
        /// worked). Fired exactly once per successful ceremony; a fast-path
        /// (already-token) connect never fires this (nothing new to save).
        case paired(token: String, resolved: LANRemoteResolvedAddress?)
        /// Every TV state broadcast — including the TV's echo of our own
        /// intents (§1.1) — lands here.
        case controlling(IHRPState)
        /// The link died (3 missed pongs, or a hard transport error) while
        /// we were previously controlling — the reconnect ladder starts
        /// right after this.
        case detached
        /// The ladder is about to sleep `delay` seconds before its `attempt`
        /// -th retry.
        case reconnecting(attempt: Int, delay: TimeInterval)
        /// `setSuspended(true)` completed — the app went to the background.
        case suspended
        /// `stop()`/`endControl()` completed — deliberate, final.
        case ended
        /// #1424 — a Trust-On-First-Use manual connect (`attachByAddress`)
        /// reached `.awaitingPairing` and observed this fingerprint. The
        /// coordinator decides what happens next: an already-trusted
        /// fingerprint silently re-attaches via the pinned path (spec
        /// §4.3's known-TV shortcut, zero new trust decision); an unknown
        /// one shows the `FingerprintConfirmView` interstitial (spec §6.3,
        /// Decision D-3 — REQUIRED, never skippable) before any code is
        /// typed.
        case awaitingFingerprintConfirmation(fingerprintHex: String)
    }

    let configuration: Configuration
    let remote: RemoteSessionActor

    // MARK: - Pure policy state (§3.2/§3.3 — mutated only from this actor's isolation)

    var health: RemoteLinkHealthState
    var backoff: RemoteReconnectBackoffState
    var flow: RemotePairingFlowState?

    // MARK: - Session bookkeeping (see `+Link.swift` for how these interact)

    var target: Target?
    /// True once `.controlling` has arrived at LEAST once for the CURRENT
    /// target — reset to `false` on every fresh `attach(to:)`, flipped
    /// `true` on first arrival, and never reset back to `false` merely
    /// because the link later drops (that's exactly what makes the
    /// reconnect ladder — as opposed to a one-shot "give up" — the right
    /// reaction to a LATER drop).
    var hasEverControlled = false
    /// True only WHILE a live, `.controlling` connection is up — flips back
    /// to `false` the instant the link drops, whether or not the ladder then
    /// kicks in.
    var isAttached = false
    var isSuspended = false
    /// How many `.idle` phase transitions are CURRENTLY expected as pure
    /// housekeeping from THIS actor's own `remote.connect(...)`/`remote
    /// .disconnect()` calls — a COUNTER, not a boolean, because two such
    /// calls can legitimately queue up faster than the phase consumer drains
    /// them (e.g. `setSuspended(true)`'s disconnect immediately followed by
    /// `setSuspended(false)`'s connect, each producing their OWN `.idle`
    /// before either has been popped off the stream) — a boolean would let
    /// the FIRST (stale) `.idle` consume the suppression meant for the
    /// SECOND (current) one, leaving a genuine reaction point unprotected.
    /// See `+Link.swift`'s `handlePhase(_:)` header comment for why every
    /// `connect`/`disconnect` call causes an internal `.idle` at all.
    var expectedIdleCount = 0
    var lastKnownRevision: UInt64?

    /// #1424 — non-nil while a Trust-On-First-Use manual connect
    /// (`attachByAddress`, `+ManualConnect.swift`) is in flight, BEFORE the
    /// ceremony `flow`/`target` are armed. The TYPE lives in
    /// `+ManualConnect.swift`; the stored `var` lives here because every
    /// actor's stored properties must live in the actor body itself. Swept
    /// at EVERY terminal site (spec §4.2): `attach(to:)`, `cancelPairing()`,
    /// `endControl()`, `stop()`, `setSuspended(true)` mid-flight, and
    /// `handleIdleOrFailed()`'s terminal branch (`+Link.swift`) — an
    /// orphaned value here is the exact bug class §4.2 designs out.
    var manualConnect: ManualConnectState?
    /// #1424 — a `.pairChallenge` nonce that arrived WHILE `manualConnect
    /// != nil` but BEFORE `confirmObservedFingerprint()` armed `flow`/
    /// `target` (spec §4.2's "challenge-before-armed race": the TOFU
    /// connect auto-sends `hello`, so the TV's challenge can race the still
    /// -suspended `attachByAddress` call at loopback speed). Stashed here
    /// so it can be replayed the instant the human confirms; cleared
    /// alongside `manualConnect` at every terminal site above — gated on
    /// `manualConnect != nil` specifically so a challenge arriving AFTER a
    /// cancel/teardown (state already cleared) is dropped, never
    /// resurrected.
    var pendingChallengeNonce: String?

    var pingTask: Task<Void, Never>?
    var reconnectTask: Task<Void, Never>?
    var phaseTask: Task<Void, Never>?
    var messageTask: Task<Void, Never>?

    let eventContinuation: AsyncStream<Event>.Continuation
    /// The ONE stream the UI/coordinator consumes — never `RemoteSessionActor`'s
    /// own streams directly (this file's header).
    public nonisolated let events: AsyncStream<Event>

    public init(configuration: Configuration) {
        self.configuration = configuration
        self.remote = RemoteSessionActor(configuration: .init(kind: configuration.remoteKind, clock: configuration.clock))
        self.health = RemoteLinkHealthState(configuration: configuration.health)
        self.backoff = RemoteReconnectBackoffState(configuration: configuration.backoff)
        let (stream, continuation) = AsyncStream.makeStream(of: Event.self)
        self.events = stream
        self.eventContinuation = continuation
    }

    // MARK: - Discovery pass-through

    public func startDiscovery() async -> AsyncStream<[LANRemoteDiscoveredService]> {
        // Lazily spins up the driver loop the FIRST time anything is asked
        // of this session — a plain browsing-only screen (nothing paired
        // yet, no `attach(to:)` ever called) still needs the loop running
        // so a LATER `attach(to:)` is instantly ready.
        ensureDriverLoopStarted()
        return await remote.startDiscovery()
    }

    public func stopDiscovery() async {
        await remote.stopDiscovery()
    }

    // MARK: - Connect / pairing / control

    /// Begins connecting to `target` — a brand-new pairing ceremony
    /// (`target.token == nil`) or the fast token-based reconnect path.
    /// Resets every piece of per-target state (spec §3.4).
    ///
    /// ELI5: "Connect to this TV."
    public func attach(to target: Target) async {
        ensureDriverLoopStarted()
        pingTask?.cancel(); pingTask = nil
        reconnectTask?.cancel(); reconnectTask = nil
        // #1424 — sweeps any in-flight TOFU manual-connect state. This is
        // ALSO what the known-TV shortcut (`+ManualConnect.swift`'s
        // `handleFingerprintObservation`) relies on when it calls this
        // method mid-manual-connect: taking over an already-parked TOFU
        // connection via the ordinary pinned path.
        manualConnect = nil
        pendingChallengeNonce = nil

        self.target = target
        self.isAttached = false
        self.hasEverControlled = false
        self.isSuspended = false
        self.lastKnownRevision = nil
        backoff.reset()
        health.reset()
        self.flow = target.token == nil
            ? RemotePairingFlowState(context: .init(
                expectedFingerprint: target.expectedFingerprint, knownCode: target.knownCode, deviceName: configuration.deviceName
              ))
            : nil

        eventContinuation.yield(.connecting(attempt: 0))
        IHLog.remote.notice("lanremote.link attach-begin")
        expectedIdleCount += 1
        try? await remote.connect(to: target.endpoint, expectedFingerprint: target.expectedFingerprint, token: target.token)
    }

    /// Path B's sheet — the human typed a code.
    public func submitPairingCode(_ code: String) async {
        guard var currentFlow = flow else { return }
        let effect = currentFlow.handle(.codeEntered(code))
        flow = currentFlow
        await apply(effect)
    }

    /// The code sheet — OR (#1424) the TOFU fingerprint-confirm
    /// interstitial, before a `flow` is even armed — was dismissed by the
    /// person: disconnects and reports `.pairingEnded(.cancelled)`; never
    /// auto-retries (this is an UNPAIRED/mid-ceremony connection, spec §3.4
    /// point 3's own rule).
    public func cancelPairing() async {
        guard flow != nil || manualConnect != nil else { return }
        flow = nil
        manualConnect = nil
        pendingChallengeNonce = nil
        target = nil
        expectedIdleCount += 1
        await remote.disconnect()
        eventContinuation.yield(.pairingEnded(.cancelled))
    }

    /// The control surface's one door for sending an actual command.
    public func sendIntent(_ message: IHRPMessage) async {
        guard message.isControlIntent else {
            assertionFailure("sendIntent called with a non-control-intent message: \(message.caseName)")
            return
        }
        try? await remote.send(message)
    }

    /// A deliberate, user-initiated "stop controlling this TV" — sends the
    /// TV a clean `.endControl` hand-off signal before disconnecting (spec
    /// §3.4: "the deliberate-hand-off log on the TV").
    ///
    /// ELI5: "I'm done with this TV for now."
    public func endControl() async {
        pingTask?.cancel(); pingTask = nil
        reconnectTask?.cancel(); reconnectTask = nil
        flow = nil
        manualConnect = nil
        pendingChallengeNonce = nil
        target = nil
        hasEverControlled = false
        isAttached = false
        expectedIdleCount += 1
        try? await remote.send(.endControl)
        await remote.disconnect()
        eventContinuation.yield(.ended)
    }

    /// Full teardown — cancels every loop, disconnects, and finishes
    /// `events` (nothing more will ever be yielded). Called when the
    /// coordinator is torn down (e.g. the control screen goes away for
    /// good), never as part of ordinary backgrounding (that's
    /// `setSuspended(true)`).
    public func stop() async {
        pingTask?.cancel(); pingTask = nil
        reconnectTask?.cancel(); reconnectTask = nil
        // Cancel the consumer loops BEFORE disconnecting — `disconnect()`
        // always yields one more `.idle` phase value (`RemoteSessionActor
        // +Connection.swift`'s `disconnect()` is unconditional), which wakes
        // the suspended `for await` up; by then `Task.isCancelled` is
        // already true, so it exits cleanly instead of running
        // `handlePhase(.idle)` one last time.
        phaseTask?.cancel()
        messageTask?.cancel()
        flow = nil
        manualConnect = nil
        pendingChallengeNonce = nil
        target = nil
        hasEverControlled = false
        isAttached = false
        await remote.stopDiscovery()
        await remote.disconnect()
        eventContinuation.finish()
    }

    /// The resolved `host:port` the LIVE (or just-was-live) connection
    /// actually used — the coordinator reads this at `.paired`/first
    /// `.controlling` to persist `PairedTVRecord.lastAddress` (spec §5.2).
    /// Exists because `RemoteSessionActor.currentResolvedRemoteAddress` is
    /// `internal` to `RemoteSessionActor`'s own actor, not reachable from
    /// `IHFeatures` directly — the coordinator only ever talks to THIS type.
    public func currentResolvedAddress() async -> LANRemoteResolvedAddress? {
        await remote.currentResolvedRemoteAddress
    }

    /// Idempotent — the driver loop (the ONE consumer of both
    /// `RemoteSessionActor` streams) starts the first time anything asks
    /// this session to do real work (`startDiscovery()`/`attach(to:)`), not
    /// necessarily at `init` — a session that's never used yet (e.g. a
    /// preview) never spins up background tasks for nothing.
    func ensureDriverLoopStarted() {
        guard phaseTask == nil else { return }
        let remote = remote
        phaseTask = Task { [weak self] in
            for await phase in remote.phaseUpdates {
                guard let self, !Task.isCancelled else { break }
                await self.handlePhase(phase)
            }
        }
        messageTask = Task { [weak self] in
            for await frame in remote.incomingMessages {
                guard let self, !Task.isCancelled else { break }
                await self.handleIncoming(frame)
            }
        }
    }
}

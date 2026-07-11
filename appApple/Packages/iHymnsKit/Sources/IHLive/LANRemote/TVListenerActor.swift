// TVListenerActor.swift
// IHLive/LANRemote
//
// ELI5: The TV-side brain of the LAN remote — listens for remotes trying to
// connect, keeps track of which ones are actually trusted yet, and is the
// ONE place that knows "here's exactly what's on screen right now."
//
// DETAILED: #1420 (`.claude/apple-phase2-implementation-plan.md` §2 PR-4:
// "`TVListenerActor` — an `actor` hosting an `NWListener` (the TV side).
// Per-connection state machine: `unpaired`... → `pairing` → `paired`.").
// This file holds configuration/init/lifecycle/public read state; the
// per-connection accept/receive/send plumbing lives in
// `TVListenerActor+Connections.swift`, and the pairing-state-machine/
// message-dispatch logic lives in `TVListenerActor+Messages.swift` — three
// files over one, purely to stay under the repo's LOC-budget tripwire
// (`Scripts/loc-budget.sh`) for a genuinely large piece of behaviour.
//
// **What this actor is, and is NOT:** it is the LAN TRANSPORT + pairing-
// state boundary + "the one canonical `(songId, componentIndex, lineIndex,
// displayState)`" bookkeeping (strategy §2.4.1). It does NOT know how to
// resolve a relative `nextComponent`/`prevComponent` intent into an actual
// index — that requires knowing the CURRENTLY LOADED song's arrangement,
// which lives in `IHModels.SongDetail`/higher-layer view-model state this
// module (`IHLive`, layered below `IHFeatures` per `Package.swift`) has no
// business owning. Instead, every accepted control intent from a PAIRED
// remote is relayed UP via `controlEvents` (an `AsyncStream`, the SAME
// broadcasting idiom `IHAuth.SessionController.stateUpdates` already
// establishes in this package) to whatever DOES own that resolution — the
// tvOS projection view-model, PR-5/PR-6's job. That layer calls back down
// via `updateState(...)` once it has computed the new canonical state,
// which THIS actor then broadcasts to every OTHER paired remote. This
// keeps `TVListenerActor` a thin, protocol-correct transport that PR-5/
// PR-6 drives, rather than a second place "what does next mean" logic could
// be written twice.
import Foundation
import IHLog
import IHModels
import Network

/// Thrown by `TVListenerActor.waitUntilReady()` when the underlying
/// `NWListener` fails to bind (e.g. the requested port is already in use).
public enum TVListenerError: Error, Sendable, Equatable {
    case listenerFailed(String)
}

/// The tvOS-side actor accepting LAN remote-control connections.
///
/// ELI5: Runs on the TV; listens for remotes, decides who's allowed to give
/// commands, and tells everyone connected what's currently on screen.
public actor TVListenerActor {

    /// Everything `TVListenerActor` needs from its caller — deliberately
    /// small and fully injectable so `LANRemoteTests` never needs a real
    /// Keychain/entitlement/Bonjour-permission stack to exercise the actor
    /// (task brief: "accept an injected identity/fingerprint so it's
    /// testable").
    public struct Configuration: Sendable {
        /// The port to listen on. `.any` (0) lets the OS pick an ephemeral
        /// port — what every test in this package uses; a real tvOS app
        /// (PR-6) may pin the documented default `7269` (strategy §2.4.5:
        /// "default TCP 7269") instead, so a venue's manual "Connect by
        /// address" entry is predictable.
        public var port: NWEndpoint.Port
        /// The Bonjour instance name this TV advertises under — cosmetic
        /// (shown in a remote's discovery list); defaults to a generic
        /// placeholder since a real device name is an app-shell (PR-6)
        /// concern.
        public var advertisedName: String
        /// Whether to advertise via Bonjour (`NWListener.service`) at all.
        /// **Defaults to `true`** for real use (the tvOS app, PR-6, WITH the
        /// `NSBonjourServices`/`NSLocalNetworkUsageDescription` entitlements
        /// this task deliberately does not add here). `LANRemoteTests` sets
        /// this `false`: without those entitlements, actually registering a
        /// Bonjour service from an unsigned `swift test` binary is exactly
        /// the "Bonjour discovery... flaky on runners" hazard the task
        /// brief warns about — empirically, it doesn't just fail to
        /// discover, it can HANG the whole listener start-up waiting on a
        /// local-network-permission resolution that a headless test host
        /// can never provide. Every test in this suite connects by explicit
        /// `NWEndpoint.hostPort(...)` regardless (the task brief's required
        /// path), so advertising was never load-bearing for what these
        /// tests prove — only for the on-device discovery flow PR-6/PR-7
        /// build against.
        public var advertiseViaBonjour: Bool
        /// Hard ceiling on simultaneously-unconfirmed (`.unpaired`/
        /// `.pairing`) connections — task brief: "enforce... a concurrent-
        /// unpaired-connection cap." Defends against a flood of connect
        /// attempts consuming unbounded per-connection state before any of
        /// them has proven anything.
        public var maxUnpairedConnections: Int
        /// The pairing-state authority — see `LANRemotePairingAuthority.swift`.
        public var pairingAuthority: any LANRemotePairingAuthority
        /// The injected clock every outgoing frame's `timestamp` is drawn
        /// from — see `LANRemoteClock.swift`.
        public var clock: any LANRemoteClock

        public init(
            port: NWEndpoint.Port = .any,
            advertisedName: String = "iHymnsTV",
            advertiseViaBonjour: Bool = true,
            maxUnpairedConnections: Int = 4,
            pairingAuthority: any LANRemotePairingAuthority,
            clock: any LANRemoteClock = SystemLANRemoteClock()
        ) {
            self.port = port
            self.advertisedName = advertisedName
            self.advertiseViaBonjour = advertiseViaBonjour
            self.maxUnpairedConnections = maxUnpairedConnections
            self.pairingAuthority = pairingAuthority
            self.clock = clock
        }
    }

    /// One control intent relayed up from a PAIRED remote — see this file's
    /// header comment for why resolution happens ABOVE this actor, not
    /// inside it.
    public struct ControlEvent: Sendable, Equatable {
        public let connectionId: UUID
        public let frame: IHRPFrame
    }

    let identity: LANRemoteIdentity
    let configuration: Configuration

    /// Every currently-open connection, keyed by the id assigned at accept
    /// time — `internal` (not `private`), read/written from both this file
    /// and the two extension files this actor is split across (the same
    /// same-target-file-split visibility pattern `IHAPI/APIClient.swift`'s
    /// own doc comment documents for `session`/`bearerToken`/etc.).
    var connections: [UUID: TVRemoteConnectionState] = [:]

    var listener: NWListener?

    /// One dedicated serial queue drives the listener AND every accepted
    /// connection's Network.framework callbacks — the standard pattern for
    /// a `NWListener`-hosting actor (never `.main`: every callback here
    /// re-enters this actor via `Task { await self... }`, and blocking/
    /// waiting on `.main` while `.main` itself is the calling context would
    /// deadlock, exactly the failure mode this file's own throwaway
    /// spike script hit before switching off `.main`).
    let networkQueue = DispatchQueue(label: "app.ihymns.lanremote.tvlistener")

    /// This TV's own outgoing frame counter — distinct from any remote's
    /// own `seq`, which only orders THAT remote's own control messages.
    var outgoingSeq: UInt64 = 0

    /// The single canonical display state every paired remote sees —
    /// strategy §2.4.1. `internal(set)` (not `private(set)`) because the
    /// actual mutation happens in `TVListenerActor+Messages.swift`'s
    /// `updateState(...)` — Swift's `private`/`fileprivate` are FILE-scoped,
    /// not type-scoped, so a same-target extension in a different file
    /// needs at least `internal` write access, the identical reasoning
    /// `IHAPI/APIClient.swift`'s own doc comment gives for its `session`/
    /// `bearerToken` properties.
    public internal(set) var canonicalState: IHRPState = .initial

    /// The last control frame this actor ACCEPTED (i.e. that won last-
    /// writer-wins against whatever came before it) — `TVListenerActor+Messages.swift`'s
    /// `applyLastWriterWins(_:)` compares every new incoming control frame
    /// against this.
    var lastAcceptedControlFrame: IHRPFrame?

    let continuation: AsyncStream<ControlEvent>.Continuation

    /// A live feed of every control intent a paired remote has sent —
    /// `for await event in tvListener.controlEvents { ... }` is how PR-5/
    /// PR-6's projection view-model drives the actual display. `nonisolated`
    /// for the same reason `SessionController.stateUpdates` is: the
    /// stream/continuation pair is fixed at `init` and never reassigned.
    public nonisolated let controlEvents: AsyncStream<ControlEvent>

    /// The pairing-ceremony state machine (#1421, PR-6 spec §3.3/§3.4) —
    /// pure `struct` state OWNED by this actor and mutated exclusively
    /// under its isolation, the same "`IHRPFrameDecoder` is a struct the
    /// actor mutates" precedent `IHRPFramer.swift`'s header documents.
    /// `internal` (not `private`) so `TVListenerActor+Pairing.swift` (a
    /// same-target extension file) and `+Messages.swift`'s `handlePairConfirm`
    /// dispatch can both read/mutate it.
    var pairingCeremony = LANRemotePairingCeremonyState()

    let pairingContinuation: AsyncStream<TVPairingEvent>.Continuation

    /// A live feed of pairing-ceremony transitions — `TVRemoteControlCoordinator`
    /// (`IHFeatures`, PR-6) drives the pairing overlay's UI state off this,
    /// the same `controlEvents` idiom above. `nonisolated` for the
    /// identical reason.
    public nonisolated let pairingEvents: AsyncStream<TVPairingEvent>

    public init(identity: LANRemoteIdentity, configuration: Configuration) {
        self.identity = identity
        self.configuration = configuration
        let (stream, continuation) = AsyncStream.makeStream(of: ControlEvent.self)
        self.controlEvents = stream
        self.continuation = continuation
        let (pairingStream, pairingContinuation) = AsyncStream.makeStream(of: TVPairingEvent.self)
        self.pairingEvents = pairingStream
        self.pairingContinuation = pairingContinuation
    }

    /// The actual bound port once `start()` has succeeded — `nil` before
    /// starting or after `stop()`. Tests (and a real "Settings → Remote
    /// Control info" screen, strategy §2.4.5) read this to know where to
    /// connect.
    public var boundPort: NWEndpoint.Port? { listener?.port }

    /// Continuations waiting on `waitUntilReady()` — resumed the moment the
    /// listener reaches `.ready`/`.failed`. Exists so tests (and any real
    /// caller that needs to know the bound port is live before advertising
    /// it) never have to poll/sleep for what is otherwise pure
    /// `NWListener` async setup latency.
    var readyContinuations: [CheckedContinuation<Void, Error>] = []

    /// Set the instant `onListenerStateChanged` observes `.failed` — read by
    /// `waitUntilReady()` to cover the race where the listener fails BEFORE
    /// anything ever calls `waitUntilReady()` (`listener.start(queue:)` is
    /// fire-and-forget; a bind failure can be reported within microseconds,
    /// well before a caller's NEXT `await` reaches this actor). Without this,
    /// a late `waitUntilReady()` call would see neither `.ready` nor a
    /// pending continuation to resume it — and hang forever, since the ONE
    /// `.failed` event that would have resolved it already fired and found
    /// no waiters. Caught by `LANRemoteLoopbackTests` hanging outright rather
    /// than failing fast.
    var listenerFailure: Error?

    /// Suspends until the listener has finished binding (or throws if it
    /// failed — INCLUDING a failure that already happened before this was
    /// called) — a no-op if it's already `.ready`.
    ///
    /// ELI5: "Wait until the TV's remote-control receiver has actually
    /// finished turning on" — and if it already failed to turn on before I
    /// even asked, tell me that instead of waiting forever for an "on"
    /// signal that's never coming.
    public func waitUntilReady() async throws {
        if listener?.state == .ready { return }
        if let listenerFailure { throw listenerFailure }
        try await withCheckedThrowingContinuation { continuation in
            readyContinuations.append(continuation)
        }
    }

    /// Starts listening for connections — builds the TLS 1.3 server options
    /// from `identity`, advertises via Bonjour under `LANRemoteDiscovery.bonjourServiceType`,
    /// and begins accepting.
    ///
    /// ELI5: "Turn the TV's remote-control receiver on."
    public func start() throws {
        let tlsOptions = try identity.makeServerTLSOptions()
        let parameters = NWParameters(tls: tlsOptions, tcp: .init())
        let listener = try NWListener(using: parameters, on: configuration.port)
        if configuration.advertiseViaBonjour {
            listener.service = NWListener.Service(name: configuration.advertisedName, type: LANRemoteDiscovery.bonjourServiceType)
        }

        listener.stateUpdateHandler = { [weak self] state in
            guard let self else { return }
            Task { await self.onListenerStateChanged(state) }
        }
        listener.newConnectionHandler = { [weak self] connection in
            guard let self else { return }
            Task { await self.handleNewConnection(connection) }
        }

        self.listener = listener
        listener.start(queue: networkQueue)
        IHLog.discovery.notice("lanremote.listener start fingerprint=\(self.identity.fingerprint, privacy: .public)")
    }

    /// Stops listening and closes every open connection.
    ///
    /// ELI5: "Turn the TV's remote-control receiver off."
    public func stop() {
        listener?.cancel()
        listener = nil
        for id in Array(connections.keys) {
            teardownConnection(id: id, reason: "listener stopped")
        }
        IHLog.discovery.notice("lanremote.listener stop")
    }

    func onListenerStateChanged(_ state: NWListener.State) async {
        switch state {
        case .ready:
            IHLog.discovery.notice("lanremote.listener ready port=\(self.listener?.port?.rawValue ?? 0, privacy: .public)")
            for continuation in readyContinuations { continuation.resume() }
            readyContinuations.removeAll()
        case .failed(let error):
            IHLog.discovery.error("lanremote.listener failed error=\(String(describing: error), privacy: .public)")
            let mapped = TVListenerError.listenerFailed(String(describing: error))
            listenerFailure = mapped
            for continuation in readyContinuations {
                continuation.resume(throwing: mapped)
            }
            readyContinuations.removeAll()
        default:
            break
        }
    }

    /// Diagnostics — how many connections are currently in each pairing
    /// phase, and which connection ids are parked in `.pairing` awaiting
    /// PR-6's ceremony UI (or a test's direct `completePairing(...)` call)
    /// to confirm them. `LANRemoteTests` uses all three directly; a future
    /// diagnostics overlay (strategy §3.4's obs-P2 item) and PR-6's
    /// trusted-remotes settings screen could too.
    public var pairedConnectionCount: Int {
        connections.values.filter { $0.pairingPhase.isPaired }.count
    }
    public var unpairedConnectionCount: Int {
        connections.values.filter { !$0.pairingPhase.isPaired }.count
    }
    public var pairingConnectionIds: [UUID] {
        connections.values.filter { $0.pairingPhase == .pairing }.map(\.id)
    }
}

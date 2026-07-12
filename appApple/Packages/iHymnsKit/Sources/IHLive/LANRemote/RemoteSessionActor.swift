// RemoteSessionActor.swift
// IHLive/LANRemote
//
// ELI5: The remote-device side of the LAN remote — finds TVs on the
// network (or connects to one you typed the address of), checks the TV
// really is who it claims to be, and then lets the UI (PR-7) send it
// commands and watch what it says back.
//
// DETAILED: #1420 (`.claude/apple-phase2-implementation-plan.md` §2 PR-4:
// "`RemoteSessionActor` — an `actor` (the remote-device side): `NWBrowser`
// for `_ihymns-remote._tcp` discovery + `NWConnection` connect; seq+
// timestamp last-writer-wins send; cert pin-before-trust (accept an
// expected fingerprint, reject a mismatch). Expose a small async API the
// UI (PR-7) will drive."). Discovery/browsing lives in this file;
// connect/send/receive lives in `RemoteSessionActor+Connection.swift`
// (same LOC-budget file split as `TVListenerActor`).
//
// Every stream this actor exposes follows the `IHAuth.SessionController
// .stateUpdates` idiom already established in this package: an
// `AsyncStream` a SwiftUI view model can `for await` over, never Combine
// (strategy §1.3).
import Foundation
import IHLog
import Network

/// Everything an IHRP send can go wrong for, on the remote-device side.
///
/// ELI5: The list of ways "send this command to the TV" can fail.
public enum RemoteSessionError: Error, Sendable, Equatable {
    /// No open, TLS-ready connection to send on.
    case notConnected
    /// `IHRPFrame`'s JSON encoding, or `IHRPFramer`'s TLV wrap, failed —
    /// should be unreachable for any well-formed `IHRPMessage` (this
    /// module's own construction always produces one), but surfaced rather
    /// than force-unwrapped.
    case encodingFailed
    /// The connection failed at the transport/TLS layer — includes the
    /// pin-mismatch case: a rejected certificate makes TLS negotiation
    /// itself fail, which Network.framework reports here indistinguishably
    /// from any other TLS/transport failure. The DEFINITIVE signal that a
    /// given failure WAS a pin mismatch is the `.fault`-level `IHLog.remote`
    /// line `RemoteSessionActor+Connection.swift`'s verify block writes —
    /// see this module's binding instrumentation contract.
    case transport(String)
}

/// What a `RemoteSessionActor` is currently doing.
///
/// ELI5: "Not connected to anything" / "connecting" / "connected, waiting
/// to be let in" / "connected and in control" / "that didn't work."
public enum RemoteSessionPhase: Sendable, Equatable {
    case idle
    case connecting
    /// TLS handshake succeeded (the TV's certificate matched the pinned
    /// fingerprint) and `hello` has been sent, but the TV hasn't yet
    /// confirmed pairing — mirrors `TVListenerActor`'s `.pairing` state on
    /// the other end.
    case awaitingPairing
    /// Paired and receiving `state` updates from the TV.
    case controlling(IHRPState)
    case failed(RemoteSessionError)

    /// Whether a message can be sent right now — true once the transport
    /// itself is up, even before pairing completes (a `hello` needs to be
    /// sendable in `.awaitingPairing`; the TV enforces confinement on its
    /// own side, this actor doesn't need to duplicate that policy).
    var isConnected: Bool {
        switch self {
        case .awaitingPairing, .controlling: return true
        case .idle, .connecting, .failed: return false
        }
    }
}

/// The remote-device-side actor for the LAN TV remote.
///
/// ELI5: Runs on the phone/iPad/Mac/Vision (or is driven by the paired
/// iPhone on behalf of a Watch, strategy §2.4.2); finds and controls a TV.
public actor RemoteSessionActor {
    /// What this actor identifies itself as in its `hello` message.
    public struct Configuration: Sendable {
        public var kind: IHRPRemoteKind
        public var clock: any LANRemoteClock

        public init(kind: IHRPRemoteKind, clock: any LANRemoteClock = SystemLANRemoteClock()) {
            self.kind = kind
            self.clock = clock
        }
    }

    let configuration: Configuration
    let networkQueue = DispatchQueue(label: "app.ihymns.lanremote.remotesession")

    var browser: NWBrowser?
    var discoveryContinuation: AsyncStream<[LANRemoteDiscoveredService]>.Continuation?

    var connection: NWConnection?
    var decoder = IHRPFrameDecoder()
    var outgoingSeq: UInt64 = 0
    var pendingConnectContinuation: CheckedContinuation<Void, Error>?
    var expectedFingerprint: String?
    var pairingToken: String?

    /// The actual `host:port` the current (or just-was-current) connection
    /// resolved to, captured off `NWConnection.currentPath?.remoteEndpoint`
    /// the instant a connection reaches `.ready` (`+Connection.swift`'s
    /// `onConnectionStateChanged`) — `nil` before any connection has ever
    /// gone `.ready`, and cleared every time `disconnect()` runs. #1422
    /// (`.claude/apple-phase2-pr7-spec.md` §2/§5.2): PR-7's
    /// `RemoteControlSession` reads this via `currentResolvedRemoteAddress`
    /// to persist `PairedTVRecord.lastAddress` — additive-only, no existing
    /// connect/verify/disconnect behaviour changes.
    var resolvedRemoteAddress: LANRemoteResolvedAddress?

    /// The public accessor for `resolvedRemoteAddress` above.
    ///
    /// ELI5: "What address is this connection actually using right now?"
    public var currentResolvedRemoteAddress: LANRemoteResolvedAddress? { resolvedRemoteAddress }

    /// The most recently known pairing token — the value to persist
    /// (Keychain, `synchronizable: false`, PR-7's job) so the NEXT
    /// `connect(to:expectedFingerprint:token:)` call can take the fast
    /// `hello(token:)` reconnect path instead of re-running the ceremony.
    /// Set from the CONNECT-supplied token immediately (#1420) and
    /// OVERWRITTEN the moment a fresh `.pairSuccess(token:)` arrives
    /// (#1421, `RemoteSessionActor+Connection.swift`'s `handleIncomingFrameData`)
    /// — a brand-new ceremony's token always supersedes whatever was
    /// passed in at connect time.
    ///
    /// ELI5: "What's the current secret password this remote uses to prove
    /// it's already trusted?" — PR-7's UI reads this after a successful
    /// pairing so it can save it for next time.
    public var currentPairingToken: String? { pairingToken }

    public private(set) var phase: RemoteSessionPhase = .idle
    let phaseContinuation: AsyncStream<RemoteSessionPhase>.Continuation
    /// A live feed of every phase transition — the UI (PR-7) drives its
    /// connection-ladder screen off this.
    public nonisolated let phaseUpdates: AsyncStream<RemoteSessionPhase>

    let messageContinuation: AsyncStream<IHRPFrame>.Continuation
    /// A live feed of every frame received from the TV.
    public nonisolated let incomingMessages: AsyncStream<IHRPFrame>

    public init(configuration: Configuration) {
        self.configuration = configuration
        let (phaseStream, phaseContinuation) = AsyncStream.makeStream(of: RemoteSessionPhase.self)
        self.phaseUpdates = phaseStream
        self.phaseContinuation = phaseContinuation
        let (messageStream, messageContinuation) = AsyncStream.makeStream(of: IHRPFrame.self)
        self.incomingMessages = messageStream
        self.messageContinuation = messageContinuation
    }

    func setPhase(_ newPhase: RemoteSessionPhase) {
        phase = newPhase
        phaseContinuation.yield(newPhase)
    }

    // MARK: - Discovery (strategy §2.4.2/§2.4.5)
    //
    // Task brief: "connect by explicit `NWEndpoint.hostPort`, do NOT rely
    // on Bonjour discovery in CI — it's flaky on runners." — this method
    // exists for the real app (PR-7's discovery-list UI) and is exercised
    // in `LANRemoteTests` only for its PURE result-mapping/permission-
    // detection helpers, never a live browse.

    /// Starts browsing for `_ihymns-remote._tcp` TVs on the local network.
    /// Calling this again while already browsing restarts discovery.
    ///
    /// ELI5: "Start looking for TVs."
    public func startDiscovery() -> AsyncStream<[LANRemoteDiscoveredService]> {
        stopDiscovery()

        let (stream, continuation) = AsyncStream.makeStream(of: [LANRemoteDiscoveredService].self)
        discoveryContinuation = continuation

        let browser = NWBrowser(for: .bonjour(type: LANRemoteDiscovery.bonjourServiceType, domain: nil), using: .tcp)
        browser.stateUpdateHandler = { [weak self] state in
            guard let self else { return }
            Task { await self.onBrowserStateChanged(state) }
        }
        browser.browseResultsChangedHandler = { [weak self] results, _ in
            guard let self else { return }
            Task { await self.onBrowseResultsChanged(results) }
        }
        self.browser = browser
        browser.start(queue: networkQueue)
        IHLog.discovery.notice("lanremote.browser start")
        return stream
    }

    /// Stops browsing — idempotent.
    ///
    /// ELI5: "Stop looking for TVs."
    public func stopDiscovery() {
        guard browser != nil else { return }
        browser?.cancel()
        browser = nil
        discoveryContinuation?.finish()
        discoveryContinuation = nil
        IHLog.discovery.notice("lanremote.browser stop")
    }

    func onBrowserStateChanged(_ state: NWBrowser.State) async {
        switch state {
        case .waiting(let error):
            // The single most valuable line this category ever carries
            // (task brief) — a missing/denied Local Network permission
            // surfaces as `NWError.dns(kDNSServiceErr_PolicyDenied)`.
            if Self.isLocalNetworkPermissionDenied(error) {
                IHLog.discovery.error("lanremote.discovery local-network-permission-denied")
            }
        case .failed(let error):
            IHLog.discovery.error("lanremote.discovery failed error=\(String(describing: error), privacy: .public)")
        default:
            break
        }
    }

    func onBrowseResultsChanged(_ results: Set<NWBrowser.Result>) async {
        let services: [LANRemoteDiscoveredService] = results.compactMap { result in
            guard case .service(let name, _, _, _) = result.endpoint else { return nil }
            return LANRemoteDiscoveredService(name: name, endpoint: result.endpoint)
        }
        IHLog.discovery.notice("lanremote.discovery result-set-changed count=\(services.count, privacy: .public)")
        for service in services {
            IHLog.discovery.debug("lanremote.discovery result name=\(service.name, privacy: .private)")
        }
        discoveryContinuation?.yield(services)
    }

    /// `NWError.dns(kDNSServiceErr_PolicyDenied)` is the documented shape
    /// Local Network permission denial takes for a Bonjour browse (Apple's
    /// "Support local network privacy" guidance).
    static func isLocalNetworkPermissionDenied(_ error: NWError) -> Bool {
        if case .dns(let dnsErrorCode) = error {
            return dnsErrorCode == kDNSServiceErr_PolicyDenied
        }
        return false
    }
}

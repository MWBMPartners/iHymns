// TVRemoteControlCoordinator.swift
// IHFeatures
//
// ELI5: The one object that actually "turns on" the TV's remote-control
// receiver — builds its identity, starts listening, watches for remotes
// trying to pair, and feeds everything the pairing overlay / Settings
// screen need to show ("here's the code," "1 remote is trying to pair,"
// "here's who's trusted"). It's the ONLY thing in the whole app that talks
// to both the LAN listener AND the projection screen.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §6.1/§6.2). The
// composition root PR-5's own spec §1 reserved ("the listener ↔
// `ProjectionViewModel` bridge PR-6's job"): builds `LANRemoteIdentityStore`
// + `KeychainLANRemotePairingAuthority` + `TVListenerActor` (all `IHLive`),
// and runs the three long-lived tasks that connect them to
// `ProjectionViewModel` (this same target) — `TVProjectionBridge.swift`'s
// pure mapping is what those tasks actually call.
//
// **No `import Network` here** (spec §8 D-11's scope tripwire): the ONE
// port literal this file needs (`7269`, strategy §2.4.5's documented
// default) is passed as a bare integer literal to `TVListenerActor
// .Configuration`'s `port: NWEndpoint.Port` parameter — `NWEndpoint.Port`
// conforms to `ExpressibleByIntegerLiteral` (confirmed against the SDK's
// module interface), so Swift resolves the literal via the parameter's
// already-visible type without this file ever needing to name
// `NWEndpoint`/`Network` itself. The coordinator talks to `IHLive`'s
// actors, never to a raw socket.
import IHLive
import IHLog
import Observation
#if os(tvOS)
import UIKit
#endif

/// The tvOS app's ONE remote-control composition root — builds the
/// listener, runs its bridge to `ProjectionViewModel`, and exposes
/// everything the pairing overlay / Settings screen need to render.
///
/// ELI5: "Turn on the TV's remote-control receiver, and tell me everything
/// happening with it."
@MainActor
@Observable
public final class TVRemoteControlCoordinator {
    /// The Settings "Remote Control info" panel's exact data — strategy
    /// §2.4.5.
    public struct ListenerInfo: Sendable, Equatable {
        public let fingerprint: String
        public let port: UInt16
        public let ipAddress: String?
    }

    public private(set) var listenerInfo: ListenerInfo?
    /// A themed, user-facing message if `start()` failed outright — the
    /// raw error itself only ever goes to `IHLog.remote` (status codes,
    /// never key material).
    public private(set) var startError: String?
    public private(set) var isPairingActive = false
    /// The code currently on screen, if a ceremony is running — the
    /// overlay reads this directly rather than caching its own copy.
    public private(set) var pairingCode: String?
    /// How many `.pairing` connections are open RIGHT NOW — the overlay's
    /// "N remotes trying to pair" live indicator.
    public private(set) var pairingRemoteCount = 0
    public private(set) var pairedRemotes: [PairedRemoteRecord] = []
    /// The most recently paired remote's name — the overlay's transient
    /// success banner.
    public private(set) var lastPairedName: String?
    /// A transient operator-facing notice — set when the listener auto-
    /// disarms the ceremony after too many wrong codes (the brute-force
    /// ceiling, post-#1421 adversarial-review fix), shown in Settings' Pair
    /// section; cleared the next time `beginPairing()` runs.
    public private(set) var pairingNotice: String?

    private let projectionViewModel: ProjectionViewModel
    private var listener: TVListenerActor?
    private var authority: KeychainLANRemotePairingAuthority?
    private var hasStarted = false

    private var bridgeInTask: Task<Void, Never>?
    private var bridgeOutTask: Task<Void, Never>?
    private var pairingEventsTask: Task<Void, Never>?

    public init(projectionViewModel: ProjectionViewModel) {
        self.projectionViewModel = projectionViewModel
    }

    /// Builds the identity + authority + listener, starts accepting
    /// connections, and spawns the three long-lived bridge tasks — idempotent
    /// (a second call while already started is a harmless no-op, matching
    /// `RootContainerView.restoreAndSync()`'s own "guarded one-shot" `.task`
    /// convention elsewhere in this package).
    ///
    /// ELI5: "Turn the TV's remote-control receiver on" — safe to ask for
    /// twice, it only actually happens once.
    public func start() async {
        guard !hasStarted else { return }
        hasStarted = true

        let name = deviceName
        do {
            let identity = try LANRemoteIdentityStore.loadOrCreate(commonName: name)
            let authority = KeychainLANRemotePairingAuthority()
            self.authority = authority

            let listener = try await makeListener(identity: identity, authority: authority, advertisedName: name)
            self.listener = listener

            listenerInfo = ListenerInfo(
                fingerprint: identity.fingerprint,
                port: await listener.boundPort?.rawValue ?? 0,
                ipAddress: LANRemoteAddress.primaryIPv4Address()
            )

            spawnBridgeTasks(listener: listener)
            pairedRemotes = await authority.listPairedRemotes()
        } catch {
            IHLog.remote.error("lanremote.coordinator start-failed error=\(String(describing: error), privacy: .public)")
            startError = "Couldn't start the remote-control receiver. Try restarting the app."
        }
    }

    /// Binds the documented default port, retrying ONCE with an OS-assigned
    /// port if something else already holds it — spec §6.1/Decision D-10:
    /// "another app squatting 7269 must not kill the feature." Deliberately
    /// never spells `NWEndpoint.Port` as a type name anywhere in this file
    /// (this file's header) — `usingDefaultPort` picks between two literal
    /// `TVListenerActor.Configuration(port:)` call sites instead, so the
    /// PORT VALUE's type is always inferred from that already-visible
    /// parameter, never written out here.
    private func makeListener(
        identity: LANRemoteIdentity, authority: KeychainLANRemotePairingAuthority, advertisedName: String
    ) async throws -> TVListenerActor {
        do {
            return try await startListener(usingDefaultPort: true, identity: identity, authority: authority, advertisedName: advertisedName)
        } catch TVListenerError.listenerFailed {
            IHLog.remote.notice("lanremote.coordinator default-port-unavailable retrying-with-any-port")
            return try await startListener(usingDefaultPort: false, identity: identity, authority: authority, advertisedName: advertisedName)
        }
    }

    private func startListener(
        usingDefaultPort: Bool, identity: LANRemoteIdentity, authority: KeychainLANRemotePairingAuthority, advertisedName: String
    ) async throws -> TVListenerActor {
        let configuration = usingDefaultPort
            // `7269` — strategy §2.4.5's documented default port, now ALSO
            // the shared `LANRemoteDiscovery.defaultPort: UInt16` constant
            // (#1424, D-13) `LANRemoteManualAddress`/`ManualConnectSheet`
            // read. **Deliberately kept as a literal HERE, not a reference
            // to that constant** — `LANRemoteDiscovery.defaultPort` is a
            // concrete `UInt16`, and this parameter is `NWEndpoint.Port`;
            // Swift's `ExpressibleByIntegerLiteral` conversion only fires
            // for a literal token at the call site (this file's own header
            // comment above), never for an already-typed `UInt16` value —
            // spelling `NWEndpoint.Port(rawValue:)` explicitly to bridge
            // the two would require `import Network` in this file, which
            // this file's header deliberately avoids (spec §8 D-11 applies
            // the same tripwire to IHFeatures views/coordinators generally).
            // `LANRemoteManualAddressTests`/`IHSettingsStoreTests` assert
            // `LANRemoteDiscovery.defaultPort == 7269` literally, so this
            // literal drifting from that constant would be caught there.
            ? TVListenerActor.Configuration(
                port: 7269, advertisedName: advertisedName, pairingAuthority: authority, clock: SystemLANRemoteClock()
              )
            : TVListenerActor.Configuration(
                port: .any, advertisedName: advertisedName, pairingAuthority: authority, clock: SystemLANRemoteClock()
              )
        let listener = TVListenerActor(identity: identity, configuration: configuration)
        try await listener.start()
        try await listener.waitUntilReady()
        return listener
    }

    // MARK: - Pairing control surface (the overlay/Settings "Pair" button)

    public func beginPairing() async {
        guard let listener else { return }
        pairingNotice = nil
        pairingCode = await listener.beginPairing()
        isPairingActive = true
    }

    public func rotateCode() async {
        guard let listener else { return }
        pairingCode = await listener.rotatePairingCode()
    }

    public func endPairing() async {
        guard let listener else { return }
        await listener.endPairing()
        isPairingActive = false
        pairingCode = nil
    }

    /// Settings "Revoke" — forgets the token (so a future `hello` fails)
    /// AND disconnects it right now if it's currently live.
    public func revoke(_ record: PairedRemoteRecord) async {
        guard let authority, let listener else { return }
        await authority.revoke(tokenHash: record.tokenHashHex)
        await listener.disconnectPaired(tokenHash: record.tokenHashHex)
        pairedRemotes = await authority.listPairedRemotes()
    }

    /// Encodes this TV's current connect info (+ live code, if requested)
    /// as the exact string the overlay/Settings QR renders.
    ///
    /// - Parameter includeCode: `true` for the ceremony QR (overlay);
    ///   `false` for the Settings standing QR, which never carries a code.
    public func qrPayloadString(includeCode: Bool) -> String? {
        guard let info = listenerInfo else { return nil }
        let payload = LANRemotePairingPayload(
            name: Self.deviceNameStatic,
            host: LANRemoteAddress.primaryIPv4Address(),
            port: info.port,
            fingerprintHex: info.fingerprint,
            code: includeCode ? pairingCode : nil
        )
        return payload.encodeToQRString()
    }

    private var deviceName: String { Self.deviceNameStatic }

    private static var deviceNameStatic: String {
        #if os(tvOS)
        UIDevice.current.name
        #else
        "iHymns TV"
        #endif
    }

    // MARK: - The listener <-> ProjectionViewModel bridge (PR-5 spec §1's reserved seam)

    /// Spawns the three long-lived tasks this coordinator retains for its
    /// whole lifetime — PR-5 spec §1's "~30-line bridge," made real.
    private func spawnBridgeTasks(listener: TVListenerActor) {
        let projectionViewModel = projectionViewModel
        bridgeInTask = Task { [weak self] in
            for await event in listener.controlEvents {
                if let reply = await TVProjectionBridge.apply(event.frame.message, to: projectionViewModel),
                   case .error(let code, let text) = reply {
                    await listener.sendError(code, message: text, to: event.connectionId)
                }
                await self?.refreshPairedRemotesIfNeeded()
            }
        }
        bridgeOutTask = Task {
            for await state in projectionViewModel.stateUpdates {
                await listener.updateState(
                    songId: state.songId, componentIndex: state.componentIndex,
                    lineIndex: state.lineIndex, displayState: state.displayState
                )
            }
        }
        pairingEventsTask = Task { [weak self] in
            for await event in listener.pairingEvents {
                await self?.applyPairingEvent(event)
            }
        }
    }

    /// A no-op today — kept as the ONE place `bridgeInTask` could refresh
    /// derived UI state after a control intent, without adding a second
    /// polling path; reserved for a future "trusted remotes" activity
    /// indicator, deliberately not overbuilt for this PR.
    private func refreshPairedRemotesIfNeeded() async {}

    private func applyPairingEvent(_ event: TVPairingEvent) async {
        switch event {
        case .remoteEnteredPairing:
            pairingRemoteCount += 1
        case .remoteLeftPairing:
            pairingRemoteCount = max(0, pairingRemoteCount - 1)
        case .codeRotated(let newCode):
            // In-process only — NEVER logged (this module's binding
            // instrumentation contract).
            pairingCode = newCode
        case .paired(let name, _):
            lastPairedName = name
            if let authority {
                pairedRemotes = await authority.listPairedRemotes()
            }
        case .proofRejected:
            break
        case .ceremonyExhausted:
            // The listener disarmed the ceremony after too many wrong proofs
            // (the cumulative brute-force ceiling) — close the overlay and
            // tell the operator why. A fresh `beginPairing()` clears the
            // notice and re-opens pairing.
            isPairingActive = false
            pairingCode = nil
            pairingNotice = "Pairing was stopped after too many incorrect codes. Tap “Pair a remote” to try again."
        }
    }
}

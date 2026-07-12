// RemoteControlCoordinator.swift
// IHFeatures
//
// ELI5: The one object the remote-control screens actually talk to — it
// owns the paired-TV address book and the connection supervisor, turns
// scanned QR codes / tapped list rows into real connection attempts, saves
// a TV the moment pairing succeeds, and boils everything happening down
// into ONE simple "what should the screen show right now?" value.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §6.2 — the
// `TVRemoteControlCoordinator` mirror, phone side). Builds `KeychainPairedTVStore`
// + `RemoteControlSession` and consumes ONLY `session.events` (never
// `RemoteSessionActor`'s own streams — the single-consumer rule
// `RemoteControlSession.swift`'s header restates) plus the discovery stream
// `session.startDiscovery()` returns. `RemotePairingEntryResolver` (`IHLive`)
// is the ONE place entry-path decisions get made — this file never
// improvises a connect target of its own.
import Foundation
import IHLive
import IHLog
import Observation

/// The phone/iPad/Mac/Vision composition root for the LAN TV remote — see
/// this file's header for the full picture.
///
/// ELI5: "Here's every TV I know about, here's what the screen should show,
/// and here's how to connect to one."
@MainActor
@Observable
public final class RemoteControlCoordinator {

    /// Everything the browsing/connecting/controlling screens render from —
    /// the PURE `uiPhase(after:current:tvName:)` mapping (below) is what
    /// turns a `RemoteControlSession.Event` into one of these.
    public enum UIPhase: Equatable {
        case browsing
        case connecting(tvName: String, attempt: Int)
        case codeEntry(tvName: String, failedAttempts: Int)
        case controlling(tvName: String, state: IHRPState)
        case reconnecting(tvName: String, attempt: Int)
        case suspended(tvName: String)
    }

    public private(set) var uiPhase: UIPhase = .browsing
    /// Resolver-built (`RemotePairingEntryResolver.listRows`) — saved TVs
    /// first, then unpaired nearby ones, rebuilt whenever either the saved
    /// list or the discovered set changes.
    public private(set) var rows: [RemoteTVListRow] = []
    /// A transient, inline themed notice — "Not a valid iHymns pairing
    /// code," "ask the operator for a fresh code," etc. Cleared on the next
    /// successful action.
    public private(set) var notice: String?
    /// Set when Bonjour browsing itself was denied Local Network
    /// permission. **Always `false` in this PR** — `RemoteSessionActor`'s
    /// current discovery API (`RemoteSessionActor.swift`'s
    /// `isLocalNetworkPermissionDenied` helper) only LOGS the denial
    /// (`IHLog.discovery.error`), it does not surface it through
    /// `startDiscovery()`'s result stream or any other public signal this
    /// PR's binding "no further `RemoteSessionActor` edits beyond the
    /// resolved-address capture" scope allows adding a hook for. Wiring this
    /// up for real needs a small, separate `RemoteSessionActor` addition —
    /// tracked as follow-up, not guessed at here.
    public private(set) var localNetworkDenied = false

    private let store: any PairedTVStoring
    private let session: RemoteControlSession
    private var savedRecords: [PairedTVRecord] = []
    private var discoveredServices: [LANRemoteDiscoveredService] = []

    /// The TV currently being attached to/controlled — tracked HERE (not
    /// read back from `RemoteControlSession`, which only exposes ITS OWN
    /// events) so `.paired`/`.controlling` events can be turned into both a
    /// `PairedTVRecord` upsert and a `UIPhase` carrying the right name.
    private var currentTVName: String?
    private var currentFingerprint: String?

    private var hasStarted = false
    private var eventsTask: Task<Void, Never>?
    private var discoveryTask: Task<Void, Never>?

    public init(
        store: any PairedTVStoring = KeychainPairedTVStore(),
        sessionConfiguration: RemoteControlSession.Configuration = .init(
            remoteKind: RemoteDeviceIdentity.kind, deviceName: RemoteDeviceIdentity.name
        )
    ) {
        self.store = store
        self.session = RemoteControlSession(configuration: sessionConfiguration)
    }

    /// Idempotent — loads the saved-TV list, starts discovery IF the Local
    /// Network primer has already been shown, and spawns the ONE
    /// `session.events` consumer this coordinator ever runs.
    ///
    /// ELI5: "Get the remote-control screen ready."
    public func start() async {
        guard !hasStarted else { return }
        hasStarted = true

        savedRecords = await store.listPairedTVs()
        rebuildRows()
        spawnEventsConsumer()

        if IHSettingsStore().hasSeenLocalNetworkPrimer {
            await beginDiscovery()
        }
    }

    /// The primer card's "Continue" action (spec §4.3) — persists that the
    /// user has seen it AND starts discovery in the same step (the OS
    /// permission prompt fires on the first real `NWBrowser` start, right
    /// after this).
    public func acknowledgeLocalNetworkPrimer() async {
        IHSettingsStore().hasSeenLocalNetworkPrimer = true
        await beginDiscovery()
    }

    public func stop() async {
        discoveryTask?.cancel(); discoveryTask = nil
        eventsTask?.cancel(); eventsTask = nil
        await session.stop()
    }

    // MARK: - Entry paths (spec §4)

    /// A scanned QR or a pasted payload string — `PairingPayloadEntrySheet`'s
    /// one call.
    ///
    /// ELI5: "I scanned/pasted this — try to connect."
    public func handleScannedOrPasted(_ string: String) {
        guard let payload = LANRemotePairingPayload(qrString: string) else {
            notice = "Not a valid iHymns pairing code."
            return
        }
        resolveAndAttach(.payload(payload), tvName: payload.name, fingerprint: payload.fingerprintHex)
    }

    /// A tap on a list row — informational-only for an unpaired nearby row
    /// (spec §4 row C′: never connects; the view's own affordance for that
    /// row opens the scan/paste sheet instead, this method simply no-ops).
    public func connect(row: RemoteTVListRow) {
        guard case .paired(let record) = row.kind else { return }
        resolveAndAttach(.savedRow(record), tvName: record.name, fingerprint: record.fingerprintHex)
    }

    private func resolveAndAttach(_ entry: RemotePairingEntryResolver.Entry, tvName: String, fingerprint: String) {
        let resolution = RemotePairingEntryResolver.resolve(entry, saved: savedRecords, discovered: discoveredServices)
        switch resolution {
        case .connect(let target):
            notice = nil
            currentTVName = tvName
            currentFingerprint = fingerprint
            Task { await session.attach(to: target) }
        case .unpairable(.noRouteToTV):
            notice = "Couldn't find \(tvName) on this network. Scan its QR code again from the TV's screen."
        }
    }

    // MARK: - Ceremony / control-surface pass-throughs

    public func submitCode(_ code: String) async {
        await session.submitPairingCode(code)
    }

    public func cancelPairing() async {
        await session.cancelPairing()
    }

    /// The user's explicit "Disconnect" button.
    public func disconnect() async {
        await session.endControl()
    }

    /// "Forget this TV" — removes the local record; the TV itself still
    /// trusts this device until revoked there too (spec §5.2's honesty
    /// note — the caller's confirmation copy says so).
    public func forget(_ record: PairedTVRecord) async {
        await store.delete(fingerprint: record.fingerprintHex)
        savedRecords = await store.listPairedTVs()
        rebuildRows()
    }

    /// `scenePhase` wiring.
    public func setScenePhaseActive(_ active: Bool) async {
        await session.setSuspended(!active)
    }

    /// The control surface's one door for sending an intent.
    public func sendIntent(_ message: IHRPMessage) async {
        await session.sendIntent(message)
    }

    // MARK: - Discovery

    private func beginDiscovery() async {
        guard discoveryTask == nil else { return }
        let stream = await session.startDiscovery()
        discoveryTask = Task { [weak self] in
            for await services in stream {
                guard let self else { break }
                self.applyDiscovered(services)
            }
        }
    }

    private func applyDiscovered(_ services: [LANRemoteDiscoveredService]) {
        discoveredServices = services
        rebuildRows()
    }

    private func rebuildRows() {
        rows = RemotePairingEntryResolver.listRows(saved: savedRecords, discovered: discoveredServices)
    }

    // MARK: - The events consumer (the ONE consumer of `session.events`)

    private func spawnEventsConsumer() {
        eventsTask = Task { [weak self] in
            guard let self else { return }
            for await event in self.session.events {
                await self.apply(event)
            }
        }
    }

    private func apply(_ event: RemoteControlSession.Event) async {
        let tvName = currentTVName ?? "this TV"
        uiPhase = Self.uiPhase(after: event, current: uiPhase, tvName: tvName)

        switch event {
        case .paired(let token, let resolved):
            await persistPaired(token: token, resolved: resolved)
        case .controlling:
            await touchLastConnected()
        case .pairingEnded(let failure):
            currentTVName = nil
            currentFingerprint = nil
            switch failure {
            case .cancelled:
                notice = nil
            case .connectionTornDown:
                notice = "Pairing didn't complete. Ask the operator to open pairing on the TV again."
            }
        case .ended:
            currentTVName = nil
            currentFingerprint = nil
        case .connecting, .awaitingCodeEntry, .detached, .reconnecting, .suspended:
            break
        }
    }

    private func persistPaired(token: String, resolved: LANRemoteResolvedAddress?) async {
        guard let fingerprint = currentFingerprint, let name = currentTVName else { return }
        let now = Date()
        let existing = await store.record(forFingerprint: fingerprint)
        let record = PairedTVRecord(
            fingerprintHex: fingerprint, name: name, token: token,
            lastAddress: resolved ?? existing?.lastAddress,
            pairedAt: existing?.pairedAt ?? now, lastConnectedAt: now
        )
        await store.save(record)
        savedRecords = await store.listPairedTVs()
        rebuildRows()
    }

    /// On each `.controlling` first-arrival after an attach/reconnect,
    /// update `lastConnectedAt`/`lastAddress` on the EXISTING saved record
    /// (spec §5.2) — distinct from `persistPaired`, which only runs once per
    /// FRESH ceremony. A fast-path reconnect (no `.paired` event at all)
    /// still needs its `lastConnectedAt` refreshed, which is what this call
    /// is for.
    private func touchLastConnected() async {
        guard let fingerprint = currentFingerprint, var record = await store.record(forFingerprint: fingerprint) else { return }
        record.lastConnectedAt = Date()
        if let resolved = await session.currentResolvedAddress() {
            record.lastAddress = resolved
        }
        await store.save(record)
        savedRecords = await store.listPairedTVs()
        rebuildRows()
    }

    // MARK: - The pure event → UI mapping (spec §7.3-testable)

    /// Turns one `RemoteControlSession.Event` into the next `UIPhase` —
    /// pure and static so `RemoteControlUIStateTests` can drive it with zero
    /// actors/sessions/network involved.
    ///
    /// ELI5: "Given what just happened, what should the screen show now?"
    nonisolated static func uiPhase(after event: RemoteControlSession.Event, current: UIPhase, tvName: String) -> UIPhase {
        switch event {
        case .connecting(let attempt):
            return .connecting(tvName: tvName, attempt: attempt)
        case .awaitingCodeEntry(let failedAttempts):
            return .codeEntry(tvName: tvName, failedAttempts: failedAttempts)
        case .pairingEnded:
            return .browsing
        case .paired:
            // `.controlling` always follows on the same connection (§1.1's
            // wire contract) — no visible transition needed here.
            return current
        case .controlling(let state):
            return .controlling(tvName: tvName, state: state)
        case .detached:
            // The ladder's first `.reconnecting(attempt: 0, …)` is only
            // moments away; show it immediately rather than leaving a stale
            // `.controlling` phase on screen for that brief window.
            return .reconnecting(tvName: tvName, attempt: 0)
        case .reconnecting(let attempt, _):
            return .reconnecting(tvName: tvName, attempt: attempt)
        case .suspended:
            return .suspended(tvName: tvName)
        case .ended:
            return .browsing
        }
    }
}

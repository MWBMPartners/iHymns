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

    // `UIPhase` + the pure `uiPhase(after:current:tvName:)` mapping live in
    // `RemoteControlCoordinator+UIPhase.swift` (#1424, relocated for the
    // LOC budget — see that file's header).

    // `internal(set)` (not `private(set)`, #1424) — `+ManualConnect.swift`
    // needs to drive `uiPhase`/`notice` directly for the fingerprint
    // interstitial (mirrors PR-7's own split conventions, e.g.
    // `RemoteControlSession`'s stored properties are plain `var`, never
    // `private`, so `+Link.swift` can drive them).
    public internal(set) var uiPhase: UIPhase = .browsing
    /// Resolver-built (`RemotePairingEntryResolver.listRows`) — saved TVs
    /// first, then unpaired nearby ones, rebuilt whenever either the saved
    /// list or the discovered set changes.
    public private(set) var rows: [RemoteTVListRow] = []
    /// A transient, inline themed notice — "Not a valid iHymns pairing
    /// code," "ask the operator for a fresh code," etc. Cleared on the next
    /// successful action.
    public internal(set) var notice: String?
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

    /// `internal` (not `private`, #1423) — `RemoteControlCoordinator
    /// +Persistence.swift`'s relocated `persistPaired`/`touchLastConnected`
    /// need direct access, the same cross-file-reuse convention this file
    /// already uses for `session`/`currentTVName`/`savedRecords`.
    let store: any PairedTVStoring
    /// Kept so `start()` can build a BRAND-NEW `RemoteControlSession` on
    /// every (re)start rather than the one this type used to construct
    /// ONCE in `init` — see `session`'s own doc comment (#1422 review fix 3).
    private let sessionConfiguration: RemoteControlSession.Configuration
    /// `var`, not `let` (#1422 review fix 3): `RemoteControlSession.stop()`
    /// is TERMINAL — `eventContinuation.finish()` means its `events` stream
    /// never yields again, no matter what's called on the actor afterwards.
    /// Before this fix `session` was a `let` built ONCE in `init` and
    /// `start()` was one-shot (`hasStarted`, never reset) — so a view that
    /// saw `onDisappear` (→ `stop()`) and then re-`appear`ed (a tab switch
    /// while this screen stays pushed; SwiftUI keeps this `@Observable`
    /// coordinator's `@State` alive across that and just re-runs `.task`,
    /// `RemoteControlView.swift:35-36`) hit a genuinely DEAD screen:
    /// `start()` no-op'd, and even if it hadn't, the OLD session's stream
    /// was finished for good either way. Fix: `stop()` resets `hasStarted`
    /// so `start()` re-arms, and `start()` builds a FRESH session — fresh
    /// actor, fresh stream, fresh `spawnEventsConsumer()` — every time it
    /// runs. The old, already-`stop()`'d session is simply dropped; it has
    /// no background tasks left by the time `stop()` returns, so nothing leaks.
    ///
    /// `internal` (not `private`, #1424) — `+ManualConnect.swift` reads
    /// `session`/`savedRecords` directly for `attachByAddress`/the known-TV
    /// shortcut lookup, the same cross-file-reuse convention this doc
    /// comment already describes.
    var session: RemoteControlSession
    var savedRecords: [PairedTVRecord] = []
    private var discoveredServices: [LANRemoteDiscoveredService] = []

    /// The TV currently being attached to/controlled — tracked HERE (not
    /// read back from `RemoteControlSession`, which only exposes ITS OWN
    /// events) so `.paired`/`.controlling` events can be turned into both a
    /// `PairedTVRecord` upsert and a `UIPhase` carrying the right name.
    /// `internal` (not `private`, #1424) so `+ManualConnect.swift` can set
    /// them for the TOFU path too.
    var currentTVName: String?
    var currentFingerprint: String?
    /// #1424 — `true` from `connectByAddress(hostInput:portInput:)` until
    /// the attempt resolves one way or another (`.paired`/`.controlling`/
    /// `.pairingEnded`/`.ended` all clear it) — lets `apply(_:)`'s
    /// `.pairingEnded(.connectionTornDown)` branch pick the manual-connect
    /// -specific "couldn't reach it" copy instead of the generic operator
    /// -guidance line (spec §6.5).
    var isManualConnectInFlight = false
    /// #1424 — the most recently parsed "Connect by Address" input, kept
    /// for the manual-failure copy (`manualConnectionTornDownNotice()`) and
    /// as the `host`/`port`/`displayName` inputs to
    /// `RemotePairingEntryResolver.manualResolution` (the known-TV
    /// shortcut, spec §4.3).
    var lastManualParsed: LANRemoteManualAddress.Parsed?

    /// Guards `touchLastConnected()` so it only runs on the FIRST
    /// `.controlling` arrival for the CURRENT attach/reconnect, not on
    /// every subsequent state broadcast — see `apply(_:)`'s `.controlling`
    /// case for the full story (#1422 review fix 2).
    private var hasTouchedThisLink = false

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
        self.sessionConfiguration = sessionConfiguration
        self.session = RemoteControlSession(configuration: sessionConfiguration)
    }

    /// Idempotent (re-armable — #1422 review fix 3) — rebuilds a fresh
    /// `session`, loads the saved-TV list, starts discovery IF the Local
    /// Network primer has already been shown, and spawns the ONE
    /// `session.events` consumer this coordinator ever runs FOR THAT
    /// session. Safe to call again after a matching `stop()` — see
    /// `session`'s own doc comment for why.
    ///
    /// ELI5: "Get the remote-control screen ready" — including "ready
    /// again," if the screen went away and came back.
    public func start() async {
        guard !hasStarted else { return }
        hasStarted = true

        // A brand-new session every time — never reuse one a prior
        // `stop()` already finished. Also reset the transient per-
        // connection UI state: a stale `.controlling`/`.codeEntry` screen
        // must not linger over a session attached to nothing.
        session = RemoteControlSession(configuration: sessionConfiguration)
        uiPhase = .browsing
        notice = nil
        currentTVName = nil
        currentFingerprint = nil
        hasTouchedThisLink = false
        isManualConnectInFlight = false
        lastManualParsed = nil

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

    /// Full teardown — also re-arms `start()` (#1422 review fix 3): a
    /// LATER `start()` call (the view reappearing) must not see
    /// `hasStarted == true` and no-op over a session this just finished.
    /// `guard hasStarted` makes a repeat/stray `stop()` call a cheap no-op
    /// instead of re-finishing an already-finished session.
    public func stop() async {
        guard hasStarted else { return }
        hasStarted = false
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
        case .unpairable(.malformedPayload):
            // Spec §8's clamp (#1422 review fix 4) rejected the payload's
            // OWN fields (port 0 / a malformed fingerprint) before this
            // resolver even looked for a route — a distinct, more specific
            // notice than "couldn't find it on the network" since the
            // payload itself is the problem, not reachability.
            notice = "Not a valid iHymns pairing code."
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

    /// `internal` (not `private`, #1423) — `+Persistence.swift`'s relocated
    /// `persistPaired`/`touchLastConnected` call this after every upsert.
    func rebuildRows() {
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
        case .connecting, .reconnecting:
            // A NEW connection attempt is (re)starting — arm
            // `hasTouchedThisLink` back to `false` so the next
            // `.controlling` arrival still gets exactly one touch
            // (#1422 review fix 2).
            hasTouchedThisLink = false
        case .paired(let token, let resolved):
            // Defensive re-reset: `.connecting` already cleared this for
            // the same attempt, but `.paired` always precedes this
            // connection's first `.controlling` on the wire (spec §1.1),
            // so resetting again costs nothing.
            hasTouchedThisLink = false
            isManualConnectInFlight = false
            lastManualParsed = nil
            await persistPaired(token: token, resolved: resolved)
        case .controlling:
            // `.controlling` fires on EVERY TV state broadcast — including
            // the TV's echo of our OWN intents (spec §1.1) — so touching
            // unconditionally would hit the Keychain (read+delete+add) +
            // `listPairedTVs()`/`rebuildRows()` on every next/prev/scroll
            // tap. This gate makes the code match `touchLastConnected()`'s
            // own "FIRST-arrival" doc comment (#1422 review fix 2): only
            // the first arrival since the last `.connecting`/
            // `.reconnecting`/`.paired` runs it; later echoes no-op.
            isManualConnectInFlight = false
            if !hasTouchedThisLink {
                hasTouchedThisLink = true
                await touchLastConnected()
            }
        case .pairingEnded(let failure):
            currentTVName = nil
            currentFingerprint = nil
            switch failure {
            case .cancelled:
                notice = nil
            case .connectionTornDown:
                // #1424 — a manual connect that never reached (or dropped
                // before/during) the ceremony gets its OWN "couldn't reach
                // it" copy instead of the generic operator-guidance line —
                // spec §6.5.
                notice = isManualConnectInFlight
                    ? manualConnectionTornDownNotice()
                    : "Pairing didn't complete. Ask the operator to open pairing on the TV again."
            }
            isManualConnectInFlight = false
            lastManualParsed = nil
        case .ended:
            currentTVName = nil
            currentFingerprint = nil
            isManualConnectInFlight = false
            lastManualParsed = nil
        case .awaitingFingerprintConfirmation(let fingerprint):
            await handleFingerprintObservation(fingerprint)
        case .awaitingCodeEntry, .detached, .suspended:
            break
        }
    }

    // `persistPaired(token:resolved:)` and `touchLastConnected()` live in
    // `RemoteControlCoordinator+Persistence.swift` (#1423, a pure
    // relocation for the LOC budget — same reason `uiPhase(after:current:
    // tvName:)` lives in `+UIPhase.swift` below).

    // `uiPhase(after:current:tvName:)` lives in
    // `RemoteControlCoordinator+UIPhase.swift` (#1424, relocated for the
    // LOC budget).
}

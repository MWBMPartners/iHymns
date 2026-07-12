// RemoteSessionActor+TOFU.swift
// IHLive/LANRemote
//
// ELI5: The ONE new way this actor is allowed to connect to a TV without
// already knowing its fingerprint — for when you typed in an address by
// hand instead of scanning a QR code. It still checks that SOMETHING
// answered with a real certificate (a completely silent/cert-less peer is
// rejected), but it doesn't yet know whether that certificate belongs to
// the RIGHT TV — that's the caller's job (the pairing ceremony + a human
// eyeball, `RemoteControlSession+ManualConnect.swift`), never this file's.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §4.1, Decision D-2 —
// BINDING). The manual "Connect by Address" rung (strategy §2.4.5: "expect
// connectivity without discovery" for a VPN'd-in remote or an AP-isolated
// network) has no out-of-band fingerprint to pin against before the FIRST
// connection — that's what Trust-On-First-Use (TOFU) means here: accept
// whatever certificate the peer presents, remember (CAPTURE) its SHA-256
// fingerprint, and let the CALLER decide whether to trust it (by running
// the SAME PR-6 pairing ceremony, whose proof channel-binds this exact
// fingerprint — a relayed/MITM'd proof binds the ATTACKER's fingerprint,
// which the real TV's own verify then rejects, spec §1.1/§8).
//
// **Deliberately a byte-for-byte MIRROR of the pinned `connect(to:
// expectedFingerprint:token:)` (`RemoteSessionActor+Connection.swift:48-97`),
// not a shared `connectCore` extraction** (spec §4.1's rejected-alternative
// discussion, Decision D-2): the pinned path is this whole module's
// security-reviewed core — ANY extraction puts it back in a diff a future
// reviewer has to re-scrutinize. A ~40-line duplicate, confined to ONE file
// and cross-referenced in both headers, is the cheaper risk. **Kept in
// deliberate sync with `RemoteSessionActor+Connection.swift:48-97` — if you
// change one, change both; `RemoteControlSessionLoopbackTests`/
// `ManualConnectLoopbackTests` cover each path independently.**
//
// SECURITY CONTRACT (spec §4.1, restated so it's impossible to miss):
//  1. NO saved token ever rides this connect — there is deliberately no
//     `token:` parameter on this method; `pairingToken` is forced `nil`
//     before the transport auto-sends `hello`, so a not-yet-verified peer
//     can never be handed a credential (Decision D-5).
//  2. The fingerprint this method RETURNS is trusted by NOBODY yet — the
//     caller (`RemoteControlSession+ManualConnect.swift`) must run the full
//     pairing ceremony (whose proof binds this exact value) and the
//     COORDINATOR must persist it only after that ceremony's `.pairSuccess`
//     (Decision D-1 — `RemoteControlCoordinator.persistPaired` is
//     completely unchanged by this PR, so this rule is enforced by NEVER
//     giving this method any other way to reach it).
//  3. This method is reachable from EXACTLY one call site in the whole app
//     — `RemoteControlSession.attachByAddress(host:port:displayName:)`, a
//     fresh user gesture. Every reconnect (the ladder, `setSuspended`
//     resume, a saved-row tap) calls the PINNED `connect(...)` instead —
//     see that method's own doc comment; nothing here changes it.
//
// **THE ONE GENUINE BEHAVIOURAL ADDITION vs. the pinned mirror: a bounded
// connect timeout.** Empirically verified against a real `NWConnection`
// (a standalone diagnostic script, not a guess): connecting to a LOOPBACK
// port NOTHING has EVER listened on reaches `NWConnection.State.waiting`
// (carrying `POSIXErrorCode.ECONNREFUSED`) and then SITS there — Network
// .framework's own documented "this might become possible later, keep
// retrying" policy for `.waiting`, which `onConnectionStateChanged`
// (`RemoteSessionActor+Connection.swift`, UNCHANGED by this PR, D-2) only
// ever resolves via `.ready`/`.failed`, never `.waiting`. A connection that
// WAS previously live (a paired TV's socket closing) instead surfaces
// through `onReceive`'s error/`isComplete` path, which the pinned actor
// DOES handle — so this gap is invisible to every EXISTING (PR-4→PR-7)
// code path, which only ever reconnects to addresses that answered at
// least once. The manual "Connect by Address" rung is the FIRST path that
// can target an address NOTHING has ever answered on, so it is the first
// to need this timeout — kept entirely inside this NEW file (no
// `RemoteSessionActor`/`+Connection.swift` diff, D-2 intact): a
// reference-identity-scoped timeout task calls `disconnect()` if — and
// ONLY if — THIS SPECIFIC connection is still the actor's current one and
// still awaiting its connect continuation, so a timeout that fires late
// can never touch a different, later connection.
import Foundation
import IHLog
import Network
import os
import Security

extension RemoteSessionActor {
    /// Connects to `endpoint` WITHOUT a pinned fingerprint — see this
    /// file's header for the full Trust-On-First-Use contract. Resolves
    /// with the SHA-256 hex fingerprint the peer's certificate presented
    /// once the transport is up and `hello(token: nil)` has been sent;
    /// throws if the transport never comes up, or (defensively —
    /// unreachable in practice, §1.2) if it somehow reached `.ready`
    /// without the verify block ever observing a certificate.
    ///
    /// ELI5: "Connect to whatever's at this address, and tell me what
    /// certificate it showed you — I'm not asking you to trust it yet."
    public func connectTrustingFirstUse(to endpoint: NWEndpoint) async throws -> String {
        disconnect()
        // The stored `expectedFingerprint`/`pairingToken` are the SAME
        // write-only bookkeeping fields the pinned `connect(...)` sets
        // (`RemoteSessionActor.swift:101-102` — read nowhere else, verified
        // by grep, `RemoteSessionActor+Connection.swift`'s header). Setting
        // `expectedFingerprint` to `nil` here (rather than skipping it) keeps
        // that bookkeeping HONEST while the fingerprint is still unknown;
        // it's overwritten with the OBSERVED value below once captured.
        // `pairingToken` stays `nil` for this call's entire lifetime — no
        // parameter exists to override it (Decision D-5, this file's header).
        self.expectedFingerprint = nil
        self.pairingToken = nil
        setPhase(.connecting)

        let signpostID = IHLog.signposter.makeSignpostID()
        let interval = IHLog.signposter.beginInterval("lan.connect.tofu", id: signpostID)

        // A `Sendable`-safe box the verify block (which runs on
        // `networkQueue`, not this actor's isolation) writes into and this
        // method reads back AFTER the connection reaches `.ready` — race
        // -free because the verify block completes strictly BEFORE
        // `.ready` fires (`RemoteSessionActor+Connection.swift`'s header,
        // §1.2 — the identical ordering guarantee the pinned path relies
        // on). `OSAllocatedUnfairLock`, not a plain `var` capture, because
        // the closure and this `async` method run on different execution
        // contexts.
        let observedFingerprintBox = OSAllocatedUnfairLock<String?>(initialState: nil)

        let tlsOptions = NWProtocolTLS.Options()
        sec_protocol_options_set_min_tls_protocol_version(tlsOptions.securityProtocolOptions, .TLSv13)
        sec_protocol_options_set_verify_block(tlsOptions.securityProtocolOptions, { _, trustRef, complete in
            let presented = Self.certificateFingerprint(from: trustRef)
            observedFingerprintBox.withLock { $0 = presented }
            if let presented {
                // The TOFU counterpart of the pinned path's "pin ok" line —
                // `.notice`, not `.fault`: observing SOME certificate on a
                // brand-new manual connect is the EXPECTED first half of
                // this flow, not an anomaly. `.public` — a fingerprint is
                // the value a human is about to compare on-screen anyway
                // (spec §6.3), never a secret.
                IHLog.remote.notice("lanremote.pin tofu-observed fingerprint=\(presented, privacy: .public)")
            } else {
                // Mirrors the pinned path's single-highest-signal `.fault`
                // line for the one case THIS method still rejects outright:
                // a peer presenting no certificate at all.
                IHLog.remote.fault("lanremote.pin tofu-no-certificate")
            }
            // Accept ANY presented certificate; reject only "no certificate
            // at all" — this is the entire TOFU relaxation, and the ONLY
            // difference between this verify block and the pinned one's.
            complete(presented != nil)
        }, networkQueue)

        let parameters = NWParameters(tls: tlsOptions, tcp: .init())
        let conn = NWConnection(to: endpoint, using: parameters)
        connection = conn

        conn.stateUpdateHandler = { [weak self] state in
            guard let self else { return }
            Task { await self.onConnectionStateChanged(state) }
        }

        // This file's header: `.waiting` never resolves on its own for an
        // address nothing has ever answered on — this timeout is what
        // turns that into an honest failure instead of an indefinite hang.
        let timeoutTask = Task { [weak self] in
            try? await Task.sleep(for: Self.tofuConnectTimeout)
            await self?.failConnectIfStillPending(conn)
        }
        defer { timeoutTask.cancel() }

        do {
            try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, Error>) in
                pendingConnectContinuation = continuation
                conn.start(queue: networkQueue)
            }
            IHLog.signposter.endInterval("lan.connect.tofu", interval, "ok")
        } catch {
            IHLog.signposter.endInterval("lan.connect.tofu", interval, "failed")
            throw error
        }

        scheduleReceive()
        await sendHello()

        guard let observed = observedFingerprintBox.withLock({ $0 }) else {
            // Defensive — unreachable in practice: reaching `.ready` means
            // the verify block already ran and completed `true`, which by
            // construction means it observed a non-nil certificate
            // (`complete(presented != nil)` above). Surfaced as a thrown
            // error rather than force-unwrapped, matching this module's
            // "never crash on a transport oddity" posture.
            disconnect()
            throw RemoteSessionError.transport("tofu-no-fingerprint")
        }
        self.expectedFingerprint = observed
        return observed
    }

    /// How long a TOFU connect waits for `.ready`/`.failed` before giving
    /// up on an address stuck in `.waiting` (this file's header) — long
    /// enough that a genuinely slow/degraded network still gets a fair
    /// chance, short enough that a manual "Connect by Address" attempt to a
    /// dead/mistyped address gives the human a bounded, sane wait instead
    /// of an indefinite spinner.
    private static let tofuConnectTimeout: Duration = .seconds(10)

    /// Fails `conn`'s connect attempt out IF it is still THIS actor's
    /// current connection AND still has a pending connect continuation —
    /// both checked so a timeout that fires after the connection already
    /// resolved (or after a LATER, unrelated connect attempt has replaced
    /// it) is a safe no-op, never a wrongful cancel of something else.
    /// `connection === conn` is a REFERENCE identity check (`NWConnection`
    /// is a class) — the only reliable way to know "is this still the
    /// exact attempt I was watching."
    private func failConnectIfStillPending(_ conn: NWConnection) {
        guard connection === conn, pendingConnectContinuation != nil else { return }
        IHLog.remote.notice("lanremote.pin tofu-connect-timed-out")
        disconnect()
    }
}

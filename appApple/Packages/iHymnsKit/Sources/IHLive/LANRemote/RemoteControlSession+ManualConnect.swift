// RemoteControlSession+ManualConnect.swift
// IHLive/LANRemote
//
// ELI5: The session's half of "connect to a TV I typed the address of" —
// starts the Trust-On-First-Use connection, tells the UI what fingerprint
// it saw, and — once a human has confirmed it matches the TV's own
// Settings screen — arms the SAME ordinary pairing ceremony every other
// connect path uses.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §4.2 — BINDING).
// Mirrors `attach(to:)`'s reset discipline (`RemoteControlSession.swift`)
// because a manual connect resets exactly the same per-target bookkeeping
// — it just can't arm `flow`/`target` yet (the fingerprint doesn't exist
// until `connectTrustingFirstUse` returns it). See
// `RemoteControlSession.swift`'s `manualConnect`/`pendingChallengeNonce`
// doc comments for the stored-property contract this file drives, and
// `+Link.swift`'s `handleIncoming`/`handleIdleOrFailed` for the other half
// of the race-closing/teardown-sweep story.
import Foundation
import IHLog
import Network

/// Session-level bookkeeping for an in-flight TOFU manual connect (spec
/// §3.5) — pure bookkeeping the session actor owns; the STORED `var` lives
/// in `RemoteControlSession.swift`'s actor body (every actor's stored
/// properties must), this TYPE lives here beside the code that drives it.
/// Internal (not `public`): never read outside this file/`+Link.swift`.
enum ManualConnectState: Sendable, Equatable {
    /// The TOFU connect is in flight; nothing observed yet.
    case connecting(host: String, port: UInt16, displayName: String)
    /// The TOFU connect observed `fingerprintHex`; waiting on the human (or
    /// the coordinator's known-TV shortcut) to decide what happens next.
    case awaitingConfirmation(host: String, port: UInt16, displayName: String, fingerprintHex: String)
}

extension RemoteControlSession {
    /// Begins a Trust-On-First-Use connect to a typed address — the
    /// "Connect by Address" entry path's one door into this actor. Never
    /// yields a failure event on its own throw path: a dropped/failed TOFU
    /// connect already produces the right terminal event through the
    /// ordinary phase stream (`+Link.swift`'s `handleIdleOrFailed`) —
    /// yielding one here too would double-report the same failure.
    ///
    /// ELI5: "Connect to whatever's at this address — I don't know yet if
    /// it's really your TV, so don't trust it with anything sensitive
    /// until a human (or an already-trusted match) says it's fine."
    public func attachByAddress(host: String, port: UInt16, displayName: String) async {
        ensureDriverLoopStarted()
        pingTask?.cancel(); pingTask = nil
        reconnectTask?.cancel(); reconnectTask = nil

        // The SAME per-target reset `attach(to:)` performs (spec §4.2
        // mirrors `RemoteControlSession.swift:181-203`) — a manual connect
        // is just as much a fresh target as a scanned/tapped one.
        target = nil
        flow = nil
        hasEverControlled = false
        isAttached = false
        isSuspended = false
        lastKnownRevision = nil
        backoff.reset()
        health.reset()
        pendingChallengeNonce = nil

        manualConnect = .connecting(host: host, port: port, displayName: displayName)
        eventContinuation.yield(.connecting(attempt: 0))
        // Host/address NEVER appears in this log line (this module's
        // instrumentation contract) — only the fact that a manual attempt
        // began.
        IHLog.remote.notice("lanremote.manual attach-begin")
        expectedIdleCount += 1

        guard let nwPort = NWEndpoint.Port(rawValue: port) else {
            // Defensive — the parser (`LANRemoteManualAddress`) already
            // guarantees `port` is `1...65535`.
            manualConnect = nil
            return
        }

        let observed = try? await remote.connectTrustingFirstUse(to: .hostPort(host: .init(host), port: nwPort))
        guard let observed else {
            // On throw: no event yielded here — see this method's own doc
            // comment for why (`handleIdleOrFailed` already covers it).
            manualConnect = nil
            pendingChallengeNonce = nil
            return
        }

        manualConnect = .awaitingConfirmation(host: host, port: port, displayName: displayName, fingerprintHex: observed)
        eventContinuation.yield(.awaitingFingerprintConfirmation(fingerprintHex: observed))
    }

    /// The fingerprint-confirm interstitial's "It Matches" — arms the
    /// ordinary ceremony `flow`/`target` over the OBSERVED fingerprint and
    /// replays any challenge that raced in ahead of this call (spec §4.2's
    /// challenge-before-armed race). A no-op if `manualConnect` isn't
    /// sitting in `.awaitingConfirmation` (e.g. called twice, or after a
    /// cancel already cleared it) — idempotent by construction.
    ///
    /// ELI5: "Yes, that fingerprint matches my TV — go ahead and pair for
    /// real."
    public func confirmObservedFingerprint() async {
        guard case .awaitingConfirmation(let host, let port, let displayName, let fingerprintHex) = manualConnect else { return }
        guard let nwPort = NWEndpoint.Port(rawValue: port) else { return }

        target = Target(
            tvName: displayName,
            endpoint: .hostPort(host: .init(host), port: nwPort),
            fallbackEndpoint: nil,
            expectedFingerprint: fingerprintHex,
            token: nil,
            knownCode: nil
        )
        flow = RemotePairingFlowState(context: .init(
            expectedFingerprint: fingerprintHex, knownCode: nil, deviceName: configuration.deviceName
        ))
        manualConnect = nil
        IHLog.remote.notice("lanremote.manual fingerprint-confirmed")

        if let nonce = pendingChallengeNonce {
            pendingChallengeNonce = nil
            guard var currentFlow = flow else { return }
            let effect = currentFlow.handle(.challengeReceived(nonce: nonce))
            flow = currentFlow
            await apply(effect)
        }
        // If no nonce arrived yet, `flow` sits in `.awaitingChallenge` and
        // the LIVE `.pairChallenge` routes through `+Link.swift`'s ordinary
        // branch the moment it arrives (`target` is now non-nil).
    }
}

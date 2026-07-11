// TVListenerActor+Pairing.swift
// IHLive/LANRemote
//
// ELI5: The part of the TV's brain that runs the actual pairing ceremony —
// "here's a fresh code, go ahead and type it into your phone," checking the
// proof a remote sends back, minting the forever-token on success, and
// telling the UI layer what's happening every step of the way (a remote
// showed up wanting to pair, someone typed the code wrong, the code just
// got rotated, someone paired successfully).
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §3.4/§3.5 — BINDING).
// Split out of `TVListenerActor.swift`/`TVListenerActor+Messages.swift` for
// the same LOC-budget reason `+Connections.swift` documents. This file owns
// the CEREMONY-SPECIFIC actor-isolated logic; `handlePairConfirm` is called
// from `TVListenerActor+Messages.swift`'s `handleUnauthenticated` the
// moment a `.pairConfirm` frame arrives on a `.pairing` connection.
//
// **Never logged, anywhere, at any level:** the code, the token, the proof,
// the nonce, raw `hello` tokens — every `IHLog.remote` line in this file
// carries only case names / transition names / statuses / attempt COUNTS.
// This is the single easiest thing to get wrong in this whole ceremony
// (spec §8's threat-model table, last row) — a reviewer should grep this
// file for every `IHLog` call and confirm none of them interpolates
// `proof`/`code`/`nonce`/`token`.
import Foundation
import IHLog

/// Every pairing-ceremony transition the tvOS UI (`TVRemoteControlCoordinator`,
/// `IHFeatures`) needs to react to — see `TVListenerActor.pairingEvents`.
///
/// ELI5: "A remote just showed up wanting to pair," "that remote gave up or
/// got disconnected," "the code just changed," "someone paired
/// successfully," "someone typed the code wrong."
public enum TVPairingEvent: Sendable, Equatable {
    /// A connection entered `.pairing` (a `hello` with no/unknown token) —
    /// the overlay's "1 remote is trying to pair…" indicator.
    case remoteEnteredPairing(connectionId: UUID, kind: IHRPRemoteKind?)
    /// A `.pairing` connection was torn down before it ever confirmed.
    case remoteLeftPairing(connectionId: UUID)
    /// The active code just changed — either the operator's manual rotate
    /// (`rotatePairingCode()`) or an automatic rotate after too many wrong
    /// proofs (§3.4). **In-process only — display, NEVER log** (this
    /// file's header contract).
    case codeRotated(newCode: String)
    /// A `.pairing` connection just became `.paired`.
    case paired(name: String?, kind: IHRPRemoteKind?)
    /// A wrong proof was rejected — `attemptsTowardRotation` is the GLOBAL
    /// (per-code, not per-connection) wrong-attempt count at the moment of
    /// rejection, for a "getting close to a rotation" UI hint if ever
    /// wanted; never required to be shown.
    case proofRejected(attemptsTowardRotation: Int)
    /// The whole ceremony hit its cumulative wrong-proof ceiling
    /// (`LANRemotePairingCeremonyState.Configuration.maxCeremonyFailures`)
    /// and was disarmed (post-#1421 adversarial-review brute-force fix). The
    /// UI (`TVRemoteControlCoordinator`) closes the overlay and surfaces a
    /// "too many attempts" notice; the operator must re-open pairing.
    case ceremonyExhausted
}

extension TVListenerActor {

    // MARK: - Public ceremony control surface (spec §3.4's "Ceremony public API")

    /// Mints a fresh code and arms the ceremony — called when the operator
    /// opens the pairing overlay.
    ///
    /// ELI5: "Show a brand-new pairing code on the TV screen."
    public func beginPairing() -> String {
        let code = LANRemotePairingSecrets.mintCode()
        pairingCeremony.begin(code: code, now: configuration.clock.now())
        return code
    }

    /// Manually mints a fresh code without waiting for a failure-triggered
    /// rotation — the overlay's TTL countdown calls this when the
    /// currently-displayed code is about to go stale, so the on-screen
    /// code is never shown past its actual expiry.
    ///
    /// ELI5: "Show a NEW code right now" — the same thing that happens
    /// automatically after too many wrong guesses, but triggered by the
    /// clock instead.
    public func rotatePairingCode() -> String {
        rotateActiveCode()
    }

    /// Fully disarms the ceremony — the operator closed the pairing
    /// overlay. Any connection already parked in `.pairing` stays parked;
    /// it simply can never successfully confirm until `beginPairing()`
    /// runs again.
    ///
    /// ELI5: "Turn the pairing screen off."
    public func endPairing() {
        pairingCeremony.end()
    }

    /// The code currently displayed, if a ceremony is running — the UI
    /// re-reads this rather than caching its own copy.
    public var activePairingCode: String? { pairingCeremony.activeCode }

    /// Sends an `error` frame to one connection — the bridge
    /// (`TVProjectionBridge`, `IHFeatures`) uses this to relay a
    /// `ProjectionViewModel` failure back to the ORIGINATING remote.
    ///
    /// ELI5: "Tell this one remote 'that didn't work, here's why.'"
    public func sendError(_ code: IHRPErrorCode, message: String?, to connectionId: UUID) {
        send(id: connectionId, message: .error(code, message: message))
    }

    /// Tears down every LIVE connection whose paired token hashes to
    /// `tokenHash` — the Settings "Revoke" action's second half (the first
    /// half, forgetting the token so a future `hello` fails, is
    /// `LANRemotePairingAuthority.revokePairedToken`/`revoke(tokenHash:)`).
    /// Without this, a revoked-but-still-open connection would keep
    /// controlling the TV until its NEXT reconnect attempt.
    ///
    /// ELI5: "That remote's access was just revoked — if it's currently
    /// connected, disconnect it right now too."
    public func disconnectPaired(tokenHash: String) {
        // `Array(connections.keys)` snapshot first — `stop()`'s own
        // identical precedent (`TVListenerActor.swift`'s doc comment on
        // that method) for why iterating while `teardownConnection`
        // mutates `connections` needs a stable snapshot.
        for id in Array(connections.keys) {
            guard let state = connections[id], case .paired(let token) = state.pairingPhase else { continue }
            guard LANRemoteFingerprint.sha256Hex(Data(token.utf8)) == tokenHash else { continue }
            teardownConnection(id: id, reason: "revoked")
        }
    }

    // MARK: - Ceremony verification (spec §3.4's exact algorithm)

    /// Dispatched from `TVListenerActor+Messages.swift`'s
    /// `handleUnauthenticated` the moment a `.pairConfirm` frame arrives.
    /// See spec §3.4 for the numbered algorithm this implements verbatim.
    func handlePairConfirm(id: UUID, state: TVRemoteConnectionState, proof: String, deviceName: String?) async {
        guard state.pairingPhase == .pairing else {
            // A connection that skipped `hello` (still `.unpaired`) and
            // sent `pairConfirm` directly is hostile/buggy — the SAME
            // confinement-violation posture `handleUnauthenticated`'s
            // `default:` branch already takes for any other out-of-order
            // control message.
            IHLog.remote.error("lanremote.pairing violation type=pairConfirm while unconfirmed")
            send(id: id, message: .error(.unauthorized, message: nil))
            teardownConnection(id: id, reason: "pairConfirm before hello")
            return
        }

        state.pairingAttempts += 1
        guard state.pairingAttempts <= pairingCeremony.configuration.maxAttemptsPerConnection else {
            send(id: id, message: .error(.pairingRejected, message: nil))
            IHLog.remote.error("lanremote.pairing attempt-cap")
            teardownConnection(id: id, reason: "pairing attempt cap")
            return
        }

        let now = configuration.clock.now()
        guard pairingCeremony.isActive(now: now), let code = pairingCeremony.activeCode, let nonce = state.pairingNonce else {
            // An expired/absent-ceremony guess does NOT count toward
            // rotation (spec Decision D-5) — reject and return WITHOUT
            // touching `pairingCeremony.wrongAttempts` at all.
            send(id: id, message: .error(.pairingRejected, message: nil))
            IHLog.remote.notice("lanremote.pairing rejected reason=inactive")
            return
        }

        guard LANRemotePairingProof.verify(proofHex: proof, code: code, fingerprintHex: identity.fingerprint, nonceHex: nonce) else {
            let outcome = pairingCeremony.recordFailure()
            let attemptsTowardRotation = pairingCeremony.wrongAttempts
            switch outcome {
            case .exhausted:
                // The CUMULATIVE per-ceremony ceiling is reached — shut the
                // whole ceremony down (post-#1421 adversarial-review fix,
                // spec §8 brute-force row): rotating yet another RANDOM code
                // would hand a LAN brute-forcer an unbounded stream of
                // guesses, which is exactly the envelope the threat model
                // wrongly assumed rotation prevented. Disarm entirely; only
                // a fresh operator-initiated `beginPairing()` re-opens
                // pairing. NEVER logs the code/attempt values, only the
                // transition.
                pairingCeremony.end()
                pairingContinuation.yield(.ceremonyExhausted)
                IHLog.remote.notice("lanremote.pairing ceremony-exhausted")
            case .rotate:
                _ = rotateActiveCode()
                IHLog.remote.notice("lanremote.pairing code-rotated")
            case .recorded:
                break
            }
            send(id: id, message: .error(.pairingRejected, message: nil))
            pairingContinuation.yield(.proofRejected(attemptsTowardRotation: attemptsTowardRotation))
            IHLog.remote.notice("lanremote.pairing proof-rejected")
            return
        }

        pairingCeremony.consume()
        let token = LANRemotePairingSecrets.mintToken()
        await promoteToPaired(
            id: id,
            token: token,
            metadata: LANRemotePairingMetadata(name: deviceName, kind: state.remoteKind, pairedAt: now)
        )
        pairingContinuation.yield(.paired(name: deviceName, kind: state.remoteKind))
        IHLog.remote.notice("lanremote.pairing pairing -> paired (ceremony)")
    }

    /// The shared success path both `handlePairConfirm` (ceremony success)
    /// and `completePairing(connectionId:token:)` (`+Messages.swift`'s
    /// manual/test confirmation hook, spec Decision D-3) funnel through —
    /// registers the token with the authority, flips the connection to
    /// `.paired`, clears its ceremony bookkeeping, and sends BOTH
    /// `.pairSuccess` (the raw token the remote persists) and
    /// `.capabilities` (existing PR-4 behaviour every completion path
    /// already had).
    func promoteToPaired(id: UUID, token: String, metadata: LANRemotePairingMetadata) async {
        guard let state = connections[id] else { return }
        let wasPairing = state.pairingPhase == .pairing
        await configuration.pairingAuthority.registerPairedToken(token, metadata: metadata)
        state.pairingPhase = .paired(token: token)
        state.pairingNonce = nil
        state.pairingAttempts = 0
        // #1421 adversarial-review fix — a `.pairing` connection that just
        // promoted is no longer "trying to pair"; balance the
        // `.remoteEnteredPairing` yielded when it entered so the overlay's
        // live count doesn't leak upward by one on every successful pair.
        // (`teardownConnection` only yields `.remoteLeftPairing` for a still-
        // `.pairing` connection, so a promoted one would otherwise never emit
        // its matching leave.)
        if wasPairing {
            pairingContinuation.yield(.remoteLeftPairing(connectionId: id))
        }
        send(id: id, message: .pairSuccess(token: token))
        send(id: id, message: .capabilities(IHRPCapabilities(currentState: canonicalState)))
    }

    /// Mints a fresh code and re-arms the ceremony, yielding `.codeRotated`
    /// — the ONE place both `rotatePairingCode()` (manual) and
    /// `handlePairConfirm`'s failure branch (automatic) rotate from, so the
    /// two can never drift in what "rotate" actually does. Uses the
    /// ceremony's `rotate(code:now:)` (NOT `begin(code:now:)`) so the
    /// cumulative `ceremonyFailures` ceiling survives every rotation — only
    /// `beginPairing()` (a fresh operator-opened ceremony) resets it.
    @discardableResult
    private func rotateActiveCode() -> String {
        let code = LANRemotePairingSecrets.mintCode()
        pairingCeremony.rotate(code: code, now: configuration.clock.now())
        pairingContinuation.yield(.codeRotated(newCode: code))
        return code
    }
}

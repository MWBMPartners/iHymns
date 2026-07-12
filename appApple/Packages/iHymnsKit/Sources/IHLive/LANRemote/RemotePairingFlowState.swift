// RemotePairingFlowState.swift
// IHLive/LANRemote
//
// ELI5: The remote's OWN half of the pairing dance — "the TV just asked me
// for a code," "here's what the person typed," "the TV said yes/no." This
// file doesn't touch the network at all; it just tracks what step we're on
// and tells whoever's driving the actual connection what to do next (send
// this proof, show a code box, tell the app we're paired, tell the app it
// failed).
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §3.1 — BINDING shape,
// do not improvise). This is the CLIENT half of the ceremony PR-6's
// `LANRemotePairingCeremonyState` (`LANRemotePairingCeremony.swift`) runs on
// the TV side — same "pure struct, no clock read, no RNG, no I/O" posture
// (`.claude/apple-native-strategy.md` §3.3's "injected-clock tests... no
// wall-clock sleeps," and the `LiveFollowEngine.isFresh(lastUpdatedAt:now:)`
// seam this whole module keeps reusing) so `RemotePairingFlowStateTests` can
// drive every branch with plain literal `Event`s, no fake clock object even
// needed here (there's no TTL on this side — the TV alone owns that).
//
// **Computing the proof is pure** (`LANRemotePairingProof.compute(...)` is a
// deterministic HMAC — `LANRemotePairingProof.swift`'s header), so it happens
// INSIDE `handle(_:)` itself rather than being deferred to the driver: the
// `Effect.sendProof` case below always carries an already-finished proof
// string, never "please go compute one." This is also the file PR-6's spec
// §7.1 golden vector gets cross-checked against — the SAME (code,
// fingerprint, nonce) triple fed through THIS reducer must produce the exact
// same proof hex the TV's `LANRemotePairingProofTests` froze, because both
// sides call the identical `LANRemotePairingProof.compute` (one
// implementation, two callers, `.claude/CLAUDE.md`'s modularity rule).
//
// **Never stores a live code anywhere in `Phase`** (spec §3.1: "the typed
// code is held ONLY long enough to compute — never stored on the state" —
// binding, because `Phase`/`Event` are both `Equatable` and could otherwise
// leak into a test failure diff or a debug dump). `awaitingCode`/`proofSent`
// carry only the (non-secret, TV-issued) nonce and a failure count.
import Foundation

/// The remote-side pairing-flow reducer — see this file's header for why
/// it's a pure `struct`, not an `actor`.
///
/// ELI5: "Where are we in the pairing dance, and what should the driver do
/// next?"
public struct RemotePairingFlowState: Sendable, Equatable {

    /// Everything the flow needs up front, fixed for the lifetime of one
    /// connection attempt.
    ///
    /// ELI5: "Which TV am I proving myself to, do I already have the code
    /// (because I scanned a ceremony QR), and what should I call myself?"
    public struct Context: Sendable, Equatable {
        /// The fingerprint WE pinned when connecting (`RemoteSessionActor
        /// .connect(to:expectedFingerprint:token:)`'s argument) — the proof
        /// binds to THIS value, never one the TV sends us (channel binding,
        /// PR-6 spec §3.2 — a relayed proof must bind to the attacker's own
        /// fingerprint, not the real TV's).
        public var expectedFingerprint: String

        /// Non-nil = path A (a scanned ceremony QR already carried the live
        /// code): the challenge is answered WITHOUT ever prompting a human.
        /// `nil` = path B: the code has to come from `codeEntered(_:)`.
        public var knownCode: String?

        /// The cosmetic device name sent alongside the proof (for the TV's
        /// trusted-remotes list) — `nil` lets the TV show "Unknown remote."
        public var deviceName: String?

        public init(expectedFingerprint: String, knownCode: String? = nil, deviceName: String? = nil) {
            self.expectedFingerprint = expectedFingerprint
            self.knownCode = knownCode
            self.deviceName = deviceName
        }
    }

    /// Where the ceremony currently stands, from the remote's point of view.
    public enum Phase: Sendable, Equatable {
        /// Connected, `hello` already sent by the transport layer, nothing
        /// back yet.
        case awaitingChallenge
        /// The TV's `.pairChallenge(nonce:)` arrived and no `knownCode` was
        /// available (or a previous one was rejected) — show the code-entry
        /// UI. `failedAttempts` is this CONNECTION's own count (mirrors the
        /// TV's per-connection cap, PR-6 spec §3.4).
        case awaitingCode(nonce: String, failedAttempts: Int)
        /// A proof was just sent; waiting for `.pairSuccess`/`.pairingRejected`.
        case proofSent(nonce: String, failedAttempts: Int)
        /// The TV accepted the proof — `token` is the raw value to persist.
        case paired(token: String)
        /// Terminal for THIS connection attempt — the driver tears down and
        /// reports to the UI; a fresh `attach(to:)` starts a whole new flow.
        case failed(RemotePairingFlowFailure)
    }

    /// Everything that can happen to this flow — every one is either a wire
    /// event relayed by the driver (`RemoteControlSession+Link.swift`) or a
    /// human action (`codeEntered`).
    public enum Event: Sendable, Equatable {
        /// The TV's `.pairChallenge(nonce:)` frame arrived.
        case challengeReceived(nonce: String)
        /// Path B: the person typed a code into the sheet.
        case codeEntered(String)
        /// The TV's `.pairSuccess(token:)` frame arrived.
        case pairSuccess(token: String)
        /// The TV's `.error(.pairingRejected, nil)` frame arrived — the
        /// connection stays up (PR-6 spec D-2); the human gets to retype.
        case pairingRejected
        /// The actor's `phase` fell to `.idle`/`.failed` mid-ceremony (a hard
        /// transport drop, or the TV's own 3-attempt teardown).
        case transportClosed
    }

    /// What the DRIVER must now do — see this file's header for why
    /// computing the proof happens INSIDE `handle(_:)`, not here.
    public enum Effect: Sendable, Equatable {
        /// Nothing to do — either a genuinely no-op transition, or an event
        /// that doesn't apply in the current phase (never a crash/assert;
        /// e.g. a duplicate `pairingRejected` after the flow already failed).
        case none
        /// Send `.pairConfirm(proof:, deviceName:)` on the wire.
        case sendProof(proof: String, deviceName: String?)
        /// Show (or refresh) the code-entry sheet — `failedAttempts == 0` is
        /// the first ask, `> 0` is a retype after a rejection.
        case promptForCode(failedAttempts: Int)
        /// Pairing succeeded — the driver persists `token` (Keychain) and
        /// the UI moves to the control surface.
        case reportPaired(token: String)
        /// Pairing ended without success — the UI shows guidance and
        /// returns to browsing.
        case reportFailed(RemotePairingFlowFailure)
    }

    /// Why a pairing attempt ended without a token.
    public enum RemotePairingFlowFailure: Sendable, Equatable {
        /// The TV hit its per-connection attempt cap (or the connection
        /// otherwise dropped) — "ask the operator for a fresh code."
        case connectionTornDown
        /// The person dismissed the code sheet themselves.
        case cancelled
    }

    public private(set) var phase: Phase
    public let context: Context

    public init(context: Context) {
        self.context = context
        self.phase = .awaitingChallenge
    }

    /// Advances the flow by one event, returning what the driver must do.
    /// See this file's header for the "no I/O, no clock, no RNG" contract —
    /// `LANRemotePairingProof.compute(...)` is deterministic, so calling it
    /// here keeps `Effect.sendProof` self-contained without breaking purity.
    ///
    /// ELI5: "This just happened — what do I do, and where are we now?"
    public mutating func handle(_ event: Event) -> Effect {
        switch phase {
        case .awaitingChallenge:
            return handleAwaitingChallenge(event)
        case .awaitingCode(let nonce, let failedAttempts):
            return handleAwaitingCode(event, nonce: nonce, failedAttempts: failedAttempts)
        case .proofSent(let nonce, let failedAttempts):
            return handleProofSent(event, nonce: nonce, failedAttempts: failedAttempts)
        case .paired, .failed:
            // Terminal phases — every event is a stale/duplicate frame
            // arriving after the flow already resolved (spec §3.1: "never a
            // crash, never an assert").
            return .none
        }
    }

    private mutating func handleAwaitingChallenge(_ event: Event) -> Effect {
        switch event {
        case .challengeReceived(let nonce):
            if let knownCode = context.knownCode {
                // Path A: a scanned ceremony QR already carried the code —
                // answer immediately, no human prompt.
                let proof = LANRemotePairingProof.compute(
                    code: knownCode, fingerprintHex: context.expectedFingerprint, nonceHex: nonce
                )
                phase = .proofSent(nonce: nonce, failedAttempts: 0)
                return .sendProof(proof: proof, deviceName: context.deviceName)
            }
            phase = .awaitingCode(nonce: nonce, failedAttempts: 0)
            return .promptForCode(failedAttempts: 0)
        case .transportClosed:
            phase = .failed(.connectionTornDown)
            return .reportFailed(.connectionTornDown)
        case .codeEntered, .pairSuccess, .pairingRejected:
            return .none
        }
    }

    private mutating func handleAwaitingCode(_ event: Event, nonce: String, failedAttempts: Int) -> Effect {
        switch event {
        case .codeEntered(let code):
            let proof = LANRemotePairingProof.compute(
                code: code, fingerprintHex: context.expectedFingerprint, nonceHex: nonce
            )
            phase = .proofSent(nonce: nonce, failedAttempts: failedAttempts)
            return .sendProof(proof: proof, deviceName: context.deviceName)
        case .transportClosed:
            phase = .failed(.connectionTornDown)
            return .reportFailed(.connectionTornDown)
        case .challengeReceived, .pairSuccess, .pairingRejected:
            return .none
        }
    }

    private mutating func handleProofSent(_ event: Event, nonce: String, failedAttempts: Int) -> Effect {
        switch event {
        case .pairSuccess(let token):
            phase = .paired(token: token)
            return .reportPaired(token: token)
        case .pairingRejected:
            // Including when `context.knownCode` was set (spec §3.1): a
            // scanned code that expired between scan and confirm degrades
            // gracefully into typing the freshly rotated one — the stale
            // `knownCode` is discarded after its one shot (this branch never
            // re-reads `context.knownCode`).
            let nextFailedAttempts = failedAttempts + 1
            phase = .awaitingCode(nonce: nonce, failedAttempts: nextFailedAttempts)
            return .promptForCode(failedAttempts: nextFailedAttempts)
        case .transportClosed:
            phase = .failed(.connectionTornDown)
            return .reportFailed(.connectionTornDown)
        case .challengeReceived, .codeEntered:
            return .none
        }
    }
}

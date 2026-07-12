// RemoteControlCoordinator+UIPhase.swift
// IHFeatures
//
// ELI5: The "what should the screen show right now?" rulebook — turns
// whatever just happened to the connection into exactly one of a small set
// of screen states, with no actor, no session, no network involved.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §6.5). A PURE
// relocation out of `RemoteControlCoordinator.swift` (LOC budget) —
// `UIPhase` + `uiPhase(after:current:tvName:)` move here byte-identically
// from the PR-7 shape, THEN gain exactly one new case:
// `.confirmingFingerprint` (the TOFU manual-connect interstitial, spec
// §6.3) and the mapping row `case .awaitingFingerprintConfirmation: return
// current`. That mapping row is DELIBERATELY the pure-map's only
// asymmetric case: the REAL next phase for an unknown fingerprint depends
// on a STORE LOOKUP (`RemotePairingEntryResolver.manualResolution`, spec
// §4.3), which is a side effect this pure function cannot perform — the
// coordinator's `apply(_:)` sets `.confirmingFingerprint` explicitly in
// that branch instead (`RemoteControlCoordinator+ManualConnect.swift`'s
// `handleFingerprintObservation`). `RemoteControlUIStateTests` proves the
// relocation itself is pure by passing UNCHANGED before this file's new
// case/row are exercised by its own additional tests.
import IHLive

extension RemoteControlCoordinator {
    /// Everything the browsing/connecting/controlling screens render
    /// from — the PURE `uiPhase(after:current:tvName:)` mapping (below) is
    /// what turns a `RemoteControlSession.Event` into one of these.
    public enum UIPhase: Equatable {
        case browsing
        case connecting(tvName: String, attempt: Int)
        case codeEntry(tvName: String, failedAttempts: Int)
        case controlling(tvName: String, state: IHRPState)
        case reconnecting(tvName: String, attempt: Int)
        case suspended(tvName: String)
        /// #1424 — a TOFU manual connect observed an UNKNOWN fingerprint;
        /// `FingerprintConfirmView` renders full-screen so a human can
        /// compare it against the TV's own Settings screen BEFORE any code
        /// is typed (spec §4.3/Decision D-3 — required, never skippable).
        /// Never reached for an already-trusted fingerprint — the
        /// coordinator's known-TV shortcut re-attaches silently instead.
        case confirmingFingerprint(tvName: String, fingerprintHex: String)
    }

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
        case .awaitingFingerprintConfirmation:
            // #1424 — deliberately returns `current` unchanged: see this
            // file's header for why the next phase is a coordinator-driven
            // side effect, not a pure mapping.
            return current
        }
    }
}

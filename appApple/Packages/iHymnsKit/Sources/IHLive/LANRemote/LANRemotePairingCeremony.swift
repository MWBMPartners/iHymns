// LANRemotePairingCeremony.swift
// IHLive/LANRemote
//
// ELI5: The tiny rulebook for "is the code currently showing on the TV
// still good to use?" — it expires after 2 minutes, it can only be used
// ONCE, and if enough wrong guesses come in it tells the TV "mint a brand
// new code." It doesn't talk to the network, read the clock itself, or
// generate anything random — every input is handed to it, which is what
// makes it trivial to test.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §3.3 — BINDING).
// `LANRemotePairingCeremonyState` is a plain `struct`, not an `actor`
// (spec Decision D-7): it crosses no isolation boundary of its own — it's
// owned as ordinary mutable state INSIDE `TVListenerActor` (the same
// "`IHRPFrameDecoder` is a struct the actor mutates under its own
// isolation" precedent `IHRPFramer.swift`'s header already documents for
// this module) — and being pure `now:`-parameterised functions (no `Date()`
// read internally, no RNG call internally) is EXACTLY what makes
// `LANRemotePairingCeremonyTests` deterministic without a fake clock object:
// every test just passes literal `Date(timeIntervalSince1970:)` values, the
// same seam `LiveFollowEngine.isFresh(lastUpdatedAt:now:)` already
// establishes in this same `IHLive` target.
//
// **Why an expired/absent code does NOT count toward rotation** (spec
// Decision D-5): `rotateAfterFailures` exists to burn a code an attacker is
// actively guessing against; if a wrong guess against an ALREADY-DEAD code
// also counted, an attacker could spam guesses against a stale code
// specifically to force a rotation on demand (a cheap way to churn the
// live code while the real operator isn't looking). `TVListenerActor
// +Pairing.swift`'s `handlePairConfirm` checks `isActive(now:)` BEFORE ever
// calling `recordFailure()`, so a dead-code guess short-circuits to
// rejection without touching `wrongAttempts` at all.
import Foundation

/// The pure pairing-ceremony state machine — see this file's header for why
/// it's a `struct`, not an `actor`.
///
/// ELI5: "Is there a code showing right now, and has it expired, been used
/// up, or been guessed wrong too many times?"
public struct LANRemotePairingCeremonyState: Sendable, Equatable {

    /// The three tunable numbers strategy §2.4.3 fixes — asserted literally
    /// by `LANRemotePairingCeremonyTests` (spec §7.2: "asserted literally so
    /// a drive-by 'tune' shows up in review").
    public struct Configuration: Sendable, Equatable {
        /// How long a minted code stays valid — strategy §2.4.3's "Code TTL
        /// 2min."
        public var codeTTL: TimeInterval
        /// Global (per-CODE, not per-connection) wrong-proof count that
        /// forces the TV to mint a fresh code — strategy §2.4.3's "5
        /// fails→rotate."
        public var rotateAfterFailures: Int
        /// Per-CONNECTION cap on `.pairConfirm` attempts before that
        /// connection is torn down outright — a SEPARATE, tighter guard
        /// from `rotateAfterFailures` above (one hostile/buggy connection
        /// can't out-guess the global rotation counter by spraying attempts
        /// from a single socket; `TVListenerActor+Pairing.swift`'s
        /// `handlePairConfirm` enforces this one).
        public var maxAttemptsPerConnection: Int

        /// The CUMULATIVE wrong-proof ceiling for one whole ceremony (across
        /// EVERY rotation, from `beginPairing()` until the ceremony is
        /// exhausted or the operator closes it) — the load-bearing
        /// brute-force bound.
        ///
        /// **Why this exists (post-#1421 adversarial-review fix, spec §8
        /// brute-force row):** `rotateAfterFailures` only swaps one RANDOM
        /// 6-digit code for another RANDOM one — rotation gives an attacker
        /// (who is guessing uniformly regardless) ZERO benefit and, on its
        /// own, never STOPS the ceremony. Without a cumulative ceiling a LAN
        /// attacker could cycle connections (4 concurrent × 3 attempts each)
        /// indefinitely while the overlay is open and, over minutes, brute a
        /// ~20-bit code to percent-level odds — NOT the "≤5 guesses,
        /// 0.0005%" the §8 threat model claimed. This counter is STICKY
        /// across rotations (only `begin(...)`, an operator-initiated fresh
        /// ceremony, resets it — `rotate(...)` does NOT), so the TOTAL
        /// guesses per ceremony are hard-bounded here regardless of TTL
        /// re-arms or intervening successful pairs. Default 15 = 3 codes'
        /// worth of rotation (`rotateAfterFailures` × 3) ⇒ ≤0.0015% per
        /// ceremony, with generous headroom for a legitimate operator's own
        /// mistypes.
        public var maxCeremonyFailures: Int

        public init(
            codeTTL: TimeInterval = 120,
            rotateAfterFailures: Int = 5,
            maxAttemptsPerConnection: Int = 3,
            maxCeremonyFailures: Int = 15
        ) {
            self.codeTTL = codeTTL
            self.rotateAfterFailures = rotateAfterFailures
            self.maxAttemptsPerConnection = maxAttemptsPerConnection
            self.maxCeremonyFailures = maxCeremonyFailures
        }
    }

    /// What one wrong proof means for the ceremony as a whole — returned by
    /// `recordFailure()` so `TVListenerActor+Pairing.swift` can react without
    /// re-reading the raw counters.
    ///
    /// ELI5: "just note it," "swap the code for a fresh one," or "that's
    /// enough — shut the whole pairing session down."
    public enum FailureOutcome: Sendable, Equatable {
        /// Recorded; the current code stays live (below every threshold).
        case recorded
        /// `rotateAfterFailures` reached for the CURRENT code — the caller
        /// mints a fresh code and calls `rotate(code:now:)`.
        case rotate
        /// `maxCeremonyFailures` reached for the WHOLE ceremony — the caller
        /// `end()`s it; only a fresh operator-initiated `begin(code:now:)`
        /// re-opens pairing.
        case exhausted
    }

    /// The code currently displayed on the TV, or `nil` if no ceremony is
    /// running (nobody has opened the pairing overlay) OR the last-minted
    /// code has already been successfully consumed (`consume()`).
    public private(set) var activeCode: String?

    /// When `activeCode` was minted — `nil` exactly when `activeCode` is
    /// `nil`. Read by `isActive(now:)`'s TTL check.
    public private(set) var mintedAt: Date?

    /// Wrong `.pairConfirm` proofs recorded against the CURRENT code —
    /// reset to `0` by BOTH `begin(code:now:)` and `rotate(code:now:)` (a
    /// fresh code, however it arrived, starts a fresh per-code count). This
    /// is the counter that drives `rotateAfterFailures`.
    public private(set) var wrongAttempts: Int = 0

    /// CUMULATIVE wrong proofs across the whole ceremony (every rotation
    /// included) — reset ONLY by `begin(code:now:)`, deliberately NOT by
    /// `rotate(code:now:)`, so it survives rotations and bounds the TOTAL
    /// guesses per ceremony (see `Configuration.maxCeremonyFailures`).
    public private(set) var ceremonyFailures: Int = 0

    public let configuration: Configuration

    public init(configuration: Configuration = .init()) {
        self.configuration = configuration
    }

    /// Whether `activeCode` both EXISTS and is still within its TTL window
    /// as of `now`. **Checks `activeCode != nil` FIRST** — `consume()`
    /// deliberately clears only `activeCode` (not `mintedAt`), so a
    /// `mintedAt`-only check would report a just-consumed, single-use code
    /// as still active for the rest of its TTL window (caught by
    /// `LANRemotePairingCeremonyTests`' single-use test).
    ///
    /// ELI5: "Is there still a live code right now?"
    public func isActive(now: Date) -> Bool {
        guard activeCode != nil, let mintedAt else { return false }
        return now < mintedAt.addingTimeInterval(configuration.codeTTL)
    }

    /// Starts a BRAND-NEW ceremony (the operator opened the pairing overlay)
    /// with a freshly-minted `code` — resets BOTH `wrongAttempts` AND the
    /// cumulative `ceremonyFailures` to `0`. This is the ONLY thing that
    /// clears `ceremonyFailures`; an auto-rotation mid-ceremony uses
    /// `rotate(code:now:)` instead, which preserves it.
    ///
    /// ELI5: "Someone just opened the pairing screen — brand-new code, brand-
    /// new attempt budget, forget everything that happened before."
    public mutating func begin(code: String, now: Date) {
        activeCode = code
        mintedAt = now
        wrongAttempts = 0
        ceremonyFailures = 0
    }

    /// Rotates to a freshly-minted `code` WITHIN the current ceremony —
    /// resets the per-code `wrongAttempts` but deliberately PRESERVES the
    /// cumulative `ceremonyFailures` (that's the whole point: rotation must
    /// not hand a brute-forcer a fresh total-guess budget). Called both by
    /// the failure-triggered rotation (`recordFailure()` → `.rotate`) and by
    /// the overlay's cosmetic TTL refresh.
    ///
    /// ELI5: "Swap the on-screen code for a fresh one, but DON'T forget how
    /// many wrong guesses this whole session has already racked up."
    public mutating func rotate(code: String, now: Date) {
        activeCode = code
        mintedAt = now
        wrongAttempts = 0
    }

    /// Fully disarms the ceremony — the operator closed the pairing
    /// overlay. A `.pairing` connection that's already parked stays parked
    /// (`TVListenerActor`'s own state, untouched here); it simply can never
    /// successfully confirm until `begin(code:now:)` is called again.
    ///
    /// ELI5: "Turn the pairing screen off."
    public mutating func end() {
        activeCode = nil
        mintedAt = nil
    }

    /// Marks `activeCode` used up on a SUCCESSFUL proof — single-use, per
    /// spec §3.1: a code that already paired one remote can never pair a
    /// second.
    ///
    /// ELI5: "That code just worked — burn it so it can't be used again."
    public mutating func consume() {
        activeCode = nil
    }

    /// Records one wrong `.pairConfirm` proof against the CURRENT code and
    /// advances BOTH counters (per-code `wrongAttempts` and cumulative
    /// `ceremonyFailures`).
    ///
    /// - Returns: the `FailureOutcome` the caller (`TVListenerActor
    ///   +Pairing.swift`) must act on — `.exhausted` (the cumulative ceiling
    ///   is reached: `end()` the ceremony) takes precedence over `.rotate`
    ///   (the per-code threshold is reached: mint a fresh code and
    ///   `rotate(code:now:)`), which takes precedence over `.recorded`
    ///   (keep going). Checking `.exhausted` FIRST means the final guess
    ///   shuts the ceremony down rather than pointlessly rotating a code
    ///   that's about to be abandoned.
    @discardableResult
    public mutating func recordFailure() -> FailureOutcome {
        wrongAttempts += 1
        ceremonyFailures += 1
        if ceremonyFailures >= configuration.maxCeremonyFailures { return .exhausted }
        if wrongAttempts >= configuration.rotateAfterFailures { return .rotate }
        return .recorded
    }
}

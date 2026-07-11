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

        public init(codeTTL: TimeInterval = 120, rotateAfterFailures: Int = 5, maxAttemptsPerConnection: Int = 3) {
            self.codeTTL = codeTTL
            self.rotateAfterFailures = rotateAfterFailures
            self.maxAttemptsPerConnection = maxAttemptsPerConnection
        }
    }

    /// The code currently displayed on the TV, or `nil` if no ceremony is
    /// running (nobody has opened the pairing overlay) OR the last-minted
    /// code has already been successfully consumed (`consume()`).
    public private(set) var activeCode: String?

    /// When `activeCode` was minted — `nil` exactly when `activeCode` is
    /// `nil`. Read by `isActive(now:)`'s TTL check.
    public private(set) var mintedAt: Date?

    /// Wrong `.pairConfirm` proofs recorded against the CURRENT code —
    /// reset to `0` by `begin(code:now:)` (a fresh code starts a fresh
    /// count; `recordFailure()`'s own rotation, when it fires, immediately
    /// calls `begin(code:now:)` again for the new code).
    public private(set) var wrongAttempts: Int = 0

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

    /// (Re-)arms the ceremony with a freshly-minted `code` — resets
    /// `wrongAttempts` to `0` regardless of whatever it was before (a fresh
    /// code deserves a fresh attempt budget, whether this is the FIRST code
    /// of a ceremony or a rotation mid-ceremony).
    ///
    /// ELI5: "Here's the new code showing on screen now — start the clock
    /// over."
    public mutating func begin(code: String, now: Date) {
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

    /// Records one wrong `.pairConfirm` proof against the CURRENT code.
    ///
    /// - Returns: `true` exactly when `wrongAttempts` has just reached
    ///   `configuration.rotateAfterFailures` — the caller (`TVListenerActor
    ///   +Pairing.swift`) MUST respond by minting a fresh code and calling
    ///   `begin(code:now:)` again (spec §3.4 step 4's "if
    ///   pairingCeremony.recordFailure() { rotateActiveCode() }").
    @discardableResult
    public mutating func recordFailure() -> Bool {
        wrongAttempts += 1
        return wrongAttempts >= configuration.rotateAfterFailures
    }
}

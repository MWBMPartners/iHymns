// LANRemotePairingAuthority.swift
// IHLive/LANRemote
//
// ELI5: The one question `TVListenerActor` asks before letting a connection
// out of "not fully trusted yet": "does this token belong to a remote we've
// already paired with?" — answered by a small, swappable object instead of
// baked-in logic, so a TEST can answer with an in-memory list while the
// real tvOS app (PR-6) answers by checking its Keychain.
//
// DETAILED: #1420 (`.claude/apple-native-strategy.md` §2.4.3: "Success
// mints a per-remote 32-byte token (TV stores `sha256`... )"). This PR does
// NOT implement the pairing CEREMONY itself (the 6-digit code + QR flow,
// the HMAC channel-binding, the 2-minute TTL/5-fail rotation — all
// explicitly PR-6's job per `.claude/apple-phase2-implementation-plan.md`
// §2). What it DOES need is the SHAPE of the boundary `TVListenerActor`'s
// `.unpaired → .pairing → .paired` state machine sits behind, so that:
//   (a) `TVListenerActor` can be built and tested NOW without depending on
//       PR-6's not-yet-written ceremony code, and
//   (b) PR-6 has a single, obvious seam to implement against — a NEW type
//       conforming to `LANRemotePairingAuthority`, backed by Keychain
//       lookups, dropped in via `TVListenerActor.Configuration` with zero
//       changes to `TVListenerActor` itself.
//
// The reference implementation below (`InMemoryLANRemotePairingAuthority`)
// is what `LANRemoteTests`' TLS-loopback suite injects, and follows the
// SAME "never store the raw credential" discipline strategy §2.4.3 states
// for the real thing — it hashes every token with SHA-256 before storing or
// comparing it, so even this throwaway in-memory version never holds a
// paired remote's actual bearer token in a form worth stealing.
import Foundation

/// The pairing-state boundary `TVListenerActor` asks before promoting a
/// connection out of confinement.
///
/// ELI5: "Have we met this remote before?" / "remember this new one" /
/// "forget that one."
///
/// DETAILED: `async` throughout — a real Keychain-backed implementation
/// (PR-6) may need to hop off the calling actor to read the Keychain
/// (`SecItemCopyMatching` is a synchronous call, but PR-6's real
/// implementation may wrap it behind its own actor for the same
/// no-shared-mutable-state discipline this whole subsystem follows); this
/// protocol's shape must not force a synchronous-only implementation.
public protocol LANRemotePairingAuthority: Sendable {
    /// Whether `token` (the raw value a `hello` message presented) belongs
    /// to a remote that has ALREADY completed pairing with this TV.
    ///
    /// ELI5: "Is this the login pass of a remote we already trust?"
    func isPairedToken(_ token: String) async -> Bool

    /// Records `token` as belonging to a newly-paired remote — called once
    /// a `.pairing`-state connection is externally confirmed (the actual
    /// ceremony completion, PR-6's `TVListenerActor.completePairing(...)`
    /// caller).
    ///
    /// ELI5: "Remember this new remote from now on."
    func registerPairedToken(_ token: String) async

    /// Records `token` as belonging to a newly-paired remote, ALONGSIDE
    /// its `metadata` (device name/kind/paired-at, for the trusted-remotes
    /// Settings list) — #1421 (PR-6 spec §2's protocol-extension pattern):
    /// a NEW requirement added to this ALREADY-SHIPPED (PR-4/#1420)
    /// protocol, with a forwarding default below so `InMemoryLANRemotePairingAuthority`
    /// (this file) and any PR-4-era test double compile UNCHANGED.
    ///
    /// ELI5: "Remember this new remote from now on, plus its name and what
    /// kind of device it is."
    func registerPairedToken(_ token: String, metadata: LANRemotePairingMetadata) async

    /// Forgets `token` — the "revoke per-remote in TV Settings" action
    /// strategy §2.4.3 names (PR-6/PR-7's UI, this is its data-layer hook).
    ///
    /// ELI5: "Forget this remote — it can't control the TV anymore."
    func revokePairedToken(_ token: String) async
}

/// Default witness for the metadata-carrying registration overload above —
/// #1421 (PR-6 spec §2): forwards to the pre-existing single-argument
/// `registerPairedToken(_:)`, discarding the metadata, so a conformer that
/// predates #1421 (this file's own `InMemoryLANRemotePairingAuthority`)
/// keeps compiling with ZERO source changes. `KeychainLANRemotePairingAuthority`
/// (`KeychainLANRemotePairingAuthority.swift`, #1421) implements the
/// metadata-carrying overload directly instead — Swift's protocol-witness
/// dispatch prefers a conformer's OWN matching method over this default
/// even when called through the `any LANRemotePairingAuthority` existential
/// `TVListenerActor.Configuration.pairingAuthority` holds.
extension LANRemotePairingAuthority {
    public func registerPairedToken(_ token: String, metadata: LANRemotePairingMetadata) async {
        await registerPairedToken(token)
    }
}

/// A simple, non-persistent `LANRemotePairingAuthority` — what
/// `LANRemoteTests` injects, and a reasonable placeholder for any
/// standalone/dev use before PR-6's real Keychain-backed implementation
/// exists.
///
/// ELI5: A little notebook of "remotes we trust," that forgets everything
/// when the app quits.
///
/// DETAILED: `actor` (not a plain class/struct) — its own mutable
/// `pairedTokenHashes` set needs the same "no torn reads/writes across
/// concurrent callers" guarantee every other piece of actor-isolated state
/// in this package gets (strategy §1.3). Stores SHA-256 digests, never raw
/// tokens — see this file's header comment.
public actor InMemoryLANRemotePairingAuthority: LANRemotePairingAuthority {
    private var pairedTokenHashes: Set<String> = []

    public init() {}

    public func isPairedToken(_ token: String) async -> Bool {
        pairedTokenHashes.contains(Self.hash(token))
    }

    public func registerPairedToken(_ token: String) async {
        pairedTokenHashes.insert(Self.hash(token))
    }

    public func revokePairedToken(_ token: String) async {
        pairedTokenHashes.remove(Self.hash(token))
    }

    private static func hash(_ token: String) -> String {
        LANRemoteFingerprint.sha256Hex(Data(token.utf8))
    }
}

// PairingTestRemote.swift
// IHLiveTests
//
// ELI5: A pretend phone that actually does the real pairing dance —
// connects for real, waits for the TV's code-challenge, computes (or, for
// the invalid-proof tests, fakes) the proof, and sends it back — shared by
// every loopback test file that needs to drive the wire ceremony from the
// remote side.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §7.5). Originally
// declared inline in `TVListenerPairingLoopbackTests.swift`; extracted into
// its own file for #1424 (PR-8) purely so `TVListenerPairingLoopbackTests
// .swift` stays under `Scripts/loc-budget.sh`'s 400-line ceiling once the
// `fingerprintOverride` parameter (below) and its doc comment landed — the
// SAME "split for the LOC/type-length budget, not for a design reason"
// precedent `RemoteControlSessionReArmTests.swift` already established.
// `internal` (not `private`) so BOTH `TVListenerPairingLoopbackTests.swift`
// and `TVListenerPairingRelayLoopbackTests.swift` (#1424, the §8-row-1
// wrong-fingerprint relay test) can construct it.
import Foundation
import IHModels
import Network
@testable import IHLive

/// A test-double remote that drives the REAL pairing wire ceremony against
/// a real `RemoteSessionActor` — this IS the exact recipe PR-7's real
/// client uses: `LANRemotePairingProof.compute(...)` is the SAME public
/// function the TV's `handlePairConfirm` verifies against (one
/// implementation, two callers, per this repo's modularity rule).
///
/// ELI5: A pretend phone that actually does the pairing dance for real —
/// connects, waits for the code-challenge, computes (or, for the invalid-
/// proof tests, fakes) the proof, and sends it back.
struct PairingTestRemote {
    let remote: RemoteSessionActor

    init(kind: IHRPRemoteKind = .phone) {
        remote = RemoteSessionActor(configuration: .init(kind: kind))
    }

    /// Connects fresh (no saved token) and waits for the TV's
    /// `.pairChallenge`, returning the nonce it carries.
    func connectAndAwaitChallenge(to endpoint: NWEndpoint, expectedFingerprint: String) async throws -> String {
        try await remote.connect(to: endpoint, expectedFingerprint: expectedFingerprint, token: nil)
        let frame = try await waitFor(remote.incomingMessages) { if case .pairChallenge = $0.message { true } else { false } }
        guard case .pairChallenge(let nonce) = frame.message else {
            throw LANRemoteTestTimeoutError()
        }
        return nonce
    }

    /// Computes the real proof (or, `invalidProof: true`, substitutes
    /// garbage) and sends `.pairConfirm`.
    ///
    /// - Parameter fingerprintOverride: #1424 (PR-8 spec §7.3/§8 row 1) —
    ///   when set, the proof is computed over THIS fingerprint instead of
    ///   `fingerprintHex`, simulating a relay/MITM whose proof binds to the
    ///   WRONG TLS session's fingerprint (the TV always verifies over its
    ///   own `identity.fingerprint`, `TVListenerActor+Pairing.swift:166`,
    ///   so this must always be rejected). `nil` (the default) preserves
    ///   every existing call site's behaviour byte-for-byte.
    func sendPairConfirm(
        code: String, fingerprintHex: String, nonce: String, deviceName: String? = nil,
        invalidProof: Bool = false, fingerprintOverride: String? = nil
    ) async throws {
        let proof: String
        if invalidProof {
            proof = String(repeating: "0", count: 64)
        } else {
            proof = LANRemotePairingProof.compute(code: code, fingerprintHex: fingerprintOverride ?? fingerprintHex, nonceHex: nonce)
        }
        try await remote.send(.pairConfirm(proof: proof, deviceName: deviceName))
    }
}

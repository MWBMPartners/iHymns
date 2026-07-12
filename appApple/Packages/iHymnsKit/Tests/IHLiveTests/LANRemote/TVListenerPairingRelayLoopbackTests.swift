// TVListenerPairingRelayLoopbackTests.swift
// IHLiveTests
//
// ELI5: Proves that if a proof is computed over the WRONG TV's fingerprint
// (exactly what happens when a relay/man-in-the-middle passes a proof
// through from a session it terminated itself), the real TV rejects it —
// and that the same connection can still recover by sending a proof over
// the CORRECT fingerprint right afterward.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §7.3/§8 row 1 — "a
// relayed proof never pairs the attacker's victim-facing session through to
// the real TV," made executable). Split into its OWN file — rather than
// added to `TVListenerPairingLoopbackTests.swift` — purely to keep THAT
// file's `type_body_length` under SwiftLint's budget (the
// `RemoteControlSessionLoopbackTests`/`RemoteControlSessionReArmTests.swift`
// precedent for the identical reason); reuses that file's `PairingTestRemote`/
// `makeListener`/`hostPortEndpoint` helpers verbatim (all `internal`, not
// `private`, specifically so this file can).
import Foundation
import Network
import Testing
@testable import IHLive

@Suite(
    "TVListenerActor pairing ceremony — wrong-fingerprint relay rejection (TLS loopback)",
    .enabled(
        if: ProcessInfo.processInfo.environment["IHYMNS_LAN_LOOPBACK_TESTS"] == "1",
        "Live 127.0.0.1 TLS loopback needs macOS Local Network permission an unsigned headless `swift test` binary can't obtain — set IHYMNS_LAN_LOOPBACK_TESTS=1 on a Mac where iHymns has that grant. See LANRemoteLoopbackTests.swift's header comment."
    )
)
struct TVListenerPairingRelayLoopbackTests {

    /// Delegates to `TVListenerPairingLoopbackTests`'s own `internal`
    /// helper — see this file's header for why it's reused rather than
    /// re-derived.
    private func makeListener() async throws -> (TVListenerActor, LANRemoteIdentity) {
        try await TVListenerPairingLoopbackTests().makeListener()
    }

    private func hostPortEndpoint(port: NWEndpoint.Port) -> NWEndpoint {
        TVListenerPairingLoopbackTests().hostPortEndpoint(port: port)
    }

    @Test(
        "A proof computed over a DIFFERENT (attacker's) fingerprint is rejected; a correct-fingerprint retry on the SAME connection then succeeds"
    )
    func proofOverWrongFingerprintIsRejectedThenCorrectRetrySucceeds() async throws {
        let (listener, identity) = try await makeListener()
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else {
            Issue.record("listener never bound a port")
            return
        }

        let code = await listener.beginPairing()
        let remote = PairingTestRemote()
        let nonce = try await remote.connectAndAwaitChallenge(to: hostPortEndpoint(port: port), expectedFingerprint: identity.fingerprint)

        // A relay/MITM scenario (spec §8 row 1): the proof is computed over
        // an ATTACKER's own fingerprint, never the real TV's
        // `identity.fingerprint` — exactly what a relayed proof looks like
        // when it binds to the wrong TLS session. The TV verifies over ITS
        // OWN identity (`TVListenerActor+Pairing.swift:166`), so this must
        // be rejected.
        let attackerFingerprint = String(repeating: "a", count: 64)
        try await remote.sendPairConfirm(
            code: code, fingerprintHex: identity.fingerprint, nonce: nonce, fingerprintOverride: attackerFingerprint
        )
        let wrongFingerprintReply = try await waitFor(remote.remote.incomingMessages) { if case .error = $0.message { true } else { false } }
        guard case .error(let wrongFingerprintErrorCode, _) = wrongFingerprintReply.message else {
            Issue.record("expected .error for the wrong-fingerprint proof")
            return
        }
        #expect(wrongFingerprintErrorCode == .pairingRejected)

        // The SAME connection, now computing the proof over the CORRECT
        // fingerprint (its 2nd attempt, still within the per-connection cap
        // of 3), pairs successfully — the connection itself survived the
        // rejection, exactly as an ordinary wrong-code retry would.
        try await remote.sendPairConfirm(code: code, fingerprintHex: identity.fingerprint, nonce: nonce)
        let successFrame = try await waitFor(remote.remote.incomingMessages) { if case .pairSuccess = $0.message { true } else { false } }
        guard case .pairSuccess = successFrame.message else {
            Issue.record("expected .pairSuccess after the correct-fingerprint retry")
            return
        }

        await remote.remote.disconnect()
    }
}

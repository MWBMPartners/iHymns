// TVListenerPairingLoopbackTests.swift
// IHLiveTests
//
// ELI5: The REAL end-to-end proof that the whole pairing ceremony works —
// a real TV listener and a real remote connection over `127.0.0.1`, real
// TLS 1.3, actually running the code-entry dance: the TV challenges, the
// remote computes a proof and answers, and the TV either lets it in or
// rejects it, exactly as a real phone and a real TV would.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §7.5). Verbatim
// posture from `LANRemoteLoopbackTests.swift` (PR-4/#1420): opt-in behind
// `IHYMNS_LAN_LOOPBACK_TESTS=1` (macOS Local Network Privacy blocks an
// unsigned headless `swift test` binary from completing a REAL
// `127.0.0.1` handshake — that file's header comment is the full
// explanation, unchanged here), `advertiseViaBonjour: false`, connect by
// explicit `.hostPort(...)`, and a `ManualLANRemoteClock` injected into the
// LISTENER's `Configuration` specifically so the TTL/rotation tests can
// drive the ceremony's clock deterministically (no real sleeps).
import Foundation
import IHModels
import Network
import Testing
@testable import IHLive

/// A test-double remote that drives the REAL pairing wire ceremony against
/// a real `RemoteSessionActor` — this IS the exact recipe PR-7's real
/// client will use: `LANRemotePairingProof.compute(...)` is the SAME
/// public function the TV's `handlePairConfirm` verifies against (one
/// implementation, two callers, per this repo's modularity rule).
///
/// ELI5: A pretend phone that actually does the pairing dance for real —
/// connects, waits for the code-challenge, computes (or, for the invalid-
/// proof tests, fakes) the proof, and sends it back.
private struct PairingTestRemote {
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
    func sendPairConfirm(code: String, fingerprintHex: String, nonce: String, deviceName: String? = nil, invalidProof: Bool = false) async throws {
        let proof = invalidProof
            ? String(repeating: "0", count: 64)
            : LANRemotePairingProof.compute(code: code, fingerprintHex: fingerprintHex, nonceHex: nonce)
        try await remote.send(.pairConfirm(proof: proof, deviceName: deviceName))
    }
}

@Suite(
    "TVListenerActor pairing ceremony — TLS loopback integration",
    .enabled(
        if: ProcessInfo.processInfo.environment["IHYMNS_LAN_LOOPBACK_TESTS"] == "1",
        "Live 127.0.0.1 TLS loopback needs macOS Local Network permission an unsigned headless `swift test` binary can't obtain — set IHYMNS_LAN_LOOPBACK_TESTS=1 on a Mac where iHymns has that grant. See LANRemoteLoopbackTests.swift's header comment."
    )
)
struct TVListenerPairingLoopbackTests {

    /// - Parameter clock: Injectable so a TTL/rotation test can keep its
    ///   OWN reference to advance later (`expiredCodeNeverCountsTowardRotation`)
    ///   — returning a 3-tuple here would trip SwiftLint's `large_tuple`
    ///   rule, so the caller supplies (and keeps) the clock instead of
    ///   receiving it back.
    private func makeListener(
        maxUnpaired: Int = 4,
        pairingAuthority: any LANRemotePairingAuthority = InMemoryLANRemotePairingAuthority(),
        clock: any LANRemoteClock = ManualLANRemoteClock()
    ) async throws -> (TVListenerActor, LANRemoteIdentity) {
        // `KeychainTestSerialization` (`LANRemoteTestSupport.swift`) — every
        // test in this suite calls `generateSelfSigned()`, and this suite's
        // 6 tests run CONCURRENTLY by default; wrapping just the identity
        // generation (not the whole test body, which is mostly network
        // I/O that doesn't touch the Keychain) is enough to eliminate the
        // SAME transient contention documented there.
        let identity = try await KeychainTestSerialization.shared.run {
            try LANRemoteIdentityFactory.generateSelfSigned()
        }
        let config = TVListenerActor.Configuration(
            port: .any,
            advertiseViaBonjour: false,
            maxUnpairedConnections: maxUnpaired,
            pairingAuthority: pairingAuthority,
            clock: clock
        )
        let listener = TVListenerActor(identity: identity, configuration: config)
        try await listener.start()
        try await listener.waitUntilReady()
        return (listener, identity)
    }

    private func hostPortEndpoint(port: NWEndpoint.Port) -> NWEndpoint {
        .hostPort(host: "127.0.0.1", port: port)
    }

    // MARK: - Happy path: full ceremony, then a token-based reconnect skips it

    @Test("beginPairing() → a remote pairs via the ceremony and receives .pairSuccess + .capabilities; reconnecting with the saved token skips the ceremony")
    func happyPathCeremonyThenReconnect() async throws {
        let (listener, identity) = try await makeListener()
        defer { identity.removeFromKeychain() }
        guard let port = await listener.boundPort else {
            Issue.record("listener never bound a port")
            return
        }

        let code = await listener.beginPairing()
        let remote = PairingTestRemote()
        let nonce = try await remote.connectAndAwaitChallenge(to: hostPortEndpoint(port: port), expectedFingerprint: identity.fingerprint)
        try await remote.sendPairConfirm(code: code, fingerprintHex: identity.fingerprint, nonce: nonce, deviceName: "Test Phone")

        let successFrame = try await waitFor(remote.remote.incomingMessages) { if case .pairSuccess = $0.message { true } else { false } }
        guard case .pairSuccess(let token) = successFrame.message else {
            Issue.record("expected .pairSuccess")
            return
        }
        _ = try await waitFor(remote.remote.phaseUpdates) { if case .controlling = $0 { true } else { false } }

        #expect(await listener.pairedConnectionCount == 1)
        #expect(await remote.remote.currentPairingToken == token)

        await remote.remote.disconnect()

        // Reconnect with the saved token — straight to `.controlling`, the
        // ceremony never runs again.
        let reconnected = RemoteSessionActor(configuration: .init(kind: .phone))
        try await reconnected.connect(to: hostPortEndpoint(port: port), expectedFingerprint: identity.fingerprint, token: token)
        _ = try await waitFor(reconnected.phaseUpdates) { if case .controlling = $0 { true } else { false } }
        #expect(await listener.pairedConnectionCount == 1)

        await reconnected.disconnect()
        await listener.stop()
    }

    // MARK: - Wrong proof: rejected in place, then the per-connection cap tears down

    @Test("A wrong proof is rejected but the connection stays open; exceeding the per-connection attempt cap tears it down")
    func wrongProofRejectedThenCapTearsDown() async throws {
        let (listener, identity) = try await makeListener()
        defer { identity.removeFromKeychain() }
        guard let port = await listener.boundPort else {
            Issue.record("listener never bound a port")
            return
        }

        let code = await listener.beginPairing()
        let remote = PairingTestRemote()
        let nonce = try await remote.connectAndAwaitChallenge(to: hostPortEndpoint(port: port), expectedFingerprint: identity.fingerprint)

        // 3 wrong proofs (§3.4's algorithm: `pairingAttempts <=
        // maxAttemptsPerConnection`, default 3, all three still proceed to
        // verify) — each rejected via `.pairingRejected`, NOT `.unauthorized`
        // (`RemoteSessionActor` only disconnects on the latter), so the
        // connection stays open every time.
        for attemptIndex in 0..<3 {
            try await remote.sendPairConfirm(code: code, fingerprintHex: identity.fingerprint, nonce: nonce, invalidProof: true)
            let reply = try await waitFor(remote.remote.incomingMessages) { if case .error = $0.message { true } else { false } }
            guard case .error(let errorCode, _) = reply.message else {
                Issue.record("attempt \(attemptIndex): expected .error")
                return
            }
            #expect(errorCode == .pairingRejected)
        }
        #expect(await remote.remote.phase.isConnected)

        // The 4th attempt exceeds the cap outright — the TV tears the
        // connection down without even checking the proof this time.
        try await remote.sendPairConfirm(code: code, fingerprintHex: identity.fingerprint, nonce: nonce, invalidProof: true)
        try await waitForCondition { await listener.unpairedConnectionCount == 0 }

        await listener.stop()
    }

    // MARK: - Rotation: enough global wrong proofs (across ≤2 connections) rotates the code

    @Test("5 wrong proofs across 2 connections rotates the code; the OLD code is now rejected, the NEW code pairs")
    func rotationAfterFiveGlobalWrongProofs() async throws {
        let (listener, identity) = try await makeListener()
        defer { identity.removeFromKeychain() }
        guard let port = await listener.boundPort else {
            Issue.record("listener never bound a port")
            return
        }

        let oldCode = await listener.beginPairing()

        let remoteA = PairingTestRemote()
        let nonceA = try await remoteA.connectAndAwaitChallenge(to: hostPortEndpoint(port: port), expectedFingerprint: identity.fingerprint)
        let remoteB = PairingTestRemote()
        let nonceB = try await remoteB.connectAndAwaitChallenge(to: hostPortEndpoint(port: port), expectedFingerprint: identity.fingerprint)

        // Connection A: 3 wrong attempts (global wrongAttempts 1, 2, 3) —
        // exactly its own per-connection cap, never exceeding it.
        for _ in 0..<3 {
            try await remoteA.sendPairConfirm(code: oldCode, fingerprintHex: identity.fingerprint, nonce: nonceA, invalidProof: true)
            _ = try await waitFor(remoteA.remote.incomingMessages) { if case .error = $0.message { true } else { false } }
        }
        // Connection B: 2 wrong attempts (global 4, then 5 — the 5th GLOBAL
        // failure triggers rotation, spec §3.4/Configuration default).
        for _ in 0..<2 {
            try await remoteB.sendPairConfirm(code: oldCode, fingerprintHex: identity.fingerprint, nonce: nonceB, invalidProof: true)
            _ = try await waitFor(remoteB.remote.incomingMessages) { if case .error = $0.message { true } else { false } }
        }

        _ = try await waitFor(listener.pairingEvents) { if case .codeRotated = $0 { true } else { false } }
        let newCode = await listener.activePairingCode
        #expect(newCode != nil)
        #expect(newCode != oldCode)

        // A FRESH connection using the OLD code is rejected (it no longer
        // matches `pairingCeremony.activeCode`).
        let remoteC = PairingTestRemote()
        let nonceC = try await remoteC.connectAndAwaitChallenge(to: hostPortEndpoint(port: port), expectedFingerprint: identity.fingerprint)
        try await remoteC.sendPairConfirm(code: oldCode, fingerprintHex: identity.fingerprint, nonce: nonceC)
        let oldCodeReply = try await waitFor(remoteC.remote.incomingMessages) { if case .error = $0.message { true } else { false } }
        guard case .error(let oldCodeErrorCode, _) = oldCodeReply.message else {
            Issue.record("expected .error for the old code")
            return
        }
        #expect(oldCodeErrorCode == .pairingRejected)

        // The SAME connection, now trying the NEW code (its 2nd attempt,
        // still within its own cap of 3), pairs successfully.
        try await remoteC.sendPairConfirm(code: newCode ?? "", fingerprintHex: identity.fingerprint, nonce: nonceC)
        _ = try await waitFor(remoteC.remote.incomingMessages) { if case .pairSuccess = $0.message { true } else { false } }

        await remoteA.remote.disconnect()
        await remoteB.remote.disconnect()
        await remoteC.remote.disconnect()
        await listener.stop()
    }

    // MARK: - Expired code: rejected, and never counts toward rotation

    @Test("An expired code's rejections never count toward rotation, even repeated well past the threshold")
    func expiredCodeNeverCountsTowardRotation() async throws {
        let clock = ManualLANRemoteClock()
        let (listener, identity) = try await makeListener(clock: clock)
        defer { identity.removeFromKeychain() }
        guard let port = await listener.boundPort else {
            Issue.record("listener never bound a port")
            return
        }

        let code = await listener.beginPairing()
        clock.advance(by: 121) // past the 120s TTL (spec §3.3 default)

        // 6 attempts (one more than `rotateAfterFailures`'s default of 5) —
        // if an expired-code rejection counted toward rotation, this would
        // have DEFINITELY rotated by now (spec Decision D-5: it must not).
        for attemptIndex in 0..<6 {
            let remote = PairingTestRemote()
            let nonce = try await remote.connectAndAwaitChallenge(to: hostPortEndpoint(port: port), expectedFingerprint: identity.fingerprint)
            try await remote.sendPairConfirm(code: code, fingerprintHex: identity.fingerprint, nonce: nonce)
            let reply = try await waitFor(remote.remote.incomingMessages) { if case .error = $0.message { true } else { false } }
            guard case .error(let errorCode, _) = reply.message else {
                Issue.record("attempt \(attemptIndex): expected .error")
                return
            }
            #expect(errorCode == .pairingRejected)
            await remote.remote.disconnect()
        }

        await #expect(throws: LANRemoteTestTimeoutError.self) {
            _ = try await waitFor(listener.pairingEvents, timeout: .milliseconds(300)) { if case .codeRotated = $0 { true } else { false } }
        }
        #expect(await listener.activePairingCode == code)

        await listener.stop()
    }

    // MARK: - No ceremony running at all

    @Test("A proof sent without ever calling beginPairing() is rejected; the connection survives")
    func noCeremonyRunningRejectsProof() async throws {
        let (listener, identity) = try await makeListener()
        defer { identity.removeFromKeychain() }
        guard let port = await listener.boundPort else {
            Issue.record("listener never bound a port")
            return
        }

        // Deliberately never call `beginPairing()`.
        let remote = PairingTestRemote()
        let nonce = try await remote.connectAndAwaitChallenge(to: hostPortEndpoint(port: port), expectedFingerprint: identity.fingerprint)
        try await remote.sendPairConfirm(code: "000000", fingerprintHex: identity.fingerprint, nonce: nonce)

        let reply = try await waitFor(remote.remote.incomingMessages) { if case .error = $0.message { true } else { false } }
        guard case .error(let errorCode, _) = reply.message else {
            Issue.record("expected .error")
            return
        }
        #expect(errorCode == .pairingRejected)
        #expect(await remote.remote.phase.isConnected)

        await remote.remote.disconnect()
        await listener.stop()
    }

    // MARK: - Paired-connection injection guard (the handlePaired reject-list)

    @Test("A PAIRED connection sending .pairConfirm gets .error(.malformedRequest); no ControlEvent is ever yielded")
    func pairedConnectionInjectingPairConfirmIsRejected() async throws {
        let authority = InMemoryLANRemotePairingAuthority()
        let (listener, identity) = try await makeListener(pairingAuthority: authority)
        defer { identity.removeFromKeychain() }
        guard let port = await listener.boundPort else {
            Issue.record("listener never bound a port")
            return
        }

        let token = "pretrusted-token-\(UUID().uuidString)"
        await authority.registerPairedToken(token)
        let remote = RemoteSessionActor(configuration: .init(kind: .phone))
        try await remote.connect(to: hostPortEndpoint(port: port), expectedFingerprint: identity.fingerprint, token: token)
        _ = try await waitFor(remote.phaseUpdates) { if case .controlling = $0 { true } else { false } }

        // A PAIRED remote sending `.pairConfirm` directly — never reachable
        // through a legitimate client (PR-7 only sends it pre-pairing) —
        // is exactly the injection attempt `handlePaired`'s reject-list
        // guards against.
        try await remote.send(.pairConfirm(proof: "irrelevant", deviceName: nil))
        let reply = try await waitFor(remote.incomingMessages) { if case .error = $0.message { true } else { false } }
        guard case .error(let errorCode, _) = reply.message else {
            Issue.record("expected .error")
            return
        }
        #expect(errorCode == .malformedRequest)

        // No `ControlEvent` ever surfaces — asserted via a short-timeout
        // expectation that `controlEvents` stays silent.
        await #expect(throws: LANRemoteTestTimeoutError.self) {
            _ = try await waitFor(listener.controlEvents, timeout: .milliseconds(300)) { _ in true }
        }

        await remote.disconnect()
        await listener.stop()
    }
}

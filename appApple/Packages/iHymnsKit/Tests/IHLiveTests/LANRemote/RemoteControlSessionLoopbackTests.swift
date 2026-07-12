// RemoteControlSessionLoopbackTests.swift
// IHLiveTests
//
// ELI5: The REAL end-to-end proof that the remote's whole supervisor works —
// a real `RemoteControlSession` talking to a real `TVListenerActor` over
// `127.0.0.1` TLS: scanning a code (or typing one), getting a wrong code
// rejected, reconnecting instantly with a saved token, and — the headline
// test — actually surviving the TV going away and coming back, entirely on
// its own.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §7.5 — BINDING).
// Verbatim posture from `TVListenerPairingLoopbackTests.swift:62-68`:
// opt-in behind `IHYMNS_LAN_LOOPBACK_TESTS=1` (macOS Local Network Privacy
// blocks an unsigned headless `swift test` binary from completing a REAL
// `127.0.0.1` handshake), `advertiseViaBonjour: false`, connect by explicit
// `.hostPort(...)`, `ManualLANRemoteClock` injected into the LISTENER's
// `Configuration`. Every session here uses MILLISECOND health/backoff
// configuration (`health.pingInterval = .milliseconds(40)`, `backoff =
// .init(baseDelay: 0.05, maxDelay: 0.2)`, spec §7.5) so the whole ladder —
// including the headline restart test — runs in real wall-clock
// milliseconds; the ALWAYS-ON suites (`RemoteLinkPolicyTests`) own the
// production numeric defaults.
//
// **Every test registers its session/listener teardown via `defer` the
// INSTANT each is created — never at the bottom of the function.** A session
// left running (its ping ticker firing every 40 ms, its reconnect ladder
// retrying) after a test exits EARLY (a failed `guard`/`Issue.record`) is not
// just an untidy leak: it keeps consuming Swift's cooperative-thread-pool
// slots for the rest of the run, and this suite's own `.serialized` trait
// (below) means every later test WAITS its turn behind whatever the leaked
// task's executor queue is doing — empirically, one early-return test
// without a `defer` was enough to make the NEXT queued test hang indefinitely
// rather than merely flake. `Task { await session.stop() }` (not a bare
// `await` — `defer` bodies are synchronous) mirrors the exact cleanup idiom
// `KeychainPairedTVStoreTests.swift`/`KeychainPairingAuthorityTests.swift`
// already use for the identical reason.
import Foundation
import IHModels
import Network
import Testing
@testable import IHLive

@Suite(
    "RemoteControlSession — TLS loopback integration",
    .enabled(
        if: ProcessInfo.processInfo.environment["IHYMNS_LAN_LOOPBACK_TESTS"] == "1",
        "Live 127.0.0.1 TLS loopback needs macOS Local Network permission an unsigned headless `swift test` binary can't obtain — set IHYMNS_LAN_LOOPBACK_TESTS=1 on a Mac where iHymns has that grant. See TVListenerPairingLoopbackTests.swift's header comment."
    )
)
struct RemoteControlSessionLoopbackTests {

    /// A session configured for millisecond-fast ping/backoff — spec §7.5.
    /// `internal` (not `private`) so `RemoteControlSessionReArmTests.swift`
    /// — split out purely to keep this struct's `type_body_length` under
    /// SwiftLint's budget (#1422 review fix 3) — can reuse it; it stays
    /// invisible outside this test target either way.
    func makeSession() -> RemoteControlSession {
        var configuration = RemoteControlSession.Configuration(remoteKind: .phone, deviceName: "Test Phone")
        configuration.health = .init(pingInterval: .milliseconds(40), maxMissedPongs: 3)
        configuration.backoff = .init(baseDelay: 0.05, multiplier: 2, maxDelay: 0.2, jitterFraction: 0.2)
        // #1424 Opus-review fix M2: the production default (10 s) made a
        // TOFU connect to a closed loopback port (`ManualConnectLoopbackTests
        // .nothingListeningYieldsExactlyOnePairingEnded`) sit the FULL 10 s
        // before failing — reused by every session this helper builds
        // (`ManualConnectLoopbackTests` composes THIS suite's `helper`), so
        // it was paid on every loopback run, not just that one test.
        // EMPIRICALLY TUNED, not guessed: `.seconds(3)` passed every
        // `ManualConnectLoopbackTests` test running ALONE, but under the
        // concurrent CPU/Keychain contention of the mandated combined
        // 4-suite run (`ManualConnectLoopback|LANConnectivityProbeLoopback|
        // RemoteControlSessionLoopback|TVListenerPairingLoopback`), several
        // genuinely-succeeding TOFU handshakes took longer than 3 s and were
        // wrongly timed out — see this PR's verification log. `.seconds(8)`
        // reliably passed the combined run twice in a row while still
        // resolving the closed-port case noticeably faster than the 10 s
        // production default.
        configuration.tofuConnectTimeout = .seconds(8)
        return RemoteControlSession(configuration: configuration)
    }

    /// `internal` for the same cross-file-reuse reason as `makeSession()` above.
    func makeListener(
        port: NWEndpoint.Port = .any,
        pairingAuthority: any LANRemotePairingAuthority = InMemoryLANRemotePairingAuthority(),
        clock: any LANRemoteClock = ManualLANRemoteClock()
    ) async throws -> (TVListenerActor, LANRemoteIdentity) {
        let identity = try await KeychainTestSerialization.shared.run {
            try LANRemoteIdentityFactory.generateSelfSigned()
        }
        let config = TVListenerActor.Configuration(
            port: port, advertiseViaBonjour: false, pairingAuthority: pairingAuthority, clock: clock
        )
        let listener = TVListenerActor(identity: identity, configuration: config)
        try await listener.start()
        try await listener.waitUntilReady()
        return (listener, identity)
    }

    /// `internal` for the same cross-file-reuse reason as `makeSession()` above.
    func target(
        tvName: String = "Fellowship Hall TV", port: NWEndpoint.Port, fingerprint: String,
        token: String? = nil, knownCode: String? = nil
    ) -> RemoteControlSession.Target {
        .init(
            tvName: tvName, endpoint: .hostPort(host: "127.0.0.1", port: port),
            expectedFingerprint: fingerprint, token: token, knownCode: knownCode
        )
    }

    // MARK: - Ceremony, path A (known code auto-answered)

    @Test("Path A: attach with a knownCode pairs automatically — .paired then .controlling, resolved address populated")
    func ceremonyPathAAutoAnswers() async throws {
        let (listener, identity) = try await makeListener()
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }

        let code = await listener.beginPairing()
        let session = makeSession()
        defer { Task { await session.stop() } }
        var events = session.events.makeAsyncIterator()

        await session.attach(to: target(port: port, fingerprint: identity.fingerprint, knownCode: code))

        let connecting = await events.next()
        #expect(connecting == .connecting(attempt: 0))

        guard case .paired(let token, let resolved) = await events.next() else {
            Issue.record("expected .paired")
            return
        }
        #expect(!token.isEmpty)
        #expect(resolved?.host == "127.0.0.1")
        #expect(resolved?.port == port.rawValue)

        guard case .controlling = await events.next() else {
            Issue.record("expected .controlling")
            return
        }
    }

    // MARK: - Ceremony, path B (typed code, wrong-then-right)

    @Test("Path B: no knownCode prompts awaitingCodeEntry; a wrong code re-prompts (connection survives); the right code pairs")
    func ceremonyPathBTypedCode() async throws {
        let (listener, identity) = try await makeListener()
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }

        let code = await listener.beginPairing()
        let session = makeSession()
        defer { Task { await session.stop() } }
        var events = session.events.makeAsyncIterator()

        await session.attach(to: target(port: port, fingerprint: identity.fingerprint))
        _ = await events.next() // .connecting(0)

        guard case .awaitingCodeEntry(let firstFailedAttempts) = await events.next() else {
            Issue.record("expected .awaitingCodeEntry")
            return
        }
        #expect(firstFailedAttempts == 0)

        await session.submitPairingCode("000000") // deliberately wrong (unless it happens to match)
        guard case .awaitingCodeEntry(let secondFailedAttempts) = await events.next() else {
            Issue.record("expected a re-prompt after a wrong code")
            return
        }
        #expect(secondFailedAttempts == 1)

        await session.submitPairingCode(code)
        guard case .paired = await events.next() else {
            Issue.record("expected .paired after the correct code")
            return
        }
        guard case .controlling = await events.next() else {
            Issue.record("expected .controlling")
            return
        }
    }

    @Test("4 wrong codes on one connection exceed the TV's 3-attempt cap and tear it down — pairingEnded(.connectionTornDown)")
    func fourWrongCodesTearDownConnection() async throws {
        let (listener, identity) = try await makeListener()
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }

        _ = await listener.beginPairing()
        let session = makeSession()
        defer { Task { await session.stop() } }
        var events = session.events.makeAsyncIterator()

        await session.attach(to: target(port: port, fingerprint: identity.fingerprint))
        _ = await events.next() // .connecting(0)
        _ = await events.next() // .awaitingCodeEntry(0)

        // Attempts 1-3 are each rejected but stay within the TV's
        // `maxAttemptsPerConnection` cap (default 3) — the connection
        // survives and re-prompts every time (`TVListenerPairingLoopbackTests
        // .wrongProofRejectedThenCapTearsDown`'s identical precedent).
        await session.submitPairingCode("111111")
        _ = await events.next() // .awaitingCodeEntry(1)
        await session.submitPairingCode("222222")
        _ = await events.next() // .awaitingCodeEntry(2)
        await session.submitPairingCode("333333")
        _ = await events.next() // .awaitingCodeEntry(3)

        // The 4th attempt EXCEEDS the cap outright — the TV still replies
        // `.error(.pairingRejected)` (one more `.awaitingCodeEntry`) BEFORE
        // it tears the connection down (`TVListenerActor+Pairing.swift`'s
        // `handlePairConfirm`: the cap-exceeded branch sends the error THEN
        // calls `teardownConnection`), so the drop itself is the event
        // AFTER that.
        await session.submitPairingCode("444444")
        _ = await events.next() // .awaitingCodeEntry(4) — the TV's own reply, right before it tears down

        guard case .pairingEnded(let reason) = await events.next() else {
            Issue.record("expected .pairingEnded after the TV's own attempt cap teardown")
            return
        }
        #expect(reason == .connectionTornDown)
    }

    // MARK: - Reconnect fast path (an already-paired target)

    @Test("A target with a pre-registered token reconnects straight to .controlling — no awaitingCodeEntry")
    func fastPathReconnectSkipsCeremony() async throws {
        let authority = InMemoryLANRemotePairingAuthority()
        let token = "pretrusted-token-\(UUID().uuidString)"
        await authority.registerPairedToken(token)
        let (listener, identity) = try await makeListener(pairingAuthority: authority)
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }

        let session = makeSession()
        defer { Task { await session.stop() } }
        var events = session.events.makeAsyncIterator()
        await session.attach(to: target(port: port, fingerprint: identity.fingerprint, token: token))

        #expect(await events.next() == .connecting(attempt: 0))
        guard case .controlling = await events.next() else {
            Issue.record("expected .controlling with no ceremony events at all")
            return
        }
    }

    // MARK: - Suspend / resume

    @Test("setSuspended(true) yields .suspended; setSuspended(false) reconnects straight to .controlling")
    func suspendThenResume() async throws {
        let authority = InMemoryLANRemotePairingAuthority()
        let token = "pretrusted-token-\(UUID().uuidString)"
        await authority.registerPairedToken(token)
        let (listener, identity) = try await makeListener(pairingAuthority: authority)
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }

        let session = makeSession()
        defer { Task { await session.stop() } }
        var events = session.events.makeAsyncIterator()
        await session.attach(to: target(port: port, fingerprint: identity.fingerprint, token: token))
        _ = await events.next() // .connecting
        _ = await events.next() // .controlling

        await session.setSuspended(true)
        #expect(await events.next() == .suspended)

        await session.setSuspended(false)
        #expect(await events.next() == .connecting(attempt: 0))
        guard case .controlling = await events.next() else {
            Issue.record("expected .controlling on resume")
            return
        }
    }

    // MARK: - THE HEADLINE TEST: kill the listener -> detach -> backoff -> restart -> re-attach

    @Test("Killing the listener detaches the session, which backs off and re-attaches once a listener returns on the same port")
    func killListenerThenRestartReattaches() async throws {
        let fixedPort = try #require(NWEndpoint.Port(rawValue: UInt16.random(in: 20_000...40_000)))
        let authority = InMemoryLANRemotePairingAuthority()
        let token = "pretrusted-token-\(UUID().uuidString)"
        await authority.registerPairedToken(token)

        let identity = try await KeychainTestSerialization.shared.run {
            try LANRemoteIdentityFactory.generateSelfSigned()
        }
        defer { identity.removeFromKeychain() }

        var listener = TVListenerActor(
            identity: identity,
            configuration: .init(port: fixedPort, advertiseViaBonjour: false, pairingAuthority: authority, clock: ManualLANRemoteClock())
        )
        try await listener.start()
        try await listener.waitUntilReady()
        defer { let finalListener = listener; Task { await finalListener.stop() } }

        let session = makeSession()
        defer { Task { await session.stop() } }
        var events = session.events.makeAsyncIterator()
        await session.attach(to: target(port: fixedPort, fingerprint: identity.fingerprint, token: token))
        _ = await events.next() // .connecting
        _ = await events.next() // .controlling

        // Kill the listener out from under the session — a hard drop.
        await listener.stop()

        #expect(await events.next() == .detached)
        guard case .reconnecting(let firstAttempt, let firstDelay) = await events.next() else {
            Issue.record("expected .reconnecting")
            return
        }
        #expect(firstAttempt == 0)
        #expect(firstDelay > 0)

        // Bring a NEW listener up on the SAME fixed port, same identity, an
        // authority that already knows the token — the fast path should
        // work once the session's ladder tries again.
        listener = TVListenerActor(
            identity: identity,
            configuration: .init(port: fixedPort, advertiseViaBonjour: false, pairingAuthority: authority, clock: ManualLANRemoteClock())
        )
        try await listener.start()
        try await listener.waitUntilReady()

        // Drain any further `.reconnecting` events (the ladder may need more
        // than one attempt if the new listener wasn't up in time for the
        // first retry) until `.controlling` arrives — bounded by a generous
        // real-world timeout since this crosses real async network I/O.
        let reconnected = try await waitForControlling(&events)
        guard case .controlling = reconnected else {
            Issue.record("expected the session to reach .controlling again with NO ceremony events")
            return
        }
    }

    /// Consumes `events` until `.controlling` arrives (skipping any further
    /// `.connecting`/`.reconnecting` attempts along the way), or throws after
    /// a generous real-world deadline — this crosses genuine async network
    /// I/O (a fresh listener actually binding + a fresh TLS handshake), which
    /// is exactly the class of wait `LANRemoteTestSupport.swift`'s own
    /// `waitFor`/`waitForCondition` document as legitimate to time-box.
    /// A plain deadline loop (not a `TaskGroup` race) — this session's
    /// `events` is a SINGLE-CONSUMER stream (this file's binding rule), and
    /// the ladder's own `.reconnecting` events fire every ≤0.2s in this
    /// suite's millisecond configuration, so the deadline check between
    /// iterations is a real bound in practice without needing a second,
    /// concurrent reader of the same iterator.
    private func waitForControlling(_ events: inout AsyncStream<RemoteControlSession.Event>.AsyncIterator) async throws -> RemoteControlSession.Event {
        let deadline = ContinuousClock.now + .seconds(10)
        while ContinuousClock.now < deadline {
            guard let event = await events.next() else { throw LANRemoteTestTimeoutError() }
            if case .controlling = event { return event }
        }
        throw LANRemoteTestTimeoutError()
    }

    // MARK: - Multi-remote reflection

    @Test("Two sessions paired to one listener both see the SAME broadcast state when either one sends an intent")
    func multiRemoteReflection() async throws {
        let authority = InMemoryLANRemotePairingAuthority()
        let tokenA = "token-a-\(UUID().uuidString)"
        let tokenB = "token-b-\(UUID().uuidString)"
        await authority.registerPairedToken(tokenA)
        await authority.registerPairedToken(tokenB)
        let (listener, identity) = try await makeListener(pairingAuthority: authority)
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }

        let sessionA = makeSession()
        let sessionB = makeSession()
        defer { Task { await sessionA.stop() }; Task { await sessionB.stop() } }
        var eventsA = sessionA.events.makeAsyncIterator()
        var eventsB = sessionB.events.makeAsyncIterator()

        await sessionA.attach(to: target(port: port, fingerprint: identity.fingerprint, token: tokenA))
        await sessionB.attach(to: target(port: port, fingerprint: identity.fingerprint, token: tokenB))

        _ = await eventsA.next() // .connecting
        guard case .controlling = await eventsA.next() else { Issue.record("A never controlled"); return }
        _ = await eventsB.next() // .connecting
        guard case .controlling = await eventsB.next() else { Issue.record("B never controlled"); return }

        let songId = SongID(songbookAbbreviation: "MP", number: 1008)
        await sessionA.sendIntent(.selectSong(songId: songId, componentIndex: 0, lineIndex: nil))
        // Simulate the projection layer resolving the intent and broadcasting.
        await listener.updateState(songId: songId, componentIndex: 0, lineIndex: nil, displayState: .lyrics)

        guard case .controlling(let stateA) = await eventsA.next() else { Issue.record("A never saw the broadcast"); return }
        guard case .controlling(let stateB) = await eventsB.next() else { Issue.record("B never saw the broadcast"); return }
        #expect(stateA.songId == songId)
        #expect(stateB.songId == songId)
        #expect(stateA.revision == stateB.revision)
    }
}

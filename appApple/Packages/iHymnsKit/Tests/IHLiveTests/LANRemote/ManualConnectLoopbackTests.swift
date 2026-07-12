// ManualConnectLoopbackTests.swift
// IHLiveTests
//
// ELI5: The REAL end-to-end proof that "type in a TV's address" actually
// works — observes a real certificate, shows the fingerprint, waits for a
// human "yes that's mine," runs the ordinary code ceremony, and pairs —
// plus the negative case that matters most: once paired, a LATER
// reconnect NEVER trusts a different TV at the same address, even if that
// TV's saved token would otherwise be accepted.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §7.3 — BINDING).
// Verbatim posture from `RemoteControlSessionLoopbackTests.swift`: opt-in
// behind `IHYMNS_LAN_LOOPBACK_TESTS=1`, millisecond health/backoff
// configuration, `defer`-registered teardown the INSTANT each
// session/listener is created. Reuses that file's `internal`
// `makeSession()`/`makeListener(...)`/`target(...)` helpers rather than
// re-deriving the recipe (this repo's modularity rule). `.serialized`
// (unlike that file): this suite's tests each spin up 1-2 REAL TLS
// listeners + a real Keychain identity, and empirically running them
// alongside the OTHER loopback suites' own concurrent tests (the full
// combined `--filter` this PR's verification runs) surfaced spurious
// millisecond-timing flakiness under heavy simultaneous load — serializing
// just THIS suite's own tests removes that self-inflicted contention
// without slowing the other suites down.
import Foundation
import Network
import Testing
@testable import IHLive

@Suite(
    "RemoteControlSession — manual connect-by-address (TOFU) TLS loopback integration",
    .serialized,
    .enabled(
        if: ProcessInfo.processInfo.environment["IHYMNS_LAN_LOOPBACK_TESTS"] == "1",
        "Live 127.0.0.1 TLS loopback needs macOS Local Network permission an unsigned headless `swift test` binary can't obtain — set IHYMNS_LAN_LOOPBACK_TESTS=1 on a Mac where iHymns has that grant. See TVListenerPairingLoopbackTests.swift's header comment."
    )
)
struct ManualConnectLoopbackTests {

    private let helper = RemoteControlSessionLoopbackTests()

    // MARK: - Headline: TOFU observe → confirm → code → paired → controlling, then the pinned forward reconnect

    @Test(
        "TOFU happy path: attachByAddress observes the real fingerprint, confirming it replays the raced challenge into the ordinary code ceremony, and pairs; a LATER attach with the pinned target+token reaches .controlling with no ceremony events"
    )
    func tofuHappyPathThenPinnedForwardReconnect() async throws {
        let (listener, identity) = try await helper.makeListener()
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }

        let code = await listener.beginPairing()
        let session = helper.makeSession()
        defer { Task { await session.stop() } }
        var events = session.events.makeAsyncIterator()

        await session.attachByAddress(host: "127.0.0.1", port: port.rawValue, displayName: "Typed TV")
        #expect(await events.next() == .connecting(attempt: 0))

        // The load-bearing equality: F_obs IS the listener's REAL
        // fingerprint — proves the verify block's accept-any-cert path
        // actually captured what the TV presented, not some placeholder.
        guard case .awaitingFingerprintConfirmation(let observedFingerprint) = await events.next() else {
            Issue.record("expected .awaitingFingerprintConfirmation")
            return
        }
        #expect(observedFingerprint == identity.fingerprint)

        // At loopback speed the TV's `.pairChallenge` ALWAYS beats the
        // human's confirm — this proves the challenge-stash replay
        // (spec §4.2's race) rather than the frame being silently dropped.
        await session.confirmObservedFingerprint()
        #expect(await events.next() == .awaitingCodeEntry(failedAttempts: 0))

        await session.submitPairingCode(code)
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

        // §4.4's forward half — "the NEXT app session reconnects via path C
        // with the saved address as its endpoint": a BRAND-NEW session (a
        // fresh app launch, never the SAME live one — reusing the live
        // session here would race its still-settling ping/health machinery
        // against a second `attach(to:)`, an orthogonal pre-existing
        // concern this test isn't about) attaches with the now-pinned
        // target and reaches `.controlling` with ZERO ceremony events — no
        // `.awaitingFingerprintConfirmation`, no `.awaitingCodeEntry`.
        let forwardSession = helper.makeSession()
        defer { Task { await forwardSession.stop() } }
        var forwardEvents = forwardSession.events.makeAsyncIterator()
        await forwardSession.attach(to: helper.target(port: port, fingerprint: identity.fingerprint, token: token))
        #expect(await forwardEvents.next() == .connecting(attempt: 0))
        guard case .controlling = await forwardEvents.next() else {
            Issue.record("expected .controlling with no ceremony events at all")
            return
        }
    }

    // MARK: - Wrong code, then the correct one

    @Test("A wrong code re-prompts (the connection survives); the correct code then pairs")
    func wrongCodeThenCorrectCodePairs() async throws {
        let (listener, identity) = try await helper.makeListener()
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }
        let code = await listener.beginPairing()

        let session = helper.makeSession()
        defer { Task { await session.stop() } }
        var events = session.events.makeAsyncIterator()

        await session.attachByAddress(host: "127.0.0.1", port: port.rawValue, displayName: "Typed TV")
        _ = await events.next() // .connecting(0)
        guard case .awaitingFingerprintConfirmation = await events.next() else {
            Issue.record("expected .awaitingFingerprintConfirmation")
            return
        }

        await session.confirmObservedFingerprint()
        #expect(await events.next() == .awaitingCodeEntry(failedAttempts: 0))

        await session.submitPairingCode("000000") // deliberately wrong (unless it happens to match)
        #expect(await events.next() == .awaitingCodeEntry(failedAttempts: 1))

        await session.submitPairingCode(code)
        guard case .paired = await events.next() else { Issue.record("expected .paired after the correct code"); return }
        guard case .controlling = await events.next() else { Issue.record("expected .controlling"); return }
    }

    // MARK: - Cancel at the interstitial

    @Test("Cancel at the fingerprint-confirm interstitial tears down cleanly and releases the listener's unpaired slot")
    func cancelAtInterstitialTearsDownCleanly() async throws {
        let (listener, identity) = try await helper.makeListener()
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }
        _ = await listener.beginPairing()

        let session = helper.makeSession()
        defer { Task { await session.stop() } }
        var events = session.events.makeAsyncIterator()

        await session.attachByAddress(host: "127.0.0.1", port: port.rawValue, displayName: "Typed TV")
        _ = await events.next() // .connecting(0)
        guard case .awaitingFingerprintConfirmation = await events.next() else {
            Issue.record("expected .awaitingFingerprintConfirmation")
            return
        }

        await session.cancelPairing()
        #expect(await events.next() == .pairingEnded(.cancelled))

        try await waitForCondition { await listener.unpairedConnectionCount == 0 }
    }

    // MARK: - Drop before confirm

    @Test("Dropping the listener while awaiting fingerprint confirmation tears down cleanly, and a fresh attachByAddress afterward still works")
    func dropBeforeConfirmSweepsStateCleanly() async throws {
        let (listener, identity) = try await helper.makeListener()
        defer { identity.removeFromKeychain() }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }
        _ = await listener.beginPairing()

        let session = helper.makeSession()
        defer { Task { await session.stop() } }
        var events = session.events.makeAsyncIterator()

        await session.attachByAddress(host: "127.0.0.1", port: port.rawValue, displayName: "Typed TV")
        _ = await events.next() // .connecting(0)
        guard case .awaitingFingerprintConfirmation = await events.next() else {
            Issue.record("expected .awaitingFingerprintConfirmation")
            return
        }

        await listener.stop()
        guard case .pairingEnded(let reason) = await events.next() else { Issue.record("expected .pairingEnded"); return }
        #expect(reason == .connectionTornDown)

        // A fresh manual connect to a DIFFERENT listener afterward must
        // fully succeed — proves no stale `manualConnect`/
        // `pendingChallengeNonce` survived the drop (spec §4.2).
        let (listener2, identity2) = try await helper.makeListener()
        defer { identity2.removeFromKeychain(); Task { await listener2.stop() } }
        guard let port2 = await listener2.boundPort else { Issue.record("no bound port"); return }
        let code2 = await listener2.beginPairing()

        await session.attachByAddress(host: "127.0.0.1", port: port2.rawValue, displayName: "Typed TV 2")
        #expect(await events.next() == .connecting(attempt: 0))
        guard case .awaitingFingerprintConfirmation(let fingerprint2) = await events.next() else {
            Issue.record("expected .awaitingFingerprintConfirmation")
            return
        }
        #expect(fingerprint2 == identity2.fingerprint)

        await session.confirmObservedFingerprint()
        guard case .awaitingCodeEntry = await events.next() else { Issue.record("expected .awaitingCodeEntry"); return }
        await session.submitPairingCode(code2)
        guard case .paired = await events.next() else { Issue.record("expected .paired"); return }
        guard case .controlling = await events.next() else { Issue.record("expected .controlling"); return }
    }

    // MARK: - Nothing listening

    @Test("attachByAddress to a closed port yields .connecting then exactly ONE .pairingEnded(.connectionTornDown) — never double-yielded")
    func nothingListeningYieldsExactlyOnePairingEnded() async throws {
        let session = helper.makeSession()
        defer { Task { await session.stop() } }
        var events = session.events.makeAsyncIterator()

        let closedPort = try #require(NWEndpoint.Port(rawValue: UInt16.random(in: 20_000...40_000)))
        await session.attachByAddress(host: "127.0.0.1", port: closedPort.rawValue, displayName: "Nothing Here")

        #expect(await events.next() == .connecting(attempt: 0))
        guard case .pairingEnded(let reason) = await events.next() else { Issue.record("expected .pairingEnded"); return }
        #expect(reason == .connectionTornDown)

        // Exactly once — the §4.2 no-double-yield rule under test: no
        // SECOND terminal (or any other) event follows within a short
        // bounded wait.
        await #expect(throws: LANRemoteTestTimeoutError.self) {
            _ = try await waitFor(session.events, timeout: .milliseconds(300)) { _ in true }
        }
    }

    // MARK: - ★ The pin holds — the anti-re-TOFU negative

    @Test(
        "The pin holds: after a TOFU pair, restarting a DIFFERENT identity on the SAME port (same authority, so the token IS still trusted) never re-TOFUs — the ladder never reaches .controlling nor .awaitingFingerprintConfirmation"
    )
    func pinHoldsAgainstIdentitySwap() async throws {
        let fixedPort = try #require(NWEndpoint.Port(rawValue: UInt16.random(in: 20_000...40_000)))
        let authority = InMemoryLANRemotePairingAuthority()

        let identityA = try await KeychainTestSerialization.shared.run { try LANRemoteIdentityFactory.generateSelfSigned() }
        defer { identityA.removeFromKeychain() }

        var listener = TVListenerActor(
            identity: identityA,
            configuration: .init(port: fixedPort, advertiseViaBonjour: false, pairingAuthority: authority, clock: ManualLANRemoteClock())
        )
        try await listener.start()
        try await listener.waitUntilReady()

        let code = await listener.beginPairing()
        let session = helper.makeSession()
        defer { Task { await session.stop() } }
        var events = session.events.makeAsyncIterator()

        await session.attachByAddress(host: "127.0.0.1", port: fixedPort.rawValue, displayName: "Typed TV")
        #expect(await events.next() == .connecting(attempt: 0))
        guard case .awaitingFingerprintConfirmation(let observedFingerprint) = await events.next() else {
            Issue.record("expected .awaitingFingerprintConfirmation")
            return
        }
        #expect(observedFingerprint == identityA.fingerprint)

        await session.confirmObservedFingerprint()
        #expect(await events.next() == .awaitingCodeEntry(failedAttempts: 0))
        await session.submitPairingCode(code)
        guard case .paired = await events.next() else { Issue.record("expected .paired"); return }
        guard case .controlling = await events.next() else { Issue.record("expected .controlling"); return }

        // Kill identity A's listener — the ladder detaches and starts
        // retrying against the now-PINNED target (identity A's fingerprint).
        await listener.stop()
        #expect(await events.next() == .detached)
        guard case .reconnecting = await events.next() else { Issue.record("expected .reconnecting"); return }

        // A NEW listener on the SAME port, a DIFFERENT identity, but the
        // SAME authority (which already holds the token from the ceremony
        // above) — if the pin didn't hold, the ladder's saved-token
        // `hello` would succeed at the APPLICATION layer against this
        // impostor. It must instead fail at the TLS layer every time.
        let identityB = try await KeychainTestSerialization.shared.run { try LANRemoteIdentityFactory.generateSelfSigned() }
        defer { identityB.removeFromKeychain() }
        listener = TVListenerActor(
            identity: identityB,
            configuration: .init(port: fixedPort, advertiseViaBonjour: false, pairingAuthority: authority, clock: ManualLANRemoteClock())
        )
        try await listener.start()
        try await listener.waitUntilReady()
        defer { let finalListener = listener; Task { await finalListener.stop() } }

        // See `assertNeverReachesControllingOrConfirmation`'s own doc
        // comment for why this is a genuine bounded race, not a deadline
        // loop, and for the pre-existing `RemoteSessionActor` finding it
        // exists to be robust against.
        await assertNeverReachesControllingOrConfirmation(events, timeout: .seconds(2))
    }

    // MARK: - Known-TV shortcut (session-level half — the coordinator's store lookup is covered by the pure resolver + UI-state tests)

    @Test("attach(to:) with a manualResolution(...).knownTV target reaches .controlling directly, with no ceremony events")
    func knownTVShortcutTargetReachesControllingDirectly() async throws {
        let authority = InMemoryLANRemotePairingAuthority()
        let token = "pretrusted-token-\(UUID().uuidString)"
        await authority.registerPairedToken(token)
        let (listener, identity) = try await helper.makeListener(pairingAuthority: authority)
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }

        let record = PairedTVRecord(fingerprintHex: identity.fingerprint, name: "Fellowship Hall TV", token: token, pairedAt: Date())
        let resolution = RemotePairingEntryResolver.manualResolution(
            observedFingerprint: identity.fingerprint, host: "127.0.0.1", port: port.rawValue,
            displayName: "127.0.0.1", saved: [record]
        )
        guard case .knownTV(let target) = resolution else { Issue.record("expected .knownTV"); return }

        let session = helper.makeSession()
        defer { Task { await session.stop() } }
        var events = session.events.makeAsyncIterator()
        await session.attach(to: target)

        #expect(await events.next() == .connecting(attempt: 0))
        guard case .controlling = await events.next() else {
            Issue.record("expected .controlling with no ceremony events at all")
            return
        }
    }
}

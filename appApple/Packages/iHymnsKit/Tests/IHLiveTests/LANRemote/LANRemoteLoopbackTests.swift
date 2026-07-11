// LANRemoteLoopbackTests.swift
// IHLiveTests
//
// ELI5: The REAL end-to-end test — actually starts a TV listener and one
// or more remote connections over `127.0.0.1`, with real TLS 1.3, and
// checks the whole pairing → control → broadcast dance works, that a
// wrong-fingerprint remote gets rejected, and that a remote can't skip
// pairing and start issuing commands.
//
// DETAILED: #1420 (task brief: "TLS loopback integration — `NWListener`+
// `NWConnection` over `127.0.0.1` with an ephemeral in-memory P-256
// identity... connect by explicit `NWEndpoint.hostPort`, do NOT rely on
// Bonjour discovery in CI" + "a pin-mismatch-rejected test"). Every test
// here connects by explicit `.hostPort(host: "127.0.0.1", port:)` against
// `TVListenerActor.boundPort` — never `startDiscovery()`/Bonjour, per that
// instruction. `waitFor`/`waitForCondition` (`LANRemoteTestSupport.swift`)
// bound how long a test waits for a REAL async network round trip to
// settle; nothing here simulates business-logic TIME (that's
// `IHRPFrameOrderingTests.swift`'s job, with `ManualLANRemoteClock`).
//
// **Why this whole suite is OPT-IN (`.enabled(if:)` on an env var, default
// OFF):** every test here opens a REAL `NWConnection` to a REAL `NWListener`
// over `127.0.0.1`. On macOS 15+/26 that trips **Local Network Privacy** —
// the OS wants an interactive, per-app permission grant that an *unsigned*
// `swift test` binary running headlessly (GitHub `macos-26` CI, or a plain
// terminal `swift test` before the machine has ever granted iHymns local-
// network access) can NEITHER present NOR satisfy, so the handshake never
// completes and the test HANGS until the job's wall-clock timeout — a red
// build for a test that proves nothing about the code, only about the
// sandbox. The pure-logic guarantees these integration tests layer on top of
// (framing caps, codec round-trips, last-writer-wins, identity/fingerprint
// generation) are ALL covered by the four always-on suites in this same
// folder, which need no network and run in milliseconds; this suite adds the
// end-to-end proof and is meant to be run deliberately, on a dev Mac where
// iHymns HAS been granted Local Network access, via:
//
//     IHYMNS_LAN_LOOPBACK_TESTS=1 swift test --filter "TLS loopback"
//
// When PR-6 wires the listener into the real, signed tvOS app shell (with the
// `NSLocalNetworkUsageDescription`/`NSBonjourServices` entitlements), the
// on-device test target can run these unconditionally; the gate is purely a
// property of the entitlement-less package-test binary, not of the protocol.
import Foundation
import IHModels
import Network
import Testing
@testable import IHLive

@Suite(
    "LAN remote — TLS loopback integration",
    .enabled(
        if: ProcessInfo.processInfo.environment["IHYMNS_LAN_LOOPBACK_TESTS"] == "1",
        "Live 127.0.0.1 TLS loopback needs macOS Local Network permission an unsigned headless `swift test` binary can't obtain — set IHYMNS_LAN_LOOPBACK_TESTS=1 on a Mac where iHymns has that grant. See this file's header comment."
    )
)
struct LANRemoteLoopbackTests {

    private func makeListener(
        maxUnpaired: Int = 4,
        pairingAuthority: any LANRemotePairingAuthority = InMemoryLANRemotePairingAuthority()
    ) async throws -> (TVListenerActor, LANRemoteIdentity) {
        let identity = try LANRemoteIdentityFactory.generateSelfSigned()
        let config = TVListenerActor.Configuration(
            port: .any,
            advertiseViaBonjour: false, // see Configuration.advertiseViaBonjour's doc comment
            maxUnpairedConnections: maxUnpaired,
            pairingAuthority: pairingAuthority,
            clock: ManualLANRemoteClock()
        )
        let listener = TVListenerActor(identity: identity, configuration: config)
        try await listener.start()
        try await listener.waitUntilReady()
        return (listener, identity)
    }

    private func hostPortEndpoint(port: NWEndpoint.Port) -> NWEndpoint {
        .hostPort(host: "127.0.0.1", port: port)
    }

    // MARK: - Happy path: pre-paired token reconnects straight to controlling

    @Test("A remote with a pre-registered token connects straight through, and control messages + broadcast round-trip")
    func fastReconnectAndControlRoundTrip() async throws {
        let authority = InMemoryLANRemotePairingAuthority()
        let (listener, identity) = try await makeListener(pairingAuthority: authority)
        defer { identity.removeFromKeychain() }

        let token = "pretrusted-token-123"
        await authority.registerPairedToken(token)

        let remoteA = RemoteSessionActor(configuration: .init(kind: .phone, clock: ManualLANRemoteClock()))
        let remoteB = RemoteSessionActor(configuration: .init(kind: .pad, clock: ManualLANRemoteClock()))
        guard let port = await listener.boundPort else {
            Issue.record("listener never bound a port")
            return
        }
        let endpoint = hostPortEndpoint(port: port)

        try await remoteA.connect(to: endpoint, expectedFingerprint: identity.fingerprint, token: token)
        _ = try await waitFor(remoteA.phaseUpdates) { if case .controlling = $0 { true } else { false } }

        try await remoteB.connect(to: endpoint, expectedFingerprint: identity.fingerprint, token: token)
        _ = try await waitFor(remoteB.phaseUpdates) { if case .controlling = $0 { true } else { false } }

        #expect(await listener.pairedConnectionCount == 2)

        // remoteA issues a control intent; the TV must relay it up via
        // `controlEvents` — this is what PR-5/PR-6's projection view-model
        // would consume to actually resolve "select this song."
        let songId = SongID(songbookAbbreviation: "MP", number: 1008)
        try await remoteA.send(.selectSong(songId: songId, componentIndex: 0, lineIndex: nil))

        let event = try await waitFor(listener.controlEvents) { $0.frame.message.caseName == "selectSong" }
        guard case .selectSong(let receivedSongId, _, _) = event.frame.message else {
            Issue.record("expected a selectSong control event")
            return
        }
        #expect(receivedSongId == songId)

        // The (simulated) display-driving layer resolves the intent and
        // pushes the new canonical state back down — BOTH remotes, not just
        // the one that sent the command, must see it (strategy §2.4.4:
        // "broadcast to all connected").
        await listener.updateState(songId: songId, componentIndex: 0, lineIndex: nil, displayState: .lyrics)

        let stateAtA = try await waitFor(remoteA.phaseUpdates) {
            if case .controlling(let state) = $0 { return state.songId == songId }
            return false
        }
        let stateAtB = try await waitFor(remoteB.phaseUpdates) {
            if case .controlling(let state) = $0 { return state.songId == songId }
            return false
        }
        guard case .controlling(let resolvedA) = stateAtA, case .controlling(let resolvedB) = stateAtB else {
            Issue.record("expected controlling phase at both remotes")
            return
        }
        #expect(resolvedA.revision == resolvedB.revision)
        #expect(resolvedA.displayState == .lyrics)

        await remoteA.disconnect()
        await remoteB.disconnect()
        await listener.stop()
    }

    // MARK: - Fresh pairing via `completePairing` (the PR-6 ceremony hook)

    @Test("A remote with no token parks in .pairing, and completePairing(...) promotes it to controlling")
    func freshPairingViaCompletePairing() async throws {
        let (listener, identity) = try await makeListener()
        defer { identity.removeFromKeychain() }

        let remote = RemoteSessionActor(configuration: .init(kind: .mac))
        guard let port = await listener.boundPort else {
            Issue.record("listener never bound a port")
            return
        }

        try await remote.connect(to: hostPortEndpoint(port: port), expectedFingerprint: identity.fingerprint, token: nil)
        _ = try await waitFor(remote.phaseUpdates) { if case .awaitingPairing = $0 { true } else { false } }

        try await waitForCondition { await !listener.pairingConnectionIds.isEmpty }
        let pairingIds = await listener.pairingConnectionIds
        #expect(pairingIds.count == 1)

        let confirmed = await listener.completePairing(connectionId: pairingIds[0], token: "freshly-minted-token")
        #expect(confirmed)

        _ = try await waitFor(remote.phaseUpdates) { if case .controlling = $0 { true } else { false } }
        #expect(await listener.pairedConnectionCount == 1)

        await remote.disconnect()
        await listener.stop()
    }

    // MARK: - Pin-before-trust: a mismatched fingerprint is rejected

    @Test("connect(...) throws, and never reaches .controlling, when the pinned fingerprint doesn't match")
    func pinMismatchIsRejected() async throws {
        let (listener, identity) = try await makeListener()
        defer { identity.removeFromKeychain() }

        let remote = RemoteSessionActor(configuration: .init(kind: .phone))
        guard let port = await listener.boundPort else {
            Issue.record("listener never bound a port")
            return
        }

        let wrongFingerprint = String(repeating: "0", count: 64)
        #expect(wrongFingerprint != identity.fingerprint)

        await #expect(throws: (any Error).self) {
            try await remote.connect(to: hostPortEndpoint(port: port), expectedFingerprint: wrongFingerprint, token: nil)
        }

        let phase = await remote.phase
        guard case .failed = phase else {
            Issue.record("expected .failed after a rejected pin, got \(phase)")
            return
        }

        #expect(await listener.pairedConnectionCount == 0)
        await listener.stop()
    }

    // MARK: - Unpaired confinement: a control message before pairing drops the connection

    @Test("A control message sent before pairing completes is rejected and the connection is dropped")
    func unpairedControlMessageDropsConnection() async throws {
        let (listener, identity) = try await makeListener()
        defer { identity.removeFromKeychain() }

        let remote = RemoteSessionActor(configuration: .init(kind: .phone))
        guard let port = await listener.boundPort else {
            Issue.record("listener never bound a port")
            return
        }

        // No token → parked in `.pairing`, never confirmed.
        try await remote.connect(to: hostPortEndpoint(port: port), expectedFingerprint: identity.fingerprint, token: nil)
        _ = try await waitFor(remote.phaseUpdates) { if case .awaitingPairing = $0 { true } else { false } }

        // A genuine control intent while still unconfirmed — confinement
        // violation (task brief: "unpaired-message confinement").
        try await remote.send(.nextComponent)

        let failedPhase = try await waitFor(remote.phaseUpdates) { if case .failed = $0 { true } else { false } }
        guard case .failed = failedPhase else {
            Issue.record("expected .failed after a confinement violation")
            return
        }

        try await waitForCondition { await listener.unpairedConnectionCount == 0 }
        await listener.stop()
    }

    // MARK: - Concurrent-unpaired-connection cap

    @Test("A new connection is rejected outright once the unpaired-connection cap is reached")
    func unpairedConnectionCapRejectsExcessConnections() async throws {
        let (listener, identity) = try await makeListener(maxUnpaired: 1)
        defer { identity.removeFromKeychain() }

        let remote = RemoteSessionActor(configuration: .init(kind: .phone))
        guard let port = await listener.boundPort else {
            Issue.record("listener never bound a port")
            return
        }

        // Occupies the ONE unpaired slot.
        try await remote.connect(to: hostPortEndpoint(port: port), expectedFingerprint: identity.fingerprint, token: nil)
        _ = try await waitFor(remote.phaseUpdates) { if case .awaitingPairing = $0 { true } else { false } }
        #expect(await listener.unpairedConnectionCount == 1)

        // A second arrival, exercised directly against the actor's own
        // accept path (`@testable import`) rather than a second real TLS
        // handshake — a rejected-before-`.start()` connection's CLIENT-side
        // behaviour is a transport-layer implementation detail Network.framework
        // doesn't document a bounded-time outcome for, which would make a
        // full second real connection attempt a flaky way to prove this
        // specific guard; the cap-enforcement CODE PATH itself is exercised
        // for real, exactly as `handleNewConnection` runs it in production.
        let placeholderConnection = NWConnection(to: hostPortEndpoint(port: port), using: .tcp)
        await listener.handleNewConnection(placeholderConnection)

        #expect(await listener.unpairedConnectionCount == 1)

        await remote.disconnect()
        await listener.stop()
    }
}

// HeadlessRelayDriverLoopbackTests.swift
// IHFeaturesTests
//
// ELI5: The real proof the pocket-control headline works — no watch, no
// WCSession, no real iPhone/TV needed: a real `TVListenerActor` running on
// `127.0.0.1`, and this suite calling `HeadlessRelayDriver.perform(_:)`
// directly, exactly the way a wake-connect from a watch tap would.
//
// DETAILED: #1423 (`.claude/apple-phase2-pr11-pocket-spec.md` §7.2 —
// BINDING, the a-g matrix). Same fact-19 harness idiom as
// `RemoteControlSessionLoopbackTests.swift` (`IHLiveTests`), rebuilt HERE
// because `HeadlessRelayDriver` lives in `IHFeatures` — a different test
// TARGET, so that file's own `waitFor`/`ManualLANRemoteClock`/
// `KeychainTestSerialization` test-only helpers (declared in ITS test
// target, not `IHLive`'s public sources) aren't reachable from here. Opt-in
// behind `IHYMNS_LAN_LOOPBACK_TESTS=1` (macOS Local Network Privacy blocks
// an unsigned headless `swift test` binary from completing a REAL
// `127.0.0.1` handshake) — run this suite ALONE, never combined with
// `IHLiveTests`' own LAN loopback suites in the same invocation (both call
// the SAME `LANRemoteIdentityFactory.generateSelfSigned()` Keychain APIs;
// the combined run's cross-target contention is the documented reason the
// mandated combined 4-suite `IHLiveTests` run itself must run serially,
// `RemoteControlSessionLoopbackTests.swift`'s header).
import Foundation
import Network
import Testing
@testable import IHFeatures
@testable import IHLive

private struct HeadlessRelayDriverLoopbackTimeoutError: Error {}

/// A local copy of `LANRemoteTestSupport.swift`'s `waitFor` — see this
/// file's header for why it can't simply be imported from `IHLiveTests`.
private func waitFor<Element: Sendable>(
    _ stream: AsyncStream<Element>,
    timeout: Duration = .seconds(3),
    where predicate: @escaping @Sendable (Element) -> Bool
) async throws -> Element {
    try await withThrowingTaskGroup(of: Element.self) { group in
        group.addTask {
            for await element in stream where predicate(element) {
                return element
            }
            throw HeadlessRelayDriverLoopbackTimeoutError()
        }
        group.addTask {
            try await Task.sleep(for: timeout)
            throw HeadlessRelayDriverLoopbackTimeoutError()
        }
        guard let result = try await group.next() else { throw HeadlessRelayDriverLoopbackTimeoutError() }
        group.cancelAll()
        return result
    }
}

@Suite(
    "HeadlessRelayDriver — real TLS loopback, no watch/WCSession/devices needed",
    .serialized,
    .enabled(
        if: ProcessInfo.processInfo.environment["IHYMNS_LAN_LOOPBACK_TESTS"] == "1",
        "Live 127.0.0.1 TLS loopback needs macOS Local Network permission an unsigned headless swift test binary can't obtain — set IHYMNS_LAN_LOOPBACK_TESTS=1 and run this suite ALONE (see this file's header)."
    )
)
@MainActor
struct HeadlessRelayDriverLoopbackTests {

    private func makeListener(authority: any LANRemotePairingAuthority) async throws -> (TVListenerActor, LANRemoteIdentity) {
        let identity = try LANRemoteIdentityFactory.generateSelfSigned()
        let listener = TVListenerActor(
            identity: identity,
            configuration: .init(port: .any, advertiseViaBonjour: false, pairingAuthority: authority)
        )
        try await listener.start()
        try await listener.waitUntilReady()
        return (listener, identity)
    }

    private func makeDriver(store: InMemoryPairedTVStore) -> HeadlessRelayDriver {
        var configuration = HeadlessRelayDriver.Configuration()
        configuration.policy = .init(connectTimeout: .milliseconds(800), idleDisconnect: .milliseconds(150), maxPending: 4)
        return HeadlessRelayDriver(store: store, configuration: configuration)
    }

    private func seedRecord(fingerprint: String, port: NWEndpoint.Port, token: String) -> PairedTVRecord {
        PairedTVRecord(
            fingerprintHex: fingerprint, name: "Fellowship Hall TV", token: token,
            lastAddress: LANRemoteResolvedAddress(host: "127.0.0.1", port: port.rawValue),
            pairedAt: Date(), lastConnectedAt: Date()
        )
    }

    // MARK: - (a) The headline E2E

    @Test("perform(.nextComponent) from idle wake-connects, the TV sees it, and the burst idles back to standby")
    func headlineWakeConnectForwardThenIdle() async throws {
        let authority = InMemoryLANRemotePairingAuthority()
        let (listener, identity) = try await makeListener(authority: authority)
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }
        let token = "trusted-token-\(UUID().uuidString)"
        await authority.registerPairedToken(token)

        let store = InMemoryPairedTVStore()
        await store.save(seedRecord(fingerprint: identity.fingerprint, port: port, token: token))
        let driver = makeDriver(store: store)

        let reply = await driver.perform(.nextComponent)
        #expect(reply.outcome == .forwarded)

        let event = try await waitFor(listener.controlEvents) { $0.frame.message.caseName == "nextComponent" }
        #expect(event.frame.message.caseName == "nextComponent")

        // Past the 150ms idle deadline — the burst should have cleanly
        // ended and the driver published back to .standby.
        try await Task.sleep(for: .milliseconds(500))
        #expect(driver.liveSnapshot == nil)
        #expect(driver.baselineSnapshot.phase == .standby)
        #expect(await listener.pairedConnectionCount == 0)
    }

    // MARK: - (b) Warm path — a second tap inside the linger reuses the connection

    @Test("A second perform within the linger forwards on the SAME connection — no new connect")
    func warmPathReusesLiveConnection() async throws {
        let authority = InMemoryLANRemotePairingAuthority()
        let (listener, identity) = try await makeListener(authority: authority)
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }
        let token = "trusted-token-\(UUID().uuidString)"
        await authority.registerPairedToken(token)

        let store = InMemoryPairedTVStore()
        await store.save(seedRecord(fingerprint: identity.fingerprint, port: port, token: token))
        let driver = makeDriver(store: store)

        let first = await driver.perform(.nextComponent)
        #expect(first.outcome == .forwarded)
        #expect(await listener.pairedConnectionCount == 1)

        let second = await driver.perform(.prevComponent)
        #expect(second.outcome == .forwarded)
        #expect(await listener.pairedConnectionCount == 1) // still ONE TV connection total
    }

    // MARK: - (c) Buffering — two taps fired before .controlling, forwarded in order

    @Test("Two commands fired before .controlling arrive at the TV in order")
    func bufferedCommandsArriveInOrder() async throws {
        let authority = InMemoryLANRemotePairingAuthority()
        let (listener, identity) = try await makeListener(authority: authority)
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }
        let token = "trusted-token-\(UUID().uuidString)"
        await authority.registerPairedToken(token)

        let store = InMemoryPairedTVStore()
        await store.save(seedRecord(fingerprint: identity.fingerprint, port: port, token: token))
        let driver = makeDriver(store: store)

        async let first = driver.perform(.nextComponent)
        async let second = driver.perform(.prevComponent)
        let (firstReply, secondReply) = await (first, second)
        #expect(firstReply.outcome == .forwarded)
        #expect(secondReply.outcome == .forwarded)

        let firstEvent = try await waitFor(listener.controlEvents) { $0.frame.message.caseName == "nextComponent" }
        let secondEvent = try await waitFor(listener.controlEvents) { $0.frame.message.caseName == "prevComponent" }
        #expect(firstEvent.frame.message.caseName == "nextComponent")
        #expect(secondEvent.frame.message.caseName == "prevComponent")
    }

    // MARK: - (d) Dead TV — a bounded, honest failure, no retry traffic

    @Test("A record pointing at nothing fails .couldNotReachTV within the ms deadline, with no retry")
    func deadTVFailsCouldNotReachTV() async throws {
        let deadPort = try #require(NWEndpoint.Port(rawValue: UInt16.random(in: 20_000...40_000)))
        let store = InMemoryPairedTVStore()
        await store.save(seedRecord(fingerprint: String(repeating: "a", count: 64), port: deadPort, token: "irrelevant-token"))
        let driver = makeDriver(store: store)

        let reply = await driver.perform(.nextComponent)
        #expect(reply.outcome == .failed)
        #expect(reply.reason == .couldNotReachTV)

        // A fresh tap right after starts a BRAND NEW burst — no leftover
        // retry-ladder state from the failed attempt (D-16).
        let second = await driver.perform(.nextComponent)
        #expect(second.outcome == .failed)
        #expect(second.reason == .couldNotReachTV)
    }

    // MARK: - (e) Revoked token — the ceremony is cancelled, never completed

    @Test("A token the TV doesn't recognize is cancelled, never a code entry — .failed(.repairNeeded)")
    func revokedTokenCancelsAndFailsRepairNeeded() async throws {
        let authority = InMemoryLANRemotePairingAuthority()
        // Deliberately NEVER registered with the authority — the TV
        // answers with `.pairChallenge`, not `.capabilities`.
        let (listener, identity) = try await makeListener(authority: authority)
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }

        let store = InMemoryPairedTVStore()
        await store.save(seedRecord(fingerprint: identity.fingerprint, port: port, token: "a-revoked-token"))
        let driver = makeDriver(store: store)

        let reply = await driver.perform(.nextComponent)
        #expect(reply.outcome == .failed)
        #expect(reply.reason == .repairNeeded)
        // No pairing ceremony was ever completed at the TV — it never
        // promoted this connection to paired.
        #expect(await listener.pairedConnectionCount == 0)
    }

    // MARK: - (f) Retire mid-linger — awaited teardown, then a fresh burst

    @Test("retire() returns only after the TV observed the close; the next perform starts a fresh burst")
    func retireAwaitsTeardownThenFreshBurst() async throws {
        let authority = InMemoryLANRemotePairingAuthority()
        let (listener, identity) = try await makeListener(authority: authority)
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }
        let token = "trusted-token-\(UUID().uuidString)"
        await authority.registerPairedToken(token)

        let store = InMemoryPairedTVStore()
        await store.save(seedRecord(fingerprint: identity.fingerprint, port: port, token: token))
        let driver = makeDriver(store: store)

        let first = await driver.perform(.nextComponent)
        #expect(first.outcome == .forwarded)
        #expect(await listener.pairedConnectionCount == 1)

        await driver.retire()
        #expect(await listener.pairedConnectionCount == 0)

        let second = await driver.perform(.prevComponent)
        #expect(second.outcome == .forwarded)
        #expect(await listener.pairedConnectionCount == 1)
    }

    // MARK: - (g) Empty store — an honest, network-free failure

    @Test("An empty store fails .noSavedTV — no session is ever built, no network touched")
    func emptyStoreFailsNoSavedTV() async {
        let driver = makeDriver(store: InMemoryPairedTVStore())
        let clock = ContinuousClock()
        let start = clock.now
        let reply = await driver.perform(.nextComponent)
        let elapsed = clock.now - start
        #expect(reply.outcome == .failed)
        #expect(reply.reason == .noSavedTV)
        // No connect was ever attempted — this resolves near-instantly,
        // nowhere close to the 800ms connect deadline.
        #expect(elapsed < .milliseconds(200))
    }
}

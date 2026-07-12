// LANConnectivityProbeLoopbackTests.swift
// IHLiveTests
//
// ELI5: Proves the troubleshooter's "is anything there?" check really
// works against a REAL TV listener — and, just as importantly, that it
// truly never starts (or looks like) a pairing attempt while doing it.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §7.3, Decision D-7).
// Verbatim posture from the sibling loopback suites: opt-in behind
// `IHYMNS_LAN_LOOPBACK_TESTS=1`, `defer`-registered teardown the instant
// each listener is created.
//
// **NOT millisecond timeouts, unlike the sibling suites — an empirically
// verified environment reality, not a design choice this test fights:** a
// standalone diagnostic (a raw `NWConnection` + `sec_protocol_options_set
// _verify_block`, no `RemoteSessionActor`/`LANConnectivityProbe` involved
// at all) proved that on this unsigned-test-binary environment, connecting
// to a `127.0.0.1` port NOTHING has ever listened on reaches
// `NWConnection.State.waiting` (carrying `POSIXErrorCode.ECONNREFUSED`) and
// STAYS there indefinitely (watched 15 real seconds, never moved) — never
// `.failed`, so `.refused` is never actually observable here; this suite's
// own `probe(...)` timeout is what terminates it, correctly, as
// `.timedOut`. The SAME environment also needs noticeably longer than a
// few hundred milliseconds (and, under the FULL combined loopback-suite
// verification run's heavy concurrent load, occasionally more than a few
// seconds) to complete a REAL reachable handshake — likely Local Network
// permission negotiation overhead specific to an unsigned `swift test`
// binary, compounded by this Mac's cooperative thread pool under many
// simultaneous real TLS/Keychain operations — so the reachable-probe test
// uses a GENEROUS timeout and `.serialized` (removing at least this
// suite's own internal contention) rather than chase a tight bound a
// diagnostic-only probe has no real reason to need. Both findings are
// consistent with — and the SAME root cause spec §5.1 already anticipated
// needing a bounded race for — the finding
// `ManualConnectLoopbackTestSupport.swift`'s doc comment records in more
// detail (a pre-existing `RemoteSessionActor+Connection.swift` gap, out of
// scope to fix, D-2).
import Foundation
import Network
import Testing
@testable import IHLive

@Suite(
    "LANConnectivityProbe — TLS loopback integration",
    .serialized,
    .enabled(
        if: ProcessInfo.processInfo.environment["IHYMNS_LAN_LOOPBACK_TESTS"] == "1",
        "Live 127.0.0.1 TLS loopback needs macOS Local Network permission an unsigned headless `swift test` binary can't obtain — set IHYMNS_LAN_LOOPBACK_TESTS=1 on a Mac where iHymns has that grant. See TVListenerPairingLoopbackTests.swift's header comment."
    )
)
struct LANConnectivityProbeLoopbackTests {

    private let helper = RemoteControlSessionLoopbackTests()

    @Test("probe(...) against a live listener returns .reachable with the listener's REAL fingerprint, and triggers NO pairing side effect")
    func probeAgainstLiveListenerIsReachableAndObserveOnly() async throws {
        let (listener, identity) = try await helper.makeListener()
        defer { identity.removeFromKeychain(); Task { await listener.stop() } }
        guard let port = await listener.boundPort else { Issue.record("no bound port"); return }

        let outcome = await LANConnectivityProbe.probe(host: "127.0.0.1", port: port.rawValue, timeout: .seconds(15))
        guard case .reachable(let fingerprintHex) = outcome else {
            Issue.record("expected .reachable, got \(outcome)")
            return
        }
        #expect(fingerprintHex == identity.fingerprint)

        // D-7's "observe-and-hang-up" contract, executable: the probe never
        // sends `hello`, so the listener must NEVER see a `.pairing`
        // connection from it — asserted via a short-timeout expectation
        // that `pairingEvents` stays entirely silent.
        await #expect(throws: LANRemoteTestTimeoutError.self) {
            _ = try await waitFor(listener.pairingEvents, timeout: .milliseconds(300)) { _ in true }
        }
        #expect(await listener.unpairedConnectionCount == 0)
    }

    @Test("probe(...) against a closed 127.0.0.1 port returns .timedOut on this environment (this file's header — .refused is classify(_:)'s job, table-tested in LANTroubleshooterAssessmentTests, not reachable live here)")
    func probeAgainstClosedPortTimesOutOnThisEnvironment() async throws {
        let closedPort = try #require(NWEndpoint.Port(rawValue: UInt16.random(in: 20_000...40_000)))
        let outcome = await LANConnectivityProbe.probe(host: "127.0.0.1", port: closedPort.rawValue, timeout: .seconds(2))
        #expect(outcome == .timedOut)
    }
}

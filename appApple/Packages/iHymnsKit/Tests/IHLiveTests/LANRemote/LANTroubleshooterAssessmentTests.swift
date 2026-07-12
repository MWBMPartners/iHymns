// LANTroubleshooterAssessmentTests.swift
// IHLiveTests
//
// ELI5: Checks that the troubleshooter's decision table produces exactly
// the right "here's the main problem" (and any extra notes) for every
// combination of "can we even look for TVs," "did we find any," and "did a
// direct connection test work" — and that turning a raw network error into
// a plain outcome always lands in the right bucket.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §5.3/§7.1 — BINDING
// table, one case per row). Pure/always-on: no actor, no network, no clock.
import Network
import Testing
@testable import IHLive

@Suite("LANTroubleshooterAssessment.evaluate(_:)")
struct LANTroubleshooterAssessmentTests {

    private func inputs(
        permissionDenied: Bool = false, discovered: Int = 0, probe: LANConnectivityProbeOutcome? = nil
    ) -> LANTroubleshooterInputs {
        LANTroubleshooterInputs(localNetworkPermissionDenied: permissionDenied, discoveredServiceCount: discovered, probe: probe)
    }

    // MARK: - The §5.3 table, row by row

    @Test("Row 1: permission denied dominates every other row, regardless of discovery/probe")
    func row1PermissionDeniedDominates() {
        let findings = LANTroubleshooterAssessment.evaluate(inputs(permissionDenied: true, discovered: 5, probe: .reachable(fingerprintHex: "f")))
        #expect(findings == [.localNetworkPermissionDenied])
    }

    @Test("Row 2: no permission issue, nothing discovered, no probe ⇒ .nothingDiscoveredNoProbe")
    func row2NothingDiscoveredNoProbe() {
        let findings = LANTroubleshooterAssessment.evaluate(inputs())
        #expect(findings == [.nothingDiscoveredNoProbe])
    }

    @Test("Row 3: discovery found something, no probe ⇒ .discoveryWorking")
    func row3DiscoveryWorkingNoProbe() {
        let findings = LANTroubleshooterAssessment.evaluate(inputs(discovered: 2))
        #expect(findings == [.discoveryWorking])
    }

    @Test("Row 4: discovery AND probe both work ⇒ .allClear")
    func row4AllClear() {
        let findings = LANTroubleshooterAssessment.evaluate(inputs(discovered: 1, probe: .reachable(fingerprintHex: "f")))
        #expect(findings == [.allClear])
    }

    @Test("Row 5: probe reachable but NOTHING discovered ⇒ .vpnOrMulticastBlocked (the owner's day-one case)")
    func row5VpnOrMulticastBlocked() {
        let findings = LANTroubleshooterAssessment.evaluate(inputs(discovered: 0, probe: .reachable(fingerprintHex: "f")))
        #expect(findings == [.vpnOrMulticastBlocked])
    }

    @Test("Row 6: discovered something but the probe times out ⇒ .likelyClientIsolation, THEN .discoveryWorking appended")
    func row6LikelyClientIsolationAppendsDiscoveryWorking() {
        let findings = LANTroubleshooterAssessment.evaluate(inputs(discovered: 3, probe: .timedOut))
        #expect(findings == [.likelyClientIsolation, .discoveryWorking])
    }

    @Test("Row 7: nothing discovered AND the probe times out ⇒ .unreachableAndInvisible")
    func row7UnreachableAndInvisible() {
        let findings = LANTroubleshooterAssessment.evaluate(inputs(discovered: 0, probe: .timedOut))
        #expect(findings == [.unreachableAndInvisible])
    }

    @Test("Row 8: the probe is refused ⇒ .portClosedOnHost, regardless of discovery count")
    func row8PortClosedOnHost() {
        #expect(LANTroubleshooterAssessment.evaluate(inputs(discovered: 0, probe: .refused)) == [.portClosedOnHost])
        #expect(LANTroubleshooterAssessment.evaluate(inputs(discovered: 4, probe: .refused)) == [.portClosedOnHost])
    }

    @Test("Row 9: the probe's TLS handshake fails ⇒ .notAnIHymnsListener, regardless of discovery count")
    func row9NotAnIHymnsListener() {
        #expect(LANTroubleshooterAssessment.evaluate(inputs(discovered: 0, probe: .tlsFailed)) == [.notAnIHymnsListener])
        #expect(LANTroubleshooterAssessment.evaluate(inputs(discovered: 2, probe: .tlsFailed)) == [.notAnIHymnsListener])
    }

    @Test("Row 10: the probe's hostname doesn't resolve ⇒ .hostnameNotResolving, regardless of discovery count")
    func row10HostnameNotResolving() {
        #expect(LANTroubleshooterAssessment.evaluate(inputs(discovered: 0, probe: .dnsFailed)) == [.hostnameNotResolving])
        #expect(LANTroubleshooterAssessment.evaluate(inputs(discovered: 1, probe: .dnsFailed)) == [.hostnameNotResolving])
    }

    @Test("Row 11: an invalid address probe ⇒ .invalidAddress, regardless of discovery count")
    func row11InvalidAddress() {
        #expect(LANTroubleshooterAssessment.evaluate(inputs(discovered: 0, probe: .invalidAddress)) == [.invalidAddress])
        #expect(LANTroubleshooterAssessment.evaluate(inputs(discovered: 3, probe: .invalidAddress)) == [.invalidAddress])
    }

    // MARK: - Deterministic ordering (headline first, secondaries after)

    @Test("Findings are always headline-first — row 6's .discoveryWorking always comes SECOND, never first")
    func headlineAlwaysFirst() {
        let findings = LANTroubleshooterAssessment.evaluate(inputs(discovered: 1, probe: .timedOut))
        #expect(findings.first == .likelyClientIsolation)
        #expect(findings.count == 2)
        #expect(findings.last == .discoveryWorking)
    }

    // MARK: - LANConnectivityProbeOutcome.classify(_:) — the pure NWError → Outcome mapping

    @Test("classify(.posix(.ECONNREFUSED)) ⇒ .refused")
    func classifyConnectionRefused() {
        #expect(LANConnectivityProbeOutcome.classify(.posix(.ECONNREFUSED)) == .refused)
    }

    @Test("classify(.posix(.ETIMEDOUT)) ⇒ .timedOut")
    func classifyTimedOut() {
        #expect(LANConnectivityProbeOutcome.classify(.posix(.ETIMEDOUT)) == .timedOut)
    }

    @Test("classify(.dns(...)) ⇒ .dnsFailed, regardless of the specific DNS error code")
    func classifyDnsFailed() {
        #expect(LANConnectivityProbeOutcome.classify(.dns(-65554)) == .dnsFailed) // kDNSServiceErr_PolicyDenied
        #expect(LANConnectivityProbeOutcome.classify(.dns(-65550)) == .dnsFailed) // kDNSServiceErr_NoSuchRecord
    }

    @Test("classify(.tls(...)) ⇒ .tlsFailed")
    func classifyTlsFailed() {
        #expect(LANConnectivityProbeOutcome.classify(.tls(-9807)) == .tlsFailed) // errSSLProtocol
    }

    @Test("classify(_:) degrades an UNRECOGNISED posix code to .timedOut — the honest 'couldn't reach it' bucket, never a crash")
    func classifyUnrecognisedPosixCodeDegradesToTimedOut() {
        #expect(LANConnectivityProbeOutcome.classify(.posix(.EHOSTUNREACH)) == .timedOut)
        #expect(LANConnectivityProbeOutcome.classify(.posix(.ECONNRESET)) == .timedOut)
    }
}

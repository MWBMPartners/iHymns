// RemotePairingEntryResolverTests.swift
// IHLiveTests
//
// ELI5: Checks that "I scanned this QR" / "I tapped this saved TV" always
// turns into the RIGHT connection plan — the right address, the right
// fingerprint, whether we skip the code dance — for every row of the entry-
// path table, including the tricky ones (already-paired TV re-scanned,
// standing QR for a TV we already trust, no address at all).
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §4/§7.2 — BINDING,
// "resolve it EXACTLY this way"). Pure/always-on, table-driven where the
// table itself is the clearest form.
import Foundation
import Network
import Testing
@testable import IHLive

@Suite("RemotePairingEntryResolver")
struct RemotePairingEntryResolverTests {

    private let fingerprint = "abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789"
    private let otherFingerprint = "1111110123456789abcdef0123456789abcdef0123456789abcdef0123456789"

    private func discovered(name: String, port: UInt16 = 7269) -> LANRemoteDiscoveredService {
        LANRemoteDiscoveredService(name: name, endpoint: .hostPort(host: "10.0.0.5", port: NWEndpoint.Port(rawValue: port)!))
    }

    private func savedRecord(
        fingerprintHex: String? = nil, name: String = "Fellowship Hall TV", token: String = "saved-token",
        lastAddress: LANRemoteResolvedAddress? = LANRemoteResolvedAddress(host: "10.0.0.9", port: 7269)
    ) -> PairedTVRecord {
        PairedTVRecord(
            fingerprintHex: fingerprintHex ?? fingerprint, name: name, token: token, lastAddress: lastAddress,
            pairedAt: Date(timeIntervalSince1970: 1_700_000_000), lastConnectedAt: nil
        )
    }

    // MARK: - Path A/B: ceremony vs standing payload for an already-saved fingerprint

    @Test("A ceremony payload (code != nil) for an ALREADY-saved fingerprint runs the ceremony anyway — token is nil (re-pair)")
    func ceremonyPayloadForSavedFingerprintReRunsCeremony() {
        let saved = [savedRecord()]
        let payload = LANRemotePairingPayload(name: "Fellowship Hall TV", host: "10.0.0.9", port: 7269, fingerprintHex: fingerprint, code: "042317")

        let resolution = RemotePairingEntryResolver.resolve(.payload(payload), saved: saved, discovered: [])
        guard case .connect(let target) = resolution else {
            Issue.record("expected .connect, got \(resolution)")
            return
        }
        #expect(target.token == nil)
        #expect(target.knownCode == "042317")
        #expect(target.expectedFingerprint == fingerprint)
    }

    @Test("A standing payload (code == nil) for a saved fingerprint short-circuits to the saved token — no ceremony")
    func standingPayloadForSavedFingerprintUsesSavedToken() {
        let saved = [savedRecord(token: "already-trusted-token")]
        let payload = LANRemotePairingPayload(name: "Fellowship Hall TV", host: "10.0.0.9", port: 7269, fingerprintHex: fingerprint, code: nil)

        let resolution = RemotePairingEntryResolver.resolve(.payload(payload), saved: saved, discovered: [])
        guard case .connect(let target) = resolution else {
            Issue.record("expected .connect, got \(resolution)")
            return
        }
        #expect(target.token == "already-trusted-token")
        #expect(target.knownCode == nil)
    }

    @Test("A standing payload for an UNKNOWN fingerprint runs the ceremony (token nil, typed-code path)")
    func standingPayloadForUnknownFingerprintNoToken() {
        let payload = LANRemotePairingPayload(name: "New TV", host: "10.0.0.9", port: 7269, fingerprintHex: otherFingerprint, code: nil)
        let resolution = RemotePairingEntryResolver.resolve(.payload(payload), saved: [], discovered: [])
        guard case .connect(let target) = resolution else {
            Issue.record("expected .connect, got \(resolution)")
            return
        }
        #expect(target.token == nil)
        #expect(target.knownCode == nil)
    }

    // MARK: - Payload endpoint resolution

    @Test("A payload with an explicit host/port uses it directly, ignoring discovery")
    func payloadWithHostUsesItDirectly() {
        let payload = LANRemotePairingPayload(name: "TV", host: "10.0.0.9", port: 7269, fingerprintHex: fingerprint, code: "042317")
        let resolution = RemotePairingEntryResolver.resolve(.payload(payload), saved: [], discovered: [discovered(name: "Some Other TV")])
        guard case .connect(let target) = resolution, case .hostPort(let host, let port) = target.endpoint else {
            Issue.record("expected a .hostPort endpoint")
            return
        }
        #expect("\(host)" == "10.0.0.9")
        #expect(port.rawValue == 7269)
    }

    @Test("A payload with nil host falls back to a name-matching discovered endpoint")
    func payloadWithNilHostUsesNameMatchedDiscovery() {
        let payload = LANRemotePairingPayload(name: "Fellowship Hall TV", host: nil, port: nil, fingerprintHex: fingerprint, code: "042317")
        let match = discovered(name: "Fellowship Hall TV", port: 12345)
        let resolution = RemotePairingEntryResolver.resolve(.payload(payload), saved: [], discovered: [match])
        guard case .connect(let target) = resolution else {
            Issue.record("expected .connect, got \(resolution)")
            return
        }
        #expect(target.endpoint == match.endpoint)
    }

    @Test("A payload with nil host and no discovery match is unpairable (.noRouteToTV)")
    func payloadWithNilHostAndNoMatchIsUnpairable() {
        let payload = LANRemotePairingPayload(name: "Fellowship Hall TV", host: nil, port: nil, fingerprintHex: fingerprint, code: "042317")
        let resolution = RemotePairingEntryResolver.resolve(.payload(payload), saved: [], discovered: [discovered(name: "Some Other TV")])
        guard case .unpairable(let reason) = resolution else {
            Issue.record("expected .unpairable, got \(resolution)")
            return
        }
        #expect(reason == .noRouteToTV)
    }

    // MARK: - Path C: a tap on an already-saved row

    @Test("A saved row with a name-matched discovery uses the discovered endpoint as primary, saved address as fallback")
    func savedRowWithNameMatchUsesDiscoveredPrimary() {
        let record = savedRecord(lastAddress: LANRemoteResolvedAddress(host: "10.0.0.9", port: 7269))
        let match = discovered(name: record.name, port: 9999)

        let resolution = RemotePairingEntryResolver.resolve(.savedRow(record), saved: [record], discovered: [match])
        guard case .connect(let target) = resolution else {
            Issue.record("expected .connect, got \(resolution)")
            return
        }
        #expect(target.endpoint == match.endpoint)
        #expect(target.token == record.token)
        guard case .hostPort(let host, let port) = target.fallbackEndpoint else {
            Issue.record("expected a .hostPort fallback endpoint")
            return
        }
        #expect("\(host)" == "10.0.0.9")
        #expect(port.rawValue == 7269)
    }

    @Test("A saved row alone (no discovery match) uses its saved address as primary, no fallback")
    func savedRowAloneUsesSavedAddress() {
        let record = savedRecord(lastAddress: LANRemoteResolvedAddress(host: "10.0.0.9", port: 7269))
        let resolution = RemotePairingEntryResolver.resolve(.savedRow(record), saved: [record], discovered: [])
        guard case .connect(let target) = resolution else {
            Issue.record("expected .connect, got \(resolution)")
            return
        }
        guard case .hostPort(let host, let port) = target.endpoint else {
            Issue.record("expected a .hostPort endpoint")
            return
        }
        #expect("\(host)" == "10.0.0.9")
        #expect(port.rawValue == 7269)
        #expect(target.fallbackEndpoint == nil)
        #expect(target.token == record.token)
    }

    @Test("A saved row with neither a discovery match nor a saved address is unpairable")
    func savedRowWithNoRouteIsUnpairable() {
        let record = savedRecord(lastAddress: nil)
        let resolution = RemotePairingEntryResolver.resolve(.savedRow(record), saved: [record], discovered: [])
        guard case .unpairable(let reason) = resolution else {
            Issue.record("expected .unpairable, got \(resolution)")
            return
        }
        #expect(reason == .noRouteToTV)
    }

    // MARK: - listRows

    @Test("listRows pins saved rows first (newest lastConnectedAt/pairedAt first), then nearby-unpaired sorted by name")
    func listRowsOrdering() {
        let older = savedRecord(fingerprintHex: fingerprint, name: "Older TV")
        var newer = savedRecord(fingerprintHex: otherFingerprint, name: "Newer TV")
        newer.lastConnectedAt = Date(timeIntervalSince1970: 1_800_000_000)

        let nearbyB = discovered(name: "Zebra Hall")
        let nearbyA = discovered(name: "Annex TV")

        let rows = RemotePairingEntryResolver.listRows(saved: [older, newer], discovered: [nearbyB, nearbyA])
        #expect(rows.count == 4)

        guard case .paired(let first) = rows[0].kind, case .paired(let second) = rows[1].kind else {
            Issue.record("expected the first two rows to be .paired")
            return
        }
        #expect(first.name == "Newer TV")
        #expect(second.name == "Older TV")

        guard case .nearbyUnpaired(let third) = rows[2].kind, case .nearbyUnpaired(let fourth) = rows[3].kind else {
            Issue.record("expected the last two rows to be .nearbyUnpaired")
            return
        }
        #expect(third.name == "Annex TV")
        #expect(fourth.name == "Zebra Hall")
    }

    @Test("listRows flags a saved row isNearby when a discovered service shares its name")
    func listRowsFlagsIsNearby() {
        let record = savedRecord(name: "Fellowship Hall TV")
        let rows = RemotePairingEntryResolver.listRows(saved: [record], discovered: [discovered(name: "Fellowship Hall TV")])
        #expect(rows.count == 1)
        #expect(rows[0].isNearby == true)
    }

    @Test("listRows never surfaces a saved TV twice as a nearby-unpaired row too")
    func listRowsDoesNotDuplicateSavedAsNearby() {
        let record = savedRecord(name: "Fellowship Hall TV")
        let rows = RemotePairingEntryResolver.listRows(saved: [record], discovered: [discovered(name: "Fellowship Hall TV")])
        #expect(rows.filter { if case .nearbyUnpaired = $0.kind { return true }; return false }.isEmpty)
    }
}

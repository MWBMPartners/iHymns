// RemotePairingManualResolutionTests.swift
// IHLiveTests
//
// ELI5: Checks that "I typed this address and the TV showed me this
// fingerprint" always resolves the right way — silently reconnect if we
// already trust that exact fingerprint (using the SAVED name/token, not
// whatever the user typed), or say "never seen it" so the app knows to ask
// a human to confirm.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §3.4/§4.3/§7.1 —
// BINDING). Split out of `RemotePairingEntryResolverTests.swift` into its
// own file purely to keep THAT file's `type_body_length` under SwiftLint's
// budget (the `RemoteControlSessionLoopbackTests`/
// `RemoteControlSessionReArmTests.swift` precedent for the identical
// reason) — own small set of fixtures rather than reaching across files
// for the original suite's `private` ones.
import Foundation
import Network
import Testing
@testable import IHLive

@Suite("RemotePairingEntryResolver.manualResolution (#1424)")
struct RemotePairingManualResolutionTests {

    private let fingerprint = "abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789"
    private let otherFingerprint = "1111110123456789abcdef0123456789abcdef0123456789abcdef0123456789"

    private func savedRecord(
        fingerprintHex: String? = nil, name: String = "Fellowship Hall TV", token: String = "saved-token",
        lastAddress: LANRemoteResolvedAddress? = nil
    ) -> PairedTVRecord {
        PairedTVRecord(
            fingerprintHex: fingerprintHex ?? fingerprint, name: name, token: token, lastAddress: lastAddress,
            pairedAt: Date(timeIntervalSince1970: 1_700_000_000), lastConnectedAt: nil
        )
    }

    @Test("An observed fingerprint matching a saved record resolves to .knownTV with the SAVED name + SAVED token")
    func matchingFingerprintResolvesToKnownTV() {
        let record = savedRecord(name: "Fellowship Hall TV", token: "saved-token", lastAddress: nil)
        let resolution = RemotePairingEntryResolver.manualResolution(
            observedFingerprint: fingerprint, host: "10.0.0.9", port: 7269, displayName: "10.0.0.9", saved: [record]
        )
        guard case .knownTV(let target) = resolution else { Issue.record("expected .knownTV, got \(resolution)"); return }
        #expect(target.tvName == "Fellowship Hall TV")
        #expect(target.token == "saved-token")
        #expect(target.expectedFingerprint == fingerprint)
        #expect(target.knownCode == nil)
        guard case .hostPort(let host, let port) = target.endpoint else { Issue.record("expected a .hostPort endpoint"); return }
        #expect("\(host)" == "10.0.0.9")
        #expect(port.rawValue == 7269)
    }

    @Test("The typed address is primary; a DIFFERENT saved lastAddress becomes the fallback")
    func usesDifferentLastAddressAsFallback() {
        let record = savedRecord(lastAddress: LANRemoteResolvedAddress(host: "10.0.0.9", port: 7269))
        let resolution = RemotePairingEntryResolver.manualResolution(
            observedFingerprint: fingerprint, host: "192.168.1.50", port: 8000, displayName: "192.168.1.50", saved: [record]
        )
        guard case .knownTV(let target) = resolution else { Issue.record("expected .knownTV, got \(resolution)"); return }
        guard case .hostPort(let primaryHost, let primaryPort) = target.endpoint else {
            Issue.record("expected a .hostPort primary endpoint")
            return
        }
        #expect("\(primaryHost)" == "192.168.1.50")
        #expect(primaryPort.rawValue == 8000)
        guard case .hostPort(let fallbackHost, let fallbackPort) = target.fallbackEndpoint else {
            Issue.record("expected a .hostPort fallback endpoint")
            return
        }
        #expect("\(fallbackHost)" == "10.0.0.9")
        #expect(fallbackPort.rawValue == 7269)
    }

    @Test("A saved lastAddress EQUAL to the typed address produces no self-fallback")
    func noSelfFallbackWhenAddressesMatch() {
        let record = savedRecord(lastAddress: LANRemoteResolvedAddress(host: "10.0.0.9", port: 7269))
        let resolution = RemotePairingEntryResolver.manualResolution(
            observedFingerprint: fingerprint, host: "10.0.0.9", port: 7269, displayName: "10.0.0.9", saved: [record]
        )
        guard case .knownTV(let target) = resolution else { Issue.record("expected .knownTV, got \(resolution)"); return }
        #expect(target.fallbackEndpoint == nil)
    }

    @Test("An UNKNOWN fingerprint resolves to .unknownTV")
    func unknownFingerprintResolvesToUnknownTV() {
        let resolution = RemotePairingEntryResolver.manualResolution(
            observedFingerprint: otherFingerprint, host: "10.0.0.9", port: 7269, displayName: "10.0.0.9", saved: [savedRecord()]
        )
        guard case .unknownTV = resolution else { Issue.record("expected .unknownTV, got \(resolution)"); return }
    }
}

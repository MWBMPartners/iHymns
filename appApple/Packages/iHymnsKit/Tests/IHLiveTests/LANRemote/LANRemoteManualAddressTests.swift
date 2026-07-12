// LANRemoteManualAddressTests.swift
// IHLiveTests
//
// ELI5: Checks that every shape of address someone might type into the
// "Connect by Address" box — a plain IPv4 address, one with a port tacked
// on, a hostname, an IPv6 address (bracketed or bare), garbage, a pasted
// URL — turns into exactly the right host/port pair, or exactly the right
// friendly error, every time.
//
// DETAILED: #1424 (`.claude/apple-phase2-pr8-spec.md` §3.1/§7.1 — BINDING
// table). Pure/always-on: no actor, no network, no clock.
import Testing
@testable import IHLive

@Suite("LANRemoteManualAddress.parse")
struct LANRemoteManualAddressTests {

    // MARK: - Plain hosts, no port anywhere

    @Test("A bare IPv4 address with no port anywhere defaults to LANRemoteDiscovery.defaultPort")
    func bareIPv4DefaultsPort() {
        let result = LANRemoteManualAddress.parse(hostInput: "192.168.1.50", portInput: nil)
        guard case .success(let parsed) = result else { Issue.record("expected success, got \(result)"); return }
        #expect(parsed.host == "192.168.1.50")
        #expect(parsed.port == 7269)
        #expect(parsed.port == LANRemoteDiscovery.defaultPort)
    }

    @Test("A bare hostname with no port anywhere defaults to LANRemoteDiscovery.defaultPort")
    func bareHostnameDefaultsPort() {
        let result = LANRemoteManualAddress.parse(hostInput: "hall-tv.local", portInput: nil)
        guard case .success(let parsed) = result else { Issue.record("expected success, got \(result)"); return }
        #expect(parsed.host == "hall-tv.local")
        #expect(parsed.port == LANRemoteDiscovery.defaultPort)
    }

    // MARK: - Host-field suffix wins over the separate port field

    @Test("A host-field :port suffix wins over a populated port field")
    func hostSuffixWinsOverPortField() {
        let result = LANRemoteManualAddress.parse(hostInput: "192.168.1.50:8000", portInput: "7269")
        guard case .success(let parsed) = result else { Issue.record("expected success, got \(result)"); return }
        #expect(parsed.host == "192.168.1.50")
        #expect(parsed.port == 8000)
    }

    @Test("No host suffix — the separate port field is used")
    func portFieldUsedWhenNoSuffix() {
        let result = LANRemoteManualAddress.parse(hostInput: "hall-tv.local", portInput: "9000")
        guard case .success(let parsed) = result else { Issue.record("expected success, got \(result)"); return }
        #expect(parsed.host == "hall-tv.local")
        #expect(parsed.port == 9000)
    }

    // MARK: - IPv6

    @Test("A bracketed IPv6 literal with a zone id and :port suffix strips the brackets, keeps the zone, parses the port")
    func bracketedIPv6WithZoneAndPort() {
        let result = LANRemoteManualAddress.parse(hostInput: "[fe80::1%en0]:7269", portInput: nil)
        guard case .success(let parsed) = result else { Issue.record("expected success, got \(result)"); return }
        #expect(parsed.host == "fe80::1%en0")
        #expect(parsed.port == 7269)
    }

    @Test("A bracketed IPv6 literal with no suffix port falls back to the default")
    func bracketedIPv6NoSuffix() {
        let result = LANRemoteManualAddress.parse(hostInput: "[::1]", portInput: nil)
        guard case .success(let parsed) = result else { Issue.record("expected success, got \(result)"); return }
        #expect(parsed.host == "::1")
        #expect(parsed.port == LANRemoteDiscovery.defaultPort)
    }

    @Test("A bare (unbracketed) IPv6 literal is never suffix-split — the whole string is the host")
    func bareIPv6NoSuffixParsing() {
        let result = LANRemoteManualAddress.parse(hostInput: "fe80::1", portInput: nil)
        guard case .success(let parsed) = result else { Issue.record("expected success, got \(result)"); return }
        #expect(parsed.host == "fe80::1")
        #expect(parsed.port == LANRemoteDiscovery.defaultPort)
    }

    // MARK: - Rejections

    @Test("A pasted URL (contains ://) is rejected as .unsupportedScheme")
    func urlSchemeRejected() {
        let result = LANRemoteManualAddress.parse(hostInput: "http://192.168.1.50", portInput: nil)
        guard case .failure(let error) = result else { Issue.record("expected failure, got \(result)"); return }
        #expect(error == .unsupportedScheme)
    }

    @Test("An empty address is rejected as .empty")
    func emptyAddressRejected() {
        let result = LANRemoteManualAddress.parse(hostInput: "", portInput: nil)
        guard case .failure(let error) = result else { Issue.record("expected failure, got \(result)"); return }
        #expect(error == .empty)
    }

    @Test("A whitespace-only address is rejected as .empty")
    func whitespaceOnlyAddressRejected() {
        let result = LANRemoteManualAddress.parse(hostInput: "   \n  ", portInput: nil)
        guard case .failure(let error) = result else { Issue.record("expected failure, got \(result)"); return }
        #expect(error == .empty)
    }

    @Test(
        "Bad ports are all rejected as .invalidPort: :0, host:0, host:70000, host:abc",
        arguments: [
            "192.168.1.50:0",
            "hall-tv.local:70000",
            "hall-tv.local:abc"
        ]
    )
    func badSuffixPortsRejected(input: String) {
        let result = LANRemoteManualAddress.parse(hostInput: input, portInput: nil)
        guard case .failure(let error) = result else { Issue.record("expected failure, got \(result)"); return }
        #expect(error == .invalidPort)
    }

    @Test("A lone ':0' (empty host, explicit zero port) is rejected as .invalidPort — the port is checked before the host")
    func colonZeroRejectedAsInvalidPort() {
        let result = LANRemoteManualAddress.parse(hostInput: ":0", portInput: nil)
        guard case .failure(let error) = result else { Issue.record("expected failure, got \(result)"); return }
        #expect(error == .invalidPort)
    }

    @Test("A bad separate port field (not a valid 1...65535 integer) is rejected as .invalidPort")
    func badPortFieldRejected() {
        let result = LANRemoteManualAddress.parse(hostInput: "hall-tv.local", portInput: "0")
        guard case .failure(let error) = result else { Issue.record("expected failure, got \(result)"); return }
        #expect(error == .invalidPort)
    }

    @Test("A host containing a space is rejected as .invalidHost")
    func hostWithSpaceRejected() {
        let result = LANRemoteManualAddress.parse(hostInput: "ho st", portInput: nil)
        guard case .failure(let error) = result else { Issue.record("expected failure, got \(result)"); return }
        #expect(error == .invalidHost)
    }

    @Test("A 254-character host (one over the DNS-name length ceiling) is rejected as .invalidHost")
    func overlongHostRejected() {
        let longHost = String(repeating: "a", count: 254)
        let result = LANRemoteManualAddress.parse(hostInput: longHost, portInput: nil)
        guard case .failure(let error) = result else { Issue.record("expected failure, got \(result)"); return }
        #expect(error == .invalidHost)
    }

    @Test("A 253-character host (exactly at the DNS-name length ceiling) is accepted")
    func exactlyMaximumHostLengthAccepted() {
        let maxHost = String(repeating: "a", count: 253)
        let result = LANRemoteManualAddress.parse(hostInput: maxHost, portInput: nil)
        guard case .success(let parsed) = result else { Issue.record("expected success, got \(result)"); return }
        #expect(parsed.host == maxHost)
    }
}

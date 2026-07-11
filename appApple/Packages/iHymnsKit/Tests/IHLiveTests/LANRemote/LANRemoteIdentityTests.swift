// LANRemoteIdentityTests.swift
// IHLiveTests
//
// ELI5: Checks that "make me a TV identity" actually produces something
// real — a proper 64-character fingerprint, a different one every time, and
// something Network.framework will accept as a TLS server identity.
//
// DETAILED: #1420. Every test here calls `identity.removeFromKeychain()`
// in a `defer` (this module's `LANRemoteIdentity.swift` header comment:
// "meant to be removed via `removeFromKeychain()` once no longer needed —
// `LANRemoteTests` does this in a `defer`") so a full test-suite run never
// leaves throwaway identities behind in the developer/CI-runner's Keychain.
import Foundation
import Testing
@testable import IHLive

@Suite(
    "LANRemoteIdentity generation",
    .enabled(
        if: lanRemoteKeychainIdentityAvailable(),
        "Needs login-keychain SecIdentity bridging an unsigned headless `swift test` binary can't do — SecItemAdd succeeds but the kSecClassIdentity query returns errSecItemNotFound (-25300) on the CI runner. See lanRemoteKeychainIdentityAvailable(). The pure fingerprint math is covered always-on by LANRemoteFingerprintTests."
    )
)
struct LANRemoteIdentityTests {

    @Test("Generates a well-formed 64-character lowercase-hex fingerprint")
    func fingerprintShape() throws {
        let identity = try LANRemoteIdentityFactory.generateSelfSigned()
        defer { identity.removeFromKeychain() }

        #expect(identity.fingerprint.count == 64)
        #expect(identity.fingerprint.allSatisfy { $0.isHexDigit && !$0.isUppercase })
        #expect(!identity.certificateDER.isEmpty)
    }

    @Test("The fingerprint is the SHA-256 of the certificate DER")
    func fingerprintMatchesDER() throws {
        let identity = try LANRemoteIdentityFactory.generateSelfSigned()
        defer { identity.removeFromKeychain() }

        #expect(identity.fingerprint == LANRemoteFingerprint.sha256Hex(identity.certificateDER))
    }

    @Test("Two generated identities never collide")
    func distinctEachCall() throws {
        let first = try LANRemoteIdentityFactory.generateSelfSigned()
        defer { first.removeFromKeychain() }
        let second = try LANRemoteIdentityFactory.generateSelfSigned()
        defer { second.removeFromKeychain() }

        #expect(first.fingerprint != second.fingerprint)
        #expect(first.certificateDER != second.certificateDER)
    }

    @Test("makeServerTLSOptions() succeeds for a freshly generated identity")
    func serverTLSOptionsBuildable() throws {
        let identity = try LANRemoteIdentityFactory.generateSelfSigned()
        defer { identity.removeFromKeychain() }

        // Smoke test only — the identity is actually EXERCISED end-to-end
        // by `LANRemoteLoopbackTests`; this just confirms the TLS options
        // object itself can be constructed without throwing.
        _ = try identity.makeServerTLSOptions()
    }
}

// LANRemoteFingerprintTests.swift
// IHLiveTests
//
// ELI5: Proves the ONE "bytes → 64-character hex fingerprint" helper is
// correct against textbook SHA-256 answers — no keychain, no network, so it
// runs EVERYWHERE (including the CI runner, where the identity-generation
// tests that USE this helper can't run — see lanRemoteKeychainIdentityAvailable()).
//
// DETAILED: #1420. `LANRemoteFingerprint.sha256Hex` is security-critical: it
// produces the pin-comparison value in `RemoteSessionActor+Connection.swift`
// (a wrong digest here would break pin-before-trust) AND the pairing-token
// hash-at-rest in `LANRemotePairingAuthority` (a wrong digest would defeat
// "never store the raw token"). The end-to-end identity/loopback tests
// exercise it too, but both are environment-gated (keychain / Local Network
// permission), so this pure known-answer suite keeps the fingerprint MATH
// covered on every runner regardless. Vectors are the canonical FIPS 180-4
// SHA-256 test values.
import Foundation
import Testing
@testable import IHLive

@Suite("LANRemoteFingerprint SHA-256")
struct LANRemoteFingerprintTests {

    @Test("Empty input hashes to the textbook SHA-256 of the empty string")
    func emptyVector() {
        #expect(
            LANRemoteFingerprint.sha256Hex(Data())
                == "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
        )
    }

    @Test("\"abc\" hashes to the textbook SHA-256(abc) vector (FIPS 180-4)")
    func abcVector() {
        #expect(
            LANRemoteFingerprint.sha256Hex(Data("abc".utf8))
                == "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
        )
    }

    @Test("Output is always 64 lowercase hex characters")
    func shapeIsAlways64LowercaseHex() {
        for sample in ["", "abc", "iHymnsTV", "the quick brown fox"] {
            let hex = LANRemoteFingerprint.sha256Hex(Data(sample.utf8))
            #expect(hex.count == 64)
            #expect(hex.allSatisfy { $0.isHexDigit && !$0.isUppercase })
        }
    }

    @Test("Deterministic — the same bytes always produce the same fingerprint")
    func deterministic() {
        let data = Data("pretrusted-token-123".utf8)
        #expect(LANRemoteFingerprint.sha256Hex(data) == LANRemoteFingerprint.sha256Hex(data))
    }

    @Test("Different inputs produce different fingerprints")
    func distinctInputsDiffer() {
        #expect(
            LANRemoteFingerprint.sha256Hex(Data("token-A".utf8))
                != LANRemoteFingerprint.sha256Hex(Data("token-B".utf8))
        )
    }
}

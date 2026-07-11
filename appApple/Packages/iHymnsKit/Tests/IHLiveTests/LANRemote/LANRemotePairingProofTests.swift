// LANRemotePairingProofTests.swift
// IHLiveTests
//
// ELI5: Proves the pairing-proof math actually works the way
// `LANRemotePairingProof.swift`'s header says it does — same inputs always
// give the same answer, changing ANY one input changes the answer, a
// frozen "known good" example never silently drifts, and a wrong/garbled
// proof is always rejected.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §7.1 — BINDING).
// The GOLDEN VECTOR below was computed ONCE, offline, straight from the
// production `HKDF<SHA256>`/`HMAC<SHA256>` construction this test also
// exercises live (`compute(...)` against the same three inputs, asserted
// equal) — so this test is both "does the code match a frozen answer" AND
// "is the frozen answer even still reachable by the real code," the same
// double-duty a golden-vector test always does. The constant-time property
// of `verify(...)` is a STRUCTURAL guarantee (it routes through CryptoKit's
// `HMAC.isValidAuthenticationCode`, which this repo's review process — not
// a timing benchmark — is the gate for per spec §7.1: "a timing benchmark
// in CI would be noise, not signal"); this file instead asserts the
// BEHAVIOURAL surface — every class of malformed/wrong input is rejected.
import Foundation
import Testing
@testable import IHLive

@Suite("LANRemotePairingProof compute/verify")
struct LANRemotePairingProofTests {

    // MARK: - Shape / determinism

    @Test("compute() returns 64 lowercase hex characters")
    func computeShape() {
        let proof = LANRemotePairingProof.compute(code: "042317", fingerprintHex: "ab", nonceHex: "cd")
        #expect(proof.count == 64)
        #expect(proof.allSatisfy { $0.isHexDigit && !$0.isUppercase })
    }

    @Test("compute() is deterministic — identical inputs always produce the identical proof")
    func computeIsDeterministic() {
        let first = LANRemotePairingProof.compute(code: "042317", fingerprintHex: "ab", nonceHex: "cd")
        let second = LANRemotePairingProof.compute(code: "042317", fingerprintHex: "ab", nonceHex: "cd")
        #expect(first == second)
    }

    @Test("compute() changes if the code, fingerprint, or nonce individually changes")
    func computeDiffersOnAnySingleInputChange() {
        let base = LANRemotePairingProof.compute(code: "042317", fingerprintHex: "ab", nonceHex: "cd")
        #expect(LANRemotePairingProof.compute(code: "999999", fingerprintHex: "ab", nonceHex: "cd") != base)
        #expect(LANRemotePairingProof.compute(code: "042317", fingerprintHex: "ff", nonceHex: "cd") != base)
        #expect(LANRemotePairingProof.compute(code: "042317", fingerprintHex: "ab", nonceHex: "ff") != base)
    }

    // MARK: - Golden vector (spec §7.1 — BINDING)

    /// **Freezes the HKDF+HMAC construction exactly as `LANRemotePairingProof
    /// .swift`'s header documents it.** If this test fails, you changed the
    /// crypto, which breaks every already-paired remote in the field —
    /// bump the pairing sub-protocol's version instead of touching the
    /// construction to make this pass.
    @Test("GOLDEN VECTOR — a frozen, known-good (code, fingerprint, nonce) → proof")
    func goldenVector() {
        let code = "042317"
        let fingerprintHex = "abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789"
        let nonceHex = "0123456789abcdef0123456789abcde0"
        let expectedProofHex = "8b88b703f398bab753db30059cfde2c10d61b3a18d2cc9640c312f6a89b08847"

        // The frozen expectation itself must be exactly 64 lowercase hex
        // characters (a typo'd literal above would otherwise silently
        // test against a truncated/garbled "golden" value).
        #expect(expectedProofHex.count == 64)

        let actual = LANRemotePairingProof.compute(code: code, fingerprintHex: fingerprintHex, nonceHex: nonceHex)
        #expect(actual == expectedProofHex)
        #expect(LANRemotePairingProof.verify(proofHex: expectedProofHex, code: code, fingerprintHex: fingerprintHex, nonceHex: nonceHex))
    }

    // MARK: - verify() — accepts its own compute(), rejects everything else

    @Test("verify() accepts a proof compute() itself just produced")
    func verifyAcceptsOwnCompute() {
        let proof = LANRemotePairingProof.compute(code: "042317", fingerprintHex: "ab12", nonceHex: "cd34")
        #expect(LANRemotePairingProof.verify(proofHex: proof, code: "042317", fingerprintHex: "ab12", nonceHex: "cd34"))
    }

    @Test("verify() rejects the wrong code")
    func verifyRejectsWrongCode() {
        let proof = LANRemotePairingProof.compute(code: "042317", fingerprintHex: "ab12", nonceHex: "cd34")
        #expect(!LANRemotePairingProof.verify(proofHex: proof, code: "999999", fingerprintHex: "ab12", nonceHex: "cd34"))
    }

    @Test("verify() rejects the wrong fingerprint")
    func verifyRejectsWrongFingerprint() {
        let proof = LANRemotePairingProof.compute(code: "042317", fingerprintHex: "ab12", nonceHex: "cd34")
        #expect(!LANRemotePairingProof.verify(proofHex: proof, code: "042317", fingerprintHex: "ffff", nonceHex: "cd34"))
    }

    @Test("verify() rejects the wrong nonce")
    func verifyRejectsWrongNonce() {
        let proof = LANRemotePairingProof.compute(code: "042317", fingerprintHex: "ab12", nonceHex: "cd34")
        #expect(!LANRemotePairingProof.verify(proofHex: proof, code: "042317", fingerprintHex: "ab12", nonceHex: "ffff"))
    }

    @Test("verify() rejects a truncated proof")
    func verifyRejectsTruncatedProof() {
        let proof = LANRemotePairingProof.compute(code: "042317", fingerprintHex: "ab12", nonceHex: "cd34")
        let truncated = String(proof.prefix(10))
        #expect(!LANRemotePairingProof.verify(proofHex: truncated, code: "042317", fingerprintHex: "ab12", nonceHex: "cd34"))
    }

    @Test("verify() rejects odd-length hex — a non-secret-dependent format rejection")
    func verifyRejectsOddLengthHex() {
        #expect(!LANRemotePairingProof.verify(proofHex: "abc", code: "042317", fingerprintHex: "ab12", nonceHex: "cd34"))
    }

    @Test("verify() rejects non-hex characters")
    func verifyRejectsNonHex() {
        let notHex = String(repeating: "zz", count: 32)
        #expect(!LANRemotePairingProof.verify(proofHex: notHex, code: "042317", fingerprintHex: "ab12", nonceHex: "cd34"))
    }

    @Test("verify() rejects an empty proof")
    func verifyRejectsEmptyProof() {
        #expect(!LANRemotePairingProof.verify(proofHex: "", code: "042317", fingerprintHex: "ab12", nonceHex: "cd34"))
    }

    // MARK: - LANRemotePairingSecrets minting

    @Test("mintCode() is 6 digits, zero-padded, from the injected generator")
    func mintCodeZeroPadded() {
        #expect(LANRemotePairingSecrets.mintCode(random: { 7 }) == "000007")
        #expect(LANRemotePairingSecrets.mintCode(random: { 999_999 }) == "999999")
        #expect(LANRemotePairingSecrets.mintCode(random: { 0 }) == "000000")
    }

    @Test("mintToken() is 64 lowercase hex characters")
    func mintTokenShape() {
        let token = LANRemotePairingSecrets.mintToken()
        #expect(token.count == 64)
        #expect(token.allSatisfy { $0.isHexDigit && !$0.isUppercase })
    }

    @Test("mintNonce() is 32 lowercase hex characters")
    func mintNonceShape() {
        let nonce = LANRemotePairingSecrets.mintNonce()
        #expect(nonce.count == 32)
        #expect(nonce.allSatisfy { $0.isHexDigit && !$0.isUppercase })
    }

    @Test("Two mints of the same kind are never equal")
    func mintsAreNeverEqual() {
        #expect(LANRemotePairingSecrets.mintToken() != LANRemotePairingSecrets.mintToken())
        #expect(LANRemotePairingSecrets.mintNonce() != LANRemotePairingSecrets.mintNonce())
    }
}

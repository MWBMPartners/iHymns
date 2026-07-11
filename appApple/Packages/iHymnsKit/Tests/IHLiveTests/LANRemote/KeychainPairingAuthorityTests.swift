// KeychainPairingAuthorityTests.swift
// IHLiveTests
//
// ELI5: Proves the REAL trusted-remotes notebook actually behaves like a
// notebook — write a remote in, it's now recognized; forget it, it's not
// anymore; ask for the whole list, get everyone back with their names/
// kinds/paired-dates intact; and — the one that matters most — the raw
// secret token is NEVER what's sitting in the Keychain, only its hash.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §7.4). Gated on
// `lanRemoteKeychainIdentityAvailable()` — reused here (rather than a
// second identical-shaped gate function) purely as the "can this process
// actually touch the Keychain for real" probe; it happens to be phrased
// around `LANRemoteIdentity` generation, but the underlying limitation
// (unsigned headless `swift test` vs. a real Keychain) is the same one
// `KeychainLANRemotePairingAuthority`'s plain `SecItemAdd`/
// `SecItemCopyMatching` generic-password calls would ALSO hit on a truly
// keychain-less host — same skip message style as every other gated suite
// in this file's folder. Every test uses a UNIQUE `service` (a random
// suffix) so tests never see each other's leftover items, and cleans up
// via a `defer { await authority.revokePairedToken(token) }`.
import Foundation
import Security
import Testing
@testable import IHLive

@Suite(
    "KeychainLANRemotePairingAuthority",
    // `.serialized` — reduces (does not, on its own, fully eliminate:
    // Swift Testing still runs DIFFERENT `@Suite` types concurrently with
    // each other) the number of simultaneous real `Security.framework`
    // calls in flight, which empirically lowers the odds of the SAME rare
    // Keychain-daemon contention `LANRemoteIdentityStoreTests.swift`'s own
    // `.serialized` comment documents. This suite's unique-per-test
    // `service` string already makes serialization unnecessary for
    // CORRECTNESS (no two tests here ever touch the same item) — this is
    // purely a contention-reduction courtesy to its sibling suites.
    .serialized,
    .enabled(
        if: lanRemoteKeychainIdentityAvailable(),
        "Needs a real Keychain an unsigned headless `swift test` binary can't reliably exercise — see LANRemoteIdentityTests.swift's identical gate."
    )
)
struct KeychainPairingAuthorityTests {

    private func makeAuthority() -> KeychainLANRemotePairingAuthority {
        KeychainLANRemotePairingAuthority(service: "app.ihymns.lanremote.pairedRemotes.test.\(UUID().uuidString)")
    }

    @Test("registerPairedToken(_:) then isPairedToken(_:) recognizes it; an unknown token is not paired")
    func registerThenIsPaired() async throws {
        // `KeychainTestSerialization` (`LANRemoteTestSupport.swift`) — see
        // its doc comment: needed to stop THIS suite's Keychain calls from
        // overlapping `LANRemoteIdentityTests`'/`LANRemoteIdentityStoreTests`'
        // own, which is what actually caused #1421's observed flakiness
        // (a per-suite `.serialized` trait alone does not prevent that).
        try await KeychainTestSerialization.shared.run {
            let authority = makeAuthority()
            let token = "raw-token-\(UUID().uuidString)"
            defer { Task { await authority.revokePairedToken(token) } }

            await authority.registerPairedToken(token)
            #expect(await authority.isPairedToken(token))
            #expect(await !authority.isPairedToken("some-other-token"))
        }
    }

    @Test("revokePairedToken(_:) makes isPairedToken(_:) false again")
    func revokeByRawToken() async throws {
        try await KeychainTestSerialization.shared.run {
            let authority = makeAuthority()
            let token = "raw-token-\(UUID().uuidString)"

            await authority.registerPairedToken(token)
            #expect(await authority.isPairedToken(token))

            await authority.revokePairedToken(token)
            #expect(await !authority.isPairedToken(token))
        }
    }

    @Test("revoke(tokenHash:) — the hash-addressed path Settings actually has — also works")
    func revokeByHash() async throws {
        try await KeychainTestSerialization.shared.run {
            let authority = makeAuthority()
            let token = "raw-token-\(UUID().uuidString)"
            let hash = LANRemoteFingerprint.sha256Hex(Data(token.utf8))

            await authority.registerPairedToken(token)
            #expect(await authority.isPairedToken(token))

            await authority.revoke(tokenHash: hash)
            #expect(await !authority.isPairedToken(token))
        }
    }

    @Test("listPairedRemotes() round-trips metadata (name/kind/pairedAt) and sorts newest-first")
    func listRoundTripsMetadataNewestFirst() async throws {
        try await KeychainTestSerialization.shared.run {
            let authority = makeAuthority()
            let olderToken = "raw-token-older-\(UUID().uuidString)"
            let newerToken = "raw-token-newer-\(UUID().uuidString)"
            defer {
                Task {
                    await authority.revokePairedToken(olderToken)
                    await authority.revokePairedToken(newerToken)
                }
            }

            let olderDate = Date(timeIntervalSince1970: 1_700_000_000)
            let newerDate = Date(timeIntervalSince1970: 1_700_001_000)
            await authority.registerPairedToken(olderToken, metadata: LANRemotePairingMetadata(name: "Older Phone", kind: .phone, pairedAt: olderDate))
            await authority.registerPairedToken(newerToken, metadata: LANRemotePairingMetadata(name: "Newer iPad", kind: .pad, pairedAt: newerDate))

            let records = await authority.listPairedRemotes()
            let ours = records.filter { $0.metadata.name == "Older Phone" || $0.metadata.name == "Newer iPad" }
            #expect(ours.count == 2)
            #expect(ours.first?.metadata.name == "Newer iPad")
            #expect(ours.first?.metadata.kind == .pad)
            #expect(ours.first?.metadata.pairedAt == newerDate)
            #expect(ours.last?.metadata.name == "Older Phone")
        }
    }

    @Test("The raw token is NEVER stored — only sha256(token) is, as the Keychain item's account")
    func hashAtRestNeverRawToken() async throws {
        try await KeychainTestSerialization.shared.run {
            let authority = makeAuthority()
            let token = "raw-token-\(UUID().uuidString)"
            let expectedHash = LANRemoteFingerprint.sha256Hex(Data(token.utf8))
            defer { Task { await authority.revokePairedToken(token) } }

            await authority.registerPairedToken(token)

            let records = await authority.listPairedRemotes()
            #expect(records.contains { $0.tokenHashHex == expectedHash })
            #expect(!records.contains { $0.tokenHashHex == token })
        }
    }

    @Test("baseAttributes(account:) is synchronizable == false and accessible == afterFirstUnlock")
    func baseAttributesInvariants() async throws {
        try await KeychainTestSerialization.shared.run {
            let authority = makeAuthority()
            #expect(await authority.baseAttributesInvariantsHold(account: "some-hash"))
        }
    }
}

extension KeychainLANRemotePairingAuthority {
    /// Test-only: evaluates `baseAttributes(account:)`'s three invariants
    /// (plan §6.4's required unit test) WITHOUT ever letting the
    /// non-`Sendable` `[String: Any]` dictionary itself cross the actor
    /// boundary — only this `Bool` result does, which strict concurrency
    /// is happy with.
    fileprivate func baseAttributesInvariantsHold(account: String?) -> Bool {
        let attributes = baseAttributes(account: account)
        guard attributes[kSecAttrSynchronizable as String] as? Bool == false else { return false }
        guard (attributes[kSecAttrAccessible as String] as? String) == (kSecAttrAccessibleAfterFirstUnlock as String) else {
            return false
        }
        guard attributes[kSecClass as String] as? String == (kSecClassGenericPassword as String) else { return false }
        return true
    }
}

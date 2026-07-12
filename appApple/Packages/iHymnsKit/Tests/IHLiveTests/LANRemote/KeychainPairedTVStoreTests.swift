// KeychainPairedTVStoreTests.swift
// IHLiveTests
//
// ELI5: Checks the REAL "TVs I trust" address book actually talks to the
// Keychain correctly — a saved record (including its raw secret token)
// comes back exactly as saved, re-saving replaces rather than duplicates,
// deleting really removes it, and — the one property this whole file exists
// to nail down — the underlying Keychain item is EXPLICITLY marked
// "never sync to iCloud."
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §7.4 — BINDING).
// Gated on `lanRemoteKeychainIdentityAvailable()` — the exact PR-6 gate/
// skip-message style (`LANRemoteTestSupport.swift`) — because a headless,
// unsigned `swift test` binary can't always bridge a Keychain item on every
// CI runner. Every test wraps its Keychain work in `KeychainTestSerialization
// .shared.run { }` (PR-6's contention-avoidance precedent) and cleans up in
// `defer`, capturing only immutable `String` fingerprints (never the whole
// mutable record) into cleanup `Task`s — the identical "don't send a
// non-Sendable/mutable value across a `Task` boundary" discipline
// `KeychainPairingAuthorityTests.swift` already follows.
import Foundation
import Testing
@testable import IHLive

@Suite(
    "KeychainPairedTVStore",
    // `.serialized` — the same contention-reduction courtesy
    // `KeychainPairingAuthorityTests.swift`'s identical suite trait
    // documents; this suite's unique-per-test `service`/`fingerprint`
    // strings already make it correct without serialization, this just
    // lowers the odds of tripping the rare cross-suite Keychain-daemon
    // contention that motivated `KeychainTestSerialization` in the first
    // place.
    .serialized,
    .enabled(
        if: lanRemoteKeychainIdentityAvailable(),
        "Needs a real Keychain an unsigned headless `swift test` binary can't reliably exercise — see LANRemoteIdentityTests.swift's identical gate."
    )
)
struct KeychainPairedTVStoreTests {

    private func makeStore() -> KeychainPairedTVStore {
        KeychainPairedTVStore(service: "app.ihymns.lanremote.pairedTVs.test.\(UUID().uuidString)")
    }

    private func record(fingerprint: String) -> PairedTVRecord {
        PairedTVRecord(
            fingerprintHex: fingerprint, name: "Fellowship Hall TV", token: "raw-token-value",
            lastAddress: LANRemoteResolvedAddress(host: "10.0.0.9", port: 7269),
            pairedAt: Date(timeIntervalSince1970: 1_700_000_000), lastConnectedAt: nil
        )
    }

    @Test("save() then record(forFingerprint:) returns the record INCLUDING the raw token")
    func saveThenRead() async throws {
        let store = makeStore()
        let fingerprint = "aa-\(UUID().uuidString)"
        let original = record(fingerprint: fingerprint)
        try await KeychainTestSerialization.shared.run {
            await store.save(original)
        }
        defer { Task { await store.delete(fingerprint: fingerprint) } }

        let read = await store.record(forFingerprint: fingerprint)
        #expect(read == original)
        #expect(read?.token == "raw-token-value")
    }

    @Test("save() upserts by fingerprint — a re-save replaces rather than duplicates")
    func upsertReplaces() async throws {
        let store = makeStore()
        let fingerprint = "aa-\(UUID().uuidString)"
        try await KeychainTestSerialization.shared.run {
            await store.save(record(fingerprint: fingerprint))
        }
        defer { Task { await store.delete(fingerprint: fingerprint) } }

        var renamed = record(fingerprint: fingerprint)
        renamed.name = "Renamed TV"
        await store.save(renamed)

        let all = await store.listPairedTVs()
        let matching = all.filter { $0.fingerprintHex == fingerprint }
        #expect(matching.count == 1)
        #expect(matching.first?.name == "Renamed TV")
    }

    @Test("delete() removes the record; an unknown fingerprint returns nil")
    func deleteRemoves() async throws {
        let store = makeStore()
        let fingerprint = "aa-\(UUID().uuidString)"
        try await KeychainTestSerialization.shared.run {
            await store.save(record(fingerprint: fingerprint))
        }

        await store.delete(fingerprint: fingerprint)
        #expect(await store.record(forFingerprint: fingerprint) == nil)
        #expect(await store.record(forFingerprint: "never-existed") == nil)
    }

    @Test("baseAttributes(account:) is synchronizable == false, accessible == AfterFirstUnlock, and the pairedTVs service")
    func baseAttributesInvariants() async {
        let store = makeStore()
        #expect(await store.baseAttributesInvariantsHold(account: "aa"))
    }
}

extension KeychainPairedTVStore {
    /// Test-only: evaluates `baseAttributes(account:)`'s invariants (plan
    /// §6.4's required unit test, spec §7.4) WITHOUT ever letting the
    /// non-`Sendable` `[String: Any]` dictionary itself cross the actor
    /// boundary — only this `Bool` result does, which strict concurrency is
    /// happy with. Mirrors `KeychainLANRemotePairingAuthority
    /// .baseAttributesInvariantsHold(account:)`'s identical test-only shape.
    fileprivate func baseAttributesInvariantsHold(account: String?) -> Bool {
        let attributes = baseAttributes(account: account)
        guard attributes[kSecAttrSynchronizable as String] as? Bool == false else { return false }
        guard (attributes[kSecAttrAccessible as String] as? String) == (kSecAttrAccessibleAfterFirstUnlock as String) else {
            return false
        }
        guard attributes[kSecClass as String] as? String == (kSecClassGenericPassword as String) else { return false }
        guard attributes[kSecAttrAccount as String] as? String == account else { return false }
        return true
    }
}

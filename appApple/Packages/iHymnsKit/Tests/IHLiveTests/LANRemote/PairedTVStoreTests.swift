// PairedTVStoreTests.swift
// IHLiveTests
//
// ELI5: Checks the pretend "TVs I trust" address book — saving the same TV
// twice replaces its entry instead of creating a duplicate, the list comes
// back newest-first, deleting actually removes it, and a record survives a
// JSON round-trip (dates included) without losing anything.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §7.2 — BINDING).
// Always-on, exercises `InMemoryPairedTVStore` (never the real Keychain
// store — that's `KeychainPairedTVStoreTests`, gated on Keychain
// availability).
import Foundation
import Testing
@testable import IHLive

@Suite("PairedTVStore")
struct PairedTVStoreTests {

    private func record(
        fingerprint: String, name: String = "TV", pairedAt: Date = Date(timeIntervalSince1970: 1_700_000_000),
        lastConnectedAt: Date? = nil
    ) -> PairedTVRecord {
        PairedTVRecord(fingerprintHex: fingerprint, name: name, token: "token-\(fingerprint)", lastAddress: nil, pairedAt: pairedAt, lastConnectedAt: lastConnectedAt)
    }

    @Test("save() upserts by fingerprint — a re-save replaces, the count stays 1")
    func saveUpsertsByFingerprint() async {
        let store = InMemoryPairedTVStore()
        await store.save(record(fingerprint: "aa", name: "First Name"))
        await store.save(record(fingerprint: "aa", name: "Renamed"))

        let all = await store.listPairedTVs()
        #expect(all.count == 1)
        #expect(all.first?.name == "Renamed")
    }

    @Test("listPairedTVs() orders newest-first by lastConnectedAt, falling back to pairedAt")
    func listOrdering() async {
        let store = InMemoryPairedTVStore()
        await store.save(record(fingerprint: "old", pairedAt: Date(timeIntervalSince1970: 1_000), lastConnectedAt: nil))
        await store.save(record(fingerprint: "recent", pairedAt: Date(timeIntervalSince1970: 1_000), lastConnectedAt: Date(timeIntervalSince1970: 5_000)))
        await store.save(record(fingerprint: "middle", pairedAt: Date(timeIntervalSince1970: 3_000), lastConnectedAt: nil))

        let all = await store.listPairedTVs()
        #expect(all.map(\.fingerprintHex) == ["recent", "middle", "old"])
    }

    @Test("delete() removes the record; record(forFingerprint:) returns nil for an unknown fingerprint")
    func deleteRemovesRecord() async {
        let store = InMemoryPairedTVStore()
        await store.save(record(fingerprint: "aa"))
        #expect(await store.record(forFingerprint: "aa") != nil)

        await store.delete(fingerprint: "aa")
        #expect(await store.record(forFingerprint: "aa") == nil)
        #expect(await store.record(forFingerprint: "never-existed") == nil)
    }

    @Test("PairedTVRecord round-trips through JSON with .iso8601 dates, including a resolved address")
    func jsonRoundTrip() throws {
        let original = PairedTVRecord(
            fingerprintHex: "aa", name: "Fellowship Hall TV", token: "raw-token-value",
            lastAddress: LANRemoteResolvedAddress(host: "10.0.0.9", port: 7269),
            pairedAt: Date(timeIntervalSince1970: 1_700_000_000),
            lastConnectedAt: Date(timeIntervalSince1970: 1_700_000_500)
        )

        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .iso8601
        let data = try encoder.encode(original)

        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
        let decoded = try decoder.decode(PairedTVRecord.self, from: data)

        #expect(decoded == original)
    }
}

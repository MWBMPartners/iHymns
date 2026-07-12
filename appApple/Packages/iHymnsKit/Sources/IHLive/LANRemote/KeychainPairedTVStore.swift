// KeychainPairedTVStore.swift
// IHLive/LANRemote
//
// ELI5: The REAL "TVs I trust" address book — one entry per paired TV,
// written to the Keychain (the same secure vault iOS itself uses for Wi-Fi
// passwords) so it survives an app relaunch or a device reboot. Unlike the
// TV's own address book (which only ever remembers a HASH of each remote's
// token), this one has to hold the ACTUAL secret token, because a remote has
// to hand that exact value back to the TV to reconnect without repeating the
// code-typing dance.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §5.2 — the
// `KeychainLANRemotePairingAuthority` idiom, adapted for the OPPOSITE side of
// the relationship: keyed by the TV's fingerprint, one item's `kSecValueData`
// holds the WHOLE `PairedTVRecord` — including the raw token — rather than a
// hash). One `kSecClassGenericPassword` item PER paired TV:
//   - `kSecAttrService = "app.ihymns.lanremote.pairedTVs"` — a NEW private
//     namespace, never shared with the TV-side authority's own
//     `"app.ihymns.lanremote.pairedRemotes"` service string.
//   - `kSecAttrAccount = fingerprintHex` — the record key (see
//     `PairedTVStore.swift`'s header for why fingerprint, not token).
//   - `kSecValueData = try JSONEncoder().encode(record)` — dates via
//     `.iso8601` for dump-stability across Keychain backup/restore; the
//     record INCLUDES the raw token (one item, one query surface — PR-6
//     Decision D-6 applied here too: no metadata sidecar to desync).
//
// **`kSecAttrSynchronizable = false` — EXPLICIT, HARD-CODED, never
// injectable.** This is the single most load-bearing character in this file
// (spec §5.2). Contrast with `KeychainTokenStore`'s (`IHAuth`) deliberately
// INJECTABLE `synchronizable` (`true` by default there — iCloud-Keychain
// propagation IS the account token's intended global-login transport,
// strategy §1.6). A LAN-remote pairing is a PHYSICAL-PROXIMITY trust
// ceremony (the person had to be standing in front of the TV to read its
// code): iCloud-syncing this item would silently hand every OTHER device on
// the same Apple ID control of a TV that device never paired with, breaking
// that whole model — and unlike the tvOS side (no iCloud Keychain at all),
// every platform THIS store runs on (iOS/iPadOS/macOS/visionOS) very much
// HAS iCloud Keychain, so the hard-coded `false` here is not a no-op, it is
// the thing standing between "physical proximity" and "same Apple ID."
// `KeychainPairedTVStoreTests` asserts this directly against
// `baseAttributes(account:)`; the PR's grep gate re-checks it at review
// (`grep -rn "Synchronizable" .../LANRemote/` must show ONLY hard-coded
// `false`).
import Foundation
import IHLog
import Security

/// The real, Keychain-backed `PairedTVStoring` — see this file's header for
/// the exact `SecItem` shape.
///
/// ELI5: The actual, persistent "TVs I trust" address book (as opposed to
/// `InMemoryPairedTVStore`, the pretend one tests/previews use).
///
/// DETAILED: An `actor` — the `KeychainLANRemotePairingAuthority`/
/// `KeychainTokenStore` precedent: every `SecItem*` call is itself
/// synchronous+thread-safe at the OS level, but isolating this type still
/// gives every call site a single, uniform `await`-based API matching
/// `PairedTVStoring`'s `async` requirements.
public actor KeychainPairedTVStore: PairedTVStoring {
    /// `kSecAttrService` — a private per-app namespace distinct from the
    /// TV-side authority's own service string (this file's header).
    private let service: String

    public init(service: String = "app.ihymns.lanremote.pairedTVs") {
        self.service = service
    }

    public func save(_ record: PairedTVRecord) async {
        // Replace semantics — delete any existing item under this
        // fingerprint first (`KeychainTokenStore.save(_:)`'s identical
        // precedent), then add the fresh one.
        SecItemDelete(baseAttributes(account: record.fingerprintHex) as CFDictionary)

        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .iso8601
        guard let payload = try? encoder.encode(record) else {
            IHLog.remote.fault("lanremote.pairedtvstore persist-failed reason=encode")
            return
        }

        var query = baseAttributes(account: record.fingerprintHex)
        query[kSecValueData as String] = payload
        let status = SecItemAdd(query as CFDictionary, nil)
        guard status == errSecSuccess else {
            // `.fault` (spec §5.2): the live session keeps working (it's
            // already `.controlling` in-memory by the time this is called),
            // but the pairing won't survive a relaunch — an alarm-level
            // condition worth surfacing, not a silent best-effort failure.
            IHLog.remote.fault("lanremote.pairedtvstore persist-failed status=\(status, privacy: .public)")
            return
        }
    }

    public func record(forFingerprint fingerprint: String) async -> PairedTVRecord? {
        var query = baseAttributes(account: fingerprint)
        query[kSecMatchLimit as String] = kSecMatchLimitOne
        query[kSecReturnData as String] = true

        var result: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        guard status == errSecSuccess, let data = result as? Data else {
            // `errSecItemNotFound` is the expected "never paired"/"forgotten"
            // case; any OTHER status also degrades to `nil` — fail-closed
            // (spec §5.2: "a TV we can't prove we trust is a TV we don't
            // list"), logged only when it's NOT the ordinary not-found case.
            if status != errSecItemNotFound {
                IHLog.remote.error("lanremote.pairedtvstore read-failed status=\(status, privacy: .public)")
            }
            return nil
        }
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
        return try? decoder.decode(PairedTVRecord.self, from: data)
    }

    /// Every paired TV, newest-first by `lastConnectedAt` (falling back to
    /// `pairedAt`).
    ///
    /// DETAILED: **Two queries, not one** — `SecItemCopyMatching` returns
    /// `errSecParam` when `kSecReturnData` is combined with
    /// `kSecMatchLimitAll` for a generic-password class (the same empirically
    /// verified constraint `KeychainLANRemotePairingAuthority
    /// .listPairedRemotes()`'s doc comment documents). Step 1 lists every
    /// matching item's ATTRIBUTES ONLY to get each account (fingerprint);
    /// step 2 fetches each one's full record via `record(forFingerprint:)`
    /// above. A handful of paired TVs makes N+1 Keychain round-trips a
    /// non-issue in practice.
    public func listPairedTVs() async -> [PairedTVRecord] {
        var listQuery = baseAttributes(account: nil)
        listQuery[kSecMatchLimit as String] = kSecMatchLimitAll
        listQuery[kSecReturnAttributes as String] = true

        var listResult: CFTypeRef?
        let listStatus = SecItemCopyMatching(listQuery as CFDictionary, &listResult)
        guard listStatus == errSecSuccess, let items = listResult as? [[String: Any]] else {
            return []
        }

        var records: [PairedTVRecord] = []
        for item in items {
            guard let fingerprint = item[kSecAttrAccount as String] as? String,
                  let record = await record(forFingerprint: fingerprint) else { continue }
            records.append(record)
        }
        return records.sorted { lhs, rhs in
            (lhs.lastConnectedAt ?? lhs.pairedAt) > (rhs.lastConnectedAt ?? rhs.pairedAt)
        }
    }

    public func delete(fingerprint: String) async {
        SecItemDelete(baseAttributes(account: fingerprint) as CFDictionary)
    }

    /// The base Keychain attribute dictionary every call above shares —
    /// `internal` (not `private`, spec §5.2/§7.4) so `KeychainPairedTVStoreTests`
    /// can assert the `kSecAttrSynchronizable == false` / accessible /
    /// service invariants DIRECTLY, the required unit test plan calls for.
    func baseAttributes(account: String?) -> [String: Any] {
        var query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            // EXPLICIT, hard-coded `false` — see this file's header. NEVER
            // make this injectable or reference an external `synchronizable`
            // parameter.
            kSecAttrSynchronizable as String: false,
            // Reconnect must work right after a reboot, before the user has
            // unlocked the device a second time — the `KeychainTokenStore`/
            // `KeychainLANRemotePairingAuthority` precedent.
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlock
        ]
        if let account {
            query[kSecAttrAccount as String] = account
        }
        return query
    }
}

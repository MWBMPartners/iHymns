// KeychainLANRemotePairingAuthority.swift
// IHLive/LANRemote
//
// ELI5: The REAL "remember which remotes we trust" notebook — instead of
// forgetting everything when the app quits (`InMemoryLANRemotePairingAuthority`,
// PR-4's placeholder), this one writes each paired remote into the
// Keychain, so the TV still remembers them after a relaunch or a reboot.
// It never stores the remote's actual secret token — only its SHA-256
// hash — so even someone with access to a Keychain backup can't recover a
// working credential from it.
//
// DETAILED: #1421 (`.claude/apple-phase2-pr6-spec.md` §5.2 — the
// `KeychainTokenStore` idiom, adapted for a LIST rather than a single
// item). One `kSecClassGenericPassword` item PER paired remote:
// `kSecAttrAccount` is the hash (never the raw token — "the account IS the
// hash," this repo's #1421 spec quote), `kSecValueData` is the
// JSON-encoded, NON-secret `LANRemotePairingMetadata` (device name/kind/
// paired-at) so list+revoke stay a SINGLE Keychain query surface instead
// of a parallel store that could desync (spec Decision D-6).
//
// **`kSecAttrSynchronizable = false` is EXPLICIT and HARD-CODED here — a
// deliberate contrast with `KeychainTokenStore`'s `synchronizable`
// property, which IS injectable** (that type's own header: `true` is the
// iCloud-Keychain global-login TRANSPORT on phone/iPad/Mac). An
// iCloud-synced pairing trust would silently break the proximity model
// this whole ceremony is built on (plan §6.4's tripwire) — tvOS has no
// iCloud Keychain anyway, but making the `false` un-overridable here means
// that stays true even if this type is ever reused from a platform that
// DOES have iCloud Keychain. `KeychainPairingAuthorityTests` asserts this
// directly against `baseAttributes(account:)`.
import Foundation
import IHLog
import Security

/// Non-secret facts about a paired remote — a device name, its kind (for
/// the Settings list's icon), and when it paired.
///
/// ELI5: "Which remote is this, what kind of device is it, and when did it
/// pair?"
public struct LANRemotePairingMetadata: Sendable, Codable, Equatable {
    public var name: String?
    public var kind: IHRPRemoteKind?
    public var pairedAt: Date

    public init(name: String? = nil, kind: IHRPRemoteKind? = nil, pairedAt: Date) {
        self.name = name
        self.kind = kind
        self.pairedAt = pairedAt
    }
}

/// One row in the trusted-remotes Settings list — `id` is the token's hash
/// (never the raw token, which this authority never even holds in memory
/// longer than one function call).
///
/// ELI5: "One entry in the 'devices that can control this TV' list."
public struct PairedRemoteRecord: Sendable, Equatable, Identifiable {
    public let tokenHashHex: String
    public let metadata: LANRemotePairingMetadata
    public var id: String { tokenHashHex }

    public init(tokenHashHex: String, metadata: LANRemotePairingMetadata) {
        self.tokenHashHex = tokenHashHex
        self.metadata = metadata
    }
}

/// The real, Keychain-backed `LANRemotePairingAuthority` — see this file's
/// header for the exact `SecItem` shape.
///
/// ELI5: The actual, persistent "remotes we trust" notebook (as opposed to
/// `InMemoryLANRemotePairingAuthority`, the pretend one tests use).
///
/// DETAILED: An `actor` — the `KeychainTokenStore` precedent (`IHAuth`):
/// every `SecItem*` call is itself synchronous+thread-safe at the OS
/// level, but isolating this type still gives every call site a single,
/// uniform `await`-based API matching the `LANRemotePairingAuthority`
/// protocol's `async` requirements.
public actor KeychainLANRemotePairingAuthority: LANRemotePairingAuthority {
    /// `kSecAttrService` — a private per-app namespace, the
    /// `"app.ihymns.token"` convention (`KeychainTokenStore`) applied to
    /// this NEW credential class.
    private let service: String

    public init(service: String = "app.ihymns.lanremote.pairedRemotes") {
        self.service = service
    }

    public func isPairedToken(_ token: String) async -> Bool {
        var query = baseAttributes(account: hashOf(token))
        query[kSecMatchLimit as String] = kSecMatchLimitOne
        var result: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        switch status {
        case errSecSuccess:
            return true
        case errSecItemNotFound:
            return false
        default:
            // Fail CLOSED for a trust decision (spec §5.2) — an
            // unexpected Keychain error must never be silently treated as
            // "this remote is trusted."
            IHLog.remote.error("lanremote.authority read-failed status=\(status, privacy: .public)")
            return false
        }
    }

    public func registerPairedToken(_ token: String) async {
        await registerPairedToken(token, metadata: LANRemotePairingMetadata(pairedAt: Date()))
    }

    public func registerPairedToken(_ token: String, metadata: LANRemotePairingMetadata) async {
        let account = hashOf(token)
        // Replace semantics — delete any existing item under this hash
        // first (`KeychainTokenStore.save(_:)`'s identical precedent),
        // then add the fresh one.
        SecItemDelete(baseAttributes(account: account) as CFDictionary)

        guard let payload = try? JSONEncoder().encode(metadata) else {
            IHLog.remote.fault("lanremote.authority persist-failed reason=encode")
            return
        }
        var query = baseAttributes(account: account)
        query[kSecValueData as String] = payload
        let status = SecItemAdd(query as CFDictionary, nil)
        guard status == errSecSuccess else {
            // `.fault` (spec §5.2): the in-flight session still works
            // (the connection's phase is already `.paired` in-memory by
            // the time this is called), but the pairing won't survive a
            // relaunch — an alarm-level condition worth surfacing, not a
            // silent best-effort failure.
            IHLog.remote.fault("lanremote.authority persist-failed status=\(status, privacy: .public)")
            return
        }
    }

    public func revokePairedToken(_ token: String) async {
        await revoke(tokenHash: hashOf(token))
    }

    /// Revokes by HASH directly — what the trusted-remotes Settings list
    /// has on hand (`PairedRemoteRecord.tokenHashHex`); it never sees the
    /// raw token at all.
    ///
    /// ELI5: "Forget this remote — I already know its hash, not its secret
    /// token."
    public func revoke(tokenHash: String) async {
        SecItemDelete(baseAttributes(account: tokenHash) as CFDictionary)
    }

    /// Every currently-paired remote, newest-first by `pairedAt`.
    ///
    /// ELI5: "List every remote this TV currently trusts."
    ///
    /// DETAILED: **Two queries per remote, not one** — `SecItemCopyMatching`
    /// returns `errSecParam` when `kSecReturnData` is combined with
    /// `kSecMatchLimitAll` for a generic-password class (empirically
    /// verified against this exact query shape; not clearly called out in
    /// Apple's own `SecItemCopyMatching` documentation). Step 1 lists every
    /// matching item's ATTRIBUTES ONLY (`kSecMatchLimitAll` +
    /// `kSecReturnAttributes`, no `kSecReturnData`) to get each account
    /// (hash); step 2 fetches each one's `kSecValueData` individually
    /// (`kSecMatchLimitOne` + `kSecReturnData`, the same shape
    /// `isPairedToken(_:)` already uses successfully). A handful of paired
    /// remotes makes N+1 Keychain round-trips a non-issue in practice.
    public func listPairedRemotes() async -> [PairedRemoteRecord] {
        var listQuery = baseAttributes(account: nil)
        listQuery[kSecMatchLimit as String] = kSecMatchLimitAll
        listQuery[kSecReturnAttributes as String] = true

        var listResult: CFTypeRef?
        let listStatus = SecItemCopyMatching(listQuery as CFDictionary, &listResult)
        guard listStatus == errSecSuccess, let items = listResult as? [[String: Any]] else {
            return []
        }

        let records: [PairedRemoteRecord] = items.compactMap { item in
            guard let hash = item[kSecAttrAccount as String] as? String else { return nil }
            return PairedRemoteRecord(tokenHashHex: hash, metadata: metadata(forAccount: hash))
        }
        return records.sorted { $0.metadata.pairedAt > $1.metadata.pairedAt }
    }

    /// Fetches ONE item's decoded `LANRemotePairingMetadata` by its account
    /// (hash) — a row whose data can't be read/decoded still lists with
    /// PLACEHOLDER metadata rather than silently vanishing (spec §5.2: "a
    /// trust entry must never be invisible").
    private func metadata(forAccount account: String) -> LANRemotePairingMetadata {
        var query = baseAttributes(account: account)
        query[kSecMatchLimit as String] = kSecMatchLimitOne
        query[kSecReturnData as String] = true

        var result: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        guard status == errSecSuccess, let data = result as? Data,
              let metadata = try? JSONDecoder().decode(LANRemotePairingMetadata.self, from: data) else {
            return LANRemotePairingMetadata(pairedAt: .distantPast)
        }
        return metadata
    }

    private func hashOf(_ token: String) -> String {
        LANRemoteFingerprint.sha256Hex(Data(token.utf8))
    }

    /// The base Keychain attribute dictionary every call above shares —
    /// `internal` (not `private`, spec §5.2) so `KeychainPairingAuthorityTests`
    /// can assert the `kSecAttrSynchronizable == false` / accessible /
    /// service invariants DIRECTLY, the required unit test plan §6.4 calls
    /// for.
    func baseAttributes(account: String?) -> [String: Any] {
        var query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            // EXPLICIT, hard-coded `false` — see this file's header.
            kSecAttrSynchronizable as String: false,
            // `KeychainTokenStore` precedent — the listener must be able
            // to authorise reconnects right after a reboot.
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlock
        ]
        if let account {
            query[kSecAttrAccount as String] = account
        }
        return query
    }
}

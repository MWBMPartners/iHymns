// PairedTVStore.swift
// IHLive/LANRemote
//
// ELI5: The remote's own little address book of "TVs I've already paired
// with" — for each one, the secret token that lets us skip the code-typing
// dance next time, its name, the last address that actually worked, and
// when we last talked to it. This file just defines the SHAPE of that
// address book plus a pretend, forgetful version for tests/previews; the
// real, persistent one (backed by the Keychain) is `KeychainPairedTVStore.swift`.
//
// DETAILED: #1422 (`.claude/apple-phase2-pr7-spec.md` §5.1 — BINDING shape).
// Mirrors PR-6's `PairedRemoteRecord`/`LANRemotePairingAuthority`
// split (`LANRemotePairingAuthority.swift`'s header: "a small, swappable
// object... so a TEST can answer with an in-memory list") — same idea,
// opposite side of the pairing relationship: the TV keys its trust list by
// TOKEN HASH (never the raw token); the remote keys ITS list by the TV's
// FINGERPRINT (never the token) because the fingerprint is the TV's stable,
// persistent identity (`LANRemoteIdentityStore`, PR-6 §5.1) while the token
// ROTATES on every re-pair — keying by fingerprint makes re-pairing a clean
// UPSERT instead of a duplicate row, and "look up by fingerprint" IS the
// reconnect path (`RemotePairingEntryResolver.swift`).
//
// **Why the RAW token is stored here (unlike the TV's sha256-only rule):**
// asymmetric custody is the whole design (strategy §2.4.3: "TV stores
// `sha256`... remote stores raw + pinned cert") — the remote must present
// the PREIMAGE in `hello` to reconnect, so it has no choice but to hold the
// real value. The raw value only ever lives inside a Keychain item
// (`KeychainPairedTVStore.swift`'s `synchronizable: false`, hard-coded); this
// file's `InMemoryPairedTVStore` is tests/previews only, never production.
import Foundation

/// One TV this device has successfully paired with.
///
/// ELI5: "Here's a TV I already trust, and everything I need to reconnect to
/// it without going through the code dance again."
public struct PairedTVRecord: Sendable, Codable, Equatable, Identifiable {
    /// The TV's STABLE identity — the natural primary key. Never the token,
    /// which rotates on every re-pair (this file's header).
    public let fingerprintHex: String

    /// Cosmetic — from the scanned/pasted payload's `name`, or the TV's own
    /// advertised Bonjour name; may go stale if the TV is renamed, which is
    /// harmless (it's display-only).
    public var name: String

    /// The RAW pairing token — the preimage this device must present in a
    /// reconnect `hello`. See this file's header for why the remote (unlike
    /// the TV) has no choice but to hold the real value.
    public var token: String

    /// The most recent address that actually worked for this TV — seeds the
    /// reconnect ladder's fallback endpoint (`RemoteControlSession
    /// +Link.swift`) and PR-8's future manual-connect-by-address rung.
    public var lastAddress: LANRemoteResolvedAddress?

    public var pairedAt: Date
    public var lastConnectedAt: Date?

    public var id: String { fingerprintHex }

    public init(
        fingerprintHex: String,
        name: String,
        token: String,
        lastAddress: LANRemoteResolvedAddress? = nil,
        pairedAt: Date,
        lastConnectedAt: Date? = nil
    ) {
        self.fingerprintHex = fingerprintHex
        self.name = name
        self.token = token
        self.lastAddress = lastAddress
        self.pairedAt = pairedAt
        self.lastConnectedAt = lastConnectedAt
    }
}

/// The remote-side paired-TV persistence boundary — swappable between the
/// real Keychain-backed store (`KeychainPairedTVStore`) and an in-memory
/// double (`InMemoryPairedTVStore`, below).
///
/// ELI5: "Remember this TV," "what do I know about this TV," "list every TV
/// I trust," "forget this TV."
public protocol PairedTVStoring: Sendable {
    /// Upserts `record` by `fingerprintHex` — a re-pair of an already-known
    /// TV replaces its row rather than duplicating it.
    func save(_ record: PairedTVRecord) async

    /// The saved record for `fingerprint`, or `nil` if this TV has never
    /// been paired (or was forgotten).
    func record(forFingerprint fingerprint: String) async -> PairedTVRecord?

    /// Every paired TV, ordered newest-first by `lastConnectedAt` (falling
    /// back to `pairedAt` for a TV that's never been reconnected to since).
    func listPairedTVs() async -> [PairedTVRecord]

    /// "Forget this TV" — removes its record. Does NOT reach out to the TV
    /// itself (that half — the TV forgetting US — is the TV's own
    /// trusted-remotes "Revoke," a separate device); the coordinator's
    /// caller is responsible for the honest "the TV still trusts you until
    /// you revoke it there too" copy (spec §5.2).
    func delete(fingerprint: String) async
}

/// A simple, non-persistent `PairedTVStoring` — what tests and SwiftUI
/// previews use; NEVER wired into the real app (that's
/// `KeychainPairedTVStore`). Mirrors `InMemoryLANRemotePairingAuthority`'s
/// identical "throwaway in-memory double, same shape as the real store"
/// role on the TV side.
///
/// ELI5: A pretend address book that forgets everything when the app quits.
public actor InMemoryPairedTVStore: PairedTVStoring {
    private var records: [String: PairedTVRecord] = [:]

    public init() {}

    public func save(_ record: PairedTVRecord) async {
        records[record.fingerprintHex] = record
    }

    public func record(forFingerprint fingerprint: String) async -> PairedTVRecord? {
        records[fingerprint]
    }

    public func listPairedTVs() async -> [PairedTVRecord] {
        records.values.sorted { lhs, rhs in
            (lhs.lastConnectedAt ?? lhs.pairedAt) > (rhs.lastConnectedAt ?? rhs.pairedAt)
        }
    }

    public func delete(fingerprint: String) async {
        records.removeValue(forKey: fingerprint)
    }
}

// DeviceSignInState.swift
// IHAuth
//
// ELI5: This device's little notebook about its OWN sign-in: "I was signed
// out here, so do not sign yourself back in", "I already swapped that old
// shared sign-in", and "that automatic sign-in key is dead, stop trying it".
//
// DETAILED: PR 3b step I2a (#2180), design rule 5 (signing out) and rule 6
// (leaving the shared token). It lives in a THIS-DEVICE-ONLY Keychain item
// (`app.ihymns.device-state`), the same custody as the device token itself,
// because it answers "what happened on THIS device": a restored backup or a
// second device must not inherit another device's "signed out here" mark or
// its list of dead keys.
//
// What it holds:
//   - `signedOutHere`: set whenever this device is signed out (by the person,
//     or because the server rejected its token). It stops the app signing
//     itself back in from the shared token or the automatic sign-in key, so a
//     lost device, a device signed out from the web, and a device whose 30
//     days ran out all stay signed out until a PERSON signs in again (owner
//     decision B, 2026-11-23). Only a manual sign-in clears it.
//   - `swappedLegacyHashes`: fingerprints of shared older-app tokens this
//     device has already swapped, so it does not try the same one every launch.
//   - `deadKeyHashes`: fingerprints of automatic sign-in keys the server
//     refused, so a dead key is not retried on every launch.
// Only a SHA-256 FINGERPRINT of a token or key is kept, never the secret
// itself: the notebook must not become a second place a secret can leak from.
// Each list is capped at 10 (oldest dropped) so the item can never grow
// without limit.
import Foundation
#if canImport(CryptoKit)
import CryptoKit
#endif

/// Turns a token or key into a short, safe fingerprint for the notebook.
///
/// ELI5: A one-way scramble: the same token always gives the same fingerprint,
/// but the fingerprint cannot be turned back into the token.
public enum TokenFingerprint {
    /// Lowercase hex SHA-256 of `secret`'s UTF-8 bytes.
    public static func sha256Hex(_ secret: String) -> String {
        #if canImport(CryptoKit)
        return SHA256.hash(data: Data(secret.utf8)).map { String(format: "%02x", $0) }.joined()
        #else
        return String(secret.utf8.count) // unreachable on Apple platforms; keeps a non-Apple build compiling
        #endif
    }
}

/// What the notebook holds. A plain value so it can be encoded to the one
/// Keychain string and compared in tests.
public struct DeviceSignInRecord: Codable, Sendable, Equatable {
    public var signedOutHere: Bool
    public var swappedLegacyHashes: [String]
    public var deadKeyHashes: [String]
    /// Whether the person turned "sign in automatically on my other Apple
    /// devices" OFF on this device. When true, a manual sign-in does not make
    /// a new automatic sign-in key. Added in I2c; an older stored notebook
    /// without it reads as `false`.
    public var automaticSignInOff: Bool

    public init(
        signedOutHere: Bool = false,
        swappedLegacyHashes: [String] = [],
        deadKeyHashes: [String] = [],
        automaticSignInOff: Bool = false
    ) {
        self.signedOutHere = signedOutHere
        self.swappedLegacyHashes = swappedLegacyHashes
        self.deadKeyHashes = deadKeyHashes
        self.automaticSignInOff = automaticSignInOff
    }

    /// Hand-written so a notebook saved before `automaticSignInOff` existed
    /// still decodes (a synthesized decoder would throw on the missing key and
    /// the whole notebook would read as empty, forgetting "signed out here").
    public init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        self.signedOutHere = try container.decodeIfPresent(Bool.self, forKey: .signedOutHere) ?? false
        self.swappedLegacyHashes = try container.decodeIfPresent([String].self, forKey: .swappedLegacyHashes) ?? []
        self.deadKeyHashes = try container.decodeIfPresent([String].self, forKey: .deadKeyHashes) ?? []
        self.automaticSignInOff = try container.decodeIfPresent(Bool.self, forKey: .automaticSignInOff) ?? false
    }
}

/// The notebook itself, over any `TokenStoring` slot (a this-device-only
/// Keychain item in the app, an in-memory one in tests). An actor so two
/// quick updates can never overwrite each other.
public actor DeviceSignInState {
    /// How many fingerprints each list keeps.
    static let listLimit = 10

    private let store: any TokenStoring

    public init(store: any TokenStoring) {
        self.store = store
    }

    /// The current notebook. An empty or unreadable value reads as a fresh,
    /// empty notebook (never an error: a missing notebook just means "nothing
    /// has happened here yet").
    public func record() async -> DeviceSignInRecord {
        guard
            let text = try? await store.load(),
            let data = text.data(using: .utf8),
            let decoded = try? JSONDecoder().decode(DeviceSignInRecord.self, from: data)
        else { return DeviceSignInRecord() }
        return decoded
    }

    /// Marks this device as signed out here (`true`) or clears the mark
    /// (`false`, only ever from a manual sign-in).
    public func setSignedOutHere(_ value: Bool) async {
        var current = await record()
        current.signedOutHere = value
        await write(current)
    }

    /// Records the person's choice about automatic sign-in on this device.
    public func setAutomaticSignInOff(_ value: Bool) async {
        var current = await record()
        current.automaticSignInOff = value
        await write(current)
    }

    /// Remembers that the shared token with this fingerprint was swapped.
    public func markLegacySwapped(hash: String) async {
        var current = await record()
        current.swappedLegacyHashes = Self.appending(hash, to: current.swappedLegacyHashes)
        await write(current)
    }

    /// Remembers that the automatic sign-in key with this fingerprint is dead.
    public func markKeyDead(hash: String) async {
        var current = await record()
        current.deadKeyHashes = Self.appending(hash, to: current.deadKeyHashes)
        await write(current)
    }

    public func isLegacySwapped(hash: String) async -> Bool {
        await record().swappedLegacyHashes.contains(hash)
    }

    public func isKeyDead(hash: String) async -> Bool {
        await record().deadKeyHashes.contains(hash)
    }

    /// Adds `hash` at the end (no duplicates), dropping the oldest beyond the limit.
    nonisolated static func appending(_ hash: String, to list: [String]) -> [String] {
        var next = list.filter { $0 != hash }
        next.append(hash)
        if next.count > listLimit { next.removeFirst(next.count - listLimit) }
        return next
    }

    /// Saves the notebook. A failed write is swallowed on purpose: losing a
    /// note means a harmless retry next launch, whereas throwing here would
    /// make a sign-out or a swap fail over bookkeeping.
    private func write(_ value: DeviceSignInRecord) async {
        guard
            let data = try? JSONEncoder().encode(value),
            let text = String(data: data, encoding: .utf8)
        else { return }
        try? await store.save(text)
    }
}

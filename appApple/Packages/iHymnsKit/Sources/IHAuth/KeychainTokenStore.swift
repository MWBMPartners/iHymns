// KeychainTokenStore.swift
// IHAuth
//
// ELI5: The real, on-device safe for the login token — built on Apple's
// Keychain, the same secure storage iOS itself uses for Wi-Fi passwords.
//
// DETAILED: Implements the exact custody rules from strategy §1.6/§3.2:
// `kSecClassGenericPassword`, service `app.ihymns.token`,
// `kSecAttrAccessibleAfterFirstUnlock` (readable once the device has been
// unlocked once since boot — needed for background tasks/widgets, but
// still requires a passcode to have ever been set), never UserDefaults,
// never logged. `kSecAttrSynchronizable` is intentionally an *injectable*
// property (not hard-coded `true`) rather than always-on: strategy §1.6's
// "Global login" wants it `true` on the phone/iPad/Mac (iCloud Keychain
// propagation IS the global-login transport) but the tvOS gap (§1.6: "no
// iCloud Keychain on tvOS") means the tvOS shell must construct this with
// `synchronizable: false` and pair via device-code instead — a hard-coded
// `true` here would silently misbehave on that platform. `accessGroup` is
// similarly injectable (`nil` by default) rather than hard-coded to
// `TEAMID.app.ihymns.shared`, both because the real team-id-prefixed group
// requires a Keychain Sharing entitlement Phase-0's bare SwiftPM test
// target doesn't have, AND because callers (the app shells) are the
// correct place to supply the real value from their own entitlements
// file — a library target hard-coding a team ID would silently break the
// moment the entitlement changes. See Apple's Keychain Services guide:
// https://developer.apple.com/documentation/security/keychain_services.
import Foundation
#if canImport(Security)
import Security
#endif

/// The production `TokenStoring` implementation, backed by the platform
/// Keychain.
///
/// ELI5: The actual safe (as opposed to `InMemoryTokenStore`, the pretend
/// one used only by tests).
///
/// DETAILED: An `actor` (not a plain struct/class) even though every
/// `SecItem*` call is itself synchronous+thread-safe at the OS level —
/// isolating it still gives every call site a single, uniform `await`-based
/// API (matching `TokenStoring`'s `async` requirements) and leaves room for
/// a Phase-1 addition (e.g. an in-actor in-memory cache to avoid a
/// `SecItemCopyMatching` round-trip on every read) without changing the
/// public shape.
public actor KeychainTokenStore: TokenStoring {
    /// The Keychain item's `kSecAttrService` — a private per-app namespace
    /// so this item can never collide with another app's Keychain data.
    private let service: String

    /// Optional Keychain access group (`kSecAttrAccessGroup`) for sharing
    /// the token with app extensions (widgets, watch companion) — supplied
    /// by the app shell, which owns the actual entitlements file.
    private let accessGroup: String?

    /// Whether this item should sync via iCloud Keychain
    /// (`kSecAttrSynchronizable`) — `true` is the global-login transport
    /// (strategy §1.6) on platforms that support it; `false` on tvOS
    /// (no iCloud Keychain there — device-code pairing is used instead).
    private let synchronizable: Bool

    public init(service: String = "app.ihymns.token", accessGroup: String? = nil, synchronizable: Bool = true) {
        self.service = service
        self.accessGroup = accessGroup
        self.synchronizable = synchronizable
    }

    public func save(_ token: String) async throws {
        try await delete() // Replace-semantics: clear any existing item first.

        var query = baseQuery()
        query[kSecValueData as String] = Data(token.utf8)
        query[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlock

        let status = SecItemAdd(query as CFDictionary, nil)
        guard status == errSecSuccess else {
            throw KeychainError(status: status)
        }
    }

    public func load() async throws -> String? {
        var query = baseQuery()
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne

        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)

        switch status {
        case errSecSuccess:
            guard let data = result as? Data, let token = String(data: data, encoding: .utf8) else {
                throw KeychainError(status: status)
            }
            return token
        case errSecItemNotFound:
            return nil
        default:
            throw KeychainError(status: status)
        }
    }

    public func delete() async throws {
        let status = SecItemDelete(baseQuery() as CFDictionary)
        // `errSecItemNotFound` is not an error here — "already absent" and
        // "successfully removed" are the same post-condition for our
        // purposes (idempotent delete).
        guard status == errSecSuccess || status == errSecItemNotFound else {
            throw KeychainError(status: status)
        }
    }

    /// The attribute dictionary shared by every Keychain call above —
    /// centralised so `save`/`load`/`delete` always agree on which exact
    /// item they mean.
    private func baseQuery() -> [String: Any] {
        var query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrSynchronizable as String: synchronizable
        ]
        if let accessGroup {
            query[kSecAttrAccessGroup as String] = accessGroup
        }
        return query
    }
}

/// Wraps a Keychain `OSStatus` failure with a human-readable description.
///
/// ELI5: "The safe said no, and here's the error code it gave."
public struct KeychainError: Error, Sendable, CustomStringConvertible {
    public let status: OSStatus
    public var description: String {
        let message = SecCopyErrorMessageString(status, nil) as String? ?? "unknown Keychain error"
        return "Keychain operation failed (OSStatus \(status)): \(message)"
    }
}

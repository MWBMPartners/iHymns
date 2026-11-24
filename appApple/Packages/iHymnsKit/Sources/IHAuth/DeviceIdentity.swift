// DeviceIdentity.swift
// IHAuth
//
// ELI5: Gives this copy of the app a random name-tag it makes once and keeps
// forever on this device, so the server can tell "the same phone signing in
// again" from "a different phone".
//
// DETAILED: PR 3b step I2a (#2180). The "install id" is what lets the server
// keep one sign-in row per device (`tblApiTokens.DeviceInstallId`): signing in
// again from the same install replaces that install's older sign-in instead of
// piling up rows, and a token tied to one install can never be used to make a
// token for another. It is a random UUID, made on first use and then kept in a
// THIS-DEVICE-ONLY Keychain item (`app.ihymns.install-id`): not synced, not
// copied by an encrypted backup, not carried by phone-to-phone transfer. So a
// phone restored from a backup comes back with NO install id, makes a new one,
// and gets its own sign-in rather than cloning the old phone's. It identifies
// an install, not a person: it is random, holds nothing personal, and is sent
// only to the iHymns server with a sign-in. The server accepts 8 to 64 letters,
// digits and dashes (`apiTokenCleanInstallId()` in `includes/api_tokens.php`);
// a lowercase UUID string (36 characters) fits that.
import Foundation

/// Anything that can say which install this is.
///
/// ELI5: "What is this device's name-tag?"
public protocol DeviceIdentityProviding: Sendable {
    /// The install id, creating and saving one the first time it is asked for.
    func installId() async throws -> String
}

/// The real provider, over any `TokenStoring` slot: the app passes a
/// this-device-only Keychain item, tests pass an in-memory one.
public actor DeviceIdentity: DeviceIdentityProviding {
    private let store: any TokenStoring

    /// - Parameter store: Where the id lives. In the app this MUST be the
    ///   this-device-only item (`AuthStorage.live()` builds it); the actor
    ///   cannot check that itself.
    public init(store: any TokenStoring) {
        self.store = store
    }

    public func installId() async throws -> String {
        if let existing = try await store.load(), Self.isUsable(existing) {
            return existing
        }
        // First use (or an unreadable value): make a new one and keep it.
        let fresh = UUID().uuidString.lowercased()
        try await store.save(fresh)
        return fresh
    }

    /// Is `value` something the server will accept as an install id? Mirrors
    /// the server's rule (8 to 64 letters, digits and dashes). A stored value
    /// that fails this is replaced rather than sent and silently dropped.
    nonisolated static func isUsable(_ value: String) -> Bool {
        guard (8...64).contains(value.count) else { return false }
        return value.allSatisfy { $0.isASCII && ($0.isLetter || $0.isNumber || $0 == "-") }
    }
}

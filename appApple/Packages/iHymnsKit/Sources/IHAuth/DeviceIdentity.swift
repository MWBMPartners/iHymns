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
// only to the iHymns server: with a sign-in, and (since 2026-11-24) as the
// anonymous song-view report's per-device limit key. The server accepts 8 to 64 letters,
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

    /// The id once it is known: it never changes after that, so later calls
    /// answer from here without touching the Keychain.
    private var known: String?

    /// The lookup-or-create that is running right now, if any. A second caller
    /// that arrives while it runs waits for the SAME one instead of starting its
    /// own (an actor lets another call in whenever one is waiting on the
    /// Keychain, so without this two first-time callers could each make an id).
    private var inFlight: Task<String, Error>?

    /// - Parameter store: Where the id lives. In the app this MUST be the
    ///   this-device-only item (`AuthStorage.live()` builds it); the actor
    ///   cannot check that itself.
    public init(store: any TokenStoring) {
        self.store = store
    }

    public func installId() async throws -> String {
        if let known { return known }
        if let inFlight { return try await inFlight.value }
        let store = self.store
        let task = Task { try await Self.loadOrCreate(in: store) }
        inFlight = task
        do {
            let id = try await task.value
            known = id
            inFlight = nil
            return id
        } catch {
            inFlight = nil // a failure is not remembered: the next call tries again
            throw error
        }
    }

    /// Reads the saved id, or makes and saves a new one (first use, or an unreadable value).
    private static func loadOrCreate(in store: any TokenStoring) async throws -> String {
        if let existing = try await store.load(), isUsable(existing) {
            return existing
        }
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

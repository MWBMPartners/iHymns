// ServiceDeviceIdentity.swift
// IHLive/ServerLive
//
// ELI5: A random, anonymous ID this device mints for itself the first time
// it joins a service, and remembers from then on — so re-joining the same
// service re-tokens instead of creating a second congregant row.
//
// DETAILED: Apple Phase-2 PR-10 (#1427; `.claude/apple-phase2-pr10-spec.md`
// §3.6, D-7). The native equivalent of the web congregant's durable
// `localStorage` device id (`service-follow.js:55-66`). Deliberately
// **UserDefaults**, not Keychain and not `identifierForVendor`: Keychain
// would outlive app deletion (more tracking-persistent than the anonymous
// web id — the wrong direction for a "not collected" privacy posture,
// strategy §3.1); `identifierForVendor` resets unpredictably and ties to
// vendor identity, neither of which this anonymous room-presence id needs
// or wants. A fresh UUID string is already `[A-Za-z0-9\-]` and well under
// the server's 64-char cap (`api.php:14613-14614`) — no further sanitising
// needed.
import Foundation

/// This device's durable, anonymous Service Mode identity — see this file's
/// header.
public struct ServiceDeviceIdentity: @unchecked Sendable {
    private let defaults: UserDefaults
    private let key: String

    /// The real, `.standard`-backed instance every non-test caller uses.
    public static let standard = ServiceDeviceIdentity()

    public init(defaults: UserDefaults = .standard, key: String = "live.serviceDeviceId") {
        self.defaults = defaults
        self.key = key
    }

    /// This device's id — minted once (a fresh `UUID().uuidString`) and
    /// persisted from then on; every subsequent read returns the SAME value.
    ///
    /// ELI5: "What's this device's anonymous room-presence id?" — the same
    /// answer every time, forever (until the app is deleted).
    public var id: String {
        if let existing = defaults.string(forKey: key) {
            return existing
        }
        let fresh = UUID().uuidString
        defaults.set(fresh, forKey: key)
        return fresh
    }
}

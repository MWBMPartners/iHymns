// LegacySharedTokenReader.swift
// IHAuth
//
// ELI5: A look-but-don't-touch window onto the OLD shared sign-in that the
// older versions of the app kept in iCloud Keychain. New code may read it (to
// swap it for this device's own sign-in) but can never save over it or delete
// it, because that one item is shared with every other Apple device the
// person owns.
//
// DETAILED: PR 3b step I2a (#2180), plan `.claude/account-profile-pr3-plan.md`
// section 3 and design rule 6 in `.claude/analysis/per-device-sign-in-design.md`.
// Before per-device sign-in, every Apple device shared ONE token in a single
// synced item (service `app.ihymns.token`). Older app builds still use it, so:
//   - a swap (`auth_device_enrol`) never revokes it on the server, and
//   - new code must never delete or overwrite it here, or it would sign out
//     every other device at once. `KeychainTokenStore.save()` deletes before
//     it adds, so even one innocent-looking write would do exactly that.
// The way to make that mistake impossible rather than merely discouraged is
// the type system: this type has `load()` and nothing else, and it is the only
// place in the app code that names the shared item's service. The guard
// `tests/test-apple-keychain-custody.js` fails the build if either stops being
// true (a second mention of the name, or any `save`/`delete` on this type).
// The shared token simply expires on its own once no older app is using it.
import Foundation

/// Anything that can hand back the older apps' shared sign-in, read-only.
///
/// ELI5: "Is there an old shared sign-in I could swap?" and nothing more.
///
/// DETAILED: A protocol so `SessionController`'s tests can supply a fake with
/// the same read-only shape (the same reason `TokenStoring` exists). Note it
/// has no `save` and no `delete`: a conforming fake cannot be written to
/// either, which keeps the tests honest about the real type's limits.
public protocol LegacyTokenReading: Sendable {
    /// The shared token, or `nil` when there is none (a fresh install, or the
    /// older apps have signed out and cleaned it up themselves).
    func load() async throws -> String?
}

/// The real reader: looks at the shared, iCloud-synced Keychain item the older
/// apps wrote, and can do nothing else with it.
///
/// ELI5: The window itself.
public struct LegacySharedTokenReader: LegacyTokenReading {
    /// Reads through a normal Keychain store pointed at the shared item. The
    /// store is `private`, so nothing outside this file can reach its `save`
    /// or `delete`.
    private let sharedItem: KeychainTokenStore

    /// - Parameter accessGroup: Optional Keychain access group, the same value
    ///   the older apps used (`nil` unless the shell's entitlements name one).
    public init(accessGroup: String? = nil) {
        // Synced on purpose: this is exactly how the older apps saved it.
        // `thisDeviceOnly` stays false because the older apps did not set it.
        self.sharedItem = KeychainTokenStore(service: "app.ihymns.token", accessGroup: accessGroup, synchronizable: true)
    }

    public func load() async throws -> String? {
        try await sharedItem.load()
    }
}

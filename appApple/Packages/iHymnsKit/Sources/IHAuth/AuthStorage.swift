// AuthStorage.swift
// IHAuth
//
// ELI5: The one place that decides which Keychain items this app uses for
// signing in, and builds them with the right safety settings for each
// platform.
//
// DETAILED: PR 3b step I2a (#2180). There are now five separate pieces of
// stored sign-in data, and each has its own custody rule, so building them in
// one place (instead of in each app shell) stops the rules drifting:
//
//   | item                        | service                   | synced? | backups? |
//   | the device's own sign-in    | app.ihymns.device-token   | no      | no       |
//   | the install id              | app.ihymns.install-id     | no      | no       |
//   | the device's notebook       | app.ihymns.device-state   | no      | no       |
//   | the automatic sign-in key   | app.ihymns.enrol-key      | YES     | yes      |
//   | the OLD shared sign-in      | (read-only, see           | yes     | yes      |
//   |                             |  LegacySharedTokenReader) |         |          |
//
// Only the key is synced: it is the one thing that is SUPPOSED to follow the
// person to their other Apple devices, and it can do nothing but be swapped for
// a new device sign-in. tvOS and watchOS get no key store at all (design
// default 5: iCloud Keychain is not assumed there), so those platforms can
// never make or redeem a key; they still read the older shared token so an
// existing sign-in can be swapped. `tests/test-apple-keychain-custody.js`
// checks these rules from the source, so a change here that breaks one turns
// the build red instead of shipping.
import Foundation

/// Everything the session controller stores about signing in, bundled.
///
/// ELI5: A tray holding the five Keychain items.
public struct AuthStorage: Sendable {
    /// This device's own sign-in token (this-device-only).
    public let deviceTokenStore: any TokenStoring
    /// The automatic sign-in key (synced), or `nil` where iCloud Keychain is not used.
    public let enrolKeyStore: (any TokenStoring)?
    /// Read-only view of the older apps' shared sign-in.
    public let legacyReader: any LegacyTokenReading
    /// This install's random id (this-device-only).
    public let deviceIdentity: any DeviceIdentityProviding
    /// This device's notebook (this-device-only).
    public let signInState: DeviceSignInState

    public init(
        deviceTokenStore: any TokenStoring,
        enrolKeyStore: (any TokenStoring)? = nil,
        legacyReader: any LegacyTokenReading,
        deviceIdentity: any DeviceIdentityProviding,
        signInState: DeviceSignInState
    ) {
        self.deviceTokenStore = deviceTokenStore
        self.enrolKeyStore = enrolKeyStore
        self.legacyReader = legacyReader
        self.deviceIdentity = deviceIdentity
        self.signInState = signInState
    }

    /// The real Keychain-backed tray, with the right settings per platform.
    ///
    /// ELI5: "Build the real set of Keychain items for this device."
    public static func live() -> AuthStorage {
        let deviceToken = KeychainTokenStore(service: "app.ihymns.device-token", synchronizable: false, thisDeviceOnly: true)
        let installId = KeychainTokenStore(service: "app.ihymns.install-id", synchronizable: false, thisDeviceOnly: true)
        let deviceState = KeychainTokenStore(service: "app.ihymns.device-state", synchronizable: false, thisDeviceOnly: true)
        #if os(tvOS) || os(watchOS)
        let enrolKey: (any TokenStoring)? = nil
        #else
        let enrolKey: (any TokenStoring)? = KeychainTokenStore(service: "app.ihymns.enrol-key")
        #endif
        return AuthStorage(
            deviceTokenStore: deviceToken,
            enrolKeyStore: enrolKey,
            legacyReader: LegacySharedTokenReader(),
            deviceIdentity: DeviceIdentity(store: installId),
            signInState: DeviceSignInState(store: deviceState)
        )
    }
}

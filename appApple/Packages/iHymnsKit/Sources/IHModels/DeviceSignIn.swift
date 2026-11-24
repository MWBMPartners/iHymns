// DeviceSignIn.swift
// IHModels
//
// ELI5: The shapes of what the server hands back when this device asks for its
// OWN sign-in, asks for an automatic sign-in key, or signs out everywhere.
//
// DETAILED: PR 3b step I2b (#2180). Verified against the live `api.php` cases
// `auth_device_enrol`, `auth_enrol_key_create`, `auth_enrol_key_redeem`,
// `auth_enrol_keys_revoke` and `auth_signout_everywhere` and the rules in
// `includes/device_signin.php` (read 2026-11-24). A successful enrol or redeem
// returns the SAME `{token, user}` shape as `auth_login` (so `AuthSession`
// already decodes it) plus `device.id`, `expiresAt`, and (enrol only, and only
// when the request did not say no) `enrolKey` + `enrolKeyExpiresAt`. The key
// text is shown by the server exactly ONCE, in that one reply: the caller must
// save it straight away and never log it. Time stamps stay plain strings, as
// everywhere else in this package (`Setlist.swift` explains why).
import Foundation

/// The reply to a successful `auth_device_enrol` or `auth_enrol_key_redeem`:
/// this device's own sign-in, and possibly a new automatic sign-in key.
///
/// ELI5: "Here is your very own sign-in, and (maybe) a key for your other devices."
public struct DeviceSignInResult: Sendable, Equatable {
    /// The new sign-in (token + who you are), the same shape `auth_login` returns.
    public let session: AuthSession
    /// A short, safe label for this sign-in as the web Devices list shows it
    /// (the first characters of a hash, never the token). Informational only.
    public let deviceId: String?
    /// When the new sign-in stops working if unused (the server's clock text).
    public let expiresAt: String?
    /// A brand-new automatic sign-in key, present only on an enrol that made
    /// one. Secret: save it at once, never log it.
    public let enrolKey: String?
    public let enrolKeyExpiresAt: String?

    public init(
        session: AuthSession,
        deviceId: String? = nil,
        expiresAt: String? = nil,
        enrolKey: String? = nil,
        enrolKeyExpiresAt: String? = nil
    ) {
        self.session = session
        self.deviceId = deviceId
        self.expiresAt = expiresAt
        self.enrolKey = enrolKey
        self.enrolKeyExpiresAt = enrolKeyExpiresAt
    }
}

/// The reply to `auth_enrol_key_create`: a new automatic sign-in key, shown once.
///
/// ELI5: "Here is the key. This is the only time you will see it."
public struct EnrolKeyIssue: Sendable, Equatable {
    /// The key (`ihek1_` plus 64 hex characters). Secret.
    public let enrolKey: String
    public let expiresAt: String?

    public init(enrolKey: String, expiresAt: String? = nil) {
        self.enrolKey = enrolKey
        self.expiresAt = expiresAt
    }
}

/// The reply to `auth_signout_everywhere`: how much was ended.
///
/// ELI5: "I signed out this many sign-ins and cancelled this many keys."
public struct SignOutEverywhereResult: Sendable, Equatable {
    public let tokensRevoked: Int
    public let enrolKeysRevoked: Int
    /// Whether the sign-in that asked was kept (only when the request said so).
    public let keptCurrent: Bool

    public init(tokensRevoked: Int, enrolKeysRevoked: Int, keptCurrent: Bool) {
        self.tokensRevoked = tokensRevoked
        self.enrolKeysRevoked = enrolKeysRevoked
        self.keptCurrent = keptCurrent
    }
}

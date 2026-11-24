// AuthDeviceEndpoints.swift
// IHAPI
//
// ELI5: The recipe cards for the five "one sign-in per device" calls: swap the
// old shared sign-in for this device's own, make an automatic sign-in key,
// use a key on a brand-new device, turn the keys off, and sign out everywhere.
//
// DETAILED: PR 3b step I2b (#2180), plan `.claude/account-profile-pr3-plan.md`
// section 2 (status table) and `includes/device_signin.php`. Verified against
// the live `api.php` cases (read 2026-11-24):
//   - `auth_device_enrol` (POST, header sign-in REQUIRED: the OLD shared
//     token): body `{deviceInstallId, platform, deviceName?, appVersion?,
//     createEnrolKey?}`. 200 returns the new sign-in (+ a key unless
//     `createEnrolKey: false`). The shared token is NOT revoked by it.
//   - `auth_enrol_key_create` (POST, the device's OWN sign-in): no body. 200
//     returns `{enrolKey, expiresAt}`.
//   - `auth_enrol_key_redeem` (POST, NO sign-in: the key is the proof): body
//     `{enrolKey, deviceInstallId, platform, deviceName?, appVersion?}`. 200
//     returns the new sign-in. EVERY unusable key (unknown, expired, revoked,
//     wrong platform, switched-off account, a device that was signed out)
//     gives the same single 401 `enrol_key_invalid`.
//   - `auth_enrol_keys_revoke` (POST, signed in): cancels every key. 200 `{revoked}`.
//   - `auth_signout_everywhere` (POST, signed in, `{keepCurrent?}`): ends every
//     sign-in and cancels every key. 200 `{tokensRevoked, enrolKeysRevoked,
//     keptCurrent}`.
// All five set `capturesRefusalReason` so the app can branch on status PLUS
// the machine `reason` word (409 `device_signin_disabled` or
// `device_signin_not_ready` or `already_enrolled`; 403 `install_signed_out` or
// `install_mismatch` or `not_a_shared_app_sign_in`; 400 `invalid_install_id`
// ...), never on the server's sentence (rule #35).
import Foundation

extension Endpoint {
    /// `?action=auth_device_enrol`: swap the older apps' shared sign-in (sent
    /// as the bearer token) for this device's own.
    ///
    /// - Parameters:
    ///   - device: This install's details (the install id is required here).
    ///   - createEnrolKey: Whether to also ask for an automatic sign-in key.
    ///     The server's default is "yes", so the key is omitted from the body
    ///     unless this is `false`.
    /// - Throws: Only if `JSONEncoder` fails (practically unreachable).
    static func authDeviceEnrol(device: DeviceSignInDetails, createEnrolKey: Bool = true) throws -> Endpoint {
        let body = try JSONEncoder().encode(DeviceEnrolRequestBody(device: device, createEnrolKey: createEnrolKey ? nil : false))
        return Endpoint(action: "auth_device_enrol", requiresAuth: true, httpMethod: "POST", httpBody: body, capturesRefusalReason: true)
    }

    /// `?action=auth_enrol_key_create`: make (another) automatic sign-in key
    /// from a device that already has its own sign-in. Sends no body.
    static let authEnrolKeyCreate = Endpoint(
        action: "auth_enrol_key_create", requiresAuth: true, httpMethod: "POST", capturesRefusalReason: true
    )

    /// `?action=auth_enrol_key_redeem`: swap an automatic sign-in key for this
    /// device's own sign-in. NOT authenticated: the key is the proof, and the
    /// device has no sign-in yet.
    static func authEnrolKeyRedeem(enrolKey: String, device: DeviceSignInDetails) throws -> Endpoint {
        let body = try JSONEncoder().encode(EnrolKeyRedeemRequestBody(enrolKey: enrolKey, device: device))
        return Endpoint(action: "auth_enrol_key_redeem", httpMethod: "POST", httpBody: body, capturesRefusalReason: true)
    }

    /// `?action=auth_enrol_keys_revoke`: turn automatic sign-in off by
    /// cancelling every key the person holds. Sends no body.
    static let authEnrolKeysRevoke = Endpoint(
        action: "auth_enrol_keys_revoke", requiresAuth: true, httpMethod: "POST", capturesRefusalReason: true
    )

    /// `?action=auth_signout_everywhere`: end every sign-in and cancel every
    /// key. `keepCurrent` keeps the sign-in that asked (the app never sets it:
    /// a person who signs out everywhere is signed out here too).
    static func authSignOutEverywhere(keepCurrent: Bool = false) throws -> Endpoint {
        let body = try JSONEncoder().encode(SignOutEverywhereRequestBody(keepCurrent: keepCurrent ? true : nil))
        return Endpoint(action: "auth_signout_everywhere", requiresAuth: true, httpMethod: "POST", httpBody: body, capturesRefusalReason: true)
    }
}

/// `auth_device_enrol`'s JSON body. `createEnrolKey` is left out unless it is
/// `false` (an absent key means "yes, make one", the server's default).
private struct DeviceEnrolRequestBody: Encodable {
    let deviceInstallId: String
    let platform: String
    let deviceName: String?
    let appVersion: String?
    let createEnrolKey: Bool?

    init(device: DeviceSignInDetails, createEnrolKey: Bool?) {
        self.deviceInstallId = device.installId
        self.platform = device.platform
        self.deviceName = device.deviceName
        self.appVersion = device.appVersion
        self.createEnrolKey = createEnrolKey
    }
}

/// `auth_enrol_key_redeem`'s JSON body.
private struct EnrolKeyRedeemRequestBody: Encodable {
    let enrolKey: String
    let deviceInstallId: String
    let platform: String
    let deviceName: String?
    let appVersion: String?

    init(enrolKey: String, device: DeviceSignInDetails) {
        self.enrolKey = enrolKey
        self.deviceInstallId = device.installId
        self.platform = device.platform
        self.deviceName = device.deviceName
        self.appVersion = device.appVersion
    }
}

/// `auth_signout_everywhere`'s JSON body: `keepCurrent` only when `true`.
private struct SignOutEverywhereRequestBody: Encodable {
    let keepCurrent: Bool?
}

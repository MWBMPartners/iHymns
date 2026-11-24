// AuthDeviceDecoding.swift
// IHAPI
//
// ELI5: Turns the server's answers to the five "one sign-in per device" calls
// into Swift values, and does the five calls themselves.
//
// DETAILED: PR 3b step I2b (#2180). The decoders follow `AuthDecoding.swift`'s
// pattern (a `nonisolated static` function per shape, testable with no
// network). The calls are POSTs, so they go through `performOnce`, never the
// retrying GET path: retrying a request that swaps a token or makes a key
// could create a second one if the first reply was merely lost.
//
// None of these ever logs a token or a key; the `APIClient` log lines carry
// only the action name, the error case name and the status. A refusal comes
// back as `APIError` (see `Endpoint.capturesRefusalReason`): `.unauthorized`
// for 401, `.rateLimited` for 429, and `.server(status:message:)` with the
// server's `reason` word for 400/403/409.
import Foundation
import IHModels

/// The `device` object inside an enrol or redeem answer (kept at file level so the
/// decoding helper below is not nested too deeply).
private struct DeviceSignInDevice: Decodable { let id: String? }

extension APIClient {
    /// Swaps the older apps' shared sign-in (the client's CURRENT bearer token
    /// must be that token) for this device's own sign-in.
    ///
    /// ELI5: "I have the old shared sign-in. Give this device its own."
    ///
    /// - Throws: `.unauthorized` (the shared token is dead), `.rateLimited`, or
    ///   `.server(status:, message: reason)`: 409 `device_signin_disabled` /
    ///   `device_signin_not_ready` / `already_enrolled`; 403 `install_mismatch`
    ///   / `not_a_shared_app_sign_in` / `install_signed_out` / `browser_request`;
    ///   400 `invalid_install_id` / `invalid_platform` / `bearer_required`.
    public func authDeviceEnrol(device: DeviceSignInDetails, createEnrolKey: Bool = true) async throws -> DeviceSignInResult {
        let data = try await performOnce(try Endpoint.authDeviceEnrol(device: device, createEnrolKey: createEnrolKey))
        return try Self.decodeDeviceSignInResult(from: data)
    }

    /// Makes (another) automatic sign-in key from this device's own sign-in.
    /// The key is shown ONCE, in the returned value.
    ///
    /// ELI5: "Make me a key for my other Apple devices."
    public func authEnrolKeyCreate() async throws -> EnrolKeyIssue {
        let data = try await performOnce(.authEnrolKeyCreate)
        return try Self.decodeEnrolKeyIssue(from: data)
    }

    /// Swaps an automatic sign-in key for this device's own sign-in. Needs no
    /// sign-in: the key is the proof.
    ///
    /// ELI5: "I am a new device with a key from iCloud. Sign me in."
    ///
    /// - Throws: `.unauthorized` for every unusable key (the server gives one
    ///   single answer on purpose), `.rateLimited`, or `.server(status:message:)`
    ///   (409 not switched on or not ready; 400 `malformed_enrol_key` etc.).
    public func authEnrolKeyRedeem(enrolKey: String, device: DeviceSignInDetails) async throws -> DeviceSignInResult {
        let data = try await performOnce(try Endpoint.authEnrolKeyRedeem(enrolKey: enrolKey, device: device))
        return try Self.decodeDeviceSignInResult(from: data)
    }

    /// Turns automatic sign-in off by cancelling every key. Returns how many were cancelled.
    ///
    /// ELI5: "Stop my other devices signing themselves in."
    public func authEnrolKeysRevoke() async throws -> Int {
        let data = try await performOnce(.authEnrolKeysRevoke)
        return try Self.decodeEnrolKeysRevoked(from: data)
    }

    /// Ends every sign-in and cancels every key.
    ///
    /// ELI5: "Sign every one of my devices out."
    public func authSignOutEverywhere(keepCurrent: Bool = false) async throws -> SignOutEverywhereResult {
        let data = try await performOnce(try Endpoint.authSignOutEverywhere(keepCurrent: keepCurrent))
        return try Self.decodeSignOutEverywhere(from: data)
    }

    /// Decodes an enrol or redeem answer: the `auth_login` shape plus the extras.
    nonisolated public static func decodeDeviceSignInResult(from data: Data) throws -> DeviceSignInResult {
        struct Extras: Decodable {
            let device: DeviceSignInDevice?
            let expiresAt: String?
            let enrolKey: String?
            let enrolKeyExpiresAt: String?
        }
        do {
            let decoder = JSONDecoder()
            let session = try decoder.decode(AuthSession.self, from: data)
            let extras = try decoder.decode(Extras.self, from: data)
            return DeviceSignInResult(
                session: session,
                deviceId: extras.device?.id,
                expiresAt: extras.expiresAt,
                enrolKey: extras.enrolKey,
                enrolKeyExpiresAt: extras.enrolKeyExpiresAt
            )
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a key-create answer. The key must be a non-empty string.
    nonisolated public static func decodeEnrolKeyIssue(from data: Data) throws -> EnrolKeyIssue {
        struct Envelope: Decodable {
            let enrolKey: String
            let expiresAt: String?
        }
        do {
            let envelope = try JSONDecoder().decode(Envelope.self, from: data)
            guard !envelope.enrolKey.isEmpty else { throw APIError.decoding }
            return EnrolKeyIssue(enrolKey: envelope.enrolKey, expiresAt: envelope.expiresAt)
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a "turn off automatic sign-in" answer to the number of keys cancelled.
    nonisolated public static func decodeEnrolKeysRevoked(from data: Data) throws -> Int {
        struct Envelope: Decodable { let revoked: Int }
        do {
            return try JSONDecoder().decode(Envelope.self, from: data).revoked
        } catch {
            throw APIError.decoding
        }
    }

    /// Decodes a sign-out-everywhere answer.
    nonisolated public static func decodeSignOutEverywhere(from data: Data) throws -> SignOutEverywhereResult {
        struct Envelope: Decodable {
            let tokensRevoked: Int
            let enrolKeysRevoked: Int
            let keptCurrent: Bool
        }
        do {
            let envelope = try JSONDecoder().decode(Envelope.self, from: data)
            return SignOutEverywhereResult(
                tokensRevoked: envelope.tokensRevoked,
                enrolKeysRevoked: envelope.enrolKeysRevoked,
                keptCurrent: envelope.keptCurrent
            )
        } catch {
            throw APIError.decoding
        }
    }
}

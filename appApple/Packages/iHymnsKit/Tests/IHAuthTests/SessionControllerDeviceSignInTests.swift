// SessionControllerDeviceSignInTests.swift
// IHAuthTests
//
// ELI5: Walks the app's launch-time sign-in decisions one by one: "I have my
// own sign-in", "I was signed out here", "I have a key in iCloud", "I only
// have the old shared sign-in", and what happens for each answer the server
// can give, including offline.
//
// DETAILED: PR 3b step I2c (#2180), plan section 3 (I2c) numbered paths 1 to 3.
// The signing-in, signing-out and account-screen paths (4 to 7) are in
// `SessionControllerDeviceSignOutTests.swift`. These run in CI on macOS
// (`apple.yml`, `swift test`), not in the sandbox this was written in. Harness:
// `DeviceSignInTestSupport.swift`. What these cannot show (needs a real
// device): that the Keychain items really are this-device-only or synced, and
// that iCloud really carries the key between devices.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth

@Suite("SessionController device sign-in: launch paths", .serialized)
struct SessionControllerDeviceSignInTests {
    typealias D = DeviceTestData

    // MARK: 1. Own token

    @Test("Path 1: an own token that auth_me accepts signs in, with no swap")
    func ownTokenIsAccepted() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken, key: D.key, ownToken: D.ownToken)
            D.route(h.log) { _ in (200, D.meOK) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(await h.controller.state == .signedIn(token: D.ownToken))
            #expect(h.log.actions == ["auth_me"])
        }
    }

    @Test("Path 1: a 401 on the own token deletes it (it is not synced), marks signed out here, and never signs itself back in")
    func ownTokenRejectedStaysSignedOut() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken, key: D.key, ownToken: D.ownToken)
            D.route(h.log) { _ in (401, Data()) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await h.controller.state == .signedOut)
            #expect(try await h.deviceStore.load() == nil)
            #expect(await h.notebook.record().signedOutHere)

            // The key and the shared sign-in are still there, yet the next launch must not use them.
            h.log.reset()
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await h.controller.state == .signedOut)
            #expect(h.log.actions.isEmpty)
        }
    }

    @Test("Path 1: an own token fails OPEN when the check cannot reach the server")
    func ownTokenFailsOpenOffline() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(ownToken: D.ownToken)
            D.route(h.log) { _ in (503, Data()) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(await h.controller.state == .signedIn(token: D.ownToken))
            #expect(try await h.deviceStore.load() == D.ownToken)
        }
    }

    // MARK: 2. Signed out here

    @Test("Path 2: signed out here means no key, no shared sign-in, and no network at all")
    func signedOutHereBlocksEverything() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken, key: D.key)
            try await h.notebook.setSignedOutHere(true)
            D.route(h.log) { _ in (200, D.signInOK()) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(await h.controller.state == .signedOut)
            #expect(h.log.actions.isEmpty)
        }
    }

    // MARK: 3a. Key

    @Test("Path 3a: a key signs a new device in, sends NO Authorization header, and saves the new token")
    func keyRedeemSucceeds() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key)
            D.route(h.log) { action in action == "auth_enrol_key_redeem" ? (200, D.signInOK()) : (200, D.meOK) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(await h.controller.state == .signedIn(token: D.newToken))
            #expect(try await h.deviceStore.load() == D.newToken)
            #expect(h.log.last(of: "auth_enrol_key_redeem")?.authorization == nil)
            // The key itself stays put: it keeps working for the person's other devices.
            #expect(try await h.keyStore.load() == D.key)
        }
    }

    @Test("Path 3a: a 401 on the key marks it dead, signs out, and the key is not retried next launch")
    func keyRejectedIsRememberedAsDead() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key)
            D.route(h.log) { _ in (401, D.refusal(401, "enrol_key_invalid")) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await h.controller.state == .signedOut)
            #expect(await h.notebook.isKeyDead(hash: TokenFingerprint.sha256Hex(D.key)))
            #expect(h.log.count(of: "auth_enrol_key_redeem") == 1)

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(h.log.count(of: "auth_enrol_key_redeem") == 1) // not tried again
        }
    }

    @Test("Path 3a: a key is never tried when the site switch is off")
    func keyIgnoredWhenSwitchOff() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key)
            D.route(h.log) { _ in (200, D.signInOK()) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: false)

            #expect(await h.controller.state == .signedOut)
            #expect(h.log.actions.isEmpty)
        }
    }

    // MARK: 3b. Shared sign-in

    @Test("Path 3b: the shared sign-in is swapped using the shared token as bearer; the new token is saved, the swap recorded, the key kept")
    func legacySwapSucceeds() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken)
            D.route(h.log) { _ in (200, D.signInOK(withKey: true)) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(await h.controller.state == .signedIn(token: D.newToken))
            #expect(try await h.deviceStore.load() == D.newToken)
            #expect(h.log.last(of: "auth_device_enrol")?.authorization == "Bearer " + D.legacyToken)
            #expect(await h.notebook.isLegacySwapped(hash: TokenFingerprint.sha256Hex(D.legacyToken)))
            #expect(try await h.keyStore.load() == D.newKey)
        }
    }

    @Test("Path 3b: a key already known to be dead is replaced by the new one the swap makes")
    func legacySwapReplacesDeadKey() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            // The held key is marked dead so the key path is skipped; a dead key is not "usable", so the swap asks for a new one.
            let h = try await D.makeHarness(legacy: D.legacyToken, key: D.key)
            await h.notebook.markKeyDead(hash: TokenFingerprint.sha256Hex(D.key))
            D.route(h.log) { _ in (200, D.signInOK(withKey: true)) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(try await h.keyStore.load() == D.newKey)
        }
    }

    @Test("Path 3b: a swap refused with 409 falls back to legacy mode: the shared token in memory only, never copied to the device store")
    func legacyModeOn409() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken)
            D.route(h.log) { action in action == "auth_device_enrol" ? (409, D.refusal(409, "device_signin_not_ready")) : (200, D.meOK) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(await h.controller.state == .signedIn(token: D.legacyToken))
            #expect(try await h.deviceStore.load() == nil)
            #expect(await h.controller.usingLegacyToken)
            // Not recorded as swapped, so the next launch tries again.
            #expect(await h.notebook.isLegacySwapped(hash: TokenFingerprint.sha256Hex(D.legacyToken)) == false)
        }
    }

    @Test("Path 3b: offline at the swap also falls back to legacy mode and stays signed in")
    func legacyModeWhenOffline() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken)
            D.route(h.log) { _ in (503, Data()) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(await h.controller.state == .signedIn(token: D.legacyToken))
            #expect(try await h.deviceStore.load() == nil)
        }
    }

    @Test("Path 3b: 403 install_signed_out means signed out here, and the shared sign-in is not used")
    func installSignedOutStaysSignedOut() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken)
            D.route(h.log) { _ in (403, D.refusal(403, "install_signed_out")) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(await h.controller.state == .signedOut)
            #expect(await h.notebook.record().signedOutHere)
            #expect(await h.controller.usingLegacyToken == false)
        }
    }

    @Test("Path 3b: any OTHER 403 (a different refusal) is not mistaken for install_signed_out: legacy mode")
    func otherForbiddenIsLegacyMode() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken)
            D.route(h.log) { action in action == "auth_device_enrol" ? (403, D.refusal(403, "not_a_shared_app_sign_in")) : (200, D.meOK) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(await h.controller.state == .signedIn(token: D.legacyToken))
            #expect(await h.notebook.record().signedOutHere == false)
        }
    }

    @Test("Path 3b: a 401 means the shared token is dead: signed out, recorded as handled, and not tried again")
    func deadLegacyTokenIsNotRetried() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken)
            D.route(h.log) { _ in (401, Data()) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await h.controller.state == .signedOut)
            #expect(h.log.count(of: "auth_device_enrol") == 1)

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(h.log.count(of: "auth_device_enrol") == 1)
        }
    }

    @Test("With the site switch off, the shared sign-in is used as before: no swap call, legacy mode")
    func switchOffUsesSharedSignIn() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken)
            D.route(h.log) { _ in (200, D.meOK) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: false)

            #expect(await h.controller.state == .signedIn(token: D.legacyToken))
            #expect(h.log.count(of: "auth_device_enrol") == 0)
            #expect(try await h.deviceStore.load() == nil)
        }
    }

    @Test("Nothing in any safe means signed out, with no network")
    func nothingMeansSignedOut() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness()
            D.route(h.log) { _ in (200, D.meOK) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await h.controller.state == .signedOut)
            #expect(h.log.actions.isEmpty)
        }
    }

    @Test("A shared token that was already swapped is not used again when there is no own token")
    func alreadySwappedIsNotReused() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken)
            await h.notebook.markLegacySwapped(hash: TokenFingerprint.sha256Hex(D.legacyToken))
            D.route(h.log) { _ in (200, D.meOK) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await h.controller.state == .signedOut)
            #expect(h.log.actions.isEmpty)
        }
    }

    @Test("Plain mode (no device sign-in support) behaves exactly as before: no token means signed out, never a swap")
    func plainModeIsUnchanged() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(plain: true)
            D.route(h.log) { _ in (200, D.signInOK()) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await h.controller.state == .signedOut)
            #expect(h.log.actions.isEmpty)
        }
    }
}

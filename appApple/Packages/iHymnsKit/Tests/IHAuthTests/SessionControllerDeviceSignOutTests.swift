// SessionControllerDeviceSignOutTests.swift
// IHAuthTests
//
// ELI5: The other half of the device sign-in tests: signing in by hand (and
// making a key), signing out of an own sign-in versus the shared one, signing
// out everywhere, deleting the account, and the automatic sign-in switch.
//
// DETAILED: PR 3b step I2c (#2180), plan section 3 (I2c) numbered paths 4 to 7,
// plus the account-screen controls of I2d. The launch paths are in
// `SessionControllerDeviceSignInTests.swift`; the harness is
// `DeviceSignInTestSupport.swift`. The load-bearing assertions are the NEGATIVE
// ones: signing out of legacy mode makes no server call (it would sign out every
// other device), and nothing here can touch the shared item (the reader the
// controller holds has no way to write; `tests/test-apple-keychain-custody.js`
// proves that from the source).
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth

@Suite("SessionController device sign-in: signing in and out", .serialized)
struct SessionControllerDeviceSignOutTests {
    typealias D = DeviceTestData

    // MARK: 4. Manual sign-in

    @Test("Path 4: a manual sign-in saves the token, clears signed-out-here, and makes a key when none is held")
    func manualSignInClearsFlagAndMakesKey() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness()
            try await h.notebook.setSignedOutHere(true)
            D.route(h.log) { action in
                action == "auth_enrol_key_create" ? (200, Data(#"{"enrolKey":"\#(D.newKey)","expiresAt":"2027-05-23 09:00:00"}"#.utf8)) : (200, D.signInOK(token: D.ownToken))
            }
            // The switch must be known on first: `restoreFromStorage` carries it in.
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            h.log.reset()

            try await h.controller.signIn(username: "jane", password: "pw")

            #expect(await h.controller.state == .signedIn(token: D.ownToken))
            #expect(try await h.deviceStore.load() == D.ownToken)
            #expect(await h.notebook.record().signedOutHere == false)
            #expect(try await h.keyStore.load() == D.newKey)
            #expect(h.log.actions == ["auth_login", "auth_enrol_key_create"])
            // The key is asked for with the NEW own token, not anything shared.
            #expect(h.log.last(of: "auth_enrol_key_create")?.authorization == "Bearer " + D.ownToken)
        }
    }

    @Test("Path 4: no key is made when one is already held, when the person turned it off, or when the switch is off")
    func manualSignInSkipsKeyWhenNotWanted() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            // A key is already held.
            let held = try await D.makeHarness(key: D.key)
            D.route(held.log) { _ in (200, D.signInOK(token: D.ownToken)) }
            try await held.controller.restoreFromStorage(deviceSignInEnabled: true)
            try await held.controller.signIn(username: "jane", password: "pw")
            #expect(held.log.count(of: "auth_enrol_key_create") == 0)

            // The person turned automatic sign-in off on this device.
            let off = try await D.makeHarness()
            await off.notebook.setAutomaticSignInOff(true)
            D.route(off.log) { _ in (200, D.signInOK(token: D.ownToken)) }
            try await off.controller.restoreFromStorage(deviceSignInEnabled: true)
            try await off.controller.signIn(username: "jane", password: "pw")
            #expect(off.log.count(of: "auth_enrol_key_create") == 0)

            // The site switch is off.
            let switchOff = try await D.makeHarness()
            D.route(switchOff.log) { _ in (200, D.signInOK(token: D.ownToken)) }
            try await switchOff.controller.restoreFromStorage(deviceSignInEnabled: false)
            try await switchOff.controller.signIn(username: "jane", password: "pw")
            #expect(switchOff.log.count(of: "auth_enrol_key_create") == 0)
        }
    }

    @Test("Path 4: a sign-in never fails because the key could not be made")
    func manualSignInSurvivesKeyFailure() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness()
            D.route(h.log) { action in action == "auth_enrol_key_create" ? (409, D.refusal(409, "device_signin_disabled")) : (200, D.signInOK(token: D.ownToken)) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            try await h.controller.signIn(username: "jane", password: "pw")
            #expect(await h.controller.state == .signedIn(token: D.ownToken))
            #expect(try await h.keyStore.load() == nil)
        }
    }

    // MARK: 5. Sign out

    @Test("Path 5: signing out of an own sign-in revokes it, marks signed out here BEFORE deleting, and drops the dead key")
    func signOutOfOwnSignIn() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken, key: D.key, ownToken: D.ownToken)
            D.route(h.log) { _ in (200, D.meOK) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            h.log.reset()

            try await h.controller.signOut()

            #expect(h.log.actions == ["auth_logout"])
            #expect(h.log.last(of: "auth_logout")?.authorization == "Bearer " + D.ownToken)
            #expect(await h.controller.state == .signedOut)
            #expect(try await h.deviceStore.load() == nil)
            #expect(await h.notebook.record().signedOutHere)
            #expect(try await h.keyStore.load() == nil)

            // And the next launch stays signed out although a shared sign-in is still in iCloud.
            h.log.reset()
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await h.controller.state == .signedOut)
            #expect(h.log.actions.isEmpty)
        }
    }

    @Test("Path 5: signing out of LEGACY mode makes NO server call (it would sign out every other device) and keeps the key")
    func signOutOfLegacyModeMakesNoCall() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken, key: D.key)
            // Make the swap fail so the device ends up in legacy mode, and keep the key from being tried.
            await h.notebook.markKeyDead(hash: TokenFingerprint.sha256Hex(D.key))
            D.route(h.log) { action in action == "auth_device_enrol" ? (409, D.refusal(409, "device_signin_not_ready")) : (200, D.meOK) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await h.controller.usingLegacyToken)
            h.log.reset()

            try await h.controller.signOut()

            #expect(h.log.actions.isEmpty)
            #expect(await h.controller.state == .signedOut)
            #expect(await h.notebook.record().signedOutHere)
            #expect(try await h.keyStore.load() == D.key) // the key is not touched
            #expect(await h.controller.usingLegacyToken == false)
        }
    }

    @Test("Path 5: a failed revoke leaves everything as it was (still signed in, token and key kept)")
    func signOutFailureChangesNothing() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key, ownToken: D.ownToken)
            D.route(h.log) { action in action == "auth_logout" ? (503, Data()) : (200, D.meOK) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            await #expect(throws: APIError.self) { try await h.controller.signOut() }

            #expect(await h.controller.state == .signedIn(token: D.ownToken))
            #expect(try await h.deviceStore.load() == D.ownToken)
            #expect(try await h.keyStore.load() == D.key)
            #expect(await h.notebook.record().signedOutHere == false)
        }
    }

    // MARK: 6. Sign out everywhere

    @Test("Path 6: sign out everywhere calls the server, then clears the token, marks signed out here, and deletes the key")
    func signOutEverywhere() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key, ownToken: D.ownToken)
            D.route(h.log) { action in
                action == "auth_signout_everywhere" ? (200, Data(#"{"tokensRevoked":3,"enrolKeysRevoked":1,"keptCurrent":false}"#.utf8)) : (200, D.meOK)
            }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            let result = try await h.controller.signOutEverywhere()

            #expect(result?.tokensRevoked == 3)
            #expect(await h.controller.state == .signedOut)
            #expect(try await h.deviceStore.load() == nil)
            #expect(await h.notebook.record().signedOutHere)
            #expect(try await h.keyStore.load() == nil)
        }
    }

    @Test("Path 6: a failed sign-out-everywhere is thrown and changes nothing locally; a 401 counts as done")
    func signOutEverywhereFailureAndUnauthorized() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key, ownToken: D.ownToken)
            D.route(h.log) { action in action == "auth_signout_everywhere" ? (503, Data()) : (200, D.meOK) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            await #expect(throws: APIError.self) { _ = try await h.controller.signOutEverywhere() }
            #expect(await h.controller.state == .signedIn(token: D.ownToken))
            #expect(try await h.keyStore.load() == D.key)

            D.route(h.log) { _ in (401, Data()) }
            let result = try await h.controller.signOutEverywhere()
            #expect(result == nil)
            #expect(await h.controller.state == .signedOut)
        }
    }

    @Test("Path 6: sign out everywhere while signed out throws unauthorized and makes no call")
    func signOutEverywhereWhileSignedOut() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness()
            D.route(h.log) { _ in (200, D.meOK) }
            await #expect(throws: APIError.unauthorized) { _ = try await h.controller.signOutEverywhere() }
            #expect(h.log.actions.isEmpty)
        }
    }

    // MARK: 7. Delete account

    @Test("Path 7: deleting the account clears the device token, marks signed out here and deletes the key")
    func deleteAccountClearsKey() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key, ownToken: D.ownToken)
            D.route(h.log) { _ in (200, D.meOK) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            try await h.controller.deleteAccount(reauth: .password("pw"))

            #expect(await h.controller.state == .signedOut)
            #expect(try await h.deviceStore.load() == nil)
            #expect(try await h.keyStore.load() == nil)
            #expect(await h.notebook.record().signedOutHere)
        }
    }

    // MARK: Account screen controls (I2d)

    @Test("Automatic sign-in off cancels every key on the server first, then remembers the choice and deletes the local key; on makes and saves a new one")
    func automaticSignInToggle() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key, ownToken: D.ownToken)
            D.route(h.log) { action in
                switch action {
                case "auth_enrol_keys_revoke": return (200, Data(#"{"revoked":1}"#.utf8))
                case "auth_enrol_key_create": return (200, Data(#"{"enrolKey":"\#(D.newKey)","expiresAt":"2027-05-23 09:00:00"}"#.utf8))
                default: return (200, D.meOK)
                }
            }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await h.controller.deviceSignInStatus().canOfferAutomaticSignIn)
            #expect(await h.controller.deviceSignInStatus().keyHeld)

            try await h.controller.setAutomaticSignIn(enabled: false)
            #expect(try await h.keyStore.load() == nil)
            #expect(await h.notebook.record().automaticSignInOff)
            #expect(await h.controller.deviceSignInStatus().keyHeld == false)

            try await h.controller.setAutomaticSignIn(enabled: true)
            #expect(try await h.keyStore.load() == D.newKey)
            #expect(await h.notebook.record().automaticSignInOff == false)
        }
    }

    @Test("A failed turn-off changes nothing (the revoke is asked for first)")
    func automaticSignInOffFailureChangesNothing() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key, ownToken: D.ownToken)
            D.route(h.log) { action in action == "auth_enrol_keys_revoke" ? (503, Data()) : (200, D.meOK) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            await #expect(throws: APIError.self) { try await h.controller.setAutomaticSignIn(enabled: false) }

            #expect(try await h.keyStore.load() == D.key)
            #expect(await h.notebook.record().automaticSignInOff == false)
        }
    }

    @Test("The automatic sign-in control is not offered in legacy mode, on a platform without keys, or in plain mode")
    func automaticSignInNotOfferedEverywhere() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let noKeys = try await D.makeHarness(ownToken: D.ownToken, supportsKeys: false)
            D.route(noKeys.log) { _ in (200, D.meOK) }
            try await noKeys.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await noKeys.controller.deviceSignInStatus().canOfferAutomaticSignIn == false)

            let plain = try await D.makeHarness(ownToken: D.ownToken, plain: true)
            D.route(plain.log) { _ in (200, D.meOK) }
            try await plain.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await plain.controller.deviceSignInStatus().canOfferAutomaticSignIn == false)

            let legacy = try await D.makeHarness(legacy: D.legacyToken)
            D.route(legacy.log) { action in action == "auth_device_enrol" ? (409, D.refusal(409, "device_signin_not_ready")) : (200, D.meOK) }
            try await legacy.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await legacy.controller.deviceSignInStatus().legacyMode)
            #expect(await legacy.controller.deviceSignInStatus().canOfferAutomaticSignIn == false)
        }
    }
}

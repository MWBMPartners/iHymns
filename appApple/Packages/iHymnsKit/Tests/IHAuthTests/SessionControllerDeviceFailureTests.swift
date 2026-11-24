// SessionControllerDeviceFailureTests.swift
// IHAuthTests
//
// ELI5: What happens when the Keychain says no. A sign-out that cannot be
// written down must not go ahead; a token that cannot be removed must not
// leave the device looking signed in; a swap whose new token cannot be saved
// must not be recorded as done.
//
// DETAILED: PR 3b review fixes (#2180). Uses `InMemoryTokenStore.setFailing`
// (forced failures) and `OperationLog` (the order of saves and deletes across
// stores). Harness: `DeviceSignInTestSupport.swift`. Launch paths are in
// `SessionControllerDeviceSignInTests.swift`; sign-in/out paths in
// `SessionControllerDeviceSignOutTests.swift`.
import Foundation
import Testing
import IHAPITestSupport
@testable import IHAPI
@testable import IHAuth

@Suite("SessionController device sign-in: failures", .serialized)
struct SessionControllerDeviceFailureTests {
    typealias D = DeviceTestData

    @Test("Swap: if the NEW token cannot be saved, the swap is not recorded and the device falls back to legacy mode")
    func swapWithFailedSaveIsNotRecorded() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken)
            await h.deviceStore.setFailing(saves: true)
            D.route(h.log) { action in action == "auth_device_enrol" ? (200, D.signInOK()) : (200, D.meOK) }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(await h.controller.state == .signedIn(token: D.legacyToken))
            #expect(await h.controller.usingLegacyToken)
            #expect(try await h.deviceStore.load() == nil)
            // Not recorded as swapped, so the next launch tries the swap again.
            #expect(await h.notebook.isLegacySwapped(hash: TokenFingerprint.sha256Hex(D.legacyToken)) == false)
        }
    }

    @Test("Path 5 (order): sign out writes the notebook FIRST, then deletes the token, then the key")
    func signOutOrderIsMarkThenTokenThenKey() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key, ownToken: D.ownToken)
            D.route(h.log) { _ in (200, D.meOK) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            h.ops.reset()

            try await h.controller.signOut()

            #expect(h.ops.all == ["notebook.save", "device.delete", "key.delete"])
        }
    }

    @Test("Sign-out everywhere (order): the notebook is written before the token is deleted")
    func signOutEverywhereOrder() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key, ownToken: D.ownToken)
            D.route(h.log) { action in
                action == "auth_signout_everywhere" ? (200, Data(#"{"tokensRevoked":2,"enrolKeysRevoked":1,"keptCurrent":false}"#.utf8)) : (200, D.meOK)
            }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            h.ops.reset()

            _ = try await h.controller.signOutEverywhere()

            #expect(h.ops.all == ["notebook.save", "device.delete", "key.delete"])
        }
    }

    @Test("Legacy sign-out: if signed-out-here cannot be saved it THROWS, and nothing is cleared or called")
    func legacySignOutFailsWhenMarkCannotBeSaved() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken, key: D.key)
            await h.notebook.markKeyDead(hash: TokenFingerprint.sha256Hex(D.key))
            D.route(h.log) { action in action == "auth_device_enrol" ? (409, D.refusal(409, "device_signin_not_ready")) : (200, D.meOK) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await h.controller.usingLegacyToken)
            h.log.reset()
            await h.notebookStore.setFailing(saves: true)

            await #expect(throws: SessionControllerError.couldNotRecordSignOut) { try await h.controller.signOut() }

            #expect(await h.controller.state == .signedIn(token: D.legacyToken))
            #expect(await h.controller.usingLegacyToken)
            #expect(h.log.actions.isEmpty)
            #expect(await h.notebook.record().signedOutHere == false)
        }
    }

    @Test("Own sign-out: the server ended it but the mark cannot be saved: throws, token kept, and trying again finishes")
    func ownSignOutRetryAfterMarkFailure() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key, ownToken: D.ownToken)
            D.route(h.log) { _ in (200, D.meOK) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            await h.notebookStore.setFailing(saves: true)

            await #expect(throws: SessionControllerError.couldNotRecordSignOut) { try await h.controller.signOut() }
            #expect(await h.controller.state == .signedIn(token: D.ownToken))
            #expect(try await h.deviceStore.load() == D.ownToken)

            // Second try: the server now says 401 (already ended), which counts as done.
            await h.notebookStore.setFailing(saves: false)
            D.route(h.log) { _ in (401, Data()) }
            try await h.controller.signOut()
            #expect(await h.controller.state == .signedOut)
            #expect(try await h.deviceStore.load() == nil)
            #expect(await h.notebook.record().signedOutHere)
        }
    }

    @Test("Sign-out: a token that cannot be deleted still leaves the device signed out, and the error says exactly that")
    func tokenDeleteFailureStillSignsOut() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key, ownToken: D.ownToken)
            D.route(h.log) { _ in (200, D.meOK) }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            await h.deviceStore.setFailing(deletes: true)

            await #expect(throws: SessionControllerError.signedOutButTokenNotRemoved) { try await h.controller.signOut() }

            #expect(await h.controller.state == .signedOut)
            #expect(await h.notebook.record().signedOutHere)
            #expect(try await h.keyStore.load() == nil) // the rest of the clean-up still ran
        }
    }

    @Test("Sign-out everywhere: the same failure is reported the same way, not as 'other devices not signed out'")
    func signOutEverywhereTokenDeleteFailure() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key, ownToken: D.ownToken)
            D.route(h.log) { action in
                action == "auth_signout_everywhere" ? (200, Data(#"{"tokensRevoked":2,"enrolKeysRevoked":1,"keptCurrent":false}"#.utf8)) : (200, D.meOK)
            }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            await h.deviceStore.setFailing(deletes: true)

            await #expect(throws: SessionControllerError.signedOutButTokenNotRemoved) { _ = try await h.controller.signOutEverywhere() }

            #expect(await h.controller.state == .signedOut)
            #expect(await h.notebook.record().signedOutHere)
        }
    }

    @Test("Sign-out everywhere: if the mark cannot be saved it throws and nothing local changes")
    func signOutEverywhereMarkFailure() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(key: D.key, ownToken: D.ownToken)
            D.route(h.log) { action in
                action == "auth_signout_everywhere" ? (200, Data(#"{"tokensRevoked":2,"enrolKeysRevoked":1,"keptCurrent":false}"#.utf8)) : (200, D.meOK)
            }
            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)
            await h.notebookStore.setFailing(saves: true)

            await #expect(throws: SessionControllerError.couldNotRecordSignOut) { _ = try await h.controller.signOutEverywhere() }

            #expect(await h.controller.state == .signedIn(token: D.ownToken))
            #expect(try await h.deviceStore.load() == D.ownToken)
            #expect(try await h.keyStore.load() == D.key)
        }
    }

    @Test("Path 3a: a 401 on the key marks it dead and CARRIES ON: the shared sign-in is then swapped")
    func deadKeyFallsThroughToSharedSwap() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let h = try await D.makeHarness(legacy: D.legacyToken, key: D.key)
            D.route(h.log) { action in
                switch action {
                case "auth_enrol_key_redeem": return (401, D.refusal(401, "enrol_key_invalid"))
                case "auth_device_enrol": return (200, D.signInOK(withKey: true))
                default: return (200, D.meOK)
                }
            }

            try await h.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(h.log.actions == ["auth_enrol_key_redeem", "auth_device_enrol"])
            #expect(await h.notebook.isKeyDead(hash: TokenFingerprint.sha256Hex(D.key)))
            #expect(await h.controller.state == .signedIn(token: D.newToken))
            #expect(try await h.deviceStore.load() == D.newToken)
            // The swap asked for a new key, because the held one is dead.
            #expect(try await h.keyStore.load() == D.newKey)
        }
    }
}

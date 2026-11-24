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
    typealias TestData = DeviceTestData

    @Test("Swap: if the NEW token cannot be saved, the swap is not recorded and the device falls back to legacy mode")
    func swapWithFailedSaveIsNotRecorded() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let harness = try await TestData.makeHarness(legacy: TestData.legacyToken)
            await harness.deviceStore.setFailing(saves: true)
            TestData.route(harness.log) { action in action == "auth_device_enrol" ? (200, TestData.signInOK()) : (200, TestData.meOK) }

            try await harness.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(await harness.controller.state == .signedIn(token: TestData.legacyToken))
            #expect(await harness.controller.usingLegacyToken)
            let actual1 = try await harness.deviceStore.load()
            #expect(actual1 == nil)
            // Not recorded as swapped, so the next launch tries the swap again.
            #expect(await harness.notebook.isLegacySwapped(hash: TokenFingerprint.sha256Hex(TestData.legacyToken)) == false)
        }
    }

    @Test("Path 5 (order): sign out writes the notebook FIRST, then deletes the token, then the key")
    func signOutOrderIsMarkThenTokenThenKey() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let harness = try await TestData.makeHarness(key: TestData.key, ownToken: TestData.ownToken)
            TestData.route(harness.log) { _ in (200, TestData.meOK) }
            try await harness.controller.restoreFromStorage(deviceSignInEnabled: true)
            harness.ops.reset()

            try await harness.controller.signOut()

            #expect(harness.ops.all == ["notebook.save", "device.delete", "key.delete"])
        }
    }

    @Test("Sign-out everywhere (order): the notebook is written before the token is deleted")
    func signOutEverywhereOrder() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let harness = try await TestData.makeHarness(key: TestData.key, ownToken: TestData.ownToken)
            TestData.route(harness.log) { action in
                action == "auth_signout_everywhere" ? (200, Data(#"{"tokensRevoked":2,"enrolKeysRevoked":1,"keptCurrent":false}"#.utf8)) : (200, TestData.meOK)
            }
            try await harness.controller.restoreFromStorage(deviceSignInEnabled: true)
            harness.ops.reset()

            _ = try await harness.controller.signOutEverywhere()

            #expect(harness.ops.all == ["notebook.save", "device.delete", "key.delete"])
        }
    }

    @Test("Legacy sign-out: if signed-out-here cannot be saved it THROWS, and nothing is cleared or called")
    func legacySignOutFailsWhenMarkCannotBeSaved() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let harness = try await TestData.makeHarness(legacy: TestData.legacyToken, key: TestData.key)
            await harness.notebook.markKeyDead(hash: TokenFingerprint.sha256Hex(TestData.key))
            TestData.route(harness.log) { action in action == "auth_device_enrol" ? (409, TestData.refusal(409, "device_signin_not_ready")) : (200, TestData.meOK) }
            try await harness.controller.restoreFromStorage(deviceSignInEnabled: true)
            #expect(await harness.controller.usingLegacyToken)
            harness.log.reset()
            await harness.notebookStore.setFailing(saves: true)

            await #expect(throws: SessionControllerError.couldNotRecordSignOut) { try await harness.controller.signOut() }

            #expect(await harness.controller.state == .signedIn(token: TestData.legacyToken))
            #expect(await harness.controller.usingLegacyToken)
            #expect(harness.log.actions.isEmpty)
            #expect(await harness.notebook.record().signedOutHere == false)
        }
    }

    @Test("Own sign-out: the server ended it but the mark cannot be saved: throws, token kept, and trying again finishes")
    func ownSignOutRetryAfterMarkFailure() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let harness = try await TestData.makeHarness(key: TestData.key, ownToken: TestData.ownToken)
            TestData.route(harness.log) { _ in (200, TestData.meOK) }
            try await harness.controller.restoreFromStorage(deviceSignInEnabled: true)
            await harness.notebookStore.setFailing(saves: true)

            await #expect(throws: SessionControllerError.couldNotRecordSignOut) { try await harness.controller.signOut() }
            #expect(await harness.controller.state == .signedIn(token: TestData.ownToken))
            let actual2 = try await harness.deviceStore.load()
            #expect(actual2 == TestData.ownToken)

            // Second try: the server now says 401 (already ended), which counts as done.
            await harness.notebookStore.setFailing(saves: false)
            TestData.route(harness.log) { _ in (401, Data()) }
            try await harness.controller.signOut()
            #expect(await harness.controller.state == .signedOut)
            let actual3 = try await harness.deviceStore.load()
            #expect(actual3 == nil)
            #expect(await harness.notebook.record().signedOutHere)
        }
    }

    @Test("Sign-out: a token that cannot be deleted still leaves the device signed out, and the error says exactly that")
    func tokenDeleteFailureStillSignsOut() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let harness = try await TestData.makeHarness(key: TestData.key, ownToken: TestData.ownToken)
            TestData.route(harness.log) { _ in (200, TestData.meOK) }
            try await harness.controller.restoreFromStorage(deviceSignInEnabled: true)
            await harness.deviceStore.setFailing(deletes: true)

            await #expect(throws: SessionControllerError.signedOutButTokenNotRemoved) { try await harness.controller.signOut() }

            #expect(await harness.controller.state == .signedOut)
            #expect(await harness.notebook.record().signedOutHere)
            let actual4 = try await harness.keyStore.load()
            #expect(actual4 == nil) // the rest of the clean-up still ran
        }
    }

    @Test("Sign-out everywhere: the same failure is reported the same way, not as 'other devices not signed out'")
    func signOutEverywhereTokenDeleteFailure() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let harness = try await TestData.makeHarness(key: TestData.key, ownToken: TestData.ownToken)
            TestData.route(harness.log) { action in
                action == "auth_signout_everywhere" ? (200, Data(#"{"tokensRevoked":2,"enrolKeysRevoked":1,"keptCurrent":false}"#.utf8)) : (200, TestData.meOK)
            }
            try await harness.controller.restoreFromStorage(deviceSignInEnabled: true)
            await harness.deviceStore.setFailing(deletes: true)

            await #expect(throws: SessionControllerError.signedOutButTokenNotRemoved) { _ = try await harness.controller.signOutEverywhere() }

            #expect(await harness.controller.state == .signedOut)
            #expect(await harness.notebook.record().signedOutHere)
        }
    }

    @Test("Sign-out everywhere: if the mark cannot be saved it throws and nothing local changes")
    func signOutEverywhereMarkFailure() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let harness = try await TestData.makeHarness(key: TestData.key, ownToken: TestData.ownToken)
            TestData.route(harness.log) { action in
                action == "auth_signout_everywhere" ? (200, Data(#"{"tokensRevoked":2,"enrolKeysRevoked":1,"keptCurrent":false}"#.utf8)) : (200, TestData.meOK)
            }
            try await harness.controller.restoreFromStorage(deviceSignInEnabled: true)
            await harness.notebookStore.setFailing(saves: true)

            await #expect(throws: SessionControllerError.couldNotRecordSignOut) { _ = try await harness.controller.signOutEverywhere() }

            #expect(await harness.controller.state == .signedIn(token: TestData.ownToken))
            let actual5 = try await harness.deviceStore.load()
            #expect(actual5 == TestData.ownToken)
            let actual6 = try await harness.keyStore.load()
            #expect(actual6 == TestData.key)
        }
    }

    @Test("Path 3a: a 401 on the key marks it dead and CARRIES ON: the shared sign-in is then swapped")
    func deadKeyFallsThroughToSharedSwap() async throws {
        try await MockTransportLock.shared.withLock { () async throws in
            defer { MockURLProtocol.requestHandler = nil }
            let harness = try await TestData.makeHarness(legacy: TestData.legacyToken, key: TestData.key)
            TestData.route(harness.log) { action in
                switch action {
                case "auth_enrol_key_redeem": return (401, TestData.refusal(401, "enrol_key_invalid"))
                case "auth_device_enrol": return (200, TestData.signInOK(withKey: true))
                default: return (200, TestData.meOK)
                }
            }

            try await harness.controller.restoreFromStorage(deviceSignInEnabled: true)

            #expect(harness.log.actions == ["auth_enrol_key_redeem", "auth_device_enrol"])
            #expect(await harness.notebook.isKeyDead(hash: TokenFingerprint.sha256Hex(TestData.key)))
            #expect(await harness.controller.state == .signedIn(token: TestData.newToken))
            let actual7 = try await harness.deviceStore.load()
            #expect(actual7 == TestData.newToken)
            // The swap asked for a new key, because the held one is dead.
            let actual8 = try await harness.keyStore.load()
            #expect(actual8 == TestData.newKey)
        }
    }
}

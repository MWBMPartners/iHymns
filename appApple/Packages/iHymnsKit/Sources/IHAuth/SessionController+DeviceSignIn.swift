// SessionController+DeviceSignIn.swift
// IHAuth
//
// ELI5: The account-side helpers of "one sign-in per device": the details sent
// with a sign-in, "signed out here", signing out everywhere, the automatic
// sign-in switch, and what the account screen needs to show.
//
// DETAILED: PR 3b step I2c (#2180), plan `.claude/account-profile-pr3-plan.md`
// section 3 (I2c) and `.claude/analysis/per-device-sign-in-design.md`. The
// launch decision tree lives in `SessionController+Launch.swift`; every member
// `SessionController.swift` marked `internal` is used here. Rules this code
// never breaks (the guards check the first two from the tree):
//   - it never writes to or deletes the shared item (it can only read it);
//   - it never keeps a token or key in a log, and only fingerprints in the notebook;
//   - the order is always "record the state change, THEN delete", so a crash in
//     the middle cannot leave a device that signs itself back in; and if the
//     record cannot be saved, nothing is deleted and the caller is told.
// Deviation from plan item 5 ("sign out never touches the key"): PR 3a's
// security review made the SERVER cancel all the person's keys whenever a
// device's own sign-in ends, so the key in iCloud is dead after a sign-out and
// this device now deletes its copy (the deletion reaches the other devices
// through iCloud, which is right: it is dead for them too).
import Foundation
import IHAPI
import IHLog
import IHModels

/// What the account screen needs to know about device sign-in.
///
/// ELI5: Which of the "sign out everywhere" and "automatic sign-in" controls to show, and how.
public struct DeviceSignInStatus: Sendable, Equatable {
    /// The site has "one sign-in per device" switched on.
    public let switchedOn: Bool
    /// This device is using the older shared sign-in in memory (the swap could not happen yet).
    public let legacyMode: Bool
    /// This platform can hold an automatic sign-in key (false on tvOS and watchOS).
    public let keySupported: Bool
    /// A key not known to be dead is in iCloud Keychain.
    public let keyHeld: Bool
    /// The person turned automatic sign-in off on this device.
    public let automaticSignInOff: Bool

    /// Show the "sign in automatically on my other Apple devices" control?
    public var canOfferAutomaticSignIn: Bool { switchedOn && keySupported && !legacyMode }
}

extension SessionController {
    // MARK: - Manual sign-in, sign-out helpers

    /// The device details to send with a sign-in, or `nil` in plain mode (or if the install id cannot be read).
    func currentDeviceDetails() async -> DeviceSignInDetails? {
        guard let support = deviceSignIn, let installId = try? await support.deviceIdentity.installId() else { return nil }
        return DeviceSignInDetails.current(installId: installId)
    }

    /// This install's random id, or `nil` in plain mode (no device sign-in support) or if it cannot be read.
    ///
    /// ELI5: "What is this device's name-tag, if it has one?" Used for the anonymous song-view
    /// report's per-device limit; never throws.
    public func installIdIfAvailable() async -> String? {
        guard let support = deviceSignIn else { return nil }
        return try? await support.deviceIdentity.installId()
    }

    /// After a person signs in by hand: clear "signed out here", and (site
    /// switch on, automatic sign-in not turned off, no usable key yet) make a
    /// key for the person's other devices. Best effort: a sign-in never fails over a key.
    func afterManualSignIn() async {
        guard let support = deviceSignIn else { return }
        try? await support.signInState.setSignedOutHere(false)
        guard deviceSignInEnabled else { return }
        let record = await support.signInState.record()
        guard await keyIsNeeded(support, record: record), let store = support.enrolKeyStore else { return }
        if let issue = try? await apiClient.authEnrolKeyCreate() {
            try? await store.save(issue.enrolKey)
        }
    }

    /// Sets "signed out here" (a no-op in plain mode).
    ///
    /// - Throws: `SessionControllerError.couldNotRecordSignOut` if the mark could not be saved.
    func markSignedOutHere() async throws {
        guard let notebook = deviceSignIn?.signInState else { return }
        do {
            try await notebook.setSignedOutHere(true)
        } catch {
            IHLog.auth.error("session: could not save signed-out-here")
            throw SessionControllerError.couldNotRecordSignOut
        }
    }

    /// Deletes this device's copy of the automatic sign-in key (a no-op in plain
    /// mode, or where keys are unsupported). The key is dead after a sign-out
    /// and this reaches the other devices through iCloud.
    func deleteKeyCopy() async {
        try? await deviceSignIn?.enrolKeyStore?.delete()
    }

    // MARK: - Sign out everywhere, automatic sign-in, status

    /// Ends every sign-in the person has (this device included) and cancels
    /// every automatic sign-in key, then forgets everything local.
    ///
    /// ELI5: "Sign every one of my devices out, this one too."
    ///
    /// DETAILED: A `.unauthorized` answer means this sign-in was already dead
    /// (the goal is met), so the local teardown still runs. Any OTHER failure
    /// (offline, maintenance, ...) is thrown and NOTHING local changes, so the
    /// caller can say "the other devices were not signed out" and let the
    /// person try again (the web card does the same). The shared item is never
    /// touched: the server has just ended that token.
    ///
    /// - Returns: what the server ended, or `nil` if the token was already dead.
    /// - Throws: `.unauthorized` if called while signed out, or the failure above.
    @discardableResult
    public func signOutEverywhere() async throws -> SignOutEverywhereResult? {
        guard case .signedIn = state else { throw APIError.unauthorized }
        let result: SignOutEverywhereResult?
        do {
            result = try await apiClient.authSignOutEverywhere()
        } catch APIError.unauthorized {
            result = nil
        }
        // The server has ended every sign-in. Mark first (a failure throws, nothing local is
        // changed, and trying again finishes: the server answers 401 = already done).
        try await markSignedOutHere()
        // A failed delete must not leave this device looking signed in: finish, then say so.
        var tokenLeftBehind = false
        if !usingLegacyToken {
            do { try await tokenStore.delete() } catch { tokenLeftBehind = true }
        }
        await deleteKeyCopy()
        usingLegacyToken = false
        await apiClient.updateBearerToken(nil)
        setState(.signedOut)
        if tokenLeftBehind { throw SessionControllerError.signedOutButTokenNotRemoved }
        return result
    }

    /// Turns "sign in automatically on my other Apple devices" on or off.
    ///
    /// ELI5: "Keep (or stop keeping) a key in iCloud so my other devices can sign themselves in."
    ///
    /// DETAILED: Off cancels every key on the server FIRST, then remembers the
    /// choice and deletes the local copy, so a failed call changes nothing. On
    /// makes a key, saves it, and only then clears the choice. Does nothing
    /// unless this device has its OWN sign-in (a legacy-mode device cannot make
    /// a key) and supports keys.
    ///
    /// - Throws: whatever the key calls throw (`.offline`, `.server(status:message:)`, ...).
    public func setAutomaticSignIn(enabled: Bool) async throws {
        guard let support = deviceSignIn, case .signedIn = state, !usingLegacyToken else { return }
        if enabled {
            guard let store = support.enrolKeyStore else { return }
            let issue = try await apiClient.authEnrolKeyCreate()
            try await store.save(issue.enrolKey)
            await support.signInState.setAutomaticSignInOff(false)
        } else {
            _ = try await apiClient.authEnrolKeysRevoke()
            await support.signInState.setAutomaticSignInOff(true)
            try? await support.enrolKeyStore?.delete()
        }
    }

    /// What the account screen needs to draw its device sign-in controls.
    public func deviceSignInStatus() async -> DeviceSignInStatus {
        guard let support = deviceSignIn else {
            return DeviceSignInStatus(switchedOn: false, legacyMode: false, keySupported: false, keyHeld: false, automaticSignInOff: false)
        }
        let record = await support.signInState.record()
        let held = await usableKey(support, record: record) != nil
        return DeviceSignInStatus(
            switchedOn: deviceSignInEnabled,
            legacyMode: usingLegacyToken,
            keySupported: support.enrolKeyStore != nil,
            keyHeld: held,
            automaticSignInOff: record.automaticSignInOff
        )
    }
}

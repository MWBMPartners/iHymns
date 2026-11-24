// SessionController+DeviceSignIn.swift
// IHAuth
//
// ELI5: How the app gives THIS device its own sign-in: at launch it checks for
// its own sign-in; failing that, it tries an automatic sign-in key from iCloud
// Keychain, then the old shared sign-in (swapping it for its own). It also
// remembers "signed out here" so a signed-out device never signs itself back
// in, and it can sign out everywhere.
//
// DETAILED: PR 3b step I2c (#2180), plan `.claude/account-profile-pr3-plan.md`
// section 3 (I2c) and `.claude/analysis/per-device-sign-in-design.md`. Split
// out of `SessionController.swift` (LOC budget); every member that file marked
// `internal` is used here. The launch decision, in order:
//
//   1. THIS device has its own token -> check it with `auth_me` (as always).
//      A 401 means it was signed out or ran out: remember "signed out here",
//      delete the token (safe: it is not synced), show signed out.
//   2. No own token, and "signed out here" is set -> stay signed out. Only a
//      person signing in by hand clears the mark. This is what stops a lost,
//      remotely signed-out, or timed-out device signing itself back in.
//   3. Otherwise, only when the site has the switch on (`app_status.deviceSignin`):
//        a. a key in iCloud Keychain that is not known dead -> redeem it. 401
//           (one answer for every unusable key) -> remember the key as dead.
//        b. else a shared older-app sign-in not yet swapped -> `auth_device_enrol`.
//           On success the new token is saved FIRST, only then is the shared
//           token's fingerprint recorded. 401 -> that shared token is dead,
//           signed out. 403 `install_signed_out` -> remember "signed out here".
//           Anything else (409, 400, another 403, offline) -> LEGACY MODE.
//      With the switch off, or no key and no swap possible, a usable shared
//      sign-in is simply used in legacy mode.
//   LEGACY MODE = the shared token held in MEMORY ONLY, never copied into any
//   store, retried at the next launch. Signing out of it makes no server call.
//
// Rules this file never breaks (the guards check the first two from the tree):
//   - it never writes to or deletes the shared item (it can only read it);
//   - it never keeps a token or key in a log, and only fingerprints in the notebook;
//   - the order is always "record the state change, THEN delete", so a crash in
//     the middle cannot leave a device that signs itself back in.
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
    /// Re-hydrates `state` from whatever sign-in this device has — called once
    /// at app launch, before the first screen renders, so a returning signed-in
    /// user never sees a sign-in prompt flash.
    ///
    /// ELI5: "Check the safe — were we already signed in? And if so, is that
    /// pass still good? If not, can this device get its own?"
    ///
    /// DETAILED (native login/account UI task, completing #1398's noted gap): a
    /// token can go bad between launches (revoked elsewhere, expired past 30
    /// days, the account disabled), so a stored token is validated with a light
    /// `auth_me` call before `.signedIn` is published:
    ///   - `auth_me` succeeds -> `.signedIn(token:)`.
    ///   - `.unauthorized` -> DEFINITIVELY dead: clear the local copy (and, with
    ///     device sign-in, remember "signed out here"), logged `.error` because
    ///     it is an involuntary sign-out.
    ///   - anything else (`.offline`, `.maintenance`, `.rateLimited`,
    ///     `.server`, `.decoding`) -> fail OPEN: still `.signedIn(token:)`. A
    ///     network blip at launch says nothing about the token, and this
    ///     codebase's posture toward transient failures is "never punitive"
    ///     (rule #17's 503 handling, #1010's graceful degrade).
    /// With no stored token this is the decision tree in this file's header.
    ///
    /// - Parameter deviceSignInEnabled: the site's switch (`app_status.deviceSignin`).
    ///   `false` by default, which keeps swaps and keys off (and every older
    ///   call site and test exactly as it was).
    public func restoreFromStorage(deviceSignInEnabled: Bool = false) async throws {
        // One swap at a time: a second launch-time call while one is running does nothing.
        guard !isRestoring else { return }
        isRestoring = true
        defer { isRestoring = false }
        self.deviceSignInEnabled = deviceSignInEnabled

        if let token = try await tokenStore.load() {
            await restoreOwnToken(token)
            return
        }
        guard let support = deviceSignIn else {
            setState(.signedOut)
            return
        }
        await restoreWithoutOwnToken(support)
    }

    // MARK: - Launch: own token

    /// Step 1: this device has its own token; check it.
    private func restoreOwnToken(_ token: String) async {
        usingLegacyToken = false
        await apiClient.updateBearerToken(token)
        do {
            // Captured so the `.notice` transition log can carry the user id (#1505).
            let user = try await apiClient.authMe()
            setState(.signedIn(token: token), userId: user.id)
        } catch APIError.unauthorized {
            // "Signed out here" FIRST, then delete: a crash between the two can
            // then never leave a device that signs itself back in.
            await markSignedOutHere()
            try? await tokenStore.delete()
            await apiClient.updateBearerToken(nil)
            IHLog.auth.error("session: stored token rejected (401) on restore — forcing sign-out")
            setState(.signedOut)
        } catch {
            setState(.signedIn(token: token))
        }
    }

    // MARK: - Launch: no own token

    /// Steps 2 and 3: no own token on this device.
    private func restoreWithoutOwnToken(_ support: DeviceSignInSupport) async {
        let record = await support.signInState.record()
        if record.signedOutHere {
            setState(.signedOut)
            return
        }

        if deviceSignInEnabled, let key = await usableKey(support, record: record) {
            switch await redeemKey(key, support: support) {
            case .signedIn, .signedOut:
                return
            case .tryNext:
                break
            }
        }

        guard let legacy = try? await support.legacyReader.load(), !legacy.isEmpty else {
            setState(.signedOut)
            return
        }
        if record.swappedLegacyHashes.contains(TokenFingerprint.sha256Hex(legacy)) {
            // Already swapped (or dead) and yet no own token: do not quietly use it again.
            setState(.signedOut)
            return
        }
        if deviceSignInEnabled {
            await enrolWithLegacy(legacy, support: support)
        } else {
            await enterLegacyMode(legacy, support: support)
        }
    }

    private enum StepOutcome { case signedIn, signedOut, tryNext }

    /// Step 3a: swap an automatic sign-in key for this device's own sign-in.
    private func redeemKey(_ key: String, support: DeviceSignInSupport) async -> StepOutcome {
        guard let device = await currentDeviceDetails() else { return .tryNext }
        do {
            let result = try await apiClient.authEnrolKeyRedeem(enrolKey: key, device: device)
            try await tokenStore.save(result.session.token)
            await apiClient.updateBearerToken(result.session.token)
            usingLegacyToken = false
            setState(.signedIn(token: result.session.token), userId: result.session.user.id)
            return .signedIn
        } catch APIError.unauthorized {
            // One answer for every unusable key: remember this one as dead so it is not retried every launch.
            await support.signInState.markKeyDead(hash: TokenFingerprint.sha256Hex(key))
            await apiClient.updateBearerToken(nil)
            setState(.signedOut)
            return .signedOut
        } catch {
            return .tryNext
        }
    }

    /// Step 3b: swap the older apps' shared sign-in for this device's own.
    private func enrolWithLegacy(_ legacy: String, support: DeviceSignInSupport) async {
        guard let device = await currentDeviceDetails() else {
            await enterLegacyMode(legacy, support: support)
            return
        }
        let record = await support.signInState.record()
        let wantKey = await keyIsNeeded(support, record: record)
        // The swap is authenticated with the shared token, so it is the bearer for this one call.
        await apiClient.updateBearerToken(legacy)
        do {
            let result = try await apiClient.authDeviceEnrol(device: device, createEnrolKey: wantKey)
            // Save the new token FIRST; only then record that the shared one was swapped.
            try await tokenStore.save(result.session.token)
            await support.signInState.markLegacySwapped(hash: TokenFingerprint.sha256Hex(legacy))
            if wantKey, let newKey = result.enrolKey, let store = support.enrolKeyStore {
                try? await store.save(newKey)
            }
            await apiClient.updateBearerToken(result.session.token)
            usingLegacyToken = false
            setState(.signedIn(token: result.session.token), userId: result.session.user.id)
        } catch APIError.unauthorized {
            // The shared token itself is dead. Remember it as handled; the shared ITEM is not touched.
            await support.signInState.markLegacySwapped(hash: TokenFingerprint.sha256Hex(legacy))
            await apiClient.updateBearerToken(nil)
            setState(.signedOut)
        } catch APIError.server(let status, let message)
            where status == 403 && message == DeviceSignInReason.installSignedOut.rawValue {
            // This install's sign-in ended earlier: it must be signed in by hand.
            await support.signInState.setSignedOutHere(true)
            await apiClient.updateBearerToken(nil)
            setState(.signedOut)
        } catch {
            // 409 (switched off / not ready), 400, any other 403, offline...: keep the shared sign-in in memory and try next launch.
            await enterLegacyMode(legacy, support: support)
        }
    }

    /// Legacy mode: use the shared token from memory only. Never saved anywhere.
    private func enterLegacyMode(_ legacy: String, support: DeviceSignInSupport) async {
        usingLegacyToken = true
        await apiClient.updateBearerToken(legacy)
        do {
            let user = try await apiClient.authMe()
            setState(.signedIn(token: legacy), userId: user.id)
        } catch APIError.unauthorized {
            usingLegacyToken = false
            await support.signInState.markLegacySwapped(hash: TokenFingerprint.sha256Hex(legacy))
            await apiClient.updateBearerToken(nil)
            setState(.signedOut)
        } catch {
            setState(.signedIn(token: legacy)) // fail open, as for an own token
        }
    }

    // MARK: - Keys

    /// A key in iCloud Keychain that is not known to be dead, or `nil`.
    private func usableKey(_ support: DeviceSignInSupport, record: DeviceSignInRecord) async -> String? {
        guard let store = support.enrolKeyStore, let key = try? await store.load(), !key.isEmpty else { return nil }
        return record.deadKeyHashes.contains(TokenFingerprint.sha256Hex(key)) ? nil : key
    }

    /// Should this device ask for a (new) key? Only where keys are supported,
    /// the person has not turned them off, and no usable key is already held.
    private func keyIsNeeded(_ support: DeviceSignInSupport, record: DeviceSignInRecord) async -> Bool {
        guard support.enrolKeyStore != nil, !record.automaticSignInOff else { return false }
        return await usableKey(support, record: record) == nil
    }

    // MARK: - Manual sign-in, sign-out helpers

    /// The device details to send with a sign-in, or `nil` in plain mode (or if the install id cannot be read).
    func currentDeviceDetails() async -> DeviceSignInDetails? {
        guard let support = deviceSignIn, let installId = try? await support.deviceIdentity.installId() else { return nil }
        return DeviceSignInDetails.current(installId: installId)
    }

    /// After a person signs in by hand: clear "signed out here", and (site
    /// switch on, automatic sign-in not turned off, no usable key yet) make a
    /// key for the person's other devices. Best effort: a sign-in never fails over a key.
    func afterManualSignIn() async {
        guard let support = deviceSignIn else { return }
        await support.signInState.setSignedOutHere(false)
        guard deviceSignInEnabled else { return }
        let record = await support.signInState.record()
        guard await keyIsNeeded(support, record: record), let store = support.enrolKeyStore else { return }
        if let issue = try? await apiClient.authEnrolKeyCreate() {
            try? await store.save(issue.enrolKey)
        }
    }

    /// Sets "signed out here" (a no-op in plain mode).
    func markSignedOutHere() async {
        await deviceSignIn?.signInState.setSignedOutHere(true)
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
        await markSignedOutHere()
        if !usingLegacyToken { try await tokenStore.delete() }
        await deleteKeyCopy()
        usingLegacyToken = false
        await apiClient.updateBearerToken(nil)
        setState(.signedOut)
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

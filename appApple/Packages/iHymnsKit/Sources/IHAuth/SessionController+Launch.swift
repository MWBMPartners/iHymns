// SessionController+Launch.swift
// IHAuth
//
// ELI5: What the app does at launch to decide who is signed in: its own
// sign-in first; failing that, an automatic sign-in key from iCloud Keychain,
// then the old shared sign-in (swapping it for its own). A device that was
// signed out here never signs itself back in.
//
// DETAILED: PR 3b step I2c (#2180), split out of `SessionController+DeviceSignIn.swift`
// for the repo's LOC budget. The launch decision, in order:
//
//   1. THIS device has its own token -> check it with `auth_me` (as always).
//      A 401 means it was signed out or ran out: remember "signed out here",
//      delete the token (safe: it is not synced), show signed out.
//   2. No own token, and "signed out here" is set -> stay signed out. Only a
//      person signing in by hand clears the mark. This is what stops a lost,
//      remotely signed-out, or timed-out device signing itself back in.
//   3. Otherwise, only when the site has the switch on (`app_status.deviceSignin`):
//        a. a key in iCloud Keychain that is not known dead -> redeem it. 401
//           (one answer for every unusable key) -> remember the key as dead
//           and carry on to step b.
//        b. else a shared older-app sign-in not yet swapped -> `auth_device_enrol`.
//           On success the new token is saved FIRST, only then is the shared
//           token's fingerprint recorded. 401 -> that shared token is dead,
//           signed out. 403 `install_signed_out` -> remember "signed out here".
//           Anything else (409, 400, another 403, offline) -> LEGACY MODE.
//      With the switch off, or no key and no swap possible, a usable shared
//      sign-in is simply used in legacy mode.
//   LEGACY MODE = the shared token held in MEMORY ONLY, never copied into any
//   store, retried at the next launch. Signing out of it makes no server call.
// The rules every file here keeps are listed in `SessionController+DeviceSignIn.swift`.
import Foundation
import IHAPI
import IHLog
import IHModels

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
            do {
                try await markSignedOutHere()
                try? await tokenStore.delete()
            } catch {
                // The mark could not be saved: keep the dead token (not synced, harmless) so the
                // next launch gets the same 401 and tries again, rather than deleting it unmarked.
            }
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
            case .signedIn:
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

    private enum StepOutcome { case signedIn, tryNext }

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
            // One answer for every unusable key: remember this one as dead so it is not retried every launch,
            // then carry on: a shared sign-in may still be swappable, and a dead key must not strand the device.
            await support.signInState.markKeyDead(hash: TokenFingerprint.sha256Hex(key))
            return .tryNext
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
            try? await support.signInState.setSignedOutHere(true)
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
    func usableKey(_ support: DeviceSignInSupport, record: DeviceSignInRecord) async -> String? {
        guard let store = support.enrolKeyStore, let key = try? await store.load(), !key.isEmpty else { return nil }
        return record.deadKeyHashes.contains(TokenFingerprint.sha256Hex(key)) ? nil : key
    }

    /// Should this device ask for a (new) key? Only where keys are supported,
    /// the person has not turned them off, and no usable key is already held.
    func keyIsNeeded(_ support: DeviceSignInSupport, record: DeviceSignInRecord) async -> Bool {
        guard support.enrolKeyStore != nil, !record.automaticSignInOff else { return false }
        return await usableKey(support, record: record) == nil
    }
}

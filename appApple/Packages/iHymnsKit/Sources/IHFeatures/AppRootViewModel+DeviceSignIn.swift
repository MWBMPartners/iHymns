// AppRootViewModel+DeviceSignIn.swift
// IHFeatures
//
// ELI5: What the account screen's two new controls call: "Sign out
// everywhere" and "Sign in automatically on my other Apple devices", plus the
// question "which of those should I even show?".
//
// DETAILED: PR 3b step I2d (#2180). Thin pass-throughs to the session
// controller, in the same "every screen talks to the engines through the one
// root view model" shape as `AppRootViewModel+Auth.swift`. The two things a
// pass-through adds are the LOCAL teardown after a successful
// sign-out-everywhere (the identical `resetLocalUserState()` a plain sign-out
// does: a different account may use this device next) and nothing else; every
// rule about tokens, keys and "signed out here" lives in
// `IHAuth/SessionController+DeviceSignIn.swift`.
import Foundation
import IHAPI
import IHAuth
import IHModels

extension AppRootViewModel {
    /// What the account screen needs to decide whether to draw the device
    /// sign-in controls, and how.
    ///
    /// ELI5: "Is 'one sign-in per device' on, and does this device hold a key?"
    public func deviceSignInStatus() async -> DeviceSignInStatus {
        await sessionController.deviceSignInStatus()
    }

    /// Signs EVERY device the person owns out (this one too), cancels every
    /// automatic sign-in key, then forgets this device's cached account data,
    /// exactly like a plain sign-out.
    ///
    /// ELI5: "Sign all my devices out, and forget my stuff on this one."
    ///
    /// - Throws: Whatever `SessionController.signOutEverywhere()` throws. A
    ///   failure (offline, maintenance, ...) leaves this device signed in and
    ///   its data untouched, so the screen can say "your other devices were
    ///   not signed out" and offer a retry.
    ///
    /// Like `signOut(discardUnsent:)` (PR 3b, I5): with the account sync on, unsent changes are sent
    /// first, and if any remain this throws `SignOutBlockedByUnsentChanges` before anything is done.
    public func signOutEverywhere(discardUnsent: Bool = false) async throws {
        try await ensureNothingUnsent(discardUnsent: discardUnsent)
        do {
            try await sessionController.signOutEverywhere()
        } catch SessionControllerError.signedOutButTokenNotRemoved {
            // Signed out everywhere, this device included: clear the cached data, then say what happened.
            await resetLocalUserState()
            throw SessionControllerError.signedOutButTokenNotRemoved
        }
        await resetLocalUserState()
    }

    /// Turns "sign in automatically on my other Apple devices" on or off.
    ///
    /// ELI5: "Keep (or stop keeping) a key in iCloud for my other devices."
    ///
    /// - Throws: Whatever `SessionController.setAutomaticSignIn(enabled:)` throws.
    public func setAutomaticSignIn(enabled: Bool) async throws {
        try await sessionController.setAutomaticSignIn(enabled: enabled)
    }
}

// AppRootViewModel+ProfileSync.swift
// IHFeatures
//
// ELI5: When the shared-settings sync runs: after sign-in and at launch, when
// the app comes back to the front, and a moment after a setting changes.
//
// DETAILED: PR 3b step I4 (#2180). The engine itself is `ProfileSyncEngine`;
// this file only decides WHEN to call it and whether it may run at all. A sync
// runs only when the person is signed in, the site has the new account sync
// switched on (`app_status.profileSync`, read at launch) and the answer has not
// been "not here" (a 409 `profile_sync_disabled` / `profile_sync_not_ready`,
// remembered by the coordinator), and a user id is known to key the person's
// notebook by. Anything else means "do nothing", which is exactly what the app
// did before this existed. `sceneBecameActive(_:)` replaces the single call
// `RootContainerView` used to make for a scene change (it still tells the live
// engines first), because that file is at the repo's 400-line budget and this
// is the one hook that fires when SwiftUI's `@AppStorage` settings (text size,
// show-chords) may have changed, which offer no write callback of their own.
import Foundation
import IHModels

extension AppRootViewModel {
    /// Syncs the shared settings now, if allowed. Never throws and never blocks the screen.
    ///
    /// ELI5: "Catch up my settings with my account."
    public func syncProfileSettings() async {
        guard await isSignedInNow(), appStatus?.profileSync == true, let userId = currentUser?.id else { return }
        await profileSync.sync(userId: userId, apiClient: apiClient)
    }

    /// Call when a shared setting changed on this device (or may have): syncs after a short pause.
    ///
    /// ELI5: "I changed a setting: send it in a moment."
    public func profileSettingsChanged() {
        guard sessionState.isSignedIn, appStatus?.profileSync == true else { return }
        profileSync.schedule { [weak self] in await self?.syncProfileSettings() }
    }

    /// The scene moved to or from the front: tells the live engines (as before), and when it
    /// came to the front, catches the shared settings up.
    public func sceneBecameActive(_ isActive: Bool) {
        setLiveScenePhaseActive(isActive)
        if isActive { profileSettingsChanged() }
    }
}

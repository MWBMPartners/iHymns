// AppRootViewModel+ViewHistory.swift
// IHFeatures
//
// ELI5: Tells the server "this signed-in person opened this song" (so it shows
// up in their history on every device and the website), and reads that history
// back.
//
// DETAILED: PR 3b step I3 (#2180), widened by the owner on 2026-11-24. The
// three calls are thin pass-throughs to `APIClient`, in the same "every screen
// talks to the engines through the one root view model" shape as the other
// `AppRootViewModel+*.swift` files. Two decisions worth knowing:
//   - A view is reported whether or not anyone is signed in, like the website.
//     Signed in, it carries the sign-in and lands in that person's own history.
//     Signed out, it carries no sign-in and no person at all, only this
//     install's random id (`device_id`), which the server uses to limit how
//     fast one device can report and does not keep with the view. Song views
//     are not the analytics events (`IHAnalyticsService`): they feed the site's
//     popular list, as the website's do, so the analytics consent switch does
//     not apply to them. The call uses `Endpoint.optionalAuth` (send the
//     sign-in if there is one), because `requiresAuth` is all-or-nothing.
//   - Reporting is fire-and-forget: a failure, a 429 ("too many views") or no
//     connection is ignored on purpose. A missed view costs one line of
//     history; it must never show an error or slow a song down.
import Foundation
import IHAPI
import IHModels

extension AppRootViewModel {
    /// Reports one view of `songId` to the server, signed in or not. Every failure is ignored.
    ///
    /// ELI5: "Tell the server I opened this song; if that fails, never mind."
    func reportSongView(_ songId: SongID) async {
        // Signed out: send this install's random id for the server's per-device limit. Signed in: the person is the key.
        var deviceId: String?
        if !sessionState.isSignedIn {
            deviceId = await sessionController.installIdIfAvailable()
        }
        // Ignored on purpose: see this file's header (429, offline, maintenance are all fine to drop).
        try? await apiClient.songView(songId: songId, deviceId: deviceId)
    }

    /// The signed-in person's recently viewed songs, newest first. Empty while
    /// signed out or when the server cannot be reached (the Home screen simply
    /// shows nothing extra).
    ///
    /// ELI5: "What have I opened lately, on any device?"
    public func songHistory() async -> [ViewedSong] {
        guard sessionState.isSignedIn else { return [] }
        return (try? await apiClient.songHistory()) ?? []
    }

    /// The signed-in person's most viewed songs, or `nil` when they are not
    /// available: signed out, offline, or the site has the new account
    /// behaviour switched off (the server then answers 409 `profile_sync_disabled`).
    ///
    /// ELI5: "Which songs do I open most? (Or nothing, if that is not on here.)"
    public func myMostViewed(period: MostViewedPeriod? = nil) async -> MostViewedResult? {
        guard sessionState.isSignedIn else { return nil }
        return try? await apiClient.myMostViewed(period: period)
    }
}

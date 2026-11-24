// AppRootViewModel+ViewHistory.swift
// IHFeatures
//
// ELI5: Tells the server "this signed-in person opened this song" (so it shows
// up in their history on every device and the website), and reads that history
// back.
//
// DETAILED: PR 3b step I3 (#2180). The three calls are thin pass-throughs to
// `APIClient`, in the same "every screen talks to the engines through the one
// root view model" shape as the other `AppRootViewModel+*.swift` files. Two
// decisions worth knowing:
//   - A view is reported ONLY while signed in. The server also accepts
//     anonymous views (they feed site-wide popularity), but sending them from
//     the app would be a new, silent usage report this app has never made, and
//     the app's analytics have their own consent switch (`IHAnalyticsService`).
//     A signed-in person's own history is account data, which they have chosen
//     to keep on the server. If the owner wants anonymous counts too, that is
//     an owner decision to add later (it would also need an optional-auth
//     endpoint, since `Endpoint.requiresAuth` is all-or-nothing today).
//   - Reporting is fire-and-forget: a failure, a 429 ("too many views") or no
//     connection is ignored on purpose. A missed view costs one line of
//     history; it must never show an error or slow a song down.
import Foundation
import IHAPI
import IHModels

extension AppRootViewModel {
    /// Reports one view of `songId` to the server, if signed in. Every failure is ignored.
    ///
    /// ELI5: "Tell the server I opened this song, if I am signed in; if that fails, never mind."
    func reportSongView(_ songId: SongID) async {
        guard sessionState.isSignedIn else { return }
        // Ignored on purpose: see this file's header (429, offline, maintenance are all fine to drop).
        try? await apiClient.songView(songId: songId)
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

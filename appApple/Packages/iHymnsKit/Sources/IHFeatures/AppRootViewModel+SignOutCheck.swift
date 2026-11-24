// AppRootViewModel+SignOutCheck.swift
// IHFeatures
//
// ELI5: Before the app signs you out and forgets your favourites, set lists and
// settings on this device, it first tries to send anything still waiting, and
// if some changes could not be sent it asks you instead of silently losing them.
//
// DETAILED: PR 3b step I5 (#2180), the Apple twin of the web's guarded sign-out
// (rule #54 in `.claude/CLAUDE.md`). Signing out clears the personal data
// cached here (`resetLocalUserState()`), so a change that never reached the
// account would be lost for good. When the site has the new account sync on
// (`app_status.profileSync`), `prepareSignOut()` first SENDS what is waiting
// (favourite and set-list changes queued while offline, and the shared
// settings), then COUNTS what is still unsent. If anything is left,
// `signOut(discardUnsent:)` throws `SignOutBlockedByUnsentChanges` instead of
// clearing, and the Account screen asks (Try again, up to three times, / Sign
// out anyway / Cancel, which stays signed in). Only an explicit
// `discardUnsent: true` skips the question, so no screen can forget to ask. With
// the account sync off, nothing here runs and sign-out is exactly what it was.
// Every queue that exists is counted: `tests/test-apple-sign-out-guard.js`
// derives the list of queues from the offline store and fails if a new one is
// not counted here (a typed list of "what to count" is the thing that drifts).
import Foundation
import IHModels

/// How many changes have not reached the account, by kind.
///
/// ELI5: "Not sent yet: 2 favourites, 1 set list, 0 settings."
public struct UnsentChanges: Sendable, Equatable {
    public let favourites: Int
    public let setlists: Int
    public let settings: Int

    public init(favourites: Int, setlists: Int, settings: Int) {
        self.favourites = favourites
        self.setlists = setlists
        self.settings = settings
    }

    public var total: Int { favourites + setlists + settings }

    public static let none = UnsentChanges(favourites: 0, setlists: 0, settings: 0)
}

/// Thrown by `signOut(discardUnsent:)` / `signOutEverywhere(discardUnsent:)` when some changes
/// could not be sent first. Nothing was cleared and the person is still signed in.
///
/// ELI5: "Wait: some of your changes have not been saved to your account."
public struct SignOutBlockedByUnsentChanges: Error, Equatable {
    public let unsent: UnsentChanges
}

extension AppRootViewModel {
    /// Sends what is waiting, then counts what is still unsent. `.none` when the account sync is
    /// off here (the older sign-out then runs unchanged) or when signed out.
    ///
    /// ELI5: "Try to save everything first. What could not be saved?"
    public func prepareSignOut() async -> UnsentChanges {
        guard appStatus?.profileSync == true, await isSignedInNow() else { return .none }
        await flushPendingFavoriteOps()
        await flushPendingSetlistOps()
        await syncProfileSettings()
        let favourites = (try? await offlineStore.pendingFavoriteOps().count) ?? 0
        let setlists = (try? await offlineStore.pendingSetlistOps().count) ?? 0
        let settings = currentUser.map { profileSync.pendingCount(userId: $0.id) } ?? 0
        return UnsentChanges(favourites: favourites, setlists: setlists, settings: settings)
    }

    /// Throws `SignOutBlockedByUnsentChanges` when there is unsent data and the caller did not say to discard it.
    func ensureNothingUnsent(discardUnsent: Bool) async throws {
        guard !discardUnsent else { return }
        let unsent = await prepareSignOut()
        if unsent.total > 0 { throw SignOutBlockedByUnsentChanges(unsent: unsent) }
    }
}

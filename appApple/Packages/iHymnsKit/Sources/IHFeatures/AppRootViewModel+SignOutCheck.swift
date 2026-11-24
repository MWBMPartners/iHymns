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
    /// True when a queue could not be read (the offline store failed), so the real number is UNKNOWN.
    /// An unknown count is treated as "ask the person", never as "nothing waiting".
    public let couldNotCount: Bool

    public init(favourites: Int, setlists: Int, settings: Int, couldNotCount: Bool = false) {
        self.favourites = favourites
        self.setlists = setlists
        self.settings = settings
        self.couldNotCount = couldNotCount
    }

    public var total: Int { favourites + setlists + settings }

    /// Should sign-out stop and ask? Yes when something is waiting, or when we cannot tell.
    public var needsAsking: Bool { total > 0 || couldNotCount }

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
    /// Sends what is waiting, then counts what is still unsent (a queue that cannot be read sets `couldNotCount`). `.none` when the account sync is
    /// off here (the older sign-out then runs unchanged) or when signed out.
    ///
    /// ELI5: "Try to save everything first. What could not be saved?"
    public func prepareSignOut() async -> UnsentChanges {
        guard appStatus?.profileSync == true, await isSignedInNow() else { return .none }
        await flushPendingFavoriteOps()
        await flushPendingSetlistOps()
        await syncProfileSettings()
        // A queue that cannot be read is "unknown", not "zero": zero would let a sign-out wipe changes we never counted.
        var couldNotCount = false
        let favourites: Int
        do { favourites = try await offlineStore.pendingFavoriteOps().count } catch { favourites = 0; couldNotCount = true }
        let setlists: Int
        do { setlists = try await offlineStore.pendingSetlistOps().count } catch { setlists = 0; couldNotCount = true }
        let settings = currentUser.map { profileSync.pendingCount(userId: $0.id) } ?? 0
        return UnsentChanges(favourites: favourites, setlists: setlists, settings: settings, couldNotCount: couldNotCount)
    }

    /// Throws `SignOutBlockedByUnsentChanges` when there is unsent data and the caller did not say to discard it.
    func ensureNothingUnsent(discardUnsent: Bool) async throws {
        guard !discardUnsent else { return }
        let unsent = await prepareSignOut()
        if unsent.needsAsking { throw SignOutBlockedByUnsentChanges(unsent: unsent) }
    }
}

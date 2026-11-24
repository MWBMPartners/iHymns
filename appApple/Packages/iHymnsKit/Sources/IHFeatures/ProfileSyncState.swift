// ProfileSyncState.swift
// IHFeatures
//
// ELI5: The app's notebook for the shared-settings sync, kept per signed-in
// person: where it had got to on the account (the cursor), what it last agreed
// with the account for each setting (the baseline), and what is waiting to be
// sent (the outbox).
//
// DETAILED: PR 3b step I4 (#2180). The web keeps these as an outbox plus a
// cursor in `localStorage` (`js/modules/profile-sync.js`); the app keeps the
// same three things in one small JSON value per person in `UserDefaults`.
// Keying by person is rule A7 of the account rewrite: one person's waiting
// changes must never be sent as another's. Nothing here is a secret or content:
// it holds only setting ids and short values (a theme word, a number, a flag).
//
// The BASELINE is the app-specific part. The web hears about a changed setting
// through a call at each write; the Apple preferences are written by SwiftUI
// `@AppStorage` properties that offer no such hook. So instead of watching
// writes, each sync compares what the device holds now with the baseline (the
// value last agreed with the account) and queues the difference. It also makes
// the two web rules automatic: a value that came FROM the account is stored as
// the baseline at once, so it is never sent back; and a setting still waiting in
// the outbox is never overwritten by something read from the account.
import Foundation
import IHModels

/// The baseline for one setting: the value last agreed with the account, or
/// `nil` when the account holds none.
///
/// ELI5: "The account and I agreed this setting is X (or unset)."
struct ProfileBaselineEntry: Codable, Equatable, Sendable {
    let value: ProfileValue?
}

/// One change waiting to be sent.
///
/// ELI5: "Still to tell the account: set theme to dark."
struct ProfileOutboxItem: Codable, Equatable, Sendable {
    /// `"set"` or `"delete"`.
    let op: String
    let value: ProfileValue?
    /// A counter that goes up with every queued change, so "was this item changed
    /// while the request was in flight?" is a number comparison.
    let seq: Int
}

/// Everything remembered for one person.
struct ProfileSyncState: Codable, Equatable, Sendable {
    var cursor: Int = 0
    /// Whether the first full read (and the claim after it) has happened on this device for this person.
    var claimed: Bool = false
    var seq: Int = 0
    var baseline: [String: ProfileBaselineEntry] = [:]
    var outbox: [String: ProfileOutboxItem] = [:]
}

/// Persists `ProfileSyncState`, one value per person id.
///
/// ELI5: The shelf the notebooks sit on, one per person.
struct ProfileSyncStateStore: @unchecked Sendable {
    private let defaults: UserDefaults
    private let keyPrefix: String

    init(defaults: UserDefaults = .standard, keyPrefix: String = "ihProfileSync.") {
        self.defaults = defaults
        self.keyPrefix = keyPrefix
    }

    /// The notebook for `userId`, or a fresh one (an unreadable value is treated as missing).
    func load(userId: Int) -> ProfileSyncState {
        guard
            let data = defaults.data(forKey: keyPrefix + String(userId)),
            let state = try? JSONDecoder().decode(ProfileSyncState.self, from: data)
        else { return ProfileSyncState() }
        return state
    }

    func save(_ state: ProfileSyncState, userId: Int) {
        guard let data = try? JSONEncoder().encode(state) else { return }
        defaults.set(data, forKey: keyPrefix + String(userId))
    }

    /// Forgets everything for `userId` (sign-out).
    func clear(userId: Int) {
        defaults.removeObject(forKey: keyPrefix + String(userId))
    }
}

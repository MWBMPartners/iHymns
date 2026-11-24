// SetlistSyncStateStore.swift
// IHFeatures
//
// ELI5: Remembers, for each signed-in person, the "last synced" time the
// server gave us after the previous setlist sync, so we can quote it back on
// the next one.
//
// DETAILED: Set-list sync protocol 2 (#1661, #1675). The server hands back a
// `syncedAt` clock reading with every sync and wants it returned as `since`
// next time: it uses it to refuse overwriting a setlist that another device
// changed after that moment. It is kept per user id (never one global value)
// so a second account on the same device never quotes the first account's
// reading. Same shape as `RecentSearchesStore`: a plain value type over an
// injectable `UserDefaults`, so a test can pass a throwaway suite. The value
// is the server's own clock text, stored and returned untouched, never
// parsed or compared here. It is not a secret and carries no content.
import Foundation

/// Persists the last server `syncedAt` reading, one per signed-in user id.
///
/// ELI5: A tiny notebook: "for person 7, the server last said 2026-11-24 09:30:00."
public struct SetlistSyncStateStore: @unchecked Sendable {
    private let defaults: UserDefaults
    private let keyPrefix: String

    /// - Parameters:
    ///   - defaults: Where to persist: `.standard` for the real app; tests
    ///     pass a throwaway `UserDefaults(suiteName:)`.
    ///   - keyPrefix: Added in front of the user id to make each person's key.
    public init(defaults: UserDefaults = .standard, keyPrefix: String = "ihSetlistSyncedAt.") {
        self.defaults = defaults
        self.keyPrefix = keyPrefix
    }

    /// The reading from the last successful sync for `userId`, or `nil` if
    /// there has not been one (the next sync is then a "first sync": no `since`).
    public func syncedAt(forUser userId: Int) -> String? {
        guard let value = defaults.string(forKey: keyPrefix + String(userId)), !value.isEmpty else { return nil }
        return value
    }

    /// Remembers `syncedAt` as the newest reading for `userId`. An empty
    /// string is ignored (nothing worth remembering).
    public func record(_ syncedAt: String, forUser userId: Int) {
        guard !syncedAt.isEmpty else { return }
        defaults.set(syncedAt, forKey: keyPrefix + String(userId))
    }

    /// Forgets the reading for `userId`: called on sign-out, so the next
    /// sign-in starts from a clean first sync.
    public func clear(forUser userId: Int) {
        defaults.removeObject(forKey: keyPrefix + String(userId))
    }
}

// RecentlyViewedStore.swift
// IHFeatures
//
// ELI5: Remembers the last several songs you've actually opened and read —
// so the Home screen can show a "Continue Reading <song>" card and a
// "Recently Viewed" shelf without asking the server for anything (it's all
// state that only ever lives on this device).
//
// DETAILED: Implements this task's Home-surface brief: "a 'Resume last
// song' card (persist the last-opened SongID via @AppStorage/UserDefaults
// when a SongDetailView appears — add that lightweight hook)" plus "a
// favourites shelf: if favourites aren't wired yet, show a 'recently
// viewed' shelf... instead." Mirrors `RecentSearchesStore`'s exact shape —
// a plain, injectable `UserDefaults` wrapper, NOT the `@AppStorage`
// property wrapper directly, for the identical test-seam reason documented
// on that file (a test injects `UserDefaults(suiteName:)` so runs never
// pollute `.standard` or each other) — but stores a small `Codable` struct
// per entry instead of a bare `String`, since a shelf row needs a title/
// songbook/number to render immediately, without a second network
// round-trip just to redraw Home.
//
// `AppRootViewModel.recordRecentlyViewed(_:)` is the ONE call site that
// writes to this store, invoked from `SongDetailViewModel`'s successful
// primary load (see that file's own comment) — every recorded entry
// therefore reflects a song the user actually opened and which loaded
// successfully, never a guess or a partial record.
import Foundation
import IHModels

/// One song the user has actually opened — just enough to render a
/// "Continue Reading"/"Recently Viewed" row without re-fetching.
///
/// ELI5: A little receipt: "you looked at this song, here's its title, and
/// when."
public struct RecentlyViewedSong: Sendable, Hashable, Codable, Identifiable {
    /// Stable identity for SwiftUI — the song's own `SongID`.
    public var id: SongID { songId }

    public let songId: SongID
    public let title: String
    public let songbookAbbreviation: String

    /// `nil` for the same handful of manually-catalogued rows with no
    /// sequence number as `SongSummary.number`/`SongDetail.number`.
    public let number: Int?

    /// When this song was recorded — kept (rather than discarded) purely so
    /// a future "Recently Viewed, sorted/grouped by day" UI has the data
    /// already, without a further store change; `HomeView`'s v1 shelf
    /// doesn't render it, relying instead on `RecentlyViewedStore`'s own
    /// most-recent-first ordering.
    public let viewedAt: Date

    public init(songId: SongID, title: String, songbookAbbreviation: String, number: Int?, viewedAt: Date = Date()) {
        self.songId = songId
        self.title = title
        self.songbookAbbreviation = songbookAbbreviation
        self.number = number
        self.viewedAt = viewedAt
    }
}

/// Persists the songs the user has recently opened, most-recent-first,
/// deduplicated by song, capped at a small fixed count.
///
/// ELI5: A tiny notebook of "songs you've read lately."
///
/// DETAILED: `@unchecked Sendable` for the identical reason
/// `RecentSearchesStore` is — `UserDefaults` has always been documented as
/// thread-safe for this read/write usage
/// (https://developer.apple.com/documentation/foundation/userdefaults) but
/// doesn't (yet) carry a compiler-visible `Sendable` conformance.
public struct RecentlyViewedStore: @unchecked Sendable {
    private let defaults: UserDefaults
    private let key: String
    private let limit: Int

    /// - Parameters:
    ///   - defaults: Where to persist — `.standard` for the real app; tests
    ///     inject a throwaway `UserDefaults(suiteName:)`.
    ///   - key: The `UserDefaults` key this store owns exclusively.
    ///   - limit: How many distinct songs to remember. 12 comfortably
    ///     covers both the "Continue Reading" card (the newest) and a
    ///     handful more for the shelf, without the list growing unbounded.
    public init(defaults: UserDefaults = .standard, key: String = "ihRecentlyViewedSongs", limit: Int = 12) {
        self.defaults = defaults
        self.key = key
        self.limit = limit
    }

    /// The currently-persisted recently-viewed songs, most-recent-first.
    ///
    /// ELI5: "What have I actually read recently?"
    ///
    /// DETAILED: Stored as JSON-encoded `Data` (not `UserDefaults`'
    /// `stringArray`/`array` property-list helpers `RecentSearchesStore`
    /// uses) because each entry is a small struct, not a bare `String` — a
    /// malformed/pre-migration value decodes to `[]` rather than crashing
    /// or throwing, the same defensive posture `SongSummary`'s optional
    /// fields take toward unexpected server payloads.
    public func load() -> [RecentlyViewedSong] {
        guard let data = defaults.data(forKey: key) else { return [] }
        return (try? JSONDecoder().decode([RecentlyViewedSong].self, from: data)) ?? []
    }

    /// Records `song` as the newest recently-viewed entry.
    ///
    /// ELI5: "Remember this one — and if I'd already read it before, just
    /// move it to the top instead of listing it twice."
    ///
    /// DETAILED: Removes any existing entry for the SAME `songId` before
    /// inserting at the front (mirrors `RecentSearchesStore.record(_:)`'s
    /// de-dupe-then-promote behaviour), so re-reading a song moves it to
    /// the top of "Recently Viewed" rather than showing it twice; then caps
    /// at `limit`, dropping the OLDEST entries first.
    public func record(_ song: RecentlyViewedSong) {
        var existing = load()
        existing.removeAll { $0.songId == song.songId }
        existing.insert(song, at: 0)
        if existing.count > limit {
            existing.removeLast(existing.count - limit)
        }
        guard let data = try? JSONEncoder().encode(existing) else { return }
        defaults.set(data, forKey: key)
    }

    /// Forgets every recorded recently-viewed song.
    public func clear() {
        defaults.removeObject(forKey: key)
    }
}

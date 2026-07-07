// OfflineStorageViewModel.swift
// IHFeatures
//
// ELI5: The little helper behind the "Storage & Offline" Settings screen —
// asks the on-device cache what's been saved for offline reading and how
// much room it's using, and knows how to forget one song (or everything).
//
// DETAILED: #187 (offline support + data management). A focused,
// screen-scoped `@Observable @MainActor` view model — mirrors
// `SettingsViewModel`'s "one small view model per Settings sub-screen"
// shape (that file's own header: `SettingsViewModel` owns exactly
// `IHSettingsStore`, not the whole app) rather than growing
// `AppRootViewModel` with storage-management state nothing else in the app
// needs. Delegates every read/write to `AppRootViewModel`'s #187
// pass-throughs (`AppRootViewModel+Offline.swift`) rather than holding an
// `OfflineStore` of its own, the same "reach the ONE engine instance
// through the root view model" rule every other screen in this package
// follows (`SongDetailViewModel`'s own header states it explicitly).
import Foundation
import IHModels
import IHPersistence
import Observation

/// Loads and manages the list of songs saved for offline reading, plus
/// their total on-disk size — `OfflineStorageView`'s data source.
///
/// ELI5: "What have I saved for offline, how much space does it use, and
/// let me get rid of some (or all) of it."
@MainActor
@Observable
public final class OfflineStorageViewModel {
    /// Every saved song, most-recently-saved first — `nil` distinguishes
    /// "haven't loaded yet" from "loaded, and it's genuinely empty" the same
    /// way `LoadState.idle` does elsewhere, but a plain optional is enough
    /// here: unlike a network fetch, a local cache read can't meaningfully
    /// "error" in a way worth its own UI state (a read failure degrades to
    /// an empty list, matching `loadFavoritesIfNeeded()`'s `try? ?? []`
    /// posture).
    public private(set) var savedSongs: [SavedSongInfo] = []

    /// The combined size, in bytes, of every saved song's cached lyrics.
    public private(set) var totalBytes = 0

    /// Whether `loadIfNeeded()` has already populated this view model this
    /// screen visit — same one-shot-guard shape as
    /// `AppRootViewModel.hasLoadedFavoritesFromCache`.
    @ObservationIgnored
    private var hasLoaded = false

    private let rootViewModel: AppRootViewModel

    public init(rootViewModel: AppRootViewModel) {
        self.rootViewModel = rootViewModel
    }

    /// Loads `savedSongs`/`totalBytes`, but only once per screen visit —
    /// safe to call from `OfflineStorageView`'s `.task {}` on every
    /// re-appearance.
    public func loadIfNeeded() async {
        guard !hasLoaded else { return }
        hasLoaded = true
        await refresh()
    }

    /// Re-reads `savedSongs`/`totalBytes` from the cache regardless of
    /// `hasLoaded` — called after every mutation below (and available for a
    /// future manual pull-to-refresh).
    public func refresh() async {
        savedSongs = (try? await rootViewModel.allSavedSongs()) ?? []
        totalBytes = (try? await rootViewModel.totalSavedSongBytes()) ?? 0
    }

    /// Removes one saved song (swipe-to-delete), then refreshes.
    ///
    /// ELI5: "Forget just this one saved song."
    public func remove(_ songId: SongID) async {
        try? await rootViewModel.removeSavedSong(songId)
        await refresh()
    }

    /// Removes EVERY saved song, then refreshes — `OfflineStorageView`'s
    /// confirmed "Remove All Downloads" action.
    ///
    /// ELI5: "Forget everything I've saved for offline."
    public func removeAll() async {
        try? await rootViewModel.removeAllSavedSongs()
        await refresh()
    }

    /// `totalBytes`, formatted for display (e.g. `"4.2 MB"`) —
    /// `ByteCountFormatter` is available on every Apple platform this
    /// package targets, so this needs no platform-specific fallback.
    public var totalSizeDisplay: String {
        ByteCountFormatter.string(fromByteCount: Int64(totalBytes), countStyle: .file)
    }
}

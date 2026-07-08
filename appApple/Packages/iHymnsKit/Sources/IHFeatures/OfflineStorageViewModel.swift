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
//
// #1440 UPDATE (offline media caching) — `totalBytes` (lyrics only) now
// has a sibling, `totalMediaBytes` (cached audio/PDF/MIDI/MusicXML), and
// `totalSizeDisplay` reports their COMBINED figure — the screen's headline
// "Total Size" should read as "everything this device downloaded for
// offline use," not just the lyrics half. `remove(_:)`/`removeAll()` both
// now cascade into the media cache too, so removing a song (or everything)
// here never leaves orphaned cached files behind with no surviving
// `saved_song` row.
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

    /// The combined size, in bytes, of every cached media file (audio/
    /// sheet-music/MIDI/MusicXML, #1440) across every song — a SEPARATE
    /// figure from `totalBytes` above (lyrics text is typically a few KB;
    /// a single cached recording can be several MB), surfaced as its own
    /// "Media Downloads" row so the split is honest rather than folded
    /// silently into one number.
    public private(set) var totalMediaBytes = 0

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
        totalMediaBytes = (try? await rootViewModel.totalCachedMediaBytes()) ?? 0
    }

    /// Removes one saved song (swipe-to-delete) AND any media cached
    /// alongside it (#1440), then refreshes.
    ///
    /// ELI5: "Forget just this one saved song, and anything downloaded for
    /// it."
    public func remove(_ songId: SongID) async {
        try? await rootViewModel.removeSavedSong(songId)
        try? await rootViewModel.removeAllCachedMedia(forSong: songId)
        await refresh()
    }

    /// Removes EVERY saved song AND every cached media file (#1440), then
    /// refreshes — `OfflineStorageView`'s confirmed "Remove All Downloads"
    /// action.
    ///
    /// ELI5: "Forget everything I've saved for offline — songs and
    /// downloaded files both."
    public func removeAll() async {
        try? await rootViewModel.removeAllSavedSongs()
        try? await rootViewModel.removeAllCachedMedia()
        await refresh()
    }

    /// `totalBytes` + `totalMediaBytes` combined, formatted for display
    /// (e.g. `"4.2 MB"`) — `ByteCountFormatter` is available on every Apple
    /// platform this package targets, so this needs no platform-specific
    /// fallback.
    public var totalSizeDisplay: String {
        ByteCountFormatter.string(fromByteCount: Int64(totalBytes + totalMediaBytes), countStyle: .file)
    }

    /// `totalMediaBytes` alone, formatted the same way — `OfflineStorageView`'s
    /// separate "Media Downloads" row (#1440), so a user can see the
    /// lyrics-vs-media split rather than only the combined total above.
    public var mediaSizeDisplay: String {
        ByteCountFormatter.string(fromByteCount: Int64(totalMediaBytes), countStyle: .file)
    }
}

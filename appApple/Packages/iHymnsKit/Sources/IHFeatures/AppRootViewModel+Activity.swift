// AppRootViewModel+Activity.swift
// IHFeatures
//
// ELI5: "Remember: I just read this song" — updates BOTH the small
// "Recently Viewed" shelf list AND (#1446) the local, on-device "Your
// Activity" usage counters, from the ONE place a song load actually
// succeeds.
//
// DETAILED: `recordRecentlyViewed(_:)` (#183) moved OUT of
// `AppRootViewModel.swift` itself purely to keep that file under the repo's
// LOC-budget tripwire (`Scripts/loc-budget.sh`) now that it also carries
// the new `usageActivityStore` property (#1446) — the exact same "a Swift
// extension can't add new STORED properties, so behaviour-only code is free
// to live in its own file" reasoning `AppRootViewModel+Catalog.swift`/
// `OfflineStore+Favorites.swift` ("#1450 moved out purely for the LOC
// tripwire") already establish. This is a PURE MOVE of the pre-existing
// #183 body, plus ONE new line: the #1446 usage-activity write.
//
// WHY THIS IS THE RIGHT (and ONLY) write hook for #1446: `SongDetailViewModel
// .loadPrimaryDetail()` calls `rootViewModel.recordRecentlyViewed(detail)`
// ONLY after a song's full record has successfully loaded — via a fresh
// network fetch OR the offline-saved-copy fallback (see that method's own
// comment) — never on a still-loading or failed fetch. That is exactly the
// signal "the user actually read this song" should be anchored to, so
// piggy-backing the usage-activity write on this SAME call site (rather
// than adding a second, independent hook) guarantees "Recently Viewed" and
// "Your Activity" can never disagree about what counts as an "open."
import IHModels

extension AppRootViewModel {
    /// Records that the user just successfully opened `detail`'s song
    /// (#183) — the "last opened song" hook the Home-surface brief asks
    /// for. Called from `SongDetailViewModel`'s successful primary load
    /// (see that file's own comment), NEVER from the View layer directly:
    /// "the song's data finished loading" is a strictly better signal than
    /// raw view appearance — a song that never finished loading (still
    /// spinning, or errored) was never actually "opened" from the user's
    /// perspective, so it shouldn't show up in "Recently Viewed" OR count
    /// toward the #1446 usage stats.
    ///
    /// ELI5: "Remember: I just read this song."
    public func recordRecentlyViewed(_ detail: SongDetail) {
        let entry = RecentlyViewedSong(
            songId: detail.songId,
            title: detail.title,
            songbookAbbreviation: detail.songbookAbbreviation,
            number: detail.number
        )
        recentlyViewedStore.record(entry)
        recentlyViewedSongs = recentlyViewedStore.load()

        // #1446 — the ONE write hook for the local "Your Activity"
        // dashboard's counters; see this file's header for why anchoring
        // it here (rather than a second, independent call site) is
        // load-bearing, not incidental.
        usageActivityStore.recordSongOpen(
            songId: detail.songId,
            title: detail.title,
            songbookAbbreviation: detail.songbookAbbreviation,
            number: detail.number
        )
    }
}

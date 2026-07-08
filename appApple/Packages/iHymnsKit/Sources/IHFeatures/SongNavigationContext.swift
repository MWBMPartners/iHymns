// SongNavigationContext.swift
// IHFeatures
//
// ELI5: A little "what comes before/after this song?" lookup — built from
// whatever ordered list of songs you tapped INTO a song from (a songbook's
// song list, or a setlist's song list), so the reading screen can offer
// "swipe/press → to go to the next hymn" without needing to re-fetch or
// re-derive that ordering itself.
//
// DETAILED: #185 (Apple Phase 1, navigation & UX consolidation — "Swipe
// navigation between songs with animations," one of the issue's checklist
// items that was NOT actually built despite being ticked, verified by
// grepping this package for `DragGesture`/"swipe" before starting: nothing
// implemented it). Deliberately a PURE, `Sendable`, side-effect-free value
// type — no view, no `@Observable`, no network — mirroring `LoadState`'s
// own "pure shared shape, not a view" precedent in this same directory, so
// its next/previous logic is trivially unit-testable (`SongNavigationContextTests`)
// without spinning up any SwiftUI machinery.
//
// Scoped DELIBERATELY to just two call sites — `SongbookSongsView`
// (tapping a song from a hymnal's own song list) and `SetlistDetailView`
// (tapping a song from a setlist) — the two places a "next song" genuinely
// means something sequential to the user. Search results, Favourites, and
// the Home/related-songs shelves keep pushing a bare `SongID` exactly as
// before (see `SongPagerView.swift`'s header for why that old, contextless
// path is INTENTIONALLY preserved side-by-side, not replaced).
import IHModels

/// An ordered list of songs a `SongPagerView` can step forward/backward
/// through — "what song comes before/after this one, in the list the user
/// tapped in from."
///
/// ELI5: "Here's the order of songs you were browsing — which one's next?"
public struct SongNavigationContext: Sendable, Hashable {
    /// The ordered song ids this context can page through, exactly as the
    /// originating list (songbook, setlist) presented them.
    public let songIds: [SongID]

    public init(songIds: [SongID]) {
        self.songIds = songIds
    }

    /// The song immediately BEFORE `current` in `songIds` — `nil` if
    /// `current` is the first entry, or isn't in this context at all (e.g.
    /// the originating list changed underneath the reader, a benign edge
    /// case this just treats as "no previous song" rather than crashing).
    ///
    /// ELI5: "What was the song just before this one?"
    public func previousId(before current: SongID) -> SongID? {
        guard let index = songIds.firstIndex(of: current), index > 0 else { return nil }
        return songIds[index - 1]
    }

    /// The song immediately AFTER `current` in `songIds` — same `nil`
    /// conditions as `previousId(before:)` above, mirrored for the end of
    /// the list.
    ///
    /// ELI5: "What's the next song after this one?"
    public func nextId(after current: SongID) -> SongID? {
        guard let index = songIds.firstIndex(of: current), index < songIds.count - 1 else { return nil }
        return songIds[index + 1]
    }
}

/// A `NavigationLink(value:)` payload carrying both WHICH song to open and
/// the ordered context it was opened from — pushed instead of a bare
/// `SongID` only from the two sequential-list screens described in this
/// file's header, so `SongPagerView` (rather than a plain `SongDetailView`)
/// is what lands in the detail column/stack for those two origins.
///
/// ELI5: "Open this song, and remember what list it came from so I can page
/// through the rest of it."
public struct SongPagerRequest: Sendable, Hashable {
    public let songId: SongID
    public let context: SongNavigationContext

    public init(songId: SongID, context: SongNavigationContext) {
        self.songId = songId
        self.context = context
    }
}

// SongPagerView.swift
// IHFeatures
//
// ELI5: A thin wrapper around the normal song-reading screen that also
// lets you swipe left/right — or press the on-screen arrows / the ← → keys
// on a Mac/iPad keyboard — to move to the next/previous song in whatever
// list you opened it from (a songbook, or a setlist).
//
// DETAILED: #185's "Swipe navigation between songs with animations."
// Deliberately does NOT touch `SongDetailView.swift` at all (that file is
// already near the repo's LOC-budget tripwire and is a large, already-
// well-tested surface — churning its internals for this would be exactly
// the "rewrite what's already good" this task's brief warns against).
// Instead this wraps a plain `SongDetailView`, swapping which `SongID` it's
// given via `.id(currentSongId)` — a well-known SwiftUI technique for
// getting a genuine insert/remove `.transition` out of a value change (see
// e.g. Apple's own "Animating Views and Transitions" guide, and the common
// `.id()` + `.transition()` + explicit `.animation(value:)` pairing) — so
// each swipe/keypress tears down the old `SongDetailView` (and its
// `SongDetailViewModel`) and mounts a completely FRESH one for the new
// song, the exact same lifecycle as if the user had popped back and pushed
// a new song normally. No shared mutable state to get subtly wrong between
// songs.
//
// GESTURE SAFETY: the horizontal swipe is attached via `.simultaneousGesture`
// (never `.gesture`/`.highPriorityGesture`), so it never competes with or
// blocks `SongDetailView`'s own vertical `ScrollView` — both gesture
// recognizers see the same touch stream, and this view only ACTS once the
// drag ends, only if the movement was decisively more horizontal than
// vertical. A normal vertical scroll through the lyrics is therefore
// unaffected.
//
// ONLY used from the two places a "next song" is genuinely sequential:
// `SongbookSongsView` (paging through a hymnal) and `SetlistDetailView`
// (paging through a setlist) — see `SongNavigationContext.swift`'s header
// for why every other origin (Home, Search, Favourites, related-songs
// shelves, credit "more by this person") keeps pushing a bare `SongID`
// straight to `SongDetailView`, unchanged.
import IHModels
import SwiftUI

/// Wraps `SongDetailView` with swipe/keyboard paging through a
/// `SongNavigationContext`'s ordered song list.
///
/// ELI5: "Show this song — and let me flip to the next/previous one in the
/// list I came from."
public struct SongPagerView: View {
    private let context: SongNavigationContext
    private let rootViewModel: AppRootViewModel

    /// The song currently on screen — starts at whatever `SongID` the
    /// caller pushed, then changes as the user pages. `.id(currentSongId)`
    /// below is what turns this state change into a fresh `SongDetailView`
    /// instance each time (this file's header).
    @State private var currentSongId: SongID

    /// Which way the MOST RECENT page happened — drives `pageTransition`'s
    /// slide direction so swiping right (going to the previous song) and
    /// swiping left (going to the next song) animate as opposite-direction
    /// slides, matching the gesture the user just made.
    @State private var lastDirection: PageDirection = .forward

    public init(initialSongId: SongID, context: SongNavigationContext, rootViewModel: AppRootViewModel) {
        self.context = context
        self.rootViewModel = rootViewModel
        _currentSongId = State(initialValue: initialSongId)
    }

    public var body: some View {
        SongDetailView(songId: currentSongId, rootViewModel: rootViewModel)
            .id(currentSongId)
            .transition(pageTransition)
            .animation(.snappy(duration: 0.3), value: currentSongId)
            .simultaneousGesture(swipeGesture)
            .toolbar { pagerToolbar }
    }

    // MARK: - Paging

    private var hasPrevious: Bool { context.previousId(before: currentSongId) != nil }
    private var hasNext: Bool { context.nextId(after: currentSongId) != nil }

    private func goToPrevious() {
        guard let previous = context.previousId(before: currentSongId) else { return }
        lastDirection = .backward
        currentSongId = previous
    }

    private func goToNext() {
        guard let next = context.nextId(after: currentSongId) else { return }
        lastDirection = .forward
        currentSongId = next
    }

    /// Slides the incoming song in from the direction opposite where it
    /// "came from" and the outgoing song out the same way — a forward page
    /// (next song) slides new-in-from-trailing/old-out-to-leading; a
    /// backward page (previous song) mirrors that.
    private var pageTransition: AnyTransition {
        switch lastDirection {
        case .forward:
            .asymmetric(
                insertion: .move(edge: .trailing).combined(with: .opacity),
                removal: .move(edge: .leading).combined(with: .opacity)
            )
        case .backward:
            .asymmetric(
                insertion: .move(edge: .leading).combined(with: .opacity),
                removal: .move(edge: .trailing).combined(with: .opacity)
            )
        }
    }

    // MARK: - Swipe gesture

    /// `.onEnded`-only (never tracks `.onChanged`) — this view never
    /// repositions anything mid-drag, so there's nothing for it to fight
    /// the descendant `ScrollView` over; it just reads the FINAL
    /// translation and decides, after the fact, whether that read like a
    /// deliberate horizontal swipe.
    private var swipeGesture: some Gesture {
        DragGesture(minimumDistance: 40)
            .onEnded { value in
                let horizontal = value.translation.width
                let vertical = value.translation.height
                // Require the swipe to be clearly more horizontal than
                // vertical, and past a real distance — a mostly-vertical
                // scroll gesture (or an incidental diagonal wobble) should
                // never accidentally page to a different song.
                guard abs(horizontal) > abs(vertical) * 1.5, abs(horizontal) > 60 else { return }
                if horizontal < 0 {
                    goToNext()
                } else {
                    goToPrevious()
                }
            }
    }

    // MARK: - Toolbar

    /// Previous/Next buttons — the visible, discoverable, mouse/keyboard
    /// -reachable counterpart to the swipe gesture above (not every user
    /// discovers a swipe, and Mac trackpad/mouse users have no swipe at
    /// all). `.navigation` placement mirrors where Mail/Podcasts put their
    /// own message/episode prev-next controls. Bare arrow-key shortcuts
    /// (no modifier) match that same convention; safe here because
    /// `SongDetailView` has no text field to steal cursor-movement keys
    /// from.
    @ToolbarContentBuilder
    private var pagerToolbar: some ToolbarContent {
        ToolbarItemGroup(placement: .navigation) {
            Button {
                goToPrevious()
            } label: {
                Label("Previous Song", systemImage: "chevron.left")
            }
            .disabled(!hasPrevious)
            .keyboardShortcut(.leftArrow, modifiers: [])
            .help("Previous Song")

            Button {
                goToNext()
            } label: {
                Label("Next Song", systemImage: "chevron.right")
            }
            .disabled(!hasNext)
            .keyboardShortcut(.rightArrow, modifiers: [])
            .help("Next Song")
        }
    }
}

/// Which way the most recent page happened — see `pageTransition`'s doc
/// comment for how this drives the slide direction.
private enum PageDirection {
    case forward
    case backward
}

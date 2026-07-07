// SongDetailToolbar.swift
// IHFeatures
//
// ELI5: The little floating control bar under the lyrics — make the text
// bigger/smaller, show/hide chords, favourite, and share.
//
// DETAILED: #180's "A floating glass toolbar: text-size stepper (persist
// the choice), chords toggle, favourite (stub the action if favourites
// aren't wired yet...), share (ShareLink with the canonical web URL)." A
// `ToolbarItemGroup` renders with the system's Liquid Glass toolbar
// treatment automatically on OS 26 with no bespoke glass code needed here —
// same reasoning `SongDetailView.swift`'s original #1399 header already
// documented for its own (now-replaced) text-size-only toolbar.
//
// #181 UPDATE (native login/account UI + favourites task) — the favourite
// button is no longer a permanently-disabled stub: it calls
// `onToggleFavorite` (wired by `SongDetailView` to
// `SongDetailViewModel.toggleFavorite()`) and reflects `isFavorite`'s
// current state with a filled/outline heart. It stays `.disabled` ONLY
// while signed out (`isSignedIn == false`) — favouriting requires an
// account server-side (`FavoritesEndpoints.swift`'s header: every
// favourites endpoint is `security: bearerAuth`) — with a `.help()` that
// explains why, the SAME "disabled + explanatory help, never a silently
// dead button" pattern the original stub already established.
//
// #181 UPDATE (setlists half) — adds a fifth control: "Add to Setlist,"
// disabled/`.help`-explained under the SAME `isSignedIn` gate as the
// favourite button (setlists are just as account-bound —
// `SetlistsEndpoints.swift`'s header). Presented as a `Binding<Bool>`
// (`isPresentingAddToSetlist`), NOT a closure like `onToggleFavorite` —
// unlike a favourite toggle (one immediate, parameterless action),
// "add to setlist" needs to show a PICKER (which setlist? or make a new
// one?), so this button's job is only to flip a `Bool` the OWNING
// `SongDetailView` already presents `AddToSetlistSheet` from via
// `.sheet(isPresented:)`, mirroring how `FavoritesView` owns its OWN
// `isPresentingLogin` sheet-trigger `@State` rather than `LoginView`
// somehow presenting itself.
//
// #187 UPDATE (offline support + data management) — adds a sixth control:
// "Save for Offline" / "Remove Downloaded Copy," toggling
// `SongDetailViewModel.toggleOfflineSave()` and reflecting
// `isSavedOffline`'s current state with a download/checkmark icon (mirrors
// the web's `data-song-download` affordance, `.claude/CLAUDE.md` rule #7).
// Deliberately NEVER `.disabled` under the `isSignedIn` gate the favourite/
// setlist buttons use — saving a song offline needs no account at all
// (`SongDetailViewModel.toggleOfflineSave()`'s own header explains why).
//
// Extracted into its own `ToolbarContent`-conforming type (rather than a
// `@ToolbarContentBuilder` computed property on `SongDetailView` itself,
// which was #1399's approach) purely to keep `SongDetailView.swift` from
// re-growing past a manageable size now that this toolbar has six controls
// instead of one.
//
// Placement: `.bottomBar` gives iOS/iPadOS/tvOS/visionOS/watchOS the
// "floating" look the brief asks for; macOS's window-toolbar model has no
// real equivalent of a floating BOTTOM bar, so it falls back to
// `.primaryAction` (the window toolbar) there — the SAFE, definitely-
// supported placement on every platform this package targets, matching
// this task's macOS build-verification step.
//
// #186 UPDATE (Apple Phase 1, "Sharing & social") — the Share button now
// carries a `SharePreview` (`shareTitle`) alongside the URL, so the system
// share sheet/Messages/AirDrop preview shows "Amazing Grace — Mission
// Praise #12" instead of a bare, unhelpful link. `SharePreview`'s public
// API is title-only (no separate subtitle parameter — Apple's own
// `SharePreview` docs: https://developer.apple.com/documentation/swiftui/sharepreview),
// so the songbook context is folded into ONE combined title string rather
// than left off entirely.
import SwiftUI

/// The floating glass toolbar under a song's lyrics.
struct SongDetailToolbarContent: ToolbarContent {
    @Binding var textScale: Double
    @Binding var showChords: Bool
    let hasChords: Bool
    let shareURL: URL?
    /// A human-readable "Title — Songbook" string for the `ShareLink`'s
    /// `SharePreview` — `nil` is fine (falls back to a bare, title-less
    /// preview) but `shareURL` should never be non-`nil` while this is,
    /// since both are derived from the same loaded `SongDetail`.
    let shareTitle: String?
    let isFavorite: Bool
    let isSignedIn: Bool
    let onToggleFavorite: () -> Void
    @Binding var isPresentingAddToSetlist: Bool
    let isSavedOffline: Bool
    let onToggleOfflineSave: () -> Void

    private static let minTextScale = 0.75
    private static let maxTextScale = 2.0
    private static let textScaleStep = 0.1

    private var placement: ToolbarItemPlacement {
        #if os(macOS)
        .primaryAction
        #else
        .bottomBar
        #endif
    }

    var body: some ToolbarContent {
        ToolbarItemGroup(placement: placement) {
            Button {
                textScale = max(Self.minTextScale, textScale - Self.textScaleStep)
            } label: {
                Label("Smaller Text", systemImage: "textformat.size.smaller")
            }

            Button {
                textScale = min(Self.maxTextScale, textScale + Self.textScaleStep)
            } label: {
                Label("Larger Text", systemImage: "textformat.size.larger")
            }

            if hasChords {
                Button {
                    showChords.toggle()
                } label: {
                    Label(
                        showChords ? "Hide Chords" : "Show Chords",
                        systemImage: showChords ? "guitars.fill" : "guitars"
                    )
                }
            }

            Button {
                onToggleOfflineSave()
            } label: {
                Label(
                    isSavedOffline ? "Remove Downloaded Copy" : "Save for Offline",
                    systemImage: isSavedOffline ? "checkmark.circle.fill" : "arrow.down.circle"
                )
            }
            .help(isSavedOffline
                ? "Remove the downloaded copy of this song."
                : "Save this song so you can read it without an internet connection.")

            Button {
                onToggleFavorite()
            } label: {
                Label(
                    isFavorite ? "Remove from Favourites" : "Add to Favourites",
                    systemImage: isFavorite ? "heart.fill" : "heart"
                )
            }
            .disabled(!isSignedIn)
            .help(isSignedIn
                ? (isFavorite ? "Remove from Favourites" : "Add to Favourites")
                : "Sign in to save favourites.")

            Button {
                isPresentingAddToSetlist = true
            } label: {
                Label("Add to Setlist", systemImage: "text.badge.plus")
            }
            .disabled(!isSignedIn)
            .help(isSignedIn ? "Add to Setlist" : "Sign in to use setlists.")

            if let shareURL {
                if let shareTitle {
                    ShareLink(item: shareURL, preview: SharePreview(shareTitle)) {
                        Label("Share", systemImage: "square.and.arrow.up")
                    }
                } else {
                    ShareLink(item: shareURL) {
                        Label("Share", systemImage: "square.and.arrow.up")
                    }
                }
            }
        }
    }
}

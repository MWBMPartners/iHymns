// SongDetailToolbar.swift
// IHFeatures
//
// ELI5: The little floating control bar under the lyrics — make the text
// bigger/smaller, show/hide chords, favourite (coming soon), and share.
//
// DETAILED: #180's "A floating glass toolbar: text-size stepper (persist
// the choice), chords toggle, favourite (stub the action if favourites
// aren't wired yet...), share (ShareLink with the canonical web URL)." A
// `ToolbarItemGroup` renders with the system's Liquid Glass toolbar
// treatment automatically on OS 26 with no bespoke glass code needed here —
// same reasoning `SongDetailView.swift`'s original #1399 header already
// documented for its own (now-replaced) text-size-only toolbar.
//
// Extracted into its own `ToolbarContent`-conforming type (rather than a
// `@ToolbarContentBuilder` computed property on `SongDetailView` itself,
// which was #1399's approach) purely to keep `SongDetailView.swift` from
// re-growing past a manageable size now that this toolbar has four controls
// instead of one.
//
// Placement: `.bottomBar` gives iOS/iPadOS/tvOS/visionOS/watchOS the
// "floating" look the brief asks for; macOS's window-toolbar model has no
// real equivalent of a floating BOTTOM bar, so it falls back to
// `.primaryAction` (the window toolbar) there — the SAFE, definitely-
// supported placement on every platform this package targets, matching
// this task's macOS build-verification step.
import SwiftUI

/// The floating glass toolbar under a song's lyrics.
struct SongDetailToolbarContent: ToolbarContent {
    @Binding var textScale: Double
    @Binding var showChords: Bool
    let hasChords: Bool
    let shareURL: URL?

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

            // Stubbed per this task's explicit instruction: favourites
            // aren't wired up in the native app yet (no `favorites_sync`
            // client, no local persistence) — a disabled button with an
            // explanatory `.help()` is honest about that, rather than
            // faking a toggle that doesn't actually save anything.
            Button {
            } label: {
                Label("Favourite", systemImage: "heart")
            }
            .disabled(true)
            .help("Favourites are coming soon.")

            if let shareURL {
                ShareLink(item: shareURL) {
                    Label("Share", systemImage: "square.and.arrow.up")
                }
            }
        }
    }
}

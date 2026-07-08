// SongCompareEntryModifier.swift
// IHFeatures
//
// ELI5: The little "Compare With…" button that lives on a song's screen —
// tap it, pick a second song from a list, and it takes you to the
// side-by-side comparison screen.
//
// DETAILED: #1445. A self-contained `ViewModifier` (rather than inlining
// this into `SongDetailView.swift`, which is already close to the repo's
// LOC-budget tripwire) that owns everything the entry point needs: the
// toolbar button, the picker sheet, and the `navigationDestination(item:)`
// push. Toolbar CONTENTS compose across modifiers in SwiftUI — applying
// `.toolbar { … }` here adds ONE more button alongside whatever
// `SongDetailToolbarContent` already contributes, without either one
// needing to know about the other. Placement mirrors
// `SongDetailToolbarContent`'s own `#if os(macOS) .primaryAction #else
// .bottomBar` split for the identical "safe, definitely-supported on every
// platform this package targets" reason.
//
// `.navigationDestination(item: $comparisonTarget)` pushes `SongComparisonView`
// in whatever stack the song screen already lives in — a REAL push, not a
// sheet, the same reasoning `HomeView`/`SongbooksView` already document for
// registering their own `SongID` destinations on their own hosting stacks.
import IHModels
import SwiftUI

/// Adds a "Compare With…" toolbar button + picker sheet + comparison push
/// to whatever view it's applied to (`SongDetailView`).
struct SongCompareEntryModifier: ViewModifier {
    let songId: SongID
    /// Gates the toolbar button — enabled only once the primary song has
    /// actually loaded, mirroring `SongDetailView.shareURL`'s identical
    /// "nothing meaningful to act on yet" nil/false-gating posture.
    let isPrimaryLoaded: Bool
    let counterparts: [SongLinkedSong]
    let relatedSongs: [RelatedSongSummary]
    let rootViewModel: AppRootViewModel

    @State private var isPresentingPicker = false
    @State private var comparisonTarget: ComparisonTarget?

    /// A trivial `Identifiable` + `Hashable` wrapper around the picked
    /// SECOND song's id — `.navigationDestination(item:)` requires BOTH
    /// conformances, and a bare `SongID` (already `Hashable`, not
    /// `Identifiable`) doesn't qualify on its own without adding a
    /// conformance to a type this package doesn't own the meaning of
    /// everywhere.
    private struct ComparisonTarget: Identifiable, Hashable {
        let id: SongID
    }

    func body(content: Content) -> some View {
        content
            .toolbar {
                ToolbarItem(placement: toolbarPlacement) {
                    Button {
                        isPresentingPicker = true
                    } label: {
                        Label("Compare With…", systemImage: "rectangle.split.2x1")
                    }
                    .disabled(!isPrimaryLoaded)
                }
            }
            .sheet(isPresented: $isPresentingPicker) {
                SongComparisonPickerView(
                    rootViewModel: rootViewModel,
                    currentSongId: songId,
                    counterparts: counterparts,
                    relatedSongs: relatedSongs
                ) { selectedId in
                    comparisonTarget = ComparisonTarget(id: selectedId)
                }
            }
            .navigationDestination(item: $comparisonTarget) { target in
                SongComparisonView(primaryId: songId, secondaryId: target.id, rootViewModel: rootViewModel)
            }
    }

    private var toolbarPlacement: ToolbarItemPlacement {
        #if os(macOS)
        .primaryAction
        #else
        .bottomBar
        #endif
    }
}

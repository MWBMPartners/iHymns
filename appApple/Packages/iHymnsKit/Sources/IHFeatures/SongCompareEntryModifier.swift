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
//
// #1455 UPDATE — `comparisonTargetId` is now an EXTERNAL `Binding<SongID?>`
// (owned by `SongDetailView`, previously private `@State` here) so a SECOND
// entry point — a shelf row's "Compare with This" context menu
// (`RelatedSongsShelfView`'s `onCompare`) — can start the exact same push
// without going through this modifier's own toolbar/picker at all. Both
// entry points still land on the SAME `.navigationDestination(item:)`
// below, so there is only ever one comparison-push code path.
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

    /// The song to compare `songId` against, once chosen — externally
    /// owned (#1455) so both the toolbar picker AND a shelf's context menu
    /// can drive the same push. `nil` = nothing chosen yet.
    @Binding var comparisonTargetId: SongID?

    @State private var isPresentingPicker = false

    /// A trivial `Identifiable` + `Hashable` wrapper around
    /// `comparisonTargetId` — `.navigationDestination(item:)` requires BOTH
    /// conformances, and a bare `SongID` (already `Hashable`, not
    /// `Identifiable`) doesn't qualify on its own without adding a
    /// conformance to a type this package doesn't own the meaning of
    /// everywhere.
    private struct ComparisonTarget: Identifiable, Hashable {
        let id: SongID
    }

    /// Bridges `comparisonTargetId` (the plain `SongID?` every caller
    /// shares) to the `Identifiable` wrapper `.navigationDestination(item:)`
    /// needs — a computed `Binding`, not a second stored value, so there is
    /// exactly ONE source of truth for "what's the current comparison
    /// target."
    private var comparisonTarget: Binding<ComparisonTarget?> {
        Binding(
            get: { comparisonTargetId.map(ComparisonTarget.init) },
            set: { comparisonTargetId = $0?.id }
        )
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
                    comparisonTargetId = selectedId
                }
            }
            .navigationDestination(item: comparisonTarget) { target in
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

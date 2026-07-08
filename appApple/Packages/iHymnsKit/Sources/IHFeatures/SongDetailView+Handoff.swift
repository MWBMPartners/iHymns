// SongDetailView+Handoff.swift
// IHFeatures
//
// ELI5: The bit of `SongDetailView` that lets a song "follow you" to
// another Apple device (or hand off into Safari) — moved into its own file
// purely so `SongDetailView.swift` itself has room under the repo's
// LOC-budget tripwire (`Scripts/loc-budget.sh`) for #1445's "Compare
// With…" entry point. A PURE move: the `handoffActivity(webpageURL:title:)`
// modifier below is byte-for-byte the same #186 implementation that used
// to live at the bottom of `SongDetailView.swift` as a `private extension
// View` — see #1450's `OfflineStore+Favorites.swift` for the identical
// "moved out purely for the LOC tripwire" precedent. The ONE real change
// this move requires is dropping `private` from the extension: Swift's
// `private` access control is FILE-scoped even for a type's own
// extensions, so `SongDetailView.swift`'s `.handoffActivity(...)` call
// site needs this to be at least `internal` (the default) once the two
// live in separate files.
import Foundation
import SwiftUI

/// #186 — the Handoff modifier, isolated in its own extension purely to
/// keep the `#if os(...)` platform-guard block out of `SongDetailView
/// .body` itself.
extension View {
    /// Advertises `webpageURL` as an `NSUserActivityTypeBrowsingWeb` Handoff
    /// activity for as long as this view is on screen — a no-op (returns
    /// `self` unmodified) while `webpageURL` is `nil` (still loading/
    /// errored, mirroring `SongDetailView.shareURL`'s own guard) so nothing
    /// is ever advertised for a song that hasn't actually loaded.
    ///
    /// ELI5: "Let this song follow you to another Apple device — or hand
    /// off straight into Safari from here."
    ///
    /// DETAILED: `NSUserActivityTypeBrowsingWeb` (Foundation's Handoff
    /// activity type for "continuing a web page," available since iOS 8/
    /// macOS 10.10) pairs with the AASA's own `activitycontinuation` key
    /// (`.well-known/apple-app-site-association.php`, #1401) and
    /// `IHymnsApp.swift`'s `.onContinueUserActivity(NSUserActivityTypeBrowsingWeb)`
    /// handler — a Handoff continuation's `webpageURL` runs through the
    /// SAME `DeepLinkRouter.resolve(_:)` a Universal Link tap does, so
    /// resuming on another device lands on this exact song either way.
    /// Guarded to iOS/macOS/visionOS only — see `SongDetailView.swift`'s
    /// #186 header comment for why tvOS/watchOS (which `IHFeatures` also
    /// compiles for, per `RootContainerView.swift`'s own header) are
    /// deliberately excluded, matching that task's explicit "guard any
    /// iOS-only Handoff… API" instruction.
    @ViewBuilder
    func handoffActivity(webpageURL: URL?, title: String) -> some View {
        #if os(iOS) || os(macOS) || os(visionOS)
        if let webpageURL {
            self.userActivity(NSUserActivityTypeBrowsingWeb) { activity in
                activity.webpageURL = webpageURL
                activity.title = title
                activity.isEligibleForHandoff = true
            }
        } else {
            self
        }
        #else
        self
        #endif
    }
}

// DeepLinkDestinationView.swift
// IHFeatures
//
// ELI5: The screen that pops up when you tap an iHymns link from outside
// the app (Messages, Mail, another app) — figures out what the link was
// pointing at and shows exactly that (a song, a songbook, a shared set
// list…), wrapped in its own little "Done" button so you can get back to
// wherever you were in the app.
//
// DETAILED: #186 (Apple Phase 1, "Sharing & social"). `RootContainerView`
// gives every top-level section (Home/Songbooks/Search/Setlists/…) its OWN
// independent `NavigationStack` (`RootContainerView.swift`'s own header:
// "each stack that might push a SongID needs its own copy of that
// modifier") and `NavigationSplitView`'s 3-column content→detail push
// behaviour has no clean, testable, cross-platform way to be driven
// programmatically from OUTSIDE a tap gesture. Rather than guess at
// per-section `NavigationPath` bindings (untestable without a simulator —
// see this task's own "simulator destinations FAIL locally" note), a deep
// link is presented as its OWN `.sheet(item:)` (`RootContainerView`'s
// `.sheet` call site), wrapping a FRESH `NavigationStack` — the exact same
// "content presented from outside the normal navigation flow" pattern this
// package already uses for `LoginView`/`AddToSetlistSheet`. This works
// identically on iPhone tab, iPad/Mac split view, and visionOS, and never
// disturbs whatever the user was already doing in the tab/sidebar
// underneath — closing the sheet returns exactly there.
//
// `.work` is included in the `switch` purely for EXHAUSTIVENESS — the app
// shell (`IHymnsApp.swift`) never actually constructs a `DeepLinkPresentation`
// for a `.work` link at all (it hands the URL straight to the system
// browser instead, since no native Work-detail screen exists yet — see
// `DeepLink.swift`'s header). `EmptyView` here is unreachable in practice.
import IHAppSupport
import IHModels
import SwiftUI

/// A `.sheet(item:)`-presentable wrapper around a resolved `DeepLink` — a
/// thin `Identifiable` shim purely so the SAME link can be re-presented even
/// if it's byte-identical to the previous one (a UUID identity, not a
/// content hash), since `DeepLink` itself stays a plain `Equatable` value
/// type for `DeepLinkRouterTests`' own equality assertions.
public struct DeepLinkPresentation: Identifiable {
    public let id = UUID()
    public let link: DeepLink

    public init(link: DeepLink) {
        self.link = link
    }
}

/// Renders the right screen for a resolved `DeepLink`, wrapped in its own
/// `NavigationStack` + a dismiss ("Done") button.
struct DeepLinkDestinationView: View {
    let link: DeepLink
    let rootViewModel: AppRootViewModel

    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            destination
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("Done") { dismiss() }
                    }
                }
        }
    }

    @ViewBuilder
    private var destination: some View {
        switch link {
        case .song(let songId):
            SongDetailView(songId: songId, rootViewModel: rootViewModel)

        case .songbooksList:
            SongbooksView(rootViewModel: rootViewModel)

        case .songbook(let abbreviation):
            DeepLinkSongbookResolverView(abbreviation: abbreviation, rootViewModel: rootViewModel)

        case .setlistShare(let shareId):
            SharedSetlistView(shareId: shareId, rootViewModel: rootViewModel)

        case .work:
            // Unreachable — see this file's header.
            EmptyView()
        }
    }
}

/// Resolves a bare songbook abbreviation (all a `/songbook/<abbr>` deep
/// link carries) against the live songbook list, then hands off to the SAME
/// `SongbookSongsView` a normal tile tap would push — a deep link has no
/// synchronous way to get a full `Songbook` value, unlike
/// `SongbooksView`/`NavigationLink(value:)`'s tap-time access to the
/// already-rendered tile's own `Songbook`.
private struct DeepLinkSongbookResolverView: View {
    let abbreviation: String
    let rootViewModel: AppRootViewModel

    private enum Resolution {
        case loading
        case found(Songbook)
        case notFound
    }

    @State private var resolution: Resolution = .loading

    var body: some View {
        Group {
            switch resolution {
            case .loading:
                ProgressView("Loading songbook…")
                    .frame(maxWidth: .infinity, minHeight: 200)

            case .found(let songbook):
                SongbookSongsView(songbook: songbook, rootViewModel: rootViewModel)

            case .notFound:
                ContentUnavailableView(
                    "Songbook Not Found",
                    systemImage: "books.vertical",
                    description: Text("\"\(abbreviation)\" doesn't match any songbook in the catalogue.")
                )
            }
        }
        .task { await resolve() }
    }

    private func resolve() async {
        guard let songbooks = try? await rootViewModel.songbooks(),
              let match = songbooks.first(where: { $0.id.caseInsensitiveCompare(abbreviation) == .orderedSame }) else {
            resolution = .notFound
            return
        }
        resolution = .found(match)
    }
}

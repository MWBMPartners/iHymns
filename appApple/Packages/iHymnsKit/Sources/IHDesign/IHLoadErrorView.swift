// IHLoadErrorView.swift
// IHDesign
//
// ELI5: The "something went wrong, here's a button to try again" card every
// screen that fetches something over the network can share — instead of
// each screen inventing its own error card (some with a retry button, most
// without one at all).
//
// DETAILED: #185 (Apple Phase 1, navigation & UX consolidation). Before this
// file, every `LoadState.error(let message)` branch across the app
// (`HomeView`, `CatalogueListView`, `SongbookSongsView`, `SongbooksView`,
// `SongDetailView`, `SheetMusicView`, `SharedSetlistView`,
// `SetlistCatalogueBrowserView`) rendered its OWN `ContentUnavailableView`
// with a title/systemImage/description, but NONE of them offered a way to
// retry short of leaving the screen and coming back — even though most of
// the underlying view models already exposed a `load()` method whose own
// doc comment says "the hook a future manual retry… action calls into"
// (e.g. `SongbooksViewModel.load()`, `HomeViewModel.load()`,
// `AppRootViewModel.loadCatalogue()`). This type is that hook, wired up:
// ONE shared, retry-capable error view (the repo's modularity rule — "if a
// piece of UI exists in more than one place, extract it") rather than eight
// screens each growing their own bespoke "Retry" button, which would be
// exactly the kind of divergent copy-paste `.claude/CLAUDE.md`'s red-flags
// list warns against.
//
// Reuses `ContentUnavailableView`'s own `Label`/`description`/`actions`
// closures (the SAME idiom `FavoritesView.signedOutView`/
// `SetlistsView.emptyView` already use for THEIR actionable empty states)
// rather than a hand-rolled `VStack` — this is still, at heart, a
// `ContentUnavailableView`, just with the retry action factored out so
// every call site gets it "for free" instead of re-typing the same
// `Button("Retry") { Task { await ... } }` boilerplate eight times.
import SwiftUI

/// A shared "couldn't load, here's what happened, try again" state view —
/// the ONE error-with-retry card every network-backed screen in the app
/// should use for its `LoadState.error` branch.
///
/// ELI5: "Oops, that didn't work — here's why, and a button to try again."
public struct IHLoadErrorView: View {
    /// The short, bolded headline — e.g. "Couldn't Load Songs."
    public let title: String

    /// The already-user-facing failure reason (see `LoadState.error`'s own
    /// doc comment on why this is a display-ready `String`, never a raw
    /// `Error`) — shown as the description body.
    public let message: String

    /// The SF Symbol shown above the title. Defaults to the same
    /// "wifi.exclamationmark" glyph every existing network-error card in
    /// this package already used before this type existed, so adopting
    /// this view is visually a no-op for the six call sites that keep the
    /// default; callers with a more specific failure (e.g. `SheetMusicView`'s
    /// "doc.text.magnifyingglass" for a bad PDF) can still override it.
    public let systemImage: String

    /// Retries the failed operation — typically a view model's `load()`.
    /// `nil` hides the "Retry" button entirely (e.g. a genuinely
    /// unrecoverable state with nothing sensible to retry), so this view
    /// still degrades gracefully to a plain, non-actionable error card.
    public let retry: (() async -> Void)?

    public init(
        title: String,
        message: String,
        systemImage: String = "wifi.exclamationmark",
        retry: (() async -> Void)? = nil
    ) {
        self.title = title
        self.message = message
        self.systemImage = systemImage
        self.retry = retry
    }

    public var body: some View {
        ContentUnavailableView {
            Label(title, systemImage: systemImage)
        } description: {
            Text(message)
        } actions: {
            if let retry {
                Button("Retry") {
                    Task { await retry() }
                }
                .buttonStyle(.borderedProminent)
            }
        }
    }
}

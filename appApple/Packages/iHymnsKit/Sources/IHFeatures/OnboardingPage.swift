// OnboardingPage.swift
// IHFeatures
//
// ELI5: The actual words and pictures for each page of the "welcome to
// iHymns" tour — kept separate from `OnboardingView.swift` itself so the
// CONTENT (what the four pages say) is easy to find and edit without
// wading through the paging/animation code that RENDERS it.
//
// DETAILED: #190 (Apple Phase 1 — first-run onboarding, "a few Liquid-Glass
// pages introducing browse/search/offline/setlists... a small paged intro,
// not a tutorial engine"). Exactly four pages, one per named topic — no
// more were added, per this task's own explicit "don't over-build"
// instruction. Each `systemImage` deliberately reuses the SAME SF Symbol
// `AppNavigationState.RootSection`/`SettingsView.storageSection` already use
// for that concept (`"books.vertical"` = Songbooks tab, `"magnifyingglass"`
// = Search tab, `"arrow.down.circle"` = the Storage & Offline row,
// `"list.bullet.rectangle.portrait"` = Setlists tab) — so a user who taps
// through onboarding then opens the real tab bar sees the SAME icon meaning
// the SAME thing, not a mismatched second iconography.
import Foundation

/// One page of the first-launch onboarding flow.
///
/// ELI5: "One slide of the welcome tour."
public struct OnboardingPage: Sendable, Equatable, Identifiable {
    public let id: String
    public let title: String
    public let message: String
    public let systemImage: String

    public init(id: String, title: String, message: String, systemImage: String) {
        self.id = id
        self.title = title
        self.message = message
        self.systemImage = systemImage
    }
}

public extension OnboardingPage {
    /// The fixed, ordered set `OnboardingView` pages through — see this
    /// file's header for why it's exactly these four, in this order.
    static let all: [OnboardingPage] = [
        OnboardingPage(
            id: "browse",
            title: "Browse Every Songbook",
            message: "Explore hymnals and worship songbooks from around the world, organised the way you'd expect.",
            systemImage: "books.vertical"
        ),
        OnboardingPage(
            id: "search",
            title: "Find Any Song Instantly",
            message: "Search by title, number, or songbook, and filter by language to find exactly what you need.",
            systemImage: "magnifyingglass"
        ),
        OnboardingPage(
            id: "offline",
            title: "Save Songs for Offline",
            message: "Download your favourite songs so you can read and play them with no connection at all.",
            systemImage: "arrow.down.circle"
        ),
        OnboardingPage(
            id: "setlists",
            title: "Build & Share Setlists",
            message: "Put together a setlist for worship or practice, then share it with your team in one tap.",
            systemImage: "list.bullet.rectangle.portrait"
        )
    ]
}

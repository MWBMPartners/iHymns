// AppNavigationState.swift
// IHFeatures
//
// ELI5: "Which top-level tab/sidebar section is showing right now, and is
// the keyboard-shortcuts help sheet open?" — pulled OUT of
// `RootContainerView` into its own small, shared object so a Mac menu-bar
// command (built in the app shell, `IHymnsApp.swift`) can change the
// selected section too, not just a tap on the sidebar/tab bar.
//
// DETAILED: #185 (Apple Phase 1, navigation & UX consolidation) fixes two
// real gaps found while auditing the existing shell against the issue's
// checklist: (1) `RootContainerView`'s compact-width `TabView` had NO
// `selection:` binding at all — it was a plain `TabView { ... .tabItem
// {...} }` with no way to programmatically switch tabs, which is exactly
// what a Mac "Go to Search" (⌘F) menu command, or a future deep-link/
// notification-driven tab switch, needs; (2) "Keyboard shortcuts overlay
// (press ? on iPad/Mac)" was ticked complete on the issue but nothing in
// the codebase implemented it (verified by grepping for
// `CommandGroup`/`onKeyPress`/"shortcuts" before starting — zero hits).
//
// `RootSection` moved here (was a `private enum` at the bottom of
// `RootContainerView.swift`) and made `public` for the SAME reason this
// whole type exists: `IHymnsApp.swift` (a different module — the `IHymns`
// app target, not `IHFeatures`) needs to reference it to build its
// section-switching `CommandGroup`.
//
// `RootSection`/`selectedSection` is NOT folded into `AppRootViewModel`
// itself — that file is already at the repo's LOC-budget tripwire edge
// (397/400 lines) and holds ENGINE state (auth/catalogue/favourites/
// setlists), whereas this is pure UI-chrome state with a completely
// different lifecycle owner (`RootContainerView`/`IHymnsApp`, not the
// app's one `AppRootViewModel`) — the same "extract into its own file
// rather than grow an already-large one" instinct `AppRootViewModel`'s own
// `AppRootViewModel+*.swift` siblings already establish, just for a type
// that was never going to fit there in the first place.
import IHAppSupport
import Observation

/// Cross-cutting "what's currently showing / presented" UI state for the
/// app shell — shared between `RootContainerView` (which reads/writes it
/// from taps) and `IHymnsApp`'s Mac menu commands (which write it from
/// keyboard shortcuts).
///
/// ELI5: "Which section am I looking at, and is the shortcuts help sheet
/// open?" — one small object both the screen and the Mac menu bar can
/// change.
@MainActor
@Observable
public final class AppNavigationState {
    /// Which top-level section is currently showing — a tab on iPhone, a
    /// sidebar row on iPad/Mac/vision. Defaults to `.home`, matching
    /// `RootContainerView`'s pre-#185 default exactly (no behaviour change
    /// for anyone who never touches a keyboard shortcut).
    public var selectedSection: RootSection = .home

    /// Whether the "Keyboard Shortcuts" help sheet (`KeyboardShortcutsOverlayView`)
    /// is currently presented — flipped `true` by the Mac ⌘/ command or a
    /// Settings row, and by the sheet's own dismissal back to `false`.
    public var isPresentingKeyboardShortcutsHelp = false

    /// #1415 — the ONE way an App Intent (`OpenSongIntent`/
    /// `PlaySongOfTheDayIntent`, `IHFeatures/AppIntents/`) hands a resolved
    /// destination to the UI: the intent runs in-process (via `@Dependency`,
    /// registered on this SAME instance by `Apps/iHymns/Sources/IHymnsApp.swift`'s
    /// `AppDependencyManager.shared.add(dependency:)`), sets this property,
    /// and that app shell's `.onChange(of:)` turns it into the SAME
    /// `incomingDeepLink` presentation a tapped Universal Link already uses
    /// — App Intents feed the EXISTING deep-link pipeline rather than
    /// inventing a second navigation path (this repo's modularity rule,
    /// `.claude/CLAUDE.md`).
    ///
    /// ELI5: "An App Intent just said 'go here' — the app shell picks this
    /// up and shows it, exactly like tapping a shared link would."
    public var pendingDeepLink: DeepLink?

    public init() {}
}

/// The top-level screens `RootContainerView` switches between — a tab on
/// iPhone, a sidebar row on iPad/Mac/vision. `public` (moved out of
/// `RootContainerView.swift`, where it was `private`) so `IHymnsApp.swift`'s
/// Mac `CommandGroup` can target one by name (e.g. `navigationState
/// .selectedSection = .search` for ⌘F).
public enum RootSection: String, CaseIterable, Identifiable, Hashable, Sendable {
    case home
    case songbooks
    case search
    case favorites
    case setlists
    case live
    // The former standalone `.account` section became `.settings`: the
    // Settings screen (`SettingsView`) NESTS the account UI inside it (an
    // "Account" row → `AccountView`), matching the conventional iOS "Apple
    // ID sits at the top of Settings" shape and keeping the compact
    // `TabView` at 7 tabs rather than overflowing into a system "More" tab.
    case settings

    public var id: String { rawValue }

    public var title: String {
        switch self {
        case .home: "Home"
        case .songbooks: "Songbooks"
        case .search: "Search"
        case .favorites: "Favourites"
        case .setlists: "Setlists"
        case .live: "Live"
        case .settings: "Settings"
        }
    }

    public var systemImage: String {
        switch self {
        case .home: "house"
        case .songbooks: "books.vertical"
        case .search: "magnifyingglass"
        case .favorites: "heart"
        case .setlists: "list.bullet.rectangle.portrait"
        case .live: "dot.radiowaves.left.and.right"
        case .settings: "gearshape"
        }
    }

    /// A single digit `1`–`7`, matching this section's position in
    /// `allCases` — the Mac `CommandGroup`'s ⌘1…⌘7 section-switch shortcuts
    /// key off this so the menu labels/shortcuts and this enum's case order
    /// can never drift apart into two competing "which number is Search?"
    /// sources of truth.
    public var keyboardShortcutDigit: Character {
        let index = Self.allCases.firstIndex(of: self) ?? 0
        return Character("\(index + 1)")
    }
}

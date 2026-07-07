// SettingsViewModel.swift
// IHFeatures
//
// ELI5: The object `SettingsView` actually reads/writes — pick a new theme
// in the UI, and this object both remembers it for THIS app run (so every
// screen redraws with it instantly) and saves it to disk (so it's still
// picked next time you open the app).
//
// DETAILED: #182 (Apple P1 Settings)'s explicit instruction: "Wire theme/
// CVD/dyslexia into an `@Observable @MainActor` settings view-model that
// the views read." Mirrors `AppRootViewModel`'s own "`@Observable`
// MainActor VM, the view just renders it" shape (that file's header) at a
// SMALLER scale — this view model owns exactly one engine
// (`IHSettingsStore`), not four, because Settings' job is orthogonal to the
// network/auth/offline/live composition root: `RootContainerView` holds
// ONE instance of each (an `AppRootViewModel` AND a `SettingsViewModel`,
// see that file's own header for why they're kept separate) and hands both
// down to whichever screen needs them.
//
// Every property below uses a `didSet` that writes straight through to
// `store` — the "set once in the UI, persisted immediately, no separate
// Save button" pattern this kind of Settings screen is expected to have
// (iOS System Settings itself works this way). `@Observable`'s macro
// expansion preserves ordinary `didSet` observers on its tracked stored
// properties (https://developer.apple.com/documentation/observation), so
// this is the same "listen for changes, persist as a side effect" idiom any
// other `didSet` would be, just on an `@Observable` type.
import IHAPI
import IHDesign
import Observation

/// The Settings screen's view model — the app's theme/CVD/consent/
/// environment preferences, mirrored live from (and persisted straight back
/// to) `IHSettingsStore`.
///
/// ELI5: "What are the current Settings, and where do I save a change?"
@MainActor
@Observable
public final class SettingsViewModel {
    /// `internal` (not `private`) — same cross-file-extension precedent
    /// `AppRootViewModel.apiClient`/`.recentSearchesStore` already
    /// establish for "another file in this module needs it too": #1412's
    /// dyslexia-mode addition extends this same view model from its OWN
    /// file rather than growing this one past a manageable size.
    let store: IHSettingsStore

    /// The active appearance theme — `RootContainerView` applies
    /// `.preferredColorScheme(theme.colorScheme)` from this at the app
    /// root, so every screen re-themes the instant this changes.
    public var theme: IHAppearanceTheme {
        didSet { store.theme = theme }
    }

    /// The active colour-vision-deficiency accommodation mode (storage-only
    /// for #182 — see `IHCVDMode`'s own header for what's deferred).
    public var cvdMode: IHCVDMode {
        didSet { store.cvdMode = cvdMode }
    }

    /// Whether the user has opted in to the first-party analytics ping —
    /// the persisted preference a future #189 reads before ever sending one.
    public var analyticsConsentEnabled: Bool {
        didSet { store.analyticsConsentEnabled = analyticsConsentEnabled }
    }

    /// Whether the dyslexia-friendly reading mode (#1412) is on. Lives here
    /// as a stored `@Observable` property (NOT in a `SettingsViewModel+…`
    /// extension) because Swift forbids adding a STORED property — which an
    /// `@Observable`-tracked, view-refreshing toggle must be — in an
    /// extension; the actual RENDERING this drives (`IHReadingMode` +
    /// `IHLyricTypography`, injected at `RootContainerView` via
    /// `\.ihReadingMode`) is what #1412 layers on top of this persisted bit.
    public var dyslexiaReadingModeEnabled: Bool {
        didSet { store.dyslexiaReadingModeEnabled = dyslexiaReadingModeEnabled }
    }

    /// An explicit dev/beta/prod override, or `nil` for "use the app
    /// shell's compiled-in default" — see `IHSettingsStore
    /// .apiEnvironmentOverride`'s own header for why this takes effect on
    /// next launch rather than live.
    public var apiEnvironmentOverride: APIEnvironment? {
        didSet { store.apiEnvironmentOverride = apiEnvironmentOverride }
    }

    /// - Parameter store: Where preferences persist; defaults to the real
    ///   `IHSettingsStore()` (itself defaulting to `UserDefaults.standard`).
    ///   Tests inject a store built on a throwaway
    ///   `UserDefaults(suiteName:)` the same way `AppRootViewModel`'s own
    ///   `recentSearchesStore`/`recentlyViewedStore` parameters are tested.
    public init(store: IHSettingsStore = IHSettingsStore()) {
        self.store = store
        // Read the CURRENTLY-persisted values first, into plain locals, so
        // the `didSet` observers above — which unconditionally re-write
        // `store` on every assignment — don't immediately re-save the exact
        // values they just loaded. Harmless either way (writing the same
        // value back is a no-op for `UserDefaults`), but assigning through
        // `self.theme = store.theme` here would trigger `didSet` for no
        // reason; direct backing-storage initialization avoids that.
        theme = store.theme
        cvdMode = store.cvdMode
        analyticsConsentEnabled = store.analyticsConsentEnabled
        dyslexiaReadingModeEnabled = store.dyslexiaReadingModeEnabled
        apiEnvironmentOverride = store.apiEnvironmentOverride
    }
}

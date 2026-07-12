// IHSettingsStore.swift
// IHFeatures
//
// ELI5: The one place every "Settings" toggle/picker actually gets saved to
// disk, so the next time you open the app it remembers your theme, colour
// -vision mode, whether you've agreed to anonymous usage stats, and which
// server copy you're pointed at.
//
// DETAILED: #182 (Apple P1 Settings screen). Follows `RecentSearchesStore
// .swift`'s exact established shape — a plain, `@unchecked Sendable` value
// type wrapping an INJECTABLE `UserDefaults` (never `@AppStorage` scattered
// across views) — see that file's own header for the full "why injectable,
// why not `@AppStorage`" rationale, which applies identically here: this
// type needs to be substitutable with a throwaway `UserDefaults
// (suiteName:)` in tests the exact same way `APIClient`'s `URLSession` and
// `KeychainTokenStore`'s Keychain attributes already are. Shaped as a small
// bag of independent computed properties (rather than `RecentSearchesStore`
// -style `load()`/`record()`/`clear()` methods) because a settings screen is
// fundamentally "several independent named preferences," not one ordered
// list — each property reads/writes its OWN `UserDefaults` key, so toggling
// one setting can never disturb another's stored value.
//
// `SettingsViewModel` (this task's `@Observable @MainActor` view-model
// requirement) is the ONLY thing that should hold a live instance of this
// store outside tests — `SettingsView` reads/writes the view model, never
// this store directly, mirroring how `CatalogueListView` never touches
// `RecentSearchesStore` directly either (`AppRootViewModel` sits in between).
import Foundation
import IHAPI
import IHDesign

/// Persists every `Settings` screen preference — theme, colour-vision mode,
/// analytics consent, and an optional API-environment override — behind one
/// small, independently-testable seam.
///
/// ELI5: A little filing cabinet for every Settings toggle/picker.
///
/// DETAILED: `@unchecked Sendable` for the identical reason
/// `RecentSearchesStore` documents: this SDK's `UserDefaults` doesn't yet
/// carry a compiler-visible `Sendable` conformance even though Apple has
/// always documented it as thread-safe for exactly this read/write usage
/// (https://developer.apple.com/documentation/foundation/userdefaults).
/// Every OTHER stored property here is a plain `Sendable` value type (the
/// `UserDefaults` reference itself is the only thing actually relying on
/// the `@unchecked` escape hatch).
public struct IHSettingsStore: @unchecked Sendable {
    private let defaults: UserDefaults

    /// - Parameter defaults: Where to persist — `.standard` for the real
    ///   app; tests inject a throwaway `UserDefaults(suiteName:)` so runs
    ///   never pollute (or are polluted by) the real app's saved settings or
    ///   each other.
    public init(defaults: UserDefaults = .standard) {
        self.defaults = defaults
    }

    /// The chosen appearance theme (#182's "Appearance/theme" requirement).
    /// Defaults to `.system` — an unset key (a fresh install) should follow
    /// the OS, not silently force light or dark.
    ///
    /// ELI5: "Which of System/Light/Dark/High-Contrast did you pick?"
    public var theme: IHAppearanceTheme {
        get { IHAppearanceTheme(rawValue: defaults.string(forKey: Keys.theme) ?? "") ?? .system }
        nonmutating set { defaults.set(newValue.rawValue, forKey: Keys.theme) }
    }

    /// The chosen colour-vision-deficiency accommodation mode (#182's "CVD
    /// mode toggle" requirement, storage-only per this task's brief).
    /// Defaults to `.none` — an unset key means no adjustment requested.
    public var cvdMode: IHCVDMode {
        get { IHCVDMode(rawValue: defaults.string(forKey: Keys.cvdMode) ?? "") ?? .none }
        nonmutating set { defaults.set(newValue.rawValue, forKey: Keys.cvdMode) }
    }

    /// Whether the dyslexia-friendly reading mode (#1412) is enabled.
    /// Defaults to `false` — strategy §4.2 is explicit this is "an explicit
    /// toggle, default off," never an auto-enabled behaviour change.
    ///
    /// ELI5: "Should lyrics render in the dyslexia-friendly style?"
    public var dyslexiaReadingModeEnabled: Bool {
        get { defaults.bool(forKey: Keys.dyslexiaReadingMode) }
        nonmutating set { defaults.set(newValue, forKey: Keys.dyslexiaReadingMode) }
    }

    /// Whether the user has opted in to the first-party, consent-gated
    /// analytics ping (#182's "analytics/telemetry consent toggle"
    /// requirement — the persisted preference a future #189 reads).
    /// Defaults to `false`: strategy §3.1's explicit "no ATT... default
    /// OFF" decision — `Bool.defaultValue` from `defaults.bool(forKey:)` on
    /// a never-set key IS already `false`, so a fresh install needs no
    /// extra guard to honour that default.
    ///
    /// ELI5: "Have you said yes to sharing anonymous usage stats?"
    public var analyticsConsentEnabled: Bool {
        get { defaults.bool(forKey: Keys.analyticsConsent) }
        nonmutating set { defaults.set(newValue, forKey: Keys.analyticsConsent) }
    }

    /// Whether the user has already been shown the first-launch onboarding
    /// flow (#190's "shown once, skippable" requirement) — `RootContainerView`
    /// checks this once at launch to decide whether to present
    /// `OnboardingView` at all. Defaults to `false`: an unset key means a
    /// genuinely fresh install, which is exactly when onboarding should
    /// show. Set to `true` the moment the user dismisses it (tapping either
    /// "Skip" or the final page's "Get Started" — both count as "seen," per
    /// this task's own "skippable" brief: skipping must not re-show it on
    /// the very next launch).
    ///
    /// ELI5: "Have you already seen the welcome tour?"
    public var hasSeenOnboarding: Bool {
        get { defaults.bool(forKey: Keys.hasSeenOnboarding) }
        nonmutating set { defaults.set(newValue, forKey: Keys.hasSeenOnboarding) }
    }

    /// Whether the user has already been shown the LAN-remote screen's
    /// Local Network explainer card (#1422, `.claude/apple-phase2-pr7-spec.md`
    /// §4.3: "permission prompt explained pre-fire" — strategy §2.4.5).
    /// Same shape as `hasSeenOnboarding` above: an unset key means the card
    /// hasn't been shown yet, so `RemoteControlView` shows it before the
    /// FIRST `NWBrowser` start (which is what actually triggers the OS's own
    /// Local Network permission prompt) rather than letting that system
    /// prompt appear with no context. Set `true` the moment the card's
    /// "Continue" button is tapped (`RemoteControlCoordinator
    /// .acknowledgeLocalNetworkPrimer()`).
    ///
    /// ELI5: "Have you already seen the explanation for why iHymns wants to
    /// look for devices on your network?"
    public var hasSeenLocalNetworkPrimer: Bool {
        get { defaults.bool(forKey: Keys.hasSeenLocalNetworkPrimer) }
        nonmutating set { defaults.set(newValue, forKey: Keys.hasSeenLocalNetworkPrimer) }
    }

    /// An explicit override of which backend deployment (`IHAPI
    /// .APIEnvironment`) this device targets, or `nil` to use the app
    /// shell's own compiled-in default (`IHymnsApp.swift`'s `.dev`, per
    /// strategy §3.1's Debug→dev mapping) — #182's "environment/base-URL
    /// picker" requirement, surfacing the SAME `APIEnvironment` type
    /// `IHAPI`/`AppRootViewModel.makeLive(environment:)` already define
    /// rather than forking a second env concept.
    ///
    /// ELI5: "Should this device talk to dev/beta/prod instead of whatever
    /// it normally would?" `nil` = "no override, use the normal default."
    ///
    /// DETAILED: Takes effect on next launch, not live — `APIClient` is an
    /// immutable-once-constructed `actor` (see that type's own header:
    /// `environment` is a `let`), and hot-swapping it mid-session would mean
    /// rebuilding every engine `AppRootViewModel` composes. `SettingsView`'s
    /// footer text says so explicitly rather than implying an instant
    /// switch that doesn't actually happen.
    public var apiEnvironmentOverride: APIEnvironment? {
        get { defaults.string(forKey: Keys.apiEnvironment).flatMap(APIEnvironment.init(rawValue:)) }
        nonmutating set {
            if let newValue {
                defaults.set(newValue.rawValue, forKey: Keys.apiEnvironment)
            } else {
                defaults.removeObject(forKey: Keys.apiEnvironment)
            }
        }
    }

    /// The last address/port a human typed into "Connect by Address"
    /// (#1424, `.claude/apple-phase2-pr8-spec.md` §6.2/§6.4) — pre-fills
    /// BOTH `ManualConnectSheet` and `NetworkTroubleshooterView`'s address
    /// field next time, since a venue's TV usually keeps the same address.
    /// `nil` on a fresh install (nothing typed yet). A LAN host string is
    /// not a secret (this repo's own `LANRemoteManualAddress.swift` header:
    /// "UX-grade validation... not a security boundary") — `UserDefaults`
    /// is the correct custody, same posture as every other property here.
    ///
    /// ELI5: "The last TV address you typed in by hand, so you don't have
    /// to retype it next time."
    public var lastManualConnectAddress: String? {
        get { defaults.string(forKey: Keys.lastManualConnectAddress) }
        nonmutating set { defaults.set(newValue, forKey: Keys.lastManualConnectAddress) }
    }

    /// The `UserDefaults` keys this store owns exclusively — kept as one
    /// private namespace so every property above reads/writes the exact
    /// same literal, never a copy-pasted string that could drift.
    private enum Keys {
        static let theme = "ihAppearanceTheme"
        static let cvdMode = "ihCvdMode"
        static let dyslexiaReadingMode = "ihDyslexiaReadingModeEnabled"
        static let analyticsConsent = "ihAnalyticsConsentEnabled"
        static let hasSeenOnboarding = "ihHasSeenOnboarding"
        static let hasSeenLocalNetworkPrimer = "ihHasSeenLocalNetworkPrimer"
        static let apiEnvironment = "ihApiEnvironmentOverride"
        static let lastManualConnectAddress = "ihLastManualConnectAddress"
    }
}

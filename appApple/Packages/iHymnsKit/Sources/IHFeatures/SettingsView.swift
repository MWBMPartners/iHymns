// SettingsView.swift
// IHFeatures
//
// ELI5: The app's Settings screen — pick your theme, a colour-vision mode,
// turn the dyslexia-friendly reading style on/off, choose whether to share
// anonymous usage stats, get to your Account (sign in/out), and see the app
// version. On a developer build it also lets you point the app at a
// different server copy.
//
// DETAILED: #182 (Apple P1 Settings). A single `Form` reachable from the
// app shell's `Settings` section (`RootContainerView`, which replaced the
// old standalone `Account` tab with this screen and nests Account INSIDE it
// — the conventional iOS "Apple ID at the top of Settings" shape, and it
// keeps the compact-width `TabView` at 7 tabs instead of overflowing into a
// system "More" tab). The view is a PURE renderer of `SettingsViewModel`
// (every picker/toggle is a `$settings.…` binding whose `didSet` persists
// straight to `IHSettingsStore`) plus `AppRootViewModel` (read-only, for the
// Account row's signed-in summary) — it owns no persistence or network logic
// of its own, mirroring every other screen in this package.
//
// The dyslexia toggle here persists the preference (#182); the actual
// lyric-RENDERING change it drives lands in #1412 (`IHReadingMode` +
// `IHLyricTypography`, injected at `RootContainerView`). The environment
// picker is compiled in ONLY under `DEBUG` (see `APIEnvironment
// .defaultForBuild`'s own note on why a `#if` can't distinguish TestFlight
// from the App Store) so a shipping user can never accidentally point the
// app at a stale docroot.
//
// #187 UPDATE (offline support + data management) — adds `storageSection`,
// a single `NavigationLink` into the new `OfflineStorageView` (its own
// screen, not inlined here, so this file doesn't have to grow a whole
// list-management UI directly) — this task's "Data management UI... a
// 'Storage & Offline' section reachable from Settings" requirement.
//
// #185 UPDATE (Apple Phase 1, navigation & UX consolidation) — adds a
// "Keyboard Shortcuts" row to `aboutSection`, presenting the SAME
// `KeyboardShortcutsOverlayView` the Mac ⌘/ command opens
// (`IHymnsApp.swift`). Kept reachable here on EVERY platform (not just
// macOS) — an external keyboard can be paired with an iPhone/iPad too, and
// there's no harm in a sighted list of shortcuts on a device that happens
// not to have one attached right now.
import IHAPI
import IHDesign
import SwiftUI

/// The Settings screen — appearance, accessibility, privacy, account, and
/// (developer builds only) the API-environment override.
public struct SettingsView: View {
    /// Read-only, for the Account row's "signed in as …" summary. Accessing
    /// its `@Observable` state inside `body` is enough for SwiftUI to refresh
    /// this row when the session changes — no `@Bindable` needed for a
    /// read-only reference.
    private let rootViewModel: AppRootViewModel

    /// The settings the pickers/toggles below bind to. `@Bindable` so
    /// `$settings.theme` etc. yield the two-way bindings `Picker`/`Toggle`
    /// require; the model's own `didSet` observers do the persistence.
    @Bindable private var settings: SettingsViewModel

    /// #185 — flips the "Keyboard Shortcuts" row's sheet; see this file's
    /// header for why this is reachable from every platform, not just macOS.
    @State private var isPresentingKeyboardShortcuts = false

    public init(rootViewModel: AppRootViewModel, settings: SettingsViewModel) {
        self.rootViewModel = rootViewModel
        self._settings = Bindable(settings)
    }

    public var body: some View {
        Form {
            accountSection
            appearanceSection
            accessibilitySection
            storageSection
            privacySection
            #if DEBUG
            developerSection
            #endif
            aboutSection
        }
        .navigationTitle("Settings")
        .sheet(isPresented: $isPresentingKeyboardShortcuts) {
            KeyboardShortcutsOverlayView()
        }
    }

    // MARK: - Account

    /// A single navigation row into the existing `AccountView` (sign in/out,
    /// identity) — #182's "include the Account section (embed/link the
    /// existing `AccountView`)". The subtitle summarises session state so the
    /// user sees at a glance whether they're signed in without drilling in.
    private var accountSection: some View {
        Section {
            NavigationLink {
                AccountView(rootViewModel: rootViewModel)
            } label: {
                Label {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Account")
                        Text(accountSummary)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                } icon: {
                    Image(systemName: "person.crop.circle")
                }
            }
        }
    }

    /// "Signed in as @name" when a session exists, otherwise a sign-in nudge.
    private var accountSummary: String {
        if rootViewModel.sessionState.isSignedIn {
            if let username = rootViewModel.currentUser?.username {
                return "Signed in as @\(username)"
            }
            return "Signed in"
        }
        return "Not signed in — tap to sign in"
    }

    // MARK: - Appearance

    /// Theme + colour-vision-deficiency pickers, mirroring the web's
    /// `ihymns_theme` / `ihymns_cvd_mode` choices one-for-one (see
    /// `IHAppearanceTheme` / `IHCVDMode`).
    private var appearanceSection: some View {
        Section("Appearance") {
            Picker("Theme", selection: $settings.theme) {
                ForEach(IHAppearanceTheme.allCases, id: \.self) { theme in
                    Text(theme.displayName).tag(theme)
                }
            }

            Picker("Colour Vision", selection: $settings.cvdMode) {
                ForEach(IHCVDMode.allCases, id: \.self) { mode in
                    Text(mode.displayName).tag(mode)
                }
            }
        }
    }

    // MARK: - Accessibility

    /// The dyslexia-friendly reading toggle (#1412). This screen persists the
    /// choice; `RootContainerView` reads it back into the `\.ihReadingMode`
    /// environment so the lyric views actually re-render (that wiring is
    /// #1412's job).
    private var accessibilitySection: some View {
        Section {
            Toggle("Dyslexia-Friendly Reading", isOn: $settings.dyslexiaReadingModeEnabled)
        } header: {
            Text("Accessibility")
        } footer: {
            Text("Renders lyrics with a dyslexia-friendly font and extra spacing between letters and lines.")
        }
    }

    // MARK: - Storage & Offline

    /// A single navigation row into `OfflineStorageView` (#187) — mirrors
    /// `accountSection`'s "one row, real content lives on the pushed
    /// screen" shape.
    private var storageSection: some View {
        Section {
            NavigationLink {
                OfflineStorageView(rootViewModel: rootViewModel)
            } label: {
                Label("Storage & Offline", systemImage: "arrow.down.circle")
            }
        } footer: {
            Text("See and manage the songs you've saved for offline reading.")
        }
    }

    // MARK: - Privacy

    /// First-party, consent-gated analytics opt-in — default OFF, no Apple
    /// ATT prompt (strategy §3.1). The persisted flag a future #189 must see
    /// as `true` before it ever sends a single ping.
    private var privacySection: some View {
        Section {
            Toggle("Share Anonymous Usage Data", isOn: $settings.analyticsConsentEnabled)
        } header: {
            Text("Privacy")
        } footer: {
            Text("Off by default. When on, iHymns collects anonymous, first-party usage counts to improve the app — never your identity, and never shared with third parties or ad networks.")
        }
    }

    // MARK: - Developer (DEBUG-only)

    #if DEBUG
    /// The API-environment override — Development / Beta / Production, or
    /// "Automatic" (`nil` → `APIEnvironment.defaultForBuild`). Takes effect
    /// on the NEXT launch (see `IHSettingsStore.apiEnvironmentOverride`).
    private var developerSection: some View {
        Section {
            Picker("Environment", selection: $settings.apiEnvironmentOverride) {
                Text("Automatic (\(APIEnvironment.defaultForBuild.displayName))")
                    .tag(APIEnvironment?.none)
                ForEach(APIEnvironment.allCases, id: \.self) { environment in
                    Text(environment.displayName).tag(APIEnvironment?.some(environment))
                }
            }
        } header: {
            Text("Developer")
        } footer: {
            Text("Which backend copy this device talks to. Takes effect the next time you open the app. Developer builds only.")
        }
    }
    #endif

    // MARK: - About

    /// App version + build, read straight from the shell's `Info.plist` so it
    /// always matches the installed binary (never a hard-coded string that
    /// could drift from `Config/Versioning.xcconfig`).
    private var aboutSection: some View {
        Section("About") {
            LabeledContent("Version", value: appVersion)
            Button {
                isPresentingKeyboardShortcuts = true
            } label: {
                Label("Keyboard Shortcuts", systemImage: "keyboard")
            }
        }
    }

    /// e.g. `"0.1.0 (1720000000)"` — `CFBundleShortVersionString` (marketing)
    /// + `CFBundleVersion` (build), the two values `project.yml`'s
    /// `MARKETING_VERSION`/`CURRENT_PROJECT_VERSION` feed. An em dash if a
    /// value is somehow absent (e.g. a preview/host with no `Info.plist`).
    private var appVersion: String {
        let info = Bundle.main.infoDictionary
        let marketing = info?["CFBundleShortVersionString"] as? String ?? "—"
        let build = info?["CFBundleVersion"] as? String ?? "—"
        return "\(marketing) (\(build))"
    }
}

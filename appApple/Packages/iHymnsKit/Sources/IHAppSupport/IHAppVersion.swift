// IHAppVersion.swift
// IHAppSupport
//
// ELI5: The one place that reads "what version/build of the app is this?"
// off the installed bundle, so every screen that shows a version number
// (Settings' About row, the new Acknowledgements screen) reads it the exact
// same way instead of two copies of the same three lines quietly drifting.
//
// DETAILED: #190 (Apple Phase 1 — help/legal/acknowledgements). Extracted
// from `SettingsView.swift`'s own pre-existing private `appVersion`
// computed property the moment a SECOND screen (`AcknowledgementsView`)
// needed the identical value — the repo's modularity rule ("when a piece of
// UI, logic, or data exists in more than one place, extract it into a
// shared module") applies here exactly as it does everywhere else in this
// codebase. Kept in `IHAppSupport` (no dependency beyond `Foundation`)
// rather than `IHFeatures` so it stays trivially reusable/testable without
// pulling in any view-model machinery.
import Foundation

/// Reads the installed app's marketing version + build number straight from
/// its own `Info.plist` — never a hard-coded string that could drift from
/// `Config/Versioning.xcconfig`'s `MARKETING_VERSION`/`CURRENT_PROJECT_VERSION`.
///
/// ELI5: "What version of the app is this, really?"
public enum IHAppVersion {
    /// e.g. `"0.1.0 (1720000000)"`. An em dash for either half if it's
    /// somehow absent (a preview/host context with no real `Info.plist`),
    /// matching the "defensive, never force-unwrap a bundle value" style
    /// this package already uses elsewhere (`CanonicalURL`'s own header).
    public static var marketingAndBuild: String {
        let info = Bundle.main.infoDictionary
        let marketing = info?["CFBundleShortVersionString"] as? String ?? "—"
        let build = info?["CFBundleVersion"] as? String ?? "—"
        return "\(marketing) (\(build))"
    }
}

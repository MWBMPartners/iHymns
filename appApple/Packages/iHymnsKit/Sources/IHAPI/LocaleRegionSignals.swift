// LocaleRegionSignals.swift
// IHAPI
//
// ELI5: Works out two little hints — "is this device roughly in the
// Northern or Southern half of the world?" and "what country's App Store
// region is it set to?" — using ONLY settings already on the device
// (Language & Region), never the device's actual location. Those two hints
// are what `?action=song_of_the_day` (#183) uses to pick the right season
// for a harvest-festival hymn, or fire a national civic-day theme on the
// right country's calendar.
//
// DETAILED: Implements this task's explicit brief: "Resolve hemisphere +
// country client-side from Locale/TimeZone (privacy-safe, no GeoIP) with a
// sane default." `SongOfTheDay.php`'s `$hemisphere`/`$country` parameters
// (#1374/#1376, `appWeb/public_html/includes/SongOfTheDay.php`) are exactly
// this signal server-side already — the web PWA presumably derives the
// same two values from the BROWSER's own `Intl`/`navigator.language`, so
// this file is the native mirror of that same client-side resolution, not
// a new capability the server doesn't already expect.
//
// PRIVACY: deliberately does NOT request `CLLocationManager`/any location
// permission, and does NOT call a GeoIP service — both would need a
// permission prompt (location) or a network round-trip that leaks the
// user's IP to a third party (GeoIP) for a feature that only needs a rough
// seasonal/civic signal. `Locale.current.region` is metadata the user
// already configured in Settings → General → Language & Region — reading
// it needs no extra permission and touches no network.
//
// Both resolvers are pure functions of an injected `Locale` (not a bare
// `Locale.current` read baked into the body) so they're unit-testable
// without touching real device settings — see `LocaleRegionSignalsTests.swift`.
import Foundation

/// Client-side, privacy-safe hemisphere + country resolution for
/// `?action=song_of_the_day`'s `hemisphere`/`country` query parameters.
///
/// ELI5: "Roughly where in the world is this phone set up for?" — without
/// ever asking for its actual location.
public enum LocaleRegionSignals {

    /// ISO-3166-1 alpha-2 codes for countries/territories whose
    /// POPULATION CENTRE (not just landmass) lies in the Southern
    /// Hemisphere — the same "Harvest fires in the right hemisphere's own
    /// autumn" signal `SongOfTheDay.php`'s `$hemisphere` gates (#1374).
    ///
    /// DETAILED: Deliberately a small, explicit allow-list rather than a
    /// computed geographic lookup — Swift's `TimeZone` exposes no
    /// latitude/longitude, so there is no API-driven way to derive this.
    /// Covers every country whose majority-populated area sits south of
    /// the equator; countries that straddle the equator (Brazil,
    /// Indonesia, Kenya, Ecuador, Colombia, …) are deliberately OFF this
    /// list because their population centres sit north of it — this is a
    /// "sane default" per the task brief, not a precise geodetic
    /// classification, and errs toward the server's own default (`'n'`)
    /// for any country not confidently Southern.
    static let southernHemisphereRegions: Set<String> = [
        "AR", "AU", "BO", "BW", "CL", "FJ", "LS", "MG", "MW", "MZ",
        "NA", "NZ", "PG", "PY", "PE", "SB", "ZA", "SZ", "TO", "UY",
        "VU", "ZM", "ZW", "TL", "WS", "NC", "PF", "CK"
    ]

    /// Resolves the `hemisphere` query value (`"n"` | `"s"`) from a
    /// `Locale`'s region.
    ///
    /// - Parameter locale: The `Locale` to resolve from — `.current` for
    ///   the real app; tests inject a fixed `Locale(identifier:)`.
    /// - Returns: `"s"` for a known Southern-Hemisphere region, `"n"`
    ///   otherwise — matching `SongOfTheDay.php`'s own default for an
    ///   absent/invalid value (#1374), so an unresolvable region is a
    ///   verified no-op rather than a guess.
    public static func hemisphere(for locale: Locale = .current) -> String {
        guard let region = regionCode(for: locale), southernHemisphereRegions.contains(region) else {
            return "n"
        }
        return "s"
    }

    /// Resolves the `country` query value (an ISO-3166-1 alpha-2 code, or
    /// `""` for "no signal") from a `Locale`'s region.
    ///
    /// - Parameter locale: The `Locale` to resolve from — same injection
    ///   seam as `hemisphere(for:)`.
    /// - Returns: The 2-letter region code, upper-cased to match
    ///   `SongOfTheDay.php`'s `strtoupper()` validation, or `""` when the
    ///   device has no resolvable region — `Endpoint.songOfTheDay`
    ///   correctly omits the query parameter entirely in that case, the
    ///   same "an absent country is a verified no-op" behaviour as an
    ///   unthemed country (#1376).
    public static func country(for locale: Locale = .current) -> String {
        regionCode(for: locale) ?? ""
    }

    /// The shared region-code lookup both signals above derive from — ONE
    /// piece of client state, so `hemisphere(for:)`/`country(for:)` can
    /// never disagree about which country they resolved.
    ///
    /// DETAILED: `Locale.Region` (the modern, non-deprecated replacement
    /// for the old `regionCode: String?` property — see
    /// https://developer.apple.com/documentation/foundation/locale/region)
    /// already validates to a 2-letter (or occasionally 3-digit UN M49)
    /// code; the `count == 2` guard excludes the rarer numeric form, since
    /// `SongOfTheDay.php`'s own validator only accepts
    /// `^[A-Za-z]{2}$` (#1376).
    private static func regionCode(for locale: Locale) -> String? {
        guard let region = locale.region?.identifier, region.count == 2 else { return nil }
        return region.uppercased()
    }
}

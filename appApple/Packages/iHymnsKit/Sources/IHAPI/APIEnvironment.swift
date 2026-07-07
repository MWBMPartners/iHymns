// APIEnvironment.swift
// IHAPI
//
// ELI5: Tells the app which "copy" of the iHymns website to talk to —
// the developer's test copy, the beta-tester copy, or the real live site —
// without ever hard-coding a URL inside a network call.
//
// DETAILED: Mirrors the three-docroot topology documented across
// `.claude/CLAUDE.md` (e.g. rule #26's "the 3-docroot env discriminator")
// and strategy §1.5/§3.1's TestFlight/env mapping: Debug builds talk to
// `dev`, internal TestFlight talks to `beta`, and both External TestFlight
// and the App Store talk to `prod`. All three docroots share ONE MySQL
// database — the environment selects the *API code version* being hit, not
// a separate dataset — so picking the wrong environment here would show
// stale/pre-migration behaviour, not different data (this bit the web team
// once already — see `MEMORY.md`'s "s.SongbookName flood" root-cause note).
import Foundation

/// Which iHymns backend deployment a request should target.
///
/// ELI5: dev / beta / prod — pick one.
///
/// DETAILED: `Sendable` + `CaseIterable` (trivial value type) so it can be
/// stored on the `APIClient` actor's isolated state, read from a
/// `@MainActor` environment-picker Settings screen (TestFlight-only per
/// strategy §3.1, "compiled out of App Store"), and round-tripped through
/// tests — all without any locking, since Swift 6 proves an immutable enum
/// of this shape can never introduce a data race.
public enum APIEnvironment: String, Sendable, CaseIterable, Codable {
    case dev
    case beta
    case prod

    /// The environment the app shell targets when the user hasn't set an
    /// explicit override (`IHSettingsStore.apiEnvironmentOverride`).
    ///
    /// ELI5: "If nobody picked one, which site should we talk to?"
    ///
    /// DETAILED: The honest 2-way split a compile flag can actually express —
    /// `DEBUG` builds hit `dev`, everything else (Release, i.e. TestFlight +
    /// App Store) hits `prod`. Strategy §3.1's finer Debug→dev / Internal-TF
    /// →beta / External-TF+AppStore→prod mapping needs a RUNTIME TestFlight
    /// signal (the app-store receipt path) to distinguish TF from App Store,
    /// which a `#if` cannot; a beta tester who needs `beta` sets it via the
    /// Settings → Developer picker (compiled in only under `DEBUG`, see
    /// `SettingsView`), and the shell reads that override first regardless.
    public static var defaultForBuild: APIEnvironment {
        #if DEBUG
        .dev
        #else
        .prod
        #endif
    }

    /// A human-readable label for the Settings → Developer environment
    /// picker (`SettingsView`, #182).
    public var displayName: String {
        switch self {
        case .dev: "Development"
        case .beta: "Beta"
        case .prod: "Production"
        }
    }

    /// The base URL every `?action=` request is built against for this
    /// environment.
    ///
    /// ELI5: The web address to send requests to.
    ///
    /// DETAILED: Placeholder hostnames for the Phase-0 skeleton — Phase 1
    /// (strategy §3.4, "IHAPI actor — Bearer/X-Requested-With/ETag/retry/env")
    /// wires these to the REAL dev/beta/prod hostnames once the contract
    /// fixtures (`appApple/Tests/Fixtures/`) exist to verify against. Kept
    /// as `URL` (not `String`) so a malformed hostname fails loudly at
    /// compile-adjacent time (force-unwrap here is intentional and safe:
    /// these are constant, developer-authored literals, never user input —
    /// the same category of force-unwrap Apple's own sample code uses for
    /// known-good static URLs).
    public var baseURL: URL {
        switch self {
        case .dev:
            // swiftlint:disable:next force_unwrapping
            URL(string: "https://dev.ihymns.app")!
        case .beta:
            // swiftlint:disable:next force_unwrapping
            URL(string: "https://beta.ihymns.app")!
        case .prod:
            // swiftlint:disable:next force_unwrapping
            URL(string: "https://ihymns.app")!
        }
    }
}

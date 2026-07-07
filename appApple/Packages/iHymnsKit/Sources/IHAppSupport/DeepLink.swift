// DeepLink.swift
// IHAppSupport
//
// ELI5: The list of "places inside the app" a shared link can point at —
// like "a specific song" — spelled out as real Swift values instead of
// loose strings, so the app can never accidentally navigate somewhere that
// doesn't exist.
//
// DETAILED: The `DeepLink` enum named in strategy §1.7 ("DeepLinkRouter
// (IHAppSupport): URL→DeepLink enum→per-shell navigation"). Phase 0 seeds
// the one case every other deep-link case will look like structurally —
// `.song(SongID)` — proving the IHAppSupport→IHModels dependency and the
// "typed route enum only" security posture strategy §3.2 requires
// ("Deep-link validation: host allowlist; typed route enum only; SongId
// shape-checked (#27) before fetch"). Phase 1 (strategy §3.4, "#186 sharing
// + deep links") grows this to the full set: `.songbook`, `.songbooksList`,
// `.person`, `.work`, `.setlist`, `.live`, `.service`.
import Foundation
import IHModels

/// A validated, typed destination inside the app, parsed from a Universal
/// Link or a custom-scheme fallback.
///
/// ELI5: "Go to this song" as a real, checked value — never a raw
/// unvalidated URL string threaded through the navigation code.
///
/// DETAILED: `Sendable` + `Equatable` so a parsed `DeepLink` can travel from
/// wherever URL-parsing happens (often a background context — e.g. a
/// `NSUserActivity`/scene-delegate callback) to the `@MainActor` navigation
/// layer, and so navigation-routing tests can assert exact equality.
public enum DeepLink: Sendable, Equatable {
    /// Navigate directly to a single song's detail screen.
    case song(SongID)
}

/// Parses incoming URLs (Universal Links, and eventually the app's own
/// custom-scheme fallback) into a validated `DeepLink`.
///
/// ELI5: Hands you a `DeepLink` if the URL really points somewhere real in
/// the app; hands you `nil` otherwise (so navigation code can never be
/// tricked into "going" somewhere invalid).
public enum DeepLinkRouter {
    /// The only hosts this router will ever resolve a `DeepLink` from.
    ///
    /// ELI5: The list of "real iHymns web addresses" — anything else is
    /// rejected outright.
    ///
    /// DETAILED: Strategy §3.2's "host allowlist" requirement, applied
    /// before any path parsing happens at all — matching the three-docroot
    /// topology (`dev`/`beta`/`www`/apex) documented in
    /// `.claude/apple-native-strategy.md` §1.7's Associated Domains list.
    /// This is intentionally a `Set` (not e.g. a `hasSuffix` check) so a
    /// malicious host like `ihymns.app.evil.example` can never slip past a
    /// careless suffix match.
    public static let allowedHosts: Set<String> = [
        "ihymns.app", "www.ihymns.app", "beta.ihymns.app", "dev.ihymns.app"
    ]

    /// Attempts to resolve `url` into a `DeepLink`.
    ///
    /// - Parameter url: A candidate Universal Link, e.g.
    ///   `https://ihymns.app/song/MP-1008`.
    /// - Returns: A validated `DeepLink`, or `nil` if the host isn't
    ///   allow-listed, the path shape isn't recognised, or the trailing
    ///   segment isn't a valid `SongID` (strategy §3.2: "SongId
    ///   shape-checked (#27) before fetch").
    public static func resolve(_ url: URL) -> DeepLink? {
        guard let host = url.host, allowedHosts.contains(host) else { return nil }

        let segments = url.pathComponents.filter { $0 != "/" }
        guard segments.count == 2, segments[0] == "song" else { return nil }

        guard let songID = SongID(rawValue: segments[1]) else { return nil }
        return .song(songID)
    }
}

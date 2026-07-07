// DiscoveryEndpoints.swift
// IHAPI
//
// ELI5: The recipe cards for "find songs connected to this one" — the
// `Discovery`/cross-book-counterpart reads a song's screen needs alongside
// `song_detail` itself — plus (#183) the "what's today's featured song?"
// read the Home screen leads with, which `api-docs.yaml` tags `[Discovery]`
// too.
//
// DETAILED: Split into its own file (rather than growing `CatalogEndpoints.swift`)
// because these reads are conceptually distinct from the plain catalogue
// fetches there — `api-docs.yaml` tags `song_links` `[Songs]` and both
// `related_songs`/`song_of_the_day` `[Discovery]` — and exist specifically
// to power a "what else should I look at?" surface (#180's song-display
// shelves, #183's Home hero card), not general catalogue browsing.
import Foundation
import IHModels

extension Endpoint {
    /// `?action=song_links&id=…` — every OTHER songbook's curated
    /// counterpart of this exact song (#807). Public, no auth required.
    ///
    /// - Parameter id: The song to find counterparts for.
    public static func songLinks(id: SongID) -> Endpoint {
        Endpoint(action: "song_links", queryItems: [("id", id.rawValue)])
    }

    /// `?action=related_songs&id=…` — songs matched server-side by shared
    /// tag, writer, composer, or songbook vicinity. Public, no auth
    /// required.
    ///
    /// - Parameter id: The source song.
    public static func relatedSongs(id: SongID) -> Endpoint {
        Endpoint(action: "related_songs", queryItems: [("id", id.rawValue)])
    }

    /// `?action=song_of_the_day&hemisphere=…&country=…` — today's featured
    /// song (#183). Public, no auth required.
    ///
    /// DETAILED: `hemisphere`/`country` are undocumented in
    /// `api-docs.yaml`'s `songOfTheDay` operation (only `lang`/`date` are
    /// listed there) but ARE real, live-verified query parameters —
    /// `appWeb/public_html/api.php`'s `song_of_the_day` case reads both
    /// straight from `$_GET` (#1374/#1376) and `SongOfTheDay.php` uses them
    /// to gate the Harvest/national-civic-day themes. `hemisphere` is
    /// always sent (the server's own default for an absent value is `'n'`,
    /// so sending it explicitly is harmless and unambiguous either way);
    /// `country` is omitted entirely when unresolved — matching
    /// `LocaleRegionSignals.country(for:)`'s `""` "no signal" contract, and
    /// mirroring `songDetail(id:include:)`'s existing "only append when
    /// non-empty" convention for an optional parameter.
    ///
    /// - Parameters:
    ///   - hemisphere: `"n"` or `"s"` — see `LocaleRegionSignals.hemisphere(for:)`.
    ///   - country: An ISO-3166-1 alpha-2 code, or `""` for "unknown" — see
    ///     `LocaleRegionSignals.country(for:)`.
    public static func songOfTheDay(hemisphere: String, country: String) -> Endpoint {
        var items: [(name: String, value: String)] = [("hemisphere", hemisphere)]
        if !country.isEmpty {
            items.append(("country", country))
        }
        return Endpoint(action: "song_of_the_day", queryItems: items)
    }
}

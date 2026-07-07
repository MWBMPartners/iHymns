// CatalogEndpoints.swift
// IHAPI
//
// ELI5: The three named "recipe cards" (see `Endpoint.swift`) for the
// catalogue reads this Phase-0/#1397 slice actually implements — the song
// list, one full song, and the songbook list.
//
// DETAILED: The start of the "typed endpoint catalogue grouped Catalog/
// User/Auth/Live/Bundle" strategy §1.5 describes — this file is the
// `Catalog` group's first three entries. Kept as simple static/factory
// members on `Endpoint` (not a separate enum or protocol) so call sites
// read as `Endpoint.songsIndex` / `Endpoint.songDetail(id: someID)` /
// `Endpoint.songbooks` — self-documenting, and matching how `Endpoint`
// itself is already just a plain value type, not a class hierarchy.
import Foundation
import IHModels

extension Endpoint {
    /// `?action=songs_index` — the full slim catalogue index. Public, no
    /// auth required.
    public static let songsIndex = Endpoint(action: "songs_index")

    /// `?action=song_detail&id=…` — one full song record. Public, no auth
    /// required.
    ///
    /// - Parameters:
    ///   - id: The song to fetch, e.g. `SongID(rawValue: "MP-1008")`.
    ///   - include: Opt-in enrichment block names (#1099 — see
    ///     `SongLineEnrichment.swift`'s header), e.g.
    ///     `["translations", "annotations", "royaltyIds"]`. Defaults to
    ///     empty — every #1397/#1399 call site that predates this parameter
    ///     keeps building the exact same bodyless-of-extras request it
    ///     always did. Unknown block names are silently ignored server-side
    ///     (`SongData::songDetailIncludeBlocks()`'s allow-list), so passing
    ///     a name this server version doesn't yet recognise is harmless.
    public static func songDetail(id: SongID, include: [String] = []) -> Endpoint {
        var items: [(name: String, value: String)] = [("id", id.rawValue)]
        if !include.isEmpty {
            items.append(("include", include.joined(separator: ",")))
        }
        return Endpoint(action: "song_detail", queryItems: items)
    }

    /// `?action=songbooks` — every songbook in the catalogue. Public, no
    /// auth required.
    public static let songbooks = Endpoint(action: "songbooks")
}

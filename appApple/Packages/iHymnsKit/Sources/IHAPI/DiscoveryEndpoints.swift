// DiscoveryEndpoints.swift
// IHAPI
//
// ELI5: The recipe cards for "find songs connected to this one" — the
// `Discovery`/cross-book-counterpart reads a song's screen needs alongside
// `song_detail` itself.
//
// DETAILED: Split into its own file (rather than growing `CatalogEndpoints.swift`)
// because these two reads are conceptually distinct from the plain catalogue
// fetches there — `api-docs.yaml` tags `song_links` `[Songs]` and
// `related_songs` `[Discovery]`, and both exist specifically to power a
// song's "other versions of this hymn" / "you might also like" shelves
// (#180's Apple P1 song-display screen), not general catalogue browsing.
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
}

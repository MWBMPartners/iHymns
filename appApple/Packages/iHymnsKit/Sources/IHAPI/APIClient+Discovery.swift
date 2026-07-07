// APIClient+Discovery.swift
// IHAPI
//
// ELI5: The "who else is connected to this song?" phone calls, plus (#183)
// "what's today's featured song?" — layered on top of the SAME `APIClient`
// actor every catalogue read already uses, just split into their own file.
//
// DETAILED: Split out of `APIClient.swift` (already at/near the repo's
// 400-line LOC-budget tripwire, `Scripts/loc-budget.sh`) the same way
// `APIClient+Auth.swift` (#1398) was — a same-target file split, enabled by
// `performIdempotentGET`/`performOnce` already being at least `internal`
// visibility. Every read below is an idempotent public GET, so — unlike
// `auth_login`/`auth_logout` — they go through the SAME retrying
// `performIdempotentGET` every catalogue read uses, not the non-retrying
// `performOnce`.
import Foundation
import IHModels

extension APIClient {
    /// `?action=song_links&id=…` — every other songbook's curated
    /// counterpart of this exact song (#807).
    ///
    /// ELI5: "Does this exact hymn also appear, under a different id, in
    /// some other songbook?"
    public func songLinks(id: SongID) async throws -> SongLinkGroup {
        let data = try await performIdempotentGET(.songLinks(id: id))
        return try Self.decodeSongLinks(from: data)
    }

    /// `?action=related_songs&id=…` — songs matched server-side by shared
    /// tag, writer, composer, or songbook vicinity.
    ///
    /// ELI5: "What else might I like, based on this song?"
    public func relatedSongs(id: SongID) async throws -> [RelatedSongSummary] {
        let data = try await performIdempotentGET(.relatedSongs(id: id))
        return try Self.decodeRelatedSongs(from: data)
    }

    /// `?action=song_of_the_day&hemisphere=…&country=…` — today's featured
    /// song (#183). `hemisphere`/`country` are plain strings here — this
    /// method does no `Locale`/`TimeZone` resolution of its own (that's
    /// `LocaleRegionSignals`' job, called by whichever view model invokes
    /// this, e.g. `IHFeatures.AppRootViewModel.songOfTheDay()`) — mirroring
    /// how every other `APIClient` method takes already-resolved values
    /// rather than reaching into global device state itself.
    ///
    /// ELI5: "What's today's featured song, for someone in roughly this
    /// part of the world?"
    ///
    /// - Parameters:
    ///   - hemisphere: `"n"` or `"s"` (see `Endpoint.songOfTheDay`'s doc
    ///     comment for the full contract).
    ///   - country: An ISO-3166-1 alpha-2 code, or `""` for "unknown."
    public func songOfTheDay(hemisphere: String, country: String) async throws -> SongOfTheDay {
        let data = try await performIdempotentGET(.songOfTheDay(hemisphere: hemisphere, country: country))
        return try Self.decodeSongOfTheDay(from: data)
    }
}

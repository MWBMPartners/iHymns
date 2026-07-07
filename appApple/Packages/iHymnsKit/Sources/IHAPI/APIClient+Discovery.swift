// APIClient+Discovery.swift
// IHAPI
//
// ELI5: The two "who else is connected to this song?" phone calls — layered
// on top of the SAME `APIClient` actor every catalogue read already uses,
// just split into their own file.
//
// DETAILED: Split out of `APIClient.swift` (already at/near the repo's
// 400-line LOC-budget tripwire, `Scripts/loc-budget.sh`) the same way
// `APIClient+Auth.swift` (#1398) was — a same-target file split, enabled by
// `performIdempotentGET`/`performOnce` already being at least `internal`
// visibility. Both reads below are idempotent public GETs, so — unlike
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
}

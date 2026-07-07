// APIClient+Search.swift
// IHAPI
//
// ELI5: The two "search the server" phone calls — layered on top of the
// SAME `APIClient` actor every catalogue read already uses, just split into
// their own file (the same same-target-file-split pattern
// `APIClient+Auth.swift`/`APIClient+Discovery.swift` already established).
//
// DETAILED: #1436's "wired for completeness/tests" server search — NOT the
// native app's primary search UX (that's the local, offline `songs_index`
// -backed filter in `AppRootViewModel.filteredSongs`). Both reads below are
// idempotent public GETs, so they go through the SAME retrying
// `performIdempotentGET` every other catalogue read uses.
import Foundation
import IHModels

extension APIClient {
    /// `?action=search&q=…` — live full-text search across titles/lyrics/
    /// writers/composers.
    ///
    /// ELI5: "Ask the server to search its whole corpus for this text."
    public func search(
        query: String,
        songbookAbbreviation: String? = nil,
        limit: Int? = nil,
        offset: Int? = nil
    ) async throws -> [SongSummary] {
        let data = try await performIdempotentGET(
            .search(query: query, songbookAbbreviation: songbookAbbreviation, limit: limit, offset: offset)
        )
        return try Self.decodeSearchResults(from: data)
    }

    /// `?action=search_num&songbook=…&number=…` — exact by-number lookup
    /// within one songbook.
    ///
    /// ELI5: "Ask the server for song number N in this exact book."
    public func searchByNumber(songbookAbbreviation: String, number: Int) async throws -> [SongSummary] {
        let data = try await performIdempotentGET(.searchByNumber(songbookAbbreviation: songbookAbbreviation, number: number))
        return try Self.decodeSearchResults(from: data)
    }
}

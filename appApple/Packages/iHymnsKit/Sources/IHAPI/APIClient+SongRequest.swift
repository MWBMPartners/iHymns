// APIClient+SongRequest.swift
// IHAPI
//
// ELI5: The "here's a song we're missing, please add it" phone call —
// layered on top of the SAME `APIClient` actor every other read/write
// already uses, just split into its own file.
//
// DETAILED: #1447. `submitSongRequest(...)` is a non-idempotent POST — it
// goes through the non-retrying `performOnce`, the SAME "never auto-retry a
// POST" rule `authLogin`/`authLogout` already follow (retrying a submission
// risks the server recording it twice). Sibling to
// `APIClient+WorkAndCreditPerson.swift` — kept separate since this is a
// genuinely different concern (a community submission, not a catalogue
// read).
import Foundation
import IHModels

extension APIClient {
    /// `?action=song_request` — submit a "we don't have this song yet"
    /// request (#280, #1447). NEVER auto-retried (see this file's header).
    ///
    /// ELI5: "Here's a song we're missing — please add it."
    public func submitSongRequest(
        title: String,
        songbook: String = "",
        songNumber: String = "",
        language: String = "en",
        details: String = "",
        contactEmail: String = ""
    ) async throws -> SongRequestResult {
        let endpoint = try Endpoint.songRequest(
            title: title,
            songbook: songbook,
            songNumber: songNumber,
            language: language,
            details: details,
            contactEmail: contactEmail
        )
        let data = try await performOnce(endpoint)
        return try Self.decodeSongRequestResult(from: data)
    }
}

// AppRootViewModel+SongRequest.swift
// IHFeatures
//
// ELI5: The "please add this song we don't have" phone call — one screen
// (`SongRequestView`) reaches it through `AppRootViewModel` rather than
// holding its own `APIClient`, exactly like every other network call in
// this app.
//
// DETAILED: #1447 — the missing-song request form. A NEW, small extension
// file (rather than folding into `AppRootViewModel+Catalog.swift`) because
// `?action=song_request` is thematically a community SUBMISSION, not a
// catalogue READ — mirrors `AppRootViewModel+Setlists.swift`'s own "group
// pass-throughs by concern, not by HTTP verb" precedent.
import IHAPI
import IHModels

extension AppRootViewModel {
    /// Submits a "we don't have this song yet" request (#280, #1447) — a
    /// thin pass-through to `APIClient.submitSongRequest(...)`, same
    /// pattern as every other method in this file's sibling extensions.
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
        try await apiClient.submitSongRequest(
            title: title,
            songbook: songbook,
            songNumber: songNumber,
            language: language,
            details: details,
            contactEmail: contactEmail
        )
    }
}

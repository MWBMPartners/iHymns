// ViewHistoryEndpoints.swift
// IHAPI
//
// ELI5: The recipe cards for "tell the server I opened this song", "what have
// I looked at recently?" and "which songs do I open most?".
//
// DETAILED: PR 3b step I3 (#2180). Verified against the live `api.php` cases
// (read 2026-11-24):
//   - `song_view` (POST, body `{song_id, platform}`): records one view. The
//     server accepts it without a sign-in too, but this app only sends it
//     WHEN SIGNED IN, with the bearer token, so the view lands in that
//     person's own history (see `AppRootViewModel+ViewHistory.swift` for why
//     anonymous views are not sent). `platform: "apple"` lets the server tell
//     where a view came from (`SONG_HISTORY_PLATFORMS`). A 429 (too many
//     views in a short time) is simply ignored by the caller: a view is
//     fire-and-forget.
//   - `song_history` (GET): the person's recently viewed songs, newest first,
//     at most 50.
//   - `my_most_viewed` (GET, optional `period`): the person's most viewed
//     songs. Answers 409 `profile_sync_disabled` while the site has the new
//     account behaviour switched off; the endpoint captures the reason word
//     so the app can treat that as "not available here", not a failure.
import Foundation
import IHModels

extension Endpoint {
    /// `?action=song_view`: record that the signed-in person opened `songId`.
    ///
    /// - Throws: Only if `JSONEncoder` fails (practically unreachable).
    static func songView(songId: SongID) throws -> Endpoint {
        let body = try JSONEncoder().encode(SongViewRequestBody(songId: songId.rawValue, platform: "apple"))
        return Endpoint(action: "song_view", requiresAuth: true, httpMethod: "POST", httpBody: body)
    }

    /// `?action=song_history`: the person's recently viewed songs.
    static let songHistory = Endpoint(action: "song_history", requiresAuth: true)

    /// `?action=my_most_viewed`: the person's most viewed songs, for `period`
    /// (or the person's saved choice when `nil`).
    static func myMostViewed(period: MostViewedPeriod? = nil) -> Endpoint {
        Endpoint(
            action: "my_most_viewed",
            queryItems: period.map { [(name: "period", value: $0.rawValue)] } ?? [],
            requiresAuth: true,
            capturesRefusalReason: true
        )
    }
}

/// `song_view`'s JSON body. The server reads `song_id` and `platform`.
private struct SongViewRequestBody: Encodable {
    let songId: String
    let platform: String

    private enum CodingKeys: String, CodingKey {
        case songId = "song_id"
        case platform
    }
}

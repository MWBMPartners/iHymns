// SongRequestEndpoint.swift
// IHAPI
//
// ELI5: The recipe card for "submit a missing-song request" — the one new
// server call #1447 needs.
//
// DETAILED: `?action=song_request` is an ALREADY-SHIPPED, working endpoint
// this task's backend survey discovered (#280, `appWeb/public_html/api.php`'s
// `case 'song_request':`) — no backend change was needed for it, only the
// native client-side wiring. Kept in its own file rather than folded into
// `WorkAndCreditPersonEndpoints.swift` (which landed in the same task but is
// a genuinely different concern — a community submission, not a catalogue
// read) so each stays independently reviewable/revertable per its own issue.
import Foundation

/// The exact wire shape `song_request`'s JSON body expects — a private
/// implementation detail of `Endpoint.songRequest(...)` below, never
/// constructed anywhere else. Field names/order verified against the LIVE
/// `api.php` `case 'song_request':` handler (2026-07-08): `title` is the
/// only required field; every other key defaults server-side when absent
/// (`mb_substr(trim($body['…'] ?? ''), 0, …)`), so this client still sends
/// every key explicitly (an empty string, never an omitted key) for
/// predictable behaviour rather than relying on the server's defaults.
private struct SongRequestBody: Encodable {
    let title: String
    let songbook: String
    let songNumber: String
    let language: String
    let details: String
    let contactEmail: String

    enum CodingKeys: String, CodingKey {
        case title, songbook
        case songNumber = "song_number"
        case language, details
        case contactEmail = "contact_email"
    }
}

extension Endpoint {
    /// `?action=song_request` — POST a "we don't have this song yet"
    /// submission (#280, #1447). Public, no auth required — the server
    /// attaches the CURRENT user's id server-side (`getAuthenticatedUser()`)
    /// when a bearer token happens to be present, but never requires one.
    ///
    /// ELI5: "Here's a song we're missing — please add it."
    ///
    /// - Parameters:
    ///   - title: The song's title — the ONLY field the server requires
    ///     non-empty (`api.php`: `"Song title is required."` on a 400).
    ///   - songbook: Which hymnal the user believes it belongs in, if known.
    ///   - songNumber: The number in that hymnal, if known.
    ///   - language: BCP-47-ish tag; defaults to `"en"` server-side too.
    ///   - details: Free-text notes (first line, tune name, anything that
    ///     helps a curator find/add it).
    ///   - contactEmail: Optional — lets a curator follow up. The server
    ///     validates this with `FILTER_VALIDATE_EMAIL` when non-empty (a
    ///     malformed address the caller already typed 400s, same as an
    ///     empty title).
    /// - Throws: Only if `JSONEncoder` itself somehow fails encoding six
    ///   plain `String` fields (practically unreachable, matching every
    ///   other POST factory's identical `throws` posture in this catalogue).
    public static func songRequest(
        title: String,
        songbook: String = "",
        songNumber: String = "",
        language: String = "en",
        details: String = "",
        contactEmail: String = ""
    ) throws -> Endpoint {
        let body = try JSONEncoder().encode(SongRequestBody(
            title: title,
            songbook: songbook,
            songNumber: songNumber,
            language: language,
            details: details,
            contactEmail: contactEmail
        ))
        return Endpoint(action: "song_request", httpMethod: "POST", httpBody: body)
    }
}

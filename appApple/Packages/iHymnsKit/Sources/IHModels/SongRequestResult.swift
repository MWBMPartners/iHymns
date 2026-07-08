// SongRequestResult.swift
// IHModels
//
// ELI5: What the server hands back after you submit "please add this song
// we don't have" — just a confirmation and a tracking number.
//
// DETAILED: #1447 — the missing-song request form. `?action=song_request`
// (`appWeb/public_html/api.php`, verified in the LIVE source — an already-
// shipped, working endpoint this task discovered during its backend survey,
// not something this task added) responds `{"ok": true, "id": 123}` on a
// 201, per that case's own `sendJson(['ok' => true, 'id' => $requestId],
// 201)` call. This DTO is that response's exact shape — nothing more (no
// echo of the submitted fields; the server doesn't send them back).
import Foundation

/// `?action=song_request`'s success response.
///
/// ELI5: "Got it — here's your request's tracking number."
public struct SongRequestResult: Sendable, Hashable, Codable {
    /// Always `true` on a successful (2xx) response — a failed submission
    /// throws an `APIError` instead of ever producing a `SongRequestResult`
    /// with `wasAccepted == false`, so this is mostly documentary; kept
    /// because the server genuinely sends the key. Wire key `"ok"` — the
    /// Swift property is spelled out (`identifier_name`'s 3-character
    /// minimum bans a bare `ok`), same "wire name and Swift name can differ
    /// once `CodingKeys` exists" convention `SongSummary.songId`'s own doc
    /// comment establishes.
    public let wasAccepted: Bool

    /// The new `tblSongRequests.Id` — not currently surfaced anywhere in the
    /// UI (there's no "my requests" screen yet), but kept on the model so a
    /// future confirmation screen ("Request #123 submitted") doesn't need a
    /// second round-trip or a DTO change.
    public let id: Int

    public init(wasAccepted: Bool, id: Int) {
        self.wasAccepted = wasAccepted
        self.id = id
    }

    private enum CodingKeys: String, CodingKey {
        case wasAccepted = "ok"
        case id
    }
}

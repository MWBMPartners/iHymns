// SetlistSyncResult.swift
// IHModels
//
// ELI5: What the server tells us after we sync our setlists: the full, true
// list, a "last synced" time stamp to hand back next time, the setlists that
// were deleted somewhere else, and any of our edits it turned down because
// the same setlist had changed elsewhere in the meantime.
//
// DETAILED: Set-list sync "protocol 2" (#1661, #1675). Verified against the
// `sendJson([...])` call at the end of `api.php`'s `case 'user_setlists_sync'`
// (read 2026-11-24): `setlists`, `syncedAt`, `truncated`, `cap` (always null),
// `tombstones` (`[{id, deletedAt, reason}]`) and `conflicts` (`[{id, reason,
// serverUpdatedAt}]`). Everything except `setlists` is optional here on
// purpose: an older server, or the read-only answer a paused site gives
// (`syncedAt: null`), must still decode instead of failing the whole sync.
// The time stamps stay plain strings for the same reason `Setlist.createdAt`
// does (see `Setlist.swift`): the server's clock text is handed back to it
// untouched, never parsed.
import Foundation

/// A set list that was deleted (by this person, on some device) and must stay
/// deleted — the server remembers it so a device holding an old copy cannot
/// bring it back.
///
/// ELI5: "This one is gone for good — drop your copy."
public struct SetlistTombstone: Sendable, Hashable, Decodable {
    /// The deleted setlist's id.
    public let id: String
    /// When the server recorded the deletion (the server's clock text).
    public let deletedAt: String?
    /// Why: `"user"` or an expiry word. Open text, never an enum, so a new
    /// reason word can never stop a sync from decoding.
    public let reason: String?

    public init(id: String, deletedAt: String? = nil, reason: String? = nil) {
        self.id = id
        self.deletedAt = deletedAt
        self.reason = reason
    }
}

/// One of our edits the server refused because that setlist was changed on
/// another device after we last synced. The server keeps its own copy, and
/// that copy is already in `SetlistSyncResult.setlists`.
///
/// ELI5: "I did not take your edit to this one — someone changed it first."
public struct SetlistSyncConflict: Sendable, Hashable, Decodable {
    public let id: String
    /// Today always `"newer_on_server"`. Open text for the same reason as
    /// `SetlistTombstone.reason`.
    public let reason: String?
    public let serverUpdatedAt: String?

    public init(id: String, reason: String? = nil, serverUpdatedAt: String? = nil) {
        self.id = id
        self.reason = reason
        self.serverUpdatedAt = serverUpdatedAt
    }
}

/// The whole answer to one `user_setlists_sync` call.
///
/// ELI5: "Here is the real list, the time to quote next time, what to drop,
/// and what I refused."
public struct SetlistSyncResult: Sendable, Equatable {
    /// The server's authoritative list after the sync.
    public let setlists: [Setlist]
    /// The server clock reading to send back as `since` on the next sync.
    /// `nil` when the server did not give one (a paused site answers read-only):
    /// then the caller must keep the reading it already had.
    public let syncedAt: String?
    /// Setlists deleted elsewhere that this device must forget.
    public let tombstones: [SetlistTombstone]
    /// Edits the server refused (see `SetlistSyncConflict`).
    public let conflicts: [SetlistSyncConflict]

    public init(
        setlists: [Setlist],
        syncedAt: String? = nil,
        tombstones: [SetlistTombstone] = [],
        conflicts: [SetlistSyncConflict] = []
    ) {
        self.setlists = setlists
        self.syncedAt = syncedAt
        self.tombstones = tombstones
        self.conflicts = conflicts
    }
}

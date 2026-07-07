// APIClient+Setlists.swift
// IHAPI
//
// ELI5: The four "phone calls" for setlists — get them all, sync a batch,
// publish/refresh a share link, look up someone's shared link — layered on
// top of the SAME `APIClient` actor every other call uses (same reasoning
// `APIClient+Favorites.swift`'s own header gives for its own split-out
// file).
//
// DETAILED: `userSetlists()`/`sharedSetlist(shareId:)` are idempotent GETs,
// so they go through the retrying `performIdempotentGET` every catalogue
// read uses. `syncSetlists(_:mode:)`/`shareSetlist(_:existingShareId:)` are
// POSTs, so — matching `APIClient+Auth.swift`'s explicit "never auto-retry
// non-idempotent POSTs" policy (strategy §1.5) — they go through the
// non-retrying `performOnce` instead, exactly like `favoritesSync`/
// `favoritesRemove`. A caller wanting retry-on-failure semantics for a
// setlist mutation gets it from the offline pending-op queue
// (`IHFeatures/AppRootViewModel+Setlists.swift`), not from silently
// re-sending a POST this layer has no idempotency-key protection for.
import Foundation
import IHModels

extension APIClient {
    /// `?action=user_setlists` — every setlist the signed-in user owns.
    ///
    /// ELI5: "What are my setlists?"
    public func userSetlists() async throws -> [Setlist] {
        let data = try await performIdempotentGET(.userSetlists)
        return try Self.decodeSetlists(from: data)
    }

    /// `?action=user_setlists_sync` — reconciles `setlists` with the server
    /// under `mode`, returning the server's resulting authoritative list.
    ///
    /// ELI5: "Here's what my setlists look like on this device — reconcile
    /// that with the server and tell me the full, up-to-date result."
    public func syncSetlists(_ setlists: [Setlist], mode: SetlistSyncMode) async throws -> [Setlist] {
        let endpoint = try Endpoint.setlistsSync(mode: mode, setlists: setlists)
        let data = try await performOnce(endpoint)
        return try Self.decodeSetlists(from: data)
    }

    /// `?action=setlist_share` — publishes (or refreshes, if
    /// `existingShareId` is supplied) a public LIVE-linked share of
    /// `setlist` — see `Endpoint.shareSetlist(_:ownerId:existingShareId:)`'s
    /// own doc comment for the live-link mechanics.
    ///
    /// ELI5: "Make a public link for this setlist — one that keeps showing
    /// my latest edits, not a snapshot frozen at share time."
    ///
    /// DETAILED: Mints a FRESH `ownerId` (`UUID().uuidString`) on every call
    /// rather than persisting/reusing one — safe because this app only ever
    /// calls this endpoint authenticated (see `Endpoint.shareSetlist(...)`'s
    /// header): once the FIRST call establishes the live link (proven via
    /// the bearer token owning `setlist.id`, not the `owner` UUID),
    /// `api.php`'s `setlist_share` handler's update-ownership check pins on
    /// that SAME authenticated user for every future update — the legacy
    /// anonymous `owner` UUID becomes irrelevant the moment a live link
    /// exists, so a new random value each time changes nothing about who
    /// can update this share afterwards.
    public func shareSetlist(_ setlist: Setlist, existingShareId: String? = nil) async throws -> ShareSetlistResult {
        let endpoint = try Endpoint.shareSetlist(
            setlist,
            ownerId: UUID().uuidString.lowercased(),
            existingShareId: existingShareId
        )
        let data = try await performOnce(endpoint)
        return try Self.decodeShareSetlistResult(from: data)
    }

    /// `?action=setlist_get&id=…` — looks up a shared setlist by its public
    /// share id. Unauthenticated (see `Endpoint.sharedSetlist(shareId:)`'s
    /// header) — anyone holding the link can open it.
    ///
    /// ELI5: "Open this shared setlist link."
    public func sharedSetlist(shareId: String) async throws -> SharedSetlist {
        let data = try await performIdempotentGET(.sharedSetlist(shareId: shareId))
        return try Self.decodeSharedSetlist(from: data)
    }
}

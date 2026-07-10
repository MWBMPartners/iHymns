// APIError+UserFacing.swift
// IHFeatures
//
// ELI5: Turns "the network says 503" into an actual sentence a person can
// read on screen — one place that does this, so every loading screen shows
// the same wording for the same problem.
//
// DETAILED: Deliberately lives in IHFeatures (the UI-copy layer), NOT in
// IHAPI itself — `APIError` (IHAPI) is a pure, taxonomy-only error type
// with no opinion on user-facing wording (strategy §1.5's taxonomy doc
// comment is explicit that `.maintenance` "must never be treated like a
// generic failure"); this extension is where that opinion actually lives,
// consumed by both `AppRootViewModel.loadCatalogue()` and
// `SongDetailViewModel.load()` (#1399) so the two load paths never drift
// into slightly different copy for the same failure.
import IHAPI

extension APIError {
    /// A short, user-facing sentence describing this failure — suitable for
    /// direct display in an error state view (e.g. `ContentUnavailableView`).
    var userFacingMessage: String {
        switch self {
        case .offline:
            "You're offline. Check your connection and try again."
        case .maintenance:
            // The 503 "temporarily unavailable" copy this task calls out
            // explicitly — mirrors `includes/maintenance.php`'s own framing
            // of a 503 as a designed state, never an alarming generic error.
            "iHymns is temporarily unavailable. Please try again shortly."
        case .unauthorized:
            "Please sign in again to continue."
        case .accountLocked:
            "Too many failed attempts. Please wait a bit before trying again."
        case .rateLimited:
            "Too many requests — please wait a moment and try again."
        case .server(let status, _):
            "Something went wrong (error \(status)). Please try again."
        case .decoding:
            "iHymns sent something we didn't understand. Please try again later."
        }
    }

    /// The account-deletion-specific mapping (#1477, App Review §5.1.1(v))
    /// — `AccountDeleteView`'s destructive, re-auth-gated flow uses THIS
    /// instead of `userFacingMessage` above, because three cases need
    /// wording specific to that one screen:
    ///   - `.unauthorized` here means the RE-AUTH credential (the password
    ///     or email code just entered) was wrong or expired — NOT that the
    ///     session token itself is dead, which is what `userFacingMessage`'s
    ///     "please sign in again" copy assumes elsewhere. Telling the user
    ///     to re-authenticate right there on the same screen is the correct
    ///     recovery action, not a sign-in prompt.
    ///   - `.rateLimited` is `api.php`'s dedicated `account_delete` guard (10
    ///     attempts per 15 minutes) — worded plainly here rather than
    ///     `userFacingMessage`'s more general phrasing.
    ///   - A `.server(status: 409, _)` is the "you are the last remaining
    ///     Global Admin" guard `case 'account_delete'` enforces — `APIError`
    ///     has no dedicated enum case for it (new cases are added sparingly,
    ///     per that type's own header), so this is the one place that maps
    ///     the raw 409 status to specific copy. Every OTHER `.server` status
    ///     (400 malformed re-auth, 403 CSRF, 503 unavailable, ...) falls
    ///     through to the generic `userFacingMessage` copy.
    var accountDeleteUserFacingMessage: String {
        switch self {
        case .unauthorized:
            "Re-authentication failed. Please try again."
        case .rateLimited:
            "Too many attempts. Please try again later."
        case .server(let status, _) where status == 409:
            "Transfer Global Admin to another account first."
        default:
            userFacingMessage
        }
    }
}

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
}

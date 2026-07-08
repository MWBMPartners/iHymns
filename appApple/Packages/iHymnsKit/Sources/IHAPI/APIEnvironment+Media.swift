// APIEnvironment+Media.swift
// IHAPI
//
// ELI5: Turns "song 123's media file lives at `/song-media/123`" into the
// real, full web address for whichever server we're currently talking to.
//
// DETAILED: #184's dev-API survey found that `SongMediaAsset.streamUrl`
// (`IHModels/SongDetail.swift`) is a SERVER-RELATIVE path
// (`"/song-media/<id>"`, built by `SongData::_songMediaMap()` as
// `'/song-media/' . (int)$row['Id']`) — a dedicated Apache rewrite route
// (`song-media.php`, NOT the `?action=` dispatcher every other `Endpoint`
// goes through), so it can't be built via `Endpoint`/`APIClient
// .makeURLRequest`. It still needs resolving against the SAME
// dev/beta/prod host every other request in this environment uses (the
// three docroots share one MySQL but serve different API code versions —
// `APIEnvironment.swift`'s own header), so this lives right next to
// `baseURL` rather than being hand-rolled at each call site.
import Foundation

extension APIEnvironment {
    /// Resolves a media asset's server-relative `streamUrl` (e.g.
    /// `"/song-media/123"`) into an absolute, fetchable `URL` for this
    /// environment.
    ///
    /// ELI5: "Here's the short address the server gave us — turn it into
    /// the full web address."
    ///
    /// DETAILED: `URL(string:relativeTo:)` does the actual resolution — a
    /// deliberately boring choice over string concatenation (the same
    /// "never hand-build a URL by pasting strings together" discipline
    /// `APIClient.makeURLRequest`'s own doc comment already documents for
    /// query items) so a `streamUrl` that ever gained its own query string
    /// or an unexpected leading/trailing slash still resolves correctly.
    /// Returns `nil` only if `path` itself is a malformed URL component —
    /// in practice, always a clean `/song-media/<id>` string from a trusted
    /// server response, so `nil` should never actually occur; callers treat
    /// it as "nothing to play/download" rather than force-unwrapping, since
    /// a broken media link is not worth crashing the whole song screen over.
    public func mediaURL(forStreamPath path: String) -> URL? {
        URL(string: path, relativeTo: baseURL)?.absoluteURL
    }
}

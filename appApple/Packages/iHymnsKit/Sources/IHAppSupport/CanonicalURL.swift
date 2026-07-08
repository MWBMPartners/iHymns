// CanonicalURL.swift
// IHAppSupport
//
// ELI5: The ONE place that knows how to turn "this song" / "this songbook" /
// "this shared set list" into the real `https://ihymns.app/...` web address
// for it — so every "Share" button in the app builds that address the exact
// same way, instead of five different screens each hand-typing their own
// `"https://ihymns.app/song/\(id)"` string and one of them eventually
// drifting out of sync.
//
// DETAILED: #186 (Apple Phase 1, "Sharing & social") — this task's own
// explicit instruction: "using ONE shared URL-builder (extract it if it's
// currently duplicated across toolbar/setlist/media)." Before this file,
// `SongDetailView.shareURL` built its string inline
// (`URL(string: "https://ihymns.app/song/\(detail.songId.rawValue)")`,
// #180), and songbooks had no share affordance at all — both now go through
// here. `ShareSetlistResult.canonicalURL` (`IHModels/SharedSetlist.swift`)
// is DELIBERATELY left as its own separate computed property rather than
// routed through this type: it prepends the host onto a server-supplied
// RELATIVE PATH (`api.php`'s `setlist_share` response, e.g.
// `"/setlist/shared/1a2b3c4d"`), not a client-synthesized path — a genuinely
// different shape, and `IHModels` has no dependency on `IHAppSupport`
// anyway (`Package.swift`'s dependency graph is one-directional:
// `IHAppSupport → IHModels`, never the reverse), so it structurally
// couldn't call into this type even if the shapes matched.
//
// This is the deliberate, tested INVERSE of `DeepLinkRouter.resolve(_:)` —
// `CanonicalURLTests`' round-trip cases assert
// `DeepLinkRouter.resolve(CanonicalURL.song(id)!) == .song(id)` (and the
// songbook/setlist-share/work equivalents), so the two halves of "share a
// link, then open it back up" can never quietly drift apart.
import Foundation
import IHModels

/// Builds the canonical, absolute `https://ihymns.app/...` web URL for
/// every deep-linkable entity in the catalogue.
///
/// ELI5: "What's the real web address for this thing?" — always the same
/// answer, no matter which screen asks.
///
/// DETAILED: Every function returns `URL?` (never a force-unwrapped `URL`)
/// even though every accepted input shape is already validated elsewhere
/// (`SongID`'s own regex, `Songbook.id`'s rule-#27 alphanumeric charset,
/// `SharedSetlist.id`'s server-issued hex) — matching this package's
/// existing "defensive, `nil` on failure" convention for server/user-facing
/// strings (`SongDetailView.shareURL`, `ShareSetlistResult.canonicalURL`)
/// rather than introducing a new force-unwrap style just for this file.
/// Always targets the bare apex host `ihymns.app` — deliberately NOT
/// `APIEnvironment.baseURL` (which could be `dev.ihymns.app`/
/// `beta.ihymns.app` for a Debug/TestFlight build): a link a user SHARES is
/// always the one public, permanent address, regardless of which backend
/// the sharing device happens to be talking to (the three docroots share
/// one MySQL database — strategy §1.7/CLAUDE.md rule #26 — so the content
/// behind the link is the same regardless of which host serves it).
public enum CanonicalURL {
    private static let host = "https://ihymns.app"

    /// `https://ihymns.app/song/<id>` — what `SongDetailToolbarContent`'s
    /// `ShareLink` and the Handoff `NSUserActivity.webpageURL` both use.
    public static func song(_ id: SongID) -> URL? {
        URL(string: "\(host)/song/\(id.rawValue)")
    }

    /// `https://ihymns.app/songbook/<abbreviation>` — `abbreviation` should
    /// already be `Songbook.id` (rule #27's alphanumeric SongId-prefix
    /// charset), but this still goes through `URL(string:)` rather than
    /// string-interpolating blindly, matching every other builder here.
    public static func songbook(abbreviation: String) -> URL? {
        URL(string: "\(host)/songbook/\(abbreviation)")
    }

    /// `https://ihymns.app/songbooks` — the browse index, no id needed.
    public static var songbooksList: URL? {
        URL(string: "\(host)/songbooks")
    }

    /// `https://ihymns.app/setlist/shared/<id>` — mirrors
    /// `ShareSetlist.id`'s own doc comment on this exact path shape.
    /// `SharedSetlistView`'s own re-share `ShareLink` uses this (the
    /// OWNER's mint-a-new-link flow in `SetlistDetailView` instead uses
    /// `ShareSetlistResult.canonicalURL`, per this file's header).
    public static func setlistShare(id: String) -> URL? {
        URL(string: "\(host)/setlist/shared/\(id)")
    }

    /// `https://ihymns.app/work/<slug>` — built even though the app has no
    /// native Work-detail screen yet (`DeepLink.work`'s own doc comment):
    /// this is what `IHymnsApp.swift` hands to the SYSTEM BROWSER when a
    /// `/work/*` Universal Link arrives, so the fallback still opens the
    /// exact page the user tapped rather than some reconstructed guess.
    public static func work(slug: String) -> URL? {
        URL(string: "\(host)/work/\(slug)")
    }

    // MARK: - #190 (help / legal / first-run)

    /// `https://ihymns.app/help` — the SAME help/FAQ page the web app's
    /// footer and nav-dropdown link to (`appWeb/public_html/includes/pages
    /// /help.php`, served via `index.php`'s `/help` route). `HelpView`'s
    /// "More on the web" link uses this rather than inventing a
    /// `/support`/`/contact` URL — the web app has no separate support page,
    /// `/help` already fills that role.
    public static var help: URL? {
        URL(string: "\(host)/help")
    }

    /// `https://ihymns.app/privacy` — the real, already-shipping Privacy
    /// Policy (`includes/pages/privacy.php`). `LegalView` links out to this
    /// rather than bundling a native copy, so the native app can never show
    /// a STALE policy after the web one is updated.
    public static var privacyPolicy: URL? {
        URL(string: "\(host)/privacy")
    }

    /// `https://ihymns.app/terms` — the real, already-shipping Terms of Use
    /// (`includes/pages/terms.php`). Same "link out, never a stale bundled
    /// copy" reasoning as `privacyPolicy` above.
    public static var termsOfUse: URL? {
        URL(string: "\(host)/terms")
    }
}

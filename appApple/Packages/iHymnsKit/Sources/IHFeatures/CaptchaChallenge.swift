// CaptchaChallenge.swift
// IHFeatures
//
// ELI5: The "socket" a CAPTCHA-drawing widget plugs into. This file
// declares the SHAPE of that socket and a small lost-property office
// (the registry) that remembers which widget goes with which provider name
// — it does NOT draw anything itself.
//
// DETAILED: #947/#340 native scaffold
// (`.claude/captcha-native-and-outage-plan.md` §2.4). The seam this whole
// scaffold exists to leave: everything provider-INDEPENDENT (the
// `AppStatus`/`CaptchaConfig` models, the `app_status` read, the
// `.captchaRequired` error case, the `captcha_token` plumbing) is built
// without knowing which provider is chosen. `CaptchaChallengeProviding` is
// the ONE conformance point a provider-specific implementation fills in —
// `TurnstileCaptchaProvider.swift` (this app's one shipped conformance,
// owner decision "Provider = Cloudflare Turnstile") is the worked example.
//
// `@MainActor` on both the protocol and the registry: every conformance
// renders SwiftUI content (a WKWebView today; a future SDK-backed
// provider's own UI tomorrow) and none of that is safe to touch off the
// main thread — matching this whole package's `@MainActor` posture for
// anything UI-adjacent (`AppRootViewModel`'s own header).
import IHModels
import SwiftUI

/// ONE conformance per CAPTCHA provider family. See this file's header for
/// the full seam design.
///
/// ELI5: "Here's how to draw and read back the answer to ONE provider's
/// verification puzzle."
///
/// DETAILED: Carries NO provider hostnames/sitekeys/URLs of its own — every
/// literal a conformance needs (`scriptUrl`/`siteKey`/`renderGlobal`/
/// `field`) arrives in the `CaptchaConfig` `makeChallengeView` receives
/// (rule #35 — "the response IS the contract," mirrored from the reference
/// web client's own `js/modules/captcha-widget.js` header, which carries
/// "NO provider table, NO hostnames and NO site key" for the identical
/// reason). `AnyObject`-constrained (a `class` protocol, not a `struct`
/// one) because `TurnstileCaptchaProvider` below needs REFERENCE semantics
/// to hold a live weak link back to whichever `WKWebView` is currently on
/// screen for `reset()` to reach — see that type's own doc comment.
@MainActor
public protocol CaptchaChallengeProviding: AnyObject {
    /// The `captchaProviders()` registry key (`includes/captcha.php`) this
    /// conformance renders — e.g. `"turnstile"`. Matched against
    /// `CaptchaConfig.provider` by `CaptchaChallengeRegistry.provider(for:)`.
    var providerKey: String { get }

    /// Whether THIS build target can render a challenge for this provider
    /// AT ALL. `false` on tvOS/watchOS for every provider today (plan
    /// §2.6/D-N2: `WKWebView` doesn't exist on either platform, and no
    /// provider ships a native SDK for either) — a caller that sees `false`
    /// must not pretend a challenge can render; `makeChallengeView` itself
    /// already degrades to a clear, worded message on such a platform (see
    /// each conformance's own contract), so callers may simply always call
    /// `makeChallengeView` and let IT decide what to draw — this property
    /// exists for callers that want to know in advance (e.g. to skip
    /// reserving layout space for a widget that will never render).
    var isSupportedOnThisPlatform: Bool { get }

    /// A view hosting the challenge for `config`. Calls `onToken` exactly
    /// once per human solve; the CALLER (a login screen) stores that token
    /// and attaches it to its NEXT submit — this method itself never
    /// touches the app's own network layer, only the provider's widget
    /// script.
    ///
    /// ELI5: "Draw the puzzle, and tell me the token the moment someone
    /// solves it."
    func makeChallengeView(config: CaptchaConfig, onToken: @escaping (String) -> Void) -> AnyView

    /// Invalidates any solved state so the NEXT render hands back a FRESH
    /// token. Tokens are single-use — consumed at the provider's first
    /// `siteverify` call regardless of whether the request that carried it
    /// went on to succeed (`captcha.php`'s own doc-block) — so a retry
    /// after ANY failed submit (a `.captchaRequired` refusal, a wrong
    /// password, a transport error) must call this before the user tries
    /// again (plan §2.5).
    ///
    /// ELI5: "Forget the last answer — the next try needs a brand new one."
    func reset()
}

/// The string-keyed registry every conformance registers itself into.
/// Ships EMPTY of real providers by default (plan §2.4/§2.7): the scaffold
/// itself names no provider — `AppRootViewModel+Captcha.swift`'s
/// `loadAppStatus()` is what registers this app's ONE shipped conformance.
///
/// ELI5: A lost-property office: hand it a widget with its provider name on
/// it, and later ask for "the one named Turnstile" back.
@MainActor
public enum CaptchaChallengeRegistry {
    private static var providers: [String: any CaptchaChallengeProviding] = [:]

    /// Registers `provider` under its own `providerKey`, replacing any
    /// earlier registration for the same key. Idempotent — safe to call
    /// more than once (`loadAppStatus()` does, on every launch).
    public static func register(_ provider: any CaptchaChallengeProviding) {
        providers[provider.providerKey] = provider
    }

    /// The registered conformance for `key` (a `CaptchaConfig.provider`
    /// value), or `nil` if nothing has registered that key. An UNKNOWN
    /// provider string — one the server's registry knows about but this
    /// app has never shipped a conformance for — resolves here, never at
    /// decode time (`CaptchaConfig.provider`'s own doc comment: it's a
    /// plain `String`, never an `enum`, for exactly this reason).
    public static func provider(for key: String) -> (any CaptchaChallengeProviding)? {
        providers[key]
    }

    /// Test-only: clears every registration, so `IHFeaturesTests` can prove
    /// `register(_:)`/`provider(for:)` round-trip without leaking state
    /// between test cases.
    static func resetForTesting() {
        providers.removeAll()
    }
}

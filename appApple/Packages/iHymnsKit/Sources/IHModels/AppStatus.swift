// AppStatus.swift
// IHModels
//
// ELI5: What the app asks the server FIRST — "is anything special going on
// right now?" — before it even knows whether it needs to. Today the only
// thing this model reads is "is there a 'prove you're human' challenge I
// need to solve before signing in?"
//
// DETAILED: `?action=app_status` (`appWeb/public_html/api.php:~7195-7245`)
// is a big, growing bag of dormant/optional flags (`maintenance`, `motd`,
// `registrationMode`, `remoteFeatures`, …) — this package deliberately
// models ONLY the `captcha` key (rule #44, "collect nothing the app acts on
// yet"; every other key here today is either PWA-only or has no native
// consumer). `Decodable` silently ignores every key it doesn't declare a
// property for, so this narrow model is forward-compatible with the server
// growing new top-level flags with zero code change here.
//
// CAPTCHA (#947/#340 native scaffold, `.claude/captcha-native-and-outage-plan.md`
// §2.1-§2.2): the server's dormancy contract is that `captcha` is ABSENT
// from the JSON entirely — never `null` — when no provider is configured
// (`captchaClientConfig()` in `includes/captcha.php` returns `null`, and the
// emit site only sets the key when that's non-null,
// `api.php:7219-7224`: `if ($captchaClient !== null) { $captchaStatusPayload['captcha']
// = $captchaClient; }`). Modelling `captcha` as `CaptchaConfig?` — an
// OPTIONAL stored property — means `JSONDecoder` synthesizes an
// absent-key-decodes-to-nil path automatically
// (https://developer.apple.com/documentation/foundation/archives_and_serialization/encoding_and_decoding_custom_types#2903096):
// on every install that hasn't turned CAPTCHA on, this type decodes
// successfully with `captcha == nil` and NEVER throws — the load-bearing
// property the plan calls out explicitly ("the captcha key is ABSENT when
// dormant, so decoding must succeed with it missing, never throw").
import Foundation

/// The `?action=app_status` response — today, ONLY the dormant CAPTCHA
/// config this package cares about.
///
/// ELI5: "Is there anything I need to know about before I try to sign in?"
public struct AppStatus: Decodable, Sendable, Equatable {
    /// `nil` on every install that hasn't configured + enabled CAPTCHA for
    /// any form — see this file's header for why an ABSENT JSON key (not a
    /// JSON `null`) is what makes that decode to `nil` safely.
    public let captcha: CaptchaConfig?

    public init(captcha: CaptchaConfig? = nil) {
        self.captcha = captcha
    }
}

/// The non-secret facts a client needs to draw + submit a CAPTCHA
/// challenge — the exact shape `captchaClientConfig()` (`includes/captcha.php`)
/// emits, verified against that function's own literal return array:
/// `['provider'=>…, 'siteKey'=>…, 'scriptUrl'=>…, 'renderGlobal'=>…,
/// 'field'=>…, 'forms'=>…]`. Every JSON key here is ALREADY camelCase on
/// the wire (unlike most of this package's other DTOs, which map a
/// snake_case wire shape via `CodingKeys`), so this type needs no
/// `CodingKeys` enum at all — the synthesized `Decodable` conformance
/// matches the wire shape verbatim.
///
/// ELI5: Everything the app needs to DRAW the "prove you're human" puzzle —
/// which provider, which public key, where to load its script from — but
/// never the provider's SECRET key (that never leaves the server; rule #38's
/// "the secret never reaches a browser" custody rule applies identically
/// here).
public struct CaptchaConfig: Decodable, Sendable, Equatable {
    /// The `captchaProviders()` registry key (`includes/captcha.php`) —
    /// e.g. `"turnstile"`. Deliberately a plain `String`, NOT a Swift
    /// `enum`: a future provider the server's registry grows (or a
    /// reserved-but-not-yet-`selectable` one) must still decode this whole
    /// struct successfully — an `enum` would throw `.decoding` on any value
    /// it doesn't already know about, which is exactly the "one bad row
    /// sinks the whole read" failure mode this package avoids elsewhere
    /// (`AuthUser.role`'s own doc comment makes the identical call). The
    /// native seam resolves an UNKNOWN provider string to "no conformance
    /// registered" at `CaptchaChallengeRegistry.provider(for:)` — a plain
    /// `nil`, never a decode failure.
    public let provider: String

    /// The provider's PUBLIC site key — safe to ship to a client (the
    /// server's own doc-block on `CAPTCHA_SETTING_SITE_KEY` says so
    /// explicitly); never the secret.
    public let siteKey: String

    /// The widget's `<script src>` URL, e.g.
    /// `https://challenges.cloudflare.com/turnstile/v0/api.js` for
    /// Turnstile — a conformance loads exactly this URL, never a
    /// hand-typed provider hostname of its own (this is the ONE thing that
    /// keeps a `CaptchaChallengeProviding` conformance provider-config-driven
    /// rather than baking a vendor's domain into Swift source).
    public let scriptUrl: String

    /// The JS global the widget's script installs (`window.<renderGlobal>`,
    /// e.g. `"turnstile"`) — every one of the three v1 providers exposes the
    /// SAME `.render(el, {sitekey, callback})` / `.reset(id)` shape under
    /// their own global name (`includes/captcha.php`'s own doc-block: "one
    /// browser API shape … which is what makes a single seam honest").
    public let renderGlobal: String

    /// The POST field name the provider's widget injects into a PLAIN HTML
    /// form (`manage/login.php`'s own read) — irrelevant to this JSON-API
    /// client (which always sends the token as `captcha_token`, see
    /// `AuthEndpoints.swift`'s `captchaToken` parameter), but decoded
    /// anyway since it's part of the real wire shape and costs nothing to
    /// carry.
    public let field: String

    /// Which `captchaFormKeys()` values (`includes/captcha.php`) are
    /// actually gated right now, e.g. `["login", "email_login"]`. An UNKNOWN
    /// value here (a form key this Swift package has never heard of) is
    /// simply never matched by `isRequired(for:)` below — tolerated, never
    /// a decode failure.
    public let forms: [String]

    public init(provider: String, siteKey: String, scriptUrl: String, renderGlobal: String, field: String, forms: [String]) {
        self.provider = provider
        self.siteKey = siteKey
        self.scriptUrl = scriptUrl
        self.renderGlobal = renderGlobal
        self.field = field
        self.forms = forms
    }

    /// Is the challenge live for THIS specific form right now? Mirrors the
    /// reference web client's `captchaRequired(form)`
    /// (`js/modules/captcha-widget.js:69`) exactly — `forms.includes(form)`
    /// there, `forms.contains(form)` here.
    ///
    /// ELI5: "Do I need to show the puzzle before I submit THIS form?"
    public func isRequired(for form: String) -> Bool {
        forms.contains(form)
    }
}

extension CaptchaConfig {
    /// Mirrors `captchaFormKeys()`'s `'login'` entry (`includes/captcha.php`)
    /// — the form key `?action=auth_login` is gated under. One of only TWO
    /// form keys this app can ever encounter (the other is
    /// `emailLoginFormKey` below) — the app has no native UI for
    /// `registration`/`password_reset`/`manage_login`, and `song_request`
    /// is a web-only endpoint (`.claude/captcha-native-and-outage-plan.md`
    /// §0-5/§0-7), so those three are never referenced from Swift at all.
    public static let loginFormKey = "login"

    /// Mirrors `captchaFormKeys()`'s `'email_login'` entry — the form key
    /// `?action=auth_email_login_request` is gated under (NOT
    /// `auth_email_login_verify`, which is never CAPTCHA-gated server-side
    /// — the code was already spent requesting it).
    public static let emailLoginFormKey = "email_login"
}

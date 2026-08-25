// AppRootViewModel+Captcha.swift
// IHFeatures
//
// ELI5: The bit of the "front desk" object that finds out, once at launch,
// whether any sign-in form needs a "prove you're human" puzzle solved
// first — and, once a provider is registered, tells the login screen which
// one to draw.
//
// DETAILED: #947/#340 native scaffold
// (`.claude/captcha-native-and-outage-plan.md` §2.4). `loadAppStatus()` is
// the app's FIRST `?action=app_status` call ever (§0-7: 0 hits pre-scaffold)
// — invoked from `restoreSessionIfNeeded()` (`AppRootViewModel+Auth.swift`),
// which already runs exactly once per launch behind its own
// `hasAttemptedSessionRestore` guard, so this file needs no SECOND one-shot
// guard of its own. Failure is swallowed to `nil` (`try?`): a dead/offline
// `app_status` call must never block launch or the sign-in flow — the app
// simply behaves exactly like a dormant install (P1 no-op, plan §3.6),
// identical to today's behaviour before this scaffold existed.
//
// `CaptchaChallengeRegistry.register(_:)` is called here too — idempotent
// (re-registering the same key just replaces the map entry), and cheap
// (`TurnstileCaptchaProvider` allocates no webview/resources until
// `makeChallengeView` is actually asked for one). Registering
// UNCONDITIONALLY, even on a dormant install, is deliberately harmless: the
// registry is consulted ONLY by `LoginView`'s `captchaSection(form:)`, which
// itself is gated on `captchaConfig` being non-nil AND the specific form
// being in `config.forms` — an unconfigured server never reaches that code
// at all, so registering a provider nobody will ever ask for costs nothing
// observable (plan's P1 dormancy proof, extended to this seam).
import IHAPI
import IHModels

extension AppRootViewModel {
    /// Loads `?action=app_status` and stores its (possibly `nil`) CAPTCHA
    /// config. Also registers this app's ONE shipped provider conformance
    /// (`TurnstileCaptchaProvider`, owner decision "Provider = Cloudflare
    /// Turnstile") into `CaptchaChallengeRegistry` — see this file's header
    /// for why doing so unconditionally is safe.
    ///
    /// ELI5: "Before anything else — is there a puzzle I might need to
    /// show, and if so, do I know how to draw it?"
    func loadAppStatus() async {
        CaptchaChallengeRegistry.register(TurnstileCaptchaProvider())
        captchaConfig = try? await apiClient.appStatus().captcha
    }

    /// Is `form` (a `CaptchaConfig.loginFormKey`/`.emailLoginFormKey` value)
    /// gated right now? `false` on a dormant/unconfigured install
    /// (`captchaConfig == nil`) — the pure, `nil`-safe convenience
    /// `LoginView` calls instead of unwrapping `captchaConfig` itself at
    /// every call site.
    ///
    /// ELI5: "Do I need to show the puzzle before this particular form can
    /// be submitted?"
    public func captchaRequired(for form: String) -> Bool {
        captchaConfig?.isRequired(for: form) ?? false
    }
}

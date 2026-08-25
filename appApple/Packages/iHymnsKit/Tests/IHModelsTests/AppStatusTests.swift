// AppStatusTests.swift
// IHModelsTests
//
// ELI5: Proves `AppStatus`/`CaptchaConfig` decode the REAL server shapes
// correctly — most importantly, that a dormant install (no `captcha` key at
// all) decodes cleanly to `captcha == nil` rather than throwing.
//
// DETAILED: #947/#340 native scaffold (`.claude/captcha-native-and-outage-plan.md`
// §2.2/§2.8). Mirrors `ContractTests.swift`'s pattern — a plain
// `JSONDecoder()` against `IHModels` types directly, no `APIClient`
// involved (that layer's own decode-wrapper coverage lives in
// `IHAPITests/AppStatusAndCaptchaAPITests.swift`). No committed fixture
// file exists for `app_status` yet (unlike `songs_index.json`/etc.) — the
// JSON below is modelled directly from the LIVE handler's literal
// `sendJson([...])` call (`api.php:7224-7245`) and
// `captchaClientConfig()`'s own literal return array (`includes/captcha.php`),
// the same "no live test account, model from source" caveat
// `FavoritesAndAuthAPITests.swift`'s header already states for this app's
// other auth-adjacent reads.
import Foundation
import Testing
@testable import IHModels

@Suite("AppStatus / CaptchaConfig decoding")
struct AppStatusTests {

    @Test("A dormant install's app_status (no captcha key at all) decodes to captcha == nil, never throws")
    func decodesDormantAppStatus() throws {
        // The REAL shape on every unconfigured install: `captchaClientConfig()`
        // returns `null` server-side, so the `captcha` key is OMITTED
        // entirely from the emit (`api.php`: `if ($captchaClient !== null) {
        // ... }`) — never present as a JSON `null`. Every OTHER top-level
        // key `app_status` emits (`maintenance`, `motd`, `registrationMode`,
        // ...) is included here too, proving `AppStatus`'s narrow model
        // tolerates a real, much bigger payload with no decode failure.
        let json = Data("""
        {
            "maintenance": false,
            "maintenanceMessage": "",
            "songRequestsEnabled": true,
            "registrationMode": "open",
            "motd": "",
            "emailLoginEnabled": false,
            "captchaProvider": "none",
            "adsEnabled": false,
            "contentGatingEnabled": false
        }
        """.utf8)

        let status = try JSONDecoder().decode(AppStatus.self, from: json)
        #expect(status.captcha == nil)
    }

    @Test("An empty {} object also decodes to captcha == nil — no keys assumed present")
    func decodesEmptyObject() throws {
        let status = try JSONDecoder().decode(AppStatus.self, from: Data("{}".utf8))
        #expect(status.captcha == nil)
    }

    @Test("A configured install's app_status decodes the real captchaClientConfig() shape")
    func decodesConfiguredAppStatus() throws {
        // Mirrors `captchaClientConfig()`'s literal return array
        // (`includes/captcha.php`) verbatim — every key ALREADY camelCase
        // on the wire, per `IHModels/AppStatus.swift`'s own header.
        let json = Data("""
        {
            "captcha": {
                "provider": "turnstile",
                "siteKey": "0x4AAAAAAA_site_key",
                "scriptUrl": "https://challenges.cloudflare.com/turnstile/v0/api.js",
                "renderGlobal": "turnstile",
                "field": "cf-turnstile-response",
                "forms": ["login", "email_login"]
            }
        }
        """.utf8)

        let status = try JSONDecoder().decode(AppStatus.self, from: json)
        let captcha = try #require(status.captcha)
        #expect(captcha.provider == "turnstile")
        #expect(captcha.siteKey == "0x4AAAAAAA_site_key")
        #expect(captcha.scriptUrl == "https://challenges.cloudflare.com/turnstile/v0/api.js")
        #expect(captcha.renderGlobal == "turnstile")
        #expect(captcha.field == "cf-turnstile-response")
        #expect(captcha.forms == ["login", "email_login"])
        #expect(captcha.isRequired(for: CaptchaConfig.loginFormKey))
        #expect(captcha.isRequired(for: CaptchaConfig.emailLoginFormKey))
        #expect(!captcha.isRequired(for: "registration"))
    }

    @Test("An UNKNOWN provider string still decodes fine — provider is a String, never an enum")
    func toleratesUnknownProvider() throws {
        let json = Data("""
        {
            "captcha": {
                "provider": "some-future-provider",
                "siteKey": "key",
                "scriptUrl": "https://example.invalid/widget.js",
                "renderGlobal": "someFutureGlobal",
                "field": "some-field",
                "forms": ["login"]
            }
        }
        """.utf8)

        let status = try JSONDecoder().decode(AppStatus.self, from: json)
        #expect(status.captcha?.provider == "some-future-provider")
    }

    @Test("An empty forms array means required-for(anything) is always false")
    func emptyFormsListRequiresNothing() {
        let captcha = CaptchaConfig(
            provider: "turnstile", siteKey: "k", scriptUrl: "https://x.invalid/s.js",
            renderGlobal: "turnstile", field: "f", forms: []
        )
        #expect(!captcha.isRequired(for: CaptchaConfig.loginFormKey))
        #expect(!captcha.isRequired(for: CaptchaConfig.emailLoginFormKey))
    }
}

# CAPTCHA — native widget scaffold + provider-outage fallback (deep plan)

**Fable-5 design pass, 2026-08-25.** Deep planning only — **no production code changed by this
pass.** Branch at design time: `claude/dormant-features-settings-1sdw4t` @ `09deaf32` (contains
`origin/alpha`; the CAPTCHA server core is **present on this branch and on `origin/alpha`, and
ABSENT on `origin/beta` / `origin/main`** — 0 gates there. The owner is migrating the build to
beta/main separately; nothing here assumes those channels have the code).

Companion to `.claude/account-security-1027-947-340-plan.md` (the original #947/#340 pack, now
**shipped through C6** on alpha). That document's claims were re-verified against the tree for
everything this plan builds on; §0 lists the corrections found. House style per rule #34: every
claim below carries a `file:line` from THIS branch's working tree.

Two work packages, independently landable:

| Part | What | Owner ask |
|---|---|---|
| **1 — Native scaffold** | Provider-**agnostic** CAPTCHA plumbing in `appApple/Packages/iHymnsKit` so the Apple app can learn a challenge exists, attach a token, and branch on the refusal — with the provider-rendering seam left as ONE conformance to fill in later | "scaffold provider-agnostic now" (provider deliberately NOT chosen) |
| **2 — Outage fallback** | A server-probed, automatically-closing grace window so a genuine provider outage does not lock legitimate users out of gated forms — plus admin visibility and a real break-glass | "a fallback for if the CAPTCHA provider service cannot be loaded … if the service is genuinely down/unavailable" |

---

## 0. Corrections + verified deltas against the original plan (read first)

1. **Channel reality:** server-complete on alpha/this branch only. `includes/captcha.php` (505
   lines) + 6 `captchaGate()` sites (`api.php:2985, :3357, :4626, :4863, :12696`;
   `manage/login.php:106`). Beta/main: absent. Any fallback work lands on this branch and
   reaches beta/main with the owner's migration — no separate porting task here.
2. **`connect-src` claim is UNVERIFIED provider documentation** (original §3.7: "widget XHR runs
   inside the provider's own frame"). Likely true for Turnstile and reCAPTCHA v2, likely **false
   for hCaptcha** (its published CSP guidance includes `connect-src`). §3.4-F fixes this with a
   *mechanism* (a `cspConnect` registry key + one more conditional append in `index.php`) rather
   than a belief, and §5's activation runbook makes the live DevTools check a required step.
3. **`api-docs.yaml` overstates the emit condition** (`:4885-4891`): it says the `captcha`
   app_status object is emitted only when configured "**AND at least one form is gated**" — the
   code emits whenever **configured** (`captchaClientConfig()` `captcha.php:348` returns non-null
   with `forms: []`). Fix the doc in the docs commit (rule #35's own lesson: the doc was wrong
   while the code was right).
4. **The load-bearing asymmetry nobody had stated:** during a genuine provider outage the server
   **already fails open for any non-empty garbage token** (`captchaVerifyToken()` transport
   failure → `true`, `captcha.php:443-450`) — a bot that sends `captcha_token: "x"` sails
   through. Only a **missing** token fails closed (`:390-391`, no network call). So during an
   outage the fail-closed path punishes exactly one population: **legitimate users whose widget
   never loaded** (they have no token to send). A bot author who has read `api-docs.yaml` is
   unaffected. Part 2's grace window therefore costs ~zero marginal security during a real
   outage and buys back the entire legitimate-user population. This observation is the spine of
   the §3 recommendation.
5. **`song_request` (the native Apple endpoint, `api.php:6518`) is not a CAPTCHA form key** —
   only the web-only `song_request_submit` (`:12696`) is gated. The admin caption already
   documents this honestly (`manage/configuration.php:1199`). Not changed by this plan; tracked
   as a follow-up issue (§7) so the hole is on the tracker, not just in a caption.
6. **The admin-lockout trap is real and currently SQL-only to escape:** ticking BOTH
   `manage_login` and `login` while the widget cannot load removes every route in —
   `/manage/login.php:106` fails closed with no token, and the public sign-in that
   `adoptApiTokenSession()` (`manage/includes/auth.php:217`) promotes runs through the equally
   gated `auth_login`. Today's only recovery is editing `tblAppSettings` by hand. §3.4-E designs
   the break-glass.
7. **Native exposure re-verified:** `grep -rni captcha appApple appAndroid` = 0 hits. Apple calls
   `auth_login` + `auth_email_login_request`/`_verify` + `auth_me` + `account_delete` +
   `song_request` (`IHAPI/AuthEndpoints.swift`, `APIClient+Auth.swift`,
   `SongRequestEndpoint.swift`); it does **not** call `auth_register` or `auth_forgot_password`
   (rule #44 — do not plumb tokens into endpoints the app never calls). It **never calls
   `app_status`** (0 hits), so today the app cannot even learn a challenge exists. Android
   (`appAndroid/`) has **no networking layer at all** — nothing to scaffold there (§2.9).
8. **Wire shape the Apple client actually receives:** the client sends `X-API-Version: 2`
   (`APIClient.swift:218`), so a 403 refusal arrives as the v2 error envelope
   (`includes/api_envelope.php:110`):
   `{"ok":false,"error":{"code":"http_403","message":"Please complete…","reason":"captcha_required"}}`
   — the `reason` key is preserved inside `error` by the envelope's key-preservation loop
   (`api_envelope.php:91-95`). The PWA (no version header, v1) receives the flat
   `{"error":…,"reason":"captcha_required"}` that `isCaptchaRefusal()`
   (`js/modules/captcha-widget.js:78-80`) reads. Both shapes are decoded in §2.3.
9. **`APIClient.classify` discards the body** (`APIClient.swift:260-276`): 401/423/429/503 are
   special-cased; 403 falls to `default:` → `.server(status: 403, message: nil)` — the machine
   `reason` is thrown away before any caller sees it. §2.3 fixes this with a body-aware sibling,
   not by mutating the pinned pure `classify`.

---

## 1. Verified current state (file:line; paths under `appWeb/public_html/` unless noted)

### 1a. Server core (all shipped, all verified this pass)

- `includes/captcha.php` — constants `:72-94` (`IHYMNS_CAPTCHA_BODY_KEY='captcha_token'` `:81`,
  `IHYMNS_CAPTCHA_REASON='captcha_required'` `:87`); `captchaFormKeys()` `:106` (6 keys);
  provider registry `captchaProviders()` `:140` (turnstile/hcaptcha/recaptcha_v2 selectable +
  reserved recaptcha_v3; per-provider `script`/`verify`/`field`/`widgetClass`/`renderGlobal`/
  `cspScript`/`cspFrame`); pure core `captchaResolveConfig()` `:202` / `captchaParseForms()`
  `:228` / `captchaGateDecision()` `:259` (no identity parameter — Reflection-pinned);
  DB wrappers `captchaConfig()` `:287` (per-request memo) → `captchaClientConfig()` `:348`;
  `captchaVerifyToken()` `:387` — missing/overlong token → `false` with **no network call**
  (`:390-391`); fail-**open** on curl-missing (`:397-401`), non-https registry URL (`:404-406`),
  `curl_init()` failure (`:409-411`), transport error / non-2xx (`:443-450`), unparsable body
  (`:451-454`); `captchaGate()` `:473-482` (dormant/unticked short-circuit before any network);
  `captchaWidgetHtml()` `:494`.
- Gates: `auth_register` `api.php:2985`, `auth_login` `:3357`, `auth_forgot_password` `:4626`,
  `auth_email_login_request` `:4863`, `song_request_submit` `:12696`, `manage/login.php:106`
  (reads the provider's own POST `field`).
- CSP: `index.php:227-235` builds `$cspCaptchaScript`/`$cspCaptchaFrame` from
  `captchaCspOrigins()`; appended at `:243` (`script-src`) and `:256` (`frame-src`). **No
  captcha conditional on `connect-src` (`:255`)** — see §0-2.
- `app_status`: `api.php:7219-7224` adds the `captcha` object when configured (absent when
  dormant); legacy flat `captchaProvider` emit `:7232` unchanged.
- Client: `js/modules/captcha-widget.js` (152 lines — `initCaptcha` `:55`, `captchaRequired`
  `:69`, `isCaptchaRefusal` `:78-80`, `mountCaptcha` `:113` with `getToken/reset/remove`);
  consumers `user-auth.js` (4 forms) + `request-a-song.js`; boot via `user-auth.js:69`
  `initCaptcha(this._appStatus)`.
- Admin: `manage/configuration.php` — `save_captcha` handler `:914-963`, card `:1594-1685`,
  per-form captions `$captchaFormMeta` `:1194-1201`. Secret custody:
  `includes/secret_crypto.php:494`. Seeds: `appWeb/.sql/schema.sql:2093-2096`; migrations
  `migrate-wire-captcha-settings.php` + registry probe (`migration-registry.php:3211-3221`).
- Guards already in the tree: `tests/php/test-captcha-gate.php` (356 lines — pure truth table,
  registry self-consistency, custody, coverage, ordering, CSP lockstep) and
  `tests/test-captcha-client.js` (132 lines — consumer derivation, status+reason branching,
  PHP↔JS body-key lockstep). Both are extended, never forked, below.

### 1b. Infrastructure this plan reuses (the precedents)

- **`getAppSetting()`/`setAppSetting()`** (`includes/maintenance.php:60/:135`) — per-request
  static cache; `setAppSetting` is an UPSERT (`ON DUPLICATE KEY UPDATE`), so a new settings row
  needs no DDL and no pre-seed to be writable.
- **`checkRateLimit()`/`recordRateLimitHit()`** (`includes/rate_limit.php:168/:~230`) —
  fail-open windowed counters on `tblLoginAttempts`; the cadence guard for probes + the hint
  endpoint.
- **`tblIntAppsSync`** (`includes/intapps_client.php:415/:746/:822`) — the house pattern for a
  cached outbound-service state: TTL, single-flight conditional-UPDATE lock, exponential
  backoff, refresh **off the critical path**, and an admin "Refresh now" that is honest about
  being locked rather than silently no-oping. §3.4-A adapts the *shape* without the table.
- **`includes/` is web-denied** (`.htaccess:54-55` `RewriteRule ^includes/ - [F,L]`) — the
  break-glass kill-file's home (§3.4-E).
- Apple test targets exist (`Packages/iHymnsKit/Tests/IHAPITests` + `IHTestFixtures`) — the
  fixture-driven contract-test home for §2.8.

---

## 2. PART 1 — Provider-agnostic native scaffold (Apple)

**Goal:** everything that does not depend on WHICH provider is chosen, built now, so the later
provider decision is ONE conformance + ONE registration line — not a rewrite. The scaffold is
inert while the server is dormant (no `captcha` key in `app_status` ⇒ every new path no-ops).

### 2.1 Server prerequisite (tiny, web-side): a machine `code` on the refusal

`captchaGateDecision()` (`captcha.php:259`) returns `['error'=>…, 'reason'=>…]`. Add
`'code' => IHYMNS_CAPTCHA_REASON` to that array. Effect: the v2 envelope's `error.code` becomes
`captcha_required` instead of the derived `http_403` (`api_envelope.php:87-89` prefers an
explicit `code`) — which is exactly what the envelope's own doc-block promises v2 clients
("a stable string a client can `switch` on"). v1 (web) additionally carries a redundant flat
`code` key — additive; `isCaptchaRefusal()` keeps reading `reason` unchanged. Native clients
then branch on **status 403 + `error.code`/`reason`** with both fields carrying the same
constant (rule #35). Update: `test-captcha-gate.php`'s exact-403-array assertion (Section 1)
grows the key — and that update IS the mutation proof (the guard goes red on today's core,
green after the one-line change; then delete the key → red → restore).

`api-docs.yaml`: document `code: captcha_required` beside the existing 403 description, and fix
the §0-3 emit-condition sentence, in the same docs commit (C-D).

### 2.2 `IHModels`: the wire types

New file `Packages/iHymnsKit/Sources/IHModels/AppStatus.swift`:

```swift
public struct AppStatus: Decodable, Sendable, Equatable {
    public let captcha: CaptchaConfig?    // ABSENT when dormant ⇒ decodes nil, never throws
}

public struct CaptchaConfig: Decodable, Sendable, Equatable {
    public let provider: String        // registry key — a String, DELIBERATELY not an enum:
                                       // an unknown/future provider must decode fine and
                                       // resolve to "unsupported" at the seam, never fail decode
    public let siteKey: String
    public let scriptUrl: String
    public let renderGlobal: String
    public let field: String
    public let forms: [String]         // captchaFormKeys() values; unknown values tolerated

    public func isRequired(for form: String) -> Bool { forms.contains(form) }
}
```

`AppStatus` models ONLY `captcha` for now (Decodable ignores every other key) — rule #44:
collect nothing the app acts on. Widening it later (maintenance/motd/registrationMode) is
additive.

### 2.3 `IHAPI`: endpoint, error taxonomy, body-aware 403 classification

1. **`Endpoint.appStatus`** (in a new `AppStatusEndpoint.swift`): bodyless GET, `requiresAuth:
   false`. **`APIClient.appStatus()`** uses `performIdempotentGET` (idempotent read) +
   `decodeAppStatus(from:)` in a new `AppStatusDecoding.swift` (the `AuthDecoding.swift`
   pattern: `nonisolated static`, throws `.decoding` on shape mismatch — but note the whole
   `AppStatus` struct is optional-tolerant, so `.decoding` only fires on non-JSON).
2. **`APIError` gains one case** — `case captchaRequired` (no associated values; the client
   behaviour is identical for missing vs invalid, mirroring the server's ONE reason). The
   taxonomy's own header says a new case "forces every switch in the codebase to be revisited,
   which is exactly the safety net we want" — the compiler enumerates the sites:
   `APIError.caseName` (`APIError.swift:96`), `httpStatusForLogging` (`:113` → returns 403),
   `isRetryable` (→ **false**: retrying without a fresh token cannot succeed),
   `userFacingMessage` (`IHFeatures/APIError+UserFacing.swift:23` → "Please complete the
   verification challenge and try again." — the server's own copy, but chosen HERE as native
   copy, not parsed from the response), and the account-delete mapping (falls through).
3. **Body-aware classification** — a NEW pure sibling, never a mutation of the pinned
   `classify`:

```swift
/// APIClient.swift (or the Networking split if the 400-line tripwire objects)
/// Decodes a 4xx body far enough to recognise a machine-coded refusal.
/// Handles BOTH wire shapes: the v2 envelope {ok:false, error:{code, reason,…}}
/// (what this client, which sends X-API-Version: 2, actually receives) AND the
/// v1 flat {error, reason, code} (defensive: unwrapEnvelope's tolerant-
/// passthrough philosophy applied to errors). Returns nil for anything else —
/// the caller then falls back to classify(httpStatus:retryAfterSeconds:).
nonisolated static func classifyMachineRefusal(httpStatus: Int, body: Data) -> APIError? {
    guard httpStatus == 403 else { return nil }
    // reads error.code / error.reason (v2) or code / reason (v1);
    // "captcha_required" → .captchaRequired; anything else → nil
}
```

   Wiring in `performOnce` (`APIClient+Networking.swift:170`): call
   `classifyMachineRefusal(httpStatus:body:)` FIRST; only when it returns nil fall through to
   the existing `classify(...)`. Every non-403, and every 403 that is NOT a captcha refusal
   (e.g. a future account-disabled 403), behaves byte-identically to today
   (`.server(status: 403, message: nil)`). Branch on STATUS + MACHINE CODE, never prose —
   rule #35 satisfied by construction (the function never looks at `message`).
4. **Token plumbing** (`AuthEndpoints.swift`): the two factories the app actually uses grow an
   optional trailing parameter, defaulting nil so every existing call site compiles and every
   existing request body stays **byte-identical** (the file's own "absent key, never a literal
   null" convention — `JSONEncoder` omits nil optionals):

```swift
static func authLogin(username: String, password: String, captchaToken: String? = nil) throws -> Endpoint
static func authEmailLoginRequest(email: String, captchaToken: String? = nil) throws -> Endpoint
// AuthLoginRequestBody / AuthEmailLoginRequestBody each gain:
//   let captchaToken: String?   // CodingKey "captcha_token" — mirrors IHYMNS_CAPTCHA_BODY_KEY
```

   Pass-throughs up the chain, all `= nil` defaulted: `APIClient.authLogin(username:password:
   captchaToken:)` / `.authEmailLoginRequest(email:captchaToken:)` (`APIClient+Auth.swift:39/
   :91`), `SessionController.signIn(username:password:captchaToken:)` (`IHAuth/
   SessionController.swift:191`) / the email-request path, `AppRootViewModel.signIn(...)`
   (`IHFeatures/AppRootViewModel+Auth.swift:41`). NO plumbing on `auth_register`/
   `auth_forgot_password` (the app never calls them — §0-7) and none on `song_request`
   (ungated — §0-5).

### 2.4 The provider-rendering seam (`IHFeatures`)

New file `Packages/iHymnsKit/Sources/IHFeatures/CaptchaChallenge.swift`:

```swift
/// ONE conformance per provider family. Registered by string key; the server's
/// app_status emit picks which one is live (rule #35 — the server emit is the
/// contract; this app carries NO provider hostnames, sitekeys, or URL literals:
/// scriptUrl/siteKey/renderGlobal all arrive in CaptchaConfig).
@MainActor public protocol CaptchaChallengeProviding {
    /// captchaProviders() key this conformance renders ("turnstile", "hcaptcha", …).
    var providerKey: String { get }
    /// Whether THIS build target can render it at all (tvOS/watchOS: see §2.6).
    var isSupportedOnThisPlatform: Bool { get }
    /// A SwiftUI view hosting the challenge. Calls onToken exactly once per
    /// human solve; the hosting form stores the token for its NEXT submit.
    func makeChallengeView(config: CaptchaConfig, onToken: @escaping (String) -> Void) -> AnyView
    /// Invalidate any solved state (called after EVERY failed submit — §2.5).
    func reset()
}

/// The string-keyed registry. Ships EMPTY of real providers; registering the
/// chosen conformance later is one line in the app bootstrap.
@MainActor public enum CaptchaChallengeRegistry {
    public static func register(_ p: CaptchaChallengeProviding)
    public static func provider(for key: String) -> CaptchaChallengeProviding?
}
```

Plus the view-model surface (`AppRootViewModel+Captcha.swift`, a same-target split per the
400-line tripwire convention):

- `loadAppStatus()` — the app's FIRST `app_status` call, invoked once from the root task that
  already drives `restoreSessionIfNeeded()`; failure is swallowed to nil (a dead `app_status`
  must never block launch — the app simply behaves as dormant, exactly like today).
- `captchaConfig: CaptchaConfig?` published; `captchaRequired(_ form: String) -> Bool`.
- `LoginView` (username/password + email-code flows): when `captchaRequired("login")` /
  `("email_login")`, and the registry resolves a supported provider → embed
  `makeChallengeView`, hold the latest token, attach it on submit. When required but
  **unresolvable** (no conformance registered / unsupported platform) → submit WITHOUT a token
  and let the server's branchable 403 drive the message (never a client-side hard block — the
  server may have a grace window open, §3, and the client must not pre-empt it).
- On ANY failed submit (`.captchaRequired` OR wrong-password `.unauthorized` OR transport):
  call `reset()` — see §2.5.

### 2.5 The reset-and-retry contract (why `reset()` is unconditional)

All three v1 providers **consume a token at first `siteverify`** (`captcha.php:379-382`'s
doc-block; no local replay cache). Consequences the scaffold must encode:

1. A token attached to a submit is SPENT the moment the server verifies it — even when the
   overall request then fails for another reason (wrong password: the gate passes at
   `api.php:3357`, `password_verify` fails later). The retry MUST carry a **fresh** token.
2. Therefore: token state lives per-submit, never cached across attempts; `reset()` after every
   failed submit, before re-enabling the button; the UI shows the widget re-armed.
3. A `.captchaRequired` response additionally means the PREVIOUS token was missing/invalid/
   already-spent — same handling, plus the specific copy.

This mirrors the reference client exactly (`user-auth.js:341/:370/:1627/:2149` — reset on
`isCaptchaRefusal`), extended to the wrong-password case because unlike the web modal (where
the widget solve happens right before submit) a native form may hold a solved token longer.

### 2.6 The provider-conditional honesty table (stated, NOT picked)

| Provider | Native path | What it would mean |
|---|---|---|
| **hCaptcha** | Official native iOS SDK (SPM) | The only first-party native path of the three. New SPM dependency; a `PrivacyInfo.xcprivacy` data-collection declaration; sitekey provisioned for the app bundle. iOS/iPadOS only — no tvOS/watchOS SDK. |
| **Turnstile** | No native SDK → WKWebView bridge | A generic bridge is buildable ~provider-agnostically: `loadHTMLString` embedding `<script src={scriptUrl}>` + `<div class={widgetClass} data-sitekey data-callback>` posting the token via `messageHandlers`, with `baseURL` set to the API origin so the widget's **hostname allow-list validation** passes (Turnstile validates the embedding domain against the sitekey's registered hostnames — an `about:blank` bridge fails it). Whether Cloudflare's interstitial behaves inside WKWebView must be proven with a real sitekey — cannot be verified until keys exist. |
| **reCAPTCHA v2** | No supported native v2 SDK → same WKWebView bridge | Same bridge, same baseURL trick, weakest privacy story (original plan D1), and Google's terms/branding requirements to review at activation. |
| **All three** | tvOS/watchOS | **WKWebView does not exist on tvOS or watchOS.** No web-based challenge can render there, and no provider ships a tvOS/watchOS SDK. If the owner ever gates `login`/`email_login`, sign-in on those targets breaks with no client-side fix — the honest options are "don't gate the shared forms" (original D2 already recommends enabling `login` last-or-never) or a server-side decision out of this plan's scope. Surfaced as decision D-N2 (informational). |

The seam is shaped so that the generic WKWebView bridge, if chosen, is itself ONE conformance
(`WebViewCaptchaProvider`) parameterised entirely by `CaptchaConfig` — all three widget vendors
share the auto-render-div + `data-callback` shape, which is the same one-seam observation the
server registry already banked (`captcha.php:55-59`).

### 2.7 What CANNOT be built until the provider is chosen (explicit)

1. The real `CaptchaChallengeProviding` conformance (SDK integration or the WebView bridge's
   provider-specific validation quirks).
2. The `PrivacyInfo.xcprivacy` additions (each provider declares different collected-data
   categories — App Store submission blocks on getting this right, and it is per-provider).
3. Sitekey/domain/bundle provisioning at the provider (incl. whether the baseURL trick
   satisfies its domain validation — testable only with real keys).
4. Any App Review–facing copy/branding obligations (reCAPTCHA attribution, hCaptcha ToS links).
5. End-to-end verification of §2.5's reset semantics against a live widget.
6. The D2 activation sequencing itself (which forms to tick, when) — owner-owned, unchanged.

Everything in §2.1–§2.5 is NOT on this list — that is the point of the scaffold.

### 2.8 Swift tests (all pure, fixture-driven; the `IHAPITests` house pattern)

- `AppStatusDecodingTests`: captcha present / absent (dormant fixture — must decode with
  `captcha == nil`) / unknown-provider string / extra unknown keys. Fixtures generated from the
  REAL server emit shape (`api.php:7219`) and committed under `IHTestFixtures` — the PHP↔Swift
  lockstep is the fixture, regenerated whenever `captchaClientConfig()` changes shape (and the
  web guard `test-captcha-gate.php` already pins that PHP shape, closing the loop).
- `ClassifyMachineRefusalTests`: truth table — v2 envelope with `code`/`reason`; v1 flat;
  403-without-reason → nil (falls back to `.server`); 403 with a DIFFERENT reason → nil; non-403
  with the reason → nil; garbage body → nil. Mutation proof: flip the status guard to 401 → the
  non-403 row goes red; drop the v1-flat branch → that fixture row goes red.
- `AuthEndpointsCaptchaTests`: `authLogin(captchaToken: nil)` body byte-identical to the
  pre-change fixture (the no-regression pin); `captchaToken: "t"` body contains
  `"captcha_token":"t"`. Mutation proof: rename the CodingKey → red.
- `APIErrorTests` extension: `.captchaRequired` → `caseName`, `httpStatusForLogging == 403`,
  `isRetryable == false`.

### 2.9 Android

No networking exists (`appAndroid/` — zero HTTP client usage, verified §0-7). Deliverable for
Android is the **contract**, not code: the `captcha_token` body key, the 403 + `captcha_required`
code/reason, the `app_status.captcha` shape and the reset-and-retry rule are already/newly in
`api-docs.yaml` (§2.1) — the Kotlin client adopts them whenever its networking layer is born.
No Android commit in this plan.

---

## 3. PART 2 — Provider-outage fallback

### 3.1 The problem, precisely

- Server → provider unreachable at VERIFY time: **already fails open** (`captcha.php:443-454`)
  — but only a request that *carries* a token ever reaches that code.
- Provider's WIDGET unreachable from browsers: the client never mints a token →
  `mountCaptcha()` returns null (`captcha-widget.js:117-119`) → the form submits token-less →
  `captchaVerifyToken('')` fails **closed with no network call** (`:390-391`) → 403. During a
  full outage the server therefore *never even observes* the outage (no verify calls happen),
  and every legitimate user of every gated form is locked out until an admin hand-edits
  settings. Per §0-4, a bot meanwhile passes by sending any garbage token.
- Owner's scope: genuine provider unavailability. Explicitly OUT of scope (owner's own
  carve-out): per-user client-side blocks — ad-blockers/corporate filters blocking
  `challenges.cloudflare.com` for SOME users while the provider is up. The design below keeps
  those users 403'd (correctly, per the stated scope) but makes them *visible* (§3.4-D).

### 3.2 The core difficulty, confronted

"The provider is down" and "a bot omitted the token" are **indistinguishable from the server's
side if the evidence comes from the client.** Any request-borne assertion — a header, a body
flag, a "widget failed" boolean — is a universal bypass the moment a bot copies it.
**Resolution adopted throughout §3.4: the ALLOW decision derives exclusively from server-side
observations** (the server's own outbound probes + transport results of real verify calls);
client signals are accepted only as (a) probe *triggers* and (b) telemetry. A client can make
the server *look*; it can never make the server *believe*. The residual trust anchor — "can an
attacker make the provider unreachable *from the server*?" — is identical to the one the
SHIPPED fail-open already rests on (original plan §A.4), so the grace window widens an existing
posture rather than minting a new one.

### 3.3 Options evaluated

| # | Option | Verdict | Why |
|---|---|---|---|
| 1 | **Server-side health probe → time-boxed, auto-closing grace window** | **ADOPT (the core)** | The only design where the open/close decision is attacker-independent. Cost: probe machinery + state. Details/§3.4-A. |
| 2 | **Corroborated client signal** (hint accepted only when a server probe agrees) | **ADOPT, narrowed** | The hint's only power is to *schedule* a probe sooner (and increment a visible counter). It never opens the window by itself — that would be §3.2's bypass. Gets the window open BEFORE the first token-less submission arrives (the widget usually fails seconds earlier), at the price of one rate-limited anonymous endpoint. |
| 3 | **Degrade to the underlying rate limits for the window** | **ADOPT — this is what "open" MEANS** | Nothing is switched off: while the window is open, gated endpoints run exactly today's non-CAPTCHA defence stack — per-IP/per-account/per-identifier budgets, honeypot, daily caps (original §1d). The window is not "no protection"; it is "the pre-#947 protection floor, temporarily, loudly". |
| 4 | **Per-form policy** | **ADOPT as an opt-out CSV** | Stakes differ (`song_request` trivial; `registration` is the spam magnet; `login` is the Sunday-morning lockout). Default: ALL gated forms degrade open (per §0-4 the marginal outage-time security of staying closed is ~nil, while the lockout cost is total). An owner who disagrees for a specific form lists it in `captcha_outage_strict_forms` (CSV ⊆ `captchaFormKeys()`, rule #20 — no ENUM, no DDL) and that form keeps today's fail-closed behaviour. Owner decision D-F1 confirms the default. |
| 5 | **Admin visibility + break-glass** | **ADOPT regardless of 1-4** | Today an outage is `error_log` only. §3.4-D/E: card health line + probe-now button, dashboard banner, activity-log transitions, misconfig detection, SFTP kill-file, and a save-time warning on the D6 lockout combination. |
| — | Client-asserted failure flag (no corroboration) | **REJECT** | §3.2 — a one-line universal bypass. Listed to record that it was considered and why it is banned (a CI guard enforces the ban, §4-G3). |
| — | Secondary/fallback CAPTCHA provider (auto-switch) | **REJECT for now** | Doubles the CSP surface permanently (both providers' origins would need pre-listing or a config write on failover), doubles provisioning, and the switch decision has the same indistinguishability problem. The registry already makes a *manual* provider switch a 30-second admin action — that, plus the grace window covering the gap, is enough. Noted as a possible future follow-up, not designed. |

### 3.4 Locked design

All new server logic lives in `includes/captcha.php` (the ONE module — rule #22), pure-core
first (the `captchaGateDecision()` testing pattern), with the same dormancy discipline: **none
of this executes unless a provider is configured AND the form being gated is ticked** — the
existing short-circuit at `captchaGate():475-478` runs first, unchanged.

#### A. Health state + probe

**State storage:** ONE `tblAppSettings` row, `captcha_health_state`, holding a small JSON blob —
zero DDL (the pack's own "settings rows, not columns" doctrine; `setAppSetting()` upserts so no
seed is required for writability, though the row IS seeded with a description for schema.sql
hygiene, §3.5). Shared across the three channels by construction (one MySQL, one physical host,
one outbound network path — a per-channel split would triple probes for no signal). Shape:

```json
{"status":"up",            // 'up' | 'down' | 'misconfig'  (VARCHAR vocab, central map — rule #20)
 "checkedAt": 1756100000,   // unix ts of the last probe/observation
 "downSince": null,          // unix ts when 'down'/'misconfig' began, else null
 "consecutiveFailures": 0,
 "lastErrno": 0, "lastHttpStatus": 200,
 "hintCount": 0}             // client widget-failure hints since the last state change (telemetry only)
```

Constants (code, not settings — no misconfigurable knobs, the `CAPTCHA_CURL_*` doctrine):
`CAPTCHA_HEALTH_FRESH_SECONDS = 60` (a probe result is authoritative for 60 s),
`CAPTCHA_HEALTH_PROBE_MIN_INTERVAL = 30` (hard floor between outbound probes, enforced via the
existing limiter: `checkRateLimit('captcha_probe','global',1,30)` + paired record — the
check/record pairing guard `test-rate-limit-pairing.php` covers the new action automatically),
`CAPTCHA_PROBE_CONNECT_TIMEOUT = 2` / `CAPTCHA_PROBE_TIMEOUT = 3` (tighter than the verify
band: a probe can sit on a user-facing request, §B). **No maximum window duration** — the
window stays open exactly as long as probes keep failing and closes on the FIRST success; an
arbitrary cap would re-brick users mid-outage, and the state cannot wedge "open" because every
admit re-checks freshness (§B). Single-flight is *approximate* (limiter check→record is not
atomic): two concurrent stale reads can both probe once. Accepted: the cost is one duplicate
outbound call every ≥30 s worst case — not worth importing `tblIntAppsSync`'s conditional-UPDATE
lock table for. (If this ever matters, the intapps lock shape is the documented upgrade path.)

**The probe** — `captchaProbeProvider(array $config): array{status, errno, httpStatus}`:

1. `GET config['script']` (the widget URL — the thing browsers actually failed to load), size
   response cap 256 KiB via the aborting write-callback, no redirects... **correction:** follow
   up to 2 redirects for the script GET only (CDNs re-home bundles; the verify POST stays
   redirect-free) — 2xx/3xx-resolved-2xx = script OK; timeout/4xx/5xx = script FAIL.
2. `POST config['verify']` with `secret + response='ihymns-health-probe'` (a deliberately
   invalid token; consumes nothing). A parsable JSON body containing a `success` key — even
   `success:false` — proves the verify service is answering = verify OK; transport
   failure/unparsable = verify FAIL.
3. `status = 'down'` when **either** leg fails (the window must open for a widget-only partial
   outage, which is the exact scenario the owner described); `'up'` when both pass.

Both legs reuse the hardened curl discipline of `captchaVerifyToken()` (SSL verify, HTTPS-only,
registry-constant URLs — SSRF-safe by construction). Honest residual, stated in the admin UI:
the probe answers "reachable **from this server**"; a geo-partial outage that blocks *clients*
but not this server keeps the window closed (the hint counter, §D, at least makes it visible).

**Feeders — the state has two, and real traffic is the cheaper one:**

- *Passive:* `captchaVerifyToken()`'s existing transport-failure branch (`:443-450`) also
  records a DOWN observation (it IS a failed probe of the verify leg, free); its definitive
  branches (parsable `success` either way) record UP. So under real traffic with tokens, the
  state tracks reality with zero extra outbound calls.
- *Active:* `captchaHealthEnsureFresh()` — called ONLY from the refusal path (§B) and the hint
  endpoint (§D): if `now - checkedAt > CAPTCHA_HEALTH_FRESH_SECONDS` and the probe limiter
  admits, run the probe and store the result. Never called on allowed requests, never on
  dormant installs, never on unticked forms — the hot path cost when everything is healthy is
  **zero** (state only gets consulted after a token is already missing/invalid).

#### B. The gate rework (`captchaGate()`, `captcha.php:473`)

```php
function captchaGate(string $form, ?string $token): ?array
{
    $config = captchaConfig();
    $forms  = captchaEnabledFormsList();
    if ($config === null || !in_array($form, $forms, true)) {
        return null;                       /* UNCHANGED dormant short-circuit — no I/O */
    }
    $ok = captchaVerifyToken($token, ...); /* unchanged: garbage → false w/o network;
                                              real token → verify (fail-open on transport,
                                              now ALSO recording the passive observation) */
    if (!$ok && !in_array($form, captchaOutageStrictForms(), true)) {
        captchaHealthEnsureFresh($config);          /* stale? probe (rate-limited) */
        if (captchaOutageDecision(captchaHealthState(), time()) === 'admit') {
            captchaHealthNoteAdmit();               /* counter only — no per-request log row */
            return null;                            /* GRACE WINDOW: the pre-#947 floor applies */
        }
    }
    return captchaGateDecision($config, $forms, $form, $ok);
}
```

- **`captchaOutageDecision(array $state, int $now): string`** (`'admit'|'enforce'`) is PURE:
  admit iff `status ∈ {down, misconfig}` AND `now - checkedAt <= CAPTCHA_HEALTH_FRESH_SECONDS`
  — a stale DOWN never admits on its own; it forces a re-probe first, which is what makes the
  window **self-closing**: the first successful probe (or successful real verify) flips the
  state and the very next request enforces again. Fully truth-tabled in the guard (§4-G1).
- The window covers the **invalid-token** case as well as the missing-token case while DOWN —
  deliberate: during a partial outage a widget may mint tokens siteverify then rejects as
  malformed; distinguishing them re-opens the §0-4 asymmetry for zero gain.
- `manage/login.php` inherits everything through this same function (its `:106` call site is
  unchanged) — the admin-lockout scenario self-heals during a genuine outage.
- Ordering within each endpoint is untouched (budgets → gate), so the probe can never become an
  amplification vector beyond its own ≥30 s limiter: junk POSTs without tokens cost at most one
  outbound probe per 30 s **globally**, not per request.

#### C. Misconfiguration detection (the OTHER way admins brick every gated form)

A pasted-wrong **secret** is not an outage: the provider answers `200 {success:false,
"error-codes":["invalid-input-secret"]}` — today every legitimate user is refused forever with
nothing anywhere but a rising 403 count. All three providers document machine-readable
`error-codes`; the secret-side codes cannot be induced by an attacker (they are a function of
OUR stored secret, delivered over TLS from the registry-constant URL). Design:

- Registry entries gain `'secretErrorCodes' => ['missing-input-secret','invalid-input-secret']`
  (identical strings across all three providers, but carried per-entry — the registry is the
  ONE table, rule #35).
- `captchaVerifyToken()`: on `success:false`, if `error-codes` intersects `secretErrorCodes` →
  record state `'misconfig'` + **return `true` (fail open)** + `error_log`. The admin banner
  (§D) says exactly what to fix. On any other `success:false` → `false`, unchanged (a normal
  human/bot failure MUST stay closed — and it records UP, because the service answered).

#### D. Corroborated client hint + admin visibility

- **Hint endpoint:** `api.php` `case 'captcha_widget_health'` (POST, anonymous, tiny): body
  `{form}` validated ⊆ `captchaFormKeys()`; per-IP limited (existing limiter, e.g. 5/5 min —
  paired check/record, auto-covered by `test-rate-limit-pairing.php`); dormant-gated (400 when
  `captchaConfig()===null` — the endpoint doesn't exist observably on an unconfigured install).
  Effect: `hintCount++` in the state JSON + `captchaHealthEnsureFresh()`. **It returns the same
  `{ok:true}` regardless of state and NEVER influences an allow decision** — guard-banned
  (§4-G3). Not CSRF-gated: it changes no user-visible state and is deliberately callable from
  the failing widget path; rule #29 targets state-changing writes, and the only "state" here is
  a rate-limited telemetry counter plus a probe the server was entitled to run anyway.
- **Client side** (`captcha-widget.js`): in `_loadProviderScript()`'s error path and
  `mountCaptcha()`'s render-failure path, fire-and-forget `apiFetch('/api?action=
  captcha_widget_health', {method:'POST', …})` (rule #31 — `apiFetch`, never bare fetch;
  same-origin so **no CSP change**). Failures swallowed.
- **Admin card** (`manage/configuration.php` CAPTCHA card): a health strip when configured —
  status badge (`Healthy` / `Provider unreachable — grace window OPEN since …` /
  `Secret rejected by provider — fix the secret key`), last-check time, hint counter, admitted
  counter, and a **"Probe now"** POST button (CSRF-gated like every card action) that calls the
  probe directly, bypassing the interval limiter but honouring a trivial concurrent-run check —
  the `intappsForceRefresh()` honesty rule: the button must never silently no-op (`intapps_client.php:822`'s
  doc-block is the cited precedent).
- **Dashboard banner:** `manage/index.php` — when configured AND state ≠ up, a dismissible
  `alert-warning` naming the state and linking the card. Read is one memoized
  `getAppSetting()` — no probe, no outbound call, from the dashboard ever.
- **Audit trail:** state TRANSITIONS only (up→down, down→up, →misconfig) write
  `logActivity('captcha.health', 'app_setting', 'captcha_health_state', {from,to,...})` — never
  per-admitted-request rows (a busy outage would flood `tblActivityLog`); the admitted count
  lives in the state JSON and is reported on the card + in the close-transition log row.
- **NOT emitted:** no `degraded`/health field in `app_status` or any client-visible response.
  Telling clients (= telling bots) the gate is open buys a marginal widget-UX improvement at
  the price of advertising the window. The widget already degrades gracefully (mount fails →
  tokenless submit → admitted). Defensible default, trivially changeable — flagged, not asked.

#### E. Break-glass (the D6 lockout, solved without SQL)

1. **Kill-file:** `captchaConfig()` (`captcha.php:287`) checks — before anything else, memoized
   — `is_file(__DIR__ . '/CAPTCHA_DISABLED')`. Present ⇒ resolve to null: the entire feature is
   dormant (gates, CSP, emit, widget — all of it, by the existing P1 dormancy chain). The file
   lives in `includes/`, which is already web-denied (`.htaccess:54-55`) and — decisive — is
   reachable over the **same SFTP access every deploy already uses**, on every channel, with no
   DB client and no working login. Drop an empty file → CAPTCHA is off; delete it → back.
   Docroot-relative (`__DIR__`), so rule #41's renamed-docroot trap cannot arise. Cost: one
   `is_file()` stat per request on configured installs (memoized; zero on dormant installs
   where `captchaConfig()` already returned early — actually the stat precedes the settings
   read, so it is one stat always on paths that consult config; acceptable, and it is the
   price of a break-glass that works when the DB-backed config is exactly the thing that is
   wrong). Documented in `SECURITY.md` + the admin card's help text.
2. **Save-time warning (not a block):** the `save_captcha` handler warns (flash, and a
   persistent caption on the card) when the ticked set includes BOTH `login` and
   `manage_login`: "Both sign-in doors are now challenge-gated. If the provider fails and the
   grace window cannot open, recovery is the CAPTCHA_DISABLED break-glass file (see help)."
   Blocking the combination would be paternalistic (the grace window + kill-file make it
   survivable) — warn + document.
3. Already-authenticated admins are unaffected by design (the gate sits on login only), so an
   admin with a live session can always untick forms — the break-glass is for the cold-start
   case.

#### F. CSP — close the §0-2 gap with a mechanism

- Registry entries gain `'cspConnect' => [...]` — `[]` for turnstile/recaptcha_v2 (their
  documented posture), `['https://hcaptcha.com','https://*.hcaptcha.com']` for hcaptcha
  (its documented requirement — still to be CONFIRMED live, but now the confirmation is a
  registry-data edit, not an `index.php` hunt).
- `captchaCspOrigins()` (`captcha.php:328`) returns a third list; `index.php` appends
  `$cspCaptchaConnect` to the `connect-src` directive (`:255`) with the same
  empty-when-dormant conditional as `:243/:256`. Byte-identical CSP when dormant (P1 upheld).
- The existing guard's CSP-lockstep half (hostname literals banned outside `captcha.php`)
  already covers the new list with no change; extend it to assert `index.php` consumes all
  THREE lists (mutation: drop the connect-src append → red).
- **Activation runbook step (recorded here because no CI can prove it):** after pasting real
  keys on alpha, load each gated form with DevTools open and confirm zero CSP violations
  through a full solve — per provider actually chosen. This is the §0-2 verification made a
  required manual step.

### 3.5 Settings, seeds, migration (rule #19 — still zero DDL)

- New rows: `captcha_outage_strict_forms` (CSV, seed `''`), `captcha_health_state` (JSON, seed
  `''` — machine state, description says so).
- ONE migration `appWeb/.sql/migrate-captcha-outage-settings.php` (`INSERT IGNORE` both rows) +
  ONE `migration-registry.php` entry; **real probe**: both rows exist (multi-object AND — a
  partial apply never shows green). `schema.sql` seed lines byte-identical, same commit
  (`test-schema-coverage.php` enforces). Pure SQL, no docroot includes — rule #41 moot;
  `test-deploy-paths.php` covers it regardless. NOTE: the code reads both via
  `getAppSetting(key, '')` defaults, so an **un-migrated install behaves identically**
  (strict-forms empty, health state cold → first refusal probes and writes the row via the
  upsert) — the migration exists for schema.sql/description hygiene, not for correctness (P4
  discipline).

### 3.6 Dormancy / no-op proofs

- **P1 unconfigured:** `captchaConfig()` null (kill-file check adds one stat) ⇒ gates
  short-circuit as today; no probe can ever run (every health entry point sits behind the
  config check); hint endpoint 400s; CSP byte-identical; card shows Dormant as today.
- **P2 configured, all healthy:** the refusal path is the ONLY consumer of health state, and a
  fresh-or-stale UP state admits nothing — every response byte-identical to today except that a
  refused request may bear one ≤3 s probe every ≥30 s globally (only when the state was stale,
  i.e. only when refusals are happening and no real verifies are). Allowed requests: untouched.
- **P3 outage:** passive/active feeders flip state DOWN within one probe interval; non-strict
  gated forms admit (= the pre-#947 rate-limit/honeypot floor, original §1d, all intact);
  transitions logged; banner up; first healthy probe closes it. `login`+`manage_login` unlock
  themselves — D6 self-heals for genuine outages.
- **P4 un-migrated:** §3.5 note — reads default safely, upsert writes the state row on first
  need. Nothing throws under STRICT (no new columns/tables anywhere).
- **P5 DB blip:** `getAppSetting` returns defaults on DB failure (`maintenance.php:66-77`) ⇒
  strict-forms empty + health cold ⇒ the probe limiter itself fails open ⇒ worst case one probe
  per refused request during a simultaneous DB+provider incident — bounded by the curl
  timeouts; and if the DB is down the gated endpoints themselves are already failing upstream.

### 3.7 Adversarial analysis (what defeats it; what would make it wrong)

- **Inducing the window from outside:** requires making the provider unreachable *from this
  server* (DDoS Cloudflare/Google, or MITM the server's egress). Identical to the trust anchor
  the shipped verify fail-open already stands on (original §A.4) — no new attacker capability
  is minted; the window only *widens the same door during the same event*, and §0-4 shows the
  door was already effectively open to token-carrying bots then.
- **Probe as amplification:** globally limited to 1/30 s + 2 s/3 s timeouts + single-flightish;
  a flood of token-less junk buys the attacker at most 2 outbound requests a minute — less than
  the verify calls their token-carrying junk could already trigger.
- **Hint flooding:** per-IP limited, counter-only, decision-inert (guard-banned §4-G3). Worst
  case: an attacker keeps the probe *fresh* — which only makes the window's state MORE
  accurate.
- **Strict-forms bypass:** the strict list is read server-side from settings; nothing
  client-supplied selects the policy. A form in the strict CSV behaves byte-identically to
  today under every state.
- **Misconfig spoofing:** an attacker cannot make siteverify say `invalid-input-secret` — the
  secret in the request is ours, the URL is a registry constant, TLS is verified. They CAN
  farm `success:false` with *token*-side error-codes — which stay fail-closed, unchanged.
- **Kill-file exposure:** web-denied directory; empty file; presence only ever *disables* a
  security-friction feature to its pre-#947 state — an attacker who can write files into
  `includes/` has RCE-adjacent access and does not need a captcha bypass.
- **What would make it wrong:** any client-readable emission of the window state (§3.4-D's
  "NOT emitted"); an allow path keyed on the hint endpoint or any request field; a stale DOWN
  admitting without a fresh re-probe (breaks self-closing); per-request activity-log rows
  (self-DoS); a second health/probe implementation outside `captcha.php` (rule #22); weakening
  the missing-token fail-closed when the state is UP (that is not this feature).

### 3.8 The security-posture change, stated plainly

Before: enabled forms fail **closed** on missing tokens under ALL conditions, including a
provider outage the server cannot see, while failing **open** for garbage tokens during that
same outage. After: during a **server-verified** provider outage (or detected secret
misconfig), enabled non-strict forms temporarily enforce nothing beyond the pre-#947 defence
floor (rate limits, honeypot, caps — all still active), loudly (banner, audit rows, counters),
self-closing on the first healthy probe. Outside a verified outage: byte-identical enforcement
to today. Net: the deliberate availability-over-friction trade the codebase already made for
verify-time failures (`captcha.php:31-38`'s FAIL POSTURE doc-block), extended to the
widget-load failure mode with the SAME attacker-unreachability argument — plus a genuine
misconfig recovery and a genuine break-glass that did not exist at all.

---

## 4. CI guards (tree-derived, mutation-proven — rule #34)

Extend the two existing guards; one new Swift test bundle (§2.8). Every mutation proof is run
break→red→restore and recorded in the commit body.

- **G1 — `tests/php/test-captcha-gate.php` (extend):**
  - Pure truth table for `captchaOutageDecision()`: (up,fresh)→enforce; (down,fresh)→admit;
    (down,STALE)→enforce (the self-closing property); (misconfig,fresh)→admit; malformed/empty
    state→enforce (fail-safe cold start). Mutation: remove the freshness check → the
    down+stale row goes red.
  - Registry: every selectable entry now also carries `cspConnect` (array) +
    `secretErrorCodes` (non-empty array) — derived by iterating the registry, never a typed
    provider list (the guard's existing Section-2 discipline).
  - Gate order (comment-stripped source window over `captchaGate`): the strict-forms check
    precedes `captchaHealthEnsureFresh`, which precedes `captchaOutageDecision`, and the
    dormant short-circuit precedes all three. Mutation: swap ensure-fresh after the decision →
    red.
  - Kill-file: `captchaConfig`'s source contains the `CAPTCHA_DISABLED` `is_file` check before
    its `getAppSetting` reads; functional half: create the file in a temp-shadowed include…
    NOT feasible against the real file safely — instead assert via a small pure seam:
    extract `captchaKillFilePresent(): bool` and truth-table it with an injected path
    (the pure-core pattern), and source-assert `captchaConfig` calls it first. Mutation:
    reorder → red.
  - `index.php` consumes all three CSP lists (`script`/`frame`/`connect` each appended into the
    matching directive). Mutation: delete the connect-src append → red.
  - 403 body: the exact refusal array now includes `code` (§2.1). Mutation: drop the key → red.
  - `app_status` emit contains NO health/degraded key: comment-stripped scan of the
    `$captchaStatusPayload` construction site for any read of `captcha_health_state` /
    `captchaHealthState(` — zero hits. Mutation: emit `degraded` → red.
- **G2 — `tests/test-captcha-client.js` (extend):** the widget module's failure paths call
  `apiFetch` with `action=captcha_widget_health` (and remain the ONLY caller of that action
  under `js/` — tree-derived); the call sites contain no read of the response beyond fire-and-
  forget (no `.then(` branching into mount/allow logic — scoped to the new call expressions,
  narrow per rule #34's false-positive warning). Mutation: make `mountCaptcha` retry-mount on a
  hint response → red.
- **G3 — decision-inertness of the hint (in G1):** comment-stripped scan of the
  `captcha_widget_health` case body in `api.php`: it contains NO call to `captchaGateDecision`,
  NO write to `captcha_enabled_forms`, and its only state effect funnels through the ONE
  `captchaHealthHintRecord()` helper; plus tree-wide: `captchaOutageDecision(` has exactly ONE
  call site (`captchaGate`) — a second consumer (e.g. the hint handler admitting) goes red.
  Mutation: call the decision from the hint case → red.
- **G4 — pairing/schema/registry (existing mechanisms, cited not duplicated):**
  `test-rate-limit-pairing.php` (the `captcha_probe` + hint-endpoint action pairs),
  `test-schema-coverage.php` + `test-migration-registry.php` (the §3.5 rows + real probe),
  `test-deploy-paths.php` (migration include hygiene).
- **G5 — Swift (`IHAPITests` — §2.8):** decode fixtures, `classifyMachineRefusal` truth table,
  endpoint-body byte-pins, taxonomy properties. Mutation proofs listed per test in §2.8.
  Cross-language lockstep = committed fixtures generated from the PHP emit/refusal shapes,
  with G1 pinning the PHP side of each shape (the two guards meet at the fixture bytes).

---

## 5. Ordered atomic commits (one branch, one PR to alpha — standing-directives §2)

Native (N) and fallback (F) interleave safely; each commit is independently revertable.

- **N1 — `feat(captcha): machine code on the 403 refusal + api-docs corrections`**
  §2.1 (`'code'` key), the §0-3 api-docs emit-condition fix, the 403 `code` documentation.
  Guard: G1's exact-array update (mutation-proven). Web behaviour: additive key only.
- **N2 — `feat(apple): app_status endpoint + CaptchaConfig decode (dormant-tolerant)`**
  §2.2 + §2.3-1. Tests: decode fixtures incl. the dormant-absent case.
- **N3 — `feat(apple): APIError.captchaRequired + body-aware 403 classification`**
  §2.3-2/3. Compiler forces the switch sweep; tests per §2.8.
- **N4 — `feat(apple): captcha_token plumbing + the provider seam (Null provider)`**
  §2.3-4 + §2.4 + §2.5 (registry ships empty; LoginView wiring behind `captchaRequired()`;
  reset-on-any-failed-submit). Byte-pin test proves nil-token bodies unchanged.
- **F1 — `feat(captcha): health state core + probe (pure decision, no consumers)`**
  §3.4-A helpers + `captchaOutageDecision()` + constants + the §3.5 migration/seeds. Zero
  behaviour change (nothing calls it). Guards: G1 truth-table + G4 registry/schema halves.
- **F2 — `feat(captcha): grace window in captchaGate + passive verify feeders + misconfig detection`**
  §3.4-B/C (+ `secretErrorCodes` registry data). Guards: G1 order/registry halves. This is the
  behaviour commit — its body carries the §3.6 proof run (P1/P2 byte-identical evidence).
- **F3 — `feat(captcha): client hint endpoint + widget wiring + connect-src mechanism`**
  §3.4-D hint + §3.4-F CSP (registry `cspConnect`, `index.php` third append). Guards: G2, G3,
  G1 CSP half, G4 pairing.
- **F4 — `feat(captcha): admin visibility + break-glass`**
  §3.4-D card strip/banner/probe-now + §3.4-E kill-file + save-time warning. Guard: G1
  kill-file half. SECURITY.md break-glass runbook in the same commit (it documents this code).
- **C-D — `docs(captcha): wiki + changelog + .claude close-out`**
  Standing-tasks sweep; the §3.4-F activation runbook lands in the wiki deploy/security page.

Every commit: `php -l` / `node --check` / `swift build`+`swift test` (Apple commits) / full
guard run / break-red-restore log per rule #34.

---

## 6. Owner decisions

### D-F1 — Adopt the grace-window fallback, and its default per-form policy

1. **The decision:** when the server itself verifies the CAPTCHA provider is unreachable (or
   the stored secret is being rejected), should gated forms temporarily admit token-less
   requests — falling back to the pre-CAPTCHA rate-limit/honeypot floor — and if so, should
   that apply to ALL ticked forms by default, or should some start in the always-strict list?
2. **Why it needs deciding:** it is a security-posture trade (availability during outages vs a
   wider window for bots during those same outages) — a values call the code cannot make. The
   technical asymmetry that informs it: during an outage today, a bot sending any garbage token
   ALREADY passes (the shipped verify fail-open), while legitimate users with no token are the
   only ones locked out — so staying closed protects almost nothing (§0-4).
3. **Options:**

| Option | Outage behaviour | Consequence |
|---|---|---|
| **A — window, all forms degrade (default `''` strict list)** | every ticked form admits during a verified outage | congregation never locked out; bots gain what they effectively already had; loud + self-closing |
| **B — window, `registration` seeded strict** | as A, but registration keeps failing closed | sign-UP breaks during outages (arguably acceptable — try later), sign-IN never does; the spam-magnet form stays sealed |
| **C — no window (do nothing)** | today's behaviour | any real provider outage locks every legitimate user out of every ticked form until an admin intervenes — with, today, no banner telling them why, and (if both login forms are ticked) no way in but SQL |

4. **Recommendation: A.** The floor (per-IP/per-account budgets, honeypot, daily caps) is
   intact during the window; the marginal outage-time exposure over today is only
   the bots too lazy to send a dummy token; and B's registration carve-out can be added by the
   owner in ten seconds on the card if registration spam during outages ever materialises —
   whereas starting strict costs real users immediately. (B is the defensible runner-up if the
   owner weighs registration spam heavily.)
5. **What I need back:** "A", "B", or "C". **Blocks:** F2 only (F1's dormant core and all of
   Part 1 proceed regardless).

### D-F2 — The CAPTCHA_DISABLED break-glass file

1. **The decision:** ship the SFTP-droppable kill-file that fully disables CAPTCHA while
   present (§3.4-E), yes or no.
2. **Why it needs deciding:** it is a deliberate new bypass primitive — anyone with
   write access to `includes/` can disable the feature. (They can already disable it by editing
   any PHP file, so the marginal exposure is nil — but adding an *intentional* switch is an
   owner-level call.)
3. **Options:** ship it (30-second recovery over the SFTP access every deploy already uses; no
   SQL, no working login needed) / don't (recovery from a misconfig-or-outage lockout with both
   login forms ticked remains hand-edited SQL). 4. **Recommendation: ship it** — the D6 trap
   analysis shows the failure mode it rescues is otherwise unrecoverable without DB access, and
   the file only ever *downgrades to the pre-#947 posture*. 5. **What I need back:** "yes" or
   "no". **Blocks:** F4's kill-file half only.

### D-N1 — Build the native scaffold now, ahead of the provider choice

1. **The decision:** land §2's provider-agnostic Apple work now, or park it until D1 (provider)
   is answered.
2. **Why it needs deciding:** it is scheduling/effort, not architecture — the scaffold is
   provider-independent by construction, but it is real work that ships inert until a provider
   is picked AND a shared form is ticked.
3. **Options:** now (the moment D1 is answered, native support is one conformance + one
   registration + privacy manifest — days, not weeks; and the app gains `app_status`
   consumption it will want anyway) / later (no dead code, but D2's shared-form activation
   stays fully blocked on a bigger native task). 4. **Recommendation: now** — it is the
   difference between D1 unblocking activation quickly and D1 starting a project.
5. **What I need back:** "now" or "later". **Blocks:** nothing else — Part 2 is independent.

### D-N2 — tvOS/watchOS posture if `login`/`email_login` are ever gated (informational)

No web-based challenge can render on tvOS/watchOS (no WKWebView, no provider SDKs — §2.6).
Gating the shared sign-in forms breaks sign-in on those targets outright; the scaffold makes
the failure a clean, worded message rather than a mystery, but cannot fix it. Nothing to answer
now — recorded so the D2 activation decision is made knowing it. **Blocks:** nothing.

*(D1 — the provider choice itself — remains open exactly as framed in
`.claude/account-security-1027-947-340-plan.md` §8; not re-asked here. Non-blocking for
everything in this plan.)*

---

## 7. Issue actions at implementation time (file BEFORE the closing commits — repo rule)

- **NEW (epic):** "CAPTCHA: native scaffold + provider-outage fallback" — parent for the below,
  citing this plan.
- **NEW:** native provider-agnostic scaffold (closes at N4; references D-N1/D-N2 and original
  D1/D3; supersedes the original plan's §9(b) "native apps adopt CAPTCHA widget support" note —
  file that one properly now and scope it to the post-D1 conformance).
- **NEW:** provider-outage grace window + admin visibility + break-glass (closes at F4;
  references D-F1/D-F2).
- **NEW (`for consideration`):** gate the native `song_request` endpoint (`api.php:6518`) under
  the `song_request` form key once native widget support exists (§0-5 — today it is a
  documented CAPTCHA-free path to the same table).
- **NEW (`for consideration`):** secondary-provider auto-failover (rejected §3.3 — recorded
  with the rejection rationale so it is not re-litigated from scratch).
- **#947 / #340:** comment (both are CLOSED at C6): outage-fallback + native scaffold follow-on
  tracked at the new epic — no reopen (the original acceptance criteria did not include
  either).
- **api-docs.yaml** corrections (§0-3, §2.1) ride N1; wiki security page gains the activation
  runbook (§3.4-F) at C-D.

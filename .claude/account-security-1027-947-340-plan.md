# Account-security pack — locked implementation spec (#1027-remainder + #947 + #340)

**Fable-5 design pass, 2026-08-22.** Deep-design only — **no code changed by this pass.**
Branch at design time: `claude/ilyrics-identity-work-model` @ `5efdf84d`.
House style per `.claude/setlist-correctness-1675-plan.md`; verified against the tree, not the
issue prose (the #1662 lesson) — and it paid off again: **#1027 is mis-closed** (§1a).

| Issue | State on the tracker | Verdict after code verification | Fix (locked) |
|---|---|---|---|
| #1027 per-account login lockout | **CLOSED** 2026-08-18 ("Shipped… api.php:3210") | **PARTIAL / MIS-CLOSED** — the API half is real; the `/manage` half the issue's own scope demanded ("Apply identically in both") never landed | One shared key-derivation helper + the same `auth_login_acct` bucket in `attemptLogin()` (§3.2); reopen, then re-close at C1 with evidence |
| #947 CAPTCHA on login/signup/reset | OPEN | **REAL** — only the three RESERVED settings rows + a dead client emit exist; zero verification code anywhere | Dormant provider-registry CAPTCHA core + per-form gates + admin card (§3.4-3.9) |
| #340 bot protection on public forms | OPEN | **REAL** — same remainder as #947 viewed from the public-forms side | Same one mechanism; `song_request` is a first-class form key (§3.1) |
| (residual, found by this pass) | untracked | `auth_email_login_request` has **no per-IP budget at all** (§1e) | 15/hr IP bucket mirroring #1028 (§3.3); issue to file at implementation |

---

## 1. Verified current state (file:line; paths under `appWeb/public_html/` unless noted)

### 1a. #1027 — per-account login lockout: DONE on api.php, NOT DONE on /manage → mis-closed

The issue's scope section names **two** files: "`api.php` (auth_login, ~1386)" **and**
"`manage/includes/auth.php` (attemptLogin)", with the instruction "Apply identically in both".

**DONE — the public API half** (commit trail `2a8cf810` → close comment citing `api.php:3210`):

- `api.php:3118` `case 'auth_login'`: per-IP counter 10 fails/15 min at `:3139-3160` (#290),
  then the **per-account bucket** — key `'acct:' . substr(hash('sha256', $username), 0, 40)`
  minted at `:3220`, checked via the shared limiter `checkRateLimit('auth_login_acct',
  $loginAcctKey, 20, 900, false)` at `:3221`, hit recorded inside the shared
  unknown-user/wrong-password failure branch at `:3280` (so the counter fills identically for
  real and imaginary accounts — no enumeration oracle), 429 body byte-identical to the per-IP
  one (`:3158` vs `:3231`). The design rationale block `:3162-3219` records why
  `tblUsers.LockedUntil` was deliberately **superseded** (no schema change; rides
  `idx_IpTime`; the `Username` column is overloaded with action names so a `WHERE Username=?`
  count would let `auth_apple`/`email_verify` traffic lock out real users of those names).
- Pinned by `tests/php/test-auth-rate-limit.php` (its own doc-block names #1027).

**NOT DONE — the /manage half:**

- `manage/includes/auth.php:560-648` `attemptLogin()` has **only** the hand-rolled per-IP
  `SELECT COUNT(*)` (`:571-587`, 10 fails/15 min, #1386) + the failure INSERT (`:596-603`).
  Grep for `checkRateLimit`, `auth_login_acct` or `acct:` in that file: **zero hits**
  (verified this pass). `attemptLogin()` is live, not dead code — `manage/login.php:103`
  calls it on every admin login POST.
- So the exact attack #1027 was filed to close — a botnet giving each node ≤9 tries while
  grinding one admin password — **still works against `/manage/login.php`**, the surface the
  file's own #1386 comment calls "the highest-value accounts: admin / global_admin".
- The issue's 2026-08-01 comment documented precisely this gap and said "keeping open"; the
  2026-08-18 close comment cites only `api.php:3210`. **Verdict: mis-closed** (the #1662
  shape). §3.2 is the remainder; C1 re-closes it with real evidence.

### 1b. #947 — CAPTCHA (login/signup/reset, admin-configurable): REAL, barely started

Per ask:

| #947 ask | State | Evidence |
|---|---|---|
| Settings storage (`captcha_provider`/`site_key`/`secret_key`) | **DONE (as dormant seeds)** | `appWeb/.sql/schema.sql:2091-2093` — all three seeded with "RESERVED — not wired yet (#1685)" descriptions; existing installs reworded by the `'fix-captcha-ads-descriptions'` registry entry (`manage/includes/migration-registry.php:3132`) |
| `captcha_enabled_forms` storage | **NOT DONE** | zero hits tree-wide |
| Secret encrypted at rest | **NOT DONE** | `captcha_secret_key` is **absent** from `secretSettingKeys()` (`includes/secret_crypto.php:430-483`; list ends at `cuercode_api_key`) |
| `includes/captcha.php` helper (`captchaWidgetHtml`/`captchaVerify`) | **NOT DONE** | no such file; `verifyCaptcha|captchaVerify` = zero hits tree-wide (re-verified this pass) |
| Widget on login/signup/reset forms | **NOT DONE** | the public auth UI is the JS-built `#user-auth-modal` (`js/modules/user-auth.js:1606+`); no captcha code in any module |
| Server-side token verification | **NOT DONE** | nothing reads `captcha_site_key`/`captcha_secret_key` anywhere |
| Admin page to pick provider/keys/forms | **NOT DONE** | `manage/configuration.php:9` still lists "CAPTCHA provider" among *future* scaffolded sections |
| Dynamic CSP per provider | **NOT DONE** | `index.php:215-243` CSP has no captcha origins (correct while dormant) |
| The one thing that DID ship | ⚠ hazard | `captcha_provider` is emitted to every client (`api.php:6997` `$publicKeys`, `:7055` `'captchaProvider' => … ?? 'none'`) with **no server-side verification behind it** — the #340 2026-08-01 comment's "apparently-present control" finding; #1685 tracks the dead chain |

### 1c. #340 — bot protection on public forms: REAL, same remainder

#340's scope is registration, password reset, song requests, contact forms — **"NOT on login
forms"** (directly contradicting #947's title; surfaced as owner decision D2, §8). Per ask:
config keys = seeded-reserved (above); `verifyCaptcha()` helper, frontend widget loading,
server verification = all absent (same evidence as 1b). The song-request form meanwhile has
its own layered defences (1d) — CAPTCHA is additive, not the first line.

### 1d. What is ALREADY DONE — the abuse-protection baseline (mitigation-aware, so we design only the gap)

This is the #1906-era floor the CAPTCHA layer sits on. **Nothing below is removed or weakened
by this plan** (defence stack, not replacement):

- `auth_register` (`api.php:2794`): 20/hr per-IP, action-scoped read `:2851-2864` **and** the
  formerly-missing write half `:2868-2875` (#1906 fixed the dead limiter); registration-mode
  gate `:2821-2828`; email-claim block (#1635) `:2960-2970`.
- `auth_login`: per-IP + per-account as in 1a.
- `auth_forgot_password` (`api.php:4420`): #1028 dual budget — 15/hr per IP + 5/hr per
  identifier (`:4469-4493`), spend-only-when-allowed, pure `apiForgotPasswordDecision()`
  keeping the anti-enumeration byte-identical 200 (`:4495-4514`, guard-pinned).
- `auth_email_login_verify` (`api.php:4803`): code mode throttled per-IP 10/15 min
  (`:4826-4845`, #1386) **plus** the per-email `'evc:'+sha256` bucket 10/15 min across all IPs
  (`:4847-4867`, recorded `:4885-4896` — #1906's distributed-code-grind fix, landed despite
  the epic's stale checkbox).
- `song_request_submit` (`api.php:12398`): honeypot field `website` with silent fake success
  (`:12442-12446`; fragment input `includes/pages/request-a-song.php:194`, client sends it
  untrimmed `js/modules/request-a-song.js:575`); operator kill-switch `:12482-12485`; per-IP
  daily cap from `max_song_requests_per_day` clamped 1..1000 (`:12496-12510`). The Apple-app
  sibling `song_request` (`api.php:6352`) has the same daily-cap read.
- `/manage`: per-IP login lockout (1a), admin CSP `manage/includes/auth.php:90-110` (#1906),
  session-fixation `session_regenerate_id` on the adoption path `manage/includes/auth.php:256-258`
  (#1906), login-CSRF same-origin check (`manage/login.php` `_loginRequestIsSameOrigin()`, rule #29).
- Headers: `.htaccess:412-434` `Header always set` security block; `.user.ini` `expose_php=Off`
  + the `X-Powered-By` rebrand (`.htaccess:400-409`) — #1906.
- Infrastructure: `includes/rate_limit.php` — `checkRateLimit()` `:110` (fail-open `:157-165`),
  `recordRateLimitHit()` `:185`, `rateLimitKey()` `:62`; counter table `tblLoginAttempts`
  pruned at 30 days (`api.php:11020` maintenance action + `appWeb/.sql/cleanup.php:135`).
  Read-side throttling: `includes/read_rate_limit.php` (`enforceReadRateLimit` `:146`,
  `…Keyed` `:253`) now covers og-image/qr/org-logo/song-media/audio-media/api (#1906) —
  **its `tblReadRateLimit` no-prune leak is #1922; referenced here, not duplicated.**

### 1e. Residual gaps this pass verified (beyond the three issues' prose)

1. **`auth_email_login_request` has NO per-IP budget.** The whole case (`api.php:4669-4791`)
   was read: the only throttle is the per-EMAIL 5/hr inside `generateEmailLoginToken()`
   (`manage/includes/auth.php:1859-1870`; `$clientIp` is only stored on the token row,
   `:1937-1941`). One IP can therefore trigger real SMTP sends to an unbounded number of
   *distinct* addresses (5 per victim per hour × any number of victims) — inbox-spray abuse,
   paid-sender cost, and sender-reputation damage. §3.3 closes it.
2. **Cross-action per-IP counter cross-talk** (adjacent finding — issue to file, NOT fixed
   here): both login per-IP counters (`api.php:3140-3142`, `manage/includes/auth.php:574-576`)
   count `Success = 0` rows for the IP **with no `Username` filter**, while `auth_register`
   (`:2870`) and `email_verify` (`:4887`) record `Success = 0` rows under the raw IP. Ten
   registration attempts in 15 minutes therefore trip the login lockout for that IP (a church
   office bulk-registering members locks itself out of sign-in). Conservative-safe direction,
   but a real UX trap; fixing it changes lockout semantics → out of scope, tracked.
3. `auth_reset_password` (`api.php:4565-4592`) has no limiter — acceptable: the token is
   high-entropy (48-hex) and unguessable; no work item.

**Native-client reality (load-bearing for the whole design):** the Apple app calls
`auth_login`, `auth_register`, `auth_forgot_password` and the email-login pair
(`appApple/Packages/iHymnsKit/Sources/IHAuth/SessionController.swift`,
`IHAPI/APIClient+Auth.swift`, `IHAPI/AuthEndpoints.swift`) and `song_request` (NOT
`song_request_submit` — that one is web-only, `request-a-song.js:75`). Any form whose CAPTCHA
is enabled is enforced for **every** client (a client-type carve-out would be the bypass), so
enabling `registration`/`login`/`password_reset`/`email_login` breaks native flows until the
native apps grow widget support. This drives the default-forms decision (D2) and the
native-adoption follow-up issue (§9).

---

## 2. Design principles (inherited, not re-argued)

- **Dormant until configured; byte-identical when dormant** (rule #28's posture): with no
  provider + keys, every touched endpoint, the CSP, the `app_status` payload and every page
  behave exactly as today. Proofs in §4.
- **Fail-open on infrastructure, fail-closed on the challenge**: a provider outage or DB blip
  must never lock a congregation out on Sunday morning (the `checkRateLimit()` posture);
  a missing/invalid token on an enabled form is a loud, branchable refusal.
- **The secret never reaches a browser** (rule #38's CueRCode custody):
  `captcha_secret_key` is server-proxied only, registered in `secretSettingKeys()`,
  encrypted at rest from its first save, verified by the existing
  `tests/php/test-secret-crypto.php` lockstep.
- **One registry, one verify seam, one gate function, one body key** (rule #22): providers
  are data in `captchaProviders()`; enforcement sites call the ONE `captchaGate()`;
  the client learns provider/site-key/script-URL from the server emit — **no PHP↔JS provider
  table to drift** (rule #35: the response is the mechanism).
- **Status + machine-readable `reason` is the contract** (rule #35): clients branch on
  `403` + `reason:'captcha_required'`, never on prose.
- **Growable vocabularies are VARCHAR/CSV app-validated against a central list, never
  ENUM/SET** (rule #20 — this overrides #947's proposed `ENUM`+`SET` columns; and since
  `tblAppSettings` is key-value, the feature needs **zero DDL**: new *rows*, not columns.
  `tblSiteSettings`, which #947 names, does not exist).
- **Rate limits stay** — CAPTCHA raises the attacker's cost; it does not replace budgets
  (token-farming services exist, §5 A.1).

---

## 3. Locked design

### 3.1 Vocabulary — form keys and the wire `reason` (rule #20/#35 discipline)

**Form keys** — `captchaFormKeys()` in `includes/captcha.php`, the ONE list:

| Key | Endpoint(s) gated | Native impact if enabled | In default seed? |
|---|---|---|---|
| `registration` | `api.php` `auth_register` | breaks Apple/Android signup | no |
| `login` | `api.php` `auth_login` | breaks native sign-in | no (the #340-vs-#947 conflict → D2) |
| `password_reset` | `api.php` `auth_forgot_password` | breaks native reset | no |
| `email_login` | `api.php` `auth_email_login_request` | breaks native magic-link | no |
| `song_request` | `api.php` `song_request_submit` | none (web-only endpoint) | no (seed is `''` — D2) |
| `manage_login` | `manage/login.php` POST | none | no |

No `contact` / `share_setlist` keys: no such forms exist (verified; rule #44 — collect
nothing the app acts on). A future form is one line here + one gate + the tree-derived guard
(§6.2) demands its enforcement site.

**Wire vocabulary** (emitted beside human `error` prose):

| Where | HTTP | `reason` | Notes |
|---|---|---|---|
| any gated endpoint, form enabled, token missing/invalid | 403 | `captcha_required` | ONE reason for both cases — distinguishing them tells a bot which failure it had; client behaviour is identical (render/reset widget, retry) |
| provider unreachable at verify time | — | (request proceeds) | fail-open; `error_log` only, never a client-visible signal |
| NEW per-IP bucket on `auth_email_login_request` | 429 | (generic body) | mirrors `auth_forgot_password`'s visible per-IP arm (#1028); the per-identifier silent-200 arm is untouched |

The token travels as body key **`captcha_token`** on every gated endpoint —
`IHYMNS_CAPTCHA_BODY_KEY` in `includes/captcha.php`, mirrored once in the JS module, PHP↔JS
lockstep-guarded (§6.3). `manage/login.php` reads the provider's own POST field name (the
widget injects it into the plain form) — see §3.6.

### 3.2 #1027 remainder — the /manage per-account bucket, drift-proofed

**One shared derivation** in `includes/rate_limit.php` (beside `rateLimitKey()`):

```php
const IHYMNS_AUTH_ACCT_ACTION = 'auth_login_acct';
const IHYMNS_AUTH_ACCT_MAX    = 20;   /* 2× the per-IP 10 — same window, they compose */
const IHYMNS_AUTH_ACCT_WINDOW = 900;

/* Canonical per-account bucket key. The FOLD LIVES HERE, deliberately:
   api.php normalises with mb_strtolower(trim()) and attemptLogin() with
   strtolower(trim()) — identical for the [a-z0-9_.\-] registered charset but
   NOT for arbitrary submitted bytes, and a submitted username is arbitrary.
   Two surfaces deriving the key from differently-folded inputs would fill
   two different buckets for the same target account. One function, one fold. */
function authLoginAcctKey(string $submittedUsername): string
{
    return 'acct:' . substr(hash('sha256', mb_strtolower(trim($submittedUsername))), 0, 40);
}
```

- `api.php:3220` swaps its inline derivation for `authLoginAcctKey($username)` and the
  literals for the constants — **behaviour-identical** ($username is already
  mb_strtolower-trimmed there; the guard asserts string equality, §6.1).
- `manage/includes/auth.php::attemptLogin()`: `require_once … includes/rate_limit.php`
  (require_once dedupes — api.php:4426 already pulls this file into API requests), then
  immediately after the existing per-IP block (`:587`), **before the user fetch and
  `password_verify()`**:

```php
/* #1027 (the /manage half) — SAME action name + SAME key derivation as
   api.php's auth_login, so the two login surfaces share ONE 20/15-min
   per-account budget: an attacker splitting guesses across /api and /manage
   draws down a single allowance instead of two. */
$acctKey = authLoginAcctKey($normalised);
if (!checkRateLimit(IHYMNS_AUTH_ACCT_ACTION, $acctKey, IHYMNS_AUTH_ACCT_MAX, IHYMNS_AUTH_ACCT_WINDOW, false)) {
    logActivity('auth.login', 'user', $normalised,
        ['reason' => 'rate_limited_account'], 'failure');
    return null;   /* caller shows the same generic invalid-credentials error */
}
```

and inside the existing shared failure branch (`:595-620`, which fires identically for
unknown-user and wrong-password — preserving the no-oracle property):
`recordRateLimitHit(IHYMNS_AUTH_ACCT_ACTION, $acctKey, false);`.

Returning `null` (not a distinct error) keeps `/manage/login.php`'s user-visible response
byte-identical to a wrong password — matching the API's byte-identical-429 doctrine one
level up (here the surface shows one generic failure for everything; the audit log carries
the distinct reason). Deliberately NOT folded: the file's inline per-IP `SELECT COUNT(*)` —
folding it into `checkRateLimit('auth_login', …)` would add a `Username` filter and change
which rows count (the 1e-2 cross-talk); that is its own tracked decision, not a drive-by.

### 3.3 Per-IP budget on `auth_email_login_request` (residual, §1e-1)

In the case body, after the email-format validation (`:4695-4698`), before
`generateEmailLoginToken()`:

```php
/* Flood control (mirrors #1028's IP arm on auth_forgot_password): 15/hr per
   address. Spend-only-when-allowed (the house pattern); FAIL-OPEN via the
   shared limiter. The per-IDENTIFIER cap (5/hr per email) stays inside
   generateEmailLoginToken() and stays a silent generic 200 — this bucket is
   keyed purely on the caller's own address, so a visible 429 leaks nothing. */
$reqIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if ($reqIp !== '' && !checkRateLimit('auth_email_login_request_ip', $reqIp, 15, 3600, false)) {
    logActivity('auth.login_email_request', '', '', ['reason' => 'rate_limited_ip'], 'failure');
    sendJson(['error' => 'Too many requests. Please try again later.'], 429);
    break;
}
if ($reqIp !== '') { recordRateLimitHit('auth_email_login_request_ip', $reqIp); }
```

`tests/php/test-rate-limit-pairing.php` (existing, tree-derived) automatically demands the
check/record pairing for the new action name — the rule-#35 mechanism already exists.

### 3.4 The CAPTCHA core — `includes/captcha.php` (NEW, the ONE module)

Modelled on `includes/cuercode_client.php` (the rule-#38 reference: settings-driven config,
dormant-until-keyed, hardened outbound call, null-on-failure).

**Provider registry** — `captchaProviders(): array`, the ONE table (a new provider is one
entry; nothing else in PHP or JS names a provider):

```php
'turnstile' => [
    'label'        => 'Cloudflare Turnstile',
    'script'       => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
    'verify'       => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    'cspScript'    => ['https://challenges.cloudflare.com'],
    'cspFrame'     => ['https://challenges.cloudflare.com'],
    'renderGlobal' => 'turnstile',    /* window.<g>.render/getResponse/reset */
    'selectable'   => true,
],
'hcaptcha'     => [ …js.hcaptcha.com/1/api.js, api.hcaptcha.com/siteverify,
                    csp: https://hcaptcha.com + https://*.hcaptcha.com (provider-documented
                    requirement — the one wildcard, noted in §5 A.7),
                    renderGlobal 'hcaptcha', selectable true ],
'recaptcha_v2' => [ …www.google.com/recaptcha/api.js, www.google.com/recaptcha/api/siteverify,
                    csp script: https://www.google.com https://www.gstatic.com,
                    frame: https://www.google.com, renderGlobal 'grecaptcha',
                    selectable true ],
'recaptcha_v3' => [ 'selectable' => false, … ]   /* reserved: score-based, different flow —
                                                    picking it is refused at save + at read */
```

Turnstile, hCaptcha and reCAPTCHA v2 share one wire shape — `POST verify-URL` with
`secret`,`response`,`remoteip` → JSON `{"success": bool}` — and one browser API shape
(`window.<g>.render(el, {sitekey, callback})` / `.getResponse(id)` / `.reset(id)`), which is
what makes a single seam honest rather than aspirational. This satisfies #947's "at least
Turnstile + hCaptcha; reCAPTCHA stretch" in one pass. v3 stays a reserved key (rule #20:
adding it later is registry data + one scoring branch, no schema).

**Pure, DB-free core** (the `apiForgotPasswordDecision()` testing pattern):

```php
function captchaResolveConfig(?string $provider, ?string $siteKey, ?string $secretKey): ?array
/* null unless $provider is a SELECTABLE registry key AND both keys are non-empty.
   'none'/''/unknown/reserved → null. Returns the registry entry + keys. */

function captchaParseForms(?string $csv): array
/* CSV → array, trimmed, deduped, intersected with captchaFormKeys(); unknown
   keys DROPPED (a typo must fail closed-to-disabled, never fatal). */

function captchaGateDecision(?array $config, array $enabledForms, string $form, bool $tokenOk): ?array
/* null = allowed. Refusal = ['error' => 'Please complete the verification
   challenge and try again.', 'reason' => 'captcha_required'] for the caller
   to send as 403. SIGNATURE PROPERTY (the #1028 reflection trick): no
   account/identity parameter exists, so account existence CANNOT influence
   the refusal — enumeration safety by construction, asserted via Reflection
   in the guard (§6.1). */
```

**Thin DB wrappers**: `captchaConfig(): ?array` (reads the three `tblAppSettings` keys via
`getAppSetting()` — transparent secret decrypt, the `cuercode_client.php:43` custody),
`captchaEnabledForForm(string $form): bool`, `captchaCspOrigins(): array{script:[],frame:[]}`
(both empty-handed when unconfigured), `captchaClientConfig(): ?array` (the app_status
object: provider, siteKey, scriptUrl, renderGlobal, forms — **never** the secret).

**The verify seam** — `captchaVerifyToken(?string $token, string $clientIp): bool`:

- empty/overlong (>2048 bytes) token → `false` immediately (no outbound call for garbage).
- cURL POST to the registry `verify` URL — a **constant from PHP source**, never
  user-influenced (SSRF-safe by construction, unlike CueRCode's admin-set base URL which
  needed host-binding); `CURLOPT_CONNECTTIMEOUT 3` / `TIMEOUT 5`, no redirects, SSL verify
  on, response size-capped (64 KiB write-callback abort — the `cuercode_client.php` shape);
  fields `secret`, `response`, `remoteip`.
- transport failure / non-200 / unparsable JSON → **`true` + `error_log`** — FAIL-OPEN
  (attacker-unreachable condition: only server↔provider connectivity produces it, §5 A.4).
- parsed `{"success":true}` → `true`; `{"success":false}` → `false`. **Single-use is the
  provider's contract**: all three providers consume a token at first siteverify, so a
  replayed token verifies `false` — #947's "token not reusable" criterion with no local state.

**The ONE gate** — `captchaGate(string $form, ?string $token): ?array` — resolves
config + forms once, short-circuits to `null` **before any network call** when the form is
not enabled, else calls the verify seam and feeds `captchaGateDecision()`. Every enforcement
site is three lines; the 403 body cannot drift because only this function builds it.

**Server-rendered widget** — `captchaWidgetHtml(string $form): string` — returns `''` when
the form is disabled, else the provider div + script tag from the registry. Sole consumer:
`manage/login.php` (a full server-rendered page, NOT an SPA fragment — rule #30 untouched;
the `/manage` CSP at `manage/includes/auth.php:110` sets no `script-src`, so no CSP change is
needed there).

### 3.5 Settings, seeds, migration, secret custody (rule #19 — zero DDL)

- **New `tblAppSettings` row**: `captcha_enabled_forms` (CSV of form keys, seed `''` — D2).
- **Description un-reserve**: the three RESERVED texts (`schema.sql:2091-2093`) are rewritten
  to describe the now-real wiring ("Verified server-side by includes/captcha.php (#947/#340);
  dormant until a provider and both keys are configured on /manage/configuration").
- **One migration** `appWeb/.sql/migrate-wire-captcha-settings.php`: `INSERT IGNORE` the new
  row + idempotent `UPDATE` of the three descriptions. **One** `migration-registry.php` entry
  (the four dashboard structures derive from it); **real probe**: `captcha_enabled_forms` row
  exists **AND** the `captcha_provider` description no longer contains `'RESERVED'`
  (multi-object OR-probe so a partial apply never shows green). `schema.sql` mirror
  byte-identical, same commit (`test-schema-coverage.php` enforces). The script needs no
  docroot includes — pure SQL — so rule #41's renamed-docroot trap cannot arise; the guard
  `test-deploy-paths.php` covers it regardless.
- **Secret custody**: `'captcha_secret_key'` appended to `secretSettingKeys()`
  (`includes/secret_crypto.php:430`) in the SAME commit as the core — encrypted at rest from
  its very first save (the `cuercode_api_key` precedent verbatim; existing empty rows are
  skipped by the encrypt-in-place machinery by documented design). The existing
  `tests/php/test-secret-crypto.php` configuration-scan lockstep then REQUIRES the C6 admin
  field to be secret-flagged — the mechanism, not a comment.

### 3.6 Server enforcement points (ordering is load-bearing)

Each site: `$cg = captchaGate('<form>', $body[IHYMNS_CAPTCHA_BODY_KEY] ?? null); if ($cg !== null) { sendJson($cg, 403); break; }`

| Endpoint | Gate placement | Why exactly there |
|---|---|---|
| `auth_register` | after the IP-cap record (`:2875`) + body parse, **before** validation/uniqueness/`password_hash()` | local limiter first bounds outbound siteverify calls per IP; gate before bcrypt bounds the CPU cost; before the username/email lookups so the 403 precedes every account-shaped response |
| `auth_login` | after BOTH buckets (`:3233`), before the user fetch | a hammering IP/account is 429'd without spending a verify call; gate before `password_verify()` |
| `auth_forgot_password` | after the #1028 buckets (`:4493`), before `apiForgotPasswordDecision()`/`generatePasswordResetToken()` | budget-first; the 403 depends only on the caller's own token — account-independent, so the #898/#1028 anti-enumeration contract is untouched (§5 A.5) |
| `auth_email_login_request` | after the NEW §3.3 IP bucket, before `generateEmailLoginToken()` | same shape |
| `song_request_submit` | **after the honeypot** (`:12446`) and after the daily cap (`:12510`), before the INSERT | honeypot-first is deliberate: a honeypot-tripping bot must keep receiving the silent fake success — a 403 would tip it off AND spend a verify call; cap-first bounds verify volume |
| `manage/login.php` | after `_loginRequestIsSameOrigin()`/CSRF, before `attemptLogin()` | form-encoded page: token read from the provider's own POST field (`cf-turnstile-response` / `h-captcha-response` / `g-recaptcha-response` — the registry carries the field name); refusal re-renders the page with the same generic error styling + the widget |

No gate on `auth_reset_password` / `auth_email_login_verify` (token/code consumption —
already entropy- or bucket-protected; a CAPTCHA there punishes the legitimate second step).

### 3.7 CSP — conditional, provider-driven (the #947 "dynamic CSP" ask)

`index.php`: beside the existing Matomo conditional (`:210-213` — the established pattern),
build `$cspCaptchaScript` / `$cspCaptchaFrame` from `captchaCspOrigins()` and append to the
`script-src` (`:221`) and `frame-src` (`:234`) directives. Unconfigured ⇒ both strings are
`''` ⇒ the CSP header is **byte-identical** to today. The origins come only from the
registry — no hostname literals in `index.php` (guard §6.2 bans them; that is the
cross-file mechanism). `connect-src` needs no change for the three v1 providers (widget
XHR runs inside the provider's own frame).

### 3.8 Client — server-fed config, one widget module, status-branching

**`app_status` emit** (`api.php:6975`): when `captchaConfig()` is non-null, add ONE additive
key `captcha: {provider, siteKey, scriptUrl, renderGlobal, forms: […]}` (from
`captchaClientConfig()`). When dormant the key is **absent** — the payload stays
byte-identical (dormancy proof P1). The legacy flat `captchaProvider` emit (`:7055`) is
**unchanged** (native contract, #1685 non-goal). The secret never enters `$publicKeys`
(guard-banned, §6.2).

**`js/modules/captcha-widget.js`** (NEW — the ONE loader/mounter; rule #31 `apiFetch` for
any same-origin call; the provider script itself is a dynamic `<script src>` insert, CSP-allowed
by §3.7):

- `initCaptcha(appStatus)` at boot (from `app.js`, where `app_status` is already consumed);
  `captchaRequired(form)`; `mountCaptcha(hostEl, form)` → handle with `getToken()` /
  `reset()` built on the shared `window[renderGlobal].render/getResponse/reset` shape.
- Carries **no provider table, no hostnames, no sitekey** — everything arrives from the
  server emit (rule #35: nothing to keep in lockstep).
- Consumers:
  - `user-auth.js` — mount into the `#user-auth-modal` register / sign-in / forgot views
    when `captchaRequired()` (the modal is JS-built at `:1606+`, so this is a plain DOM
    mount, no fragment involved); attach `captcha_token` to the POST bodies
    (`:290 auth_register`, `:311 auth_login`, `:1553 auth_forgot_password`,
    `:1972 auth_email_login_request`); on `res.status === 403` with
    `data.reason === 'captcha_required'` → reset widget + inline message (never prose-match).
  - `request-a-song.js` — mount into a NEW static host `<div>` in
    `includes/pages/request-a-song.php` (markup only — rule #30; the module is already
    router-wired), attach the token to the `:75` submit, same 403 branch. The honeypot
    stays exactly as-is.
- The no-JS form fallback (#711) on `/request`: a form-encoded POST carries no captcha token
  → with `song_request` enabled it receives the 403 redirect branch
  (`$respondErr`), which surfaces the error banner. Documented consequence: enabling
  CAPTCHA on song requests ends the no-JS submission path (the widget is JS). Stated in the
  admin card's help text; acceptable because the honeypot+cap floor remains for the owner
  who values no-JS more than CAPTCHA (they simply leave the form unticked).

### 3.9 Admin UI — a card on `/manage/configuration.php` (NOT a new `/manage/security` page)

Deviation from #947's ask, with rationale: `configuration.php` is the established home for
provider integrations with secrets (Email service, IntApps, CueRCode `:1442` — including the
"(unchanged — leave blank to keep)" secret-field idiom and the configured/dormant status
badge). A parallel one-card page would be the "second home" this codebase's rules exist to
prevent. The card: provider `<select>` built from `captchaProviders()` (selectable entries +
'none'), site-key text field, secret-key password field **secret-flagged** (the
`test-secret-crypto.php` scan makes the flag mandatory), form checkboxes built from
`captchaFormKeys()` with per-form native-impact captions (D3's warning made permanent UI),
save-side validation: provider ∈ registry ∧ selectable, forms ⊆ keys. Status line mirrors
CueRCode's configured/dormant badge.

---

## 4. Dormancy / fail-open / no-op proofs

- **P1 — unconfigured (every install today):** `captcha_provider='none'` (or keys empty) ⇒
  `captchaResolveConfig()` null ⇒ `captchaEnabledForForm()` false ∀ forms ⇒ every
  `captchaGate()` returns null before any network I/O ⇒ all six handlers execute today's
  statements with today's bound values; `app_status` emits no new key ⇒ byte-identical JSON;
  `captchaCspOrigins()` empty ⇒ byte-identical CSP header; `captchaWidgetHtml()` `''` ⇒
  byte-identical `manage/login.php`; `captchaRequired()` false ⇒ zero DOM/script changes
  client-side. The only unconditional deltas in the whole pack are §3.2 (a *stricter* login
  budget on /manage — the issue's own ask) and §3.3 (a new 429 above 15 req/hr/IP on
  email-login requests).
- **P2 — configured, form not ticked:** per-form short-circuit — same as P1 for that form.
- **P3 — provider outage:** `captchaVerifyToken()` transport failure ⇒ allow + `error_log`.
  Degrades to the P1 behaviour *plus* the intact rate-limit floor (§1d) — availability wins;
  the attacker cannot induce this state from outside (§5 A.4).
- **P4 — un-migrated install (no `captcha_enabled_forms` row):** `getAppSetting()` default
  `''` ⇒ `captchaParseForms('')` = `[]` ⇒ disabled. No new tables/columns anywhere ⇒ nothing
  to throw under STRICT mysqli; the migration is additive `INSERT IGNORE` + idempotent
  description `UPDATE`s.
- **P5 — DB blip:** §3.2/§3.3 ride `checkRateLimit()`'s documented fail-open
  (`rate_limit.php:157-165`); a counter-table outage degrades to today's behaviour, never a
  lockout.

---

## 5. §A — Adversarial analysis (what defeats each control; what would make each fix wrong)

**A.1 Token farms.** Human-solver services defeat every commercial CAPTCHA at ~$1-3/1k
solves. That is why nothing in §1d is removed: the per-IP/per-account/per-identifier budgets
still cap the *rate* even of farmed-token traffic, and CAPTCHA's job is only to raise the
per-attempt cost above this app's value to a spammer. A design that swapped limits for
CAPTCHA would be strictly weaker; this one stacks.

**A.2 Token replay.** All three v1 providers consume a token at first siteverify; our verify
runs exactly once per request, before any state change. A farmed token buys ONE gated
attempt. No local replay cache to build, corrupt, or leak.

**A.3 "I'm a native app" bypass.** There is deliberately NO client-type carve-out — any
UA/header exemption would be the first thing a bot sends. The honest cost is that enabling a
shared form breaks native flows (§1e note); it is paid via owner sequencing (D2/D3), not via
a spoofable exemption.

**A.4 Abusing fail-open.** The open path triggers only on server→provider transport failure,
which an external attacker cannot induce (they can send garbage tokens — those get a
definitive `success:false` = fail-closed 403, not an outage). Residual: a genuine sustained
provider outage silently de-fangs the gate — visible in `error_log`, floor limits still
active; accepted (availability doctrine, P3).

**A.5 Enumeration.** The gate refusal is a function of (config, form, token) only —
`captchaGateDecision()` has no account/identity parameter, asserted by Reflection (§6.1), the
same signature-property trick that protects `apiForgotPasswordDecision()`. On
`auth_forgot_password` the gate sits before any account lookup; the #1028 byte-identical-200
machinery and its guard are untouched. The 403 fires identically for real and imaginary
accounts, so the new response class adds no oracle.

**A.6 Lockout-DoS via the new /manage account bucket.** Same trade as the API side
(`api.php:3206-3218`): reaching 20 needs ≥3 addresses (each address self-caps at ≤9 via the
per-IP counter — and on /manage the unfiltered per-IP counter (§1e-2) only *helps* the
defender here by filling faster), the window self-heals in 15 min, and the shared-budget
design means the two surfaces cannot be ground independently.

**A.7 CSP widening.** Provider origins enter `script-src`/`frame-src` **only when
configured** — an unconfigured install's CSP is byte-identical (P1). The one wildcard
(`https://*.hcaptcha.com`, provider-documented requirement) exists only while hCaptcha is the
active provider. Origins live in the registry alone; `index.php` cannot accumulate stale
hostnames when a provider is switched (guard §6.2).

**A.8 Third-party script on the admin login page.** `manage_login` loads a provider script
into a pre-auth page whose scripts cannot be SRI-pinned (provider-rotated bundles — the
#1587 rule targets *authenticated* admin sessions, but a compromised provider script here
could keylog an admin password). Mitigations: default-off; the D1 self-hosted option (ALTCHA)
eliminates it; the card's caption states the trade. Do NOT "fix" this by pinning a hash —
the provider rotates bundles and a stale hash becomes a dead login page (#1647's lesson).

**A.9 What would make each fix wrong.**
- Gating before the honeypot on `song_request_submit` (tips off honeypot bots + spends
  verify calls on them) — ordering is guard-asserted.
- Gating before the local buckets anywhere (turns the verify seam into an amplification
  target: every junk POST would cost us an outbound HTTPS call).
- A second key derivation for `auth_login_acct` (the exact drift that produced the #1027
  mis-close — now impossible while §6.1's single-definition assert holds).
- Mirroring the provider table into JS (lockstep rot; the server emit IS the contract).
- `captcha_secret_key` in `$publicKeys` / the client emit (guard-banned).
- A `SET`/`ENUM` column or a per-form settings column (rule #20; the CSV row needs no DDL).
- Removing or "simplifying away" any §1d limiter because "CAPTCHA covers it now" (A.1).
- Prose-matching the 403 in a client (rule #35; guards §6.3 ban it in the new branches).

---

## 6. CI guards (tree-derived, mutation-proven — rule #34)

Every guard's first run must be proven able to fail (break → red → restore, recorded in the
commit verification). Narrowness: all new prose/literal bans are scoped to the new
modules/branches, never whole-file phrase bans.

1. **`tests/php/test-auth-rate-limit.php` (extend — C1).**
   - Functional (extraction harness, no DB): `authLoginAcctKey(' UsEr ') ===
     authLoginAcctKey('user')`; equals the legacy inline derivation for an
     already-normalised input (the behaviour-identity proof for the api.php swap);
     45-char key fits `VARCHAR(45)`.
   - Structural, tree-derived: enumerate every file containing `password_verify(` under
     `api.php` + `manage/includes/` (derived, not typed); assert each login path contains
     `checkRateLimit(IHYMNS_AUTH_ACCT_ACTION` textually BEFORE its `password_verify(`, and a
     paired `recordRateLimitHit(IHYMNS_AUTH_ACCT_ACTION` inside the failure branch; assert
     exactly ONE definition of `authLoginAcctKey` and ZERO other `'acct:'` derivations
     tree-wide (comment-stripped).
   - Mutation proof: delete the manage-side check → red; re-inline the derivation in
     api.php → red.
2. **`tests/php/test-captcha-gate.php` (NEW — C3, extended C4).**
   - Functional truth table on the pure core: `captchaResolveConfig` (null for
     none/unknown/reserved/missing-either-key; array for each selectable provider with both
     keys); `captchaParseForms` (unknown dropped, `''`→`[]`, whitespace/dupes folded);
     `captchaGateDecision` (disabled→null; enabled+`$tokenOk=false`→the exact 403 array;
     enabled+ok→null). Reflection assert: `captchaGateDecision` has NO account/identity
     parameter (A.5's signature property).
   - Registry sanity: every selectable provider entry carries script/verify/csp/renderGlobal;
     every verify URL is https and matches its cspScript host (self-consistency, derived by
     iterating the registry — never a typed provider list in the test).
   - Enforcement coverage, tree-derived: derive the form keys by CALLING
     `captchaFormKeys()`; for each key assert ≥1 `captchaGate('<key>'` site exists under
     `appWeb/public_html` (comment-stripped). Mutation: remove the `song_request` gate → red.
   - Ordering (comment-stripped source windows, sized generously per the rule-#34 lesson):
     in `auth_register` the gate precedes `password_hash(`; in `auth_forgot_password` it
     precedes `generatePasswordResetToken(`; in `song_request_submit` the `$honey` check
     precedes the gate and the gate precedes `INSERT INTO tblSongRequests`; in
     `auth_email_login_request` the `auth_email_login_request_ip` bucket and the gate both
     precede `generateEmailLoginToken(`.
   - Secret custody: `in_array('captcha_secret_key', secretSettingKeys(), true)`;
     zero references to `captcha_secret_key` under `js/`, `includes/pages/`,
     `includes/partials/`, `index.php`; the `$publicKeys` array in `api.php` does not
     contain it (mutation: add it → red).
   - CSP lockstep: `index.php` contains a `captchaCspOrigins(` call; zero captcha-provider
     hostname literals (`challenges.cloudflare.com|hcaptcha.com|google.com/recaptcha`)
     outside `includes/captcha.php` across php+js (comment-stripped). Mutation: inline a
     hostname in `index.php` → red.
3. **`tests/test-captcha-client.js` (NEW — C5).**
   - Derive the consumers from the tree (files importing `captcha-widget.js`); assert each
     new failure branch reads `status === 403` and `reason === 'captcha_required'` and
     contains no regex/`includes(` match on the server sentence (scoped to the new branch
     bodies only); assert `captcha-widget.js` is the ONLY module creating a `<script>`
     element for a captcha URL, contains no hostname/sitekey literals, and defines the body
     key once; PHP↔JS body-key lockstep by reading `IHYMNS_CAPTCHA_BODY_KEY` out of
     `includes/captcha.php` and comparing (the `test-org-logo-surfaces.php` registry-lockstep
     shape). Mutation: rename the JS-side key → red.
4. **Existing mechanisms that cover this pack automatically** (cited, not duplicated):
   `test-rate-limit-pairing.php` (the §3.3 action pairing), `test-secret-crypto.php` (the C6
   secret-flag ↔ `secretSettingKeys()` lockstep), `test-schema-coverage.php` +
   `test-migration-registry.php` (the C3 seed migration + real probe),
   `test-fragment-inline-scripts.php` (the request-a-song host div stays script-free),
   `test-deploy-paths.php` (the migration's include hygiene), and the #1028 byte-equality
   asserts in `test-auth-rate-limit.php` (prove the forgot-password anti-enumeration
   contract survived the new gate — if the gate ever consults account state, those stay
   green but the Reflection assert in guard 2 goes red).

---

## 7. Commit breakdown (one branch, one PR to `alpha`, smallest-safest-first)

**C1 — `fix(auth): per-account login bucket on /manage + one shared key derivation (#1027)`**
`authLoginAcctKey()` + the three constants in `includes/rate_limit.php`; api.php swaps to the
helper (behaviour-identical, guard-proven); `attemptLogin()` gains check + record (§3.2).
Tests: guard 1. **Reopen #1027 first, close at C1** — the close comment cites this SHA, the
`manage/includes/auth.php` lines, and names the 2026-08-18 mis-close explicitly so the
tracker timeline reads true (D4).

**C2 — `fix(auth): per-IP budget on email-login requests`**
§3.3 exactly. **File the tracking issue at implementation time, before the commit** (repo
rule: issue precedes the closing commit), citing §1e-1's evidence; close it here.
`test-rate-limit-pairing.php` covers the pairing.

**C3 — `feat(captcha): dormant verification core, registry, seeds, secret custody (#947/#340 groundwork)`**
`includes/captcha.php` (§3.4); `captcha_secret_key` → `secretSettingKeys()`;
`migrate-wire-captcha-settings.php` + registry entry + real probe + `schema.sql` mirror
(§3.5). Tests: guard 2's functional/registry/custody halves. Zero behaviour change anywhere
(P1) — the safest possible landing for the largest new file.

**C4 — `feat(captcha): server gates, 403 vocabulary, conditional CSP, app_status emit`**
The six gates (§3.6), the `captcha` client-config emit (§3.8), the CSP conditionals (§3.7).
Tests: guard 2's ordering/coverage/CSP halves. Still dormant end-to-end (no UI can set the
keys yet; a hand-edited DB row would already enforce correctly — which is precisely what the
alpha verification pass will do).

**C5 — `feat(captcha): client widget module + form wiring`**
`js/modules/captcha-widget.js`; `user-auth.js` + `request-a-song.js` wiring + the fragment
host div; `manage/login.php` widget + POST handling. Tests: guard 3. Server (C4) and client
(C5) are separable — a C4-only deploy 403s an enabled form loudly rather than silently, and
nothing is enabled yet — so they stay two revertable commits.

**C6 — `feat(captcha): admin configuration card (#947, #340)`**
The `configuration.php` card (§3.9). `test-secret-crypto.php` now enforces the secret flag.
**Closes #947** (acceptance sweep in the close comment: Turnstile+hCaptcha+reCAPTCHA-v2 ✔,
admin-switchable ✔, failure rejected+logged ✔, token single-use via provider ✔, graceful
'none' ✔, dynamic CSP ✔, provider a11y modes are the widgets' built-ins — noted) and
**closes #340** (providers Friendly/ALTCHA/MTCaptcha not shipped: registry-extensible, noted
in the close per D1). Comment on **#1685**: the captcha third of the dead chain is now wired;
motd/ads remain, issue stays open.

**C7 — `docs(security): api-docs + wiki + changelog + SECURITY.md + close-out`**
`api-docs.yaml` (the `captcha_token` body key, the 403 `reason`, the `app_status.captcha`
object, the new 429), CHANGELOG, Wiki security/API pages, SECURITY.md, `.claude/` docs +
handoff, standing-tasks sweep. No code.

Ordering rationale: C1/C2 are self-contained hardening with no dependencies; C3 is
pure-dormant scaffolding; C4 depends on C3; C5 on C4's emit; C6 on C3's custody registration;
C7 sweeps. Every commit `php -l` / `node --check` / full test run / break-red-restore log.

---

## 8. Owner decisions surfaced

### D1 — CAPTCHA provider posture (THE decision; privacy dimension — deliberately not picked unilaterally)

**The decision:** which challenge provider iHymns activates — and whether visitor
interaction data may leave the site at all.
**Why it needs deciding:** a hosted CAPTCHA sends each visitor's interaction signals + IP to
a third party on every gated submission — a real data-offsite consideration for a church
congregation app — while the self-hosted route trades that away for weaker bot resistance
and more build. That is a values call, not a code call.
**Blocks:** nothing in C1-C7 — the mechanism is provider-agnostic and dormant; the choice
gates only *activation* (creating an account + pasting keys).

| Option | Visitor data offsite | Cost | UX | Build beyond this plan | Consequence |
|---|---|---|---|---|---|
| **Cloudflare Turnstile** | interaction signals + IP to Cloudflare (no ad-tracking; no persistent cross-site ID) | free, unlimited | invisible for most humans | none — v1 registry entry | best resistance-per-friction among the hosted three |
| **hCaptcha** | signals + IP to hCaptcha (privacy-marketed) | free tier | visible puzzles more often | none — v1 entry | stricter, more human friction |
| **reCAPTCHA v2** | signals + IP **to Google**, cookie-linked | free | familiar checkbox | none — v1 entry | weakest privacy story; blocked in some regions |
| **ALTCHA (self-hosted proof-of-work)** | **nothing leaves the site** | free (OSS) | invisible (CPU proof) | its own follow-up pass: vendored widget (SRI-pinnable!), server challenge-mint + verify, no external verify seam — removes A.8 entirely | weakest against human farms & headless solvers; strongest privacy; most build |
| **Do nothing** (keep §1d floor only) | n/a | none | none | none | honeypot + per-IP/account/identifier budgets remain; residual exposure is distributed-bot registration/email-spray at the budgeted rates — genuinely tenable for this app's size |
| Friendly Captcha / MTCaptcha | EU-hosted / commercial | paid | invisible | one registry entry each | listed for completeness; not in v1 |

**Recommendation:** build C1-C7 as specced (provider-agnostic), and when activating, pick
**Turnstile** — the best friction/effectiveness/no-ad-tech balance among the hosted options
— **unless** the privacy stance is "no visitor data offsite, ever", in which case say so and
ALTCHA becomes a scoped follow-up (I would then still land C1-C7 now: the gates, vocabulary,
guards and admin card are identical; only the registry entry + verify seam grow).
**What I need back:** "Turnstile", "ALTCHA", another named option, or "do nothing for now".

### D2 — Which forms, and the #340-vs-#947 login conflict

#340 says CAPTCHA "NOT on login forms"; #947's title is "Login forms". Both keys exist in
the registry; the *seed* decides nothing is enabled: **`captcha_enabled_forms` seeds `''`**
(fully manual — pasting keys must not silently change six endpoints; the admin card is one
tick per form). **Recommendation when activating:** `song_request` + `manage_login` first
(zero native impact), `password_reset`/`email_login`/`registration` only after D3, `login`
last or never (it already carries the strongest stacked budgets, and login friction is the
costliest UX). Default picked; trivially changeable; non-blocking.

### D3 — Native-app sequencing (informational; non-blocking)

Enabling any shared form (`registration`, `login`, `password_reset`, `email_login`) 403s the
current Apple/Android clients (§1e). The 403 carries `reason:'captcha_required'` so natives
degrade to showing the server sentence, not a silent failure. A follow-up issue (§9) tracks
native widget adoption; until it lands, those forms stay off or the owner accepts the
breakage window. Nothing to answer unless you want shared forms enabled early.

### D4 — #1027 tracker hygiene

Recommendation: **reopen at implementation start, close at C1** with the completing SHA and
a one-line note that the 2026-08-18 close was premature (api half only) — mirrors the #1662
reopen precedent and keeps the timeline honest. Alternative (comment-on-closed without
reopening) loses the "this was open work" signal. Non-blocking; C1 proceeds either way.

---

## 9. Issue actions on landing

- **#1027**: reopen → close at C1 (SHA + `manage/includes/auth.php` line evidence + the
  shared-budget note + mis-close acknowledgement).
- **NEW (file before C2)**: `auth_email_login_request` per-IP budget — cites §1e-1; closes at C2.
- **#947**: close at C6 (acceptance sweep per C6's text; a11y note: each provider's built-in
  accessible mode is reachable because the widget is the provider's own).
- **#340**: close at C6 (mechanism + `song_request` key; unshipped providers =
  registry-extensible, per D1).
- **#1685**: comment at C6 — captcha chain now wired (emit unchanged, per its non-goals);
  stays open for motd/ads.
- **#1906**: comment — this pack lands the remaining auth-abuse child items adjacent to the
  epic (per-account /manage, email-login IP budget); its stale checkboxes for already-landed
  items (per-email code bucket, register write-half) noted with SHAs.
- **NEW (file at implementation, `for consideration`)**: (a) cross-action per-IP login
  counter cross-talk (§1e-2 — registration failures can trip the login lockout for the same
  IP; both surfaces); (b) native apps adopt CAPTCHA widget support (unblocks D2's shared
  forms; reference D3); (c) if D1 answers "ALTCHA" — the self-hosted provider pass.
- **#1922**: referenced (the read-side counter prune) — no action here; `tblLoginAttempts`
  (this pack's counter table) already has its 30-day prune (`api.php:11020`, `cleanup.php:135`).

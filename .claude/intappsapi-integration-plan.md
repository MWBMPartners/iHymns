# iHymns ⇄ MWBM-IntAppsAPI — integration analysis, verdict and plan

> Produced 2026-08-01 by six **sequential** Fable 5 phases (gateway contract →
> iHymns overlap → adversarial verdict → one-pass design → adversarial stress
> test → executable plan). Gateway repo read at commit `6816ed8`.
>
> **HEADLINE: the recommendation is to DEFER the integration until after the
> merge to main.** It does not block the launch. See §3 Verdict.
>
> ⚠️ **Two bugs were found in the GATEWAY repo while establishing its contract,
> and both are filed there:** MWBM-intAppsAPI#120 (all five client examples sign
> the wrong HMAC canonical string, so every documented mutating request 403s) and
> MWBM-intAppsAPI#121 (`ApiException::internal()`/`::forbidden()` are undefined at
> five call sites). #120 in particular is why the liveness/credential check is a
> BLOCKING prerequisite rather than a formality.
>
> ⚠️ **`api.mwbmpartners.ltd` cannot be reached from the dev container** — the
> agent proxy answers 403 to CONNECT for the whole `mwbmpartners.ltd`/`ihymns.app`
> domain space (`ihymns.app`, which is certainly live, fails identically). That is
> a network-policy fact and is **NOT** evidence about the gateway's deployment
> status. Liveness must be established from a real machine.

---

## 1. Gateway contract (from the gateway's own source)

# MWBM intAppsAPI — Integration Contract (ground truth from source)

Read from `/workspace/mwbm-intappsapi` @ commit `6816ed8` (2026-07-22, `main`, no tags). All paths below are relative to `/workspace/mwbm-intappsapi/` unless absolute.

---

## 0. Response envelope (all endpoints)

Every JSON response uses `{"success": bool, "data": ...}` on success and `{"success": false, "error": {"code": "...", "message": "..."}}` on failure — `web/src/Core/Response.php:20-69`. Responses carry `Cache-Control: no-store, no-cache, must-revalidate` (`Response.php:103`) — **the gateway never emits ETags or cache headers for clients; there is no ETag support anywhere**. `X-Request-Id` header is echoed when set (`Response.php:106-108`). Error codes: `ACCESS_DENIED`, `NOT_FOUND`, `VALIDATION_FAILED`, `INVALID_REQUEST`, `RATE_LIMITED`, `INTERNAL_ERROR`, etc. (`web/src/Core/Constants.php:35-45`).

---

## 1. Client-facing endpoints

All app-facing routes are in a `/v1` group — `web/config/routes.php:132-156`. The middleware stack for each is `ApiVersionMiddleware → DebugModeMiddleware → RateLimitMiddleware → AuthMiddleware` (`routes.php:72-74`). Every `{app_slug}` endpoint verifies the URL slug **equals the authenticated app's slug**, else 403 (`web/src/Controllers/Traits/ResolvesApp.php:46-48`) — one credential set serves exactly one slug.

### Public (no auth)

| Endpoint | Source | Response `data` |
|---|---|---|
| `GET /v1/status` | `routes.php:105`, `StatusController.php:19-63` | `{status: "ok"\|"degraded", version: "0.3.0", server_time, database: "connected"\|"unreachable", cache: "connected"\|"unavailable", api_versions: [{version, deprecated_at, sunset_at}]}` — always HTTP 200 |
| `GET /v1/health` | `routes.php:106`, `HealthController.php:22-85` | `{status: "ok"\|"degraded"\|"unhealthy", version, server_time, uptime_s, checks: {database, cache, disk, tables}}`; HTTP 200 healthy / **503 degraded or unhealthy** (`HealthController.php:53`). In production each check is reduced to `{status}` only (`HealthController.php:59-63`) |
| `GET /docs`, `GET /docs/openapi.json` | `routes.php:110-128` | Swagger UI / OpenAPI 3.1 spec (spec version 0.3.0) |

### Authenticated (3-factor + HMAC on writes)

**`GET /v1/heartbeat`** — `routes.php:141`, `HeartbeatController.php:17-33`.
Response `data`: `{status:"ok", server_time (ISO 8601 Z), api_version:"0.3.0", app_name, app_slug}`.

**`GET /v1/features/{app_slug}`** — `routes.php:143`, `FeatureController.php:27-51`.
Response `data`: `{app_slug, features: [{feature_key, label, enabled (bool), enabled_at, disabled_at, metadata (decoded JSON or null)}], count}` (`FeatureController.php:136-154`).

**`GET /v1/features/{app_slug}/{feature_key}`** — `routes.php:144`, `FeatureController.php:56-79`.
Response `data`: one formatted feature object. 404 (`NOT_FOUND`) if key unknown (`FeatureController.php:67-69`).

**`POST /v1/features/{app_slug}/batch`** — `routes.php:145`, `FeatureController.php:86-131`.
Body: `{"keys": ["flag-a", ...]}`; empty/omitted `keys` returns all flags (`FeatureController.php:106-110`). Non-array keys → 422 `VALIDATION_FAILED` (`FeatureController.php:93-94`). Response shape identical to list. **Being POST, this requires an HMAC signature** (see §2) — the GET list is signature-free and equivalent for the empty-keys case.

**`GET /v1/notifications/{app_slug}?version=x.y.z`** — `routes.php:147`, `NotificationController.php:25-63`.
Returns only `is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())` rows (`Models/Notification.php:19-30`). If `?version` given, rows with a `target_version` SemVer constraint that the client version fails are filtered out (`NotificationController.php:41-45`). Response `data`: `{app_slug, notifications: [{id (int), title, body, severity ("info"|"warning"|"critical"), target_version, expires_at, created_at}], count}`.

**`GET /v1/updates/{app_slug}`** — `routes.php:149`, `UpdateChannelController.php:27-42`.
Response `data`: `{app_slug, channels: [{channel, current_version, update_url, updated_at}], count}`.

**`GET /v1/updates/{app_slug}/{channel}?version=x.y.z`** — `routes.php:150`, `UpdateChannelController.php:47-77`.
Response `data`: one channel object; with `?version` also `update_available (bool)` + `client_version` (`UpdateChannelController.php:70-74`). 404 if channel unknown.

**`POST /v1/crash-reports/{app_slug}`** — `routes.php:152`, `CrashReportController.php:31-88`.
Body: `{"crash_data": {...} (required object, ≤64 KB), "app_version": "1.2.3" (optional SemVer), "platform": "slug" (optional)}` (`CrashReportController.php:41-66`). Client IP is HMAC-SHA256-hashed for dedup (`CrashReportController.php:69`); a repeat from the same IP within 5 minutes returns HTTP 200 `{accepted: true, duplicate: true, message}` (`CrashReportController.php:73-79`, window: `Models/CrashReport.php:44`); otherwise **HTTP 201** `{report_id, accepted: true}` (`CrashReportController.php:83-86`). Rate limit: **10/min/IP** (`routes.php:63`, `config/security.php:34`).

**`POST /v1/email/{app_slug}/send`** — `routes.php:154`, `EmailController.php:84-290`. Sends via MS365 Graph.
Body: `from` (must be pre-registered in `tblEmailSenders`, else 403* — `EmailController.php:100-105`), `to` (required array ≤50 total with cc+bcc), `cc`, `bcc`, `subject` (≤500 chars), `body_html` and/or `body_text` (≤256 KB each; at least one required), `body_amp` (requires `body_html`), `reply_to`, `importance` (`low|normal|high`), `sensitivity`, `expires_at`, `request_delivery_receipt`, `request_read_receipt`, `voting_options` (2-10), `headers` (X-prefixed only), `attachments` (≤20, ≤3 MB total, MIME allow-list at `EmailController.php:50-82`). Response `data`: `{sent: true, from, to, subject, attachments_count, request_id}` (`EmailController.php:282-289`). Rate limit 30/min (`config/security.php:55`).
> ⚠️ ***Latent bug**: `EmailController.php:95,101,266,279` call `ApiException::internal()` and `ApiException::forbidden()`, but `ApiException` defines only `internalError()` and `accessDenied()` (`web/src/Core/ApiException.php:38-108`). Those four failure paths would raise `Error: Call to undefined method` → generic 500 from the global error handler, not the intended 403/500 message. Unregistered-sender and Graph-failure paths are affected; the happy path is not.*

### Status codes (app-facing)

- **403 `ACCESS_DENIED`** — any auth failure, deliberately undifferentiated (`AuthMiddleware.php:24`, factories at `ApiException.php:38-45`).
- **404 `NOT_FOUND`** — unknown feature/channel; unknown API version tag (`ApiVersionMiddleware.php:42`).
- **410 `GONE`** — `/v1` past its `sunset_at` (`ApiVersionMiddleware.php:52-57`).
- **422 `VALIDATION_FAILED`** — bad input (`ApiException.php:74-81`).
- **429 `RATE_LIMITED`** with `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining: 0` headers (`RateLimitMiddleware.php:63-75`). General app-endpoint limit: **60/min per IP** (`routes.php:62`, `security.php:33`) — per-IP, so a NAT'd office shares one bucket.
- **400/500** as usual.

---

## 2. Authentication, exactly

`web/src/Middleware/AuthMiddleware.php` — three factors in order, generic 403 on every failure:

1. **`X-App-ID`** must match `tblApps.app_uuid` of an active app (`AuthMiddleware.php:38-50`).
2. **User-Agent prefix**: `str_starts_with($userAgent, $app['user_agent_prefix'])` — a plain prefix match; skipped only when the registered prefix is the empty string (`AuthMiddleware.php:52-59`):
```php
if ($expectedPrefix !== '' && !str_starts_with($userAgent, $expectedPrefix)) {
    $this->recordAuthFailure(...); throw ApiException::accessDenied();
}
```
3. **`X-API-Key`**: `password_verify()` first against each active **scoped key** in `tblAppApiKeys` (bcrypt `key_hash`), then falling back to the legacy app-level `tblApps.api_key_hash` (`AuthMiddleware.php:68-86`).

**Scoped keys** (`AuthMiddleware.php:90-101, 199-212`): a scoped key carries a JSON `permissions` array; recognised scopes are exactly **`read`**, **`write`**, **`full`**. `full` = everything; POST/PUT/PATCH/DELETE need `write`; GET/HEAD/OPTIONS need `read` or `write`. Fail-closed: empty/unknown scope set permits nothing. Legacy app-level keys have full access. Created via admin `POST /v1/admin/apps/{app_slug}/keys` (`routes.php:200`).

**HMAC — mutating requests only** (POST/PATCH/DELETE; GETs never need a signature — `AuthMiddleware.php:104`). Both `X-Signature` and `X-Timestamp` required, else 403 (`AuthMiddleware.php:105-110`). Verification (`web/src/Helpers/HmacValidator.php:24-55`):

```php
if (!ctype_digit($timestamp)) { return false; }          // Unix seconds, digits only
...
if (abs($now - $ts) > $maxAge) { return false; }          // ±300 s window (config/security.php:18, Constants.php:70)
$payload = $requestBody . '.' . $timestamp;               // THE canonical string
$expected = hash_hmac('sha256', $payload, $secret);       // hex output
return hash_equals($expected, $signature);
```

So the contract is: **`X-Signature = hex(HMAC-SHA256(rawBody + "." + unixTimestamp, hmac_secret))`**, where `rawBody` is the exact raw request body bytes (`Request.php:129-132`), the timestamp is **Unix epoch seconds as a decimal string** (ISO strings fail `ctype_digit`), and the window is ±`HMAC_MAX_AGE_SECONDS` (default 300, env-overridable). Method and path are **not** part of the signed string. The secret is the per-app `hmac_secret` issued once at registration, stored AES-256-GCM-encrypted server-side (`HmacValidator.php:66-113`, `security.php:21`).

> ⚠️ **All four bundled client examples contradict the server.** `web/docs/examples/curl.sh:12-16`, `php-client.php:58-60`, `python-client.py:53-59`, `javascript-client.js:42-46` sign `METHOD|PATH|TIMESTAMP|BODY` with an **ISO 8601** timestamp. GET calls in those examples work only because the server ignores signatures on GET; **every POST example would 403**. Implement against `HmacValidator.php`, not the examples.

**Failure lockout**: 10 failed auth attempts per (app-ID + IP) per minute → 429 (`AuthMiddleware.php:29-30, 133-166`; the CHANGELOG's "20 failures" claim at `CHANGELOG.md:13` is stale — code says 10).

**`X-Dev-Token`** (`web/src/Middleware/DebugModeMiddleware.php`): only relevant when `?debug` or `?dev` query param is present. **Hard-blocked with 403 in production regardless of token** (`DebugModeMiddleware.php:36-39`). Non-production: token is bcrypt-verified against up to three `DEV_TOKEN_HASH_n` env hashes (`DebugModeMiddleware.php:64-77`, `security.php:26-30`), and a valid token **bypasses AuthMiddleware entirely** (`DebugModeMiddleware.php:56-58`; `ResolvesApp.php:33-39` then resolves the app by slug alone). Not usable by iHymns in production.

**CORS** (matters for the iHymns PWA calling from a browser): allowed origins is an exact-match list from a single env var `CORS_ORIGIN` **defaulting to the API's own URL** (`security.php:41-44`, `CorsMiddleware.php:48-61`). As configured, `https://ihymns...` origins would get **no** `Access-Control-Allow-Origin` header → browser fetch fails. Also, embedding `X-API-Key`/HMAC secret in a public PWA is credential disclosure — browser-side direct calls are effectively unsupported by this design; **route iHymns web traffic through the iHymns PHP backend** (server-to-server), and use direct calls only from native Apple/Android clients (where the secret is still extractable — accept that risk consciously or proxy those too).

---

## 3. Feature flag semantics

Schema (`web/sql/schema.sql:36-52`): a flag is a per-app row in `tblAppFeatures` — `feature_key` (slug ≤100), `feature_label`, **`is_enabled TINYINT(1)`** (a plain boolean), `enabled_at`/`disabled_at` DATETIME schedule columns, and `metadata_json JSON` (free-form, the only place for values/config).

- **A flag is a boolean plus optional arbitrary metadata.** There is **no rollout percentage, no per-user targeting, no app-version targeting on flags** (version targeting exists only on *notifications*, `Notification.php:22-26` + `NotificationController.php:41-45`).
- Effective state = `is_enabled` overlaid with schedule: disabled-but-`enabled_at`-passed ⇒ true; enabled-but-`disabled_at`-passed ⇒ false (`Models/Feature.php:200-220`). A cron worker also materialises schedules (`Feature.php:172-195`).
- The app receives `{feature_key, label, enabled, enabled_at, disabled_at, metadata}` (`FeatureController.php:146-153`).
- **Caching**: server-side only — Redis, TTL 300 s (`Feature.php:23-39`, `Cache.php:24`), invalidated on admin writes. Client-side: no ETag/Last-Modified/TTL is offered (`Response.php:103` sends `no-store`). No documented client cache guidance exists in the repo; the implied model (README.md:9 "disable a broken feature") is: poll at your own interval, cache the last-known answer yourself.
- **Unreachable-gateway behaviour: nothing in this repo specifies it.** No SDK, no fallback contract. iHymns must define its own default (sensible: last-cached value, then the flag's compiled-in default; fail-open for cosmetic features, fail-closed for legally-gated ones). Flag this as a decision the gateway does not make for you.

---

## 4. App registration — what must exist before iHymns can call anything

Registration is **admin-only**: `POST /v1/admin/apps` behind admin Bearer login (`routes.php:192`, `web/src/Controllers/Admin/AppController.php:46-84`), or the CLI `php web/cli.php` (`CHANGELOG.md:16`).

Required to create: `app_name` (≤100 chars), `app_slug` (**`[a-zA-Z0-9_-]+`, ≤50**, unique — `AppController.php:55`, `InputSanitizer.php:195-214`, unique key `schema.sql:29`; duplicate → 409 `DUPLICATE_SLUG`, `AppController.php:59-65`), `user_agent_prefix` (≤100; empty string disables UA check).

Creation returns — **once, unrecoverably** (`AppController.php:76-83`): `app_uuid` (UUIDv4 → your `X-App-ID`), `api_key` (64 hex chars → your `X-API-Key`; only its bcrypt hash is stored), `hmac_secret` (64 hex chars → signing secret; stored encrypted). Key rotation via `POST /v1/admin/apps/{slug}/rotate-key` (`AppController.php:144-163`); note there is **no HMAC-secret rotation endpoint** (only API-key rotation) — ambiguous whether that's intended.

So server-side prerequisites for iHymns: (1) an admin user exists and someone logs in; (2) an `ihymns` app row is created and `is_active=1`; (3) `tblApiVersions` has `v1` active (seeded, `schema.sql:306`); (4) for email: MS365 env vars set (`security.php:50-52`) **and** each `from` address registered via `POST /v1/admin/email/senders` (`routes.php:262`, `EmailSender.php:68-77`); (5) optionally, scoped keys minted per platform. Clients must send a matching `User-Agent` (e.g. prefix `iHymns/`).

---

## 5. Schema summary (consumer-relevant tables, `web/sql/schema.sql` + migrations)

| Table | Purpose | Key columns |
|---|---|---|
| `tblApps` (schema.sql:16-31) | App registry / credentials | `app_uuid` CHAR(36) UQ, `app_slug` VARCHAR(50) UQ, `user_agent_prefix`, `hmac_secret` (encrypted), `api_key_hash` (bcrypt), `is_active` |
| `tblAppFeatures` (36-52) | Feature flags | UQ(`app_id`,`feature_key`), `is_enabled`, `enabled_at`/`disabled_at`, `metadata_json` JSON; FK CASCADE |
| `tblNotifications` (57-72) | In-app notices | `severity` ENUM info/warning/critical, `target_version` SemVer constraint, `is_active`, `expires_at` |
| `tblUpdateChannels` (77-90) | Update polling | UQ(`app_id`,`channel_name`), `update_url`, `current_version` |
| `tblCrashReports` (95-109) | Crash intake | `crash_data` JSON, `client_ip_hash` (dedup), `app_version`, `platform` |
| `tblAppApiKeys` (205-218, migration 004) | Scoped keys | `key_hash` bcrypt, `permissions` JSON (`read`/`write`/`full`), `is_active`, `last_used_at` |
| `tblRateLimits` (191-200) | Per-minute counters | UQ(identifier, endpoint_group, window_start) |
| `tblApiVersions` (177-186) | `/v1` lifecycle | `is_active`, `deprecated_at`, `sunset_at` |
| `tblEmailSenders` / `tblEmailLog` (migrations/013:4-30) | Email allow-list + audit | sender UQ email; log has status ENUM sent/failed, `request_id` |
| Server-internal: `tblActivityLog`, `tblAdminUsers`, `tblAdminSessions`, `tblAuditLog`, `tblWebhooks`(+Deliveries, migrations 003/008), `tblBackups`, `tblMigrations`, `tblFeatureAnalytics` (migration 011) | | |

Note: `schema.sql:3` claims "cumulative through migration 007" while migrations run to 014 (`CHANGELOG.md:87` says "through 012") — the email tables (013) and later indexes are **not** in schema.sql; a fresh install needs `php web/migrate.php` after importing schema.sql (`README.md:116-117`).

## 6. Operational reality — is it live at api.mwbmpartners.ltd?

**I could not verify liveness and the repo contains no proof of deployment. Treat "deployed" as UNVERIFIED.**

Evidence for it being a real, mature project:
- Active development through 2026-07: last commit 2026-07-22 (dependabot GH-Actions merge, PR #103), plus CI (`/.github/workflows/`: build.yml, ci.yml, php-static-analysis.yml, pr-security.yml).
- Complete deployment tooling: `DEPLOYMENT.md` with Apache/Nginx vhosts for `api.mwbmpartners.ltd`, security checklist, monitoring section; `docker-compose.yml`, `install.php`, `migrate.php`; `APP_URL` **defaults** to `https://api.mwbmpartners.ltd` (`config/app.php:55`); README.md:36 lists "Hosting: `api.mwbmpartners.ltd`".
- README.md:5 describes it as serving existing MWBM apps (MeedyaDL, CueRCode, Go2My.Link).

Evidence of doubt:
- Everything above is *instructions and intent*, not a deployment record. The DEPLOYMENT.md security checklist is unchecked; there is no deploy log, no release artifact, **no git tags at all** (despite README.md:80-89 marking M1-M6 "Complete" and CHANGELOG.md:90 listing a `[1.0.0]` section — the same README admits "the `v1.0.0` release tag has not yet been cut" and the code self-reports 0.3.0, `Constants.php:16`).
- **Direct probe failed for environment reasons, not evidence either way**: `curl https://api.mwbmpartners.ltd/v1/status` from this sandbox was rejected by the session's egress proxy (`CONNECT tunnel failed, response 403` — policy denial at the agent proxy, confirmed via `$HTTPS_PROXY/__agentproxy/status` `connect_rejected` entries). I cannot distinguish "site down/nonexistent" from "domain not on this sandbox's allow-list".
- The bundled client examples being uniformly wrong about HMAC signing (§2) suggests **no external consumer has ever completed a signed POST against it** — a live gateway with real integrations would likely have surfaced that immediately.

**Recommendation-shaping facts**: before iHymns writes a line of integration code, someone with real network access must (a) `curl https://api.mwbmpartners.ltd/v1/status`, and (b) confirm an admin can log in and create the `ihymns` app. If the gateway is not stood up, standing it up (Docker path, README.md:102-109) plus fixing the `ApiException::internal/forbidden` bug and the example-vs-server HMAC divergence are prerequisites. When integrating: sign `rawBody + "." + unixSeconds`, send `X-App-ID`/`X-API-Key`/`X-Signature`/`X-Timestamp` + matching User-Agent, expect the `{success,data|error}` envelope, budget for 60 req/min/IP, and proxy all browser-origin traffic through the iHymns PHP backend (CORS single-origin + secret exposure make direct PWA calls a non-starter).
---

## 2. What iHymns already has — the overlap matrix

# iHymns ground truth vs. the MWBM intAppsAPI gateway

All paths relative to `/home/user/iHymns/appWeb/public_html/` unless noted. Repo root `/home/user/iHymns`.

---

## 1. Existing gating — what axes, and per-user or per-app?

iHymns gates on **four distinct axes**, and they are ALL per-user/per-request — none is a per-app kill switch in the gateway's sense, but the two dormancy master flags come close.

**Axis 1 — Access tier (per-USER entitlement to CONTENT).** `TIER_CAPS` in `includes/access_tier_validation.php:72-86` — 7 column-backed caps (`CanViewLyrics`…`RequiresCcli`) plus JSON-backed admin-defined caps unioned in from `tblGatingCapabilities` via `tierCapsEffective()` (`access_tier_validation.php:319-324`, #1481 P1). The requester's tier is resolved per-user (`resolveEffectiveTier()`, `includes/ccli_validator.php:104-206` — personal `tblUsers.AccessTier` vs. org-inherited licence via recursive CTE, highest wins per `TIER_LEVELS`, `ccli_validator.php:86-92`). `checkTierAccess()` (`ccli_validator.php:376-458`) overlays the live `tblAccessTiers` registry (`capsForTierFromRegistry()`, :250-301) onto a hardcoded matrix that survives ONLY as the un-migrated fallback (:381-387). Enforcement: `contentGatingApply()` strips lyric body / media / offline affordance from `song_detail`/`song_data`/`random` payloads (`includes/content_gating.php:163-322`); `contentGatingMediaAllowed()` gates the actual bytes at `/song-media/<id>` (:356-406). Admin-defined deny-only rules run last via `gatingRulesApply()` (`content_gating.php:312`, loop in `includes/gating_rules.php:562-622`, CRUD UI `manage/feature-gating.php:1-120`, entitlement `manage_feature_gating` at `feature-gating.php:75`).

**Axis 2 — Role entitlements (per-USER capability to DO things).** `ENTITLEMENTS` map in `includes/entitlements.php:35+` — role→verb map (`edit_songs`, `delete_songs`, `manage_feature_gating`, …), checked by `userHasEntitlement()`. This gates admin/curation actions, not content.

**Axis 3 — Entity restrictions.** `includes/content_access.php::checkContentAccess()` (per CLAUDE.md rule #8) — per-song/songbook restriction rows (`tblContentRestrictions`), with the Service-Mode presence-token CCLI OR-grant (`content_gating.php:194-220`).

**Axis 4 — API-key scopes (per-CLIENT-app, the closest thing to gateway auth).** `includes/api_keys.php:1-30` — machine-to-machine keys `ihk_live_<40 hex>`, SHA-256-hashed in `tblApiKeys`, scoped and revocable, built for external services (MeedyaDL #907 is named at `api_keys.php:7`). A `content:gated`-scoped key bypasses stripping entirely (`content_gating.php:181-187`).

**The two "kill switches" iHymns does have are dormancy flags, not per-feature flags:** `content_gating_enabled` (`contentGatingEnabled()`, `content_gating.php:76-82`) and the nested `feature_gating_rules_enabled` (`gating_rules.php:522-526`), both `tblAppSettings` rows toggled at `manage/configuration.php:722-725`. There is also a small compiled-in `APP_CONFIG['features']` boolean map (`includes/config.php:559-565`: `audio_playback`, `sheet_music`, `shuffle`, `favorites`, `public_domain_only`) — code-constant, not remotely togglable, and barely used.

**Verdict:** iHymns gating answers "may THIS user see/do this?" (entitlement). The gateway's `tblAppFeatures` answers "is this feature ON for this app build at all?" (operational kill switch / remote config). These are **different questions** — the gateway would COMPLEMENT the tier system, not duplicate it. The genuine overlap is narrow: `tblAppSettings` flags + `APP_CONFIG['features']` already serve the kill-switch role for the web, and `app_status` already broadcasts some of them to clients (below). Note the near-name-collision trap: iHymns' "/manage/feature-gating" is capability *definitions for tiers*, NOT feature flags — any integration must not conflate them.

## 2. Existing remote/config mechanisms

- **`tblAppSettings`** — key/value store, read via memoized, DB-safe `getAppSetting()` (`includes/maintenance.php:60-97`, transparently decrypting `enc:v1:` envelopes), written via `setAppSetting()` (:135-150, encrypt-on-save, fail-closed).
- **Admin UI**: `/manage/configuration` (`manage/configuration.php`, gated `manage_configuration`, :57-61) toggles: email provider + credentials (:78-110), per-environment maintenance mode/message/allow-admins/refresh (:423-439), Apple SIWA + APNs keys (:613-627), native-app store IDs (:665-688), content gating + gating rules flags (:722-725), editor default (:756). MOTD, `registration_mode`, `ads_enabled`, `song_requests_enabled`, `captcha_provider` are further settings surfaced to clients.
- **Client-facing broadcast**: `GET /api?action=app_status` (`api.php:6272-6345`) emits an allow-listed public subset — `maintenance`, `maintenanceMessage`, `songRequestsEnabled`, `registrationMode`, `motd`, `emailLoginEnabled`, `captchaProvider`, `adsEnabled`, `contentGatingEnabled`, `appleWebLoginEnabled`, `appleSiwaServicesId` (:6288, :6313-6344). **This is already a remote-config poll endpoint for iHymns clients** — the natural seam for merging gateway flags in, rather than clients calling the gateway directly.
- **Maintenance**: per-environment (`maintenance_mode_<env>`, `environment.php` detects alpha/beta/production from docroot path, `includes/environment.php:21-41`), enforced at `enforceMaintenanceForPublicSite()`/`enforceMaintenanceForApi()` (`maintenance.php:413-471`; `app_status` + `auth_*` exempt at :445).
- **Channel gate**: `includes/channel_gate.php:64-114` — alpha/beta entitlement gate, currently short-circuited by an unconditional `return` at :83 ("TEMPORARILY DISABLED").
- **`APP_CONFIG`** (`config.php:292-605`) — code constants: library pins+SRI, analytics IDs (all null), native-app fallbacks, feature booleans.

## 3. Existing outbound HTTP — yes, plenty, with an established pattern

iHymns already makes outbound HTTP from request paths; the gateway would NOT be the first. Sites:

| Caller | Where | Timeout/failure handling |
|---|---|---|
| Email providers (SendGrid/Mailgun/SES/Graph/Gmail) | `EmailService.php:1201-1236` `httpPostRaw()` | curl, `CONNECTTIMEOUT 5` / `TIMEOUT 15`, TLS verify on, structured `['err','code','body']` return, never throws |
| Web Push services | `web_push.php:970-1013` | curl, `CONNECTTIMEOUT 8` / `TIMEOUT 15`, HTTPS-only protocols, prune-on-404/410, status-string result |
| Apple (SIWA JWKS + token, APNs) | `apple_siwa.php:701+`, `apns.php:377-423` | curl, TLS-verify invariant documented; APNs probes HTTP/2 support and refuses rather than degrade (`apns.php:56-63`) |
| iTunes store lookup | `config.php:250-284` `_verifyAppleAppStore()` | `stream_context` `timeout 5`, fail-OPEN on network error with `_transient` no-cache flag, 24 h file cache in `sys_get_temp_dir()` (:202-240) |
| IP geolocation | `ip_geolocation.php:229-262` `ihymnsGeoHttpGetJson()` | curl `CONNECTTIMEOUT 2`/`TIMEOUT 3` with `file_get_contents` fallback, null on any failure |
| Google Places proxy | `manage/places-api.php:435-451` | curl with stream fallback |
| Self-HEAD verifier | `manage/gating-noop-verify.php:258-278` | stream, `timeout 4` |

**The pattern to copy**: short curl timeouts (connect 2-8 s, total 3-15 s), `function_exists('curl_init')` guard with graceful degrade, TLS verification always on, structured-array return (never an exception on the hot path), fail-open-with-retry (the `_transient` no-cache trick, `config.php:262-267`) or fail-soft-null, plus a **file cache with TTL** for request-path lookups. A gateway feature-flag fetch should follow `verifyAppStoreApp()`'s shape: cached, short-timeout, last-known-value on failure.

## 4. Existing crash/error reporting

- **Client beacon**: `js/modules/error-monitor.js` POSTs uncaught JS errors to `?action=client_error_report` (`api.php:11499-11590`). Server-side pure validator/scrubber/fingerprinter in `includes/client_error_report.php` (#1582): closed kind allow-list (:119), secret scrubbing (Bearer/64-hex/query-secret, :149-161), 16-hex env-prefixed fingerprint (:308-312).
- **Storage**: **no new table** — rows go to the existing `tblActivityLog` (`EntityType='client'`, `EntityId=<fingerprint>`, `Result='error'`) via `logActivity()` (`api.php:11569-11579`), with 15-min server dedupe (:11535-11562), 10/60 s per-IP rate limit (:11511), 8 KB body cap (:11518). Viewed on `/manage/activity-log`.
- Payload already carries `version` + `channel` (:11576-11577) — i.e. iHymns already has app-version-tagged, deduped, scrubbed error intake for the web/PWA.
- There is deliberately **no client error monitor under `/manage/*`** (CLAUDE.md red-flag note re #1587).

The gateway's `POST /v1/crash-reports/{app_slug}` would **duplicate this for the web** but **complement it for native apps** (Apple/Android currently have no crash intake at all in this repo — `analytics_ingest.php` accepts analytics events from the Apple app but not crashes). Relaying the web beacon to the gateway would add an outbound HTTP call to a fire-and-forget hot path for no gain; the sane split is: web keeps `client_error_report`, native apps report to the gateway (or to iHymns).

## 5. Existing email

`includes/EmailService.php` (#898) is a complete, self-contained multi-provider sender: SendGrid / Mailgun / SES (hand-rolled SigV4) / raw-socket SMTP-AUTH with M365+Google presets and send-as delegates / MS Graph OAuth2 / Gmail API OAuth2 / log / none (`EmailService.php:24-61`). Config lives in `tblAppSettings` via `/manage/configuration` (`configuration.php:78-110+`), secrets encrypted at rest (`secretSettingKeys()`, `secret_crypto.php:430-466`). Every send is audit-logged to `tblActivityLog` with template/provider/sha256(email)-prefix/Message-Id, never the secret (:75-84). Notably, `EmailService.php:49` already says "**MailerMatt remains the eventual consolidated mechanism**" — a centralised MWBM email service is an acknowledged future direction.

The gateway's `POST /v1/email/{app_slug}/send` (MS Graph only, pre-registered senders) would **replace** the Graph driver but is strictly narrower than what iHymns has (no SMTP/SendGrid/Mailgun/SES, 50-recipient/3 MB-attachment caps, plus the gateway's own latent `ApiException::internal/forbidden` 500 bug on its failure paths). Cost of switching: a network dependency on auth-critical flows (magic-link login, password reset — a gateway outage becomes a login outage), loss of provider choice, loss of the fail-closed local audit trail. Gain: one place for MS365 credentials. Cleanest fit: add `intappsapi` as **one more EmailService driver** (~80 lines following `httpPostRaw()`), selected by `email_service` setting — the existing architecture explicitly anticipates this ("one surface to update", `EmailService.php:1187-1189`).

## 6. Existing push / notifications

Three separate systems:

- **In-app notification feed**: `tblNotifications`, one row per recipient, composed on `/manage/notifications` (`manage/notifications.php:6-27`, targeting user/role/all), consumed by the header bell via `?action=notifications_list` (`api.php:11653+`, existence-gated, per-env + expiry columns per #1238). This **duplicates the gateway's notifications feature almost exactly** (severity, expiry, targeting — the gateway adds SemVer version-targeting, iHymns adds per-user/role targeting the gateway lacks; the gateway's are per-app broadcast, anonymous).
- **Web Push**: `includes/web_push.php` — hand-rolled VAPID (RFC 8292) + aes128gcm encryption (RFC 8291/8188), no Composer (:15-19), RFC test-vector-proven (:57-66) but **never delivered to a real device** (:70-78); dormant until VAPID keys generated on `/manage/notifications` (:80-84); subscriptions in `tblPushSubscriptions` (`push_subscribe` at `api.php:10005`).
- **APNs**: `includes/apns.php` (#1410) — dormant ES256 provider-JWT + HTTP/2 send plumbing, `tblApnsTokens` registration (`apns_register`, `api.php:4982`), no key provisioned; HTTP/2-on-shared-hosting flagged as unverified risk (`apns.php:55-63`).

The gateway's `GET /v1/notifications/{app_slug}` is a **pull** feed; all three iHymns systems either already do that (bell) or do **push**, which the gateway doesn't do at all.

## 7. The environment constraint — confirmed from source

- **Three docroots, one MySQL**: `includes/environment.php:14-16` ("all three environments share ONE database"), detection by docroot path `public_html_dev`/`public_html_beta` (:27-32) with CI-injected `.env-channel` fallback (:34-39). Per-env behaviour keys off `ihymns_environment()` (maintenance keys, `maintenance.php:47-50`) or the Service-Mode `Channel` column (`includes/service_mode.php`).
- **Web-run migrations, never auto-applied**: `manage/feature-gating.php:88-92` ("migrations are web-run, never auto-applied on deploy"); every optional read is INFORMATION_SCHEMA-gated (e.g. `tierCapsColumnExists()`, `access_tier_validation.php:496-524`).
- **No CLI/SSH, no Composer, no cron**: web_push docblock "**This project has no Composer**" (`web_push.php:17`); the only "cron"-ish hits are comments (grep across `*.php` shows no crontab/CLI runner). Everything executes inside web requests.

**Implications**: (a) any "poll the gateway every N minutes" must be **request-piggybacked with a TTL'd cache** (the `verifyAppStoreApp()` file-cache pattern, `config.php:202-240`, or a `tblAppSettings`/table row with a fetched-at timestamp) — there is no daemon; (b) the ±300 s HMAC window is fine since signing happens per-request server-side; (c) any gateway credential must live in the established secrets locations (below), reachable identically from three docroots — note the three docroots would present as **one app or three** to the gateway's one-slug-per-credential model (`ResolvesApp` slug check), and its 60 req/min/IP limit is shared across whatever egress IP the host uses; (d) the gateway's per-app `User-Agent` prefix must be set on every server-side call.

## 8. Secret storage — a mature, established pattern exists

Two-layer scheme:

1. **Filesystem, outside the docroot**: `appWeb/.auth/` — `db_credentials.php` (loaded by `db_mysql.php:35-38`) and `secrets_master_key.php` (`secret_crypto.php:67-71`). Gitignored wholesale (`/home/user/iHymns/.gitignore:217-220` — `appWeb/.auth/*` with only `.htaccess` + `*.example.php` re-included), never web-served.
2. **Encrypted-at-rest in `tblAppSettings`** (#1466): `includes/secret_crypto.php` — libsodium secretbox / AES-256-GCM `enc:v1:` envelopes (:24-33), master key from `.auth/`, keyid rotation support, placeholder-key rejection (:118-129). Secret keys are registered in `secretSettingKeys()` (`secret_crypto.php:430-466` — SMTP/SendGrid/Mailgun/SES/Graph/Gmail creds, SIWA + APNs `.p8`, VAPID private key). Reads transparently decrypt via `getAppSetting()` (`maintenance.php:92-96`, fail-safe to default); writes go through `setAppSetting()` (fail-CLOSED, `maintenance.php:135-150`).

**Where the gateway's `X-App-ID` / `X-API-Key` / `hmac_secret` would live**: as `tblAppSettings` rows (e.g. `intappsapi_api_key`, `intappsapi_hmac_secret`) added to `secretSettingKeys()` — one line each — edited on `/manage/configuration`, encrypted from first save. This exactly mirrors how every existing third-party credential is held. (The app UUID and base URL are non-secret settings.) Since one MySQL serves all three docroots, one credential set = one gateway app slug shared by alpha/beta/production unless keyed per-env like maintenance flags.

---

## OVERLAP MATRIX

| Gateway capability | Verdict for iHymns | Reason |
|---|---|---|
| **Feature flags** (`tblAppFeatures`) | **COMPLEMENTS** (with a small duplicated edge) | iHymns has no remote per-app kill switch for *native* builds; its gating (TIER_CAPS/`checkTierAccess`) is per-user entitlement — a different axis (§1). For the web, `tblAppSettings` flags + `app_status` (`api.php:6272-6345`) already do remote config, so gateway flags for the *web* would be a second store for the same job; value is for the Apple/Android/FireOS apps and cross-app MWBM ops. Consumption must be server-proxied + TTL-cached (§3, §7) and merged into `app_status`, not fetched browser-side (gateway CORS + secret exposure). Never wire gateway flags into `contentGatingApply()` — rule #28's registry is the only content-gating source. |
| **Notifications** (`tblNotifications`, pull, severity + SemVer targeting) | **DUPLICATES** | iHymns already has a full in-app notification system: same-named table, admin compose UI with richer targeting (user/role/all, per-env, expiry — `manage/notifications.php`, `api.php:11653+`), consumed by the header bell — plus Web Push and dormant APNs that the gateway can't do (§6). Only the "message every MWBM app at once" case adds anything, and that could relay *into* `tblNotifications`. |
| **Update channels** (`tblUpdateChannels`) | **COMPLEMENTS** (natives) / IRRELEVANT (web) | The PWA self-updates via the service worker and CI-stamped `infoAppVer.php`; the web needs no update poll. Native app store presence is admin-configured + store-verified (`config.php:188-241`), but there is **no** "a newer version exists, here's the URL" endpoint for the native apps — the gateway provides exactly that. |
| **Crash reports** (`tblCrashReports`) | **DUPLICATES** (web) / **COMPLEMENTS** (natives) | Web already has a scrubbed, fingerprinted, deduped, rate-limited beacon into `tblActivityLog` (`client_error_report.php`, `api.php:11499-11590`) with an admin viewer; relaying it gains nothing and adds an outbound call to a fire-and-forget path. Native apps have **no** crash intake anywhere in this repo — the gateway fills a real gap there. |
| **Email** (`/v1/email/send`, MS Graph) | **DUPLICATES** (narrower than what exists) | `EmailService.php` already speaks Graph *and* five other providers, with encrypted-at-rest creds, audit logging, and admin UI (§5). The gateway is a strict subset with a latent 500 bug on failure paths, and would put a network hop inside login/reset flows. If consolidation is wanted (the file itself names "MailerMatt" as the eventual plan), add it as one more driver behind `email_service` — don't replace. |
| **Webhooks** (`tblWebhooks`, server-internal) | **IRRELEVANT** | iHymns neither emits nor consumes webhooks anywhere (no consumer surface found); the gateway's webhooks are its own internal delivery machinery. Nothing to integrate today. |
| **Analytics** (`tblFeatureAnalytics`) | **IRRELEVANT** | iHymns runs first-party anonymous analytics into its own `tblAppAnalyticsEvents` (`analytics_ingest.php`, deliberate no-PII stance) plus `tblActivityLog` + `/manage/analytics`. The gateway's feature-flag-usage analytics are about *its* flags; iHymns' privacy posture (no device id, no IP stored) argues against shipping usage data out. |
| **Heartbeat/status** (`/v1/status`, `/v1/heartbeat`) | **COMPLEMENTS** (trivially) | iHymns has the inverse (`app_status` tells clients about iHymns) but nothing that reports iHymns' health *to* MWBM ops. A low-value nice-to-have; if used, piggyback on existing request flow — no cron exists to emit heartbeats (§7). |

**Cross-cutting facts that shape any integration**: outbound HTTP is established, so the pattern (short timeouts, structured failure, TTL cache, fail-open-to-last-known) is copy-ready (§3); credentials slot into `secretSettingKeys()` + `/manage/configuration` with two one-line additions (§8); every fetch must be existence/flag-gated and a byte-identical no-op when unconfigured (the house dormancy discipline in `content_gating.php:76-82` and `web_push.php:80-84` is the template); the unreachable-gateway default the contract says the gateway doesn't define **must** be "cached value, then compiled-in default" because a gateway outage on shared hosting can never be allowed to take down a login or a song read; and the gateway's HMAC must be implemented against `HmacValidator.php` (`hex(HMAC-SHA256(rawBody + "." + unixSeconds))`), not its bundled examples.
---

## 3. Verdict — should we do this now?

Both documents check out against the tree where I spot-verified them (`app_status` at `api.php:6272`, `secretSettingKeys()` at `secret_crypto.php:430`, the dormancy gate at `content_gating.php:81`). Here is the adversarial answer.

---

# Should iHymns wire into MWBM-IntAppsAPI before merging to main?

## 1. Timing

The case FOR doing it now: the house has a proven "ship dormant, flip later" discipline — content gating (`content_gating_enabled='0'`), Web Push (`web_push.php:80-84`), APNs — all landed as byte-identical no-ops and were verified as such. A dormant gateway foundation would follow a well-worn template, and landing schema pre-launch avoids a post-launch migration on a live public install.

The case AGAINST is stronger, and it is the branch's own recent history. The last session was spent excavating **silent no-ops** (#1565 CSP-refused scripts, #1581 mismatched event names, #1623/#1628/#1680 ignored URL params, #1677 a client violating its own documented contract). A remote-flag integration is a *silent-no-op factory by construction*: its designed failure mode is "quietly serve the cached/default value." Every failure — wrong HMAC canonical string, expired cache never refreshing, a flag key typo'd on the gateway side — presents as "the feature looks alive and nothing happens," which is precisely the failure class this codebase has repeatedly taken weeks to notice (Export was dead for ~7 weeks). Adding that class of code in the merge window, under Get It Right First Time, when it cannot even be integration-tested from this environment (the egress proxy blocks `api.mwbmpartners.ltd`), is the textbook definition of scope that lands after launch. Nothing in the launch depends on it: the web already has remote config (`app_status`), and the native apps — the actual beneficiaries — are not what is launching.

**Verdict: the timing is wrong.** The one thing pre-launch timing genuinely buys (avoiding a later migration) is nearly worthless here because the cheapest correct design needs **no migration at all** (see §5).

## 2. Duplication — be precise about the axes

- **`TIER_CAPS` vs `tblAppFeatures`: different axes, no conflict.** iHymns' registry answers "may THIS USER see this content?" (per-user entitlement, resolved via `resolveEffectiveTier()`). The gateway answers "is this feature ON for this APP BUILD at all?" (operational kill switch / remote config, boolean + metadata, no per-user targeting, no rollout %). These do not overlap, and that is the honest strongest argument FOR integrating — with the hard corollary that **gateway flags must never feed `contentGatingApply()`** (rule #28: the registry is the one source for content gating). The near-name-collision (`/manage/feature-gating` = tier capability definitions, NOT feature flags) is a standing confusion hazard any integration must name loudly.
- **But for the WEB specifically, the flag case still creates two sources of truth.** `tblAppSettings` + `app_status` (`api.php:6272-6345`) is already a working remote-config broadcast: `maintenance`, `adsEnabled`, `songRequestsEnabled`, `motd`, etc. Adding gateway flags for web behaviour means "is ads on?" can be answered two places — the exact most-repeated failure mode (rule #35's cross-file-agreement class, at system scale). The only safe web-side shape is gateway flags **merged into** `app_status` server-side with an explicit precedence rule, and even then the marginal value over just editing `/manage/configuration` is near zero.
- **The rest of the gateway mostly duplicates:** notifications (iHymns' `tblNotifications` + bell has *richer* targeting, plus push the gateway lacks), email (`EmailService.php` speaks Graph plus five other providers; the gateway is a strict subset with a latent 500 bug on its failure paths), web crash reports (`client_error_report.php` already scrubs/fingerprints/dedupes/rate-limits). What genuinely **complements**: update channels and crash intake for the **native** apps, which have nothing today.

**Verdict: complementary for native apps; duplicative or two-sources-of-truth for the web.** The value is real but it is native-app value, and the native apps are not launching now.

## 3. Availability

Shared hosting, three docroots, one MySQL, **no cron/CLI** — every gateway fetch must be piggybacked on a web request. There IS an unambiguously safe design, and the house already owns every piece of it:

- Credentials in `secretSettingKeys()` / `tblAppSettings` `enc:v1:` envelopes (§8 of the overlap doc).
- Fetch shaped like `verifyAppStoreApp()` (`config.php:202-284`): curl, connect ≤2 s / total ≤3 s, structured-array return, never throws on the hot path.
- Flag snapshot cached as a JSON `tblAppSettings` row + `fetched_at`; serve **stale-then-refresh**, with a cheap lock row so only one request per TTL window pays the refresh; on any failure, last-known value, then compiled-in default. Fail-open for cosmetic features; there must be **no** legally-gated feature behind a gateway flag (that stays in `TIER_CAPS`, which is local).
- Master dormancy switch `intappsapi_enabled` default `'0'`, byte-identical no-op verified the `gating-noop-verify` way.

With that design: gateway hard-down = no-op (cache serves, or defaults serve); gateway slow = **one request per TTL window eats up to ~3 s**, everyone else unaffected. That residual is real but bounded, and never a broken page, never a login/song-read outage. The danger is not that a safe design doesn't exist — it's that nothing *enforces* it except discipline plus a mutation-tested guard (rule #34), i.e. more launch-window work. Also note the gateway's 60 req/min **per-IP** limit is shared across all three docroots' single egress IP — TTL caching is mandatory, not optional.

**Verdict: safely integrable in principle; the safe design is ~half the total cost.**

## 4. Is it even live?

**Unverified, and the evidence leans "never exercised by any external consumer."** No git tags despite a claimed 1.0.0; code self-reports 0.3.0; `schema.sql` drifted from its own migrations; a latent `ApiException::internal/forbidden` undefined-method bug on email failure paths; and — most damning — **all four bundled client examples sign the wrong HMAC canonical string**, meaning every example POST would 403. A gateway with even one real signed-write consumer would have caught that on day one. The direct probe from this sandbox failed for proxy-policy reasons (not evidence either way), so liveness at `api.mwbmpartners.ltd` is simply unknown from here.

In practice, "integrating now" means writing client code against a server that may not be stood up, cannot be reached from this development environment, and whose write path has plausibly never been exercised end-to-end by anyone. That code would merge to main **untested against the real endpoint** — the exact wrong-but-green pattern rule #34 exists to kill. This alone is close to disqualifying for option (a), and it substantially weakens (b): even a dormant foundation should have its HMAC signer proven against the live server before it is trusted enough to merge.

## 5. Honest cost of a correct, house-rules-compliant integration

| Piece | What, concretely | Estimate |
|---|---|---|
| HTTP client | `includes/intappsapi_client.php`: HMAC per `HmacValidator.php` (`hex(HMAC-SHA256(rawBody + "." + unixSeconds))` — NOT the gateway's own examples), 4 auth headers + UA prefix, envelope unwrap branching on **status** (rule #35), timeouts, structured returns | 250–350 lines, two-register annotated |
| Secrets | 3 keys into `secretSettingKeys()` + non-secret base-URL/app-UUID settings | ~10 lines |
| Admin UI | `/manage/configuration` section: enable toggle, credentials, base URL, TTL, "test connection" button | 100–150 lines |
| Caching | Snapshot + `fetched_at` + refresh-lock as `tblAppSettings` rows — **zero migration** if done this way; a dedicated table instead costs a migration + byte-identical `schema.sql` mirror + one `migration-registry.php` entry with a REAL probe (rules #19/#20) | 80–120 lines (or + full migration ceremony) |
| `app_status` merge | Gateway flags merged server-side with explicit precedence; must be a byte-identical no-op when `intappsapi_enabled='0'` | 40–80 lines + no-op verification |
| Tests | HMAC signer vs known vectors; dormancy no-op test; a guard **derived from the tree and proven able to fail** (rule #34: break it, watch red, restore); update `test-*` runner lists via mechanism not comment | 200–400 lines |
| Docs/process | `api-docs.yaml`, wiki (API/Architecture/Setup), CHANGELOG, DEV_NOTES, ProjectBrief, epic + child issues filed at discovery, session handoff | 2–4 hours |
| Gateway side | Someone with real network access: verify liveness, admin login, create `ihymns` app, capture the once-only credentials, set `CORS_ORIGIN` decision, ideally fix the `ApiException` bug | 1–3 hours, **blocking prerequisite** |
| Native clients | Swift + Kotlin clients, plus the unresolved secret-in-binary vs proxy-through-iHymns decision | separate work, weeks not hours |

**Total for the web-side foundation alone: roughly 700–1,100 lines across 12–18 files, 2–4 full sessions** including the verification passes the house standard demands. Anyone quoting "an afternoon" is describing the happy path with no tests, no no-op proof, no docs — i.e. the thing this repo's red-flag list rejects.

## 6. Sequencing options

| Option | What it means | Cost now | Risk | Cost of NOT doing it |
|---|---|---|---|---|
| **(a) Integrate fully before main** | Client + cache + admin UI + `app_status` merge + tests + docs, live before launch | 2–4 sessions + gateway-side setup, all inside the merge window | **High.** Code merges untestable against a possibly-nonexistent endpoint; adds a silent-no-op class during a launch that just purged them; burns review budget on non-launch scope; gateway's own write path is unproven | — |
| **(b) Dormant foundation now, enable later** | Land client + secrets + toggle (default `'0'`), verified byte-identical no-op; flip post-launch | 1.5–3 sessions; smaller but still real review surface at the worst moment | **Medium.** Follows house dormancy discipline, but the HMAC signer merges unproven against the real server (only unit vectors); dormant-but-wrong code has historically sat undetected here; delays the merge by days for zero launch value | Saves nothing meaningful — the settings-row cache design means no migration is avoided by going early |
| **(c) Defer entirely; file the epic; revisit post-launch** | Merge to main clean. File: epic + liveness-verification prerequisite + web-foundation + native update-channel + native crash-intake + email-driver-option issues. Integrate in the first post-launch cycle, starting with a real `curl /v1/status` | ~1 hour of issue-filing (standing-tasks §2 obliges this anyway) | **Low.** Only real cost: native apps go without remote kill-switch/update-poll a few weeks longer — and they are not shipping now. Web loses nothing (`app_status` already covers it). Risk of "later never comes" is mitigated by the tracker being the point of truth | Doing nothing *forever* would leave natives with no kill switch, no update poll, no crash intake — a real gap, eventually |

*(A fourth micro-option worth naming: as part of (c), have the owner run the 10-minute liveness check from a real machine now — `curl https://api.mwbmpartners.ltd/v1/status` + admin login — so the post-launch issue starts from fact rather than the current unknown.)*

---

## Recommendation

**Option (c): do NOT integrate now. Merge to main without it. File the epic and child issues today, with liveness verification as the explicit first prerequisite, and land option (b)'s dormant foundation as the first post-launch cycle's work.**

Reasoning, in order of weight:

1. **The gateway's deployment status is unverified and its write path has plausibly never worked for anyone** (four-for-four wrong signing examples, no tags, version self-report mismatch). You do not couple a launch branch to that; you verify it first, from a machine that can reach it.
2. **Zero launch value.** Everything the gateway offers the *web* today, iHymns already does — mostly better (`app_status`, `tblNotifications` + bell, `EmailService`, `client_error_report`). The genuine value (native update channels, native crash intake) belongs to apps that are not launching in this merge.
3. **Worst possible failure class at the worst possible moment.** Remote flags fail silently by design; this branch just spent a session eradicating exactly that class. Get It Right First Time means proving the integration against the live endpoint before it merges — which is impossible this week and trivial next month.
4. **Deferral is nearly free.** The correct cache design (settings rows, not a new table) means no migration is dodged by going early; the whole "land schema before launch" argument evaporates. The only thing deferral costs is calendar time on a native-app feature with no current consumer.

When it IS built, the non-negotiables are already clear from source: sign `rawBody + "." + unixSeconds` per `HmacValidator.php` (never the bundled examples), branch on HTTP status not error prose, proxy all browser traffic through the iHymns backend (gateway CORS + secret exposure make direct PWA calls a non-starter), keep gateway flags out of `contentGatingApply()`, TTL-cache with fail-open-to-last-known-then-default, and gate everything behind `intappsapi_enabled='0'` with a proven byte-identical no-op and a mutation-tested guard.

**Does this block the merge to main? No.** Nothing in the launch depends on the gateway, and deferring creates no schema, contract, or migration debt that gets more expensive after launch. Merge clean; integrate against a verified-live gateway afterwards.
---

## 4. Design (one-pass, forward-looking)

# iHymns ⇄ MWBM-IntAppsAPI Integration — One-Pass Design (rule #20 final shape)

Status: DESIGN ONLY. Per the verdict, implementation lands post-launch, after gateway liveness is verified. This document is the final shape so that when it lands, it lands once — one migration, no second ALTER, no re-architecture.

Verified anchors used below: `migration-registry.php` entry shape (`/home/user/iHymns/appWeb/public_html/manage/includes/migration-registry.php`), `secretSettingKeys()` (`includes/secret_crypto.php:430-466`), `getAppSetting()/setAppSetting()` (`includes/maintenance.php:60,135`), `app_status` (`api.php:6272`), dormancy template (`includes/content_gating.php:76,168`), admin-links entry shape + `manage_configuration` gate (`manage/includes/admin-links.php:113`).

---

## 1. THE CLIENT — `appWeb/public_html/includes/intapps_client.php`

One shared module, loadable side-effect-free (declares functions only; its `require` of `maintenance.php`/`secret_crypto.php` opens no connection — same discipline as `song_relocate.php`'s registry note). Two-register annotations per house standard.

### Function signatures (exact)

```php
/** Master dormancy gate. True ONLY when tblAppSettings.intappsapi_enabled === '1'
 *  AND intappsConfig() returns a complete credential set. Memoized per request. */
function intappsEnabled(): bool;

/** Resolved config or null if any required piece is missing/empty.
 *  Keys: base_url, app_uuid, app_slug, api_key, hmac_secret, user_agent.
 *  api_key + hmac_secret arrive transparently decrypted via getAppSetting(). */
function intappsConfig(): ?array;

/** PURE signer — the ONLY place the canonical string is built. Unit-tested
 *  against fixed vectors (tests/php/test-intapps-hmac.php).
 *  Returns hex(HMAC-SHA256(rawBody . '.' . timestamp, hmacSecret)) —
 *  implemented against the gateway's HmacValidator.php:24-55, NEVER its
 *  bundled examples (all four sign the wrong string). */
function intappsSign(string $rawBody, int $unixTimestamp, string $hmacSecret): string;

/**
 * One HTTP round trip. NEVER throws. NEVER called from a render path except
 * via intappsRefreshIfDue()'s single-flight winner.
 *
 * @param string      $method  'GET' | 'POST'  (PATCH/DELETE reserved, same signing rule)
 * @param string      $path    e.g. '/v1/features/ihymns'  (leading slash, no base URL)
 * @param string|null $rawBody exact bytes to send AND sign; null for GET
 * @return array{
 *   transport: 'disabled'|'unreachable'|'answered',
 *   httpStatus: ?int,          // null unless transport === 'answered'
 *   ok: bool,                  // answered && 2xx && envelope success===true
 *   data: ?array,              // decoded envelope data on ok
 *   errorCode: ?string,        // gateway envelope error.code (ACCESS_DENIED, ...)
 *   errorMessage: ?string,     // envelope error.message OR curl error string
 *   durationMs: float
 * }
 */
function intappsRequest(string $method, string $path, ?string $rawBody = null): array;

/** Read the local snapshot for a scope (channel-resolved, see §2). Cache-only —
 *  performs NO HTTP. @return array{payload:?array, fetchedAt:?string, stale:bool} */
function intappsCachedScope(\mysqli $db, string $scope): array;

/** Single-flight refresh: wins the lock row or returns immediately. Bounded by
 *  the curl timeout; never throws; records success/failure per §2. Safe to call
 *  from any request path — at most ONE caller per TTL window pays anything. */
function intappsRefreshIfDue(\mysqli $db, string $scope = 'features'): void;

/** The consumer API. Cache/default only — never blocks on HTTP.
 *  $default is the compiled-in behaviour iHymns has today. */
function intappsFlag(\mysqli $db, string $featureKey, bool $default): bool;

/** Full flag object {enabled, label, metadata, enabled_at, disabled_at} or null. */
function intappsFlagMeta(\mysqli $db, string $featureKey): ?array;
```

### Wire contract (from gateway source, not its examples)

- Headers on every call: `X-App-ID: <app_uuid>`, `X-API-Key: <api_key>`, `User-Agent: iHymns/<version> (<channel>)` — the UA MUST start with the `user_agent_prefix` registered for the `ihymns` app (`iHymns/`).
- Mutating calls only (POST/PATCH/DELETE) additionally send `X-Timestamp: <unix seconds, decimal digits>` and `X-Signature: intappsSign($rawBody, $ts, $secret)`. GETs are sent unsigned — the server ignores signatures on GET, and phase 1 is GET-only anyway.
- Envelope unwrap branches on **HTTP status + envelope `success` boolean**, never on error prose (rule #35). `errorCode` is carried for logging only.
- URL slug in path must equal the registered slug (`/v1/features/ihymns`); one credential set serves exactly one slug.
- Credential to mint gateway-side: a **scoped key with `read` permission only** (least privilege; the POST `features/batch` endpoint is redundant with the GET list). A `write` key is minted only if/when the crash-relay flip (§8) happens.

### Timeouts and failure behaviour

`CURLOPT_CONNECTTIMEOUT = 2`, `CURLOPT_TIMEOUT = 3`, TLS verification ON, HTTPS-only protocols. Justification: this matches the established house band for request-path lookups (`ip_geolocation.php:229` uses 2/3; app-store verify uses 5) and, combined with the single-flight lock (§2), bounds the worst-case cost of a slow-or-dead gateway to **one request per TTL window paying ≤ 3 s** — everyone else reads cache. `function_exists('curl_init')` guard → `transport: 'unreachable'` degrade (no stream fallback needed; the answer is just "use cache"). Nothing in this module ever throws into a page render: all mysqli work is inside try/catch returning the disabled/degraded shape (the DB layer THROWS under STRICT, rule #5 — the false-check is dead code).

---

## 2. THE CACHE — `tblIntAppsSync` (one table, final shape)

**Where:** a dedicated table, NOT `tblAppSettings` rows. Reasoning: rule #20 says design the final shape once. The cache genuinely needs a keyed row per **(Scope, Channel)** with per-row bookkeeping (fetched-at, attempt-at, lock, failure count) — jamming four scopes × three channels of that into JSON blobs in a key/value settings table means hand-rolled read-modify-write races on the lock. A real UNIQUE-keyed row gives an **atomic** single-flight lock via one conditional UPDATE. The cost — one migration — is paid exactly once because the table is designed final (DDL in §4).

**Scopes** (VARCHAR vocabulary, app-validated, never ENUM): `'features'` (phase 1), `'updates'`, `'notifications'`, `'status'` (reserved, dormant — the rows simply never exist until a consumer is enabled). Adding a scope later is zero schema change.

**Channel:** column present from day one, `DEFAULT ''`. Resolution rule in `intappsCachedScope()`: read the exact-channel row (`serviceMode_channel()` value) first, fall back to the `''` row. **Default operation writes/reads only the `''` row** — one gateway app slug, one MySQL, flags are per-app not per-env, so a shared snapshot is correct and avoids 3× the fetch traffic against the gateway's 60 req/min **per-IP** limit (all three docroots share one egress IP). The reserved discriminator exists because rule #26's prod-stale lesson says cross-env divergence is foreseeable (e.g. staging a flag on alpha first, later, via a per-channel gateway app); when that day comes it is a data change, not an ALTER. This is exactly the `Scope`-reservation pattern from the #1066 batch.

**TTL:** 300 s fresh. Matches the gateway's own server-side Redis TTL (`Feature.php:23-39`) — polling faster buys nothing because the gateway itself serves ≤ 300 s-old answers. Worst-case gateway traffic: 1 scope × 12/hr ≪ 60/min.

**Refresh trigger (no cron, no CLI):** request-piggybacked. The ONE designated seam is the `app_status` handler (`api.php:6272`) — it is already a background poll every client makes, is exempt from maintenance enforcement, and is never render-blocking for a human. It calls `intappsRefreshIfDue($db, 'features')`. The single-flight lock is one atomic statement:

```sql
UPDATE tblIntAppsSync
   SET RefreshLockedUntil = UTC_TIMESTAMP() + INTERVAL 10 SECOND,
       AttemptedAt        = UTC_TIMESTAMP()
 WHERE Scope = ? AND Channel = ?
   AND (RefreshLockedUntil IS NULL OR RefreshLockedUntil < UTC_TIMESTAMP())
   AND (FetchedAt IS NULL OR FetchedAt < UTC_TIMESTAMP() - INTERVAL 300 SECOND)
   AND (AttemptedAt IS NULL OR AttemptedAt < UTC_TIMESTAMP()
        - INTERVAL LEAST(300 * POW(2, ConsecutiveFailures), 3600) SECOND)
```

`affected_rows === 1` wins and performs the fetch (≤ 3 s); everyone else returns instantly. On success: `PayloadJson` + `FetchedAt` updated, `ConsecutiveFailures = 0`. On failure: `ConsecutiveFailures + 1`, `LastHttpStatus`/`LastErrorCode` recorded, **`PayloadJson` and `FetchedAt` untouched** — a failure can never poison or age out the last-known-good snapshot. The exponential backoff means a hard-down gateway costs at most one 3-second attempt per hour, total, across all requests.

**Cold cache** (row absent or `PayloadJson` NULL): consumers get their compiled-in `$default`; the winning request attempts one fetch; if it fails, defaults stand. First-ever population can also be forced from the admin "Refresh now" button (§7), so an operator can warm it deliberately rather than waiting for traffic.

---

## 3. THE FAIL-OPEN CONTRACT (invariant, in rule #28's register)

> **The IntApps integration is ENTIRELY DORMANT unless `tblAppSettings.intappsapi_enabled = '1'` AND a complete credential set exists — and even when enabled, every consumer-visible answer degrades, in order, to: (1) the last-known-good cached snapshot regardless of age, then (2) the compiled-in default supplied by the caller — which is, by definition, exactly what iHymns does today. A gateway that is unreachable, slow, misconfigured, or lying can therefore never make iHymns behave differently from a build with no gateway at all, and can never add more than 3 seconds to more than one background request per TTL window.**

Case table (all verified by the tests in §4/§7):

| Gateway condition | iHymns behaviour |
|---|---|
| `intappsapi_enabled` ≠ `'1'` (the shipped default) | Byte-identical no-op. No HTTP, no `tblIntAppsSync` reads, `app_status` output byte-identical to today (the `remoteFeatures` key is absent, not empty). Verified the `gating-noop-verify` way. |
| Unreachable (DNS/TCP/TLS/timeout) | `transport:'unreachable'`; cache untouched; consumers serve cache-then-default; backoff engages. |
| Answers garbage (non-JSON, no envelope, `success` missing) | Treated identically to unreachable. A malformed answer NEVER overwrites `PayloadJson`. |
| 403 `ACCESS_DENIED` (bad key, revoked app, wrong UA prefix) | Same as unreachable for consumers; `LastHttpStatus=403` recorded for the admin status card; backoff prevents lockout-storming the gateway's 10-failures/min counter. Never logged with the credential value. |
| 429 / 410 / 5xx / 503-degraded | Same as unreachable; status recorded. |
| Slow (hangs) | Curl total timeout caps at 3 s; only the single lock-winner pays; every other request is untouched. |
| Healthy | Snapshot ≤ 300 s stale; consumers still read only the local row — the gateway is never synchronously on any critical path. |

Hard exclusions, stated as part of the invariant: **no gateway flag may ever gate authentication, password reset/magic-link email, song reads, content gating, or media byte-serving.** Those paths keep their existing local mechanisms unconditionally.

---

## 4. SCHEMA — final DDL, one pass, additive + idempotent + dormant

**Migration:** `appWeb/.sql/migrate-add-intapps-sync.php` — `CREATE TABLE IF NOT EXISTS`, safe to re-run, creates a dormant empty table (nothing reads it until `intappsapi_enabled='1'`). No settings-key migration is needed: `tblAppSettings` rows are created on first save by `setAppSetting()`, as every existing credential does.

**DDL (byte-identical in the migration and appended to `appWeb/.sql/schema.sql`, rule #19):**

```sql
-- #<epic> IntAppsAPI gateway sync cache. Dormant until
-- tblAppSettings.intappsapi_enabled='1'. One row per (Scope, Channel);
-- Channel '' = shared across all three docroots (the default operating mode).
CREATE TABLE IF NOT EXISTS tblIntAppsSync (
  Id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Surrogate PK',
  Scope VARCHAR(30) NOT NULL COMMENT 'Gateway data family: features|updates|notifications|status. App-validated vocabulary (rule #20: VARCHAR, never ENUM)',
  Channel VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'Docroot discriminator (serviceMode_channel value) or empty = shared across environments. Reserved multiplicity per rule #20/#26; default operation uses only the empty row',
  PayloadJson JSON NULL COMMENT 'Last-known-GOOD decoded gateway envelope data. Never overwritten by a failed or malformed fetch',
  FetchedAt DATETIME NULL COMMENT 'UTC time of last SUCCESSFUL fetch. NULL = cold. DATETIME not TIMESTAMP (house rule, #1066)',
  AttemptedAt DATETIME NULL COMMENT 'UTC time of last attempt, success or failure — drives exponential backoff',
  RefreshLockedUntil DATETIME NULL COMMENT 'Single-flight lock: a request that wins the conditional UPDATE owns the refresh until this UTC time; stale locks self-expire',
  LastHttpStatus SMALLINT UNSIGNED NULL COMMENT 'HTTP status of last attempt; NULL = transport-level failure (no answer at all)',
  LastErrorCode VARCHAR(50) NULL COMMENT 'Gateway envelope error.code of last failure (ACCESS_DENIED, RATE_LIMITED, ...) for the admin status card; never the message prose',
  ConsecutiveFailures INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Failure streak since last success; backoff = LEAST(300*2^n, 3600) seconds',
  PRIMARY KEY (Id),
  UNIQUE KEY uq_IntAppsSync_ScopeChannel (Scope, Channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='IntAppsAPI gateway local snapshot + refresh bookkeeping (dormant; fail-open contract in includes/intapps_client.php)';
```

Column justifications not already inline: `Id` surrogate PK matches house convention; the UNIQUE `(Scope, Channel)` is the reserved-discriminator pattern (`tblApiKeyUsage.Scope` precedent); `LastHttpStatus`/`LastErrorCode` exist so the admin card can distinguish "never configured" / "down" / "credentials rejected" **by status code** (rule #35) without any log-diving; `ConsecutiveFailures` makes backoff stateless across requests (no daemon exists to hold it).

**Registry entry** — ONE entry appended to `/home/user/iHymns/appWeb/public_html/manage/includes/migration-registry.php` (the four legacy arrays derive from it):

```php
'intapps-sync' => [
    'script' => 'migrate-add-intapps-sync.php',
    'card' => [
        'title'  => 'IntAppsAPI Gateway Sync Cache (#<epic>)',
        'body'   => 'Creates <code>tblIntAppsSync</code>, the local snapshot +'
                  . ' refresh-bookkeeping table for the MWBM IntAppsAPI gateway.'
                  . ' Entirely dormant until the gateway is enabled on'
                  . ' /manage/configuration. Idempotent — safe to re-run.',
        'button' => 'Create IntApps Sync Table',
    ],
    'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblIntAppsSync'),
],
```

Real probe (live-schema check, single object so single-condition is correct — no always-true stub). Every `tblIntAppsSync` read in the client is `_intappsTableExists()`-gated (memoized INFORMATION_SCHEMA probe), so an enabled setting on an un-migrated docroot degrades to defaults instead of throwing under STRICT (rule #28-C shape).

**Tests shipped with the migration (rule #34 — each mutation-tested: break, watch red, restore):** `tests/php/test-intapps-hmac.php` (signer vs fixed vectors, incl. rejecting an ISO-timestamp canonical string — the gateway-examples trap); `tests/php/test-intapps-dormant-noop.php` (asserts `app_status` output byte-identical with the module disabled, and that `intapps_client.php` performs zero HTTP/table access when `intappsEnabled()` is false); `tests/php/test-intapps-flag-boundary.php` (§6). Existing CI (`test-schema-coverage.php`, `test-migration-registry.php`) covers the mirror + probe automatically.

---

## 5. SECRETS

Exactly the established two-layer pattern — nothing invented:

- **Append two keys to `secretSettingKeys()`** (`includes/secret_crypto.php:430`): `'intappsapi_api_key'`, `'intappsapi_hmac_secret'`. Encrypted `enc:v1:` at rest from first save (libsodium/AES-256-GCM, master key in `appWeb/.auth/secrets_master_key.php`, gitignored, outside docroot). Reads transparently decrypt via `getAppSetting()`; writes via `setAppSetting()` fail closed. Registration is zero-risk before any row exists — the `apple_apns_private_key` precedent in the same function documents this exact move.
- **Non-secret settings**, plain `tblAppSettings` rows: `intappsapi_enabled` (default absent ⇒ off), `intappsapi_base_url` (default `https://api.mwbmpartners.ltd`), `intappsapi_app_uuid`, `intappsapi_app_slug` (default `ihymns`). TTL and timeouts are code constants in `intapps_client.php`, not settings — fewer knobs, no way to misconfigure the fail-open bound.
- **Operator path, no shell:** paste the once-only registration output (`app_uuid`, `api_key`, `hmac_secret`) into the `/manage/configuration` card (§7). One MySQL serves all three docroots, so one paste configures all three — consistent with the one-slug-per-credential gateway model.
- Never committed (`.auth/` gitignored; settings live only in DB); never logged (client logs `LastHttpStatus`/`LastErrorCode` only); the session-scrubber's 64-hex token shape already matches both the api_key and hmac_secret formats.

---

## 6. RELATIONSHIP TO EXISTING GATING — the composition rule

**Rule: gateway flags are operational kill switches for app-build behaviour; they compose with existing gating by STRUCTURAL SEPARATION, never by precedence.** Concretely:

1. **A gateway flag may never answer a question an existing registry already answers.** Content entitlement is `TIER_CAPS` → `checkTierAccess()` → `contentGatingApply()`/`contentGatingMediaAllowed()`, with `gatingRulesApply()` deny-rules last (rule #28). Admin capability is `ENTITLEMENTS`. Entity restriction is `checkContentAccess()`. Gateway flags feed NONE of these — not as an input, not as an override, not as a new cap source.
2. **The only two consumption seams are:** (a) the `app_status` payload gains one new top-level object, `remoteFeatures: { "<feature_key>": { "enabled": bool, "metadata": object|null }, ... }`, emitted ONLY when `intappsEnabled()` — clients (native first) read their kill switches there; (b) server-side `intappsFlag($db, $key, $default)` calls in non-gating, non-auth, non-content code paths (e.g. temporarily disabling a cosmetic subsystem). No gateway flag is ever mapped onto an existing `app_status` key (`adsEnabled`, `maintenance`, …) — those stay `tblAppSettings`-backed and locally authoritative. Because the namespaces are disjoint, there is no precedence question to get wrong, and no second source of truth for any question the existing stores answer.
3. **Naming hazard, stated loudly in the module docblock:** `/manage/feature-gating` is tier-capability definitions (`tblGatingCapabilities`/`tblGatingRules`), NOT feature flags. The gateway integration must never appear on, link to, or read from that page or its tables.
4. **The mechanism, not a comment (rules #34/#35):** `tests/php/test-intapps-flag-boundary.php` derives the protected file set from the tree — every file defining or included by `checkTierAccess`, `contentGatingApply`, `gatingRulesApply`, `checkContentAccess`, `tierCaps*` (seed: `includes/content_gating.php`, `ccli_validator.php`, `access_tier_validation.php`, `gating_rules.php`, `content_access.php`, plus anything newly matching `function checkTierAccess|contentGatingApply|gatingRulesApply` under `includes/`) — and FAILS on any reference to `intappsFlag|intappsRequest|intappsCachedScope|tblIntAppsSync` inside them. Mutation test at authoring time: add a dummy `intappsFlag` call to `content_gating.php`, watch red, revert.

Defence of the rule: the honest overlap analysis shows the two systems answer different questions ("may this USER see this?" vs "is this feature ON in this BUILD?"). Composition-by-precedence (gateway overrides local, or vice versa) would recreate the rule-#35 cross-file-agreement failure at system scale; disjoint namespaces make divergence structurally impossible rather than disciplinarily avoided.

---

## 7. ADMIN UI

Two surfaces, both gated `manage_configuration` — matching their nav reality (rule: gate and nav must agree):

1. **Credentials + toggle: a new card on the existing `/manage/configuration`** (already gated `manage_configuration` at page level; already the home of every other third-party credential). Fields: enable checkbox (`intappsapi_enabled`), base URL, app slug, app UUID, API key (secret field, write-only display like SMTP pass), HMAC secret (same). Saves via the page's existing settings-save handler → `setAppSetting()`. No new nav entry needed — the existing `configuration` entry (`admin-links.php:113`) covers it.
2. **Status + snapshot viewer: new page `/manage/intapps-status.php`.** Nav entry in `admin-links.php`: `['intapps-status', '/manage/intapps-status', 'bi-broadcast-pin', 'IntApps Gateway', 'manage_configuration', 'Operations']`; the page's own gate is the SAME `userHasEntitlement('manage_configuration')` check — one entitlement, both places, per the #1587 rule. Shows: enabled/configured state; per-scope row from `tblIntAppsSync` (fetched-at, age vs TTL, last status **code**, error code, failure streak); the decoded `remoteFeatures` snapshot as a read-only responsive table (`admin-table-responsive`, house checkpoint #13); a **"Test connection"** button (POSTs to itself with `validateCsrfRequest()`, rule #29; calls `intappsRequest('GET','/v1/heartbeat')` live and renders the typed result — this is the ONLY place a human-triggered synchronous gateway call happens); and a **"Refresh now"** button (forces `intappsRefreshIfDue()` ignoring TTL but honouring the lock — the cold-cache warm path). Flags are read-only here: the gateway's own admin UI is where flags are edited; iHymns only consumes. Uses shared partials (`admin-nav.php`, `admin-footer.php`, `head-favicon.php`), theme via `admin-theme-init.php`, Bootstrap via `head-libs.php` — no bespoke `<head>` (rule #36).

---

## 8. SCOPE BOUNDARY

**IN (this design, one migration, one PR when built):**
- The client module, HMAC signer, typed result (§1).
- `tblIntAppsSync` + migration + registry entry + schema.sql mirror (§4).
- Feature-flag consumption: `features` scope, `app_status.remoteFeatures` merge, `intappsFlag()` (§6).
- Secrets + `/manage/configuration` card + `/manage/intapps-status` (§5, §7).
- The three new tests + boundary guard (§4, §6).
- **Schema headroom (dormant, zero extra cost):** the `updates`/`notifications`/`status` scopes and the `Channel` discriminator exist in the DDL so later flips are data + code, never ALTERs.

**OUT, deliberately:**
- **Email via `/v1/email/send`** — `EmailService.php` already speaks MS Graph plus five other providers with encrypted creds and audit logging; the gateway path is a strict subset with a latent `ApiException::internal/forbidden` 500 bug on its failure paths, and would put a network hop inside login/reset flows. If MWBM consolidation ("MailerMatt", `EmailService.php:49`) proceeds, it lands as one more `EmailService` driver behind the `email_service` setting — a separate issue, not this integration.
- **Web crash-report relay** — `client_error_report` already scrubs/fingerprints/dedupes into `tblActivityLog`; relaying adds an outbound call to a fire-and-forget path for zero gain.
- **Gateway notifications consumption** — `tblNotifications` + the bell already exist with richer targeting (user/role/env). If "message every MWBM app" is ever wanted, the shape is an admin-triggered relay **into** `tblNotifications` (the `notifications` scope row is reserved for it), never a second client-facing feed.
- **Webhooks, feature analytics** — irrelevant (no consumer surface) and contrary to the no-PII analytics posture, respectively.
- **Heartbeat-out to MWBM ops** — no cron exists to emit it; the gateway can poll iHymns' own `app_status` if MWBM wants liveness.
- **Direct browser→gateway calls** — permanently out: gateway CORS is single-origin, and shipping `X-API-Key`/HMAC secret to a browser is credential disclosure. All web traffic goes through the iHymns PHP backend; the PWA keeps using `apiFetch()` against iHymns' own `/api` (rule #31 — no new fetch pathway client-side at all).
- **Native update-channel + native crash intake** — real gaps, but they ship with the native apps (§9), consuming reserved scopes; nothing to build web-side now beyond the headroom already in the DDL.

**Blocking prerequisites before ANY implementation (gateway-side, owner-actionable):** verify liveness (`curl https://api.mwbmpartners.ltd/v1/status` from a real machine), admin login works, create the `ihymns` app (UA prefix `iHymns/`), mint a `read`-scoped key, capture the once-only credentials. File as the epic's first child issue; note also the gateway has **no HMAC-secret rotation endpoint** (only API-key rotation) — flag to the gateway maintainer.

---

## 9. NATIVE CLIENTS

**Recommendation: Apple/Android/FireOS apps never talk to the gateway directly. They consume everything through iHymns' own API — specifically the `app_status` poll they already need.**

Justification: (1) the gateway's one-slug-per-credential model plus per-IP rate limiting fits a server, not a fleet of devices; (2) an HMAC secret or API key compiled into a store binary is extractable — the gateway contract itself flags this, and proxying removes the decision entirely; (3) the fail-open contract (§3) then lives in ONE place, server-side, instead of being re-implemented in Swift and Kotlin with three chances to get "silently serve the default" subtly wrong; (4) natives already must poll `app_status` for maintenance/MOTD, so `remoteFeatures` arrives with zero new endpoints, zero new client auth.

Later native flips, all pre-shaped by this design: **update polling** — iHymns fetches the gateway `updates` scope into `tblIntAppsSync` and emits an `updates: [{channel, current_version, update_url}]` object in `app_status`, gated behind its own settings flag; the native compares its own version locally. **Crash intake** — natives POST to a new iHymns endpoint modelled on `client_error_report` (same scrub/dedupe/rate-limit, `tblActivityLog` storage, `platform` tag); a server-side relay to the gateway's `/v1/crash-reports/ihymns` (the one place a `write`-scoped key + `intappsSign()` on a POST would be exercised) is a subsequent, independently-flippable step — local storage always succeeds first, relay failure is invisible to the device. Shared native code (per the modularity rule) holds one `AppStatusClient` in the shared Swift package / shared Kotlin module; per-target code only consumes it.

---

### Implementation inventory (so the builder makes zero design decisions)

| File | Action |
|---|---|
| `appWeb/public_html/includes/intapps_client.php` | NEW — §1 functions, §2 lock/backoff, §3 contract in docblock |
| `appWeb/.sql/migrate-add-intapps-sync.php` | NEW — §4 DDL |
| `appWeb/.sql/schema.sql` | APPEND — byte-identical §4 DDL |
| `appWeb/public_html/manage/includes/migration-registry.php` | APPEND — `'intapps-sync'` entry (§4) |
| `appWeb/public_html/includes/secret_crypto.php` | EDIT — two keys in `secretSettingKeys()` (§5) |
| `appWeb/public_html/manage/configuration.php` | EDIT — credentials card (§7) |
| `appWeb/public_html/manage/intapps-status.php` | NEW — status page (§7) |
| `appWeb/public_html/manage/includes/admin-links.php` | EDIT — nav entry, `manage_configuration`, Operations (§7) |
| `appWeb/public_html/api.php` (`app_status`, :6272) | EDIT — `intappsRefreshIfDue()` + conditional `remoteFeatures` merge (§2, §6) |
| `tests/php/test-intapps-hmac.php`, `test-intapps-dormant-noop.php`, `test-intapps-flag-boundary.php` | NEW — §4/§6, each mutation-tested before first green is trusted |

Sequencing per the verdict: file the epic + child issues now (liveness verification first, blocking); build post-launch against a verified-live gateway; ship dormant (`intappsapi_enabled` absent), verify the byte-identical no-op, then flip.
---

## 5. Adversarial stress test

# Adversarial stress-test findings

Verified against the repo before writing: `api.php:6272-6345` (app_status handler), `api.php:17269-17276` (`sendJson`), `js/modules/user-auth.js:42-74`, `js/modules/pwa.js:74-89`, `manage/includes/migration-registry.php` (probe helper naming), `includes/secret_crypto.php:430`, `includes/maintenance.php:46-49` (per-env key suffixing).

## CONCRETE DEFECTS

**1. BLOCKER — The single-flight lock can never be won on a row that does not exist, so the cache never populates and the entire integration is a day-one silent no-op.**
§2's lock is a conditional `UPDATE tblIntAppsSync ... WHERE Scope=? AND Channel=?`. The migration "creates a dormant empty table" and nothing in the design ever INSERTs a `(Scope, Channel)` row. On a cold table the UPDATE matches zero rows, `affected_rows === 0` for every caller, forever — no winner, no fetch, no `PayloadJson`, and `intappsCachedScope()` returns cold. The §7 "Refresh now" warm path has the identical hole ("ignoring TTL but honouring the lock" — still an UPDATE on a nonexistent row). Meanwhile the admin card renders, the toggle saves, "Test connection" succeeds (it calls `intappsRequest()` directly), so every observable surface says "working". This is precisely the codebase's signature failure (rule #30's silent-no-op class; #1339 "never once executed"). The design's own case table even asserts "the winning request attempts one fetch" for cold cache — an assertion its own SQL makes impossible.
**Remedy:** `intappsRefreshIfDue()` first executes `INSERT INTO tblIntAppsSync (Scope, Channel) VALUES (?,?) ON DUPLICATE KEY UPDATE Id = Id` (race-safe via the UNIQUE key, one extra no-op statement per call), then the conditional UPDATE. And the dormant-noop/mutation tests must include a cold-table end-to-end assertion: empty table → one `app_status` call with a stubbed gateway → `PayloadJson` populated. Break the INSERT, watch it red.

**2. MAJOR — `intappsapi_enabled` is one global row in a database shared by all three docroots: no canary, and the kill switch is all-or-nothing across environments.**
One MySQL serves alpha/beta/production (`environment.php:14-16`). A single `intappsapi_enabled` key means flipping it on alpha to trial the integration simultaneously enables it in production — the exact cross-env class rule #26 warns about, and the design reserved a `Channel` discriminator in the *cache* while leaving the *enable gate* env-blind. The house already has two precedents the design ignores: per-env maintenance keys (`maintenance.php:46-49`, `maintenance_mode_<env>`) and the SIWA channel allow-list (`appleWebLoginEnabledForChannel()`, consumed at `api.php:6312`). At 9am Sunday you also cannot kill production while leaving alpha up to diagnose.
**Remedy:** make enablement channel-aware — a `intappsapi_enabled_channels` allow-list setting (the SIWA pattern), checked inside `intappsEnabled()` against `serviceMode_channel()`. Data change only, zero schema cost, restores the ship-dormant→canary-on-alpha→flip-production sequence the repo's own history says it needs.

**3. MAJOR — No response-size cap before decode/store: a misbehaving or compromised gateway can drive shared-hosting memory exhaustion (the #929 OOM class).**
`intappsRequest()` specifies timeouts and TLS but no body-size bound. Curl on a fast link can transfer tens of MB inside the 3 s total timeout; the body is then `json_decode`d (peak PHP array memory ≈ 4-10× body size) and written into `PayloadJson`, and §6 then re-emits the stored `remoteFeatures` (including free-form `metadata_json`, which the gateway contract says is arbitrary) to every anonymous client on every `app_status` call. The fail-open contract bounds *time* ("never add more than 3 seconds") but not *bytes* — an incomplete invariant.
**Remedy:** `CURLOPT_MAXFILESIZE` plus an explicit `strlen($body) > 262144 → treat as malformed` check (never overwrites last-known-good), and state the byte bound in the §3 invariant. Add a fixture test with an oversized 200 body.

**4. MAJOR — The fail-open contract has a hole: a well-formed envelope with the wrong `data` shape can overwrite the last-known-good snapshot.**
§3 defines "malformed" as non-JSON / no envelope / `success` missing. But `ok` = "answered && 2xx && success===true", and `data` is typed `?array`. A `{"success":true,"data":null}` or `{"success":true,"data":{"count":0}}` (no `features` key — reachable via gateway bugs, a proxy interception page behind a captive portal that happens to be JSON, or a gateway version change) passes `ok` and, per §2 "On success: PayloadJson … updated", replaces a good snapshot with garbage or null. Consumers then silently fall to compiled defaults *while the status page shows a fresh green fetch* — a lying dashboard.
**Remedy:** per-scope shape validation before commit — for `features`: `is_array($data['features'] ?? null)` and each element has `feature_key` + `enabled`; anything else is recorded as a failure (`LastErrorCode='BAD_SHAPE'`) and never touches `PayloadJson`/`FetchedAt`. Fixture-test both bad shapes.

**5. MAJOR — Rule #20: the UNIQUE key has no gateway-app discriminator, and a second registered slug forces the second migration the rule forbids.**
`UNIQUE (Scope, Channel)` assumes exactly one gateway app forever. The gateway's model is per-app flags with one credential set per slug (`ResolvesApp.php:46-48`), and the realistic 12-month path — MWBM registering `ihymns-apple` / `ihymns-android` so each store binary gets its own kill-switch set, or a per-env gateway app (which §2 itself names as the future use of `Channel`... but a per-env *gateway app* means a second slug, not just a second channel) — needs a second cache row per scope that this UNIQUE blocks. The design's own cited precedent (`tblApiKeyUsage.Scope` reserved discriminator) argues for reserving it. The workaround (namespaced `feature_key`s inside one app) exists but is a convention fighting the gateway's native per-app model.
**Remedy:** add `AppSlug VARCHAR(50) NOT NULL DEFAULT ''` (matches the gateway's slug charset/length, `AppController.php:55`) to the table and to the UNIQUE as `(Scope, Channel, AppSlug)`. Zero behavioural cost today; default operation uses `''`.

**6. MINOR — The refresh winner's own `app_status` response is delayed up to 3 s, and `app_status` is user-awaited, contradicting "never render-blocking for a human".**
`_ensureAppStatus()` (user-auth.js:51-74) is awaited when the auth modal opens, and `pwa.js:81-89` awaits it in `init()`. The web client fetches it once per boot, not as a background poll. If `intappsRefreshIfDue()` runs before `sendJson()` in the handler, the lock winner — plausibly a user opening the sign-in modal — eats the full gateway timeout. `sendJson()` (api.php:17269) does not exit, so the fix is cheap.
**Remedy:** call `intappsRefreshIfDue()` *after* `sendJson()`, preceded by `fastcgi_finish_request()` where available (else `ignore_user_abort(true)` + flush); document that on SAPIs without either, the winner pays ≤3 s once per TTL window as the accepted worst case.

**7. MINOR — "Refresh now" honouring the backoff condition makes the admin button a silent no-op during a failure streak.**
The eligibility UPDATE includes the `AttemptedAt < now − LEAST(300·2^n, 3600)` backoff clause. §7 says the button ignores TTL "but honour[s] the lock" — ambiguous about backoff. If the manual path reuses the same UPDATE, an operator debugging a 403 clicks Refresh, zero rows match, nothing happens, no error — on the *diagnostic* page.
**Remedy:** the manual path bypasses TTL *and* backoff, honours only `RefreshLockedUntil`, and always renders the typed `intappsRequest` result (or "another refresh in flight"). Assert this in the status-page test.

**8. MINOR — Enabled-checkbox-with-incomplete-credentials is silently off.**
`intappsEnabled()` requires the complete credential set; an operator who ticks the box but leaves one field blank (or whose secret save fails closed) gets a UI showing "enabled" while every consumer behaves disabled. Discoverable on the status page, but only if the operator looks.
**Remedy:** the configuration card's save handler warns when `intappsapi_enabled='1'` and `intappsConfig()===null`, naming the missing key(s); the status page shows the same banner.

**9. MINOR — The boundary guard cannot see the real attack surface: a gateway flag influencing a gated payload from *outside* the protected files.**
`test-intapps-flag-boundary.php` bans `intapps*` references inside the gating modules — good, mutation-testable. But the plausible regression is an `intappsFlag()` call in `api.php`'s `song_detail` assembly *before* `contentGatingApply()` runs, or in `song-media.php` — files the guard deliberately excludes because they legitimately host seam (a). Also, "every file … included by" the gating functions is not mechanically derivable in PHP; that part of the derivation is aspiration, not mechanism (rule #34/#35).
**Remedy:** drop the un-derivable "included by" closure; add a second derived assertion: in `api.php`, `intapps` identifiers may appear only inside the `case 'app_status':` block (parse case boundaries from the tree), and in `song-media.php`/`includes/pages/*` not at all. Mutation-test by planting a call in `case 'song_detail'`.

**10. MINOR — Gateway-controlled strings reach an admin page and the status table; base_url is an SSRF-ish knob.**
`LastErrorCode` (and Test-connection's `errorMessage`, which can be a raw curl string or gateway prose) render on `/manage/intapps-status` — must be `htmlspecialchars`-escaped like everything else, worth stating since the source is a *remote* system. `intappsapi_base_url` lets a `manage_configuration` admin aim server-side GETs (with credentials attached!) at arbitrary hosts and read the response via Test connection. Admin-trusted per house precedent (email endpoints are similar), but the credentials-attached part is new.
**Remedy:** enforce `https://` scheme on save; send `X-App-ID`/`X-API-Key` headers only when the host matches the saved base_url (trivially true, but prevents a future redirect-following curl from leaking them — also set `CURLOPT_FOLLOWLOCATION` false, which the design should state explicitly).

**11. MINOR — The feature-key vocabulary between gateway and consumers has no mechanism (rule #35), pre-shaping the next silent no-op.**
A flag key exists in the gateway admin; its consumer is a string in Swift/Kotlin/PHP with a compiled-in default. A typo'd key = permanent default, no error anywhere — the event-name bug (#1581) at cross-*system* scale, and no CI can span the gateway DB and a store binary. Phase 1 ships zero real consumers (`remoteFeatures` is emitted for native apps that don't exist yet; `intappsFlag()` has no named call site), so nothing exercises the pipeline end-to-end.
**Remedy:** a checked-in key manifest (e.g. `data/intapps-feature-keys.json`: key, default, consumer) that server tests read, plus a status-page diff: "keys in snapshot consumed by nothing / keys consumed but absent from snapshot". Ship at least one real web-side `intappsFlag()` consumer in the launch PR so the pipeline is exercised, not merely renderable.

## AXES THE DESIGN SURVIVES (verified, not assumed)

- **HMAC correctness (axis 5):** `rawBody . '.' . unixSeconds`, hex, digits-only timestamp exactly matches `HmacValidator.php:24-55`; the design explicitly distrusts the four wrong examples and pins it with fixed-vector tests including the ISO-timestamp trap. Phase 1 is GET-only (server ignores GET signatures), so no live signing risk at launch.
- **Staleness via HTTP caching:** `sendJson()` sends `Cache-Control: no-cache, must-revalidate` and no ETag (api.php:17272-17274), so adding `remoteFeatures` cannot be masked by a 304; and `app_status` is not in `$_cacheablePages` (that mechanism is page fragments).
- **PHP session serialisation (axis 4):** `api.php` opens no PHP session (grep confirms `session_start` absent), so a slow gateway cannot serialise a user's parallel requests.
- **STRICT-mysqli fail-open (axis 3):** the contract of try/catch-everything + `_intappsTableExists()` INFORMATION_SCHEMA gating + fail-to-default matches the proven `tierCapsColumnExists()` shape; an enabled-but-un-migrated docroot degrades rather than throws — *provided* defect 4's shape hole is closed.
- **Two sources of truth (axis 6):** structural namespace disjointness (no gateway flag maps onto an existing `app_status` key or feeds `TIER_CAPS`/`gatingRulesApply`/`checkContentAccess`) is total and deterministic — there is no precedence question because there is no shared question. The guard needs defect 9's widening, but the composition rule itself is sound and the `/manage/feature-gating` name-collision is called out.
- **Schema hygiene (axis 1, apart from defect 5):** no ENUM, DATETIME not TIMESTAMP, VARCHAR vocabularies, real probe (`_migProbe_tableExists` — helper name verified against `migration-registry.php:21,183`), byte-identical schema.sql mirror, ONE registry entry, secrets via `secretSettingKeys()` (verified at `secret_crypto.php:430`) with the settings-rows-need-no-migration claim correct.
- **Reversibility (axis 8, apart from defect 2's granularity):** the kill switch is a local `tblAppSettings` row on `/manage/configuration`, readable/writable with the gateway down or lying; disabling removes `remoteFeatures` entirely and clients revert to compiled defaults — a true gateway-independent kill, just not a per-env one until defect 2 is fixed.
- **Scope boundary (axis 2/6):** keeping email, web crash relay, and notifications OUT is correct and well-argued; each rejected path had a concrete existing superior (`EmailService`, `client_error_report`, `tblNotifications`), and the gateway's latent `ApiException::internal/forbidden` 500 bug is rightly treated as disqualifying for auth-critical flows.

**Bottom line:** the architecture (server-proxied, cache-first, dormant, structurally separated from gating) is right and most of the house-rule compliance is real, but defect 1 means the design *as written* ships a feature that has never once executed its core loop — the exact failure mode this codebase's rules exist to prevent — and defects 2-5 are each cheaper to fix now than after the one-pass migration lands.
---

## 6. Executable implementation plan

# iHymns ⇄ MWBM-IntAppsAPI — FINAL EXECUTABLE IMPLEMENTATION PLAN
(v2 — incorporates all 11 stress-test remedies; supersedes the §1–§9 design where they conflict)

Timing per the verdict: **file the epic now; implement post-launch on `claude/wave3-fixes`, only after Issue A (liveness) closes.** Nothing here blocks the merge to main.

Verified anchors used below (re-checked this session): CI globs `tests/php/*.php` (`.github/workflows/test.yml:233,253`) and `tools/run-node-tests.js` globs `tests/*.js` — **new suites are auto-run, no runner edits needed** (rule #35 mechanism already exists). Probe helper is `_migProbe_tableExists` (`migration-registry.php:21,183`). `secretSettingKeys()` at `includes/secret_crypto.php:430`. `app_status` at `api.php:6272`; `sendJson()` at `api.php:17269` does not `exit`. Test doubles live in `tests/php/lib/mysqli_doubles.php`.

---

## 1. THE EPIC + CHILD ISSUES

All filed under one epic before any commit. Titles are final; bodies summarised.

### EPIC: `[Epic] MWBM-IntAppsAPI gateway integration — dormant, fail-open, server-proxied feature-flag foundation`
Body: server-side client + cache + admin surface for the IntAppsAPI gateway, shipped ENTIRELY DORMANT (`intappsapi_enabled_channels` absent ⇒ byte-identical no-op, proven to the rule #28 standard). Gateway flags are operational kill switches only — structurally disjoint from `TIER_CAPS`/`gatingRulesApply()`/`checkContentAccess()`, enforced by a mutation-tested guard. Links the verdict + design + stress-test docs. Native-app consumption is OUT (separate follow-ups, §6).

### Issue A — `IntAppsAPI: verify gateway liveness and register the ihymns app (BLOCKING prerequisite)`
Owner-actionable, from a real machine (the container proxy blocks `api.mwbmpartners.ltd`): `curl -sS https://api.mwbmpartners.ltd/v1/status`; confirm admin login; create app slug `ihymns`, UA prefix `iHymns/`; mint a **read-scoped** key; capture the once-only `app_uuid`/`api_key`/`hmac_secret`; note the gateway's missing HMAC-secret-rotation endpoint to its maintainer.
**AC:** a 200 `/v1/status` transcript pasted into the issue; credentials stored (password manager, never the repo); `GET /v1/features/ihymns` returns a well-formed envelope with the real key.
**Tier: n/a (human).** Blocks B–H.

### Issue B — `IntAppsAPI: tblIntAppsSync migration — final one-pass schema (Scope, Channel, AppSlug)`
Dormant cache/bookkeeping table per design §4 **plus stress remedy 5**: `AppSlug VARCHAR(50) NOT NULL DEFAULT ''` in the table and in the UNIQUE key `(Scope, Channel, AppSlug)`, so a second registered gateway slug (per-platform or per-env apps) is a data change, never a second migration (rule #20).
**AC:** migration idempotent (`CREATE TABLE IF NOT EXISTS`, re-run clean); byte-identical mirror in `schema.sql`; ONE `migration-registry.php` entry, probe `!_migProbe_tableExists($db,'tblIntAppsSync')`; `test-schema-coverage.php`, `test-migration-registry.php`, `test-schema-installs.php` green.
**Tier: sonnet** — standard house migration pattern with a written-out DDL; no judgement calls remain.

### Issue C — `IntAppsAPI: secrets + channel-scoped enablement settings`
Append `intappsapi_api_key`, `intappsapi_hmac_secret` to `secretSettingKeys()`. Non-secret settings: `intappsapi_enabled_channels` (comma/JSON allow-list of channel names — the SIWA `appleWebLoginEnabledForChannel()` pattern, **stress remedy 2**: canary on alpha without enabling production), `intappsapi_base_url` (default `https://api.mwbmpartners.ltd`), `intappsapi_app_uuid`, `intappsapi_app_slug` (default `ihymns`). No `intappsapi_enabled` boolean exists — the allow-list IS the gate.
**AC:** keys encrypt `enc:v1:` on first save; `intappsEnabled()` true only when the current `serviceMode_channel()` is in the allow-list AND `intappsConfig()` is complete; zero migration (settings rows are created on save).
**Tier: haiku** — two-line append + settings constants, purely mechanical against an exact spec.

### Issue D — `IntAppsAPI: client module includes/intapps_client.php (HMAC, typed transport result, fail-open)`
Design §1 signatures, with remedies baked in: **(3)** `CURLOPT_MAXFILESIZE` + `strlen($body) > 262144 ⇒ malformed` (never touches last-known-good; byte bound added to the §3 invariant); **(4)** per-scope shape validation before commit — for `features`, `is_array($data['features'] ?? null)` and every element has `feature_key` + `enabled`, else record failure `LastErrorCode='BAD_SHAPE'` and leave `PayloadJson`/`FetchedAt` untouched; **(10)** `CURLOPT_FOLLOWLOCATION=false`, auth headers attached only when the request host equals the saved base_url host, `https://` scheme enforced (loopback hosts `127.0.0.1`/`::1`/`localhost` exempt, solely so the local stub gateway in §3 is testable); **(1)** `intappsRefreshIfDue()` executes the **seed INSERT before the lock UPDATE** (see §2 commit 3 for the exact SQL — this resolves the BLOCKER); **(7)** `intappsForceRefresh()` added: bypasses TTL AND backoff, honours only `RefreshLockedUntil`, returns the typed `intappsRequest` result. Signer: `hex(HMAC-SHA256(rawBody . '.' . unixSeconds))` per the gateway's `HmacValidator.php`, never its bundled examples.
**AC:** `test-intapps-hmac.php` (fixed vectors incl. rejecting the ISO-timestamp trap) and `test-intapps-client.php` (cold-table populate, lock contention, backoff arithmetic, oversized-body, `{"success":true,"data":null}` and missing-`features` shapes, malformed-never-overwrites) green; module loads side-effect-free; nothing in it can throw into a render path.
**Tier: opus** — this file IS the fail-open contract; the single-flight SQL, backoff, and shape-validation edge cases are exactly the silent-no-op class the stress test caught the first design shipping.

### Issue E — `IntAppsAPI: app_status wiring (post-response refresh + remoteFeatures emit) + dormant no-op + cold-cache tests`
In `api.php` `case 'app_status':` — when `intappsEnabled()`: merge `remoteFeatures` from `intappsCachedScope()` into the payload **before** `sendJson()`; call `intappsRefreshIfDue()` **after** `sendJson()`, preceded by `fastcgi_finish_request()` where available else `ignore_user_abort(true)` + flush (**remedy 6** — the lock winner's response is not delayed; documented residual: SAPIs with neither pay ≤3 s once per TTL window). When disabled: the key is **absent, not empty**.
**AC:** `test-intapps-dormant-noop.php` green (disabled ⇒ output byte-identical, zero HTTP, zero `tblIntAppsSync` access); **cold-table end-to-end** green (empty table + stubbed gateway + one `app_status` call ⇒ `PayloadJson` populated — the mutation test for the BLOCKER: comment out the seed INSERT, watch red, restore); behavioural diff in §4 empty.
**Tier: sonnet** for the wiring; the two tests reviewed by **opus** before first green is trusted (rule #34).

### Issue F — `IntAppsAPI: boundary guard — gateway flags can never reach gating code`
**Remedy 9 shape**, replacing the un-derivable "included-by closure": (i) ban `intappsFlag|intappsRequest|intappsCachedScope|intappsForceRefresh|tblIntAppsSync` in the gating modules, the file list **derived** by grepping `includes/` for `function checkTierAccess|function contentGatingApply|function gatingRulesApply|function checkContentAccess|function tierCap` (seed set: `content_gating.php`, `ccli_validator.php`, `access_tier_validation.php`, `gating_rules.php`, `content_access.php`); (ii) in `api.php`, parse `case '...'` boundaries and assert `intapps` identifiers appear **only** inside `case 'app_status':`; (iii) zero occurrences in `song-media.php` and `includes/pages/*`.
**AC:** guard mutation-tested three ways before merge — plant `intappsFlag()` in `content_gating.php`, in `case 'song_detail':`, and in `song-media.php`; each goes red; revert. Guard passes on the correct tree (narrow enough not to fail legitimate code — rule #34's other half).
**Tier: opus** — the repo's guard-writing history (rule #34: wrong-but-green twice in one pass) says this is hard-reasoning work.

### Issue G — `IntAppsAPI: admin surfaces — /manage/configuration card + /manage/intapps-status`
Design §7 with remedies: **(8)** save handler warns (named missing keys) when channels are enabled but `intappsConfig()===null`, same banner on the status page; **(7)** "Refresh now" calls `intappsForceRefresh()` and always renders the typed result or "another refresh in flight"; **(10)** every remote-sourced string (`LastErrorCode`, curl/gateway prose from Test connection) through `htmlspecialchars()`; base_url scheme validation on save. Both surfaces gated `userHasEntitlement('manage_configuration')`; nav entry in `admin-links.php` advertises the same entitlement (#1587 parity). Shared partials, `head-libs.php`, `admin-table-responsive`, `validateCsrfRequest()` on the two POST buttons.
**AC:** `test-admin-gate-parity.php` green (derived — picks the new page up automatically); behavioural checks in §3.
**Tier: sonnet** — assembling proven house partials to a written spec.

### Issue H — `IntAppsAPI: feature-key manifest + first real consumer (Song of the Day card kill switch)`
**Remedy 11.** Checked-in `data/intapps-feature-keys.json` — `[{ "key": "web.sotd_card", "default": true, "consumer": "appWeb/public_html/includes/pages/home.php" }]`. New `test-intapps-key-manifest.php`: every manifest key's consumer file exists and contains an `intappsFlag(..., '<key>', ...)` call with the manifest default; every `intappsFlag()` call site in the tree (derived by grep) has a manifest entry. Status page renders the diff both ways ("in snapshot, consumed by nothing" / "consumed, absent from snapshot"). The consumer: `includes/pages/home.php` wraps the Song-of-the-Day card emit in `intappsFlag($db, 'web.sotd_card', true)` — cosmetic, global (safe in the shared-cache fragment per rule #6: no per-user divergence), never content/auth, and default-true means dormant = today's behaviour. Register the key in the gateway admin (owner, piggybacks Issue A credentials).
**AC:** manifest test mutation-tested (rename the key in home.php only ⇒ red); with the local stub serving `enabled:false`, a fresh home fragment omits the card; disabled/default paths byte-identical to today.
**Tier: sonnet.**

### Issue I — `IntAppsAPI: docs + standing-tasks sweep`
`api-docs.yaml` (`remoteFeatures` on `app_status`), wiki (API/Architecture/Schema/Setup), `CHANGELOG.md`, `DEV_NOTES.md`, `ProjectBrief.md`, this epic's status, session handoff. Also record the two accepted residuals in `DEV_NOTES.md`: gateway lacks HMAC-secret rotation; per-SAPI winner-pays-3s fallback.
**AC:** standing-tasks.md checklist run; nothing silently skipped.
**Tier: haiku** — mechanical doc sync against this plan.

Dependency order: **A → B → C → D → E → F → G → H → I** (F can land any time after D; scheduled after E so the mutation test can target the real `app_status` block).

---

## 2. THE COMMIT SEQUENCE

One PR from `claude/wave3-fixes` → `alpha`. Nine commits, each atomic, revertable, green. Every commit body explains WHY and cites its issue (`Closes #<n>`). Pre-commit hooks never skipped.

**Commit 1 — `feat(db): add tblIntAppsSync dormant gateway sync cache (#B)`**
Files: `appWeb/.sql/migrate-add-intapps-sync.php` (NEW), `appWeb/.sql/schema.sql` (APPEND, byte-identical), `appWeb/public_html/manage/includes/migration-registry.php` (APPEND one entry).
DDL = design §4 **with remedy 5 applied**:
```sql
  AppSlug VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'Gateway app slug this row caches ('''' = the primary ihymns app). Reserved multiplicity for per-platform/per-env gateway apps (rule #20); default operation uses only the empty value',
  ...
  UNIQUE KEY uq_IntAppsSync_ScopeChannelApp (Scope, Channel, AppSlug)
```
Verify:
```bash
php -l appWeb/.sql/migrate-add-intapps-sync.php
php tests/php/test-schema-coverage.php
php tests/php/test-migration-registry.php
php tests/php/test-schema-installs.php        # real MariaDB build incl. the new table
```

**Commit 2 — `feat(secrets): register IntAppsAPI credential keys + channel allow-list settings (#C)`**
Files: `appWeb/public_html/includes/secret_crypto.php` (two keys in `secretSettingKeys()`); settings-name constants in the (next commit's) client are documented here in the commit body only — no other code yet.
Verify:
```bash
php -l appWeb/public_html/includes/secret_crypto.php
for f in tests/php/test-*.php; do php "$f" || exit 1; done   # full existing suite
```

**Commit 3 — `feat(intapps): client module — HMAC, single-flight cache, fail-open transport (#D)`**
Files: `appWeb/public_html/includes/intapps_client.php` (NEW), `tests/php/test-intapps-hmac.php` (NEW), `tests/php/test-intapps-client.php` (NEW, uses `tests/php/lib/mysqli_doubles.php` + a curl-injection seam so no network is needed).
**BLOCKER RESOLUTION (stress defect 1)** — `intappsRefreshIfDue()` body, in order:
```php
// 1. SEED: the lock row must exist before a conditional UPDATE can ever win.
//    Race-safe via uq_IntAppsSync_ScopeChannelApp; a no-op when present.
$stmt = $db->prepare('INSERT INTO tblIntAppsSync (Scope, Channel, AppSlug)
                      VALUES (?,?,?) ON DUPLICATE KEY UPDATE Id = Id');
$stmt->bind_param('sss', $scope, $channel, $appSlug); $stmt->execute();
// 2. LOCK: the atomic single-flight conditional UPDATE (design §2, unchanged
//    except WHERE ... AND AppSlug = ?). affected_rows === 1 wins.
// 3. FETCH (winner only): intappsRequest(); commit ONLY a shape-valid body
//    (remedy 4); size-capped (remedy 3); failure bumps ConsecutiveFailures
//    and never touches PayloadJson/FetchedAt.
```
Also in this commit: `intappsForceRefresh()` (remedy 7), size cap + shape validation (remedies 3/4), header/host binding + no-follow + scheme rule (remedy 10). All `tblIntAppsSync` access `_intappsTableExists()`-gated and try/caught (rule #5 STRICT).
Verify:
```bash
php -l appWeb/public_html/includes/intapps_client.php
php tests/php/test-intapps-hmac.php
php tests/php/test-intapps-client.php
# Mutation pass (recorded in the commit body, then reverted):
#   comment out the seed INSERT  -> test-intapps-client.php cold-table case RED
#   restore                      -> GREEN
```

**Commit 4 — `feat(api): app_status remoteFeatures emit + post-response refresh (#E)`**
Files: `appWeb/public_html/api.php` (edits confined to `case 'app_status':`), `tests/php/test-intapps-dormant-noop.php` (NEW).
Order inside the handler (remedy 6): build payload → merge `remoteFeatures` iff `intappsEnabled()` → `sendJson()` → `fastcgi_finish_request()`/flush → `intappsRefreshIfDue()`.
Verify:
```bash
php -l appWeb/public_html/api.php
php tests/php/test-intapps-dormant-noop.php
php tests/php/test-intapps-client.php
# Behavioural (local instance, §3): disabled app_status byte-diff empty (§4 procedure)
```

**Commit 5 — `test(guard): intapps↔gating boundary guard, tree-derived + mutation-tested (#F)`**
Files: `tests/php/test-intapps-flag-boundary.php` (NEW). Auto-run by CI's glob — no workflow edit.
Verify:
```bash
php tests/php/test-intapps-flag-boundary.php     # green on correct tree
# Mutation triptych (each: plant, RED, revert — transcript in commit body):
#   intappsFlag() in includes/content_gating.php
#   intappsFlag() in api.php case 'song_detail':
#   intappsFlag() in song-media.php
```

**Commit 6 — `feat(admin): IntAppsAPI configuration card + status page (#G)`**
Files: `appWeb/public_html/manage/configuration.php` (card), `appWeb/public_html/manage/intapps-status.php` (NEW), `appWeb/public_html/manage/includes/admin-links.php` (entry: `manage_configuration`, Operations group).
Verify:
```bash
php -l appWeb/public_html/manage/intapps-status.php appWeb/public_html/manage/configuration.php
php tests/php/test-admin-gate-parity.php
php tests/php/test-a11y-static-checks.php
# Behavioural: status page renders "not configured"; enabled+missing-secret warning shows (remedy 8)
```

**Commit 7 — `feat(intapps): feature-key manifest + web.sotd_card consumer (#H)`**
Files: `data/intapps-feature-keys.json` (NEW), `tests/php/test-intapps-key-manifest.php` (NEW), `appWeb/public_html/includes/pages/home.php` (SOTD card wrapped in `intappsFlag($db,'web.sotd_card',true)`).
Verify:
```bash
php -l appWeb/public_html/includes/pages/home.php
php tests/php/test-intapps-key-manifest.php
php tests/php/test-fragment-inline-scripts.php   # home.php touched — fragment guard must stay green
# Mutation: typo the key in home.php only -> manifest test RED; revert
```

**Commit 8 — `docs: IntAppsAPI integration — api-docs, wiki, changelog, project docs (#I)`**
Files: `api-docs.yaml`, `iHymns.wiki/*`, `CHANGELOG.md`, `DEV_NOTES.md`, `.claude/ProjectBrief.md`, `.claude/sessions/<date>-HANDOFF.md`.
Verify: `npm run test:all` (docs can't break code, but the commit must still leave the tree provably green).

**Commit 9 — `chore: full-suite re-verification sweep (#Epic)`**
No functional changes — the owner's explicit "re-verify the whole codebase" gate, recorded as the final commit body with the full transcript of §3's global pass. If anything is red, it is fixed in a preceding amended-before-push commit, never here.

---

## 3. THE VERIFICATION PLAN

**Existing suites that must stay green (all of them — both runners are globs, so the new suites join automatically and nothing is hand-listed, rule #35):**
```bash
npm run test:all                                  # = node glob runner + php -l sweep + node --check sweep
for f in tests/php/test-*.php; do echo "== $f"; php "$f" || exit 1; done   # the ~80 PHP suites, CI-equivalent
```
Watch specifically (files this work touches or neighbours): `test-schema-coverage.php`, `test-migration-registry.php`, `test-schema-installs.php`, `test-admin-gate-parity.php`, `test-fragment-inline-scripts.php`, `test-component-json-guard.php`, `test-csrf-same-origin.php`, `test-event-names.js`, `test-api-client-usage.js`.

**New suites (5, all auto-globbed):** `test-intapps-hmac.php`, `test-intapps-client.php`, `test-intapps-dormant-noop.php`, `test-intapps-flag-boundary.php`, `test-intapps-key-manifest.php`. Each is mutation-tested at authoring time (break → red → restore), transcript in its introducing commit body.

**Behaviourally verifiable HERE (MariaDB + `php -S 127.0.0.1:8123 -t appWeb/public_html` + fixture DB):**
1. Migration end-to-end via the real web path: log in to `/manage/setup-database` on :8123, apply the "Create IntApps Sync Table" card, confirm the probe flips pending→applied, re-apply (idempotency), confirm Schema Audit shows no orphan.
2. **Stub gateway** — a 20-line `stub-gateway.php` in the scratchpad served by a second `php -S 127.0.0.1:8124`, answering `/v1/features/ihymns` with a canned envelope (variants: good, `data:null`, missing-`features`, 1 MB body, 403, hang via `sleep(10)`). The loopback `http://` carve-out (remedy 10) exists precisely for this.
3. Cold-cache loop (the BLOCKER's behavioural proof): `TRUNCATE tblIntAppsSync;` → set channel allow-list + creds + `intappsapi_base_url=http://127.0.0.1:8124` → one `curl 'http://127.0.0.1:8123/api?action=app_status'` → `SELECT Scope,FetchedAt,PayloadJson IS NOT NULL FROM tblIntAppsSync;` shows a populated `features` row.
4. Fail-open matrix against the stub variants: garbage/oversized/403/hang each leave `PayloadJson` untouched, bump `ConsecutiveFailures`, record `LastHttpStatus`/`LastErrorCode` (`BAD_SHAPE` for the shape cases), and `app_status` keeps serving last-known-good; hang case: winner request ≤ ~3.2 s wall (`time curl ...`), concurrent second curl unaffected.
5. Backoff: two consecutive stub-403 cycles → third `app_status` inside the backoff window performs no fetch (stub access log shows nothing).
6. Admin surfaces: Test connection (typed result rendered, escaped), Refresh now during a failure streak (bypasses backoff — remedy 7 behavioural check), enabled-with-missing-secret warning (remedy 8), gate parity (anonymous → 403/redirect on `/manage/intapps-status`).
7. Channel canary: allow-list containing only the non-local channel name ⇒ local instance behaves fully disabled (remedy 2).
8. Consumer: stub serves `web.sotd_card:false` → fresh home fragment omits the SOTD card; `true`/disabled → card present and byte-identical to baseline.

**CANNOT be verified here — explicitly deferred to Issue A / owner, from a real machine:**
- Gateway liveness, TLS chain, and that `GET /v1/features/ihymns` works with the **real** credentials (the container's egress proxy blocks the host; a proxy 403 is not evidence either way).
- Real-world UA-prefix acceptance and the 60 req/min per-IP behaviour under the three shared-docroot egress IPs.
- HMAC acceptance on a live signed **POST** — phase 1 is GET-only so this is not launch-blocking, but the signer must be proven against the live server before any write-scoped follow-up (Issue K, §6) is trusted. Until then the signer's correctness rests on fixed vectors derived from `HmacValidator.php` source.
- Gateway-side flag registration for `web.sotd_card`.
The flip to enabled on any real environment happens **only after** Issue A's transcript exists; that ordering is stated in the epic and in `intapps_client.php`'s docblock.

---

## 4. THE NO-OP PROOF

Standard: rule #28's `content_gating` bar — **byte-identical output, zero table access, zero outbound**, while `intappsapi_enabled_channels` is absent (the shipped state).

```bash
# ---- A. Byte-identical API output: merge-base vs branch, same fixture DB ----
BASE=$(git merge-base alpha claude/wave3-fixes)
git worktree add /tmp/claude-0/-home-user-iHymns/eecf773e-4f1c-5106-9640-a22245226a39/scratchpad/noop-base "$BASE"
php -S 127.0.0.1:8125 -t /tmp/claude-0/-home-user-iHymns/eecf773e-4f1c-5106-9640-a22245226a39/scratchpad/noop-base/appWeb/public_html &   # baseline
php -S 127.0.0.1:8123 -t appWeb/public_html &                                                                                             # branch, module dormant

ENDPOINTS='action=app_status action=songs_index action=song_detail&id=MP-1 page=home page=song&id=MP-1 action=access_tiers'
mkdir -p /tmp/claude-0/-home-user-iHymns/eecf773e-4f1c-5106-9640-a22245226a39/scratchpad/noop/{base,branch}
for e in $ENDPOINTS; do
  curl -s "http://127.0.0.1:8125/api?$e" -o ".../noop/base/$(echo $e | tr '&=' '__')"
  curl -s "http://127.0.0.1:8123/api?$e" -o ".../noop/branch/$(echo $e | tr '&=' '__')"
done
diff -r .../noop/base .../noop/branch          # MUST be empty — any byte fails the proof
# (home fragment served fresh both sides — same DB, so identical unless the branch leaks)

# ---- B. Zero tblIntAppsSync access while dormant ----
mysql -e "SET GLOBAL general_log='ON'; SET GLOBAL general_log_file='/tmp/.../scratchpad/noop/general.log';"
for e in $ENDPOINTS; do curl -s "http://127.0.0.1:8123/api?$e" >/dev/null; done
curl -s "http://127.0.0.1:8123/" >/dev/null
mysql -e "SET GLOBAL general_log='OFF';"
grep -ci tblIntAppsSync /tmp/.../scratchpad/noop/general.log    # MUST print 0

# ---- C. Zero outbound while dormant ----
php tests/php/test-intapps-dormant-noop.php
#   asserts intappsEnabled()===false with no settings rows, and that the disabled
#   path structurally cannot reach curl (the curl seam records zero invocations
#   across a full simulated app_status dispatch)

# ---- D. Prove the proof can fail (rule #34) ----
#   temporarily emit remoteFeatures unconditionally in api.php  -> step A diffs RED
#   temporarily drop the intappsEnabled() guard on the cache read -> step B count > 0
#   revert both; transcript recorded in commit 9's body
```
The A/B/C transcript plus the D mutation record is the deliverable of commit 9 and is pasted into the epic before the PR is marked ready.

---

## 5. THE ROLLBACK

**Runtime (no deploy, works with the gateway down or lying):**
```sql
DELETE FROM tblAppSettings WHERE SettingKey = 'intappsapi_enabled_channels';
-- or narrow the canary: UPDATE ... SET SettingValue='alpha' WHERE SettingKey='intappsapi_enabled_channels';
```
Effect is immediate and total: `intappsEnabled()` false ⇒ no fetches, no table reads, `remoteFeatures` absent, `web.sotd_card` consumer returns its compiled default `true`. This is the 9am-Sunday lever, and it is per-channel (remedy 2).

**Per-commit `git revert <sha>`, each independently clean:**
- Commit 7 (consumer/manifest): revert alone — home reverts to unconditional SOTD; manifest test leaves with it.
- Commit 6 (admin UI): revert alone — settings rows persist harmlessly (unread keys).
- Commit 5 (guard): revert alone — no functional change.
- Commit 4 (app_status wiring): revert alone — client module becomes uncalled dead-but-green code.
- Commit 3 (client): revert 7→4 first (they call it), then 3.
- Commit 2 (secrets): revert after 3 — leaves any saved `enc:v1:` rows unreadable-as-secrets but inert.
- Commit 1 (schema): revert removes the migration/registry/schema.sql text; an already-created `tblIntAppsSync` on a live DB is left as a dormant orphan (Schema Audit will flag it) — drop manually if desired: `DROP TABLE IF EXISTS tblIntAppsSync;` (safe: nothing references it once 3–7 are reverted; it holds only re-fetchable cache).

**Whole-feature revert:** `git revert <c7> <c6> <c5> <c4> <c3> <c2> <c1>` in that order (docs commits 8–9 optional), then the DROP above. No data loss is possible at any point — the table contains nothing but a re-fetchable snapshot and bookkeeping.

---

## 6. DELIBERATELY NOT DONE (each gets a `for consideration` follow-up issue, filed with the epic)

| Not done | Why | Follow-up issue title |
|---|---|---|
| Email via gateway `/v1/email/send` | `EmailService.php` is a superset (6 providers, encrypted creds, audit log); gateway path has a latent `ApiException::internal/forbidden` 500 bug on failure paths and would put a network hop inside login/reset | `EmailService: optional IntAppsAPI/MailerMatt driver behind the email_service setting` |
| Web crash-report relay to `/v1/crash-reports` | `client_error_report` already scrubs/fingerprints/dedupes into `tblActivityLog`; relay adds an outbound call to a fire-and-forget path for zero gain | `IntAppsAPI: evaluate server-side relay of client_error_report fingerprints (post write-key proof)` |
| Gateway notifications consumption | `tblNotifications` + bell has richer targeting; correct future shape is an admin-triggered relay INTO it (the reserved `notifications` scope row) | `IntAppsAPI: MWBM-wide announcement relay into tblNotifications` |
| Native (Swift/Kotlin) clients + update-channel + crash intake | Native apps aren't launching; they consume via iHymns' `app_status` proxy (design §9), never the gateway directly (credential-in-binary + per-IP rate limit) | `Native apps: consume remoteFeatures + updates via app_status` / `Native crash intake endpoint modelled on client_error_report` |
| Write-scoped key + signed POST paths | Phase 1 is GET-only; the live server has plausibly never validated a correct signature for anyone (all four bundled examples sign wrong) — prove `intappsSign()` against the live endpoint before any write flow trusts it | `IntAppsAPI: live-endpoint HMAC POST verification (prereq for any write scope)` |
| Second gateway app slug (per-platform / per-env) | Schema headroom (`AppSlug` in the UNIQUE) already reserved; activating it is data + config, no ALTER | `IntAppsAPI: per-platform gateway apps via AppSlug rows` |
| Webhooks + feature analytics | No consumer surface; analytics contrary to the no-PII posture | `IntAppsAPI: webhooks/analytics — declined, record rationale` |
| Gateway-side fixes (wrong HMAC examples, missing secret-rotation endpoint, `ApiException` bug) | Wrong repo — belongs to the gateway maintainer; iHymns only needs them noted | `Upstream: report HMAC example bug + secret-rotation gap to MWBM-IntAppsAPI` |
| In-code #1352/#1353/#1354 citation cleanup encountered while editing gating files | Per rule #28's own policy: corrected only in files this work touches, never a mass sweep | (covered by existing citation-warning policy; no new issue) |

**Stress-test disposition summary:** defect 1 (BLOCKER) — resolved by the seed-INSERT-before-lock in commit 3 plus the cold-table end-to-end test and its recorded mutation pass; defects 2–5 (MAJOR) — resolved in Issues C/D/B respectively (channel allow-list, size cap, shape validation, `AppSlug` in the UNIQUE); defects 6–11 (MINOR) — resolved in Issues E (post-`sendJson` refresh), D/G (`intappsForceRefresh` bypassing backoff), G (misconfig warning, escaping, scheme/host binding), F (widened, derivable guard), H (key manifest + real consumer). Nothing from the stress test is deferred.
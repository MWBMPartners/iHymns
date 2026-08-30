# Security

> Security measures, authentication, and best practices

---

## Content Security Policy (CSP)

Every request generates a unique nonce for inline scripts. The CSP header includes:

```text
default-src 'self';
script-src 'self' 'nonce-<random>' https://cdn.jsdelivr.net ...;
style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net ...;
img-src 'self' data: https:;
font-src 'self' https://cdn.jsdelivr.net ...;
connect-src 'self' https://www.google-analytics.com ...;
frame-ancestors 'self';
base-uri 'self';
form-action 'self';
upgrade-insecure-requests;
```

All CDN resources include **Subresource Integrity (SRI)** hashes.

The CSP is **enforcing** — no `'unsafe-inline'` on `script-src`. Because of this, SPA page fragments (served as separate, sometimes shared-cache, HTTP responses from `api.php?page=...`) must never carry an executable inline `<script>`: the browser silently refuses a nonce-less script node, with no visible error. A CI guard (`tests/php/test-fragment-inline-scripts.php`) fails the build on any such script under `includes/pages/` or `includes/partials/`. See [[Architecture]] for the full SPA-fragment pattern.

### Client error telemetry (#1582)

Uncaught browser errors surface one generic toast to the user and are beaconed — deduplicated, throttled, and privacy-scrubbed — to the existing activity log (`POST ?action=client_error_report` → `tblActivityLog`, `Action=client.jserror`). Scrubbing strips bearer tokens, 64-hex strings, and query-string secrets, and reduces stack traces to `pathname:line`. Reports are anonymous, rate-limited, and fail open — this is not a new PII surface, and not consent-gated analytics.

---

## Authentication Security

### Password Hashing

- Algorithm: **BCRYPT** (`PASSWORD_BCRYPT`)
- Cost factor: **12** (higher than default for stronger protection)
- PHP's `password_hash()` / `password_verify()` — timing-safe comparison

### Bearer Tokens

- 64-character lowercase hexadecimal (32 bytes of `random_bytes()`)
- 30-day expiry with server-side validation
- Stored in `tblApiTokens` table
- Deleted on logout and password reset
- **Device metadata + management (#1409 / #1975):** each token row carries an optional `DeviceName` / `Platform` / `AppVersion` / `LastSeenAt`. A web sign-in is auto-named server-side from the request `User-Agent` (e.g. "Chrome on Windows") at token-issue time, so the Settings → Signed-in devices list is legible rather than a wall of "Unnamed device". Users can remotely sign a device out (`device_signout`) or rename it (`device_rename`) — both are own-only (`WHERE UserId = ?`), same-origin-CSRF-gated (`validateCsrfRequest()`) and per-user rate-limited. The client is only ever handed a truncated hash **prefix** as the device id, never the raw token nor the full stored hash.

### Password Reset Tokens

- 48-character lowercase hexadecimal (24 bytes of `random_bytes()`)
- 1-hour expiry
- Single-use (marked as `Used` after consumption)
- Previous tokens for the same user are deleted when a new one is generated
- Password reset invalidates ALL API tokens (forces re-login on all devices)

### Session Security (Admin Panel)

- `httponly` flag — prevents JavaScript access to session cookie
- `samesite=Strict` — prevents CSRF via cross-site requests
- `secure` flag — when HTTPS is detected
- `session_regenerate_id(true)` on login — prevents session fixation
- Session cookie scoped to `/manage/` path only

### CSRF Protection

- Per-session CSRF token (64 hex chars via `random_bytes(32)`)
- Validated with `hash_equals()` — timing-safe comparison
- Required on all admin panel form submissions
- State-changing AJAX (the editor save, duplicate-songs merge/delete, musician-duplicates merge/dismiss/undismiss (#1785), publishers CRUD (#93), licence-types CRUD (#1769), the tiers / content-restrictions / entitlements gating pages (#1769 P0), the set-list share-link mint/update/revoke (#1791), `live_follow_extend` (#1798), places-api, and every legacy editor POST) instead calls `validateCsrfRequest()`, which accepts EITHER a still-valid session token OR a genuine same-origin request: the `X-Requested-With` header (a browser cannot set it cross-origin without a CORS preflight this server never grants) plus any present `Origin`/`Referer` matching the request's own host **and port**. This exists because a baked per-session token goes stale on a long-lived page (rotates, GCs, or changes across tabs) and produces a sporadic "CSRF error" on save — the same-origin check never goes stale. The port comparison was itself a fix (#1709): `HTTP_HOST` keeps the port but a parsed `Origin`/`Referer` host does not, so the original naive string compare could never match a site on a non-default port, and separately never rejected a *different* port on the same host as if it were same-origin.
- **Database Setup dashboard** (2026-08-30 audit finding L-1): `/manage/setup-database.php`'s `?action=` links (Install, Apply-all-migrations, Backup, Restore, Drop-legacy, OPcache-reset, every per-migration card) are plain GET links, so before this fix `SameSite=Strict` was the *only* defence against a forged cross-site request (e.g. an `<img src>` pointing at a backup/restore action). `validateCsrfRequest()` now gates all three of the page's dispatch paths as one choke point ahead of every one of them; the single genuinely read-only action is exempt. Every link/form on the page carries its own token so the no-JavaScript fallback keeps working.

### Outbound-request SSRF protection

Three server-side HTTP clients call out to admin-configured base URLs: the CueRCode QR service (`includes/cuercode_client.php`), the MWBM-IntAppsAPI gateway (`includes/intapps_client.php`), and the Internet Archive reconciliation client (`includes/ia_client.php`). Before the 2026-08-30 audit (finding L-2), each treated `https://` as sufficient proof a URL was safe to dial, regardless of where it actually resolved — an admin-typed URL (a typo, or a compromised admin account) could point at a cloud-metadata address (`169.254.169.254`), a loopback service, or an internal `10.x`/`192.168.x` host, and the client would dial it anyway. The shared core `includes/network_guard.php` (`ihymnsHostResolvesPrivate()`) resolves a host to its IPv4 and IPv6 addresses and refuses any that land in a private (RFC 1918/4193) or reserved (loopback, link-local) range — mirroring `manage/configuration.php`'s pre-existing SMTP-host check (#1304). All three clients now call the one shared function rather than each carrying its own copy; the CueRCode/IntApps admin save handlers surface an immediate heads-up if a saved URL will now be refused, rather than a later, confusing "test failed".

---

## Database Security

### MySQLi Prepared Statements

**All** queries — song data, admin panel, and auth alike — go through the single `getDbMysqli()` connection factory (`includes/db_mysql.php`) using MySQLi with **prepared statements**; every value entering a SQL string is bound via `$stmt->bind_param(...)`, never string-interpolated. This prevents SQL injection attacks.

**PDO was fully removed from the codebase** (#554/#555) — there is no PDO connection anywhere, admin panel included. mysqli runs under `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`, so a failing statement throws rather than silently returning `false`.

### Credential Storage

- Stored in `appWeb/.auth/db_credentials.php` — **outside the public web root**
- File permissions set to `0600` (owner read/write only) by the installer
- `.htaccess` in `appWeb/.auth/` denies all web access (defense-in-depth)
- Credentials file is excluded from version control via `.gitignore`

### Database Naming Convention

- Tables: `tblCamelCase` (e.g., `tblSongs`, `tblUserGroups`)
- Columns: `CamelCase` (e.g., `SongId`, `CreatedAt`, `SongbookAbbr`)

---

## Content Tier Gating

> ⚠️ **DORMANT ON ALL THREE ENVIRONMENTS.** Everything in this section is a
> verified no-op while `tblAppSettings.content_gating_enabled = '0'`, which is
> the current live value on `dev`, `beta` and `www`. The code is written, tested
> and ready; it is not switched on. **Do not cite this section as evidence that
> premium content is currently protected — it is not.** See #1590 for the
> enablement programme and #1616 for the runbook.

When enabled, content access is enforced server-side using the content tier system, preventing access to premium features (audio, MIDI, PDF) regardless of client-side state.

**Turning the switch on is a guided, checked process, not a bare flip.** The raw `content_gating_enabled` checkbox on `/manage/configuration` still exists and still works byte-identically — it is the emergency off-switch, unchanged. The recommended door is now a step-by-step activation wizard on the gating hub (`/manage/gating`, #2006): it previews exactly what would change (how many songs, how much audio/sheet-music would become gated), checks that a qualifying licence is actually on file, lets an admin optionally pre-seed extra restriction rows, and lets them dry-run the result on one real song before committing — then leaves a one-click rollback that undoes only what the wizard itself seeded, never a curator's own rows. The wizard never edits the enforcement chain described below; it only decides *when* to flip the one existing switch, with more checking first.

**Capabilities live in one registry, not a hardcoded matrix.** `TIER_CAPS` in `includes/access_tier_validation.php` is the single source of truth; adding a gateable feature is one line there plus its migration card, never a new column and never a per-tier map. The legacy matrix inside `checkTierAccess()` survives only as the un-migrated/unknown-tier fallback.

**Payload gating is not asset gating** (#1388). Two distinct functions, deliberately kept in lockstep:

| Function | Protects |
|---|---|
| `contentGatingApply()` | Response **bodies** — strips lyric bodies, translations, annotations and media entries from `song_detail` / `song_data` / `random` / `songbook_export` |
| `contentGatingMediaAllowed()` | **Bytes** — `/song-media/<id>` and the `bulk_audio` offline manifest |

Stripping a media row from a payload hides an affordance; it does not protect the file. A URL-addressable asset is bookmarkable, shareable and guessable by id, so it needs its own gate — and both must resolve through the same registry so a cap cannot hide the button while leaving the file open.

Both fail **open** by design: the three docroots share one MySQL and migrations are web-run rather than auto-applied, so an un-migrated read degrades to the pre-gating behaviour rather than to a broken endpoint.

### Tier Resolution Logic

The server resolves a user's effective tier by comparing their personal tier with their organisation tier and taking the highest:

```text
effective_tier = MAX(user.AccessTier, org_tier_from_groups)
```

- **Personal tier** is read from `tblUsers.AccessTier`
- **Organisation tier** is resolved from the user's group memberships via `tblAccessTiers`
- The higher of the two is used for all access checks
- Tier checks are performed server-side before serving gated content (MIDI files, PDF downloads)
- The `tier_check` API endpoint allows clients to pre-check access before attempting to load gated resources

### CCLI Number Validation

CCLI licence numbers are validated before being stored:

- **Format check**: must be a numeric string, typically 5-8 digits
- **Sanitisation**: trimmed, non-numeric characters rejected
- Input validated via the `ccli_validate` API endpoint (POST)
- Stored in `tblUsers.CcliNumber` with verification status in `tblUsers.CcliVerified`
- Invalid formats return a 400 error with a descriptive message

---

## Input Sanitisation

### API Inputs

- Usernames: lowercased, trimmed, validated against `/^[a-z0-9_.\-]+$/`
- Song IDs: validated against `/^[A-Za-z]+-\d+$/`
- Setlist IDs: alphanumeric only (regex filtered)
- Owner UUIDs: hex + hyphen only
- Display names: trimmed, truncated to 100 chars
- Setlist names: trimmed, truncated to 200 chars
- Song counts: capped at 200 per setlist, 50 setlists per user
- Arrangements: validated as arrays of non-negative integers

### HTML Output

- All dynamic content escaped with `htmlspecialchars()`
- JavaScript uses `escapeHtml()` from `js/utils/html.js`
- No raw HTML interpolation of user data

---

## File Security

### Direct Access Prevention

Every PHP include file starts with:

```php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}
```

### Shared Setlist Files

- Stored in `appWeb/data_share/setlist_json/`
- Setlist IDs are 8-character hex strings (4 bytes of randomness)
- Atomic file creation with `fopen('x')` to prevent TOCTOU races
- Ownership verified before updates (owner UUID must match)

---

## Feature security — branch `claude/issue-sweep-fixes-89`

- **Custom print layouts pass through an allowlist HTML/CSS sanitiser** (`includes/html_sanitizer.php`) on save AND on the server-PDF render path (#1767 remainder). No `<script>`, no event handlers, no `<iframe>`/forms, and no external fetch (remote images / stylesheets / fonts) survive — a curator's uploaded layout can only be safe, print-oriented markup.
- **The server-PDF endpoint** (`manage/print-pdf.php`) requires an authenticated session and answers **401 JSON** (not a redirect) when absent; it sanitises the POSTed document server-side, and the GPL rendering engine (mPDF) is vendored **outside every web docroot** (`appWeb/private_html/lib/pdf/vendor/`). The CCLI copies count is re-resolved server-side — the client can't claim it.
- **Set-list edit links are 256-bit capability URLs** (#1791). A link's power lives in the `tblSharedSetlists` row (scope, edit audience, revoked flag), not in who holds the URL: each link is revocable per-link, an org may clamp "anyone with the link" to "signed-in required", and the server **re-resolves the audience on every write** — a write that needs sign-in and doesn't have it gets `401 {reason:'signin_required'}`, never trusting the client's claimed audience.
- **The IA fetch client** (`includes/ia_client.php`) is SSRF-hardened: host-bound to archive.org, size-capped with an aborting write-callback, no redirects, SSL verify on — the same house pattern as `intapps_client.php` / `cuercode_client.php`.
- **Organisation logo SVG uploads pass through a dedicated, stricter sanitiser** (`includes/svg_sanitizer.php`, #1830), separate from and stricter than the print-layout HTML sanitiser above — that module keeps blocking `<svg>` outright; this one exists specifically to turn an untrusted SVG upload into safe bytes to store. `DOMDocument::loadXML()` with `LIBXML_NONET`, never `LIBXML_NOENT`, entity loader nulled, a pre-parse byte reject of any `<!DOCTYPE`/`<!ENTITY` occurrence PLUS a post-parse doctype check (two independent XXE layers), a 10 000-node/64-level render-bomb budget, and a default-deny element/attribute rebuild that **drops** (never unwraps) anything not on an 19-element allow-list — `<script>`, `<style>`, `<foreignObject>`, `<use>`, `<image>`, `<a>`, every SMIL animation element and every filter/mask/pattern element are simply absent from it. The only `url()` shape that survives anywhere (an attribute or inside `style=`) is a same-document `url(#id)`. Logos are served by the standalone `org-logo.php` endpoint (mirrors `qr.php`/`og-image.php`) with a `default-src 'none'; sandbox` CSP and are **never inlined** into any page — always a plain `<img src="/org-logo.php?...">`. `tests/php/test-svg-sanitizer.php` is the mutation-proven functional truth table.

---

## User Enumeration Prevention

- `auth_forgot_password` always returns HTTP 200 with the same message, regardless of whether the user exists
- Registration returns 409 for duplicate usernames (necessary for UX, acceptable trade-off)

---

## Rate Limiting

Application-level rate limiting exists on multiple layers:

- **Read endpoints** — `enforceReadRateLimit()` / `enforceReadRateLimitKeyed()` (`includes/read_rate_limit.php`, #1354) throttle the heaviest public reads (e.g. bulk song lists) against a `tblReadRateLimit` windowed counter, per IP or per auth context
- **Admin login lockout** — 10 failed attempts from an IP within 15 minutes locks out further attempts (checked against `tblLoginAttempts`) **before** the password is even verified, so the lockout holds even for a correct guess during the window
- **Live Follow / Service Mode** — session-creates and joins are separately rate-limited (see [[Live Follow & Service Mode]])
- Song requests are rate-limited to `max_song_requests_per_day` per IP (configurable via `tblAppSettings`)

Web-server-level rate limiting (Apache `mod_ratelimit`, nginx `limit_req`) can still be layered on top as defence-in-depth, but is no longer the only line of defence.

---

## Security Headers

| Header | Value |
| --- | --- |
| `Content-Security-Policy` | Per-request with nonce (see above) |
| `X-Content-Type-Options` | `nosniff` (on all JSON responses) |
| `Cache-Control` | `no-cache, must-revalidate` (on API responses) |

---

## Recommendations for Production

1. **Enable HTTPS** — the session cookie `secure` flag activates automatically
2. **Consider additional web-server-level rate limiting** for `/api.php` auth endpoints as defence-in-depth (application-level login lockout and read-endpoint rate limiting already exist — see Rate Limiting above)
3. **Remove `_dev_token`** from `auth_forgot_password` response in production
4. **Implement email delivery** for password reset tokens
5. **Monitor** `tblApiTokens` table size and clean up expired tokens periodically
6. **Backup** the MySQL database regularly
7. **Restrict MySQL user permissions** — grant only the minimum required (SELECT, INSERT, UPDATE, DELETE)

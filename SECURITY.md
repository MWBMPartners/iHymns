# Security Policy

iHymns takes the security of its users, contributors and worship communities
seriously. This document explains how to report a vulnerability and summarises
the security model the codebase is built on.

## Reporting a vulnerability

**Please report security issues privately — do not open a public GitHub issue.**

- **Preferred:** use GitHub's **private vulnerability reporting** — on the
  repository, go to **Security → Report a vulnerability** (GitHub Security
  Advisories). This keeps the report confidential until a fix ships.
- Include: what you found, where (file/endpoint/URL), how to reproduce, the
  impact, and any proof-of-concept. Please give us a reasonable window to fix
  before any public disclosure (we aim to acknowledge within a few days).

We will credit reporters who wish to be credited once a fix is released.

## Scope

In scope: the web/PWA application under `appWeb/public_html/` (PHP 8 + the
vanilla-JS PWA), its API (`api.php`, `manage/editor/api.php`,
`manage/editor/api2.php`), the importers, and the build/deploy tooling. The
native Apple/Android apps under `appApple/` and `appAndroid/` are tracked
separately.

Out of scope: third-party services and CDNs (report to the upstream vendor),
denial-of-service via volumetric traffic, and findings that require a
already-compromised admin account.

## Security model (how the app defends itself)

These are enforced conventions; new code must follow them (see
`.claude/CLAUDE.md` rules #5, #8, #19–#21):

- **SQL** — all values are bound via `mysqli` prepared statements
  (`getDbMysqli()` + `bind_param`). String interpolation into SQL is only ever a
  hardcoded constant or an allow-list-validated identifier. A CI guard
  (`bindParamSafe()`) catches placeholder/value mismatches. **PDO is fully
  removed.**
- **Input** — request data (`$_GET`/`$_POST`/`$_COOKIE`/body) is type-coerced or
  allow-list-validated before use; integer identifiers are cast, vocabularies are
  matched against central maps.
- **Output** — DB/user-derived values are escaped with
  `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` server-side and an `escapeHtml()`
  helper client-side; no `innerHTML = userInput`.
- **Authentication** — Bearer token (`tblApiTokens`, SHA-256-hashed) with a
  `SameSite=Lax`, `HttpOnly`, `Secure` cookie fallback (`ihymns_auth`). The
  `manage/*` admin area adopts the API-token session.
- **Authorization** — role hierarchy (`user` < `editor` < `admin` <
  `global_admin`) plus a fine-grained **entitlement** system
  (`includes/entitlements.php`, `userHasEntitlement()`); sensitive routes call
  `requireAdmin()`/`requireEditor()`/`userHasEntitlement()`. **Global Admin
  always bypasses the invite-only channel gate** so it can't lock itself out.
- **CSRF** — state-changing requests are verified by `validateCsrfRequest()`
  (`manage/includes/auth.php`): it passes when EITHER a valid per-session token is
  present OR the request is provably same-origin — it requires the
  `X-Requested-With` header (which a browser cannot set cross-origin without a
  CORS preflight the app never grants) AND any present `Origin`/`Referer` host
  must match `HTTP_HOST`. Because it does not rely on a token that can go stale in
  a long-lived tab, it closes the intermittent-failure gap while keeping the
  cross-origin door shut. It gates duplicate-songs merge/delete, places-api, and
  a single top-level POST guard over all legacy `/manage/editor/api.php` writes.
- **Content access** — gated centrally via
  `includes/content_access.php::checkContentAccess()` against
  `tblContentRestrictions` + access tiers + organisation licences (never queried
  ad-hoc from a page). **Server-side enforcement** of access tiers
  (`includes/content_gating.php`, #1353) strips gated fields (lyric body, media)
  from the API payload by the requester's tier capability, with caps resolved
  from the live `tblAccessTiers` row via an extensible registry (`TIER_CAPS`,
  #1352). It is **fail-open and STRICT-safe** (an un-migrated/edge env returns
  data unchanged rather than throwing) and **entirely dormant** — a verified
  no-op — unless `tblAppSettings.content_gating_enabled='1'`.
- **Read rate limiting** — the heaviest sessionless public reads (`song_detail`,
  `search`, `songs_index`, `related_songs`, bulk) carry a fixed-window
  per-requester limit (`includes/read_rate_limit.php`, #1354) — keyed per session
  token where present, else per IP, so a NAT-shared set of devices isn't
  collectively penalised — emitting `429` + `Retry-After`. Window boundaries are
  computed SQL-side (no per-node clock drift). It is **fail-open** (any error or
  an un-migrated `tblReadRateLimit` table allows the request) so it can never take
  the site down, and blunts scraping/volumetric abuse without affecting real
  clients.
- **File / XML handling** — uploaded XML (MusicXML, PPTX, OpenSong, OpenLyrics,
  TTML) is parsed with `LIBXML_NONET` and no entity expansion (XXE/SSRF-safe on
  PHP 8); ZIP imports are read in-memory (no `extractTo`), validate entry names
  against `..`/absolute-path traversal, and enforce per-entry + cumulative
  uncompressed-size caps (decompression-bomb defence). Media uploads MIME-sniff
  the bytes (not the client type) against an allow-list and store by SHA-256.
- **Secrets** — DB credentials, keys and tokens live outside the web root
  (`appWeb/.auth/`, `appWeb/private_html/`) and are never committed. CI scans for
  committed secrets.
- **CSP** — the public app sends an enforcing per-request-nonce Content-Security-Policy
  (`script-src 'self' 'nonce-…'`, no `'unsafe-inline'`); SPA fragments must never
  carry executable inline scripts, since a shared-cache fragment cannot carry a
  per-request nonce (CI guard `tests/php/test-fragment-inline-scripts.php`).
- **Client error telemetry** — uncaught browser errors are beaconed
  (`?action=client_error_report`) to the existing activity log, not a new store.
  Reports are privacy-scrubbed on both the client and the server (bearer tokens,
  64-hex-char strings, and query-string secrets are stripped; stack frames are
  reduced to `pathname:line`), and the beacon is throttled by three independent
  layers — no new PII surface.

## Security review cadence

- A **standing pre-PR security review** runs before any PR (input handling,
  bound-param SQL, output escaping, CSRF, authZ, secrets, dangerous functions,
  dependencies, logging).
- Periodic **adversarial multi-agent security audits** sweep the codebase and
  verify each finding before a fix lands (e.g. the 2026-06 audit fixed a critical
  SQL-injection in the EasyWorship importer and a `.mxl` path-traversal).

## Dependencies

Third-party libraries and their licences are listed in
[`LICENSING.md`](LICENSING.md). CDN-loaded libraries are pinned to specific
versions with Subresource Integrity where applicable. Dependency updates are
checked against published CVEs.

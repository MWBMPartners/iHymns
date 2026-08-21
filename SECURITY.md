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
  `manage/*` admin area adopts the API-token session. **First-admin
  registration is race-safe** (#1388) — both registration paths (password and
  magic-link) count existing users and insert the first account as
  `global_admin` inside one transaction with `SELECT … FOR UPDATE`, closing a
  TOCTOU where two registrations racing on a virgin install could otherwise
  both read zero users and both become the top-privilege account.
  **Brute-force throttles on account creation and email-code entry now actually
  engage** (#1906) — a previously-dead registration throttle was wired in, and
  the magic-link/email-code check, which had been per-IP only, gained a
  per-email bucket so a single IP can no longer grind codes across many accounts.
  A **session-fixation** gap on cross-surface admin sign-in is closed (#1906):
  the session id is regenerated (`session_regenerate_id`) when the API-token
  session is adopted into the `manage/*` PHP session, so a pre-planted session
  id cannot survive authentication.
- **Authorization** — role hierarchy (`user` < `editor` < `admin` <
  `global_admin`) plus a fine-grained **entitlement** system
  (`includes/entitlements.php`, `userHasEntitlement()`); sensitive routes call
  `requireAdmin()`/`requireEditor()`/`userHasEntitlement()`. **Global Admin
  always bypasses the invite-only channel gate** so it can't lock itself out.
  **Org-scoped reads are structurally isolated, not just entitlement-gated**
  (#1861) — `/manage/my-ccli-report`'s only row source refuses to run without a
  non-empty, `tblOrganisationMembers`-derived org-id list, so an org admin can
  never see another organisation's CCLI usage regardless of the entitlement
  check alone; the system-wide `/manage/ccli-report` stays a separate,
  higher-privileged view.
- **CSRF** — state-changing requests are verified by `validateCsrfRequest()`
  (`manage/includes/auth.php`): it passes when EITHER a valid per-session token is
  present OR the request is provably same-origin — it requires the
  `X-Requested-With` header (which a browser cannot set cross-origin without a
  CORS preflight the app never grants) AND any present `Origin`/`Referer` host
  must match `HTTP_HOST`. **Tightened in #1388**: `X-Requested-With` alone no
  longer passes when both `Origin` and `Referer` are absent — a real browser
  sends `Origin` on every non-GET/HEAD request per the Fetch spec, so absent-both
  means "not a browser POST" and the check now falls back to requiring a valid
  session token; the rejection is logged (method + URI) rather than silent.
  Because it does not rely on a token that can go stale in
  a long-lived tab, it closes the intermittent-failure gap while keeping the
  cross-origin door shut. It gates duplicate-songs merge/delete, places-api,
  musician-duplicates merge/dismiss (#1785), publishers CRUD (#93), licence-types
  CRUD and the tiers/restrictions/entitlements gating pages (#1769), the set-list
  share-link mint/update/revoke (#1791), `live_follow_extend` (#1798), and a
  single top-level POST guard over all legacy `/manage/editor/api.php` writes.
- **Custom print layouts are sanitised** (#1767) — uploaded full-page HTML
  layouts (`tblPrintTemplateCustomLayout`) pass through the allowlist HTML/CSS
  sanitiser (`includes/html_sanitizer.php`) on save AND on the server-PDF render
  path: no `<script>`, no event handlers, no `<iframe>`/forms, no external fetch
  (remote images/stylesheets/fonts) survives.
- **The server-PDF endpoint** (`manage/print-pdf.php`, #1767) requires an
  authenticated session and answers **401 JSON** (not a redirect) when absent;
  it sanitises the POSTed document server-side, re-resolves the CCLI copies count
  server-side, and the GPL rendering engine (mPDF) is vendored **outside every
  web docroot** (`appWeb/private_html/lib/pdf/vendor/`).
- **Set-list edit links are 256-bit capability URLs** (#1791) — a link's power
  lives in the `tblSharedSetlists` row (scope, edit audience, revoked flag), not
  in who holds the URL. Each link is revocable per-link, an org can clamp
  "anyone with the link" to "signed-in required", and the server **re-resolves
  the audience on every write** (a `401 {reason:'signin_required'}` contract),
  never trusting the client's claimed audience.
- **The IA fetch client is SSRF-hardened** (#94) — `includes/ia_client.php` is
  host-bound to archive.org, size-capped with an aborting write-callback, follows
  no redirects, and keeps SSL verification on (the same house pattern as
  `intapps_client.php` / `cuercode_client.php`).
- **Organisation-logo SVG uploads are sanitised by a dedicated, stricter module**
  (#1830) — `includes/svg_sanitizer.php`, separate from and stricter than the
  print-layout sanitiser above (which correctly keeps blocking `<svg>` outright).
  `DOMDocument::loadXML()` with `LIBXML_NONET`, never `LIBXML_NOENT`, the entity
  loader nulled, a pre-parse `<!DOCTYPE`/`<!ENTITY` byte reject **plus** a
  post-parse doctype check (two independent XXE layers), a 10 000-node/64-level
  render-bomb budget, and a 19-element allow-list that **drops** (never unwraps)
  `<script>`, `<style>`, `<foreignObject>`, `<use>`, `<image>`, `<a>`, every SMIL
  element and every filter/mask/pattern element. The only `url()` shape that
  survives anywhere is a same-document `url(#id)`. Logos are served by the
  standalone `org-logo.php` (`default-src 'none'; sandbox` CSP) and are
  **never inlined** — always a plain `<img src>`.
- **Content access** — gated centrally via
  `includes/content_access.php::checkContentAccess()` against
  `tblContentRestrictions` + access tiers + organisation licences (never queried
  ad-hoc from a page). **Server-side enforcement** of access tiers
  (`includes/content_gating.php`, #1353) strips gated fields (lyric body, media)
  from the `song_detail`/`song_data`/`random` API payload by the requester's tier
  capability, with caps resolved from the live `tblAccessTiers` row via an
  extensible registry (`TIER_CAPS`, #1352); as of **#1388** the same strip also
  applies per-song to `songbook_export` (previously entity-gated only — the
  widest lyric leak in the API once gating goes live), and a **second gate**,
  `contentGatingMediaAllowed()`, protects the media **bytes** themselves —
  `/song-media/<id>` (403 on denial) and the offline `bulk_audio` manifest —
  since stripping a media link from a payload hides the affordance but does not
  protect the file, which stays a bookmarkable, guessable-by-id URL. Service-Mode's
  presence-token CCLI unlock now also requires a **live heartbeat** within the
  freshness window, not just an operator-set `IsActive` flag (#1388) — an
  operator's device going offline without a clean session end previously left a
  standing content unlock live for every congregant who had ever joined. It is
  **fail-open and STRICT-safe** (an un-migrated/edge env returns
  data unchanged rather than throwing) and **entirely dormant** — a verified
  no-op — unless `tblAppSettings.content_gating_enabled='1'`. **#1906** closes a
  matching leak on the social share-image endpoint (`og-image.php`), which could
  render copyrighted lyric text onto a share card even while content-locking is
  on.
- **Read rate limiting** — the heaviest sessionless public reads (`song_detail`,
  `search`, `songs_index`, `related_songs`, bulk) carry a fixed-window
  per-requester limit (`includes/read_rate_limit.php`, #1354) — keyed per session
  token where present, else per IP, so a NAT-shared set of devices isn't
  collectively penalised — emitting `429` + `Retry-After`. Window boundaries are
  computed SQL-side (no per-node clock drift). It is **fail-open** (any error or
  an un-migrated `tblReadRateLimit` table allows the request) so it can never take
  the site down, and blunts scraping/volumetric abuse without affecting real
  clients. **#1906** brings further heavy public endpoints under the same
  fixed-window limiter — `og-image`, `random`, `song_of_the_day` and the media
  reads.
- **File / XML handling** — uploaded XML (MusicXML, PPTX, OpenSong, OpenLyrics,
  TTML) is parsed with `LIBXML_NONET` and no entity expansion (XXE/SSRF-safe on
  PHP 8); ZIP imports are read in-memory (no `extractTo`), validate entry names
  against `..`/absolute-path traversal, and enforce per-entry + cumulative
  uncompressed-size caps (decompression-bomb defence). Media uploads MIME-sniff
  the bytes (not the client type) against an allow-list and store by SHA-256.
- **Secrets** — DB credentials, keys and tokens live outside the web root
  (`appWeb/.auth/`, `appWeb/private_html/`) and are never committed. CI scans for
  committed secrets.
- **CI/CD workflow hardening** — GitHub Actions `run:` steps never interpolate
  attacker-controllable text (e.g. a commit message) via `${{ }}` directly into
  the shell script body, since that pastes the raw text into the script before
  the shell parses anything — a crafted commit message can break out of the
  intended token and run arbitrary commands on a runner holding
  `contents: write` (#1622). Such values are passed through an environment
  variable instead, which the process receives verbatim and the shell never
  re-parses. Workflow YAML itself is linted by `actionlint` in CI.
- **Session hygiene on shared devices** — logging out clears the per-user
  Cache Storage buckets the service worker owns and keys by URL alone (#1388),
  so a subsequent user of a shared/kiosk device isn't served page fragments
  fetched under the previous session. Deliberately spares the offline-download
  and media caches, which represent a deliberate save-for-offline choice by
  the user, not session state.
- **CSP** — the public app sends an enforcing per-request-nonce Content-Security-Policy
  (`script-src 'self' 'nonce-…'`, no `'unsafe-inline'`); SPA fragments must never
  carry executable inline scripts, since a shared-cache fragment cannot carry a
  per-request nonce (CI guard `tests/php/test-fragment-inline-scripts.php`).
  **#1906** extends header coverage to the `manage/*` admin area and the
  social-card endpoint (`og-image.php`), which previously shipped no CSP or
  hardening headers of their own.
- **HTTP-layer hardening** (#1905 / #1906) — an unknown route (a `/wp-admin/`
  scanner probe, any made-up path) now returns a real **404** rather than a soft
  HTTP-200 app shell; the valid-route allow-list is **derived** from the app's
  own pages and CI-locked in step with the client router (#1905). Security
  response headers are emitted **on error responses too** (`Header always set`),
  not just on `200`s. `X-Powered-By` now advertises the app's own
  `iHymns/<version>` identity while the PHP runtime version is suppressed at
  source (`expose_php=Off`), so the stack no longer volunteers its component
  versions to a scanner. An owner/host-gated remainder (`Options -Indexes`,
  `ServerSignature Off`) is still pending an alpha check.
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

# Architecture

> Technical architecture of the iHymns multiplatform application

---

## Project Structure

```text
iHymns/
├── .claude/                  # Claude AI context & project brief
├── .github/workflows/        # CI/CD: deploy, version bump, changelog, tests (14 workflows)
├── .SourceSongData/           # Raw song text files (original import source — DO NOT MODIFY)
├── tools/                    # Build tools & song data parser
│   ├── parse-songs.js        #   Parses .SourceSongData/ → data/songs.json
│   └── build-web.js          #   Web build/packaging script
├── data/                     # Generated song data
│   ├── songs.json            #   One-time migration input, NOT a runtime file (see Data Flow below)
│   └── songs.schema.json     #   JSON Schema (draft 2020-12) for validation
├── tests/                    # Unit + PHP test harnesses
├── appWeb/                   # Web PWA application
│   ├── public_html/          #   Deployed source (single source for all environments)
│   │   ├── index.php         #     SPA shell — OG tags, CSP nonce, JSON-LD
│   │   ├── api.php           #     AJAX API — pages, search, auth, setlists, Live Follow, Service Mode
│   │   ├── includes/         #     PHP components, pages, config
│   │   │   └── markdown_lite.php  #  Escape-first Markdown renderer (used by /whats-new)
│   │   ├── js/               #     ES modules architecture
│   │   │   ├── app.js        #       Main app bootstrap
│   │   │   ├── constants.js  #       Shared event-name / localStorage-key constants
│   │   │   ├── modules/      #       Feature modules (router, search, setlist, error-monitor, etc.)
│   │   │   └── utils/        #       Utilities (html.js, text.js, components.js)
│   │   ├── css/              #     Stylesheets
│   │   └── manage/           #     Admin area (editor, users, auth, API Docs Swagger UI)
│   └── .sql/                 #   schema.sql + migrate-*.php (web-run migrations)
├── appApple/                 # Native Apple app — Swift 6.3 / SwiftUI, iHymnsKit SwiftPM package
├── appAndroid/                # Native Android app (Kotlin 2.1 / Jetpack Compose) — scaffold
├── help/                     # User documentation guides
└── wiki/                     # GitHub Wiki source pages
```

---

## Web PWA Architecture

### SPA Pattern

The PWA is a single-page application served from `index.php`. All URLs are rewritten via `.htaccess` to `index.php`, which:

1. Generates a unique CSP nonce per request
2. Detects the URL path for Open Graph meta tags and JSON-LD structured data
3. Renders the HTML shell (header, content area, footer)
4. Loads the JS app which handles client-side routing via History API

Page content itself is fetched separately: `router.js` requests an HTML **fragment** from `api.php?page=...`, which is a distinct HTTP response the document's per-request CSP nonce cannot travel with. Several fragments (`page=home`, `page=song`, `page=songbook`, …) are additionally served from a **shared HTTP cache** across all visitors, so they can never carry anything per-request at all — no per-user personalisation, no nonce.

This has one hard consequence: **a fragment can never carry an executable inline `<script>`.** The enforcing nonce CSP silently refuses any script node without a matching nonce — the page looks fine until a user clicks the broken feature. `router.js` no longer even tries to re-execute injected `<script>` tags (the `_executeInlineScripts()` shim that used to re-create them, nonce-less, was removed once nothing needed it); a fragment script today simply never runs. A CI guard (`tests/php/test-fragment-inline-scripts.php`) fails the build on any executable inline script under `includes/pages/` or `includes/partials/` — its allowlist is currently empty.

The correct pattern — used by `js/modules/home-page.js` — is a real ES module, imported by the router's `afterPageLoad(page, params)` hook once the fragment has landed in the DOM, with its inputs read `data-*`-attribute-first from the fragment markup (e.g. `.page-song[data-song-id]`) and the route parameter only as a fallback.

When a fragment request itself errors, `router.js` now shows the server's own themed explanation instead of discarding it (#1705). Previously any non-OK response was thrown away unread, and every one of the six pages that answer a bad request with a proper error card (`song.php`'s 410/404, `songbook.php`, `person.php`, `work.php`, `tag.php`, and `maintenance.php`'s 503) was replaced client-side by a generic "Failed to load page. Please check your connection and try again." — telling a reader who followed a link to a merged song to check their WiFi. `js/utils/error-response.js`'s `shouldRenderErrorBody()` is a pure gate on status (400–599), a `text/html` content type, and a non-empty body — deliberately never on the body's *text* (rule #35), and deliberately refusing a JSON error body (which would otherwise dump `{"error":"..."}` onto the page). A genuine network failure still shows the generic message, correctly.

### JavaScript Module Architecture

The app uses **ES modules** with a central `iHymnsApp` class that coordinates the feature modules, including:

```
iHymnsApp
├── Router          — History API routing, AJAX fragment loading, afterPageLoad() module wiring
├── Transitions     — Page transition animations
├── Settings        — Theme, motion, font size, analytics consent
├── Search          — Fuse.js search with TF-IDF related songs
├── Favorites       — Favourite songs (synced server-side when signed in, localStorage otherwise)
├── SetList         — Setlists with custom arrangements
├── UserAuth        — Bearer token auth, cross-device sync
├── PWA             — Install banner, service worker
├── Audio           — MIDI playback
├── SheetMusic      — PDF sheet music viewer
├── History         — Recently viewed songs
├── Display         — Presentation mode, font prefs
├── Compare         — Side-by-side song comparison
├── Shortcuts       — Keyboard shortcuts overlay
├── Numpad          — Numeric keypad for song number search
├── Share           — Song sharing (Web Share API)
├── Shuffle         — Random song picker
├── Transpose       — Capo/transpose indicator
├── ReadingProgress — Scroll-linked progress bar
├── SongbookIndex   — Alphabetical songbook index
├── SearchHistory   — Recent search terms
├── SongOfTheDay    — Daily featured song
├── OfflineIndicator— Online/offline status
├── ErrorMonitor    — Catches uncaught errors/rejections, one toast + scrubbed beacon (#1582)
├── StorageBridge   — Cross-domain localStorage sync
├── SubdomainSync   — Subdomain cookie sync
├── Gestures        — Touch swipe navigation
├── Analytics       — GA4, Plausible, Clarity, Matomo, Fathom
└── Request         — Missing song request form
```

Event names dispatched/listened for across modules are centralised once in `js/constants.js` — a raw `ihymns:*` string literal anywhere else is a CI-banned regression (`tests/test-event-names.js`).

**Same-origin requests go through `js/utils/api-client.js`** (`apiFetch()` / `apiFetchJson()`), not bare `fetch()`. There is **no global `window.fetch` override anywhere in the app** — an earlier `songbook-language-filter.js` patch that replaced `window.fetch` to attach an `X-Preferred-Languages` header was deleted, because a global patch (a) turns a header bug into a failed request for every unrelated caller, and (b) only applies on pages that happened to install it, silently doing nothing everywhere else. The client instead reads the language preference on every call, an auth-header provider is injected at boot (`setAuthHeaderProvider()` in `app.js`, avoiding an import cycle with `user-auth.js`), and it treats a `503` (maintenance/DB-outage) as its own signal rather than a network failure. The service worker keeps native `fetch` deliberately — different global scope, no `localStorage`, not user-scoped.

### PHP Server Architecture

```
index.php           — SPA shell (OG tags, CSP nonce, JSON-LD)
api.php             — AJAX API (pages, search, auth, setlists, Live Follow, Service Mode, telemetry)
├── includes/
│   ├── config.php            — Centralised configuration
│   ├── infoAppVer.php        — App version metadata
│   ├── db_mysql.php          — getDbMysqli() — the ONE database connection factory
│   ├── SongData.php          — Song data handler class (scoped live-read methods)
│   ├── markdown_lite.php     — Escape-first Markdown renderer (What's New page)
│   ├── components/           — Reusable PHP components
│   └── pages/                — Page templates (home, song, setlist, whats-new, etc.)
└── manage/
    ├── includes/
    │   ├── auth.php     — Authentication middleware (roles, sessions, CSRF)
    │   └── db.php       — Thin wrapper calling getDbMysqli()
    ├── editor/          — Song editor (requires editor+ role); api2.php is the current write path
    ├── api-docs.php     — Swagger UI rendering of api-docs.yaml (view_api_docs entitlement)
    ├── users.php        — User management (requires admin+ role)
    ├── setup.php        — First-run Global Admin setup
    ├── login.php        — Admin login page
    └── logout.php       — Admin logout
```

### Data Flow

Every runtime read is **live MySQL** — this is the single biggest architectural fact about the current codebase (the DB-direct rewrite, epic #1010, June 2026). `songs.json` is a **one-time migration input**, not a runtime file, and nothing ships it to a client:

```
.SourceSongData/ (raw text files)
        │
        ▼  tools/parse-songs.js
data/songs.json (one-time migration input)
        │
        ▼  appWeb/.sql/migrate-json.php  (run once, confirm=1-gated)
MySQL `ihymns` database (single shared DB across all 3 docroots)
        │
        ▼  scoped, live reads only — nothing materialises the whole corpus
        ├── getSongsSlimIndex()  — lightweight id/number/title/songbook index
        ├── getSongs($abbr)      — one songbook
        └── getSongById()        — one full song record
```

Nothing loads the whole ~14,000-song catalogue into PHP memory at once — an earlier unscoped read caused an OOM (#929). If the database is unreachable, the app shows a themed 503 maintenance page; the **only** fallback is whatever a client previously downloaded into its own offline cache (browser Cache Storage for the PWA, GRDB for Apple) — there is no server-side JSON fallback mode.

---

## Native App Architecture

### Apple (Swift 6.3 / SwiftUI)

- **Package**: `iHymnsKit`, a SwiftPM package (`appApple/Packages/iHymnsKit/`) shared across every Apple target (iOS, iPadOS, macOS, tvOS, visionOS, watchOS) — per-target code under `appApple/Apps/` imports it rather than duplicating logic.
- **Offline cache**: **GRDB.swift** (`IHPersistence` module) — an on-disk SQLite-backed store with full-text search and versioned migrations, used for saved songs, setlists, and favourites. This is a real local database, not bundled JSON in UserDefaults.
- **Networking**: URLSession-based client talking to the same bearer-token API as the PWA (auth, setlists, favourites sync, Live Follow, Service Mode, device-code pairing).
- **Status**: Phase 1 (web-app parity) and Phase 2 (Live features: watch relay, tvOS projector, Live Activities, App Intents) code-complete, unreleased. Compiled only by CI (`apple.yml`); device matrices and APNs provisioning are owner-gated.
- **UI**: `TabView` (iPhone) / `NavigationSplitView` (iPad/Mac).

See `LICENSING.md` for the exact pinned GRDB version and licence.

### Android (Kotlin 2.1 / Jetpack Compose)

- **Pattern**: MVVM with `SongViewModel` + StateFlow
- **Status**: Scaffold / in progress — no shared networking or persistence layer yet
- **Platforms**: Phone, Tablet, Android TV, Fire OS (zero Google Play Services dependencies)
- **UI**: Single-activity, NavHost navigation

See [[Native Apps (Apple & Android)]] for the fuller current-state breakdown.

---

## Database

MySQL 5.7+ / MariaDB 10.3+ only — **PDO was fully removed from the codebase** (#554/#555). The single database access layer is `getDbMysqli()` in `includes/db_mysql.php`, running mysqli under `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` (a failing statement throws, it never silently returns `false`). Every value entering a SQL string is bound via `$stmt->bind_param(...)`.

- **142 tables**, named `tblCamelCase` (`appWeb/.sql/schema.sql` is the canonical source of truth for a fresh install).
- Migrations are **web-run**, not auto-applied on deploy — an admin applies pending migration cards from `/manage/setup-database`. This means "it's in `schema.sql`" does not imply "it exists on this environment yet."
- Every migration that creates a table/column also updates `schema.sql` in the same commit (CI-enforced by `tests/php/test-schema-coverage.php`) and registers a completion probe (CI-enforced by `tests/php/test-migration-registry.php`).

See [[Database & Migrations]] for the full schema breakdown.

---

## External integrations

### MWBM-IntAppsAPI gateway (Epic #1725)

A server-proxied, cache-first, fail-open client for MWBM's shared feature-flag
gateway — an operational build-behaviour kill switch, structurally separate from
content gating (it never feeds `TIER_CAPS`/`checkTierAccess()`/
`contentGatingApply()`/`gatingRulesApply()`/`checkContentAccess()`, and a
tree-derived, mutation-tested guard — `tests/php/test-intapps-guards.php` —
enforces that separation on every commit).

- **Client:** `includes/intapps_client.php`. Loading it has no side effect;
  every function degrades to the caller's compiled-in default the instant the
  module is disabled, the credential set is incomplete, the cache table is
  absent, or the gateway is unreachable/slow/lying. Nothing in it ever throws
  into a page render.
- **Cache:** one table, `tblIntAppsSync`, keyed `(Scope, Channel, AppSlug)` —
  `Scope` is `'features'` today with `'updates'`/`'notifications'`/`'status'`
  reserved; `Channel` is the 3-docroot discriminator (default operation uses
  only the shared `''` row); `AppSlug` reserves a second registered gateway
  app. A failed or malformed fetch never overwrites the last-known-good
  snapshot.
- **Enablement:** a per-channel allow-list,
  `tblAppSettings.intappsapi_enabled_channels` — the same mechanism
  `apple_web_login_enabled` already uses — so alpha can canary the
  integration without touching production on the shared database. **Ships
  with no row at all**, which is a verified byte-identical no-op: zero HTTP,
  zero `tblIntAppsSync` access, `app_status` output unchanged.
- **The signer.** `hex(HMAC-SHA256(rawBody . '.' . unixTimestamp, secret))`,
  implemented directly against the gateway's own `HmacValidator.php` source —
  its five bundled client examples sign a DIFFERENT, wrong string
  (`METHOD|PATH|TIMESTAMP|BODY`, filed upstream as MWBM-intAppsAPI#120) and
  must never be copied.
- **Refresh:** request-piggybacked off `api.php`'s `app_status` handler,
  scheduled to run AFTER the response is already on the wire
  (`fastcgi_finish_request()` where available), with an atomic
  seed-INSERT-then-conditional-UPDATE single-flight lock so at most one
  request per TTL window pays the cost of a live fetch.
- **Consumption seams:** (a) `app_status.remoteFeatures` — a keyed object,
  emitted only when enabled, the primary channel for native clients; (b)
  server-side `intappsFlag($db, $key, $default)` calls in cosmetic,
  non-gating code — the shipped example is the Song-of-the-Day card's
  presence on the home page.
- **Local stub gateway:** `tests/php/fixtures/intapps-stub-gateway.php` is a
  line-for-line port of the gateway's own auth + HMAC verification (pinned
  commit `6816ed8`), used by `tests/php/test-intapps-stub-e2e.php` to prove
  the real signer over real loopback HTTP. It lives outside every directory
  the deploy workflow mirrors and cannot accidentally ship.
- **What is NOT proven by any of the above:** acceptance by the REAL
  gateway (`api.mwbmpartners.ltd`), which needs the owner-only liveness +
  app-registration prerequisite (Epic #1725's Issue A) before any channel is
  enabled on a real environment.

Admin surfaces: the credentials + enablement card on `/manage/configuration`,
and the read-only snapshot/diagnostic viewer at `/manage/intapps-status`
(both gated `manage_configuration`).

---

## Security summary

- Content Security Policy with per-request nonces; enforcing (`script-src 'self' 'nonce-…'`, no `'unsafe-inline'`) — see the SPA fragment constraint above.
- `validateCsrfRequest()` — same-origin AJAX check (`X-Requested-With` + `Origin`/`Referer` host **and port** match) for state-changing endpoints, replacing the older baked-session-token-only check for long-lived admin pages. The port comparison was itself a fix (#1709): `HTTP_HOST` keeps the port (`example.com:8080`) but `parse_url($origin, PHP_URL_HOST)` never includes it, so a naive string compare could never match on a non-default port — and, in the opposite direction, silently accepted a different port on the same host as same-origin. Both sides now resolve to "explicit port, else the header's own scheme's default" before comparing.
- Role + entitlement gates (`requireAdmin()`, `userHasEntitlement()`) on every admin surface.
- A registry-driven content-access-tier / gating system (`TIER_CAPS` in `includes/access_tier_validation.php`) — entirely dormant unless explicitly enabled, and never a hardcoded per-tier matrix. Enforcement splits in two: `contentGatingApply()` (`includes/content_gating.php`) strips gated fields from JSON *payloads* (`song_detail`, `song_data`, `random`, `songbook_export`); its sibling `contentGatingMediaAllowed($kind, $userId, $presenceToken)` answers the same question for one media row and gates the *bytes* — `song-media.php` and the `bulk_audio` offline manifest. A payload gate alone hides the affordance but leaves a URL-addressable file bookmarkable, so every gated asset needs both checks resolving through the same registry.
- Friendly, theme-aware error pages for every status the app actually emits — `errorPageMap()` / `errorPageStatuses()` in `includes/error_page.php` is the one status→copy registry (400/401/403/404/405/410/429/500/503), and `error.php` (the Apache `ErrorDocument` target for 403/405/500/503) derives its render whitelist from it rather than a second hand-typed list (#1704). 405 and 410 are recent additions — 410 is what a soft-deleted or merged-with-no-replacement song now returns instead of a generic 404.

See [[Security]] for full details.

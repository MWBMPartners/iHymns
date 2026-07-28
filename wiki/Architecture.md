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

This has one hard consequence: **a fragment can never carry an executable inline `<script>`.** The enforcing nonce CSP silently refuses any script node without a matching nonce, and because `router.js` re-creates injected `<script>` tags verbatim, the failure is a console-only violation with no visible error — the page looks fine until a user clicks the broken feature. A CI guard (`tests/php/test-fragment-inline-scripts.php`) fails the build on any executable inline script under `includes/pages/` or `includes/partials/`.

The correct pattern — used by `js/modules/home-page.js` — is a real ES module, imported by the router's `afterPageLoad(page, params)` hook once the fragment has landed in the DOM, with its inputs read `data-*`-attribute-first from the fragment markup (e.g. `.page-song[data-song-id]`) and the route parameter only as a fallback.

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

## Security summary

- Content Security Policy with per-request nonces; enforcing (`script-src 'self' 'nonce-…'`, no `'unsafe-inline'`) — see the SPA fragment constraint above.
- `validateCsrfRequest()` — same-origin AJAX check (`X-Requested-With` + `Origin`/`Referer` host match) for state-changing endpoints, replacing the older baked-session-token-only check for long-lived admin pages.
- Role + entitlement gates (`requireAdmin()`, `userHasEntitlement()`) on every admin surface.
- A registry-driven content-access-tier / gating system (`TIER_CAPS` in `includes/access_tier_validation.php`) — entirely dormant unless explicitly enabled, and never a hardcoded per-tier matrix.

See [[Security]] for full details.

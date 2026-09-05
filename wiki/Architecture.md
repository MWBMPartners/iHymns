# Architecture

> Technical architecture of the iHymns multiplatform application

---

## Project Structure

```text
iHymns/
├── .claude/                  # Claude AI context & project brief
├── .github/workflows/        # CI/CD: deploy, release, changelog, tests (15 workflows at last count — see the directory itself for the live number)
├── .SourceSongData/           # Raw song text files (original import source — DO NOT MODIFY)
├── tools/                    # Build tools & song data parser
│   └── parse-songs.js        #   Parses .SourceSongData/ into tmp/songs.json — a gitignored LOCAL BUILD ARTEFACT only (#1617); nothing commits it and nothing in the app reads it
├── data/                     # Empty except for a .gitkeep — the tracked data/songs.json this folder used to hold was retired in #1617 (it was ~4x stale against the live catalogue and unused at runtime)
├── tests/
│   └── fixtures/songs.schema.json  #   JSON Schema (draft 2020-12) the parser's output is validated against (moved here from data/ in #1617)
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

**Unknown routes return a real 404, not a soft 200 shell (#1905).** The `.htaccess` catch-all no longer answers *every* unmatched path with the app shell — a made-up path (a `/wp-admin/` scanner probe, any URL the app doesn't own) now gets a genuine HTTP 404. Obvious scanner-bait paths are refused at the web-server edge; everything else is checked by the front controller against a **valid-route list derived from the app's own pages** (so a new page is recognised automatically), with a CI guard keeping that list in lockstep with the client router. This closes the "soft-404" gap where a probe scan reported HTTP 200 for paths that don't exist.

Page content itself is fetched separately: `router.js` requests an HTML **fragment** from `api.php?page=...`, which is a distinct HTTP response the document's per-request CSP nonce cannot travel with. Several fragments (`page=home`, `page=song`, `page=songbook`, …) are additionally served from a **shared HTTP cache** across all visitors, so they can never carry anything per-request at all — no per-user personalisation, no nonce.

The corollary (#1710): `api.php` now resolves `$currentUser` when rendering a **non-cacheable** fragment, so a signed-in viewer gets correctly personalised copy — a signed-in user is no longer wrongly told to "Sign in to sync…" on Settings. Cacheable fragments deliberately stay un-personalised for shared-cache safety, and a mutation-tested guard keeps the two apart.

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
├── Search          — Fuse.js search with TF-IDF related songs; accent/apostrophe-folded matching (#1039)
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

### API coverage — everything through the API

**Principle:** the Web/PWA and both native apps (Apple, Android/FireOS) interact with the backend **exclusively** through `api.php` and the editor API (`manage/editor/api2.php` + the legacy `manage/editor/api.php` shim) — there is no admin or curator capability that exists only as a `/manage/*.php` form-POST a browser session can reach and a native client cannot. This was largely already true for the *consumer* surface (the public PWA's own `fetch`/`apiFetch` calls all target `/api`, bar one deliberate seam); the 2026-08-28/29 **API-coverage program** (branch `claude/dormant-features-settings-1sdw4t`) closed the remaining gap on the *admin/curator* side — roughly 90 new `admin_*`/`org_admin_*` actions plus a Bearer-auth seam on the editor API — so the same statement now holds for the whole app, not just the read path. See [[API Reference]] for the family breakdown and [[Native Apps (Apple & Android)]] for what this unlocks for each native client.

**The mechanism that keeps this true — `tests/php/test-manage-action-api-coverage.php`.** A one-off audit finds today's gaps; it says nothing about tomorrow's (rule #34/#35 — a fact about the tree needs a standing check, not a document). This guard tokenises the real source tree (never a typed list) to enumerate every state-changing `$_POST`/`$_REQUEST` action across all `manage/*.php` pages — across the several dispatch shapes this codebase actually uses (`switch`, `if`/`elseif` chains, a raw superglobal comparison, `in_array()`), plus the handful of pages with exactly one implicit action — and cross-checks each one against a maintained mapping to either:

1. a real `api.php`/`api2.php` action (the common case — `web_only:GAP-...` entries, honestly flagged rather than guess-mapped, were the mechanism by which the program tracked its own remaining work down to zero), or
2. an explicit `web_only:<reason>` entry for a surface that is deliberately **never** exposed over the network — secret material (`configuration.php`), the schema-mutating `setup-database.php` console, the master content-gating switches, the role→entitlement matrix itself (an API write there could self-escalate), or a handful of interactive wizard-shaped flows (bulk-promote, a songbook's `family_manifest`) with no clean single-call API shape, or
3. a `native:<reason>` entry for the two endpoints that grew Bearer support **on themselves** rather than gaining an `api.php` twin (`manage/places-api.php`, `manage/print-pdf.php` — see below).

A new manage-page action that lands without a mapping entry fails the guard outright — it is mutation-proven (an injected fake action reliably goes red), so "every admin action reaches native apps" is a standing, machine-checked fact rather than a claim that can quietly go stale the way the codebase's own history shows undocumented/unmapped surfaces otherwise do (rule #34).

**The Bearer-auth seam.** `api.php` has accepted `Authorization: Bearer <token>` (in addition to the `ihymns_auth` cookie) since well before this program — every `admin_*` action was already native-reachable. The gap was the **song-editor API**, which gated on the `/manage` PHP session cookie alone; a native curator app has no cookie jar. The fix is one shared verifier, `apiTokenResolveBearerUser()` in `includes/api_tokens.php`, wired into `manage/editor/api2.php`, the legacy `manage/editor/api.php`, `manage/places-api.php`, and `manage/print-pdf.php`: each tries Bearer first and falls through to the pre-existing cookie check, byte-identically, when no Bearer header verifies. A Bearer request is CSRF-immune by construction (an explicit header a cross-site page cannot attach), so the `X-Requested-With` same-origin gate now applies only to the cookie path. Per-action entitlement checks are untouched — a Bearer caller gets exactly its own user's privileges, never more.

**Scale, as last measured:** `api.php` dispatches **312** public `?action=` cases (up from 223 before this program) and `api2.php` **66** — both counts verified live by `tests/php/lib/dispatch_parser.php`, the same tokeniser the coverage guard and `test-openapi-actions-exist.php` use, so treat any number here as orientation rather than a pinned contract.

### Guided-wizard framework (#1992 family)

`js/modules/admin-wizard.js` (`createWizard(rootEl, opts)`) is the **one reusable multi-step "wizard" stepper** for `/manage/*` admin pages — the same shape every "set up a new thing in N small screens, Next/Back, a progress trail" flow needs, built once instead of per-feature. It knows how to show one step at a time, move focus correctly when the step changes (WCAG 2.4.3), block advancing until the current step validates, and render the progress trail; it carries **zero domain knowledge** of any one wizard. Steps are **derived from the DOM** (`[data-wiz-step]` elements, in document order), never a JS-side list that the markup could drift out of sync with (rule #35 — the same drift class #1581's event-name bug was). A host supplies field markup, its own `validateStep`/save logic, and a modal to put it in — `opts.host` is either `'bootstrap-modal'` (the host page's own Bootstrap Modal instance supplies the focus trap; every current consumer uses this) or `'overlay'` (the module lazily pulls in `js/utils/dialog-a11y.js`'s `openModalDialog()` for pages with no Bootstrap modal already on screen).

Eight guided wizards consume this ONE engine, each additive with the page's pre-existing manual path left fully intact — the first five (#1992/#1993/#1995/#1996/#1997) shipped first; the last three (#2003/#2004/#2005/#2006, epic #2002) completed the program:

| Wizard | Page | Steps |
| --- | --- | --- |
| Add provider (guided) | `manage/external-link-types.php` | Name → sample-URL pattern suggestion + live test → review |
| New songbook (guided) | `manage/songbooks.php` | Identity → permanent short code (live uniqueness + digit warning) → optional details → review |
| Live Service setup (guided) | `manage/venues.php` | Live-session mode (Quick Live Follow vs. Service Mode) → venue → service time → optional presentation-app key → review |
| New organisation (guided) | `manage/organisations.php` | Organisation details → licences (optional, finer-gated) → members (optional) → review |
| Guided (New Song) | Editor2 (`manage/editor/editor2.php`) | Songbook → number (live availability) → title + alt-titles → seed verse/chorus → create + open |
| Connect a service (guided) | `manage/configuration.php` | Intro → what you'll need (+ provider-portal link) → provider choice (CAPTCHA only) → paste credentials → save → live connectivity test → confirm dependent surfaces are live — one shared modal covering IntAppsAPI, CueRCode, CAPTCHA, Email, Sign in with Apple and Partner webhooks |
| Guided environment setup | `manage/setup-database.php` | Environment (connection/tables) → Apply migrations (with an auto-backup-first step) → Baseline data → Connect services (links out to the wizard above) → Verify |
| Turn on content locking (guided) | `manage/gating.php` | Understand → Preview (impact counts) → Rules (optional) → Licence check → Enable → Test a song → Finish, with a one-click rollback |

Each wizard is pure client orchestration over the page's **existing** write actions (rule #22) — no wizard introduces a parallel save path; the External Link, Songbook and Organisation wizards each gained one new `admin_*_create` API twin so the guided flow and a native caller reach the identical validate/create core the page's own manual form already used (see [[API Reference]]). The four list pages (External Link Types, Songbooks, Venues, Organisations) additionally render a shared **"Get started" empty-state launcher** (`manage/includes/wizard-empty-state.php`) in place of a bare empty list — an icon, one line of explanation, and a button that opens the exact same wizard modal the page's header button opens (same `data-bs-target`, so it's a second trigger on one modal, never a second implementation). `tests/php/test-wizard-empty-state.php` and the per-wizard PHP guards (`test-songbook-wizard.php`, `test-service-setup-wizard.php`, …) keep the markup contract and modal wiring honest.

**Connect a service (#2003/#2004) is registry-driven, not six copies.** `includes/integration_registry.php` is the ONE source of truth — `integrationRegistry()` (pure/static: label, icon, intro copy, "what you'll need" list, provider-portal link, field list, save action, test function, dependent surfaces) and `integrationClientProjection(callable $getSetting, …)` build the secret-free JSON the client reads. A `secret:true` field's setting value is used only inside a `!== ''` boolean comparison and never assigned into the output array, so a secret cannot structurally reach the browser regardless of what the reader returns. The one generic client driver, `js/modules/integration-connect-wizard.js`, renders whatever the registry describes — field type, validation, POST body — with no per-integration literal anywhere in the file. Each integration still saves through its page's **existing** classic save action (`save_intappsapi`/`save_cuercode`/`save_captcha`/`save_email`/`save_apple`/`save_webhooks`) — the wizard is a guided front end onto the same write path, never a parallel one. CueRCode's connectivity test (`cuercodeProbe()`) bypasses the response cache deliberately, so a cache hit can never make the test pass without the live service actually answering.

**The gating activation wizard (#2006) is the safety-sensitive one.** `includes/gating_wizard.php` is a read-only census + a pure precondition evaluator + a dry-run song simulator, sitting entirely in front of the ONE existing `content_gating_enabled` flag (rule #28) — it never touches the enforcement chain (`content_gating.php`/`access_context.php`/`access_resolver.php`/`ccli_validator.php`/`licences.php`) itself. Its precondition table is deliberately **warn-but-allow, never a hard block**, except the one genuine technical prerequisite (an un-migrated gating schema) — every licensing risk surfaces as a loud, must-acknowledge warning plus a typed `ENFORCE` confirmation, not a wall the admin can't get past (an explicit owner correction during design). Rollback is unconditional and reachable with the wizard closed, and removes only the restriction rows the wizard itself seeded (tracked via a sentinel), never a curator's own rows.

### Data Flow

Every runtime read is **live MySQL** — this is the single biggest architectural fact about the current codebase (the DB-direct rewrite, epic #1010, June 2026). The original migration from the `.SourceSongData/` text files into MySQL was a **one-time** event; the pipeline that did it (below) is now historical, not something a fresh install repeats — new/updated song text goes in through the Song Editor's bulk importers instead (#664):

```
.SourceSongData/ (raw text files)
        │
        ▼  tools/parse-songs.js  (historical — the JSON it wrote was a one-time
        │                         seed; today it writes only a gitignored
        │                         tmp/songs.json local build artefact, #1617)
MySQL `ihymns` database (single shared DB across all 3 docroots)
        │
        ▼  scoped, live reads only — nothing materialises the whole corpus
        ├── getSongsSlimIndex()  — lightweight id/number/title/songbook index
        ├── getSongs($abbr)      — one songbook
        └── getSongById()        — one full song record
```

New content today skips the JSON step entirely: `/manage/editor`'s bulk importers (ZIP, OpenLyrics/OpenLP, ProPresenter, VideoPsalm, FreeShow, OpenSong, Proclaim, ChordPro, EasyWorship) write straight to MySQL, and `appWeb/.sql/restore.php` restores a real backup — see [[Database & Migrations]] § Data Migration. The one-time bootstrap script this diagram used to name, `appWeb/.sql/migrate-json.php`, no longer exists (retired #1614).

Nothing loads the whole ~14,000-song catalogue into PHP memory at once — an earlier unscoped read caused an OOM (#929). If the database is unreachable, the app shows a themed 503 maintenance page; the **only** fallback is whatever a client previously downloaded into its own offline cache (browser Cache Storage for the PWA, GRDB for Apple) — there is no server-side JSON fallback mode.

### Catalogue entities — single-home modules (#1741)

The catalogue expansion (Musicians / Works / Tunes / Song identifiers) is built on a small set of shared, single-home modules — the modularity rule made concrete, so a new caller reuses rather than re-forks:

```
includes/identifier_normalize.php  — IHYMNS_ID_SCHEMES + one canonicaliser per scheme (iswc/ccli/bowi/isrc/isni/ipi)
includes/identifier_resolve.php    — ihymns_resolve_identifier() — table/column-gated, bind_param
includes/pages/identifier.php      — the ONE page the /iswc /ccli /ipi /isni /bowi /isrc alias routes render
includes/media_identifiers.php     — RECORDING_EXTERNAL_ID_TYPES vocabulary + pure validators (no DB)
includes/song_external_ids.php     — the tblSongs.Isrc -> tblSongExternalIds dual-write mirror (#1749)
includes/tune_helpers.php          — tuneFindOrCreateByName() (the ONE tune lookup) + ihymns_meter_normalize()
includes/partials/external-links-panel.php — shared Work/Tune/Musician external-links editor
includes/musician_duplicates.php   — registry-vs-registry duplicate scan (#1785) — pure candidate generator + the shared disambiguation-payload builder
includes/work_admin.php            — workMedley*() ordered-medley core (#1907) — tblWorkComponents attach/replace/cycle-guard; the /manage/works editor AND the component_upsert lockstep both delegate here
```

**Medley composition + component labels (#1907, #1860 Phase 5).** Two per-section concerns ride the existing component read/write path, never a fork: a custom **display** name (`tblSongComponents.Label`, DISPLAY-ONLY — `Type` stays authoritative for CSS + every machine-export keyword, so exporters carry zero `.label`, CI-guarded by `tests/test-component-label-sites.js`) and a per-section **source Work** (`SourceWorkId`, medley stitching). Both are thin-row metadata carried on `component_upsert` / `lyricLinesWriteComponents()` — never the `tblLyricLines` line path (rule #25). The read shape emits `label` **sparsely** (public) so the strict-`===` read contract holds unchanged; the write path is **silent-wipe-proof in three layers** (handler target-preserve + read-modify-write carry + writer provided-flag preserve) because `components_replace`/`save_song_core` rebuild fixed shapes. Setting a section's `SourceWorkId` additively syncs `tblWorkComponents` (the §3.6b.2 lockstep, non-blocking); the song page + `/work/<slug>` render a read-only "Medley of: A, B, C".

In the editor, `manage/editor/api2.php::ed2_songTuneApply()` is the single place `tblSongs.TuneName`/`TuneId` are written — always together, so a tune edit (or a whole-song save, bulk import, or revision restore, all of which funnel through it) can never strand the registry link. See [[Database & Migrations]] for the entity tables and **DEV_NOTES.md → Architecture Decisions** for the reuse contract.

**Permanent internal ids — ILID (#1860).** `includes/ilyrics_id.php` is the ONE allocator. `IHYMNS_ILID_TYPES` is the ONE prefix → table registry (song `ILS`, work `ILW`, musician `ILM`, tune `ILT`, publisher `ILP`, catalogue `ILC`, songbook `ILB`, document/media `ILD`) — every function in the file derives its behaviour from this map, never a second hardcoded prefix list. `ilidAllocate()` mints inside the caller's own transaction (`SELECT … FOR UPDATE` on `tblIlyricsIdSequence` + a claim-check against `uq_IlId`, so two concurrent creates can never collide) and is wired into every song/work/musician/tune/publisher/catalogue/songbook/media create funnel across both editors, the importers and the `/manage` CRUD pages. Format is `IL<letter>` + 10 zero-padded digits (e.g. `ILS0000012345`) — no separator, which is what makes it grammar-disjoint from the public `<letters>-<digits>` SongId form: a **dual-addressing** resolver can branch on grammar alone (hyphen present = public id) rather than probing which table an ambiguous token belongs to. The identical pattern — `ilidParse()`, then an `INFORMATION_SCHEMA` probe of the entity's `IlId` column, then a try/catch fallthrough on any miss — repeats in every resolver that accepts an IL id today: `SongData::getSongById()`, the `musician`/`publisher`/`tune` public pages, and `song-media.php`. Every one of these is a verified no-op on an un-migrated install (see [[Database & Migrations]] for the schema; [[API Reference]] for the resolvers). This is explicitly the live groundwork for the eventual iLyricsDB merge (Phase TWO) — ids are minted and dual-addressed now, well ahead of any cross-database join.

**Musician-registry deduplication (#1785).** `/manage/musician-duplicates` finds registry rows that are probably the same person spelled two ways, live-computed per page load (no precompute table — the registry is two orders of magnitude lighter than the song corpus, so the staleness cost that justifies `tblSongLinkSuggestions` for songs isn't worth paying here). Blocking (an exact fold, or a metaphone of the first/last name token) keeps candidate pairs to the low thousands even at N≈5,000 registry rows — `includes/musician_duplicates.php`'s `musicianDuplicatesFindCandidates()` is a PURE function over plain name arrays (no `\mysqli` in its call graph), separated from the DB-touching orchestrator `musicianFindRegistryDuplicates()` so the blocking maths is unit-testable without a database. Every merge, from any of the three affordances that offer one (`/manage/musicians`'s Merge modal, `/manage/musicians-bulk-promote`, this review page), delegates to the ONE shared core `musicianMergeExecute()` (`includes/musician_helpers.php`, #1785 C4/C5) — never re-implemented per surface. Dismissals persist to `tblMusicianDuplicatesDismissed` (mirrors `tblSongLinkSuggestionsDismissed`'s pair-normalised shape). See `.claude/musicians-dedup-1785-plan.md` for the full design.

### Shared cores — branch `claude/issue-sweep-fixes-89` (#1765 / #1769 / #1767 / #94 / #1786 / #93)

Each family below lands as a small set of single-home modules; a new caller reuses, never re-forks (the modularity rule made concrete). Design lives in the cited `.claude/*-plan.md`, not duplicated here.

**Print pipeline (#1767 remainder, `.claude/print-templates-1767-remainder-plan.md`).** `includes/print_template_schema.php` is the ONE block/page-option registry (mirrored client-side; agreement CI-guarded by `test-print-block-registry.php`). `includes/pdf_renderer.php` is the ONE swappable engine seam — mPDF ~8.3 vendored under `appWeb/private_html/lib/pdf/vendor/`, **outside every docroot** (GPL-2.0, see LICENSING.md), 503-degrading. `includes/html_sanitizer.php` provides the allowlist profiles applied to uploaded custom layouts; `includes/print_usage.php` is the ONE CCLI print-usage writer; `includes/print_custom_layout.php` handles the full-page layouts. **One-renderer invariant:** browser print, server PDF (`manage/print-pdf.php`), batch set-list PDF, and the admin live preview all render through the same body renderer — guard `tests/php/test-print-one-renderer.php`.

**Gating Model-2 (#1769 P2, `.claude/gating-model-review-1769-plan.md`).** `includes/access_context.php` resolves the viewer ONCE per request; `includes/access_resolver.php` makes every field/media decision; `includes/licence_registry.php` is the ONE `tblLicenceTypes` reader. `contentGatingApply()` / `contentGatingMediaAllowed()` in `includes/content_gating.php` are thin delegates over these. Entirely dormant behind `content_gating_enabled='0'`; hub at `/manage/gating`.

**Publisher cores (#93).** `includes/publisher_admin.php` (validate / uniqueness / persist / rename-cascade / aliases / merge / delete) + `includes/publisher_helpers.php` (`IHYMNS_PUBLISHER_KINDS` / `IHYMNS_PUBLISHER_ROLES` VARCHAR vocabs, slug fold, `publisherFindOrCreateByName()`). Fully specified in CLAUDE.md rule #37; `/manage/publishers` and the future `admin_publisher_*` API both delegate here.

**Organisation-licence core (#1969).** `includes/org_licence_admin.php` is the ONE validate/list/upsert/update/delete/reconcile core for `tblOrganisationLicences` (an org holds several licences in parallel — CCLI + MRL + …). All four surfaces delegate here: `/manage/organisations` (global admin, per-row editor), `/manage/my-organisations` (member self-service), and the `admin_organisation_update` + `org_admin_licence_*` API actions. Types are validated against the `tblLicenceTypes` registry (never a hard-coded list, rule #9); `orgLicenceSyncSet()` reconciles a submitted type-set NON-DESTRUCTIVELY (staying rows keep their number/expiry/active/notes). The tier resolver (`resolveEffectiveTier()`) and the CCLI gate (`getUserEffectiveLicences()`) both union the full active, non-expired licence set.

**IA reconcile (#94, `.claude/ia-ocr-94-plan.md`).** `includes/ia_client.php` — an SSRF-hardened, host-bound archive.org fetcher in the `intapps_client.php` / `cuercode_client.php` mould — plus `includes/ia_reconcile.php`, a pure segmenter/scorer. Read-only for song content, CI-enforced by `tests/php/test-ia-reconcile-guards.php`.

**List-sort cores (#1786, `.claude/public-list-sort-1786-plan.md`).** `js/utils/sort-compare.js` (pure comparators, shared with `js/modules/admin-table-sort.js`) + `js/modules/list-sort.js` + `includes/partials/list-sort-control.php`. Persistence is device-local plus the `user_settings` namespace `list_sorts` for signed-in sync (never a bespoke per-user endpoint).

**Songbook visibility (#1765).** `includes/songbook_visibility.php` + a `SongData` audience mode (`forAdmin()`); public reads compose `songVisibleSql()` AND `songServableSql()` so a disabled songbook drops out of every public query at once.

**MARCXML + service driver (#1765 / #1770).** `includes/marcxml.php` is the pure, DB-free MARCXML import+export for the three publication entities (`manage/includes/marcxml_admin.php` is the admin funnel). `includes/service_driver_keys.php` backs the `service_drive` endpoint, which writes through the same `serviceMode_applyBroadcast()` core as `service_broadcast`.

**QR (#1767 R / owner directive; cache #1920).** QR images are generated server-side by the CueRCode service via `includes/cuercode_client.php` and served by the same-origin `/qr.php` endpoint — no client-side QR library. See CLAUDE.md rule #38. A QR for a fixed `(payload, options)` pair never changes, so the client now reads through a server-side cache first: `cuercodeGenerateCached()` checks `tblQrCache` (keyed by a sha256 of the canonical payload+normalised-options JSON) before ever calling CueRCode, and stores only a real success — never a failure, so an outage can't freeze into a permanent cached 503. Dormant until keyed AND until the (additive, idempotent) migration has run; a 90-day TTL plus a 20,000-row belt bound growth. Both `/qr.php` and `pdf_renderer.php`'s `_pdfInlineQrImage()` (the server-PDF path, which never self-requests `/qr.php` over HTTP) go through the same cached wrapper.

**Perf & resilience — Wave 3 (#1920 / #1921 / #1571 safe subset).** Beyond the QR cache above: `?action=songs_index` (the PWA's slim catalogue index) now supports conditional revalidation — a version-signal `ETag` (two cheap COUNT/MAX aggregates over `tblSongs`/`tblSongbooks`, folded with the API contract version, the deploy commit SHA, and a schema-shape token; **never a hash of the payload**, which would re-materialise the very read this exists to avoid) lets a matching `If-None-Match` short-circuit to a **304 with no body**, skipping the ~14.5k-row query entirely. The service worker's `songs_index` route gained a dedicated `networkFirstRevalidated()` strategy (beside, not instead of, the existing `networkFirstWithCache()`) that actually sends `If-None-Match` from its cached copy's ETag — without this half the server-side ETag would have been a silent no-op, since that route's `cache: 'no-store'` fetch (a deliberate fix for an unrelated browser-HTTP-cache trap) also meant no validator was ever attached. Separately, `songbook_export` gained its own read-rate-limit bucket (split from `bulk`, #1571), and every export surface now confirms before building a 500+ song export and shows coarse progress while doing so.

**Organisation logos (#1830 + #1840, `.claude/org-logos-1830-plan.md` / `.claude/org-logo-surfaces-1840-plan.md`).** `includes/org_logo_helpers.php` (the ONE `IHYMNS_ORG_LOGO_KINDS` 10-kind registry, ladder order = the `'auto'` fallback ladder + reads) + `includes/org_logo_admin.php` (validate/stage/upsert/delete/toggle — the SVG branch's ONLY path into `includes/svg_sanitizer.php`, a NEW, separate, STRICTER sibling of `html_sanitizer.php` built specifically for turning an untrusted SVG upload into safe bytes to store; see [[Security]]). Served by the standalone `org-logo.php` (mirrors `qr.php`/`og-image.php`) — always `<img src>`, never inlined. `#1830` wired the print `logo` block only (`includes/print_template_schema.php` + `js/modules/print.js`'s `renderBlock('logo')` + `pdf_renderer.php`'s `_pdfInlineOrgLogo()`, mirroring `_pdfInlineQrImage()`'s "never mPDF self-request over HTTP" doctrine), pinned to the `'default'` variant. **`#1840` landed the three surfaces #1830 deferred**, each resolving through the ONE new themed resolver `ihymnsOrgLogoResolveThemedAsset()` (+ its JS twin `resolveThemedAsset()` in `js/modules/org-logo.js`) rather than forking its own kind/variant fallback: (1) an app-header co-brand emblem (`js/modules/header-branding.js`, signed-in members only, theme-matched); (2) a Service-Projection corner-bug logo (server-resolved per venue, theme hardcoded dark, an operator on/off toggle); (3) a branded colour band on a shared set-list's OG-image share card (`tblOrganisations.BrandColor`, gated on the link's existing `ShowSharerName` consent, PNG-only logo bytes read directly — never an HTTP self-request to `org-logo.php`). `tests/php/test-svg-sanitizer.php` and `tests/php/test-org-logo-surfaces.php` (a tree-derived wiring guard, never a typed file list) are both mutation-proven.

**ProPresenter interop (epic #1968).** A hand-rolled, server-side proto3 wire-walker (`includes/propresenter7_decode.php`) — never a browser decoder, since the export side is already forced onto an eval-free static protobuf module by the enforcing CSP (#1788) — reads genuine ProPresenter 7+ files. **Import**, via the Song Editor's bulk-import pipeline (`includes/song_importers.php`): a single `.pro` presentation (lyrics, structure, arrangements, CCLI block), a `.probundle` (one or more bundled presentations, read by a tolerant hand-rolled ZIP64 scanner — `includes/propresenter7_zip.php` — because genuine PP7 bundles are ZIP64 with an inconsistent end-of-central-directory that `ZipArchive`/`unzip`/Python `zipfile` all reject), and a `.proplaylist` (a service order, imported as one iHymns set list). **Export**: a song to `.pro`/`.probundle` and a set list to `.proplaylist`, sharing the same CSP-safe static protobuf encoder. **Media ingest** (Phase 4): background video/image referenced by an imported bundle lands in `tblSongMedia`, gated **admin-only until a curator publishes it** via the per-row `Visibility` column (`includes/song_media_visibility.php` — see [[Database & Migrations]]); a single-song export can also **embed** a song's published background media into the `.probundle` it produces (#1979). **Chord round-trip (#1968 P6).** PP7 does **not** store chords as inline `[G]` brackets in the slide text — that's only ProPresenter's own editing metaphor. Chords are positioned protobuf `CustomAttribute` rows (an int range + a chord string) layered over clean plain text; the decoder/importer buckets them into iHymns' existing per-line positioned `chords` cells (riding `lyricLinesWriteComponents()`, rule #25 — no new schema), and the exporter emits the same `custom_attributes[]` shape at the displayed column positions, with the RTF slide text itself always clean. **Timeline groundwork (#1968 P6, dormant).** `Presentation.timeline`'s auto-advance cue schedule is now fully decoded and, behind the `pp7_timeline_import_enabled` app-setting (seeded off), captured into the new dormant `tblSongPresentationCues` table — schema and capture only; no playback or auto-advance UI exists yet. Every decoder/exporter change in this epic is cross-validated against an independent decoder (protobufjs reflection) on real, MIT-licensed third-party `.pro`/`.probundle` fixtures — never validated only against its own output.

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

- **166 tables** (plus 7 compat `VIEW`s, e.g. `tblCreditPeople` → `tblMusicians`), named `tblCamelCase`. New tables land often, so treat any written-down count — including this one — as a snapshot rather than gospel: `appWeb/.sql/schema.sql` is the canonical source of truth for a fresh install, and `grep -c '^CREATE TABLE' appWeb/.sql/schema.sql` (or `grep -c '^CREATE OR REPLACE VIEW\|^CREATE VIEW'` for the views) always gives the live number.
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
(both gated `manage_configuration`). A **"Set up with a guide"** button on
this card (and on CueRCode/CAPTCHA/Email/Sign in with Apple/Partner-webhooks
alongside it) opens the shared **"Connect a service"** guided wizard — see
[Guided-wizard framework](#guided-wizard-framework-1992-family) above — which
saves through this same card's existing action and adds a live connectivity
test on top.

### Outbound-request SSRF guard (`includes/network_guard.php`)

Every server-side client that dials an admin-configured base URL — CueRCode
(`cuercode_client.php`), IntAppsAPI (`intapps_client.php`), and the Internet
Archive reconciliation client (`ia_client.php`) — resolves the host first and
refuses to proceed if it lands on a private (RFC 1918/4193) or reserved
(loopback, link-local — including the `169.254.169.254` cloud-metadata
address) range, via the one shared `ihymnsHostResolvesPrivate()` function.
Added as part of the 2026-08-30 security audit (finding L-2): previously each
client trusted an `https://` scheme as sufficient proof a URL was safe,
regardless of where it actually resolved, so an admin typo or a compromised
admin account could point an outbound call back at the server's own internal
network. The function mirrors a pre-existing SMTP-host check on
`manage/configuration.php` (#1304) rather than forking a third copy. See
[[Security]] for the full write-up.

---

## Security summary

- Content Security Policy with per-request nonces; enforcing (`script-src 'self' 'nonce-…'`, no `'unsafe-inline'`) — see the SPA fragment constraint above.
- `validateCsrfRequest()` — same-origin AJAX check (`X-Requested-With` + `Origin`/`Referer` host **and port** match) for state-changing endpoints, replacing the older baked-session-token-only check for long-lived admin pages. The port comparison was itself a fix (#1709): `HTTP_HOST` keeps the port (`example.com:8080`) but `parse_url($origin, PHP_URL_HOST)` never includes it, so a naive string compare could never match on a non-default port — and, in the opposite direction, silently accepted a different port on the same host as same-origin. Both sides now resolve to "explicit port, else the header's own scheme's default" before comparing.
- Role + entitlement gates (`requireAdmin()`, `userHasEntitlement()`) on every admin surface.
- A registry-driven content-access-tier / gating system (`TIER_CAPS` in `includes/access_tier_validation.php`) — entirely dormant unless explicitly enabled, and never a hardcoded per-tier matrix. Enforcement splits in two: `contentGatingApply()` (`includes/content_gating.php`) strips gated fields from JSON *payloads* (`song_detail`, `song_data`, `random`, `songbook_export`); its sibling `contentGatingMediaAllowed($kind, $userId, $presenceToken)` answers the same question for one media row and gates the *bytes* — `song-media.php` and the `bulk_audio` offline manifest. A payload gate alone hides the affordance but leaves a URL-addressable file bookmarkable, so every gated asset needs both checks resolving through the same registry.
- Friendly, theme-aware error pages for every status the app actually emits — `errorPageMap()` / `errorPageStatuses()` in `includes/error_page.php` is the one status→copy registry (400/401/403/404/405/410/429/500/503), and `error.php` (the Apache `ErrorDocument` target for 403/405/500/503) derives its render whitelist from it rather than a second hand-typed list (#1704). 405 and 410 are recent additions — 410 is what a soft-deleted or merged-with-no-replacement song now returns instead of a generic 404.
- Defensive hardening pass (#1906, no user-visible behaviour change): the `/manage` admin area and the social-card `og-image.php` endpoint gained their own security headers / CSP; registration + email-code brute-force protections now actually engage (a dead registration throttle revived; the email-code check gained a per-email bucket on top of per-IP); a cross-surface admin sign-in session-fixation gap was closed with `session_regenerate_id`; copyrighted lyrics no longer leak through the share-image endpoint when content-locking is on; several heavy public endpoints (og-image, random, song-of-the-day, media) gained rate limits; and error responses now carry the security headers (`Header always set`). `X-Powered-By` advertises our own `iHymns/<version>` identity while the PHP runtime version is suppressed at source (`expose_php=Off`). An owner/host-gated remainder (`Options -Indexes`, `ServerSignature Off`) is still pending an alpha check.
- **Redo audit of the API-coverage program (2026-08-29).** Once the ~90 new `admin_*`/`org_admin_*` actions and the editor-API Bearer seam above landed, the new surface was re-audited end-to-end (Bearer seam, secrets handling, uploads, injection) before being called done. It found one genuine issue: a **cross-tenant IDOR** in `org_admin_schedule_save` (Batch 3) — the action authorised an org-admin's write against the org derived from the *posted* `venue_id` but never re-checked the *existing* schedule row's own owning org, so an admin of org A could re-parent org B's service schedule onto their own venue via a crafted `schedule_id`. Fixed by mirroring `org_admin_venue_save`'s existing-row double-check (load the row, require `userCanActOnOrg()` against its *current* `OrgId`, before the write); guarded by `tests/php/test-security-schedule-idor.php` (mutation-proven — dropping the re-check goes red). Two Low/hardening-grade findings closed alongside it: `manage/print-pdf.php`'s `copies` value is now clamped at the endpoint before it reaches the CCLI usage log (defence-in-depth — `printUsageLog()` already clamped it, so this was never externally exploitable), and a handful of the new `admin_*` actions were moved off a bare role check onto the matching `userHasEntitlement()` call the page itself already uses (behaviour-neutral today — every admitted role already held the entitlement — but a future entitlement revocation now reaches the API too, not just the page). Everything else the redo pass checked — the Bearer resolver, the show-once secret discipline on the new API-key/webhook endpoints, file uploads, injection — came back clean.
- **2026-08-30 whole-codebase security + accessibility audit — two passes.** The first pass closed two Low-severity security findings: the Database Setup dashboard's `?action=` links gained a `validateCsrfRequest()` gate (L-1 — previously `SameSite=Strict` was the only defence against a forged cross-site request); the CueRCode/IntApps/Internet-Archive outbound clients gained the shared `network_guard.php` private-address refusal described above (L-2). Alongside it, a batch of keyboard/screen-reader fixes landed across the favourites tag editor, the shared external-link chip-list editor, the compare tool, the musicians page, and every guided-wizard modal (a dead "Undo" button in the gating wizard was actually unreachable since it shipped; several live-status regions and focus-management gaps were fixed). A coordinated light-theme colour-contrast pass fixed five failures — `.link-light`, bare `.text-warning`/`.text-info`, two outline-button variants, and 35 `.btn-close-white` dismiss buttons — all scoped to `[data-bs-theme="light"]` so dark theme is untouched (closes #2000).
- **A second, deeper pass the same day (epic #2018 correctness review + epic #2027 accessibility epic).** Correctness review (2 Medium, 1 Low, all confirmed by trace): F-1 — `network_guard.php`'s SSRF check missed a bracketed-IPv6 (`[::1]`) or numeric-IPv4 (`0x7f000001`, `2130706433`) host literal, so a URL spelled that way slipped the L-2 guard above; fixed by normalising both forms before classifying the host. F-2 — `resolveEffectiveTier()` only enforced licence expiry on one of the two org-licence read paths added by #1969, so an **expired** legacy-column CCLI licence could still elevate a member to the paid tier; both paths now agree. F-3 — a cosmetic duplicate-tag bug in the favourites custom-tag editor (wrong escaping style used inside a CSS selector). Separately, `includes/pages/publisher.php`'s JSON-LD block was missing `JSON_HEX_TAG` (a stored-XSS gap identical to one `musician.php` had already been fixed for) — closed, with a new tree-derived guard checking every such block in the codebase. The accessibility half (epic #2027) is a full WCAG 2.1 AA pass: 0 Critical, 2 High, 8 Medium, 10 Low findings, all fixed. Highlights: `.btn-info` and several small-text colour tokens raised to clear 4.5:1; the opt-in "Emphasise Links" mode gained an underline (its colour-only version couldn't clear both the WCAG 1.4.1 and 1.4.3 contrast rules at once on the surfaces it renders on) with retuned colours that now clear 4.5:1 outright; four more JS-built dialogs (keyboard shortcuts, the second presentation overlay, song comparison, the quick song-number picker) adopted the shared `openModalDialog()` focus-trap recipe; `router.js` now re-titles the browser tab with the actual record name for every song/songbook/tag/musician/publisher/tune/work page instead of one generic placeholder per page type; the legacy (v1) song editor's previously-indistinguishable form controls gained real accessible labels; and `prefers-reduced-motion` is now honoured by every JS-driven smooth-scroll site, not just some of them. Six CI guards were built or widened alongside the fixes, each mutation-proven (rule #34): a computed-contrast registry that recomputes real WCAG ratios from the live CSS tokens rather than trusting a comment's claimed number, a tree-wide dialog-recipe scanner, a router-title-coverage check, and three others.

See [[Security]] for full details.

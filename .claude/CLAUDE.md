# iHymns — Claude Context

Auto-loaded by Claude Code on session start. This file codifies **how to work in this repo** — project rules, conventions, architecture guardrails. Paired with `.claude/ProjectBrief.md` (the current state snapshot) and `.claude/ProjectOverview.md` (the original scoping doc).

## 🧱 Modularity rule (non-negotiable)

**When a piece of UI, logic, or data exists in more than one place, extract it into a shared module. If a shared module already exists, reuse it — do not duplicate.**

Concretely, for every platform variant:

- **Web / PWA** (`appWeb/public_html/`) — every `/manage/*.php` page MUST use the shared partials in `appWeb/public_html/manage/includes/` (`admin-nav.php`, `admin-footer.php`, `head-favicon.php`, `auth.php`, `db.php`). Pages MUST NOT inline their own navbar, footer, favicon set, or Bootstrap / Bootstrap-Icons / admin-css loads. New cross-page concerns get a new partial or JS module — not copy-paste into each page.
- **Apple** (`appApple/`) — cross-platform (iOS / iPadOS / tvOS) code lives in shared Swift packages or frameworks. Per-target code lives under its target folder and may only import the shared code, not duplicate it.
- **Android** (`appAndroid/`) — shared UI + domain logic in shared modules (Kotlin Multiplatform where possible; shared Gradle modules otherwise). Per-target code consumes the shared module.
- **Amazon FireOS** — a target of the Android codebase; shares the Android sharedModules, differing only in the launcher / store metadata.

### Web/PWA specific checkpoints

Before adding code on `/manage/*` or `/` (main app), review this list:

1. **Navigation chrome** → `manage/includes/admin-nav.php`. The page supplies `$activePage`; the nav does the rest.
2. **Footer / copyright / version** → `manage/includes/admin-footer.php`. Do not render your own; do not re-load Bootstrap JS anywhere else.
3. **Favicon / app icons** → `manage/includes/head-favicon.php` (admin) / the `<link>` block in `index.php` (main site).
4. **Auth + CSRF + role + entitlement checks** → `manage/includes/auth.php` + `includes/entitlements.php`. Pages MUST call `isAuthenticated()`, `requireAdmin()`, or `userHasEntitlement()` — never reinvent the check.
5. **DB connection + parameter binding** → `includes/db_mysql.php::getDbMysqli()`. Never instantiate PDO / mysqli directly. (PDO has been fully removed — see #554 / #555.) Every value that enters a SQL string MUST be bound via `$stmt->bind_param(...)` — never string-interpolated, never `$db->query("... " . $userInput . " ...")`. The only legitimate string interpolations into SQL are (a) hardcoded constants from PHP source (column names from a fixed `$mappings` array, `?,?,?` placeholder strings built from `array_fill(0, count($values), '?')`), or (b) values that have first been validated against an exact allow-list. CI's `bindParamSafe()` wrapper (#928) catches placeholder/value count mismatches at runtime; `tests/php/test-migration-registry.php` flags the always-true probe pattern. New manage/* page handlers, API endpoints, and migration probes all follow this same convention.
6. **Card-layout reorder + hide** → `includes/card_layout.php` (server) + `js/modules/card-layout.js` (client). Any new card grid that should support reorder uses `data-layout-surface` + the shared helpers. **Two hydration modes by surface:** the admin dashboard is rendered per-user, so the server emits cards pre-ordered (`cardLayoutResolve()`); the **public home is a shared-cache fragment** (`/api?page=home` is in `$_cacheablePages`) so the server CANNOT emit a per-user order — instead it emits the canonical default and the client applies the saved order/hidden set via `applyCardLayout()` (#448, fetches `card_layout_get` over the same-origin `ihymns_auth` cookie, mirrors `cardLayoutMerge()`). Never try to server-personalise a cached fragment; never reintroduce a per-user ETag on `page=home`. The drag handle host is configurable via `data-layout-handle` (defaults to `.card-admin`; the home uses `.card-layout-handle`).
7. **Offline-download UI** → `js/modules/offline-ui.js`. Any new "save for offline" button uses `data-song-download` or `data-songbook-download` and relies on the shared feature detection + state machine.
8. **Content access / gating** → `includes/content_access.php::checkContentAccess()`. Never query `tblContentRestrictions` directly from a page or an API handler.
9. **Licence type picker** → `$LICENCE_TYPES` map in `organisations.php` today, will migrate to `tblLicenceTypes` (#459). Never hard-code licence keys inline elsewhere.
10. **Entitlement labels** → `$ENTITLEMENT_LABELS` map in `manage/entitlements.php`; friendly labels only, tech detail behind `global_admin` gate.
11. **External-link URL → provider detection** → `js/modules/external-link-detect.js` (exposes `window.iHymnsLinkDetect`). Patterns are loaded into `window._iHymnsLinkTypes[].patterns` from `tblExternalLinkPatterns` (#845); the module falls back to its bundled `RULES` constant when patterns haven't been migrated yet. Any new card-list / chip-list editor that pastes a URL and wants to pre-select a provider MUST consume this module — never re-inline a regex list.
12. **External-link type registry** → `manage/external-link-types.php` (CRUD for `tblExternalLinkTypes` + `tblExternalLinkPatterns`). Curators add new providers + their URL patterns here; nothing in `/manage/*` or `js/modules/*` should hard-code a provider key list.
13. **Responsive admin tables** → opt in via `<table class="admin-table-responsive">` and tag each `<th>`/`<td>` with `data-col-priority="primary|secondary|tertiary"` (#842). Stacks columns at progressive breakpoints; pair with the sortable-headers convention from #844 — both shipped on Credit People, Songbooks, Songbook Series, Works, and the eight other admin lists.
14. **Works composition grouping** → `tblWorks` (self-FK nesting) + `tblWorkSongs` + `tblWorkExternalLinks` (#840). Public page `/work/<slug>` via `?page=work&slug=…`; admin CRUD at `/manage/works`. Songs that are part of a work render the "Part of work" panel via the shared partial — don't roll your own grouping UI.
15. **External-links registry** (#833) → `tblExternalLinkTypes` + per-entity tables `tblSongExternalLinks` / `tblSongbookExternalLinks` / `tblCreditPersonExternalLinks` / `tblWorkExternalLinks`. Editors are card-lists that share validation + the URL → provider auto-detect helper. Any new entity that grows external links gets its own `tbl<Entity>ExternalLinks` table, not a generic FK column.
16. **Admin theme** → `manage/includes/admin-theme-init.php` (#955). Synchronous `<script>` partial loaded via `head-libs.php` (or directly from `editor/index.php`'s bespoke `<head>`). Reads `localStorage.ihymns_theme` + `localStorage.ihymns_cvd_mode` (same keys the public site writes) and sets `data-bs-theme` / `data-ihymns-theme` / `data-ihymns-contrast` / `data-ihymns-cvd` on `<html>` BEFORE any CSS loads. Live-listens for OS theme changes when the user is on `'system'`. Never hardcode `data-bs-theme="dark"` on a new admin page — that's the regression #953/#955 fixed.
17. **Song reads are LIVE MySQL — there is NO songs.json file cache** (DB-direct rewrite, epic #1010 / WS-A–WS-K, 2026-06). `includes/songs_cache.php`, `SongData::exportAsJson()`, the `/api?action=songs_json` endpoint and the editor `?action=load` corpus serve were all **removed in WS-J #1020**. Reads now go straight to MySQL and NOTHING materialises the whole corpus: `SongData::getSongsSlimIndex()` (the lightweight id/number/title/songbook index — served by `/api?action=songs_index` to the PWA), `SongData::getSongs($abbr)` (one songbook — served by the editor's `?action=songbook_export`), `SongData::getSongById()` (one full record — `?action=song_detail` / editor `load_song`). The ONLY fallback is the client PWA offline cache; a DB outage is a graceful 503, never stale data (WS-K #1021 `includes/maintenance.php` + the api.php `isDbConnectionFailure()` 503 path). Never reintroduce a whole-corpus materialiser or a server-side JSON corpus cache.
18. **Inline link styling** → public-facing prose links use `.song-meta-link` (#951 / #952) for the muted dotted-underline look. The global `<a>` default in `app.css` Section 2.5 already gives every naked `<a>` the same treatment; component classes (`.btn`, `.nav-link`, `.dropdown-item`, `.breadcrumb-item a`, etc.) keep their explicit styling via specificity. Never hand-roll `text-decoration: underline; color: blue;` for catalogue prose links.
19. **DB migrations** → every `appWeb/.sql/migrate-*.php` that creates a table or adds a column MUST also append the matching declaration to `appWeb/.sql/schema.sql` in the same commit. `schema.sql` is the canonical source of truth a fresh install reads from; if it drifts, fresh installs land structurally different from long-running installs and the Schema Audit page (#518) flags every divergence as "Orphan in DB". The migration also MUST get matching entries in `appWeb/public_html/manage/setup-database.php` — one each in `$scriptMap`, `$migrationOrder`, `$migrationCards`, and `$migrationProbes` (these are now DERIVED from the single `migration-registry.php` entry — append ONE entry there, not four). The probe MUST detect actual completion from the live schema/data (or sentinel row in `tblAppSettings`); never `static fn(\mysqli $db) => true` because that makes the "Apply all pending" counter impossible to drive to zero. A migration that creates SEVERAL objects uses a multi-object OR-probe (`!tableExists(A) || !tableExists(B) || !columnExists(...)`) so a partial apply never shows the card green. Multi-column ALTERs need a `@migration-adds tblX.Col` doctag PER column (the schema-coverage scanner only catches the first `ADD COLUMN` per ALTER otherwise). Every column/index/FK in the migration DDL must be **byte-identical** to its `schema.sql` mirror (incl. COMMENT text) so a fresh install equals a migrated one. CI guards both directions: `tests/php/test-schema-coverage.php` for the schema.sql sync, `tests/php/test-migration-registry.php` for the probe registry.
20. **One-pass forward-looking schema + VARCHAR-not-ENUM** → when a *feature family* needs new schema, design the FINAL DDL up front (design → adversarial "what would force a second migration?" stress → implement → verify) and ship it as additive, idempotent, dormant tables in ONE batch — do NOT dribble out incremental ALTERs as the feature is tweaked. The #1066 batch (`tblSongArrangements`, `tblLyricsConflicts`, `tblLyricsReviewQueue`, `tblApiKeyUsage`, `tblApiKeyIdempotency`, `tblSongIdentityMap`, `v_ChristianSongs` + columns on `tblSongComponents`/`tblApiKeys`/`tblSongs`/`tblSongLinkSuggestions`/`tblWorks`) and the #1088 batch (`tblLyricLineTranslations`, `tblLyricLineAnnotations`) are the worked examples. Load-bearing rules they encode: **any growable / moderation vocabulary is `VARCHAR` (app-validated against a central map), never `ENUM`** — an ENUM value-add is an `ALTER` = the second migration we forbid (`Status`/`Kind`/`AnnotationType`/`Signal`/conflict-queue-review vocab all follow this); reserve a discriminator/scope column in a UNIQUE key when multiplicity is foreseeable (`tblApiKeyUsage`'s `Scope`, `tblSongIdentityMap`'s non-unique `SongId`); a `(Source, SourceRef)` UNIQUE makes external re-import idempotent (multiple NULLs let manual rows coexist); idempotency TTLs use `DATETIME` not `TIMESTAMP`. The cross-DB iLyricsDB-keyed objects stay **gated** on the DB-merge decision (#1010 follow-on) — never ship a guessed bridge view.
21. **Per-line lyric enrichment anchors on `tblLyricLines.Id`** (#1088) → per-line **translations/romanizations** (`tblLyricLineTranslations`, modelling Apple Music TTML `<translation>`/`<transliteration>`) and Genius-style **annotations** (`tblLyricLineAnnotations`, span = `StartLineId` + nullable `EndLineId` + nullable UTF-8-code-point `StartOffset`/`EndOffset`) attach to the normalized `tblLyricLines` (BIGINT PK) read path — NOT the index-fragile `tblSongComponents.LinesJson`/`NotesJson`/`ChordsJson` parallel arrays (those are right only for presenter notes + chords). These are DISTINCT from `tblSongTranslations` (whole-song → separate song record). Language tags are free-text `VARCHAR(35)` (no FK to `tblLanguages`, mirroring `tblLyricLines.LanguageCode`) so TTML/LRC script subtags never RESTRICT-fail ingest; the slide/presenter render path reaches enrichment via `tblLyricLines.ComponentId`. Slice line text by code point (`mb_substr` / `Array.from`), never byte or UTF-16 index.

### Red flags during review

Reject any change that introduces:

- A duplicate `<nav>` on an admin page.
- A duplicate `<link rel="stylesheet" href="/css/app.css">` + `/css/admin.css` block when `admin-footer.php` or another shared include could host it.
- A hard-coded list of roles, entitlements, licence types, tier names, or card IDs that already exists in a central map.
- A PDO / mysqli instantiation outside `getDbMysqli()`. (PDO is no longer used at all — any `new PDO(...)` is a regression.)
- A `<script>` loading Bootstrap or Bootstrap-Icons on a page that also includes `admin-footer.php` (double-load).
- An inline click handler that re-implements behaviour the corresponding shared JS module already offers.
- A hand-rolled regex / `URL.hostname.endsWith(...)` ladder for "what kind of link is this" — `external-link-detect.js` already exists and reads its rules from `tblExternalLinkPatterns`.
- A new admin list page that doesn't opt into `.admin-table-responsive` + sortable headers (#842 / #844) when surrounding pages already do.
- A duplicate songbook/song/credit-person/work "external links" editor — reuse the shared chip-list module, don't fork it per entity.
- A new admin page that hardcodes `<html lang="en" data-bs-theme="dark">`. The theme is resolved at runtime by `admin-theme-init.php` (#955) reading the user's preference from `localStorage`. Hardcoding the attribute causes a flash of dark for users who picked Light / High-contrast / System with light OS.
- A read-side endpoint that materialises the WHOLE corpus (the old `SongData::exportAsJson()` / `songsCacheServe()` pattern — both removed in WS-J #1020). Reads MUST be scoped: `getSongsSlimIndex()` (lightweight index), `getSongs($abbr)` (one songbook), or `getSongById()` (one record). Reintroducing a whole-corpus load (~140 MB of PHP-array memory, #929 OOM) or a server-side `songs.json` cache is a regression.
- A prose link styled `text-decoration: underline; color: blue;` or via `<a style="color: var(--bs-link-color)">`. The global `<a>` default in `app.css` Section 2.5 (#951) handles muted styling site-wide; explicit colour-blue overrides revive the Bootstrap default we deliberately killed.
- A new `migrate-*.php` script that creates a table or adds a column but doesn't update `appWeb/.sql/schema.sql` in the same commit. CI's `test-schema-coverage.php` will fail; that's the signal — copy the CREATE TABLE / column declaration into schema.sql alongside the migration.
- A new entry in `$migrationOrder` (in `manage/setup-database.php`) without a matching probe in `$migrationProbes`, OR a probe written as `static fn(\mysqli $db) => true`. Both make the dashboard's pending counter stuck above zero. CI's `test-migration-registry.php` will fail; the probe must check the live schema / data (or a `tblAppSettings` sentinel the migration writes) so the card flips from pending → applied once the work has landed.
- An `ENUM` column for any vocabulary that could grow (moderation states, kinds, signals, types). Use `VARCHAR` + an app-level allow-list — an ENUM value-add is an `ALTER` (rule #20). Existing ENUMs (e.g. `tblLyrics.Status`) are grandfathered; new ones in the #1066/#1088 families are deliberately VARCHAR.
- Per-line translations or annotations stored as a JSON parallel array on `tblSongComponents`, or anchored on a `LinesJson` index. They belong in `tblLyricLineTranslations` / `tblLyricLineAnnotations` anchored on `tblLyricLines.Id` (rule #21). Slicing a line by byte or UTF-16 offset instead of code point is also a regression.
- An incremental `ALTER` that re-opens a schema family already shipped one-pass (#1066/#1088), when the column/table could have been included in the original forward-looking batch. Prefer extending the dormant table; only ALTER for genuinely unforeseeable needs.

When in doubt: extract first, use second. A 30-line partial is cheaper than debugging five divergent copies.

## 🗂 Project layout

```
appWeb/          — Web / PWA (PHP + vanilla JS modules + Bootstrap 5)
appApple/        — Apple: iOS / iPadOS / tvOS targets + shared Swift code
appAndroid/      — Android (incl. Amazon FireOS) + shared Kotlin code
data/            — Source song JSON + seed data
tests/           — Cross-platform test harnesses
tools/           — Build + data-prep scripts
.claude/         — This file + ProjectBrief / ProjectOverview / project-rules
```

## 🛠 Commit / PR expectations

- **One PR per piece of work, multiple commits inside it.** Group related work into a single PR with logical, well-scoped commits rather than splitting across several smaller PRs. One review session, one deploy to alpha, one verify pass. Each commit stays atomic and individually revertable (`git revert <sha>` works per-commit). Avoids the inter-PR race conditions and multi-deploy churn that bit the 2026-04-25 audit-cleanup work, where a chain of small PRs each triggered its own deploy + verify cycle and one mis-diagnosis cascaded through all of them. Multiple PRs only for genuinely independent pieces of work that happen to be in flight at the same time (e.g. unrelated bugfix + unrelated feature).
- Commits have descriptive first-line summaries; wrapped body explaining the WHY, not just the WHAT.
- Every user-reported bug or feature gets a tracking GitHub issue **before** the commit that closes it, so the timeline reads sensibly.
- PRs target `alpha`. Stacked PRs (PR-B depends on PR-A landing first) are an exception, reserved for genuinely sequential dependencies — most work should land as a single PR per the rule above. Note the base branch in the description.
- Never skip pre-commit hooks (`--no-verify`), never force-push main/alpha, never amend merged commits.
- Audit before opening a PR: PHP syntax (`find appWeb -name '*.php' -exec php -l {} \;`), JS syntax (`find appWeb -name '*.js' -exec node --check {} \;`), security + accessibility + structure per the pattern established on PR #445.

## 📎 Other references in this directory

- `.claude/ProjectBrief.md` — current project state, versions, phase, database schema summary.
- `.claude/ProjectOverview.md` — original multi-platform scoping.
- `.claude/project-rules.md` — this file's detailed expansion (naming, data-access layers, error handling, i18n, test discipline).

## 💾 Session continuity across devices

Raw session transcripts live at `~/.claude/projects/<project-hash>/*.jsonl` on whichever device Claude Code ran — they contain the full tool-call log (every file read, every command run, every response). The repo carries **scrubbed copies** in `.claude/sessions/`, synced via:

```
tools/sync-claude-session.sh
git diff .claude/sessions/    # REVIEW — scrubber is best-effort
git add .claude/sessions/ && git commit -m "chore(sessions): sync"
```

The scrubber redacts known token shapes (Anthropic, GitHub, AWS, Google, `Bearer`, private keys). It does **not** catch a password typed into a prompt or customer data in a test fixture. Always review the diff. See `.claude/sessions/README.md` for the full policy.

Per-user global memory (`~/.claude/CLAUDE.md`) stays on the user's machine — it's not project policy. If guidance from a session turns out to be permanent, copy it into `.claude/project-rules.md` here so future sessions pick it up automatically.

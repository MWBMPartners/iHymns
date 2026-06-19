# iHymns — Project Rules (detailed)

Expanded rules for contributors (human or AI). The short version lives in `.claude/CLAUDE.md`; this file is the long version, safe to link in code review comments when a rule needs citing.

## 1. Modularity (the master rule)

See `.claude/CLAUDE.md` for the full policy. Summary: don't duplicate — extract, then reuse.

### 1.1 Shared components — WEB / PWA

| Concern | Shared module | Consumer pattern |
|---|---|---|
| Admin top-nav (brand + theme + avatar + hamburger offcanvas) | `manage/includes/admin-nav.php` | `<?php require __DIR__ . '/includes/admin-nav.php'; ?>` with `$activePage` set |
| Admin footer (copyright / version / Terms / Privacy + Bootstrap bundle JS) | `manage/includes/admin-footer.php` | Include once, immediately before `</body>` |
| Favicon + app icons | `manage/includes/head-favicon.php` | Include in `<head>` |
| Session / auth bootstrap | `manage/includes/auth.php` | `require_once` first, then call `isAuthenticated()` / `requireAuth()` / `requireAdmin()` |
| DB handle (single connection across admin + main app) | `includes/db_mysql.php::getDbMysqli()` | Never `new mysqli(...)` or `new PDO(...)` directly. PDO has been fully removed (#554 / #555). |
| Entitlement check | `includes/entitlements.php::userHasEntitlement()` | Never check role strings directly for authorisation — always through this |
| Entitlement labels | `$ENTITLEMENT_LABELS` in `manage/entitlements.php` | Extend the map; never hand-craft a string at render time |
| Licence type labels | `$LICENCE_TYPES` in `manage/organisations.php` (migrating to `tblLicenceTypes`, #459) | Consumers iterate the map; never hard-code licence keys |
| Card-layout reorder/hide (server) | `includes/card_layout.php` | `cardLayoutResolve($baseline, $surface, $user)` to render order; `cardLayoutSave*` to persist |
| Card-layout reorder/hide (client) | `js/modules/card-layout.js` | `initCardLayout(gridEl)` — grid must carry `data-layout-surface`, cards `data-card-id` |
| Offline download UI | `js/modules/offline-ui.js` | Cards use `data-songbook-download` / `data-song-download`; feature detection handled centrally |
| Content access evaluation | `includes/content_access.php::checkContentAccess()` | API + page gates use this; never query `tblContentRestrictions` directly |
| SPA router | `js/modules/router.js` | New routes register via `parseRoute()`; after-load hooks go in `afterPageLoad()` |
| Main-site home / song / songbook templates | `includes/pages/*.php` | Rendered by `api.php` via `?page=...` |

### 1.2 Shared components — APPLE

- Cross-target Swift code in a `Shared` package imported by iOS / iPadOS / tvOS targets.
- Design tokens + colours match the web CSS variables (`--accent-*`, `--surface-*`, `--text-*`). Keep the palette in one shared `Theme.swift`.
- Network layer talks to the same `/api?...` endpoints the web uses. No separate schema.

### 1.3 Shared components — ANDROID + FireOS

- Kotlin Multiplatform where feasible; shared Gradle modules otherwise.
- FireOS is a variant of the Android target — differs only in launcher icon, store metadata, and any device-specific capability checks. No parallel implementation.

## 2. Naming conventions

- **Database:** `tblCamelCase` tables, `CamelCase` columns, `utf8mb4_unicode_ci` collation (case-insensitive uniqueness on usernames + slugs).
- **PHP:** `snake_case` for functions + local vars; `PascalCase` for classes; `UPPER_SNAKE` for constants. Match existing code in the file you're editing.
- **JS:** `camelCase` for functions + variables; `PascalCase` for classes; `UPPER_SNAKE` for module-level constants.
- **CSS custom properties:** `--accent-*`, `--surface-*`, `--text-*`, `--card-*`, `--footer-*`, `--header-*` — see `css/app.css:1`.
- **URLs:**
  - Main app uses clean, hyphenated paths (`/songbook/CP`, `/song/CP-0001`). The `.htaccess` rewrites to `index.php`, then the SPA router parses.
  - `/manage/*` uses clean URLs too — every `<name>` resolves to `<name>.php` via the generic rule in `manage/.htaccess`. No per-page rewrite lines (#443).
- **Entitlement keys:** `snake_case`, verb-forward (`edit_songs`, `manage_user_groups`). Always accompanied by a human label in `$ENTITLEMENT_LABELS`.
- **Card IDs** (for reorder surfaces): lowercase, alnum + hyphen, ≤ 64 chars, validated by `cardLayoutSanitiseIds()`.

## 3. Auth / security

1. **Every page** gates via `isAuthenticated()` → `userHasEntitlement()` pair, OR the `requireAuth()` / `requireAdmin()` / `requireGlobalAdmin()` helpers. No role-string comparisons in business logic.
2. **Every POST form** includes a `csrf_token` hidden input; every POST handler calls `validateCsrf()` before dispatch.
3. **Every SQL statement** uses prepared statements with placeholders. No string concatenation of user-supplied data into SQL, ever.
4. **Every echoed variable** uses `htmlspecialchars()` (with `ENT_QUOTES` when inside attribute context). JSON embedded in attributes uses `JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP`.
5. **Tokens (API, password-reset, magic-link)** stored hashed at rest, compared via constant-time hash check.
6. **Usernames** stored case-preserved (users pick the case shown); uniqueness + login lookups rely on the DB's case-insensitive collation — never normalise to lower-case on insert.
7. **Session cookies** are `HttpOnly`, `Secure`, `SameSite=Lax`. The session name is `ihymns_manage_session` for the admin panel.

## 4. Error handling

- **System boundaries only.** Internal code trusts internal code; no defensive `isset()` on values you just assigned.
- **User input** gets validated at the boundary (form handler, API action). Once validated, downstream code uses it directly.
- **DB errors** are caught at the top of a page (see `manage/data-health.php` for the pattern) so the surrounding layout still renders with a visible error banner rather than a blank page.
- **Client errors** surface via `console.error()` + a visible alert. No silent `.catch(() => {})` for anything that affects UX.

## 5. Accessibility / W3C compliance

- Every `<input>` has a matching `<label>` or `aria-label`.
- Every icon-only button has `aria-label` + `title`; the `<i>` inside gets `aria-hidden="true"`.
- Every modal has `aria-labelledby` pointing at its `.modal-title`'s `id`.
- Every table uses proper `<thead>` / `<tbody>`.
- Keyboard navigation tested on every interactive surface (Tab reaches controls, Enter/Space activates).
- No duplicate IDs across a rendered page — especially watch for IDs inside loops; suffix with the row key.
- Colour is never the only signal of state; always pair with an icon or text change.

## 6. Performance

- **ETag + short `Cache-Control`** on idempotent API page fragments (`api.php` page branch). User-specific pages skip the cache path.
- **N+1 DB queries are a bug.** Every loop that calls `getSongById()` / `getUserById()` etc. per-row must be refactored to preload via a single query + in-memory map.
- **Service worker** caches CSS / JS / HTML page fragments; bumped cache version on deploy so changes take effect.
- **No render-blocking synchronous `<script>` tags** in the main-site head — defer or `type="module"`.

## 7. Test discipline

- `npm test` runs the song-parser harness at minimum.
- `npm run test:php` + `npm run test:js` sweep syntax across the tree.
- Manual test plan lives in every PR description — explicit checklist, each item a smoke test.

## 8. GitHub workflow

- Issue BEFORE commit when possible: `feat(x): … (#NNN)`. Every PR lists the issues it closes.
- Retrospective issues for work that shipped without one are OK — see #438-442 as precedent.
- Every PR description explains WHY the change exists (not just WHAT) and carries a Test Plan checklist.
- **Never open a PR unless the user explicitly asks for one.** Commit + push to the working branch and stop. If the previous PR from that branch has merged, further commits on the same branch do NOT land anywhere until a new PR opens — flag that to the user and wait for them to decide, don't auto-open a follow-up PR.

## 9. What NOT to do (recent anti-patterns to avoid)

- **Don't invent SRI hashes.** A wrong `integrity="…"` silently blocks the script. Either compute the real hash or omit the attribute.
- **Don't render `d-none` on controls and rely on JS to reveal.** The JS may not run on first paint. Render visible; hide via a body-class feature-flag.
- **Don't put Bootstrap `<script>` tags on individual pages.** The shared footer owns JS inclusion for `/manage/*`.
- **Don't inline a navbar on a `/manage/*` page** when `admin-nav.php` is right there.
- **Don't expose backend keys to end users.** `ihymns_pro` is a DB value, not a label; surface the label via the central map.
- **Don't scatter auth checks.** One helper call; never `$u['role'] === 'admin'` in business logic.
- **Don't commit stacked PRs that re-implement work already in a parallel branch.** Rebase and reuse.
- **Don't write literal `<?=` / `<?php` / `<?` inside HTML comments or backticks in `.php` files.** PHP doesn't respect HTML-comment boundaries — it parses every `<?` open-tag it finds, regardless of surrounding `<!-- ... -->`. On PHP 8.1+, `func(...)` is first-class callable syntax, so a comment that says `<?= json_encode(...) ?>` evaluates `json_encode(...)` (returns a Closure), then `<?=` tries to echo it → runtime fatal `Object of class Closure could not be converted to string` → output halts mid-stream → browser receives a truncated HTML response → the SPA shell renders but `app.js` is never reached and the loading spinner hangs forever. This took down alpha in PR #536 (commit `96cd14a`). If you need to reference PHP code in a comment, omit the open tag (`echo json_encode() call` not `<?= json_encode() ?>`). CI now greps for this pattern in `.github/workflows/test.yml`.
- **Don't assume `$db->query()` / `$db->prepare()` return `false` on error.** `getDbMysqli()` sets `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)`, so a failing statement **throws `mysqli_sql_exception`** — `if ($res = $db->query(...))` is dead code, and an uncaught throw discards the output buffer and white-screens the page. For reads touching a table/column that may be **unmigrated on a given environment**, gate them with an INFORMATION_SCHEMA existence probe, and wrap page-load detection in `try/catch` that degrades to a themed error card pointing at `/manage/setup-database`. This blanked `/manage/duplicate-songs` when only the newer `tblSongLinkSuggestionsDismissed` table was unmigrated (#1228 → fixed #1229; `$suggTableExists` was even probing the *wrong* table). **Migrations are not auto-applied on deploy**, so "the column is in `schema.sql`" does not mean it exists on alpha — code that queries a freshly-added object must tolerate its absence.

## 10. Conventions established in the 2026-04 batch

These are project conventions worth knowing when touching adjacent code, established across #670 / #672 / #673 / #676 / #681 / #687 / #695 / #699:

- **Songbook auto-colour (#677).** The `Colour` field on `tblSongbooks` is nullable. Leaving it empty in the editor lets the system pick a palette tone from the active theme — the result is consistent across the catalogue and changes with the user's chosen theme. Hard-coded hex still wins. The picker logic lives in `manage/includes/songbook-palette.php::pickAutoSongbookColour()`. Don't auto-fill `Colour` server-side on import — keep it nullable so the auto-pick path runs on every render.

- **IETF BCP 47 composite language picker (#681 / #687).** Both `/manage/songbooks` (songbook-level) and `/manage/editor` (per-song) consume one shared partial + module: `manage/includes/partials/ietf-language-picker.php` + `js/modules/ietf-language-picker.js`. Three sub-fields (Language / Script / Region) compose into a single saved tag. Vocabulary is sourced live from `tblScripts` (ISO 15924) + `tblRegions` (ISO 3166-1 alpha-2 + M.49 numeric area codes), seeded by migration card 3o. Public clients (Apple / Android / FireOS) hit the equivalent listings at `/api?action=scripts` and `/api?action=regions`. Server-side validation on every save path (`validateBcp47()` in `includes/bcp47.php`).

- **INSERT-only bulk-import contract (#664 / #676).** `/manage/editor/api?action=bulk_import_zip` never overwrites existing songbook or song rows. Pre-flight existence check; mismatches reported in the three-counter response shape `{songs_created, songs_skipped_existing, songs_failed}`. Decompression-bomb caps live in `_BULK_IMPORT_MAX_ENTRY_UNCOMPRESSED` (5 MiB) + `_BULK_IMPORT_MAX_TOTAL_UNCOMPRESSED` (500 MiB) plus the `_BULK_IMPORT_MAX_ENTRIES = 100,000` entry-count cap. Async path uses `tblBulkImportJobs` + the `bulk_import_status` polling endpoint + the persistent progress widget.

- **Cross-source integrity check workflow (#699 Phase C).** When two sources cover the same songbook (e.g. ChristInSong.app + sdahymnal.org for SDAH/CH/HASD/HA), use `SDAHymnals_SDAHymnal.org.py --prefer-source {sidebar|sdah|cis}` to compare. `cis` mode is read-only audit and writes the report to `_integrity-check.md` in the songbook folder. Audit reports — committable summaries — live under `.importers/audits/`. Per-songbook detail files (`.SourceSongData/<book>/_integrity-check.md`) are gitignored.

- **PR target = `alpha`, default branch = `main` → GitHub auto-close does NOT fire.** PRs that close issues with `closes #N` syntax merge into `alpha`, but GitHub only auto-closes issues when the PR merges into the repo's default branch (`main`). Open issues that should be closed pile up. Sweep periodically: `git log alpha` for `closes #N` references, cross-reference against `gh issue list --state open`, close in batch with a comment crediting the merge commit. (Done as part of the #682 hygiene tail; ~50 issues swept on 2026-04-30.)

- **Activity Log error capture (#695).** Admin save handlers that catch `\Throwable` and surface a generic banner ALSO call `logActivityError()` so the failure lands in `tblActivityLog` with `Result='error'`. The viewer at `/manage/activity-log` filters by Result. `error_log()` calls remain as belt-and-braces. Use a stable `admin.<surface>.<action>` verb (`admin.songbooks.save`, `admin.requests.update`, `admin.ccli_report.load`, etc.) so the Action filter narrows usefully. Sensitive fields (passwords / tokens) MUST stay out of the Details JSON — see §11 below. **When the page is admin-gated, surface the actual exception detail (class + message + file:line) inline instead of "see server logs"** — saves the curator from needing SSH access just to read why a save failed. Pages that already do this: songbooks, requests, organisations, groups, missing-numbers, credit-people, revisions, ccli-report, restrictions, tiers. Pages that don't yet: tracked under the rolling sweep at #713.

- **New migration → register in THREE places (#708).** The `$scriptMap` in `manage/setup-database.php` maps action keys to file names; `$migrationOrder` is what the "Apply all pending migrations" button walks; per-card UI definitions render the targeted single-step buttons. A new `migrate-*.php` script must be added to all three or it silently drops out of the bulk run (which is exactly what bit #708 — the bulk action shipped in #577 but the order list froze, so 10 subsequent migrations weren't being picked up). Each script must remain idempotent (every migration starts with an INFORMATION_SCHEMA / SHOW TABLES probe so re-runs are no-ops).

- **Public form no-JS fallback (#711).** The `/request` page (and any public form that POSTs to an API endpoint) has BOTH:
  - an `action="…" method="POST"` attribute on the `<form>` so a default-submit reaches the API even when the inline `<script type="module">` fails to load;
  - a server-rendered success/error banner driven off `?submitted=1&id=N` / `?error=…` query strings so the user gets feedback on the no-JS path;
  - the API endpoint detects `Content-Type=application/x-www-form-urlencoded` and 302-redirects in that case (vs. returning JSON for the JS path).

  And note: SQL string literals always use single quotes (`'pending'`, not `"pending"`). MySQL's default mode treats both as strings, but `sql_mode='ANSI_QUOTES'` parses double-quoted as a column reference — and the song-request endpoint had exactly that bug for a while (#711).

- **Shared colour-picker partial (#715).** Hex-colour text inputs on `/manage/*` go through `manage/includes/partials/colour-picker.php` + the `js/modules/colour-picker.js` boot. The partial emits a native `<input type="color">` swatch alongside the hex text input, two-way bound. Empty text → swatch shows a neutral seed but the saved value stays empty so the auto-pick path (#677) runs at render. New consumers should pull this partial rather than rolling their own.

- **Misc-pinned-bottom + non-official alphabetical (#717 / #718).** Two related sort rules:
  - `/manage/songbooks` quick-sort presets pin the Misc songbook (`Abbreviation = 'Misc'`) to the bottom regardless of name/abbr direction. Implementation: short-circuit in the `sortByKey` JS helper before the regular compare.
  - Songs within a songbook sort by Number when the songbook is `IsOfficial = 1` AND has at least one numbered hymn; else sort alphabetically by Title. Hybrid books (mostly numbered with an un-numbered supplement) put numbered first, alphabetical tail second. Implemented via a CASE branch in `SongData::getSongs()`'s ORDER BY.

- **Database Setup bulk-run (#708 / #720).** The "Apply all pending migrations" button walks `$migrationOrder` (separate list from `$scriptMap`). When adding a new `migrate-*.php` register it in BOTH places, in deployment order, AND add the per-card UI block. The bulk-run handler:
  - Captures the first-failing-step's metadata so the page renders a prominent banner ABOVE the (potentially long, scrollable) output panel — operators can spot the failure without scrolling.
  - Has a `register_shutdown_function` that catches PHP fatals (E_ERROR / E_PARSE / etc.) which bypass try/catch, so even a fatal mid-bulk gets surfaced before the page renders.

- **Activity Log local-time + UA wrapping (#721 / #723).** The `/manage/activity-log` listing renders the When column in the user's local timezone via inline `Intl.DateTimeFormat`, falling back to UTC if Intl is unavailable. UA strings render in full (no server-side substr cap) with `.activity-ua { word-break: break-word; overflow-wrap: anywhere }` so long UAs wrap rather than truncate.

## 12. Conventions established in the 2026-05 batch (#840–#852)

These are the patterns and gotchas added during the catalogue-refresh batch. Read them before touching adjacent code.

- **Works composition grouping (#840).** `tblWorks` carries an optional self-FK (`ParentWorkId`) so a "Messiah" can have nested "Part 1 / Part 2" works without a separate join table; `tblWorkSongs` joins to `tblSongs`; `tblWorkExternalLinks` is per-work. The `AppliesTo` SET on `tblExternalLinkTypes` was widened from `'song,songbook,creditperson'` to also include `'work'` — when adding a new entity-type that wants its own external links, widen this SET, add the per-entity table, AND make sure `external-link-detect.js` doesn't need any change (the module is entity-agnostic by design).

- **DB-driven URL → provider detection (#841 / #845).** The decision tree for "URL X belongs to provider Y" used to live in a hand-rolled regex ladder embedded in three different editor pages. It's now: (a) `tblExternalLinkPatterns` keyed to `tblExternalLinkTypes`, (b) curator-editable at `/manage/external-link-types`, (c) consumed by `js/modules/external-link-detect.js` which prefers `window._iHymnsLinkTypes[].patterns` from the server, falling back to its bundled `RULES` when those haven't been seeded yet. Migration card 7a populates the patterns table from the bundled `RULES` so existing deployments get the same matches without code change. **Don't add a new provider regex inline — add a row to `tblExternalLinkPatterns` (or seed it via migration) and the module picks it up.**

- **Responsive admin lists + sortable headers (#842 / #844).** Opt-in is two attributes: `<table class="admin-table-responsive">` and `data-col-priority` on each `<th>`/`<td>`. Sortable headers are a `<th data-sort-key="…">` + the shared `js/modules/admin-table-sort.js`. Pages that opt in: Credit People, Songbooks, Songbook Series, Works, plus six others tracked in #844. New admin lists should opt into both — the styles + module are zero-cost when the table has < 10 rows.

- **Bulk-promote curator workflow (#846).** Promoting in-use Credit People into the register uses Levenshtein distance + token-set Jaccard similarity (cap 0.85) so "J. Smith" and "John Smith" cluster automatically; the curator confirms each cluster, the submit is a single transaction tagged with a shared `bulk_run_id` so the whole run can be reverted from the activity log if a duplicate slips through.

- **CI/auto-merge resilience (#848 / #850 / #852).** Three lessons from the catalogue-refresh batch:
  1. **Migration cards must render even on a no-action visit (#848).** The bug shipped in #847: `/manage/setup-database.php` had a `goto` that skipped the per-card render block when no `?action=…` was supplied, so a fresh visit showed only the bulk-run output panel. Fix: render the card grid unconditionally; the action handler runs above it and writes to a `$bulkOutput` buffer that the cards read.
  2. **The CI guard that scans for unbalanced `<?php` tags must not trip on its own block-comment (#849).** When adding a guard like `grep -n '<?php' …`, escape the marker in the comment that explains what it does (we use `&lt;?php` in the surrounding markdown / a non-marker substitution in the heredoc) — otherwise the guard's own source matches its own pattern.
  3. **Auto-Merge Alpha PRs workflow tolerates `gh pr merge --auto` non-zero exits (#850), and `Lint & Validate` runs on every PR with no path filter (#852).** The deadlock symptom: a workflow-only / docs-only PR has no required-check matches under the path filter, so `gh pr merge --auto` queues a merge that never fires; once we noticed, the fix was to drop the `paths:` filter on the `pull_request` trigger so every PR gets the lint job, and to make the auto-merge step swallow the "PR is fast-mergeable, no auto-merge needed" non-zero exit. Lesson: **path-filtered required checks + auto-merge is a footgun**; if a check is required for protection, it must run on every PR or the gate becomes vacuous on the filtered paths.

- **`tblExternalLinkPatterns` migration deploy gate.** When patterns are pulled from the DB but the migration hasn't run yet, `js/modules/external-link-detect.js` falls back to its bundled `RULES` so editors keep working. Don't remove the fallback — it's the only thing keeping the editor functional during the rolling deploy of #845 across alpha → main.

- **PR target = `alpha`, batch as one PR.** Re-emphasised by the #847 → #848 → #849 cascade: when a single feature ships in a chain of micro-PRs, each one triggers a deploy and verify cycle, and a mis-diagnosis cascades. The 2026-05 batch followed §10's one-PR rule for the main feature work (#840–#846 all in one PR per the global rule) and only span out separate PRs for the genuinely independent CI/auto-merge fixes that emerged afterwards.

## 13. Bulk-import + long-running operation surfaces

Established 2026-05-08/09 across #906 / #907 / #908. Every long-running operation that writes to multiple rows (bulk import, mass re-tag, batch language assignment) should follow these patterns.

### 13.1 Surface skipped / failed counts per-entity, not just aggregate

A "1,137 skipped" toast told a curator nothing — they had to query `tblSongs` directly to figure out the 1,137 was an exact match for HA + HASD content already in the DB. Always carry a per-entity breakdown alongside the aggregate, so the toast / status response can show "HA: 0 created, 527 skipped, 0 failed" rows. Captured in `tblBulkImportJobs.PerSongbookJson` (#906) and the `per_songbook` field of the status response. Apply the same shape to any future bulk operation: per-entity counters keyed by the entity's natural unit (songbook, songbook + language, user-batch, etc.).

### 13.2 Don't lie about side effects — toast must reflect reality

Long-running operations often have a UI affordance ("Imported X" / "Synced X" / "Sent X email") that fires on a 200 response. **The 200 must reflect the actual side effect, not the local code path's intent.** Anti-pattern: an endpoint that generates a token, persists it, and returns 200 + "code sent" while the actual `sendEmail()` is an `error_log()` TODO. The toast lies; the user thinks the operation succeeded; downstream paths break silently. Same applies to bulk-import: if 4,458 entries were attempted and only 3,321 created, the toast must say so — not just "imported successfully". See `api.php:1631 / 1751 / 7464` for the canonical anti-pattern (#898) and #909/#911 for how bulk-import's summary now carries `created/skipped/failed` separately.

### 13.3 Activity-log every per-entity failure, with a sane cap + overflow row

When a bulk operation produces N failures, **don't** lump them into a single `tblActivityLog` row that summarises "X failed". Write one structured `Details` row per failure (with a per-request cap to stay under `IHYMNS_LOG_PER_REQUEST_CAP`), plus an `entries_truncated` overflow row when the cap is hit. The full failure list still lives in the operation's job-table column; the per-failure rows make the activity-log viewer's filter/search useful. Reference: `_perBookBump()` in `_bulkImport_processZip()` after #908. Use `EntityId='<jobId>:<entry>'` so the viewer can filter to "all failures from job N" via `EntityId LIKE '<jobId>:%'`.

### 13.4 Long-running UI must show *something* within 200ms

A blank screen or "0%" indicator for any duration > 200ms is unacceptable. Three layers required:

- **Upload phase**: use `XMLHttpRequest` (not `fetch`, which has no upload-progress event in any production browser as of 2026) and wire `xhr.upload.onprogress` to a byte-level UI before any server response is even parsed.
- **First server-state response**: fire the polling fetch IMMEDIATELY (not on the polling timer) so worker state appears within ~100ms of upload return.
- **Worker silence on preflight**: persist a `PhaseLabel` (or equivalent enum) per major worker transition so the UI can render "Walking ZIP archive…" / "Parsing songs…" / "Finalising…" above the percentage even while `ProcessedEntries` is still 0. See `tblBulkImportJobs.PhaseLabel` (#907) and `_bulkImport_processZip()`'s phase transitions.

### 13.5 Long-running UI doesn't overlap general toasts

Bootstrap's toast container lives bottom-right by default. Long-running indicators (bulk import, mass operations) should claim a different corner — typically **top-right** — so general-purpose toasts at bottom-right stay readable when an import is in flight. No z-index gymnastics, no shared real estate.

### 13.6 Long-running UI must auto-dismiss + always be dismissable

Two failure modes the curator hits:

- **Stuck completion toast**: import finishes; toast sits forever; curator has to refresh the page to clear it. Fix: auto-dismiss after a reasonable window (we use 15s on success / 45s on failure to give time to read), with `mouseenter` pausing the timer and `mouseleave` rescheduling from the full duration.
- **Mid-run with no escape**: import is running; curator wants to hide the indicator (it's blocking other UI); no × button is shown until completion. Fix: × button **always visible**. Mid-run dismiss closes the visible widget but leaves the operation running on the server — the curator can verify completion via `/manage/activity-log` later.

Reference implementation: `bulk-import-progress.js` after #911.

## 11. Activity logging — what NEVER goes in `tblActivityLog.Details` (#535)

Every meaningful action writes a row to `tblActivityLog` via
`includes/activity_log.php::logActivity()`. The `Details` JSON column
is free-form, which makes it tempting to dump request bodies wholesale.
**Don't.**

**NEVER log:**
- Password hashes (bcrypt/argon2 strings)
- Plaintext passwords in any form, even temporarily
- Bearer tokens, magic-link tokens, password-reset tokens, CSRF tokens
- Email subject lines or bodies for magic-link emails (log only `sent: true|false`)
- Plaintext personal details that aren't already in the entity's row

**OK to log:**
- User ID + username (already on `tblUsers`)
- Email address on auth events (already on `tblUsers`)
- IP address + truncated User-Agent (already columns on `tblActivityLog`)
- For edits: the list of fields that changed + before/after values for those fields specifically
- Error messages and class names for `Result='error'` rows — these aid debugging and don't leak user data

When in doubt, log the field NAME but not the field VALUE. A row that
says `{ "fields": ["PasswordHash"] }` is fine; one that includes the
hash itself is a bug.

## 14. Conventions established post-#852 (HTTP / parser / browser-state)

Durable patterns extracted from the diagnostic / scraper / auth-audit work after the catalogue-refresh batch. Read these before similar work — each rule has been paid for in real debugging time.

### 14.1 Triage durable HTTP blocks via browser → curl → script (NOT VPN/UA swaps)

When a scraper, fetcher, or any HTTP-driven script reports a block / rate-limit / wall that **survives across multiple sessions, networks, VPN endpoints, and User-Agents**, do NOT keep iterating on the network/UA dimension. Run a three-tier triage:

1. Does the URL load in a normal browser on the same network? If no, it's truly a server-side block; VPN/UA swap may help.
2. Does `curl` with realistic headers fetch the same content? If no, the block is at the HTTP-client level (TLS fingerprint, header order, ALPN); upgrade the client.
3. Does the script's parser produce the right shape from that same byte stream? If no, **the bug is in our code**, not theirs.

Only after steps 1–3 rule everything out, suspect a server-side bug.

**Apply this whenever a "rate limit" survives more than ~3 attempted variations on the network/UA axis.** Real precedent: HA hymn 331 wall, 2026-05-07. Multiple sessions worth of VPN swaps + residential UA pools were spent on what turned out to be a parser bug (Section 14.2). `curl` with a Safari UA on the same machine returned full hymn HTML in 1.6s every time.

### 14.2 Custom HTML parsers must keep void elements depth-neutral

When writing or maintaining a depth-tracking HTML parser (subclass of `html.parser.HTMLParser` in Python or equivalent in any language), HTML void elements (`<br>`, `<hr>`, `<img>`, `<input>`, `<meta>`, `<link>`, `<area>`, `<base>`, `<col>`, `<embed>`, `<param>`, `<source>`, `<track>`, `<wbr>`) must NOT contribute to the depth counter on either `handle_starttag` or `handle_endtag`.

Reason: `<br>` (no closing tag) fires only `handle_starttag`, while `<br />` (XHTML self-closing) fires both via `handle_startendtag`'s default. Treating either consistently — both increment + decrement, OR both skipped — keeps depth balanced.

The bug shape is: title parses but no sections / children, page looks empty, structural rate-limit fallback misfires. That's the signature.

Reference implementation: `.importers/scrapers/SDAHymnals_SDAHymnal.org.py` after PR #894. Don't repeat the depth-mistake pattern in any new parser.

### 14.3 Per-origin browser state vs cross-origin cookies — known confusion

iHymns runs at `dev.ihymns.app` (alpha), `beta.ihymns.app` (beta), and `www.ihymns.app` (main) sharing one MySQL DB. The shape of cross-subdomain state-sharing is asymmetric and easy to misdiagnose:

- **Server-side auth via the `ihymns_auth` cookie** is `Domain=.ihymns.app`-scoped (set by `setAuthTokenCookie()` / `_authCookieOpts()` in `api.php`), so a server-side render or API call on any subdomain reads it. Cross-subdomain auth IS designed to work.
- **Client-side bearer token in localStorage** is per-origin by W3C spec — each subdomain has its own. The frontend reads `localStorage.getItem('ihymns_auth_token')` and attaches it as `Authorization: Bearer …`. If main's localStorage is empty, the JS sets no Authorization header, but the browser still sends the `ihymns_auth` cookie automatically (default `credentials: 'same-origin'`), so the server still authenticates via the cookie fallback in `getAuthBearerToken()`.
- **Cross-subdomain settings sync** (theme, font size, default songbook, etc.) goes via the `ihymns_sync` cookie at `.ihymns.app` scope — see `js/modules/subdomain-sync.js`. This is the ONLY lightweight-settings sharing mechanism; large data (setlists, favourites) is excluded by design.
- **Service-worker caches** are per-origin too. A stale `?action=user_setlists` GET response on main can persist after the alpha-side sync wrote the new row to the shared DB. **Per-origin SW cache is the most common cause of "alpha data not appearing on main" symptoms** when the underlying DB row is verified-present.
- **localStorage data** (setlists, favourites, history) is per-origin; for **signed-in** users it is now a mirror of DB-first auto-sync (see Section 15), not the source of truth. For anonymous users it remains local-only.

> **Update (2026-06, epic #1010):** The structural root cause behind most "subdomain X has the data but Y doesn't" reports was that **song reads served a per-environment `songs.json` file cache, never live MySQL** — so a write on alpha was invisible on main until that env's file regenerated. The DB-direct rewrite (Section 15) removed that cache entirely; song reads are now live on every subdomain. The diagnostic below still applies to *signed-in user data* (setlists/favourites) where a stale per-origin SW GET response can briefly lag the shared DB.

**Diagnostic sequence when "subdomain X has the data but Y doesn't":**

1. Confirm the data actually exists in the shared DB via `/manage/setup-database`'s tables panel or a direct SQL query.
2. On Y, DevTools → Application → check `ihymns_auth` cookie (Domain should be `.ihymns.app`, not the subdomain).
3. On Y, run `fetch('/api?action=user_setlists').then(r=>r.json())` in the console — if it returns the data fresh, the SW cache is the culprit; unregister via DevTools → Application → Service Workers → Unregister, reload.
4. Only after steps 1–3 rule everything out, suspect a server-side bug.

## 15. DB-direct data layer (epic #1010 / WS-A–WS-K, 2026-06)

The defining architecture rule post-rewrite: **every runtime read hits live MySQL; nothing materialises the whole corpus; a DB outage is a graceful 503, never stale data.**

### 15.1 Reads are scoped — never whole-corpus

`SongData::exportAsJson()`, `includes/songs_cache.php` / `songsCacheServe()`, the `?action=songs_json` endpoint and the editor `?action=load` corpus serve were **all removed in WS-J #1020**. Use the scoped readers instead:

- `SongData::getSongsSlimIndex()` — lightweight id/number/title/songbook index (served by `/api?action=songs_index` to the PWA for offline + search).
- `SongData::getSongs($abbr)` — one songbook (served by the editor's `?action=songbook_export`).
- `SongData::getSongById()` — one full record (`?action=song_detail`, editor `load_song`).

`SongData`'s constructor **throws** if there is no DB connection — there is no JSON fallback to silently fall back to. Reintroducing a whole-corpus loader (~140 MB PHP-array memory, the #929 OOM) or a server-side `songs.json` cache is a regression CLAUDE.md red-flags.

### 15.2 Signed-in user data is DB-first auto-sync

Setlists, favourites (+ their `Tags`), custom tags, and view-history sync to MySQL on every edit (WS-F/G, #1011/#1012):

- **Authoritative-replace per edit** — the client sends its full current set; the server replaces and deletes-absent so deletions propagate (last-writer-wins).
- **First-login MERGE backfill** — on a new device the client sends `mode=merge`; the server unions (no loss) and, for favourites, server-side `array_unique(array_merge(serverTags, incoming))` so cross-device tags survive.
- The client `_syncReady` gate arms the destructive replace **only after** the merge has hydrated the local cache, so a fresh device can't clobber the server with its empty set.
- localStorage is the **offline mirror**, not the source of truth. Anonymous users stay local-only.

### 15.3 Maintenance mode + DB-down = themed 503 (never stale)

`includes/maintenance.php` gates `index.php` + `api.php`. The `/manage/*` entry point, `app_status`, and `auth_*` are **structurally exempt** so an admin can never lock themselves out. `isDbConnectionFailure()` (in `db_mysql.php`) converts an unreachable DB into the same themed 503. The service worker caches only 2xx, so a 503 page is never cached → clean recovery once the DB/maintenance flag clears. Error surfaces render through `includes/error_page.php` (theme-aware, PWA-offline-capable).

## 16. Deploy + migration include-path gotchas (2026-06, lyrics-mirror saga)

### 16.1 A `.sql/` migration requiring a NEW `public_html/includes/` file MUST resolve it `DOCUMENT_ROOT`-first

`appWeb/.sql/migrate-*.php` scripts compute paths to `public_html/includes/` helpers. The intuitive `dirname(__DIR__) . '/public_html/includes/foo.php'` (treating `.sql/` and `public_html/` as siblings) **works for long-lived files like `db_mysql.php` but silently 500s on a brand-new include** — on the server the SERVED web docroot is a *different tree* from that sibling path, and the sibling carries old files but not the newest ones. This cost hours on `migrate-lyric-lines-mirror.php` (`Failed opening required …/public_html/includes/lyric_lines_sync.php`, even though the deploy DID upload it).

**Pattern (use this for any migration that requires a new public_html include):** try the live docroot first, sibling as the CLI fallback, error clearly if neither exists:

```php
$cands = [];
$dr = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($dr !== '') { $cands[] = $dr . '/includes/foo.php'; }
$cands[] = dirname(__DIR__) . '/public_html/includes/foo.php';
foreach ($cands as $c) { if (is_file($c)) { require_once $c; $found = true; break; } }
```

(`db_mysql.php`-only migrations can keep the simple sibling path — it happens to be present in the stale tree. The hazard is specifically NEW includes.)

### 16.2 The SFTP deploy content-compares; new files no longer silently strand

`deploy.yml`'s normal path used `--only-newer`, which — because `actions/checkout` stamps every file with the checkout mtime — **silently skipped files** whose remote copy had a later (prior-deploy) mtime. It stranded `lyric_lines_sync.php` (and #919/#920 before it). Now the normal path **content-compares** (size+content); `[deploy all]` in the commit/PR-title still forces a full sweep; the deploy job has a `timeout-minutes: 20` cap + `~/.lftprc` net timeouts so a stalled mirror can't hang the 6h Actions ceiling (it did, twice). When you add a NEW file a deploy must pick up, a `[deploy all]` PR title is the belt-and-braces guarantee.

## 17. Model-tier selection — match the model to the task complexity

**Standing rule:** when delegating to subagents (the Agent tool) or to a workflow stage, **match the model tier to the complexity of the work.** Don't default everything to the top tier (wasteful on cost + latency); don't hand hard reasoning to a weak tier (risky for correctness). Spread the work across the tiers available to you so each task runs on the cheapest model that can still do it well.

The mapping:

- **Fast / cheap tier (e.g. Haiku)** → mechanical, low-reasoning work: renames, formatting, import sorting, doc-blocks + comment annotation, boilerplate, simple find-and-replace, trivial config edits, simple `grep`/`glob` sweeps, syntax-only fixes.
- **Mid tier (e.g. Sonnet)** → standard implementation: a self-contained feature, a single-page handler, a focused bugfix in code you already understand, writing tests against a clear spec.
- **Top tier (e.g. Opus)** → genuinely hard reasoning: architecture + data-model decisions, subtle multi-file bugs, large cross-cutting refactors, adversarial review / verification, security-sensitive changes (auth, CSRF, SQL-binding, secrets), ambiguous trade-offs with no obvious right answer.

The aim is cost/latency efficiency **without sacrificing quality where it matters**. **When unsure, prefer the more capable tier for anything correctness-critical** — a wrong auth check or a missed SQL-injection vector costs far more than the extra tokens. Tier-down only when the task is unambiguously mechanical.

This repo already ships matching agent types as the natural vehicles — use them rather than reinventing the routing:

- **`quick-edits`** (`.claude/agents/quick-edits.md`, fast/cheap tier) — the low-reasoning mechanical lane.
- **`deep-architect`** (`.claude/agents/deep-architect.md`, top tier) — the hard-reasoning / review / security lane.

Standard mid-tier implementation work needs no special agent — run it on the default model. Reserve the two named agents for the ends of the spectrum.

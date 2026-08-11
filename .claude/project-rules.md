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

## 18. Extensible gating registry + native-API gating (**#1590**, branch `feat/api-native-gating`)

> ⚠️ **Citation warning.** This section, `CLAUDE.md` rules #28/#29, `MEMORY.md` and many in-code
> comments cite **#1352 / #1353 / #1354** for this program. Those three numbers are **SDA scraper
> HTTP-403 reports**, not this work — the mis-numbering was propagated into issue bodies at the time
> (#1357 opens "follow-up to #1353 (content-gating enforcement…)"), which suggests the session that
> built this cited numbers it expected to file rather than numbers it had filed. **#1590 is the
> canonical tracker.** Numbers that ARE correct: #1357 (unify tier gating on the web/offline path),
> #1358 (static `/data/audio` gating), #1481 (admin-configurable feature gating). The in-code
> comments are left as-is until each file is next touched — a mass find-and-replace across the tree
> is exactly the unreviewed sweep the annotation standard forbids.

The expanded detail behind CLAUDE.md rules #28 and #29. This whole family is **additive, web-run, and dormant by default**: the 3 docroots (alpha / beta / prod) share ONE MySQL and migrations are NOT auto-applied on deploy — they are run from `/manage/setup-database` — so every new read MUST tolerate the un-migrated shape (STRICT-mode mysqli throws on a missing column/table; gate or try/catch it), and content gating is a verified no-op until an operator flips `tblAppSettings.content_gating_enabled=1`.

### 18.1 The capability registry — `TIER_CAPS`

- **One registry, one place.** `TIER_CAPS` in `includes/access_tier_validation.php` is the single source of truth for the `tblAccessTiers` capability set. Each entry is a 4-tuple `[short_label, full_description, storage, default]` keyed by the exact capability key.
- **`storage` decides where the cap lives:** `'column'` = the cap has its own `TINYINT` column on `tblAccessTiers`; `'json'` = the cap is a key inside the single additive `tblAccessTiers.Capabilities` JSON column (`migrate-add-tier-capabilities-json.php`).
- **The 7 originals stay `'column'`** (`CanViewLyrics` / `CanViewCopyrighted` / `CanPlayAudio` / `CanDownloadMidi` / `CanDownloadPdf` / `CanOfflineSave` / `RequiresCcli`). Their camelCase emit keys (`canViewLyrics`, …) ARE the native-app API contract — **never re-home an existing column cap into JSON** (it would silently break that contract).
- **Every NEW cap is `'json'`** — one line, no `ALTER`, no new column. This is rule #19/#20 applied to gating vocab: a growable cap set is JSON-backed and app-validated, never an ENUM and never one-column-per-feature.
- **Reads go through the ONE registry**, never a forked matrix: `tierCapStorage()` (where a cap lives), `tierCapDefault()` (assumed 0/1 when absent/unmigrated), `tierCapColumnKeys()` / `tierCapJsonKeys()` (split for the dynamic SQL + the emit), and `tierCapRead($tierRow, $key)` (effective 0/1 off a fetched row — column-backed reads its field, json-backed decodes `Capabilities` and falls back to the default on absent/NULL/malformed JSON). The Capabilities column is existence-gated by `tierCapsColumnExists($db)` (request-cached INFORMATION_SCHEMA probe, bound param) because STRICT mysqli throws on selecting a column that doesn't exist yet on this env.

### 18.2 Canonical how-to — adding a gateable feature

To add a new gateable feature:

1. Add ONE line to `TIER_CAPS` in `includes/access_tier_validation.php`, e.g.
   `'CanRequestSongs' => ['Requests', 'Submit song requests', 'json', 0]`.
2. Run the JSON-backed tier-capabilities card on `/manage/setup-database` (`migrate-add-tier-capabilities-json.php`) on each env that needs it.

That's it — there is **no schema change** and **no per-surface edit**. The admin checkbox (`manage/tiers.php`), both API CRUD endpoints (`admin_tier_create` / `admin_tier_update`), the public `access_tiers` API emit (as `canRequestSongs`) and content gating (`checkTierAccess`) all pick the cap up from the registry. Never add a cap as a new column, and never hand-roll a per-tier matrix to express it.

### 18.3 Registry-driven `checkTierAccess()`

`checkTierAccess($userTier, $action, $hasCcli)` in `includes/ccli_validator.php` is the per-cap resolver (#1353). It still contains the legacy `['public' => [...], 'free' => [...]]` matrix, but that survives ONLY as the **fallback** for an un-migrated DB / unknown tier / a read that threw — it is no longer the source of truth. On a migrated env, `capsForTierFromRegistry($userTier)` reads the LIVE `tblAccessTiers` row (incl. any #1352 json cap) and the result is OVERLAID onto the matrix, so a curator's edits + any new json cap are enforced with no edit to this function. An action that is itself a cap key (camelCase emit shape or PascalCase `TIER_CAPS` key) is resolved directly against the live row, so a brand-new json cap is enforceable the moment a caller passes its name. **The CCLI gate is unchanged:** a `ccli`-tier user without a verified live CCLI licence is still denied `view_copyrighted` / `play_audio` regardless of what the registry says.

### 18.4 Enforcement — `contentGatingApply()` (dormant)

> **#1769 P2 update:** the enforcement CORE described here now lives in the shared Model-2 pipeline (`includes/access_resolver.php`), and `contentGatingApply()` / `contentGatingMediaAllowed()` are thin **delegates** that build a viewer struct (`includes/access_context.php`) and call it. The behaviour below is unchanged — it is a proven byte-identical no-op — but the "where" moved. See **§18.7**. The rest of this subsection describes that (now shared) behaviour.

`contentGatingApply($song, $userId, $platform)` in `includes/content_gating.php` (#1353) is the server-side enforcement point for the public / native read API. It strips the fields a tier may not see/use from a built `song_detail` / `song_data` / `random` payload immediately before emit, and (since #1388) from every song in `songbook_export`.

**Payload gating is not asset gating.** `contentGatingApply()` decides what a *response body* may contain. It cannot protect a URL-addressable file: `/song-media/<id>` is bookmarkable, shareable and guessable by id, and stays fetchable long after the row disappeared from someone's payload. That is what **`contentGatingMediaAllowed($kind, $userId, $presenceToken)`** is for — the same registry-backed decision, applied to a single `tblSongMedia` row, gating the bytes in `song-media.php` and the `bulk_audio` offline manifest. The kind→cap mapping is deliberately mirrored between the two so a cap can never hide the button while leaving the file open (or vice versa).

Both fail open, both are exact no-ops while `content_gating_enabled='0'`, and both resolve caps only through `checkTierAccess()` — never a local matrix. Three LOCKED rules (do not relax):

- **(A) Master switch — dormant by default.** Every public function returns byte-identical data unless `getAppSetting('content_gating_enabled','0') === '1'`. Shipping this changes NOTHING on any live env until an operator flips the flag. Any change here MUST stay a verified no-op when the flag is `'0'`.
- **(B) Caps come from the registry.** No hardcoded tier→field matrix in this module — per-cap decisions go through `checkTierAccess()` (§18.3), so a new one-line json cap is enforced automatically.
- **(C) STRICT-safe + fail-open.** Migrations aren't auto-applied and the 3 docroots share one MySQL, so a request can hit a half-migrated env. Every optional read is wrapped; on ANY uncertainty the helper returns the song UNCHANGED (the master switch is the real gate — this module only trims within an already-opted-in deployment), and logs for an operator.

What it trims when on: lyric BODY (`components`) + per-line / whole-song `translations` / `annotations` + `vocalParts` when the tier can't `view_lyrics`, OR the song is copyrighted (lyrics NOT public domain — the LYRICS axis only, never AND-ed with the music PD flag) and the tier can't `view_copyrighted` — adding `contentRestricted=true` + a `restrictionReason` while keeping the metadata so the client can show a locked card; media rows by kind (`audio`→`play_audio`, `midi`→`download_midi`, `sheet-music`/`musicxml`→`download_pdf`) are dropped (not nulled) so the affordance disappears; `hasAudio` / `hasSheetMusic` are kept consistent with what survived; `offlineAllowed` reflects `offline_save`. `contentGating_userHasCcli($userId)` mirrors the `tier_check` endpoint (non-empty `CcliNumber` AND truthy `CcliVerified`; anonymous → false; DB blip → deny the unlock).

### 18.5 Read rate limiting — `enforceReadRateLimit()` (#1354)

The heaviest sessionless public reads are throttled by `enforceReadRateLimit($scope, $perMin, $perDay = 0)` in `includes/read_rate_limit.php` against the additive `tblReadRateLimit` fixed-window counter (`migrate-add-read-rate-limit.php`). Call sites + ceilings: `song_detail` 240/min, `search` 120/min, `songs_index` 120/min, `related_songs` 240/min, `bulk` 60/min. Key design points:

- **Keyed per-token-then-IP.** A native-app bearer token (accepted only in the strict 64-hex shape `getAuthBearerToken()` uses, then hashed — the raw token is never stored) buckets per device; absent/junk token falls back to `REMOTE_ADDR` (never `X-Forwarded-For`, which a client can forge). This is the same NAT lesson as Service Mode rule #26: a congregation behind one NAT egresses as one IP, so per-IP alone would throttle the whole room. The token is NOT validated against the sessions table (that would cost a query per request and defeat the low-overhead goal) — a forged 64-hex token just gets its own generous bucket; the per-IP backstop still covers the no-token scraper.
- **FAIL-OPEN + dormant.** The table is existence-gated (request-cached INFORMATION_SCHEMA probe) and every DB touch is try/catch'd — an un-migrated install or any error ALLOWS the request. A rate limiter must never take the site down (the #1228 white-screen lesson). Window boundaries are computed SQL-side (`DATE_FORMAT(UTC_TIMESTAMP(), …)`, a hardcoded constant — rule-#5-legitimate) so there's no PHP-vs-SQL clock skew. On a trip it emits 429 + `Retry-After` + `X-RateLimit-*` and `exit`s. Per-endpoint `$scope` reserves room for new endpoint limits with no further migration.

### 18.6 CSRF — `validateCsrfRequest()` for state-changing AJAX

`validateCsrfRequest(?string $token)` in `manage/includes/auth.php` is the robust CSRF check for same-origin writes (#1352). It accepts the request when EITHER (a) a valid session token was supplied (back-compat with `validateCsrf()`), OR (b) it is a genuine same-origin AJAX request: `X-Requested-With` is present (a browser cannot set it cross-origin without a CORS preflight this server never grants) AND any present `Origin`/`Referer` host matches `HTTP_HOST` (explicit cross-origin host rejected; absent header allowed, since the custom header already proves same-origin and some privacy setups strip Referer). The `X-Requested-With` route **never goes stale**, which fixes the SPORADIC "CSRF error" on long-lived editor pages — a baked `$_SESSION['csrf_token']` rotates / GCs / changes across multi-tab sessions while the page stays open, so a legitimate merge/delete/save then carries a stale token and is rejected. Wired into `/manage/duplicate-songs` merge/delete, `/manage/places-api.php`, and a top-level POST gate on ALL legacy `/manage/editor/api.php` writes (clients send `X-Requested-With`). The whole-song save moved to the v2 editor API: it is now `editorSaveSongCore()` in `manage/editor/save_song_core.php`, served by BOTH `manage/editor/api.php` (back-compat shim) and `manage/editor/api2.php`, and the editor POSTs the save to api2 under its `X-Requested-With` CSRF gate. New state-changing AJAX endpoints call `validateCsrfRequest()` — never re-bake a per-render session token into a long-lived page and compare with `validateCsrf()` alone, and don't hand-roll a bespoke same-origin check inline instead of calling the shared helper.

### 18.7 Model 2 — the ONE resolver + ONE pipeline (#1769 P2, branch `claude/gating-model-review`)

The #1769 review found the gating logic scattered: tier/ccli/presence resolution was inlined at the top of `contentGatingApply()` AND `contentGatingMediaAllowed()`, the media kind→cap switch was copy-pasted, and the licence vocabulary was hardcoded in six places that had already drifted. P2 consolidates it into **facts × grants × ONE resolver × ONE pipeline** — additive, dormant, and a proven byte-identical no-op in both gating-off and gating-on states.

- **The viewer struct — `includes/access_context.php`.** `accessViewerContext($userId, $platform, $presenceToken, $apiKeyScopes)` resolves "who is asking?" ONCE into a struct keyed by `ACCESS_VIEWER_KEYS` (`gatingEnabled` / `userId` / `platform` / `tier` / `caps` / `hasCcli` / `licences` / `presenceToken` / `presenceCcli` / `bypass`). Its statement order and throw semantics are lifted verbatim from the old inline resolution: bypass + tier resolution throws propagate to the delegate (fail-open); `hasCcli` and the presence block are internally caught (deny-on-error); the NEW `licences` read is internally caught → `[]` so a throw there can't change the apply outcome. `caps` are the six `TIER_ACTION_CAP_MAP` actions via `checkTierAccess()` (§18.3) with NO presence OR (the resolver adds it). `accessViewerAssemble(...)` is the pure, I/O-free seam the equivalence test builds viewers with. The media path passes `apiKeyScopes=[]` so NO bypass is resolved and no request headers are read (the pre-P2 byte gate had no bypass branch).
- **The pipeline — `includes/access_resolver.php`.** `accessApplySong($song, $viewer)` and `accessMediaAllowed($kind, $songFacts, $viewer)` make every field-level decision from the struct, branch-for-branch identical to the deleted `_contentGating*LegacyCore` seams. `accessResolve($viewer, $facts, $action)` is the general allow/deny primitive P3/P6 will wire (unwired in P2). A per-song **rights-fact** gate on the lyric body is present but **deliberately dead** (triple-locked: `SongData` selects neither `LyricsRightsLicenceKey`/`MusicRightsLicenceKey` column, no row carries a value, the key-present guard skips null/'') — it flips true only when a future emitter (P6) emits a non-empty key naming a licence the viewer lacks, and is tested positively so the machinery is proven live though inert today.
- **The delegates.** `contentGatingApply()` / `contentGatingMediaAllowed()` in `content_gating.php` now check the master switch, then **lazy-require** the pipeline and route through it. Lazy require is load-bearing: `content_gating.php` keeps NO file-scope dependency on the pipeline, so it loads fully first and the require cycle can't form.
- **The licence registry — `includes/licence_registry.php`.** The ONE reader of the licence vocabulary (`tblLicenceTypes`, #459/#1769 P1). `licenceTypesAll()` / `licenceTypesForPicker()` / `licenceTypeKeys()` / `licenceConfersTier()` / `licenceCovers()` are existence-gated and degrade to `LICENCE_TYPES_FALLBACK` (the byte-exact P1 seeds) on any failure. The six vocab sites (`organisations.php`, `restrictions.php`, `my-organisations.php`, `ccli_validator.php`'s conferral, and three schema COMMENTs) all consume it. `resolveEffectiveTier()`'s conferral overlay is a **proven identity** for every current input (the registry confers what the legacy literal did; `mrl`/`custom` confer nothing → fall through to `'free'`).
- **The `enforce` element.** `TIER_CAPS` tuples gain an OPTIONAL 5th element (`tierCapEnforceShapeValid()` / `tierCapEnforce()`) describing WHAT a cap gates (payload keys / media kinds / actions / a licence coverage kind). The frozen 7 built-in caps never carry one; a DB-defined cap gains it only via a shape-valid `tblGatingCapabilities.EnforceJson`. Zero production callers in P2 (the P3+ pipeline will consume it) — the ONE place enforcement intent is read (never a second matrix).

**Proof discipline (rule #34/#35).** The pre-refactor outputs were frozen as goldens (`tests/php/fixtures/gating-goldens.json`, 240 apply + 144 media over a tier×ccli×presence×shape matrix) and replayed byte-identically through the new pipeline by `test-gating-equivalence.php` (DB-free — it skips when a DB is reachable, since the goldens are matrix-captured; CI runs no MySQL for `getDbMysqli()`). `test-gating-pipeline-structure.php` locks the facts-inertness lock, the `$viewer[…]` key mechanism (tree-derived — scans every `.php` under `includes/`), the deleted legacy cores, and the delegate routing. The whole checklist was mutation-proven break→red→restore.

**P3 — emitter adoption + song.php fork collapse (dormant).** The public HTML song page (`includes/pages/song.php`) had its OWN inline copy of the tier/presence/media gating; P3 collapses it onto the shared pipeline via `includes/song_page_gating.php` (`songPageGatingDecide(array $viewer, …)`, reading tier/caps/presenceCcli off the viewer — the page now resolves nothing itself). Proven byte-identical the same A→B→C way: the legacy maths was extracted verbatim, its outputs frozen as 4608 goldens (`tests/php/fixtures/song-page-gating-goldens.json`), and the viewer-driven form proven to reproduce every one (`test-song-page-gating-equivalence.php`). Two subtleties P3 pins: (a) the page's DELIBERATE pre-existing quirk — presence ORs into sheet/midi as well as audio, where the API ORs only audio — is preserved, not converged (owner decision #1774); (b) the song.php viewer is built with `apiKeyScopes=[]` (the bypass short-circuit's neutral all-false caps would otherwise GATE a bypass-key render on a caps-reading page). `songbook_export` builds the viewer ONCE and maps `accessApplySong` (a perf win; the single-song emitters stay on the delegates by decision). And the master cache gap is closed: when gating is on, api.php marks the viewer-dependent `page=song` fragment `Cache-Control: private, no-store` + excludes it from the shared ETag/SW cache, and the service worker's `swResponseCacheable()` honours no-store at its incidental put sites (auth already rode the `ihymns_auth` cookie — no client change needed). Deliberate offline downloads are NOT gated (an entitled user must still save; re-gated on next fetch — the #1388 posture). All dormant: with `content_gating_enabled='0'` every P3 change is a verified no-op.

**P4 — management surfaces + DM-2 rights-fact derivation (dormant).** The WRITE path over the Model-2 vocabulary + facts, all no-op until P6. `manage_licence_types` + `/manage/licence-types` + `admin_licence_type_*` do CRUD over `tblLicenceTypes` through ONE shared core `includes/licence_type_admin.php` (LicenceKey immutable on update; a coversMerge that keeps an unqualified `{}` stable across a JSON round-trip instead of flipping it to `[]`; delete refuses system/referenced rows; reference counts existence-gated). The v2 editor grows a **Rights panel** (`js/…/rights-panel.js`, vocab from `window._iHymnsLicenceTypes`) writing the two per-song facts through an existence-gated `metadata_field_update` branch that validates against the live registry (422 on unknown key) and audits `admin.song.rights_set`; `load_song` emits a songbook-default prefill hint. `/manage/songbooks` sets the songbook DEFAULT rights keys with an opt-in **fill-NULL-only** (`IS NULL`) apply-to-songs sweep. **DM-2** (`migrate-derive-rights-facts.php`, an Apply-all card with a data-derived drift-detecting probe + CLI revert) copies existing `require_licence` restrictions into the fact columns via the ONE shared fold `rightsFactColumnForLicence()` (lyrics/music/none — shared by migration AND probe so they can't disagree), fill-NULL-only + idempotent + data-only (no schema.sql edit). `require_*` restrictions normalise `Effect='deny'` server-side (engine-dead — content_access already ignores Effect for them). A `§(g)` fact-column **containment lock** (in `test-gating-pipeline-structure.php`) proves the two fact columns are referenced ONLY by the P4 management/migration surface — a leak into any public read path goes red. And a tree-derived **activity-log guard** (`test-gating-admin-activity-log.php`) fails the build if any gating-write admin surface skips `logActivity()` — it caught `tiers.php` logging only on failure (now logs `admin.tiers.*` on success too). All dormant: with `content_gating_enabled='0'` every P4 change is a verified no-op; the derived facts + licence vocab ENFORCE nothing until P6.

**Deferred to P6 (owner decisions, tracked):** whether `mrl`/`custom` should confer a tier (#1772); making the `RequiresCcli` hard gate registry-cap-keyed rather than tier-name-keyed (#1773); the sheet/midi-vs-audio presence-OR web/API divergence (#1774); a serve-time tier gate on `audio-media.php` (#1775); clearing user Cache-API buckets on login for the shared-device offline case (#1776); and wiring the presence token into `checkBulkAccess` — the deferred P3 Commit F, a state-(b) delta (#1777). The **P6 activation itself** — the `/manage/gating` hub + the master-switch flip — stays owner-gated, as do the dead songbook/feature restriction pickers. All non-blocking; P2+P3+P4 preserve today's behaviour exactly.

---

## 19. Observability conventions (#1581 / #1582 / #1583)

Three related pieces landed together in 2026-07. They exist because of one recurring failure mode: **something breaks in the browser and nobody finds out for weeks.** The public Export feature was dead for roughly seven weeks (#1565) and the Settings language filter silently never refreshed Song of the Day — in both cases the code *looked* alive, threw no visible error, and only a user's report surfaced it.

### 19.1 Event names live once — `js/constants.js` (#1581)

Every custom DOM event name (`ihymns:*`) is declared once in `appWeb/public_html/js/constants.js` and imported. A raw string literal anywhere else is banned by `tests/test-event-names.js`, which additionally asserts that **every declared name has both a dispatcher and a listener**.

The reason is a real bug, not tidiness: a dispatcher spelled one thing, a listener spelled another, and the mismatch is a **perfect silent no-op** — no exception, no console warning, no failed network call. A pair-existence check is what actually catches it; a naming convention alone would not have.

### 19.2 Client error surfacing — `js/modules/error-monitor.js` → `client_error_report` (#1582)

`error-monitor.js` boots **first** in `app.js` (before anything it might need to observe), listens for `error` and `unhandledrejection`, shows the user **one** generic toast, and beacons a report to `POST /api?action=client_error_report`.

Contract, and why each part is there:

- **Throttled three ways** — a client-side per-fingerprint window (10 min, `sessionStorage`-persisted so it survives a reload), a hard per-session cap, and a **server-side 15-minute fingerprint backstop** plus a 10-per-60s per-IP rate limit. One broken page open in fifty tabs must not become fifty thousand rows, and the client half of that is attacker-controlled, so the server keeps its own.
- **Scrubbed on both sides.** Bearer tokens, 64-hex strings and query-string secrets are stripped, and stacks are reduced to `pathname:line`. The client scrub is convenience; the server scrub is the one that counts, because the client is attacker-controlled.
- **Reuses `logActivity()`** — reports land in `tblActivityLog` as `Action=client.jserror`, reviewable at `/manage/activity-log`. Deliberately **no new table**: this is crash surfacing for an internal admin audience, not product analytics, so it is not consent-gated and collects no new PII category.
- **Malformed bodies are dropped silently and the endpoint fails open.** `navigator.sendBeacon` cannot read a response, so there is nobody to tell; and an error reporter that can itself break the page is worse than no error reporter.

### 19.3 What's New — deploy-time extraction → `markdown_lite.php` (#1583)

`deploy.yml` extracts the **first three `## ` sections** of `CHANGELOG.md` into `appWeb/public_html/data/whats-new.md` on every deploy; `/whats-new` renders it through `includes/markdown_lite.php`.

Two consequences worth internalising:

- **The top of `CHANGELOG.md` is a user-facing surface.** A malformed or duplicated top header ships straight to testers — exactly what happened when a consolidation merge left two `## [unreleased]` headers and the page showed *unreleased, unreleased, April* (#1586).
- **`markdown_lite.php` is deliberately tiny and escape-first**: it escapes the input, then applies a small closed set of inline rules. Do **not** swap in a full Markdown library. The input is a repo file today, but the renderer's safety property should not depend on that staying true.

## 20. The v2 Song Editor cutover (epic #1601, 2026-07-30)

`/manage/editor/` now serves the **v2** editor. `manage/editor/index.php` (v1) 302-redirects to
`editor2.php`, forwarding the query string. v1 is **not** deleted.

### 20.1 Why the route, not the nav

Six surfaces link into `/manage/editor/` with instructions in the URL: `admin-links.php`,
`manage/index.php`'s dashboard card, `revisions.php` (`?song=&tab=history`), `missing-numbers.php`
(`?songbook=` + `#number=`), `duplicate-songs.php` (`?song=`), and the public song page's Edit
button. Changing only the nav href leaves the other five on v1 — and after retirement, on nothing.

**302, never 301.** A 301 is cached by the browser, sometimes indefinitely; on a revert, everyone
who visited once keeps being sent to v2 *from their own cache*, with no server-side way to stop it.

### 20.2 The three escape hatches, in order of how fast they act

1. `?legacy=1` — per-visit, immediate, no state. **`editor2.php`'s "Legacy" button must carry it**;
   a bare `/manage/editor/` link bounces straight back and reads as a broken button.
2. `tblAppSettings.editor_v2_default = '0'` — fleet-wide, no deploy. An **absent** key means v2, so
   no migration is required (rule #19 governs *schema*; this is a key-value row). `getAppSetting()`
   returns its default on ANY DB error, so a database wobble sends people to v2 rather than throwing
   on the way in.
3. Reverting the commit.

### 20.3 Why v1 is still there

Retirement (#1601 scope item 3) is a separate change, for two reasons that outlive this pass:

- **None of the cutover was runtime-verified.** It was built in a container with no MySQL and no
  browser; every check is static analysis or a source-derived test. Deleting the fallback in the
  same change that makes v2 the default leaves no way back if something only appears under real
  curator load.
- **The three docroots share ONE database** and `beta`/`main` sit on unrelated histories a month
  behind. Retirement depends on cross-branch state that cannot be established from a build
  container. The weakest branch decides.

### 20.4 What v1 still owns

`manage/editor/api.php` remains a back-compat shim (rule #29 — it serves `editorSaveSongCore()`).
Before deleting it, re-run the consumer sweep: #1629 found four consumers, and a **fifth** — inside
v2 itself (`v2/export.js`'s EasyWorship export, #1678) — was missed because that audit looked for
consumers *outside* the editor. `tests/php/test-v1-consumer-deorphan.php` covers the known set.

### 20.5 Two clear-semantics traps in `component_upsert`

It decides "did the caller supply this field?" with `isset()`, and `isset()` is **false** for a JSON
`null`. So **sending `null` PRESERVES the stored value** rather than clearing it:

- clearing chords requires `[]` (an empty array replaces);
- clearing the last per-line language override requires an **array of `null`s**, not `null`.

Neither mistake produces an error at any layer — the save "succeeds" and the field silently keeps its
old value, permanently un-clearable.

### 20.6 Arrangement ordinals index the ASSEMBLED list

`tblSongs.ArrangementJson` holds 0-based indexes into the song's section list, and repetition is the
point (`[0,1,1,2]`). Two component lists exist: **assembled** (what gate G4 and the public render
index) and **editable** (what the editor indexes). They are equal *except* when a zero-line component
exists, where editable is longer. `arrangement_update` therefore validates against the **assembled**
count and returns **422** while the two diverge, rather than storing ordinals that mean different
things to each side. Clearing is always allowed — you must be able to get out of that state.

The validator is the shared `includes/arrangement.php`; the write side (`_sanitiseArrangement()`) and
gate G4 both call it. Its int-vs-digit-string asymmetry is deliberate: `arrangementSanitise()`
coerces because it handles INPUT, `arrangementViolations()` is strict because it judges data already
STORED, where a digit-string means the writer was bypassed.

## 21. Conventions from the `claude/issue-sweep-fixes-89` batch (2026-08, #89/#91)

### 21.1 Public list-sort persistence — a saved layout is a wish, not a contract (#1786)

The public multi-level Sort ▾ control (`js/modules/list-sort.js` + the pure comparators in
`js/utils/sort-compare.js`, shared with `js/modules/admin-table-sort.js`; server partial
`includes/partials/list-sort-control.php`) persists a per-surface sort spec. **The spec is validated
against the keys a control CURRENTLY offers on every read** (`normalizeSortSpec()`: validate / cap at
3 levels / dedupe) — a persisted level whose key a surface no longer offers is dropped, not honoured
blindly. Persistence rides the EXISTING namespaced `user_settings` endpoint (#1671 F5), namespace
`list_sorts` — **never a new endpoint, table, or migration for a per-user preference the
`user_settings` namespaces already carry.** Anonymous/offline is device-`localStorage`-only;
signed-in additionally syncs (account wins per surface on read; the namespaced POST branch carries
`validateCsrfRequest()`). Known pre-existing exposure carried, not silently absorbed: `settings.js`'s
legacy whole-blob `user_settings` push still clobbers every namespace (the same hazard `cardLayouts`
already lives with) — filed, not hidden.

### 21.2 The outbound-HTTP client house pattern — now three instances (#1725 / #38 / #94)

Every server-side call OUT to a third party copies the ONE shape: `includes/intapps_client.php`
(MWBM-IntAppsAPI, #1725), `includes/cuercode_client.php` (QR, rule #38), and `includes/ia_client.php`
(archive.org OCR, #94). The shared contract — **SSRF-hardened host-bound URL** (the target host is
fixed, never user-supplied), a **size-capped aborting write-callback**, **no redirects**, **SSL verify
on**, house-band timeouts, **returns null on ANY failure (never throws)**, and **dormant until keyed**
where a secret is involved (config null with no key; the key registered in `secretSettingKeys()` so
it's encrypted at rest). A 4th outbound integration copies this shape — never a bare `file_get_contents($url)`
or an un-host-bound cURL. The two secret-bearing ones (`cuercode`, and any IntApps key) stay dormant
until an admin pastes the key on `/manage/configuration`.

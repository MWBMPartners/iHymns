# 📋 iHymns — Project Brief

> **Claude Context File** — This file ensures continuity across development sessions.

---

## 📌 Continuation note — 2026-08-11 (#91 FINAL docs-consistency sweep + version bump — supersedes all "NOT YET PUSHED" claims below)

**The `claude/issue-sweep-fixes-89` branch is PUSHED and up-to-date with origin.** Every earlier
2026-08-xx continuation note below that says "BUILD pass done, NOT YET PUSHED" (the #1786, #1800,
#1798, #1786-admin-sweep, #1785, #1770 notes) is **superseded on that point** — the branch has since
been pushed; the code descriptions in those notes remain accurate. A single PR for the whole branch is
pending the owner (the one-PR rule).

**#91 was the final documentation-consistency sweep — DONE.** Five atomic commits reconciled help,
wiki, OpenAPI, the top-level markdown, and these `.claude/` docs with what actually shipped on the
214-commit branch:

- **C1 `docs(help)`** — public `help.php` (Printing & PDF #1767, Sorting Lists #1786, theme chips
  #288, set-list print cross-link), `help/exporting.md` (fixed the false "no separate download" line;
  Download PDF now exists), `help/searching-songs.md`, and admin `manage/help.php` (IA Reconcile #94,
  Publishers #93, Licence Types + Gating Hub #1769, extended Print Templates / My Organisations /
  Service Mode).
- **C2 `docs(wiki)`** — 7 wiki pages (Database, API-Reference, Setlists, Live-Follow, PWA-Features,
  Architecture, Security).
- **C3 `docs(api)+chore(version)`** — added `?page=publisher` to `api-docs.yaml`; **version bump
  0.4100.0 → 0.5050.0** (owner-directed significant minor) in lockstep across `infoAppVer.php`, `api-docs.yaml`,
  `manifest.json`, `README.md`, `PROJECT_STATUS.md`, `appWeb/CHANGELOG.md`. The dead "v1.x =
  local-JSON phase" comment was retired.
- **C4 `docs(changelog,md)`** — a consolidated `[0.5050.0]` CHANGELOG header + the 3 deferred family
  entries (#1767 remainder, #94, #1765 core) + #1791 server-half + gating P0/P1; README /
  PROJECT_STATUS / DEV_NOTES / SECURITY updated.
- **C5 `docs(claude)`** — this note; MEMORY.md; CLAUDE.md rules #39/#40 + the #28 patch;
  project-rules.md notes; a fresh HANDOFF.

**The batch (§0 families, with the design doc to cite — do NOT re-derive design):** #1765 songbook/
catalogue epic + #93 Publishers (`.claude/songbook-catalogue-enhancements-plan.md`); #1769/#1778
gating program (`.claude/gating-model-review-1769-plan.md`); #1767 print/PDF remainder
(`.claude/print-templates-1767-remainder-plan.md`); #94 IA-reconcile Phase 1 (`.claude/ia-ocr-94-plan.md`);
#1770/#1792/#1798 Live-Follow (`.claude/live-follow-1770-plan.md`); #1791/#1790/#1789 set-list sharing
(`.claude/setlist-sharing-1790-1791-plan.md`); #1786 public list-sort (`.claude/public-list-sort-1786-plan.md`);
#1785 musicians dedup (`.claude/musicians-dedup-1785-plan.md`); #1783 duplicate-song; #1788
ProPresenter CSP-safe export; QR → CueRCode (`.claude/qr-cuercode-integration-plan.md`). Full sweep
spec: `.claude/final-docs-sweep-91-plan.md`.

**Header-fact refresh (2026-06-21 "fact-refresh" precedent — counted from live source this date):**
- `schema.sql` now has **152** `CREATE TABLE` statements (was "~131"/"142") — 150 distinct `tbl*` names.
- API surface: **~294 dispatched actions / 275 documented OpenAPI paths** across the 4 dispatchers
  (was "70+"/"195"). The 19-path gap is exactly the 20 legacy `manage/editor/api.php` actions,
  undocumented since merge-base — a pre-existing hole, not a branch regression (filed for
  consideration under #91: "document or retire the legacy editor API surface").
- **11 new migration cards** pending on each environment; the operator runs them via
  `/manage/setup-database`: `migrate-publication-metadata`, `migrate-publishers-entity`,
  `migrate-reconcile-credit-name-bytes`, `migrate-musician-duplicates-dismissed`,
  `migrate-add-gating-facts-and-licence-types`, `migrate-derive-rights-facts`,
  `migrate-consolidate-org-licences`, `migrate-live-follow-quick-capable`,
  `migrate-setlist-share-scope`, `migrate-print-template-layouts`, `migrate-ia-reconcile`.
- **Deploy-time blocker (non-blocking to build):** QR is dormant until an iHymns→CueRCode API key is
  pasted on `/manage/configuration` (rule #38). `content_gating_enabled` stays `'0'`.

**Suites at sweep close: 153 PHP / 56 node, all green** (docs don't move the counts).

**Version is now `0.5100.0`** (owner-directed significant minor bump; supersedes the `0.4001.0` claims still
written at the historical §"What Is iHymns?" and the 07-30 consolidation note below — read
`includes/infoAppVer.php` as the authority, per the fact-refresh precedent).

---

## 📌 Continuation note — 2026-08-11 (#1786 Option B — public multi-level list-sort, BUILD pass done, NOT YET PUSHED)

**#1786 Option B (the PUBLIC-app half of "every table/list should be user-sortable, multi-level") —
fully built + guarded on branch `claude/issue-sweep-fixes-89`, NOT YET PUSHED. The admin `<table>`
half shipped in an earlier session (`js/modules/admin-table-sort.js`); this pass is everything else
the issue asked for — card/list surfaces, which have no column headers to click.**

- **C1 `f20b3af8`** — extracted `makeCompare()`/`multiKeyCompare()` out of `admin-table-sort.js`
  VERBATIM into a new pure `js/utils/sort-compare.js` (rule #22 — never re-fork), plus new
  `multiKeyCompareMissingLast()` (a level with no value sorts after every present value, in BOTH
  directions), `titleSortKey()`/`SORT_ARTICLES` (JS mirror of `ihymns_title_sort_key()`), and
  `normalizeSortSpec()` (validate/cap-3/dedupe). `tests/test-admin-table-sort.js` unmodified, still
  green.
- **C2 `b418bc9c`** — the shared "Sort ▾" control: `includes/partials/list-sort-control.php`
  (server-emitted, shared-cache-safe per rule #6/#30) + `js/modules/list-sort.js` (two adoption
  modes: DOM-reorder via `data-list-sort-list`/`data-sort-<key>`, and array/server mode via
  `wireListSortControl()`), booted unconditionally from `router.js afterPageLoad()`.
- **C3-C5 `efe42259`/`4c8e64f6`/`776f755e`** — DOM-mode adoption: `/songbooks`, `/songbook/<abbr>`
  (absorbing `songbook-index.js`'s old single-level unpersisted toggle — new `EVT_LIST_SORT_CHANGED`
  constant lets the alphabet strip rebuild after a re-sort), `/tag`, `/musician`, `/tune`,
  `/publisher`, `/work` (Default = "Work order", not "Number" — a work's curated member order IS
  meaningful), the identifier pages.
- **C6-C7 `89f8ba99`/`5cf20230`** — `/favorites` (array mode, sorts a copy of the localStorage array)
  and `/search` (server-sort mode — pagination makes client DOM-reorder dishonest; new `sort=` param,
  `SongData::_searchOrderBy()` maps to HARDCODED `ORDER BY` fragments only, rule #5; `api-docs.yaml`
  updated same commit, rule #33).
- **C8 `c1386f2e`** — account sync rides the EXISTING namespaced `user_settings` endpoint (#1671 F5),
  namespace `list_sorts` — **no new endpoint, no schema, no migration**. `validateCsrfRequest()` added
  to the namespaced POST branch (the `live_follow_extend` precedent, rule #29).
- **C9 `91bb3034`** — `tests/php/test-public-list-sort.php` (10 candidates, 8 fully DOM-checked, 2
  array/server-mode partial, 26 option keys) + `tests/test-list-sort.js` (50 assertions). Both
  mutation-proven — 12 distinct mutations run red→restored→green, recorded in the commit body.
- **Suite counts**: node 55→56, PHP 152→153. Both full suites green after every commit.
- **Home page songbook grid deliberately excluded** (⚑ N6 — duplicates `/songbooks` one tap away) —
  filed as owner-decision issue #1808, recommendation is to leave excluded.
- **Follow-ups filed**: #1806 (settings.js's legacy whole-blob push still clobbers `list_sorts`/
  `cardLayouts` until next touched — pre-existing exposure, not new here), #1807 (a generated
  `TitleSortKey` column so `/search`'s SQL `ORDER BY` becomes article-aware like every other surface).
- **#1786 closed** (comment posted linking all 9 commits + the 3 follow-up issues) — both the admin
  and public halves named in the issue's original scope are now shipped.
- **NOT DONE this pass**: push to remote (stopped per instruction — build-pass only); a live-browser
  click-through verify (guards are static-source-analysis + jsdom-free pure-logic, per their own
  documented SCOPE/LIMITS — they prove the markup/wiring is present, not that a click sorts a
  visually-rendered page).

Full design: `.claude/public-list-sort-1786-plan.md`.

## 📌 Continuation note — 2026-08-11 (#1800 musician merge/dedup follow-ups, BUILD pass done, NOT YET PUSHED)

**#1800 (musician merge/dedup follow-ups to #1785/#1796) — three of four items BUILT, on branch
`claude/issue-sweep-fixes-89`, NOT YET PUSHED (stopped after build + guards + docs per the build-pass
brief). Fourth item assessed and deliberately DEFERRED — see below.**

- **C1 — `credit_search`'s third table list, collapsed.** `manage/editor/api2.php`'s `credit_search`
  autocomplete carried its own hand-typed six-table `$kindToTable` map — a THIRD copy of the same
  fact alongside `ED2_CREDIT_TABLES` (same file) and `MUSICIAN_CREDIT_ROLE_TABLES`
  (`includes/musician_helpers.php`), flagged as "discovered, not fixed here" in
  `tests/php/test-musician-credit-tables-single-list.php`'s own doc-block since #1785. Now reads
  `MUSICIAN_CREDIT_ROLE_TABLES` directly — its singular keys already match this endpoint's `kind=`
  query convention byte-for-byte, so no transform was needed (unlike `ED2_CREDIT_TABLES`'s plural
  keys, which would have). The guard gained a PART C that isolates the `credit_search` case block by
  source position (next `case '...'` at the same indentation — brace-balancing would over-match the
  block's own internal `foreach` loops) and asserts no bare six-table-name literal remains AND the
  shared constant IS referenced. Mutation-proven both directions (literal list restored → red;
  restored → green).
- **C2 — COALESCE-fill on merge.** `musicianMergeExecute()` used to silently drop every biographical
  fact recorded ONLY on the losing side of a merge — a source's `Biography`/`MusicBrainzArtistMBID`/
  `Disambiguation`/birth-death dates vanished the moment its row was deleted, even when the target had
  nothing there at all. Now backfills the survivor's EMPTY `Biography` / `MusicBrainzArtistMBID` /
  `Disambiguation` / `BirthDate`+`BirthDatePrecision` / `DeathDate`+`DeathDatePrecision` from the
  source — **never** overwrites a non-empty target value (the survivor's own data always wins). New
  `musicianMbidColumnExists()` gate (mirrors `musicianMaidenSurnameColumnExists()`'s pattern) joins the
  existing `musicianProfileColumnsExist()`/`musicianDatePrecisionColumnsExist()` probes (rule #9) —
  `MusicBrainzArtistMBID` was added by ALTER (`migrate-identifier-media-hardening.php`), not part of
  the table's original definition. **Ordering is load-bearing**: the fill runs AFTER the source row's
  `DELETE`, inside the SAME transaction the function already owns — `MusicBrainzArtistMBID` carries a
  UNIQUE key (`uq_MbArtist`), so filling the target's copy BEFORE the source row is gone would collide
  with the source's own still-live value. Identity-shaped columns (`FirstNames`/`Surname`/`Suffix`/
  `MaidenSurname`) are deliberately excluded (not asked for, riskier to silently fill); ISNI/IPI are
  NOT single-value columns on `tblMusicians` at all — they're multi-row children in
  `tblMusicianIdentifiers`, already covered by the existing `keepIpiIds` carry-over. The report gains
  an additive `fieldsFilled` key, surfaced in all three call sites' activity-log payloads
  (`manage/musicians.php`, `manage/musician-duplicates.php`, `api.php`'s `admin_musician_merge`) plus
  `musicians.php`'s success banner and `api.php`'s JSON response. **Runtime-verified** against the live
  `ihymns_live` scratch DB — 17 checks across three disposable-row scenarios (empty target fills from a
  populated source; non-empty target keeps its own values untouched; a partially-empty target fills
  only its own empty fields), all passed, cleanup left zero residual rows.
  `musicianMergeExecute()` owns its own transaction (asserted by the brief NOT to nest one around it —
  `mysqli`'s `START TRANSACTION` implicitly commits any already-open one, so an outer wrap would not
  actually achieve a rollback), so verification used explicit `DELETE`s of the disposable rows instead
  of a literal SQL `ROLLBACK`.
- **C3 — create-time fold-match hint.** The `/manage/musicians` "Add person" drawer's Name field now
  debounce-checks a new `musicianFindFoldMatches()` helper — reusing the SAME
  `ihymns_sim_name_normalise()` exact-fold test the duplicate-review page's Bucket A already uses
  (rule #22, `includes/musician_duplicates.php`) — via a new read-only GET `?action=name_fold_check`
  endpoint on `manage/musicians.php` (mirrors the existing `merge_target_search` endpoint's shape). A
  soft, non-blocking "possible duplicate of X (view)" hint appears below the field — linking to the
  matched person's public `/musician/<slug>` page in a new tab — and NEVER disables Save. The check
  only ever fires while the Name field is editable: Edit mode locks it (renames go through the
  separate Rename action instead), so there's nothing to debounce there — no extra readOnly guard was
  even needed, since a locked `<input>` never fires user-driven `input` events. New guard
  `tests/php/test-musician-name-fold-check.php`: Part A (source scan, always runs) asserts the route
  is wired and the drawer renders the hint element; Part B (behavioural, runs against a reachable DB
  inside a rolled-back transaction — `musicianFindFoldMatches()` is READ-ONLY, so unlike C2's merge
  core this WAS safe to wrap in an outer transaction) asserts an exact whitespace/case/apostrophe-style
  variant IS found, a genuinely different name is NOT (no false positives), `exclude_id` is honoured,
  and blank/punctuation-only input never throws. Mutation-proven in three ways (fold comparison
  short-circuited → red; `exclude_id`'s SQL operator inverted → red; the route's action string renamed
  → red on Part A specifically), each restored before committing.
- **C4 (assessed, DEFERRED) — unifying `/manage/musician-duplicates` with
  `/manage/musicians-bulk-promote`.** Weighed against the `#1215` precedent
  (`duplicate-songs` absorbing `song-link-suggestions`) and judged NOT a clean, contained merge —
  reported as a scoped follow-up rather than forced. The two pages' own in-repo comments already draw
  the line explicitly (`manage/musicians.php` around the two CTA banners): bulk-promote's candidate
  universe is song-credit NAMES with no registry row yet (per-NAME `register`/`merge`/`skip` actions);
  musician-duplicates' candidate universe is PAIRS of EXISTING registry rows that look like the same
  person (per-ID-PAIR `merge`/`dismiss` actions). Unlike `song-link-suggestions`, which was the SAME
  "compare two songs" concern as `duplicate-songs` with a different data source (precomputed batch
  table vs. adhoc), there is no natural absorption here — the two pages don't share a candidate shape
  to unify around. A real merge would need a design pass reconciling the two action vocabularies
  across ~1,650 combined lines of actively-used, independently-tested bulk POST logic FIRST, before
  any code moves — real regression risk to two currently-working surfaces for a "balloons" outcome the
  build brief explicitly said not to force. Left as a follow-up recommendation (not filed as a GitHub
  issue per this session's instructions — the calling agent owns issue-tracker actions).
- **Suites: 145 PHP (144 baseline + this session's two new guard files, `test-musician-name-fold-
  check.php` new + `test-musician-credit-tables-single-list.php` extended with Part C) / 54 node**
  (the +1 over the 53 baseline is unrelated concurrent `#1789` work landed mid-session by a sibling
  session sharing this container — see the "concurrent session" note below), all green after every
  commit.
- **Concurrent session note**: this branch was being worked simultaneously, in the SAME container, by
  a sibling session on unrelated `#1789`/`#1791` set-list print/share work (commits `83d45a68`,
  `2eff4be1`, `50a9e6bd` landed interleaved with this session's three). Only this session's own
  explicit file paths were ever `git add`ed — never `-A` — so the two bodies of work stayed cleanly
  separated in the history despite sharing one working tree. One accidental collision surfaced and was
  fixed: a doc-comment for the new `musicianMbidColumnExists()` gate initially wrote out the pre-#1741
  table name literally, tripping `test-musician-rename-guard.php`'s count-exact old-name-token
  allowlist — reworded to avoid the banned token rather than widening the allowlist.

## 📌 Continuation note — 2026-08-10 (later still — #1798 Live Follow session-length + extend, BUILD pass done, NOT YET PUSHED)

**#1798 (follow-on to #1770/#1792) — declare a Quick session's length at "Go Live" + extend a
running one mid-service — BUILT, on branch `claude/issue-sweep-fixes-89`, NOT YET PUSHED (stopped
after build + guard + docs per the build-pass brief).** Service Mode is unaffected (bounded by the
scheduled service end, not idle) — this is Quick-only.

- **Server** — `live_follow_create` accepts an optional `idleTimeoutMins` (clamped [5,240], capped
  at the host's own enforcing-org lock if any). `serviceMode_resolveIdleTimeoutMins()` grew an
  additive by-reference out-param (`&$enforcedMinsOut`) exposing just the enforced layer it already
  computes, so create/extend share ONE reader of `LiveIdleTimeoutMins`/`EnforceIdleTimeout` (the
  `test-live-follow-idle.php` A3 single-reader guard stays green untouched). New endpoint
  `live_follow_extend` widens a RUNNING session's window and resets the idle clock to now.
  Authorisation (host, OR an admin/owner of an org the HOST belongs to, OR a site admin) is resolved
  via the HOST's `tblOrganisationMembers` rows — **never the session's own `OrgId`**, which Quick
  sessions keep `NULL` (rule #26, load-bearing for CCLI gating) — and extracted into a real,
  independently-testable function `serviceMode_liveFollowExtendAuthorize()` rather than staying
  inline in the dispatcher, mirroring #1792's `serviceMode_codeOnOtherChannel()` shape. 409 (not
  404/400) on an un-migrated install (rule #35); channel-scoped resolve (rule #26);
  `validateCsrfRequest()` (rule #29); rate-limited 60/hour/user (rule #1636).
- **Client** — `live-follow.js`'s `goLive()` gained a lightweight duration picker (30 min / 1 hour /
  2 hours / until I end it) shared with a new **Extend** button on the host bar; both use the
  established direct `bootstrap.Modal` pattern (never `app.js`'s two-option-only `showChoice()`).
  Branches on HTTP `409` for "not migrated yet" vs the generic failure toast (rule #35).
- **Org-admin surface** — `/manage/my-organisations` gained a "Members' live sessions" card (active
  Quick sessions hosted by someone in an org the viewer administers, each with an Extend control),
  column-existence-gated on `serviceMode_idleColumnsExist()`.
- **Guard** — `tests/php/test-live-follow-extend.php` (Part A static source-scan of the real
  endpoint/client/org-admin-page wiring; Part B live-DB behavioural against the REAL
  `serviceMode_liveFollowExtendAuthorize()` + the REAL resolver's out-param — host/org-admin/
  unrelated-user authorization, create-override + enforced-cap, out-of-range clamping, and channel
  scoping). Mutation-proven post-commit (org-membership check short-circuit → B3 red; clamp removed
  from the real case body → A2 red), then reverted via `git checkout --`.
- **Deviation flagged**: the task brief's "Commit 1 — server" / "Commit 2 — client" split was
  collapsed into ONE commit. `tests/php/test-orphan-inventory.php` (tree-derived, excludes `tests/`
  from its caller corpus by design) fails a newly-dispatched public action with no first-party
  caller, and `tests/php/fixtures/orphan-allowlist.php`'s own header explicitly discourages a
  temporary entry ("wire it… together, never one without the others") — so landing the server case
  without its one JS caller in the same commit would have been the very anti-pattern that file's
  philosophy exists to prevent, as well as a red intermediate commit (the brief's own "never commit
  red" is the stronger, more explicit rule here). Server + client landed together; the org-admin UI
  and the guard+docs remained their own commits as specified.
- **Suites: 144 PHP (143 baseline + this session's new guard) / 53 node (unchanged), all green**
  after every commit.

## 📌 Continuation note — 2026-08-10 (later still — #1786 admin sortable-headers sweep + #1799 fix, BUILD pass done, NOT YET PUSHED)

**#1786 (admin adoption sweep of the shared multi-column sortable-table module) + #1799 (two broken
pages) — BUILT, on branch `claude/issue-sweep-fixes-89`, NOT YET PUSHED (stopped after sweep + guard +
docs per the build-pass brief; PR/issue bookkeeping is the orchestrator's job).** Module core already
landed as #1786 core (`js/modules/admin-table-sort.js`, commit `3d2d772f`); this pass was the
**admin-only** adoption sweep (the public app has no data `<table>`s, so it was out of scope by design).

- **Tree-derived audit found more than #1799 described.** #1799 named two broken pages
  (`musicians.php` / `musicians-bulk-promote.php`, tagged `mus-sortable` — a class the module's
  default selector never matches). Deriving the real page list from `grep -rlE '<table'
  manage/*.php` (rule #34) instead of trusting the existing `cp-sortable` tag turned up **twelve
  more** pages (activity-log, ccli-report, data-health, languages, missing-numbers, notifications,
  publishers, requests, revisions, tags, tunes, works) that were tagged `cp-sortable` correctly but
  **never loaded `admin-table-sort.js` at all** — no `<script type="module">` import anywhere on the
  page. Same silent-no-op shape as #1799, just not caught by name. All twelve now boot the module.
- **Full column coverage.** Completed four partially-wired tables (feature-gating.php x2, groups.php,
  licence-types.php, musician-duplicates.php) and fully wired five previously-bare pages (api-keys.php
  — 3 tables, catalogues.php, entitlements.php, my-organisations.php — 2 tables, venues.php — 2
  tables), plus two orphaned tables riding an existing page boot call (organisations.php's edit-view
  Members list, tags.php's canonicalisation-suggestions list).
- **#1799 fixed**: both tables retagged `mus-sortable` → `cp-sortable`, fully keyed, and
  `musicians-bulk-promote.php` got its first-ever module import. Also fixed `musicians.php`'s row id
  (`(int)$p['Id']` → `(int)($p['registry_id'] ?? 0)` — `'Id'` is never a key in that array; every row
  silently collided on `id="mus-person-0"`).
- **Two documented structural exceptions** rather than silent skips: my-organisations.php's Licences
  table colspan-merges four columns into one inline-edit cell (only Type is positionally addressable);
  songbooks.php's Order column holds a live `<input>` feeding the existing drag-to-reorder mechanism
  (rule #6), not sortable text.
- **New guard**: `tests/php/test-admin-tables-sortable.php` — tree-derived (glob minus a documented
  `$PAGE_EXCLUSIONS` allow-list: index/diagnostics/schema-audit/setup-database/service-projection/
  intapps-status/gating-noop-verify/analytics/duplicate-songs, each with a one-line reason), asserts
  cp-sortable + module-boot + per-column `data-sort-key` coverage. Mutation-proven post-commit per rule
  #34 (strip cp-sortable → red; drop a column key → red; add a brand-new unwired page → red; all
  restored via `git checkout --`).
- **Suites: 143 PHP (142 baseline + this session's new guard) / 53 node (unchanged — no JS touched),
  all green** after every commit. Four atomic commits: the sweep (25 files), the #1799 fix (2 files),
  the guard (1 new file), docs (this note + CHANGELOG.md).
- **Flagged for the orchestrator, not guessed silently**: `service-projection.php`'s small connections
  table (Label/Prefix/Venue/Protocol/Last used/Actions) looks like a genuine record list but was kept
  on the pre-approved exclusion list as a live-operator-console widget — worth a second look if that
  page grows more rows. GitHub issue updates for #1786/#1799 (closing, cross-linking the twelve extra
  pages found) were **NOT** performed in this pass — that bookkeeping is the orchestrator's job per
  the build-pass brief.

## 📌 Continuation note — 2026-08-10 (later same day — #1785 status; the #1770 note below is unrelated and still current for that epic)

**#1785 (musician registry-vs-registry duplicate detection + easier merge UX) — C1-C10 ALL BUILT,
on branch `claude/issue-sweep-fixes-89`, NOT YET PUSHED (stopped for review per the build-pass
brief).** Follow-up to #1784 (the invisible-byte reconcile), under epic #1787. Full design:
`.claude/musicians-dedup-1785-plan.md` (now carries an as-built header). A prior session landed C1-C5
(dormant schema, the shared NAME scorer, the merge core extracted then hardened); this pass built
C6-C10:

- **The scan (C6)** — `includes/musician_duplicates.php`. `musicianDuplicatesFindCandidates()` is
  PURE (no `\mysqli`) — blocking via an exact fold (Bucket A) or a COMBINED first+last-token metaphone
  dictionary (Bucket B — the shared key space is what catches a comma-reversed "Newton, John" vs
  "John Newton"), plus a curated-alias signal (Bucket C). No silent caps (rule #35) — an over-cap
  block is skipped and the skip reported in `stats.skippedBuckets`. `musicianDisambiguationPayloadBulk()`
  is the ONE shared payload builder (id, lifespan, per-role use-counts, link/alias/identifier counts)
  reused by every merge affordance built in C7/C8.
- **The review page (C7)** — `/manage/musician-duplicates`, mirroring `/manage/duplicate-songs`
  (#1215): byte-variant groups as cards, fuzzy pairs as a sortable table, one-click Merge (delegating
  to `musicianMergeExecute()`, never re-implemented), Dismiss/Undismiss (`tblMusicianDuplicatesDismissed`),
  a `force=1` + type-to-confirm guard on a lifespan-conflicting pair, keyboard nav (j/k/Enter/d/s, `?`
  legend). CTAs from `/manage/musicians` (a cheap Bucket-A-only count badge) and
  `/manage/musicians-bulk-promote` (cross-link).
- **Disambiguation everywhere (C8)** — the merge-target typeahead, the `/manage/musicians` Merge
  modal (now shows all SIX credit-role pills, not five — `total` already included artist credits from
  C5's hardening, the visible pills just hadn't caught up), and bulk-promote's match labels all now
  show WHY two similar names look alike (the variant badge) and WHICH registry row is which
  (id/lifespan/credit-count) — closes "which is merging into which?" everywhere, not just the new page.
- **Guards (C9), mutation-proven this session against the two NEW files C6/C7 added** — found and
  fixed a genuine gap: G3 (`test-musician-credit-tables-single-list.php`)'s file scan was a hardcoded
  4-file list written before C6/C7 existed, so a re-forked credit-table list in either new file would
  have gone completely undetected; extended to six files, then mutation-proved (fake fork pasted into
  each new file → red → reverted). G2/G4/G5 needed no code change (already tree-derived) but were each
  mutation-proved against the new files too (a `levenshtein()` call, an over-cap-check defeat, a
  Disambiguation-exclusion removal, and a fake inline merge-core copy — all → red → reverted).
- **Docs (C10)** — this note, `CHANGELOG.md`, `DEV_NOTES.md` (Architecture Decisions — the shared
  modules list, mirroring the #1741 pattern), `wiki/Architecture.md` + `Database-&-Migrations.md` +
  `Security.md`, and an as-built header on the plan file itself.

**Suites: 141 PHP (140 + this session's extension of the C6 guard file, net +1 from the 140
baseline C1-C5 left) / 53 node (unchanged — no JS files touched this epic), all green.** Runtime-
verified against the local scratch DB (a request simulator running the REAL page through its actual
auth/CSRF/dispatch code, not a bypassed check) for the full merge/dismiss/undismiss/degrade/force-gate
cycle, and separately for C8's additive payload fields — all rolled back or explicitly cleaned up,
zero leftover fixture rows confirmed each time. GitHub issue updates (closing sub-items, filing the
discovered `tblSongArtists`-gap follow-ups already noted in earlier commits, filing the
`credit_search` third-fork follow-up G3's doc-block flags) were NOT performed in this pass — the
owner handles all issue filing/updates, per this build's explicit instruction; see the session's final
report for the exact list.

One pre-existing, unrelated bug was discovered but NOT fixed (out of this epic's scope): `manage/
musicians.php`'s list-render loop reads `$p['Id']` (should be `$p['registry_id']`) at the `<tr id=…>`
line, producing a PHP "Undefined array key" warning (and an `id="mus-person-0"` HTML attribute) on
every row — present in the base commit before this branch touched the file. Worth a small follow-up
issue.

---

## 📌 Continuation note — 2026-08-10 (supersedes the 08-03 note below for #1770 status)

**#1770 (Live Follow UX rework, Option A) — C5-C8 BUILT, on branch `claude/issue-sweep-fixes-89`,
NOT YET PUSHED (stopped for review per the build-pass brief).** C1-C4 (dormant schema + server
resolution/broadcast-core logic) had already landed on this branch; this pass added the client +
the two deferred admin UI surfaces + six mutation-proven guards + docs, per
`.claude/live-follow-1770-plan.md`:

- **Client (C5)** — `js/utils/presence-identity.js` (device id + presence-cookie set/clear,
  extracted from `service-follow.js`, now shared by both followers); `live-follow.js` gained a
  fixed persistent HOST bar (rule #32 shape, wired from `router.js afterPageLoad()` on every
  navigation), passive leader-activity tracking feeding the heartbeat's `leaderActive` flag, and
  the presence-minting `_doJoin()` POST upgrade.
- **Admin UI (C5-UI)** — idle-timeout fields on `/manage/configuration` (app default),
  `/manage/organisations` + `/manage/my-organisations` (org override/lock, column-existence-gated),
  and `/settings` (personal preference, an intentionally UNPREFIXED localStorage key —
  `STORAGE_LIVE_IDLE_TIMEOUT_MINS` in `constants.js` — because it must equal the server's
  `tblUsers.Settings` JSON root key verbatim); a "Presentation-app control" card on
  `/manage/service-projection` (mint/list/revoke `tblServiceDriverKeys`).
- **Optional client (C6)** — `js/modules/live-host-console.js` (reuses `ServiceBroadcaster` via a
  transport adapter, never a fork); the "Show code" big-code+QR view (`/qr.php`-backed, rule #38);
  `service-follow.js`'s `?svc_code=` deep-link reader (rule #33 — closes the standing emitter-with-
  no-reader gap the projection QR had had since #1339).
- **Guards (C7), all mutation-proven** (broke → red → restored → green, per rule #34):
  `tests/php/test-live-session-channel.php` (G1, channel-wall presence across the 3 walled tables),
  `tests/php/test-live-follow-idle.php` (G2, static + a live-DB resolver-precedence matrix),
  `tests/php/test-live-follow-host-ccli.php` (G3, static + a live-DB dormancy check),
  `tests/php/test-live-follow-broadcast-core.php` (G4, one-broadcaster-core), `tests/*.js` +
  `tests/php/test-rate-limit-pairing.php` extended (G5, `service_drive`/`live_follow_poll` keying),
  `tests/test-svc-code-contract.js` (G6, cross-language emitter/reader). Three now-genuinely-wired
  `service_driver_key_*` actions were removed from `tests/php/fixtures/orphan-allowlist.php`
  (self-cleaning, exactly as that file's own history documents).
- **Docs (C8)** — `api-docs.yaml` (the `live_follow_join` POST/presence mode,
  `idleTimeoutMins`/`leaderActive` fields), `help/live-follow.md` + the in-app help topic,
  `wiki/Live-Follow-&-Service-Mode.md`, `CHANGELOG.md`, this note, and one-line pointers from
  CLAUDE.md rule #26.

**Suites: 135 PHP (131 + 4 new guard files) / 53 node (52 + 1 new guard file), all green.** Deferred/
flagged, not silently skipped: the live two-device verify (#1339/#1792) is explicitly out of this
build's scope per the brief (needs two real devices on one channel, never yet executed); the
ProPresenter-specific protocol shim is its own tracked spike (plan §7/§10 S1), the generic
`service_drive` contract ships instead. GitHub issue updates (closing sub-items, filing the S1 spike
issue if not already filed) were NOT performed in this pass — flagged as a remaining standing-task
item for whoever pushes/opens the PR.

---

## 📌 Continuation note — 2026-08-03 (supersedes the 07-31 note below)

**Live resume point: [`.claude/sessions/2026-07-28-HANDOFF.md`](sessions/2026-07-28-HANDOFF.md)** (its
`#1741` P-series status block is kept current commit-by-commit).

**Epic #1741 (catalogue data-model expansion) is being built ON `claude/wave3-fixes` — NOT
post-merge.** The 07-31 note below still calls #1741 "post-merge"; that is now stale. The branch is
still the ONE pre-merge branch (no PR — the owner wants a single PR to `alpha`, on their word), and
the branch/push warnings below (and `tools/githooks/pre-push`) still stand — install the hooks in
every fresh container (`git config core.hooksPath tools/githooks`).

**#1741 progress (all landed + independently verified on the branch):**
- **P1** — additive, dormant schema batch (identity/disambiguation columns on `tblSongs`,
  `tblMusicians`→`tblTunes`+satellites, `tblSongExternalIds` D5 key/value recording-ID store; all
  `schema.sql`-mirrored, real migration probes).
- **P3** — shared identifier normaliser + resolver (`includes/identifier_normalize.php` +
  `identifier_resolve.php` + `includes/pages/identifier.php`) and alias routes `/isrc /iswc /ccli
  /ipi /isni /bowi` (`dc9b5067`).
- **P4** — per-entity pages: Work + Musician profile + Tune page keyed on the registry, shared
  external-links panel + `tune_helpers.php` extraction (`cb612036`/`8af91f12`/`365b7f41`); P4a-3
  (writer/person consolidation) **HELD on owner decision D4** — see the handoff.
- **D5 backfill (#1747)** — idempotent mirror of `tblSongs.Isrc` + the 4 grandfathered
  `tblSongIdentityMap` columns into `tblSongExternalIds`; added the `genius` recording-ID type
  (`3ff77e02`, owner-confirmed A=C / B=yes / +genius / mirror-ISRC).
- **P5 (COMPLETE)** — editor can enter every identity column (409-gated) + the #1749 ISRC dual-write
  mirror (P5a+P5d `223a10b9`); recording-ID card-list on the Metadata tab (P5b `2349ae57`); tune
  typeahead + the ONE `TuneName`↔`TuneId` write core `ed2_songTuneApply()` + `tune_search` +
  `ihymns_meter_normalize()`, with the lockstep also wired into whole-song save, bulk import, AND
  revision-restore (P5c `55bbac84`). `place-search.js` was **generalised, not forked**, to back the
  tune control. Two new mutation-proven guards (`test-tune-lockstep.php`, `test-tune-typeahead-ui.js`).
- **P6 (docs, this note)** — CHANGELOG + this brief + the handoff + DEV_NOTES ("reuse these shared
  modules") + the in-repo **`wiki/`** (Architecture / Database-&-Migrations / API-Reference) all
  updated; `api-docs.yaml` was already kept current through P2-B (person→musician deprecation is
  documented); per-action api2 docs remain the **deferred #1201** breakout. (Note: the wiki is the
  in-repo `wiki/` tree — 18 tracked pages — NOT the external `../iHymns.wiki/` the older CLAUDE.md
  reference implies; it is editable + committed here directly.) Native surfacing filed as **#1752**.

**Suites now: 100 PHP / 48 node** (re-derived this session; the 07-31 note's "80 / 45" predates the
#1741 guards). Both runners glob their directories.

**#1741 is DEV-COMPLETE** (2026-08-03). **P4a-3 landed** (`64dbb52e`, owner D4 = consolidate): the
heuristic `writer.php` folded into the musician profile — `/writer/<slug>` real-301→`/musician/<slug>`
(index.php), fragment serves the musician page, `router.js` `replaceState`-canonicalises, one shared
fail-open `musicianResolveLegacySlugDb()` ladder (also fixes name-slug `/musician/` credit links),
`writer.php` deleted, sitemap registry-driven, no-registry fallback widened (no silent 404). Also fixed
**#1753** (stranded musician Edit button). Two mutation-proven guards; verified by me (101 PHP / 49
node + my own 3 mutations + live probe). Epic follow-ups (non-blocking): #1748/#1749/#1750/#1751/#1752
+ #1754 (P4a-3 minor follow-ups).

**Follow-up queue drain (owner 2026-08-03: "do the iHymns bits first… then Meedya"):**
- **#1749 / #1751 / #1754 — DONE + CLOSED.** Phase-1 (`e65b92a4`) shipped the ISRC resolver union +
  ingest mirror (`$source` param) + the P4a-3 JSON-LD/alias-rung/discography trio. **#1749 then
  ESCALATED to full unification** (owner "do full unifications now also") — `8f2f3a4f`:
  `tblSongExternalIds` is now the recording-ID **authority**, `tblSongs.Isrc` a synced denorm (one
  projection builder + `songExternalIdSyncIsrcDenorm()` last-word; mirror re-projects on both paths;
  panel/ingest/merge all sync; data-only `migrate-reconcile-isrc-denorm.php` + drift-probe card).
  **#1755** (merge cascade-wiped store rows) fixed in the same commit. D-3 default: tblSongIdentityMap
  columns frozen (flagged to owner, non-blocking). 103 PHP / 49 node green; every guard mutation-proven;
  mirror/sync/promotion/merge-collapse/resolver-union-arm live-probed against dev DB (rolled back). The
  three adversarial guard gaps (mirror assertion 8 per-return-path, assertion 9 per-statement window,
  new behavioural reconcile test) were found by the verify lens and hardened this session.
- **#1750 — DONE + CLOSED** (`56fdd51c`). Public song page + `song_detail`/`song_data`/`getSongs`
  payload + JSON-LD now surface the five P1 identity fields (subtitle/disambiguation/first-published/
  ©-split); one probe + one select-fold feed both read paths; opt-in `include=externalIds` (no
  SourceRef); the five keys are the frozen **#1752 native contract** (noted on #1752). New guard
  `test-song-identity-render.php` (schema-derived, comment-stripped, `tsirVariableUsageCount` catches
  a deleted render). 104 PHP / 49 node green; live data-layer probe + own mutations verified.
- **#1748 — DONE + CLOSED** (`761a5e63`). `/manage/tunes` CRUD + `manage_tunes` + `admin_tune_*` API +
  shared `tune_admin.php` core + external-links tick UI. The adversarial verify caught **two real
  STRICT-mode gating bugs** (Subtitle/Disambiguation are tune-enrichment-card columns; MusicianId is
  post-musicians-rename) — fixed with a `tuneAdminColumnExists()` column probe and **proven live**
  against a scratch pre-enrichment schema. Guard hardened (scans the shared core; PHP-only projection
  kills a JS-comment decoy; both whitelists; gating locks) — all mutations red-then-green. 105 PHP / 49
  node green. ⚠️ Process note: a backup-timing slip during mutation testing (backed up pre-fix, then
  cp/`git checkout`-restored) transiently wiped the fixes; caught + fully recovered before commit — the
  lesson (back up the FIXED state; never `git checkout` to restore uncommitted work) is reinforced.
- **#1752 — DONE + CLOSED** (`a35ddcd0`). Apple decodes/renders the P1 song identity keys + externalIds
  + Work P4b keys + musician IPI/ISNI (Optional/`decodeIfPresent`); one shared copyright fold; `/musician/`
  deep link now resolves; the only web change (`getMusician()` identifiers, existence-gated) verified live.
  Android forward-compat only (Phase-2 client = #1756). Guard `test-native-identity-contract.js` (node
  49→50) — a rule-#34 gap (`WORK_KEYS === 9` → `>= 9`) caught by the adversarial pass + fixed +
  mutation-proven. ⚠️ Swift/Android NOT compiled (no toolchain) — tracked pre-merge CI step. Deferred:
  #1756/#1757/#1758/#1759.
- **✅ iHYMNS FOLLOW-UP QUEUE FULLY DRAINED** (2026-08-03): #1749/#1751/#1754/#1755/#1750/#1748/#1752 all
  closed on `claude/wave3-fixes`. Every one adversarially verified (workflow lenses) + independently
  re-verified by me (suites + own mutations + live dev-DB probes). PR to `alpha` still HELD per owner.
  **NEXT: the 3 Meedya repos** (task #72 — MeedyaSuite-core #65, MeedyaManager #196, MeedyaConverter #478;
  issues already filed; implement per each repo's CLAUDE.md).

**Remaining on the branch after the queue:** the owner's next major directive, the
**3 Meedya repos** — issues FILED (core [#65], MM [#196], MC [#478]); implementation next, per repo,
once each repo's CLAUDE.md loads (they're attached + cloned in `/workspace/`, roots registered). (MeedyaSuite-core / MeedyaManager / MeedyaConverter — file an issue in each + implement
the shared media-ID + core-info model per `.claude/media-identifiers-spec.md` §5). Owner-only gate
still open: **#1726** (IntAppsAPI gateway liveness + credentials).

---

## 📌 Continuation note — 2026-07-31 (superseded by the 08-03 note above)

**Live resume point: [`.claude/sessions/2026-07-28-HANDOFF.md`](sessions/2026-07-28-HANDOFF.md)**

⚠️ **The 07-30 note below says "both `claude/*` branches are now deleted". That is STALE.** Active
work lives on **`claude/wave3-fixes`**, **~226 commits ahead of `alpha`** (2026-08-02), all pushed.
**No PR exists** — the owner wants ONE PR to `alpha`, created on their word.

**State as of 2026-08-02 (pre-release pass, final dev items):** the pre-release sweeps + IntAppsAPI
integration + wave-3/4 reopened-issue fixes are all landed. This session closed the last three
pre-merge dev items: **#1740** (`processOpenSong` parses before connecting — `071983b6`), **#1742**
(v2 `create_song` recomputes `tblSongbooks.SongCount` — shared-host prod bug — `ee326b31`) and the
**#1158** pre-merge pass (convention decision + the `test-annotation-coverage.php` baseline guard
`d01d0c5a`; the program stays open for the giant-file backfill, post-merge). 90 PHP + 45 node suites
green. Remaining before the PR: this final documentation sweep. **Post-merge:** epic **#1741**
(Musicians/Works/Tunes/Songs catalogue expansion) then the demoted comprehensive OpenAPI pass
(#1201). Owner-only gate still open: **#1726** (IntAppsAPI gateway liveness + credentials).

⚠️ **The branch named in the session prompt (`claude/apple-branches-cleanup-export-7mxhpo`) is a
DIFFERENT, older, DELETED branch.** Pushing there re-creates it on the remote and strands work away
from its history.

**This warning did not work.** It was written after the first occurrence, sat here in plain sight,
and the same mistake happened again on 2026-07-31 — the session read the "designated branch" line in
its own prompt and ran `git push -u origin <that branch>` while checked out on `wave3-fixes`. A
warning that is read *after* the mistake is a missing mechanism, not a missing note (rule #35).

**The mechanism is now `tools/githooks/pre-push`.** Install it in every fresh container — it is not
automatic, because `.git/hooks` is not tracked:

```
git config core.hooksPath tools/githooks
```

It refuses (a) any push to a known-dead branch, and (b) any push of a local branch that is not the
one currently checked out — which is the exact shape of the bug, since `git push -u origin <other>`
silently publishes that other branch's tip. Deletes are always allowed. Bypass, if genuinely
intended, is `IHYMNS_ALLOW_ANY_PUSH=1` (deliberately not `--no-verify`, which CLAUDE.md forbids).

**Suites: 80 PHP / 45 node** (re-derived 2026-08-01 — the previous "64 / 37" was stale; `ls tests/php/test-*.php | wc -l` and `ls tests/test-*.js | wc -l` are the source, never a remembered number). Both runners glob their directories, so a new suite cannot exist
without running — except in the `php-compat` matrix, which still hand-lists and therefore runs the
newest guards on one interpreter (#1682).

### What this programme is

The owner's instruction after reading the orphan audit: *"fix ALL of the issues it had identified …
We dont want to be back here again later with more issues!"* Two durable documents drive it —
`.claude/orphan-inventory-2026-07-30.md` (the mechanically-derived audit) and
`.claude/remediation-plan-2026-07-30.md` (the batch plan). Both are **claims to check**, not truth:
§4.6's table was wrong about `delete_songs`, and acting on it unverified would have stripped every
curator's delete.

Delivered so far: the permanent orphan CI guard; org CCLI licences (three disconnected stores);
setlists (no cap, tombstones, optional expiry); the #1679 songbook-move re-key plus two rounds of
hardening; seed-column-width guard; the entitlement truth-up — **all ten** decorative permissions now
wired or deleted, the allowlist's `entitlements` bucket is empty; the namespaced preference store;
and four of #1671's six features.

### The two things a new session must not mis-read

1. **NOTHING ON THIS BRANCH HAS BEEN RUN.** No MySQL, no browser. ~90 commits are reasoned correct
   and observed correct nowhere. That is **P0** in `.claude/proposals-2026-07-31.md` and it outranks
   every feature.
2. **THE METHOD LESSON** (written up in the handoff, worth reading before writing any guard): four
   consecutive rounds shipped a guard that was green while the thing it guarded was broken, because
   **source inspection was used as primary evidence for properties that have a runtime handle**.
   When a property lives in a pure function, TEST THE FUNCTION —
   `tests/php/test-transaction-fatal.php` is the worked example.

---

## 📌 Continuation note — 2026-07-30 (superseded, kept for the consolidation record)
(dated 07-28 but still being appended to as this branch's work continues; the 2026-07-26 handoff it
supersedes is still the reference for the export root-cause chain).

**The big consolidation has LANDED.** PR #1585 merged to `alpha` as squash **`887bcd2f`** — 98
commits, 228 files — carrying the whole nine-branch consolidation (Apple Phase-2 PR-11/14/15/16,
App Intents #1415, the docs branch, the public export fix family) plus the observability trio
(#1581/#1582/#1583), the deploy media guard (#1584), the Apple CI serialisation (#1558), the ad-hoc
Service Mode expiry floor (#1576), the `migrate-json.php` confirm gate, the HA integrity-audit path
fix, the Swagger UI hardening (#1587) and the repo-wide documentation overhaul (#1586).
Containment was proved by comparing blob SHAs, not commit messages.

**Both `claude/*` branches are now deleted.** The remote holds only `alpha`, `beta`, `main` and
`archive/alpha`. PR #1578 was closed as superseded before its branch went.

**Version is `0.4001.0`** — bumped in `bad5ca4f` (PR #1592).
`appWeb/public_html/includes/infoAppVer.php` is the only authoritative source. ⚠️ `version-bump.yml`
fires **only on a push to `beta`**, so an `alpha` merge NEVER bumps — that is why 98 commits shipped
under one version string and the bump had to be done by hand. Expect to repeat that after any large
alpha batch. Any version number written into a doc rots; prefer pointing at the footer or that file.

**The `claude/sotd-language-filter-typeahead-a11y` branch has since MERGED** — squashed to `alpha` as
**`bc0eb52e`** (PR #1651, "Security fixes, data-loss fixes, and the behaviour-audit programme", 56
commits). It had grown well past its two founding bugs. Both landed early: **#1593** Song of the Day
vanishing at 2+ languages (the fetch-patch read `input.url` on a `URL` object, got `undefined`, threw
across ten unrelated call sites) and **#1594** the location typeahead being mouse-only (grew into
consolidating all nine typeaheads onto one shared keyboard + ARIA combobox helper). It also carried a
long tail of further fixes — perf (#1598 `bulk_songs`, #1037 songbook slim-index listing), #1080
ChordPro inline `[chord]` markers, #1089/#1100 per-line translation toggle, #1339 Service Mode
congregant join QR, #1572 CSP (extracted `request-a-song`'s inline module — the fragment allowlist is
now **empty**), #1028/#1027/#1022 auth hardening, #1589 What's New fix, #1597 offline bulk-download
self-destruct fix, and #1612/#1615/#1618 (corrected the lyrics-cutover verifier's gate count to
**nine** gates — G1, G2, G3, G5, G6, G7, G8, G9, G10 — not ten or thirteen). CHANGELOG backfill for
this wave's gaps landed in Wave 3 below (issue #1625).

**Wave 3 (2026-07-29/30, branch `claude/wave3-fixes`, off `bc0eb52e`), 56 commits — headline:
`/manage/editor/` now serves the v2 Song Editor.** Epic **#1601** scope item 2: `manage/editor/index.php`
is now a 302 redirect to the v2 shell (forwarding the query string), closing every parity gap the
#1601 audits had found — a chords box, an Arrangement (running-order) editor, and per-line
translation/annotation panels (#1627); `?tab=`/`?songbook=`/`#number=`/`?open=` deep links, the
sidebar songbook filter + sort, `bulk_tag_detach`, and the export lines-per-slide setting (#1628,
#1680); four de-orphaned v1 `api.php` consumers ahead of retirement (#1629); and a **P0** fix (#1677)
for a bug that had made every v2 write return 403 since the v2 shell first shipped — its own client
never sent the `X-Requested-With` header api2.php requires on every POST. **v1 is deliberately NOT
retired** (scope item 3) — nothing on this branch was runtime-verified (no MySQL, no browser exist in
this build container) and retirement also needs cross-branch coordination (beta/main are a month
behind on unrelated history, sharing the one MySQL instance). Two escape hatches: `?legacy=1`
per-visit, `tblAppSettings.editor_v2_default='0'` fleet-wide (absent key means v2). Also in this wave:
setlist collaboration finished end-to-end (notify + "Shared with me" list + enforced view/edit
permission, #1638); cross-device sync data-loss fixes for capped set-list/favourite/custom-tag syncs
(#1649); an accessibility + security sweep (#1643–#1648, #1665) covering high-contrast/CVD modes dead
across all of `/manage`, a real focus-trapping Present-mode dialog, Service Mode announcements, the
setlist Arrangement editor's keyboard/touch support, SRI + vendored fallbacks for SortableJS and the
Bootstrap CDN loads, and eight admin pages whose gates didn't match their nav entitlement; an iHymns
interchange JSON importer (#1633); and a `build:proto` tool repair (#1634). Suites: 54 PHP / 34 node.
Full detail: `.claude/sessions/2026-07-28-HANDOFF.md` (still the live resume point) and
`git log origin/alpha..HEAD` on this branch for every commit body. **Now underway:** the documentation
sweep (CHANGELOG backfill for #1625, `api-docs.yaml`, in-app help, README/DEV_NOTES/PROJECT_STATUS,
this file, `MEMORY.md`) for this wave — the Wiki (`iHymns.wiki/`) is **not present in this container**
and could not be updated; that remains a tracked gap.

**Wave 2 (2026-07-29, this session):** **#1388** closed eight pre-gating leaks ahead of ever flipping
`content_gating_enabled` — the load-bearing one is the **payload-vs-asset gating** distinction:
`contentGatingApply()` (`includes/content_gating.php`) strips gated fields from JSON *payloads*
(now including `songbook_export`), while the new sibling `contentGatingMediaAllowed($kind, $userId,
$presenceToken)` gates the media *bytes* themselves (`song-media.php`, the `bulk_audio` manifest) —
plus a first-admin registration TOCTOU fix and a logout cache-clearing gap. **#1031** deleted the
sitewide `window.fetch` monkey-patch (`songbook-language-filter.js`) in favour of the shared
`apiFetch()`/`apiFetchJson()` client in `js/utils/api-client.js` — same-origin request concerns are
now attached by structure (every call site asks) rather than by a global override that only applied
where something happened to install it (an anonymous cold load of `/search` never got the language
filter at all). **#1533** setlist playback — tap any song, your own list or a shared one, to arm a
fixed bottom bar (Prev/Next, "N of M", next-song title, arrow keys, exit); shared setlists can be
navigated for the first time, fixed by replacing the by-id list lookup with a playlist *context*
carrying its own song order. **#1623** fixed Revisions Audit's "Open in editor" link (linked
`?open=`, the editor only read `?song=`) and wired `?tab=history` to auto-open the diff modal.
**#1619/#1620** followed up: deleted the now-dead `_executeInlineScripts()` router shim (nothing left
to execute) and renamed `js/modules/request.js`'s exported `Request` class to `SongRequest` so it
stops shadowing the Fetch API's global `Request` (the file itself keeps its name — it's in the
service-worker precache list). This wave's own documentation sweep landed, then Wave 3 (above)
continued on a new branch.

The 2026-07-26 caveat is **discharged**: the merged Swift compiled green on CI (`apple.yml` `build`,
run 30365569513) before #1585 merged. The **draft-PR-until-green** procedure is still the standing
practice for any PR touching `appApple/` — `apple.yml` is not a required check (#1526), so a ready
PR auto-merges on the ~45-second web lint, roughly 25 minutes before the Apple build reports.

House rules now load-bearing from this stretch of work: **rule #30** (an SPA fragment can never carry
an executable inline `<script>` — the enforcing nonce CSP refuses it *silently*, which is how the
entire public Export feature stayed dead for ~7 weeks; its `_executeInlineScripts()` mechanism is
gone as of #1619), **rule #31** (same-origin requests use `apiFetch`/`apiFetchJson`, never a
`window.fetch` override, #1031), **rule #32** (a fixed-position `<body>` UI element must tear itself
down on every SPA navigation before any early return, #1533), and the `fetch()` relative-URL red
flag. All have CI guards except #32, which is pinned by `tests/test-setlist-playback.js`'s structural
assertions instead.

> **Note on pruned handoffs:** some references below point at `.claude/sessions/*-HANDOFF.md` files
> that were pruned in `254e9744`. They remain in git history — see `.claude/sessions/README.md` for
> the two commands that list and recover them.

---

## 🎯 What Is iHymns?

A multiplatform Christian lyrics application providing searchable hymn and worship song lyrics from multiple songbooks, designed to enhance worship.

- **Domain**: [iHymns.app](https://ihymns.app)
- **Copyright**: © 2026– MWBM Partners Ltd
- **License**: Proprietary (third-party components retain their own licenses)
- **GitHub Repo**: <https://github.com/MWBMPartners/iHymns>
- **Current Version**: **`0.4001.0`** (alpha, Phase 1). The authoritative source is
  `appWeb/public_html/includes/infoAppVer.php` — `version-bump.yml` bumps it on a push to **beta**,
  so a number written into any doc goes stale within days. Historical version-by-version narrative
  (the #1010 DB-direct rewrite, the v0.550→0.770 lyrics-platform program, the 2026-05 catalogue
  refresh, the bulk-import UX batch) has moved to the historical log at the foot of this file. The
  release line on `main` sits at 0.25.2; `main` was promoted from `beta` on 2026-05-07 (PR #896).
- **Database**: MySQL 5.7+ (**142 tables**, tblCamelCase naming — counted from `appWeb/.sql/schema.sql`). All three subdomains (`dev.ihymns.app` = alpha, `beta.ihymns.app` = beta, `www.ihymns.app` = main) connect to a **single shared MySQL** at `mysql.MWBMpartners.ltd:3306` / DB name `ihymns` — confirmed via `/manage/setup-database` connection banner across all three. 2026-04 added songbook metadata extensions (#672), an Affiliation registry (#670), optional Language column (#673 → composite IETF BCP 47 with `tblScripts` + `tblRegions` in #681), `tblBulkImportJobs` async-job table (#676), and Activity Log Result/Details columns (#695). 2026-05 added the MusicBrainz-style external-links registry (#833 — `tblExternalLinkTypes` + `tblSongExternalLinks` + `tblSongbookExternalLinks` + `tblCreditPersonExternalLinks`), Works composition grouping (#840 — `tblWorks` with self-FK nesting + `tblWorkSongs` + `tblWorkExternalLinks`, plus `AppliesTo` SET widened to `'work'`), curator-editable URL → provider rule table (#845 — `tblExternalLinkPatterns`), per-component language override (#858 — `tblSongComponents.Language`), song media uploads (#853 — `tblSongMedia`), arrangement persistence (#892 — `tblSongs.ArrangementJson`), and bulk-import diagnostics (#906 + #907 — `tblBulkImportJobs.PerSongbookJson` + `PhaseLabel`).
- **API**: **≈195 JSON actions** via `api.php`, of which 189+ are documented in the OpenAPI 3.0 spec at `appWeb/public_html/api-docs.yaml` — browsable with Try-it-out at `/manage/api-docs` (Swagger UI, `view_api_docs` entitlement, hardened in #1587). Notable families: the public `action=scripts` + `action=regions` listings for native clients (#682); `?page=work&slug=…` for the Works public page (#840); the scoped DB-direct read endpoints `action=songs_index` / `action=song_detail` (#1010/#1020); the **Service Mode** endpoints `service_session_start` / `service_code_rotate` / `service_code_current` / `service_session_end` / `service_broadcast` / `service_join` / `service_poll` / `service_leave` (#1323/#1335); and the telemetry endpoint `action=client_error_report` (#1582). The editor has its own separate `/manage/editor/api.php` (legacy v1) + `api2.php` (granular v2, now the DEFAULT editor backend as of #1601 — see the Wave 3 note above) (load / save_song / songbook_export / bulk_import_zip / bulk_import_status / typeaheads / arrangement_update / bulk_tag_detach / easyworship_export) — the whole-song save lives in the shared `save_song_core.php` served by both.
- **★ Latest (2026-06-25) — branch `feat/api-native-gating`, ~25 commits, PR in flight (this session):** the **API-native-gating + content-gating program**. **(1) Extensible gating registry** (#1352, CLAUDE.md **rule #28**) — `TIER_CAPS` in `includes/access_tier_validation.php` is the single registry; a new gateable cap is **ONE line + its migration card**, stored in the additive **`tblAccessTiers.Capabilities` JSON column** (the 7 original caps keep their `TINYINT` columns; all per-tier values live in MySQL, edited at `/manage/tiers`). **(2) Server-side content-gating enforcement** (#1353) — `includes/content_gating.php::contentGatingApply()` strips gated fields (lyric body, media) from `song_detail`/`song_data`/`random`; `checkTierAccess()` is registry-driven; **ENTIRELY DORMANT + a verified no-op unless `content_gating_enabled='1'`**, fail-open + STRICT-safe. **(3) Tier-aware web/offline gating** (#1357) — `song.php` (+ the `bulk_songs` offline bundle) composes the tier axis with the entity model; presence unlock overrides tier. **(4) Read rate-limiting** (#1354) — `includes/read_rate_limit.php` + `tblReadRateLimit` on the heavy public reads (per token/IP, 429, fail-open). **(5) Robust same-origin CSRF** (CLAUDE.md **rule #29**) — `validateCsrfRequest()` in `manage/includes/auth.php`; fixes the sporadic stale-token errors; swept across ALL manage AJAX-write pages (duplicate-songs, places-api, editor, api-keys, tags, languages, activity-log, songbooks). **(6) Editor save→v2** — `manage/editor/save_song_core.php` served by both editor APIs; editor POSTs save to api2 under its CSRF gate. **(7) The full API platform** — `/manage/api-keys` usage+limits dashboard, `catalogue:read` keyed reads (`enforceReadRateLimitKeyed`), the **dormant `content:gated`** scope (per-partner licensing grant), and **self-serve key requests** (`tblApiKeyRequests` + `request_api_keys` entitlement). Plus: the `?action=songs` whole-corpus **OOM fix** (rule #17), XSS `JSON_HEX_*` hardening, **OpenAPI refreshed to 0.1250.0**, a comprehensive docs pass (fixed stale Wiki/help.php/.md + a new Wiki `Service-Mode.md`), and the version bump to **0.1250.0**. **OPERATOR — run these `/manage/setup-database` cards** (not auto-applied): **JSON-backed tier capabilities** (#1352), **Public-read rate-limit** (#1354), **Self-serve API-key requests** (Phase D), and the **#1066 API-key usage** card. **content_gating_enabled stays `'0'`** until you decide #1357 follow-ons. **STILL TODO (own efforts):** Phase-D **webhooks**; **#1358** static `/data/audio` gating (a STAGED VERIFIED rollout — `.htaccess` deny changes live playback, can't test locally). Full detail: `.claude/sessions/2026-06-25-HANDOFF.md` (+ Continuation block) + the auto-memory resume + CLAUDE.md rules #28/#29 + `.claude/api-platform-strategy.md`. *(Prior 2026-06-21 Service Mode landing recorded in `.claude/sessions/2026-06-21-HANDOFF.md`.)*

---

## 📐 Two-Phase Approach

### Phase ONE (Current) — v0.x.x (pre-release)

- Songs sourced from local `.SourceSongData/` text files
- Parsed into JSON (`data/songs.json`), then migrated into **MySQL database**
- ~30 songbooks, 12,370+ songs after the CIS scrape (#663 / 2026-04-29). Original five English: CP (243), JP (617), MP (1355), SDAH (695), CH (702); plus 23 multi-language CIS hymnals (Spanish HA, Portuguese HASD, French DLG, Russian GASD, Twi TWI, Tonga TKMN, Tswana KMK, Sotho KP, Chichewa KMN, Shona KMNz, Venda NYD, Swahili NZK, Ndebele UKE, Xhosa UKEng, Xitsonga RRV, Gikuyu NCA, Abagusii OKON, Dholuo WN, Kinyarwanda IZGI, Tumbuka NMSDA, Sepedi KKK, Bemba BKMN, English CIS) plus Misc + AH/AYS/NAH placeholder books
- Multilingual sister-site scraper expansion (#699 Phase A + B, 2026-04-30): the SDAHymnal scraper now covers 12 sites — sdah, ch, ha (es), nha (es), hasd (pt), hl (fr), ia (it), hac (sr-Latn), hp (bg), hjp (mk), pes (sr-Cyrl), pj (hr) — plus an opt-in cross-source integrity check (`--prefer-source`) that diffs ChristInSong extracts against fresh scrapes. Audit findings in `.importers/audits/2026-04-30-cross-source-integrity.md`: English sources match perfectly; HASD has ~11% real data-quality issues (Latin-1 → Latin-2 encoding corruption + OCR-style errors in CIS — SDAHymnal is the cleaner source).
- Non-English SDA scrape complete 2026-05-07 in `SourceSongData/_SDAHymnal Export 2/` — 5,167 hymns across 10 hymnals using **canonical native-script folder names** taken from sdahymnal.org's "Choose another Hymnal" picker (#901): `El Himnario Adventista (1962) [HA]_Spanish-es` (527), `El Himnario Adventista Nuevo (2010) [NHA]_Spanish-es` (613), `Hinário Adventista [HASD]_Portuguese-pt` (610), `Hymnes et Louanges, Cantiques [HL]_French-fr` (654), `Innario Avventista [IA]_Italian-it` (653), `Hrišćanske adventističke himne [HAC]_Serbian (Latin)-sr-Latn` (490), `Християнски песни [HP]_Bulgarian-bg` (300), `Христијански песни [HJP]_Macedonian-mk` (340), `Хришћанске адвентистичке химне [PES]_Serbian (Cyrillic)-sr-Cyrl` (490), `Kršćanske adventističke himne [PJ]_Croatian-hr` (490). Bracketed `[<ABBR>]` stays Latin/ASCII so it round-trips through `tblSongbooks.Abbreviation` (`VARCHAR(10)` indexed) + URL routing. Diagnosis-of-the-day (HA #331 wall): `HymnParser._depth` bug on void HTML tags (`<br>` increments depth without a matching close), not a server block — fix in PR #894.
- MySQL with mysqli prepared statements throughout (PDO removed via #554/#555; project-wide auto-memory enforces this)
- Database naming: `tblCamelCase` tables, `CamelCase` columns
- User accounts with role hierarchy (global_admin/admin/editor/user)
- User groups with version access control (Alpha/Beta/RC/RTW channel gating)
- Song requests, multi-language support, activity logging, favorites sync
- Song Editor in `/manage/editor/` (session-based auth)
- Comprehensive REST-like API for PWA and native app consumption

### Phase TWO (Future) — v2.x.x

- Songs sourced from iLyrics dB API (<https://github.com/MWBMPartners/iLyricsDB>)
- MySQL backend, Christian songs only
- Same frontend UI, different data source
- Apple TV Remote Control: iPhone/iPad controls tvOS lyrics display over LAN

---

## 🖥 Target Platforms

| Platform | Technology | Directory | Status |
| --- | --- | --- | --- |
| Web/PWA | PHP 8.5+, Bootstrap 5.3.6, Vanilla JS (ES modules), Fuse.js | `appWeb/` | Core + Enhanced complete |
| Apple (iOS/iPadOS/tvOS/visionOS/macOS/watchOS) | Swift 6.3, SwiftUI | `appApple/` | Phase 1 + Phase 2 code-complete (`iHymnsKit` SwiftPM package; watch relay, tvOS projector, Live Activities, App Intents) — consolidated and CI-compiled, unreleased; device matrices + APNs provisioning owner-gated |
| Android (+ Fire OS, Android TV) | Kotlin 2.1, Jetpack Compose | `appAndroid/` | Scaffold / in-progress (~12 Kotlin files) |

### Application IDs

- Web/PWA: `Ltd.MWBMPartners.iHymns.PWA`
- Apple: `Ltd.MWBMPartners.iHymns.Apple`
- Android: `Ltd.MWBMPartners.iHymns.Android`

---

## 🚀 Deployment & Versioning

### Branches

| Branch | Purpose | Deploys To |
| --- | --- | --- |
| `alpha` | Experimental | `public_html/` → remote `public_html_dev/` |
| `beta` | Active development | `public_html/` → remote `public_html_beta/` |
| `main` | Production releases | `public_html/` → remote `public_html/` |

### Web Directory Structure

- `appWeb/public_html/` — Single source directory (deployed to all environments)
- `appWeb/data_share/` — Shared runtime data (deployed alongside public_html). **NOTE:** the whole-corpus song read cache (`data_share/song_data/songs.json` + the SQLite mirror) was removed in WS-J #1020 — reads now go DB-direct. This dir no longer carries a materialised corpus.
- `appWeb/private_html/` — Private admin tools, song editor (separate SFTP path)

### Automated Deployment

- GitHub Actions with `lftp` for SFTP mirroring (modelled on phpWhoIs)
- lftp `--exclude` uses **regex patterns**, NOT shell globs (e.g. `\.xcodeproj$` not `*.xcodeproj`)
- All branches deploy from `appWeb/public_html/`; branch determines remote SFTP path
- `appWeb/data_share/` deployed alongside (without `--delete` to preserve runtime data)
- `.env-channel` file injected by CI for server-side environment detection
- `vars.SFTP_ENABLED` kill switch
- `[deploy all]` commit flag forces full upload
- `[skip ci]` skips all workflows

### Version Numbering

- `v0.x.x` = Phase 1 pre-release (current)
- `v1.x.x` = Phase 1 stable
- `v2.x.x` = Phase 2 (iLyrics dB integration)
- Auto-bumped via conventional commits on push to `beta` (single source of truth)
- Alpha builds display commit date timestamp (yyyymmddhhmmss) in footer

### Cross-environment data sharing

The three subdomains (`dev.ihymns.app` = alpha, `beta.ihymns.app` = beta, `www.ihymns.app` = main) connect to **one shared MySQL** at `mysql.MWBMpartners.ltd:3306` / DB name `ihymns`. There is no separate alpha-DB / beta-DB / prod-DB.

State sharing across subdomains is **asymmetric** — easy to misdiagnose if you forget which axis is per-origin and which is `.ihymns.app`-scoped:

| Layer | Scope | Notes |
| --- | --- | --- |
| MySQL row data | shared | One DB. A row written on alpha is immediately visible to beta + main. |
| `ihymns_auth` cookie | `.ihymns.app` | Set by `setAuthTokenCookie()` / `_authCookieOpts()`. Cross-subdomain auth IS designed to work. |
| `ihymns_sync` cookie | `.ihymns.app` | Lightweight settings sync (theme, font size, default songbook) via `js/modules/subdomain-sync.js`. Only path for cross-subdomain settings. |
| Bearer token in localStorage | per-origin | W3C spec — each subdomain has its own. The cookie fallback in `getAuthBearerToken()` covers the gap. |
| Service-worker cache | per-origin | Caches only 2xx responses, so a 503 maintenance page is never cached. With DB-direct reads this is no longer a "stale song data" vector for logged-in reads; it can still serve stale *static assets* per-origin until SW update. |
| Setlist / favourite / tag / history | **DB-first (signed-in)** | Since WS-F/G (#1011/#1012) these sync to MySQL on every edit (authoritative-replace + first-login MERGE backfill) — cross-device + cross-subdomain by design for signed-in users. localStorage is the offline mirror, not the source of truth. Anonymous users remain local-only. |

When debugging "subdomain X has the data but Y doesn't", follow the diagnostic sequence in `.claude/project-rules.md` Section 14.3.

---

## 🎨 Design

- **Colour scheme**: Clean neutral slate/grey — professional, easy on the eyes
- **Navbar**: Solid dark slate `#1e293b`, no gradient
- **Songbook cards**: ALL same soft grey gradient, no rainbow
- **Accent**: Muted teal `#0d9488`
- **Dark mode**: Charcoal blue `#0f172a`
- **Colourblind mode**: CVD-safe palette (Wong 2011)
- **Accessibility**: WCAG 2.1 AA, skip-to-content, focus indicators, reduced motion

---

## 📏 Development Standards

- **PHP**: 8.5+ with `declare(strict_types=1)`, `str_contains()`, match expressions
- **JS**: ES modules architecture (25+ modules in `js/modules/`, utilities in `js/utils/`)
- **Security**: Content Security Policy with per-request nonces, SRI hashes on CDN resources
- **Analytics**: GA4, Plausible, Clarity, Matomo, Fathom — GDPR consent banner required
- **Accessibility**: WCAG 2.1 AA, automated badge contrast via relative luminance
- **Detailed code annotations**: Comments on every code block (ideally every line)
- **Modular architecture**: PHP components (`includes/components/`), JS ES modules
- **Automated copyright year**: `© 2026–<current year>` resolved at runtime
- **Clean code**: All linting/security checks must pass with zero issues

---

## ✅ Standing Tasks (After Every Prompt)

1. Create GitHub Issue before work; close when done
2. Run syntax/lint/security checks; fix ALL issues
3. Ensure accessibility compliance
4. Update ALL documentation (README, CHANGELOG, PROJECT_STATUS, DEV_NOTES, help, .claude/)
5. Update .gitignore
6. COMMIT changes (push only when asked)
7. Clean up temp files
8. Keep `data/songs.schema.json` in sync with any `songs.json` structure changes (#226)

---

## 🗂 Key Files

| File | Purpose |
| --- | --- |
| **MySQL `ihymns` DB** | **Canonical song database (single source of truth).** Every runtime read is DB-direct via `SongData` / `getSongsSlimIndex()` / `getSongs($abbr)` / `getSongById()`. |
| `data/songs.json` | Ingestion **seed/build artifact** only (source-files → parse → DB). NOT a runtime read cache — that was removed in WS-J #1020. |
| `data/songs.schema.json` | JSON Schema (draft 2020-12) for the ingestion songs.json validation (#226) |
| `tools/parse-songs.js` | Parses .SourceSongData/ → songs.json (ingestion seed) |
| `tools/build-web.js` | Web build/packaging script |
| `appWeb/public_html/includes/infoAppVer.php` | App version metadata |
| `appWeb/public_html/includes/components/*.php` | Modular PHP components |
| `appWeb/public_html/includes/pages/*.php` | Page templates (song, writer, privacy, terms, settings) |
| `appWeb/public_html/js/modules/*.js` | ES modules (router, analytics, gestures, settings, etc.) |
| `appWeb/public_html/js/utils/*.js` | JS utilities (html.js, text.js) |
| `appWeb/public_html/js/constants.js` | Centralised localStorage key constants (#139) |
| `appWeb/public_html/api.php` | Server-side API (songs, setlists, search, user auth, password reset) |
| `appWeb/public_html/og-image.php` | Dynamic OG image generator (1200×630, contextual song images) |
| `appWeb/public_html/sitemap.xml.php` | Dynamic XML sitemap from song database |
| `appWeb/public_html/includes/config.php` | App configuration (analytics, features) |
| `appWeb/public_html/manage/includes/auth.php` | Authentication middleware with role hierarchy |
| `appWeb/public_html/includes/db_mysql.php` | Single mysqli connection factory (`getDbMysqli()`) shared by main app + admin since #555 |
| `appWeb/public_html/js/modules/user-auth.js` | Public user auth (register, login, sync, password reset) |
| `appWeb/public_html/js/utils/components.js` | Shared song component tag utility (12 types) |
| `appWeb/private_html/editor/` | Song editor (dev tool) |
| `appApple/iHymns/iHymns/Services/AppInfo.swift` | Apple app info |
| `appAndroid/.../AppInfo.kt` | Android app info |
| `tests/test-song-parser.js` | 33 unit tests |

---

## 📝 SFTP Secrets Required

| Secret | Purpose |
| --- | --- |
| `SFTP_HOST`, `SFTP_USER`, `SFTP_KEY`/`SFTP_PASSWORD` | Server connection |
| `SFTP_LIVE_PATH`, `SFTP_BETA_PATH`, `SFTP_DEV_PATH` | Deploy directories |
| `SFTP_PRIVATE_PATH` | Song editor deploy directory |
| `SFTP_ENABLED` (Variable) | Kill switch (`true` to enable) |

See `DEV_NOTES.md` for full setup guide including Apple, Android, and Fire OS.

---

---

## User Account System

### Role Hierarchy (highest to lowest)

| Role | Level | Capabilities |
| --- | --- | --- |
| `global_admin` | 4 | All powers, auto-assigned to first user |
| `admin` | 3 | Manage users (assign roles up to admin) |
| `editor` | 2 | Edit songs via /manage/editor/ |
| `user` | 1 | Save setlists centrally, cross-device sync |

- Each role inherits capabilities of roles below it
- Non-logged-in (anonymous) users: local-only setlists (localStorage)
- Public API uses bearer tokens (64-char hex, 30-day expiry)
- Admin panel uses PHP sessions (session-based auth)
- Password reset via secure tokens (48-char hex, 1-hour expiry, single-use)
- Future: SIGNula ID integration

### Custom Song Arrangements

- Per-song arrangement editor in setlists (ProPresenter 7-style)
- 12 component types with short tags: V, C, R, PC, B, T, CD, I, O, IL, VP, AL
- Drag-and-drop reordering, auto-generate, sequential reset
- Arrangements persisted in setlist data and shared setlist links

---

Last updated: 2026-05-04 — refreshed at the close of the #840–#852 catalogue-refresh batch:

- **#840** — Works composition grouping (`tblWorks` with self-FK unlimited nesting, optional ISWC, member-songs across the catalogue, public `/work/<slug>` page, "Part of work" panel on song pages, admin CRUD at `/manage/works`).
- **#841** — Global URL → provider auto-detect for the external-links card-list editor (`js/modules/external-link-detect.js`, exposed on `window.iHymnsLinkDetect`, loaded on every `/manage/*` page).
- **#842** — Responsive admin list-view convention (`.admin-table-responsive` + `data-col-priority="primary|secondary|tertiary"`). Opted in: Credit People, Songbooks, Songbook Series, Works.
- **#843** — Comprehensive docs refresh (visitor in-app help, admin in-app help, `DEV_NOTES.md`, `CHANGELOG.md`, OpenAPI `Work` + `ExternalLink` schemas).
- **#844** — Sortable headers across every admin list page (10 pages opted in).
- **#845** — URL-detect rules moved into MySQL (`tblExternalLinkPatterns`); new `/manage/external-link-types` curator-editable CRUD page; JS module reads patterns from `window._iHymnsLinkTypes[].patterns`, falls back to bundled `RULES` on pre-migration deployments.
- **#846** — Bulk-promote in-use Credit People into the register (Levenshtein + token-set Jaccard fuzzy-match, single-transaction submit with shared `bulk_run_id`).
- **#848 / #849** — Hotfixes for #847's two follow-on bugs (migration cards not rendering on no-action visit; CI guard tripping its own block-comment).
- **#850 / #852** — CI/auto-merge plumbing made resilient: workflow tolerates `gh pr merge --auto` non-zero exits on fast-mergeable PRs; `Lint & Validate` now runs on every PR (no path filter on the `pull_request` trigger), so workflow-only / docs-only PRs can no longer deadlock auto-merge.

Active in-flight items deferred from earlier batches (will land in their own PRs):
- **#706** — Songbook cascade-delete with two-step confirmation modal.
- **#707** — Org-admin role + per-org member/licence management at /manage/my-organisations.
- **#709** — tblUserSetlists empty despite migrations + legacy JSON files not imported.
- **#713** — Rolling Manage-area sweep tracker for catch-all-with-error_log-no-logActivityError pattern.
- **#719** — Comprehensive API parity audit + OpenAPI refresh + in-app docs + Wiki refresh.
- **#722** — Schema Audit drift: 3 uncovered columns + 18 orphans-in-DB.

New deferred items from the 2026-05 batch:
- **#838** — credit-people external-links editor on the new schema (legacy `tblCreditPersonLinks` still read-fallback).
- **#839** — chip-list editor for song external links in `/manage/editor`.

---

## 🗄 Historical log (superseded — newest entry first)

> **Read this section as history, not as current state.** What follows is a stack of dated
> "Last updated" narratives from earlier sessions, appended over months without pruning. Each was
> accurate on its date and several contradict each other (and the header above) on version numbers,
> table counts and platform status. When they disagree with the block at the top of this file, or
> with `.claude/MEMORY.md`, **the top of this file wins** — and when a fact matters, read it from the
> code (`infoAppVer.php`, `schema.sql`, `admin-links.php`) rather than from any document.
>
> Kept because the *reasoning* is valuable: why a schema batch was shaped the way it was, what a
> workstream was trying to fix, which alternatives were rejected.

Last updated: 2026-05-10 — refreshed at the close of the post-#852 catch-up batches (v0.50→v0.110):

### Major batches landed since 2026-05-04

**Activity-log + auth-resilience cluster (#917–#931).** Real email delivery for magic-link, reset, register, admin force-reset (#922 closes #898 P0/security); per-request rows + IPv6/proxy/VPN resolution in `tblActivityLog` (#919); every uncaught throwable + PHP fatal mirrored to the activity log (#918); editor 5xx error detail surfaced in the toast (#927); defensive `bindParamSafe()` wrapper that prevents the silent activity-log regression class of bug (#928, retrofit of `activity_log.php` after `'isssssssssssssi'`-vs-14-placeholder typo); migrations use `getDbMysqli()` instead of bogus `MYSQL_HOST` constants (#930).

**Songs static cache (#933).** Replaces on-demand `SongData::exportAsJson()` rebuild on every editor open / PWA cold-start with a precomputed on-disk cache regenerated on save. Per-request peak memory drops from ~140 MB to <2 MB; wire size drops from 5.96 MB to 928 KB on gzip-9. Save-hook regen wired into editor `save_song` + four bulk-import flows; manual regenerate button on `/manage/data-health`. Reverts #931's 512 MB band-aid.

**Credit-people structured-name split (#935).** Adds `FirstNames` / `Surname` / `Suffix` columns to `tblCreditPeople` alongside the canonical `Name`. Backfill heuristic peels Jr/III/PhD trailing suffixes; comma-inverted "Wesley, Charles" handled. Group / special-case rows leave the three columns NULL. `Name` is recomposed on individual saves so all 30 read sites keep working unchanged.

**Quick-wins batch (#948).** Seven commits in one PR: rebuild-bug fix on `/manage/song-link-suggestions` (script lived at project-root `tools/` but only `appWeb/public_html/` deploys — #937); inline labels on `/manage/my-organisations` licence-row inputs (#936); visual separation between media-kind blocks in the Song Editor's Media tab (#938); design-intent doc-comment for the future PD-gating tier (#939, lyrics-PD and music-PD must be checked independently); public song-page metadata becomes navigation — Tune name → `/tune/<slug>`, CCLI # → SongSelect new tab, ISWC → `/iswc/<code>`, plus credits-block parity + Works-graph translations (#940); Catalogues many-to-many song grouping concept (#941, schema + admin CRUD at `/manage/catalogues`); one-shot Works backfill from existing ISWC values (#942).

**Docs port + version bump (#950).** Recovered durable engineering content stranded on the auto-undeleted `claude/chore-claude-context-sync` branch into `.claude/project-rules.md` Section 14 (HTTP-block triage, void-element parsers, per-origin browser state) and a Cross-environment data sharing subsection here. Version 0.100.0 → 0.110.0.

**Centralised link styling (#952).** Kills Bootstrap default `<a>` blue + solid-underline site-wide; replaces with `.song-meta-link`-style muted dotted-underline + accent on hover. Bootstrap component classes (`.btn`, `.nav-link`, `.dropdown-item`, `.breadcrumb-item a`) keep their styling via specificity. Credits-block author / composer footer is now clickable too (parity with the header).

### In-flight (PRs awaiting merge)

- **#954** — one-line catalogues dark-mode regression fix (will be superseded by #956).
- **#956** — admin pages obey user theme preference (Light / Dark / High-contrast / CVD / System) via the new `admin-theme-init.php` synchronous resolver in `<head>`. Drops hardcoded `data-bs-theme="dark"` from every admin page; admin-nav theme dropdown now persists to `localStorage.ihymns_theme` and round-trips with the public site.
- **`claude/fix-pending-migrations-Vj4SQ`** — migration counter never reached zero on `/manage/setup-database` because five probes were hard-coded `=> true`; replaced with smart probes that detect actual completion. Schema.sql sync added the 18 tables + 10 columns previously orphan-in-DB on the audit page (Catalogues, Works, ExternalLinks family, Multi-language, Songbook Series, Compilers, SongMedia, Alternative Titles + the 10 column-additions on tblUsers/tblSongbooks/tblBulkImportJobs/tblSongs/tblCreditPeople/tblSongComponents). Schema-audit page now surfaces uncovered + missing columns by name in an "Action items" card directly under the banner. Two new CI tests guard the regression classes: `tests/php/test-migration-registry.php` (every `$migrationOrder` slug has a matching `$migrationProbes` entry; no probe is the always-true placeholder) and `tests/php/test-schema-coverage.php` (every migration-created table/column is mirrored in `schema.sql`). CLAUDE.md gained checklist entry 19 + two red-flag bullets codifying the discipline. Run both locally via `bin/audit-schema`.

### New tracking issues (deferred, full design captured)

- **#943** — Works ISWC API integration (ISWCnet + MusicBrainz + MRO IDs).
- **#944** — UI i18n + Translator role + Roles admin area.
- **#945** — Naming cleanup: User Groups / Access Tiers / Roles / Entitlements / Licence Types vocabulary audit.
- **#946** — Analytics expansion (user/referral/entry-exit) + external platform integration (GA4 / Plausible / Matomo).
- **#947** — Login forms: Cloudflare Turnstile / reCAPTCHA / hCaptcha admin-configurable CAPTCHA.

### Open priority items

1. **#945 (naming cleanup)** — most-impactful of the deferred large issues; every other large issue (especially #944 i18n) benefits from clearer vocabulary first.
2. **DB-isolation diagnostic queries** from `project_db_environment_isolation_open.md` memo — cheap to run now that #898's email delivery is live; confirms whether the missing-user-signup hypothesis is closed.

---

Last updated: 2026-06-03 — **DB-direct data-layer rewrite complete + custom error pages + codebase hardening pass.** Branch `claude/db-direct-data-layer`, version `0.200.0`. NOT yet PR'd to alpha (owner gates the push).

### The data-architecture fix (epic #1010 — root cause + remedy)

**Root cause** of the long-running "alpha has the data but main doesn't" class of bug: song *reads* never touched MySQL. They served a **per-environment `songs.json` file cache** (`data_share/song_data/songs.json`, built writes-only on save). Each subdomain had its own file, so a row written on alpha was invisible on main until that env's file was regenerated — staleness was structural, not a SW-cache fluke. Remedy (owner's emphatic decision): **rip out every JSON/SQLite file read cache; make every read DB-direct; make setlists/favourites DB-first with auto-sync.** Full design in `.claude/data-architecture-remediation.md`.

### Workstreams shipped (WS-A → WS-K)

- **WS-A #1014 / WS-B #1015** — live DB search + live Song-of-the-Day (no corpus materialisation).
- **WS-C / WS-D / WS-E** — DB-direct read paths across song/songbook/tune/iswc/work/person pages; live songbook-name JOINs (no denormalised columns; #1013).
- **WS-F / WS-G #1011/#1012** — setlists, favourites, custom tags, view-history are now **DB-first auto-sync**: authoritative-replace per edit (deletions propagate, LWW) + first-login MERGE backfill (union, no loss); `_syncReady` gate arms destructive replace only after merge hydrates the cache; server-side tag-union in merge mode. New `tblUserFavorites.Tags` (JSON) + `tblUserCustomTags`; migration `migrate-user-data-sync.php`.
- **WS-I** — PWA offline uses a **slim song index** only; the whole corpus is precached **nowhere**.
- **WS-H** — lightweight DB-direct paginated song index endpoint (#1012).
- **WS-J #1020** — **decommissioned** `includes/songs_cache.php` + `SongData::exportAsJson()`; deleted `data_share/song_data/songs.json` + the SQLite mirror; editor `?action=load` → `songbook_export` (`getSongs($abbr)`); `SongData` is DB-only (constructor throws if no DB; no JSON fallback).
- **WS-K #1021** — **system maintenance mode** (`includes/maintenance.php`): admin toggle in `/manage/configuration`, themed 503 intercept in `index.php` + `api.php` (exempts `/manage/*` entry point, `app_status`, and `auth_*` so admins can never lock themselves out), `isDbConnectionFailure()` turns an unreachable DB into a graceful 503 — never stale data.

### Custom error pages (theme-aware, PWA-offline-capable)

`includes/error_page.php` (`renderErrorPage` / `renderErrorFragment` / `renderContentGatedFragment`) + standalone `error.php` (status whitelist 400/401/403/404/429/500/502/503). Page fragments (song/songbook/work/person/not-found) render in-theme 404s; bootstrap + maintenance failures render a themed 503; service-worker `OFFLINE_FALLBACK_HTML` is theme-aware so offline errors still match light/dark/HC. `.htaccess` `ErrorDocument` 403/500/503 → `/error.php`. Forward-looking **gated-lyrics** fragment wired into `song.php` behind the `content_gating_enabled` flag (copyrighted-lyrics gating is under consideration).

### Codebase hardening pass (the queued 7-task program)

1. **Lint/validity sweep** (`4acf696b`) — fixed an undefined-`$db` TypeError on successful VideoPsalm bulk-import in `editor/api.php`.
2. **Security audit** (`ca5aefb7`) — added postMessage origin+source validation to `storage-bridge.js` (CWE-346) and a same-site `_manageSafeRedirect()` guard on `manage/login.php` (CWE-601). Rest of the codebase verified clean (no SQLi/XSS/IDOR/committed-secrets).
3. **API/OpenAPI + help docs** (`26889b41`) — `api-docs.yaml` version 0.57→0.200, 6 newly-documented endpoints (song_detail/songs_list/song_links/catalogue_language_subtags/auth_verify_email/auth_update_avatar_service) + 5 new `app_status` fields; help-page Favourites cross-device-sync note.
4. **GitHub issues audit** — 325 open issues classified against the live codebase (17-agent workflow); ~178 verified-implemented/obsolete closed with per-issue evidence comments (excluding the unmerged #1010–#1021 epic and #4 never-built genre filter).
5–6. **`.claude/` + memory refresh** (this update) and **enhancement-issue backlog** creation.

### Deferred hardening (tracked as new enhancement issues)

CDN SRI on Bootstrap/Tone.js/pdf.js; SW message-handler validation; unguessable storage-bridge request IDs; editor-API OpenAPI coverage gap; content-gating auth-on-page-fetch + cache-exclusion follow-ups.

---

Last updated: 2026-06-05 (cont. 2) — **Adversarial security + WCAG 2.2 audits (fixed), W3C validity fixes, channel-gate lock-out fix, sheet-music design, and the standing-tasks governance convention.** Branch now **85 commits ahead of `origin/alpha`, never pushed**. _(Version note: the authoritative app version is **0.550.0** per `infoAppVer.php`; earlier mentions of "0.200.0" in this brief are stale.)_ Security audit → fixed a CRITICAL SQL-injection in the EasyWorship importer + `.mxl` path-traversal + 4 role→entitlement checks (`2e1f31ff`); legacy file-editor risk filed #1157. WCAG 2.2 audit (57 confirmed) → fixed SPA focus-management, `:focus-visible`, `aria-live`, and a 24px target-size floor (`94736c16`+`73806ae8`); full checklist on #1151. W3C → 5 `index.php` ARIA-on-div validity errors fixed (`71d00e6b`). **Global Admin now always bypasses the invite-only channel gate** so it can't lock itself out (`c9982cb4`). Sheet-music score-attach designed (#1155, FRBR tune→work→song→arrangement) + MIDI→MusicXML (#1156). **Governance:** the after-work consistency discipline is now policy — `.claude/standing-tasks.md` + CLAUDE.md § "Standing consistency tasks" (annotate code + update Issues/Milestones/Wiki/.md/.claude every time); new `SECURITY.md` + `LICENSING.md`; README refreshed; #1158 (annotation backfill) + #1159 (issue sweep) programs; 4 Milestones created. Full detail: `.claude/sessions/2026-06-05-HANDOFF.md` Continuation 2.

Last updated: 2026-06-05 (cont.) — **Home-UX rethink + import parsers + design rethinks (OpenLyrics, gating).** Branch now **78 commits ahead of `origin/alpha`, never pushed**. Six further local commits this session: the flat ISO-code language wall → a compact searchable dropdown picker (#1149, `resolveLanguageMeta()` added to `language_names.php`, fixes home + `/songbooks`); the Browse-by-Theme wall → a Top-8 "Popular Themes" strip with counts via a new `popular_tags` endpoint reusing `manage/tags.php`'s UseCount JOIN (#1148); the existing `'home'` card-layout surface wired into the public home via **client-side hydration** (`applyCardLayout()` in `card-layout.js` — the home fragment is shared-cache so the server can't emit a per-user order) (#448 reopened); a home heading-outline a11y fix; and a tested **MusicXML parser** (`includes/MusicXmlImporter.php` + `tests/php/test-musicxml-parser.php`, 25 assertions) for #1096 (parser only — editor wiring + DB write + real-export validation still TODO). Design rethinks logged as issues, not code: OpenLyrics (#1152 seed the 164-theme CCLI/OpenLyrics `themelist.txt`, #1153 dedupe-safe repo import, #1154 techniques-to-borrow; never-overwrite-`Verified` guard added to #881/#1052) and the **feature-gating system** (#946 expanded from a rename pass to a 4-axis structural model — Staff capability / Content access / Tenancy / Build channel — plus a per-user "Effective access" inspector; verified-dead `tblUserPermissions` + orphaned group channel flags flagged for removal; superseded #642). Full detail: `.claude/sessions/2026-06-05-HANDOFF.md` continuation section.

Last updated: 2026-06-05 — **Multi-format interchange + lyrics-ingest program: one-pass forward-looking DB schema shipped (two batches) + cross-repo issue map.** Branch `claude/db-direct-data-layer`, still local-only (owner gates the push). This continues the multi-format worship-software import/export + TTML lyrics-ingest program (the v0.550 lyrics-platform work spanning iHymns / iLyricsDB / MeedyaDL), with the **schema deliberately shipped in two additive, idempotent, CI-green batches so we never run multiple migration rounds as the features are built**. Both batches were produced by a design → adversarial-stress → implement → verify workflow (the same discipline #1010 used). Reads stay DB-direct (rule #17); all new tables are additive and dormant until their consuming feature lands.

### Schema batch A — #1066 one-pass (interchange fidelity · ingest hardening · identity)

Six migrations under `appWeb/.sql/` (each mirrored to `schema.sql` + one `migration-registry.php` entry with a **real** probe; both CI guards green; FK types/collation verified; adversarial verification verdict **SHIP**):

- `migrate-interchange-fidelity.php` — `tblSongComponents.ChordsJson` + `.NotesJson` (lossless chord + presenter-note round-trip), `tblSongArrangements` (named component reorderings the PP7 exporter reads via `IsDefault=1`; carries `Description`/`KeySignature`/`CapoFret`).
- `migrate-ingest-review-queue.php` — `tblLyricsConflicts` + `tblLyricsReviewQueue` (moderation gate between ingest and the read path; `AssignedTo` for multi-curator claim).
- `migrate-api-key-hardening.php` — `tblApiKeys.RateLimitPerMin/PerDay`, `tblApiKeyUsage` (rolling counters; `Scope` reserved in the unique key for per-endpoint limits), `tblApiKeyIdempotency` (safe-retry cache; `ExpiresAt` is **DATETIME**).
- `migrate-song-normalized-title.php` — `tblSongs.NormalizedTitle` + index + **backfill** via `ihymns_normalize_title()` (indexed dedup/match pre-filter; PHP still does the exact compare).
- `migrate-song-link-confidence.php` — `tblSongLinkSuggestions.Confidence` (ENUM high/medium/low) + `Signal` (VARCHAR).
- `migrate-song-identity-map.php` — `tblWorks.MusicBrainzWorkMBID` (composition identity on the work), `tblSongIdentityMap` (recording identity: ISRC/MusicBrainz/Spotify/Genius — **SongId is a non-unique index**, a song maps to many recordings; uniqueness on the external-id columns), `v_ChristianSongs` (slim **`SQL SECURITY INVOKER`** read fence, id/title/songbook/flags only). Change history → `tblActivityLog` (no dedicated table).

GATED (NOT shipped — blocked on the DB-merge decision, #1010 follow-on): the iLyricsDB bridge (`tblSongIdentityMap.ILyricsDBSongId` + bridge views). Issues #1085/#1086.

### Schema batch B — #1088 per-line lyric enrichment

`migrate-line-enrichment.php` (+ schema.sql + registry, both CI guards green, migration↔schema.sql drift-checked identical):

- `tblLyricLineTranslations` — per-line meaning **translation** + **romanization/transliteration**, modelling the Apple Music TTML `<translation>`/`<transliteration>` head tracks. Anchored on `tblLyricLines.Id` (BIGINT). Natural key `(LineId, TargetLanguage, Kind, Source)` admits Apple + human + machine and both kinds on one line/lang; `IsPrimary` picks the display row; `IsAutoGenerated` drives a machine-translation badge.
- `tblLyricLineAnnotations` — Genius-style **explanatory gloss** over a span. `StartLineId` (+ nullable `EndLineId`) + nullable `StartOffset`/`EndOffset` (0-based UTF-8 code-point, exclusive end) express sub-line phrase / whole-line / multi-line with no later ALTER. First-class indexed `IsVerified` badge. (Per-user auditable voting deferred to a future `tblLyricAnnotationVotes` table.)

Both anchor on the normalized `tblLyricLines` read path (distinct from `tblSongTranslations` = whole-song, `tblSongComponents.NotesJson` = presenter notes). All growable/moderation vocab is **VARCHAR not ENUM**; language tags are free-text `VARCHAR(35)` (no FK to `tblLanguages`) so TTML/LRC script subtags never RESTRICT-fail ingest; CASCADE to the line/lyrics, SET NULL to users; `(Source, SourceRef)` makes re-import idempotent.

### New schema objects (add to the DB summary)

Batch A tables/views: `tblSongArrangements`, `tblLyricsConflicts`, `tblLyricsReviewQueue`, `tblApiKeyUsage`, `tblApiKeyIdempotency`, `tblSongIdentityMap`, `v_ChristianSongs` (+ columns on `tblSongComponents`, `tblApiKeys`, `tblSongs`, `tblSongLinkSuggestions`, `tblWorks`). Batch B tables: `tblLyricLineTranslations`, `tblLyricLineAnnotations`.

### Cross-repo issue map (created this session)

- **iHymns:** epic **#1066** + schema #1067–#1072 + features #1073–#1084 + gated #1085/#1086 + G-Presenter **#1087**; line-enrichment schema **#1088** + feature **#1089**.
- **iLyricsDB:** **#147** (dual-mode offset/tempo `<10`=tempo float / `≥10`=percent + syllable-level timing, extends #13); **#148** (per-line translations + annotations parity for the shared core).
- **MeedyaDL:** **#908** (Idempotency-Key push) + **#909** (populate identity signals on push).
- **G-Presenter (#1087):** author has offered a **`.json`** format file — importer/exporter will target G-Presenter JSON once the sample lands (blocked-on-sample, like MediaShout was).

### Durable schema conventions reinforced this session

- **One-pass forward-looking schema:** for a feature family, design the *final* DDL up front (design → adversarial "what forces a second migration?" stress → implement), so additive tables ship once and sit dormant until consumed. No incremental ALTER rounds as the feature is tweaked.
- **VARCHAR not ENUM for any growable / moderation vocabulary** (`Status`, `Kind`, `AnnotationType`, `Signal`, conflict/queue/review vocab) — app-validated against a central map. An ENUM addition is an `ALTER` = the second migration we forbid.
- **Per-line lyric enrichment anchors on `tblLyricLines.Id`** (BIGINT), the normalized read path — not the index-fragile `LinesJson`/`NotesJson` parallel arrays (those are right only for presenter notes/chords).
- Idempotent external re-import via a `(Source, SourceRef)` UNIQUE (multiple NULLs allowed → manual rows coexist).

### Status / next

All 11 working-tree files (8 batch-A + `migrate-line-enrichment.php` + the two shared `schema.sql`/`migration-registry.php`) are **staged-to-commit but NOT yet committed** — awaiting owner go-ahead. Proposed: one atomic commit per batch (or one combined), explicit pathspecs, no push. On alpha apply: the new `/manage/setup-database` cards drive to zero-pending; the NormalizedTitle backfill recomputes all ~3,612 rows (re-runnable). Feature/app code (ingest wiring, curator UI, display, exporters) is tracked by the issues above — none shipped yet.

---

Last updated: 2026-06-10 — **Duplicate & Counterpart Review (epic #1215): the `/manage/duplicate-songs` page unified with the cross-book link-suggestions workflow.** Branch `claude/jolly-heisenberg-gb3hzn` (off alpha). Six commits, **no schema migration** — reuses the dormant `tblSongLinkSuggestions.Confidence`/`Signal` from #1066 + the existing `tblSongLinks` / `tblSongLinkSuggestionsDismissed`.

- **New shared scorer `includes/song_similarity.php` (#1216).** Extracted the builder's private `_bsls_*` maths into one module (`ihymns_sim_normalise` / `ihymns_sim_text` / `ihymns_sim_authors_jaccard` / `ihymns_sim_score` / `ihymns_sim_confidence_tier`). `ihymns_sim_score()` centralises the 0.50/0.35/0.15 title/first-line/authors blend + a hard-ID override (shared ISWC/CCLI/ISRC → certain match) and finally populates `Confidence`+`Signal`. **Two** normalisers remain, clearly named: `ihymns_normalize_title()` = exact dedup fold (grouping/NormalizedTitle/ingest), `ihymns_sim_normalise()` = fuzzy-compare fold (also strips leading articles). Unit test `tests/php/test-song-similarity.php` (22 assertions).
- **`/manage/duplicate-songs` rewritten (#1217/#1218/#1219).** Union-find clustering over exact-title + hard-ID + shared-URL + fuzzy-suggestion edges; heavy fields (first-line/authors/lyrics-count) fetched for **candidate members only** (rule #17). Three sections by book span (cross-book / same-official-songbook / same-collection); per-group probability % + signal chips. Same-official-songbook merges guarded (type-to-confirm + server `force` flag). Cross-book bulk **Link** writes `tblSongLinks`; per-group **Dismiss** writes `tblSongLinkSuggestionsDismissed` (a cluster is suppressed only when ALL its pairs are dismissed); **Rebuild** re-runs the builder in-process.
- **Per-action entitlements + nav unification (#1220).** Page view + Link + Dismiss = `edit_songs`; Merge = `manage_duplicate_songs`. `/manage/song-link-suggestions` → 302 redirect; nav relabelled "Duplicates & Links". Editor inline "Suggested counterparts" panel untouched (reads the API). CLAUDE.md gains checkpoint #22.
- **Deferred (#1221, for consideration):** `tblSongIdentityMap` signal, first-line fingerprint + incremental rebuild, dedicated review-dismissal table, merge-two-groups, cross-language detection.

NOT pushed beyond the feature branch; targets alpha. No `infoAppVer` bump (left to the beta auto-bump). **Pre-existing unrelated test drift** noted: `tests/php/test-opensong-parser.php` + `test-videopsalm-parser.php` fail because their `OPENSONG PARSER` marker no longer exists in `editor/api.php` (parser relocated) — predates this branch, untouched by it.

---

Last updated: 2026-06-10 (cont. 2) — **Standard theme vocabulary + canonicalisation + "Collections" rename merged to alpha (#1152 / #1222 / #1223, PR #1226, merge `ce0d392`); the unofficial-songbook badge — the last open #1223 item — in flight on branch `claude/unofficial-songbook-badge-gb3hzn` (off alpha).**

- **Theme vocabulary (#1152).** `appWeb/.sql/migrate-seed-theme-vocabulary.php` seeds the OpenLyrics `themelist.txt` / CCLI SongSelect taxonomy into `tblSongTags`. Additive, idempotent columns: `ParentId` (self-FK → 2-level Parent/Child hierarchy), `CcliThemeId` (dormant SongSelect number), `Source` (`'curator' | 'ccli-openlyrics'`). Leaf name in `Name`, path via `ParentId`; same-named curator tags promoted to the standard source, not duplicated. Surfaced with its hierarchy on `/manage/tags`. `schema.sql` + `migration-registry.php` updated; CI green.
- **Canonicalisation (#1222).** `/manage/tags` now suggests folding curator-added variant tags into their standard theme (shared `includes/song_similarity.php` scorer), reusing the existing irreversible Merge (variant = source/deleted → standard = target).
- **Collections rename (#1223).** "Catalogue" → "Collection" in user-facing admin copy + the nav entry **only**; the table (`tblCatalogues`/`tblCatalogueSongs`), the `/manage/catalogues` route, `admin.catalogues.*` log keys, the `'catalogue'` entity type, form fields and the `manage_songbooks` entitlement all stay `catalogue` **internally**. Vocabulary tracked in #945.
- **Unofficial-songbook badge (#1223 — this branch).** Server-rendered `.songbook-unofficial-badge` beside the abbreviation on the home tiles, the `/songbooks` list and the songbook header, shown when `tblSongbooks.IsOfficial = 0` (schema `DEFAULT 0` = "curated grouping / pseudo-songbook"). Presentation-only: `getSongbooks()` already returns official + unofficial books (no filter — only 0-song books are hidden); no storage merge (a song's home is `SongbookAbbr NOT NULL`, so a merge would orphan its songs). Bootstrap 5.3 `warning-subtle` tokens (theme-aware); "Unofficial" folded into each tile's accessible name; colour never the sole signal (WCAG 1.4.1). Four files: `includes/pages/{songbooks,home,songbook}.php` + `css/app.css`. **No schema change.** CLAUDE.md gains checkpoints #23 (theme vocabulary) + #24 (songbook official/unofficial + "Collection" naming) and three red-flag bullets. CHANGELOG + this brief updated in the same PR (the #1226 work had shipped code-only).

---

Last updated: 2026-06-10 (cont. 3) — **Version → 0.990.0; badge merged + `/manage/duplicate-songs` blank-page fixed; `.claude/` refreshed for cross-device dev.**

- **Version bump → `0.990.0`** (`includes/infoAppVer.php`; was 0.880.0). Owner-requested; the PWA service-worker cache version auto-syncs off this value (#81), so the bump alone forces clients to refresh.
- **Unofficial-songbook badge merged** — PR #1227 → `alpha` (squash `da3ab73`), auto-merged; content verified byte-for-byte on alpha. Issues #1152 / #1222 / #1223 closed (completed).
- **#1228 / PR #1229 — `/manage/duplicate-songs` rendered a fully BLANK page on alpha; fixed.** Root cause: the DB layer runs mysqli under `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` (`includes/db_mysql.php`), so a failing query **throws** `mysqli_sql_exception` — it does NOT return false. The GET detection path was written for *false-return* semantics (`if ($res) {…}`) with no try/catch, so any DB error white-screened it. Trigger: `$suggTableExists` probed `tblSongLinkSuggestions` but gated a read of the newer, **unmigrated-on-alpha** `tblSongLinkSuggestionsDismissed`. Fix: independent existence-probes per optional table + drop the `NOT EXISTS(dismissed)` pre-filter when that table is absent + a top-level try/catch → actionable error card, never blank. Squash `2e67f32`. **Ops follow-up:** apply the pending `tblSongLinkSuggestionsDismissed` migration on alpha via `/manage/setup-database` for full function.
- **`.claude/` cross-device refresh** — new `MEMORY.md` (portable working memory) + `sessions/2026-06-10-HANDOFF.md`; `CLAUDE.md` red-flag + `project-rules.md §9` capture the mysqli-STRICT-vs-`if($res)` anti-pattern; `README.md` lists `MEMORY.md`.
- **Tracked follow-up (unfiled):** ~6 other `/manage/*` pages share the `if ($res = $db->query(...))` false-return assumption — only risky where a query targets a possibly-missing object; worth a sweep (relates to #713).

---

Last updated: 2026-06-14 (session 4) — **Lyrics-normalisation #1235: P3 SHIPPED to alpha; the P4 cutover CODE IS COMPLETE (C1–C7 committed on `feat/lyrics-1235-p4`, draft PR #1262), each phase adversarially reviewed + its bugs fixed; only the operational soak + the gated drop RUN remain.** Full resume map: **`.claude/sessions/2026-06-12-HANDOFF-session4.md`**; plan of record: `.claude/lyrics-normalisation-strategy.md` §11 + §12; cutover runbooks: `.claude/lyrics-cutover-dress-rehearsal.md` + `.claude/lyrics-cutover-promotion-checklist.md`.

- **SHIPPED — PR #1259 (MERGED `7168606d`):** #1235 **P2b + P3** (Id-preserving line diff, per-line language + translation/annotation editor) **plus two data-loss fixes** found by the P4 planning pass: **PF1/R1** (#1257 CLOSED — stale-client save wiped per-line chords/languages; fixed with server-side carry-forward across `save_song`/`component_upsert`/`components_replace`) and **PF2/R3** (#1258 open — enrichment cascade snapshotted to `tblActivityLog` before the diff DELETE).
- **P4 cutover — `feat/lyrics-1235-p4`, DRAFT PR #1262 (kept draft so auto-merge can't land an incomplete cutover):** **C1** `eb3dd686` shared line-first assembler `includes/lyric_lines_read.php` (+9 tests); **C2** `303cf3d2` `PartTypeSlug` backfill + slug-at-write (closes the #1138 NULL gap); **C3** `10c2e407` verification tooling `appWeb/.sql/verify-lyrics-cutover.php` (G-gates; G2 = the byte-identity losslessness proof; writes the drop-gating sentinel) + `tools/export-fidelity-snapshot.php`; **C4** `241a30e5` **read switch** — `SongData` assembles components from `tblLyricLines` (byte-identical; orphaned `_mirror*` helpers removed). All zero-data-loss, revertable, CI-green.
- **P4 cutover — now CODE-COMPLETE (C4-cleanup→C6 committed since session 3):** **C4-cleanup** `97336426` (3 preview-line bypass readers off `LinesJson`); **C5** `91a77dec` **write inversion** — `tblLyricLines` is the write source of truth, the shared engine `lyricLinesWriteComponents()` (Id-stable thin upsert + gated shadow-JSON + the PURE `lyricLinesBuildDesiredFromComponents` + shared `lyricLinesApplyDesired` diff) replaces the LinesJson-sourced projector in all 5 funnels, v2 ed2 helpers drop-safe, CI guard `test-component-json-guard.php`; **load_song follow-up** `ae52f8bc`; **C6 the gated drop** `3a995e22` (`migrate-retire-component-lines-json.php` Stage-0-gated + `regenerate-lines-json-from-lines.php` recovery + schema.sql thin mirror + `@migration-drops` scanner + retired-era guards + `'manual'` registry flag); doc-fixes `ca3d8207`/`8bbdbaba`. **Adversarially reviewed:** C5 = 5 bugs fixed (the big one: read+write gates unified on `tblLyricLines.ChordsJson+Note+PartTypeSlug`), C6 = 4 bugs fixed (the destructive drop excluded from "Apply all" via the `'manual'` flag + `confirm=1`). Decisions: **C-variant**, **cutover-first**, **`tblSongChords` = chord home**, **`Source='ihymns'` reads**.
- **★ RESUME** = push/review PR #1262 → land C4/C5/C6 on alpha → ≥7-night alpha soak → **PROMOTE the full lyric stack to beta + production** (the 2026-06-13 audit found `origin/beta`+`origin/main` are PRE-P1, so the drop's prerequisite is C4/C5 live on ALL THREE shared-DB envs) → **run the drop ONCE on the SHARED DB** (`ihymns@mysql.MWBMpartners.ltd` — NOT per-env; one copy) inside a #1234 freeze with all three UIs paused + a tested backup. See the two cutover runbooks. Keep lyric editing alpha-only until C4/C5 is everywhere.
- **★ Strategic finding (§12):** the "23.8% duplicate part keys forecloses pure-C" figure is **94% scraped repeat-refrain garbage + ~1.3% mis-parses** — so on CLEAN data **pure-C is re-opened**. Logged as the data-cleanup **epic #1260** (`for consideration`): collapse repeats→arrangement, triage the 208 distinct cases, then re-decide pure-C. The owner provided a real production DB dump (gitignored gz; PII) that grounded the whole P4 plan.
- **Owner actions pending:** review/soak #1262 on alpha (apply `component-line-languages` + `lyric-lines-parttypeslug` migrations, run `verify-lyrics-cutover.php --phase=pre`); beta→main promotion (independent); the #1260 cleanup epic. Sibling **#1243** (musical metadata) must consume the C1 assembler, never re-grow a parallel JSON array.

---

Last updated: 2026-06-21 — **fact-refresh pass against codebase reality (no new features).** Corrected three stale facts in the header block: the DB table count (`schema.sql` now has **~131** `CREATE TABLE` statements, not ~50); the API endpoint count (**70+**, now including the scoped DB-direct read endpoints `songs_index`/`song_detail` and the eight Service Mode endpoints `service_session_start`/`service_code_rotate`/`service_code_current`/`service_session_end`/`service_broadcast`/`service_join`/`service_poll`/`service_leave`); and the Apple/Android platform status (**Scaffold / in-progress** — ~14 Swift + ~12 Kotlin files — not "Code complete"). Re-affirmed the standing reality the rest of this brief already encodes: version `0.990.0` (`infoAppVer.php`); **DB-direct reads** (epic #1010 / WS-J #1020) — live MySQL, NO `songs.json` corpus cache or `songs_json` endpoint, a DB outage returns a themed **503** (WS-K #1021) not a JSON fallback, and `songs.json` is a migration **input** only; **`tblLyricLines` is the source of truth** for lyric lines (#1235), the `tblSongComponents` `LinesJson`/`ChordsJson`/`NotesJson` columns being a gated shadow under retirement; operator is **web-only** (no CLI/SSH) and migrations are **not auto-applied** (run via `/manage/setup-database`, registry-driven "Apply all pending"). Shipped feature families already covered above and reconfirmed live: Service Mode / congregation Live-Follow (#1323/#1335 — org venues+schedules, rotating-code join, presence, the two broadcaster UIs `/manage/service-projection` + `/manage/service-lead`, dormant CCLI gate), Duplicate & Counterpart detection (#1215/#1216, `/manage/duplicate-songs`, shared `includes/song_similarity.php`; old `/manage/song-link-suggestions` = 302 redirect), standard CCLI/OpenLyrics theme vocabulary (#1152/#1222, `/manage/tags`), Works (#840, `/manage/works`, `/work/<slug>`), the external-links registry (#833/#845, `/manage/external-link-types`), songbook `DisplayAbbr` display-label (#1332), Catalogues user-labelled "Collections" (#1223, internal name stays `catalogue`), and unofficial-songbook badging (#1223).

---

Last updated: 2026-08-01 — **MWBM-IntAppsAPI gateway integration (Epic #1725), Block D, lands on `claude/wave3-fixes` — six commits, entirely dormant.** This entry covers only Block D; the intervening ~6 weeks of unrelated work on this branch (#960 credit-registry regression, #882 OpenSong import, #1608 v2 counterpart surface) is not summarised here — see the branch's own commit history and `.claude/wave4-prelaunch-plan.md`.

- **What shipped:** `appWeb/.sql/migrate-add-intapps-sync.php` (one dormant table, `tblIntAppsSync`, UNIQUE `(Scope, Channel, AppSlug)` — all three reserved-multiplicity per rule #20); `includes/intapps_client.php` (the whole client: HMAC signer, single-flight cold-cache populate, exponential backoff, fail-open transport, SSRF-hardened URL resolution); `api.php`'s `app_status` case wired to emit `remoteFeatures` (native-client-facing) and piggyback the post-response refresh; `/manage/configuration`'s new IntAppsAPI card + `/manage/intapps-status` (both `manage_configuration`-gated); `includes/pages/home.php`'s Song-of-the-Day card wrapped in the first real `intappsFlag()` consumer; `tests/php/fixtures/intapps-stub-gateway.php` (a checked-in, line-for-line port of the gateway's own `AuthMiddleware`/`HmacValidator`, pinned commit `6816ed8`, structurally unable to deploy) + its e2e suite + a boundary guard banning gateway identifiers from every content-gating file.
- **Dormant by construction, proven not assumed:** `tblAppSettings.intappsapi_enabled_channels` ships with no row. Byte-identical no-op proved against the real local instance at branch tip vs the tip immediately before Block D (`d9807776`): six endpoints (`app_status`, `songs_index`, `song_detail`, `page=home`, `page=song`, `access_tiers`) diffed empty; zero `tblIntAppsSync` references across those requests in MySQL's own general query log (2115 real log lines captured, 0 matches); three mutations (force `remoteFeatures` to always emit, drop the enablement guard on the cache read, point a capture at extensionless `/api`) each independently proven to turn the relevant check red, then reverted.
- **D1, the pre-launch stress-test BLOCKER, fixed and reproduced on demand:** an enabled-but-un-migrated docroot hitting `tblIntAppsSync` directly would throw `mysqli_sql_exception` under STRICT reporting straight through the public home page. Every table access now sits behind an INFORMATION_SCHEMA existence probe AND a try/catch. Reproduced literally: removed the guard, hit `app_status` with the module enabled and the table renamed away, got a real HTTP 500 with the exact exception in the server log; restored the guard, same request returned 200 with a graceful degrade.
- **The signer is verified against a REAL local HTTP round trip, not just unit-tested:** the gateway's own five bundled examples sign `METHOD|PATH|TIMESTAMP|BODY` — all wrong (MWBM-intAppsAPI#120). `intappsSign()` implements the gateway's actual verification source (`rawBody . '.' . timestamp`) instead, and the e2e suite proves the real client's signature is ACCEPTED by a verifier ported line-for-line from that same source, while the vendor's own wrong scheme is REJECTED.
- **Two real bugs found and fixed while building the e2e proof** (not from static reasoning): (1) the single-flight lock was held for its full 10s window regardless of actual fetch duration, making back-to-back "Refresh now" clicks report a false busy signal — fixed by releasing the lock on commit, success or failure; (2) `CURLOPT_MAXFILESIZE` fires via its own error path (`CURLE_FILESIZE_EXCEEDED`) before the manual write-callback's oversized flag is ever consulted, so an oversized response was silently reclassified as generic `unreachable` with no error code — fixed by treating both triggers as the same `OVERSIZED` outcome.
- **What is explicitly NOT proven here:** signature acceptance by the REAL gateway (`api.mwbmpartners.ltd` is unreachable from this container — proxy policy, not evidence of downtime); the enablement flip on any real environment; native-app consumption. Issue A / **#1726 is owner-only, gates ENABLEMENT only** (not landing), and must never appear in a `Closes` line — confirmed absent from every commit in this batch.
- **Baseline:** 84 → 88 PHP suites, 45 node suites, eslint clean, throughout. DB hygiene (`songs=7 books=2 people=3 sugg=0 links=0 orphanlines=0 lyriclines=15`) reconfirmed unchanged after every commit's behavioural verification.
- **Not done in this pass (flagged, not silently skipped):** the `iHymns.wiki` pages are not updated — the wiki is a separate git repository this session did not have cloned/attached, so no push was attempted; a future session with wiki access should add an IntAppsAPI page under Architecture/API per the standing-tasks checklist. `.claude/ProjectBrief.md`'s header block (table/endpoint counts, platform status) was not re-verified in this pass — only this dated entry was appended.

# 🧠 iHymns — Claude Memory

> **Portable working memory** for picking the project up on any device. High-signal
> quick-reference: current state, the live workflow, and hard-won gotchas. Pairs with
> `CLAUDE.md` (rules / guardrails — auto-loaded), `ProjectBrief.md` (full state snapshot),
> `project-rules.md` (detailed rules), and `sessions/<date>-HANDOFF.md` (session history).
> When something here goes stale, fix it **here and in the file it mirrors**.

_Last updated: 2026-07-30._

## Where things stand
- **Version:** `0.4001.0` (alpha, Phase 1) — authoritative source is `includes/infoAppVer.php`
  (the PWA service-worker cache version + every `?v=` cache-buster auto-sync off it, #81). Docs that
  hardcode a version rot within days — point at the file.
  ⚠️ **`version-bump.yml` fires ONLY on a push to `beta`** — an `alpha` merge never bumps. That is
  how 98 commits shipped under one version string; the bump had to be done by hand (`bad5ca4f`,
  PR #1592) using the workflow's own arithmetic (`MINOR+1`, `PATCH=0`) so the two don't drift.
  **Expect to repeat this after any large alpha batch.**
- **`beta` is frozen at v0.1254.1** (~2026-06-25, PR #1369) and predates the entire Live Follow fix
  train (#1375/#1377/#1386/#1405). The promotion PR #1580 was **closed unmerged** — alpha and beta
  have **unrelated histories**, so it needs a tree-replacement, not a merge. Before any future
  promotion, set `APPLE_DEPLOY_ENABLED=false`: `apple-deploy.yml` has failed 5/5 runs (#1579), and
  fixing it would re-arm a TestFlight **external** upload on every beta push.
- **Environments / deploy:** single shared MySQL; `alpha` → `dev.ihymns.app` (auto-merge + SFTP),
  `beta` → `beta.ihymns.app`, `main` → `www.ihymns.app`. Most work targets **`alpha`**.
- **Reads are LIVE MySQL** — there is NO `songs.json` corpus cache (epic #1010). Scoped reads only
  (`getSongsSlimIndex` / `getSongs($abbr)` / `getSongById`); a DB outage is a themed 503, never stale.
- **The consolidation LANDED (2026-07-28):** PR #1585 merged to alpha as squash **`887bcd2f`** (98
  commits, 228 files); the version bump followed in **`bad5ca4f`** (PR #1592). Both `claude/*`
  branches were deleted at that point — since then, `claude/sotd-language-filter-typeahead-a11y`
  (wave 2) has been created and merged, and `claude/wave3-fixes` (wave 3, active, not yet merged)
  followed it; see below.
- **Wave 2's branch merged:** `claude/sotd-language-filter-typeahead-a11y` squashed to alpha as
  **`bc0eb52e`** (PR #1651, "Security fixes, data-loss fixes, and the behaviour-audit programme",
  56 commits). Its two founding bugs, #1593 (Song of the Day vanishes with >1 language selected) and
  #1594 (location typeahead was mouse-only), both landed early, and it carried a long tail of further
  work — #1388 (pre-gating hardening), #1031 (deleted the `window.fetch` monkey-patch), #1533
  (setlist playback) + #1623 (Revisions Audit link fix), #1619/#1620 (cleanup), and more. See "Recent
  landings" below.
- **Active branch: `claude/wave3-fixes`** (off `bc0eb52e`), 56 commits — headline: epic **#1601**
  scope item 2, `/manage/editor/` now 302-redirects to the **v2 Song Editor** by default (`?legacy=1`
  or `tblAppSettings.editor_v2_default='0'` reverts to v1, which is deliberately NOT retired). See
  "Recent landings — wave 3" below; do not assume any branch name above still describes HEAD.
- **Verified counts** (re-derived 2026-07-28 — most docs disagreed with all of these): **142** tables
  in `schema.sql`; **38** admin nav destinations in `admin-links.php` (Dashboard + 6 groups);
  **14** workflows in `.github/workflows/`; **8** guides in `help/`; **≈195** real API actions.
- **Apple programme:** Phase-2 code-complete but **never compiled as a merged whole** — the
  consolidation was done in a Linux container with no Swift toolchain. Audit-B security gate, device
  matrices and APNs provisioning remain outstanding; all hardware/owner-gated.

## Workflow (locked)
- **Fable 5 = deep planning/review** (SEQUENTIAL, one at a time; fall back to Opus only if Fable is
  down, retry Fable next run). **Sonnet/Haiku = implementation** (Opus only for genuinely complex or
  security-critical). Token-efficient but GIRFT — right first time.
- **One PR per piece of work → `alpha`**, multiple atomic commits. Branch `claude/<topic>-<suffix>`,
  **always off the latest `origin/alpha`** — PRs **squash-merge**, so branching off a stale feature
  branch makes the next PR re-show already-merged commits.
- Alpha PRs **auto-merge** once the required web checks pass. ⚠️ **`apple.yml` is NOT a required
  check (#1526)** → an appApple PR auto-merges on the ~2-min web lint, often 30+ min BEFORE the Apple
  build finishes. **Mitigation needing no settings change: open the PR as a DRAFT**
  (`auto-merge-alpha.yml` skips drafts, but `pull_request` checks still run) → wait for `apple.yml`
  `build` green → mark ready for review.
- After merge, **close the tracking issue manually** with the squash SHA + evidence — `Closes #N`
  does NOT auto-close from an `alpha` merge (GitHub only auto-closes on the default branch `main`).
- Every user-reported bug / feature gets a **tracking issue BEFORE** its closing commit.
- Push with `git push -u origin <branch>`; retry on network error with backoff.

## Gotchas (hard-won — read before debugging a blank/odd/silent page)
- **An inline `<script>` in an SPA fragment NEVER runs** (rule #30, #1565). Enforcing nonce CSP
  (#117) + shared-cache fragments (rule #6) = the browser silently refuses it. **No exception, no
  toast — the feature just looks alive and does nothing when clicked.** This killed the entire public
  Export feature (all 8 formats, both surfaces) and the Present button for ~7 weeks before a user
  noticed. Wire from `router.js afterPageLoad()` as a real module (the `home-page.js` pattern).
  CI guard: `tests/php/test-fragment-inline-scripts.php`.
- **A relative `fetch()` URL resolves against the DOCUMENT, not the script** (#1566). Combined with
  the SPA catch-all answering **200 + HTML** for any unmatched path, `response.ok` passes and you get
  a baffling `SyntaxError` out of `.json()` instead of an honest 404. Use root-absolute paths.
- **mysqli THROWS; it does NOT return `false`.** `getDbMysqli()` sets
  `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`, so `if ($res = $db->query(...))` guards are **dead
  code**. Gate optional tables with an INFORMATION_SCHEMA probe + `try/catch` → themed error card.
  This white-screened `/manage/duplicate-songs` (#1228 → fixed #1229).
- **Migrations are NOT auto-applied on deploy** — code ships to `alpha`; the matching migration is run
  by hand via `/manage/setup-database`. Any page touching a new table/column must tolerate its absence.
- **Never write `<?=` / `<?php` / `<?` inside HTML comments or backticks in `.php`** — PHP parses the
  open tag regardless of `<!-- -->`; on 8.1+ this fatals + truncates the response (took down alpha in
  #536). CI greps for this.
- **Never hardcode `data-bs-theme` on an admin page** — resolved at runtime by
  `admin-theme-init.php` from `localStorage` (#955).
- **A test that has never been proven to FAIL is worthless.** The ProPresenter suite passed 54/54 for
  weeks while injecting the bundle straight into `init()`, so the real fetch path had never once run —
  which is precisely how #1566 shipped unnoticed. Always break the thing on purpose and watch the
  test go red before trusting it.
- **`git stash` during a branch switch with only UNTRACKED changes saves nothing** → a later
  `git stash pop` pops a STALE stash. Stage explicit pathspecs; avoid blind `git stash pop`.
- **`CHANGELOG.md`'s top section is USER-VISIBLE.** `deploy.yml` extracts the first three `## `
  sections into `public_html/data/whats-new.md` on every deploy and `/whats-new` renders it. A
  malformed or duplicated top header ships straight to testers — a consolidation merge left TWO
  `## [unreleased]` headers and the What's New page showed *unreleased, unreleased, April* (#1586).
- **Docs drift silently and compound.** The 2026-07-28 audit found four different version numbers
  across README / PROJECT_STATUS / ProjectBrief / Home.md, table counts off by 11, workflow counts
  off by 9, and eleven wiki pages still calling `songs.json` the "single source of truth" more than
  a month after #1010 removed it. **Never cite a count from another document** — re-derive it from
  `schema.sql` / `admin-links.php` / `ls .github/workflows/`, or de-version the sentence entirely.

## Recent landings (2026-07-29/30 — wave 3, on `claude/wave3-fixes`, off `bc0eb52e`, NOT yet on alpha)
- **Epic #1601 scope item 2 — `/manage/editor/` now serves the v2 Song Editor by default.**
  `manage/editor/index.php` is a 302 redirect to the v2 shell, forwarding the query string, so all six
  places linking into the editor with URL instructions (nav, dashboard card, Revisions "Open in
  editor", Missing Numbers prefill, Duplicate Songs, the public song page's Edit button) land on v2
  without their own change. 302 not 301 — stays reversible server-side. Two escape hatches: `?legacy=1`
  per-visit, `tblAppSettings.editor_v2_default='0'` fleet-wide (absent key means v2). **v1 is NOT
  retired** (scope item 3) — nothing here was runtime-verified (no MySQL, no browser in this
  container) and retirement needs cross-branch coordination (beta/main share the one MySQL, a month
  behind on unrelated history).
- **#1677, P0 — every v2 write returned 403 until this fixed it.** `api2.php` requires
  `X-Requested-With: XMLHttpRequest` on every POST; the v2 shell's own client sent it on reads only,
  never on either write helper — so save/create/delete/component writes/bulk actions/revision
  restore/enrichment all 403'd from the v2 UI while browsing (GETs) worked fine. Fixed client-side.
  `tests/php/test-editor-api2-contract.php` derives both sides from source.
- **#1627 — v2 gains a chords box, an Arrangement (running-order) editor, and per-line
  translation/annotation panels.** New `arrangement_update` api2 action + shared
  `includes/arrangement.php` validator (used by both the writer and the #1618 cutover-gate auditor).
  Arrangement UI uses move-left/move-right buttons, not drag — sidesteps another unpinned SortableJS
  CDN load and fixes the same keyboard/touch gaps #1644 closed elsewhere. Per-line enrichment needed a
  `lineIds` field the editor read shape had been silently dropping (rule #21 — anchors on
  `tblLyricLines.Id`, never a `LinesJson` index).
- **#1628 / #1680 — v2 gains `?tab=`/`?songbook=`/`#number=`/`?open=` deep links, the sidebar
  songbook filter + 3-way sort, `bulk_tag_detach`, and the export lines-per-slide setting.** The deep
  links are the THIRD instance of the same shape (#1623 → #1628 → #1680) — a param another page emits
  that the destination doesn't read is silent, so grep for callers before changing what a route serves
  (see rule #33 in CLAUDE.md, added this wave).
- **#1629 — de-orphan four v1 `api.php` consumers ahead of retirement** (Content Restrictions'
  `user_search`/`org_search`, the admin Songbooks bundle-export button, the bulk-import progress
  poller's stale-job fallback, and a new `unlink` action on `/manage/duplicate-songs`).
- **#1676 — one shared emitter for Bootstrap CDN tags.** `includes/bootstrap_assets.php`; four pages
  (incl. the v2 editor's own bespoke `<head>`) had drifted to three pinned versions, two with no SRI.
- **#1638 — setlist collaboration finished end-to-end.** Notifications, a "Shared with me" list, and
  actually-enforced view/edit permission (it had shipped write-only and decorative).
- **#1649 — cross-device sync data-loss fixes.** Capped per-user syncs (set lists/favourites/custom
  tags) no longer delete rows only absent because of the cap; a `since` watermark stops an older
  device deleting another device's newer, unseen writes.
- **#1643–#1648, #1665 — accessibility + security sweep.** High-contrast/CVD modes had never been
  styled anywhere under `/manage` (the CSS keyed on the right attributes but only shipped to the public
  shell); Present mode is now a real focus-trapping dialog; Service Mode announces section changes and
  no longer races the render on a fixed timer; sortable headers keep their `columnheader` role; SPA
  navigation stopped reading whole pages aloud; the setlist Arrangement editor works by keyboard and
  touch; SortableJS + the Bootstrap CDN loads gained SRI + vendored fallbacks; eight admin pages' gates
  now match what the nav advertises.
- **#1633 — iHymns interchange JSON importer**, additive/merge-only, writing straight to the DB.
- **#1634 — repaired `npm run build:proto`** (stale path, missing npm script, non-deterministic output).
- Suites: **54 PHP / 34 node**. Documentation sweep (this pass) covers CHANGELOG backfill for #1625,
  `api-docs.yaml`, in-app help, README/DEV_NOTES/PROJECT_STATUS, ProjectBrief, this file. **The Wiki
  (`iHymns.wiki/`) is not present in this container and could not be updated** — tracked gap.

## Recent landings (2026-07-29 — wave 2, merged to alpha as `bc0eb52e` (PR #1651) — was on `claude/sotd-language-filter-typeahead-a11y`)
- **#1388 pre-gating hardening** — eight gaps closed before `content_gating_enabled` can ever flip to
  `'1'`; every change is a verified no-op today. The load-bearing one: **payload gating is not asset
  gating**. `contentGatingApply()` strips gated fields from JSON payloads (now including
  `songbook_export`, previously entity-gated only — the widest lyric leak in the API); the new sibling
  `contentGatingMediaAllowed($kind, $userId, $presenceToken)` gates the media BYTES for
  `song-media.php` and the `bulk_audio` manifest, resolving through the same `TIER_CAPS` registry
  (rule #28). Also: first-admin registration TOCTOU closed (`SELECT ... FOR UPDATE`), logout now
  clears user-scoped Cache Storage, `validateCsrfRequest()` tightened (rejects `X-Requested-With`
  alone when both `Origin` and `Referer` are absent), Service Mode's CCLI unlock now requires a live
  heartbeat not just `IsActive`.
- **#1031 the `window.fetch` monkey-patch is DELETED** — `js/utils/api-client.js` (`apiFetch`/
  `apiFetchJson`) is the new shared client; nothing overrides global `fetch` anymore (rule #31). Fixes
  the root cause under #1593: the patch only installed on `home`/`songbooks`/`settings`, so an
  anonymous cold load of `/search` silently sent no language filter at all.
- **#1533 setlist playback** — tap any song in a setlist (yours or shared) to arm a fixed bottom bar
  (Prev/Next, "N of M", next-song title, arrow keys, exit). The real bug it fixed: `getNavigation()`
  looked lists up by id in `getAll()`, so a SHARED setlist (no local record) could never be navigated
  at all. Replaced with a playlist *context* (`sessionStorage`) carrying its own song order. **Trap**:
  the bar is `position: fixed` on `<body>`, which does NOT get removed on an SPA content swap —
  `renderSongNavigation()` must tear down unconditionally before any early return, and the router
  calls it on every navigation, not just song pages (rule #32).
- **#1623 Revisions Audit "Open in editor"** — linked `?open=<id>`, the editor only ever read
  `?song=`; silently selected nothing. Fixed + kept `open` as a back-compat alias; also wired
  `?tab=history` to auto-open the diff modal.
- **#1619/#1620 cleanup** — deleted `router.js`'s now-dead `_executeInlineScripts()` shim (nothing
  left to execute once #1572 emptied the fragment allowlist); renamed `request.js`'s exported
  `Request` class to `SongRequest` so it stops shadowing the Fetch API's global `Request` (the file
  keeps its name — it's in the service-worker precache list).
- **Documentation sweep for this wave landed** — in-app help (`includes/pages/help.php`,
  `manage/help.php`) and these `.claude/` files; the CHANGELOG backfill it deferred (issue #1625)
  landed in wave 3 above. The Wiki was not reachable from this container in either pass.

## Recent landings (2026-07-28 — originated on `claude/observability-alpha-3k9wqz`, now MERGED to alpha via the consolidation squash `887bcd2f` — see "Where things stand" above)
- **Observability trio** — #1581 (event names live once in `js/constants.js`; `tests/test-event-names.js`
  bans raw `ihymns:*` literals — the Settings language filter silently never refreshed Song of the
  Day), #1582 (`js/modules/error-monitor.js` → one toast + a throttled, scrubbed beacon to
  `client_error_report` → `tblActivityLog` `Action=client.jserror`), #1583 (`/whats-new`).
- **#1584 deploy media guard** — the docroot mirror runs in delete mode, so every deploy was wiping
  `/data/audio` and `/data/music`. Owner still needs to check those directories on the servers.
- **#1587 Swagger UI hardening** — `/manage/api-docs` floated `swagger-ui-dist@5` with no SRI, failed
  silently to `console.error`, and enforced `requireEditor()` while the nav checked `view_api_docs`
  (a curator saw no link but could deep-link in). Pinned 5.32.11 + SRI + vendored fallback.
- **#1586 documentation overhaul** — every `.md`, the in-app help, the wiki, the `.claude/` files and
  `api-docs.yaml` re-checked against the code. `wiki/Architecture.md` rewritten (it claimed SQLite +
  SQL Server support); `wiki/API-Reference.md` stopped hand-maintaining ~45 of ~195 endpoints and now
  points at the OpenAPI spec; OpenAPI gained the 12 undocumented actions + `page=whats-new` and marks
  the four dormant endpoints as dormant. See the two gotchas above.
- Also: #1558 (serial `swift test` — cross-suite Keychain contention was the dominant CI failure),
  #1576 (ad-hoc Service Mode sessions born expired), the `migrate-json.php` confirm gate, and the HA
  integrity-audit path fix (that monthly job had failed silently since July — three compounding bugs).

## Earlier landings (2026-07-26 — same branch, arrived via the consolidation merge)
- **Branch consolidation** — 7 outstanding branches → 1. Four (`pr11`/`pr14`/`pr15`/`pr16`) were
  already fully contained in `integration/apple-phase2-batch`; only App Intents (#1415) and the docs
  branch carried unique commits. Merged from integration's **tip~2** to exclude two stray commits
  (unrelated agent tooling + a force-added `.claude/settings.local.json`).
- **Public export fix** — #1565 (CSP wiring — the real bug), #1566 (relative bundle URL), #1567
  (editor2 protobuf), #1568 (Present button + keydown leak), #1570 (shared menu partial + ChordPro on
  songbooks), #1569 (CI guard + regression tests, every one proven able to fail).
- **Live Follow / Service Mode docs** (#1577) — they were undocumented and conflated, which is what
  made Live Follow look permanently broken. Now split across `help/live-follow.md`, two `help.php`
  topics, `wiki/Live-Follow-&-Service-Mode.md` and Apple's `HelpView`.
- **Recovered** (`50ff2b80`) the prompt-polish agent skill + `.claude/settings.local.json`, which an
  earlier merge had wrongly excluded as "strays". See the handoff's warning box.
- Issues filed this session: **#1565–#1577**.

## Live features — the distinction that keeps costing time
**Live Follow (#1268) and Service Mode (#1323/#1335) are DIFFERENT features sharing one table.**
- `live_follow_create` requires **only a sign-in** — no venue, no schedule, no org (`OrgId` hardcoded
  NULL, `api.php:13968`). Fixed 6-char code, song-level sync, 4h life.
- `service_session_start` requires **venue + occurrence date + org-admin** (`api.php:14366-14385`).
  Rotating venue-screen code, song **and** section sync.
- A session is walled to its **`Channel`** — dev/beta/www share ONE database but sessions never cross.
  Desktop-on-dev + phone-PWA-on-www **always** fails, with only a generic wrong-code toast.
- The **Go Live button doesn't render when signed out** (`live-follow.js:399`).
- ⛔ Un-applied Service Mode migration ⇒ **Go Live 500s for plain Live Follow too** (its INSERT names
  `Channel`). Confirm the two cards on `/manage/setup-database` before debugging anything else (#1339).

## Offline download's 7 defects — FIXED (#1597, 2026-07-29, merged to alpha as `bc0eb52e`)
All "looks alive, isn't" bugs. Worst (RC1): bulk-downloaded and recently-viewed songs shared one
2000-entry `RECENT_CACHE` budget that trimmed oldest-first on **every song view**, so downloading a
3,517-song book and opening one song deleted ~1,500 entries. Now separate budgets; downloads are
exempt from the recency trim. Also fixed: the deploy keep-list never retained `iHymns-media-v1` (every
deploy wiped downloaded audio — worse once #1596 made alpha bump its SW version on every push);
offline navigation fell back for `page=song` only (home/songbooks/songbook/search 503'd); eviction +
size reporting used the wrong URL shape (`/data/audio/<book>/…` vs the real flat
`/data/audio/<SongId>.mp3`); the song progress bar could hang forever; `navigator.storage.persist()`
was never called; the Settings "include audio offline" toggle event had zero dispatchers. Verified in
Node against the trim/keep-list logic; the real download → deploy → still-there round trip needs a
browser and remains owner-verified.

## Key pointers
- Export: `js/modules/export-ui.js` (wiring, router-driven) · `manage/editor/format-export.js`
  (7 formats) · `manage/editor/propresenter-export.js` (PP7 protobuf + the shared ZIP writer) ·
  `includes/partials/export-menu.php` (THE single menu source for both public surfaces).
- Duplicate / counterpart scoring → `includes/song_similarity.php` (the ONE scorer; never re-fork).
  Exact dedup fold → `ihymns_normalize_title()`; fuzzy fold → `ihymns_sim_normalise()`.
- DB access → `includes/db_mysql.php::getDbMysqli()` ONLY (no PDO, no raw `new mysqli`); always
  `bind_param`.
- Lyric lines → read via `includes/lyric_lines_read.php`, write via `lyricLinesWriteComponents()`.
  ONE read path, ONE write path (rule #25).
- Gating caps → `TIER_CAPS` in `includes/access_tier_validation.php` (rule #28). A new cap is ONE
  `'json'` line — never a new column, never a second tier matrix. ⚠️ Its tracker is **#1590**;
  the `#1352/#1353/#1354` cited all over the rules and code are **SDA scraper 403 reports**, not
  this work.
- Export formats — the ONE source is `$EXPORT_MENU_FORMATS` in `includes/partials/export-menu.php`:
  OpenSong · OpenLyrics/OpenLP · **ProPresenter 6** · **ProPresenter 7+** · VideoPsalm · FreeShow ·
  Proclaim · ChordPro. **There is no EasyWorship exporter on the public menu** (it exists only in the
  admin editor's import/export tooling), and ProPresenter is two entries, not one. Planning notes have
  had this wrong twice.
- Swagger UI → `/manage/api-docs` (`view_api_docs` entitlement, pinned + SRI + `/vendor/` fallback,
  #1587). The spec it renders is `appWeb/public_html/api-docs.yaml` — a public docroot file.
- Song Editor → `/manage/editor/` 302-redirects to the **v2** editor by default (epic #1601, wave 3).
  Legacy v1 (`api.php`) is NOT retired — `?legacy=1` per-visit or `tblAppSettings.editor_v2_default='0'`
  fleet-wide reverts to it. v2's backend is `api2.php`; every POST there needs `X-Requested-With`
  (client-side fixed in #1677 — the API doc was correct throughout, the client wasn't). v2 Restore
  applies a revision's snapshot (state AFTER that edit); legacy v1 Restore undid it (state BEFORE).
- Same-origin requests → `apiFetch()`/`apiFetchJson()` in `js/utils/api-client.js` (rule #31, #1031).
  No `window.fetch` override exists anywhere in the app now; the service worker is the one deliberate
  holdout (different global scope, not user-scoped).
- Setlist playback state → `SetList.getPlaylistContext()`/`setPlaylistContext()`, `sessionStorage` key
  `STORAGE_PLAYLIST_CONTEXT` (rule #32, #1533). Carries its own song order so it serves a SHARED
  setlist identically to an owned one — `activeSetListId` is only the pre-#1533 fallback now, kept
  alive for `applyCustomArrangement()`.
- Content gating: `contentGatingApply()` gates JSON payloads, `contentGatingMediaAllowed()` gates
  media bytes — same registry, two enforcement points (rule #28, #1388). Both are verified no-ops
  while `content_gating_enabled='0'`.
- After every substantive piece of work, run **`.claude/standing-tasks.md`** (issues, milestones,
  wiki, .md docs, and these `.claude/` files).
- **"HA" in `maintenance-ha-integrity-audit.yml` is Himnario Adventista** (a Spanish songbook), NOT
  Home Assistant. The workflow cross-checks two scrapers' extracts of it.

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
- Push with `git push -u origin <branch>` — **and `<branch>` MUST be the one you are checked out on.**
  ⚠️ **The "designated branch" line in the session prompt is STALE and has been for weeks.** It names
  `claude/apple-branches-cleanup-export-7mxhpo`, a branch the owner deleted on purpose. Obeying it
  literally re-creates it on the remote — and worse, `git push -u origin <other-branch>` does not push
  your work at all, it silently publishes THAT local branch's tip. It happened twice; the second time
  a warning about it was already sitting in `ProjectBrief.md`, unread. **`git branch -vv` first, then
  push what you are on.** The mechanism is now `tools/githooks/pre-push`, which refuses both cases —
  install it in every fresh container with `git config core.hooksPath tools/githooks` (`.git/hooks`
  is not tracked, so it does NOT come with the clone). Bypass is `IHYMNS_ALLOW_ANY_PUSH=1`, never
  `--no-verify`.
- 🙋 **Owner decisions are NEVER a bare question** (owner-stated 2026-07-30, full shape in
  `CLAUDE.md` → "Asking the owner for a decision"). Always: **the decision** in one plain sentence ·
  **why it needs a human** (product call / data-shape consequence / a plan I can't see) · **the
  options with real consequences**, including the cost of doing nothing · **a recommendation, always,
  with reasoning** — "it's up to you" is not an answer · **the smallest reply that unblocks me** ·
  and **whether it blocks anything** (most don't — say so, so it can be deferred guilt-free).
  A sub-question the owner didn't answer gets a stated default, not a stall.
  **And if later investigation undermines your own recommendation, say so unprompted** (#1679).

## The 2026-07-31 pre-release sweeps — what four independent audits all found

Four sweeps (correctness, security, accessibility, lint) run against a codebase being prepped for
public release. They were scoped separately and found the SAME SHAPE each time, which is why it is
recorded here rather than in four issue bodies:

**A thing that looks like it works, is cited as working, and has never once run.**

- The CI step named "Lint JavaScript (ESLint)" had **never linted anything** — no config existed,
  so it ran config-less eslint (a crash since ESLint 9) and `|| exit 0` swallowed it. Green tick,
  zero checks, for its entire life.
- `/` and Ctrl+K were advertised as "Open search" in the shortcuts overlay AND on /help, and did
  nothing — bound to ids #812 deleted.
- Search autocomplete (#307) is fully implemented, endpoint included, reachable from nowhere —
  **and its test has always passed, because the test builds its own `<input>` and calls the
  function directly.** A green test over unreachable code is the most misleading artefact in this
  repo.
- `card-layout.js`'s doc-comment claimed "Move up/down" buttons existed. `git log -p`: they never
  did. A comment describing a feature that has never existed is worse than no comment — it stops
  the next reader checking.

**THE METHOD THAT KEEPS WORKING:** derive the list from the tree, then break the thing and watch
the check go red. In this session FIVE new guards were wrong-but-green on their first run, and
every one was caught by mutation, never by review:
  - two were satisfied **by their own explanatory comments** (the comment contained the literals
    the guard grepped for);
  - one analysed function bodies and silently skipped `js/utils/html.js` — the canonical escaper
    **16 modules import** — because its table lives in a module-scope const;
  - one returned "0 dead buttons" while a planted dead button sat in front of it (`.btn`
    substring-matched everything);
  - one flagged three correct functions, i.e. failed on correct code, which is how guards get
    deleted rather than fixed.

**CONFIDENT NEGATIVES ARE THE DANGEROUS OUTPUT.** Four times this session a tool said "nothing
there" and was wrong: a `[^;]*?` window truncated by a semicolon INSIDE a quoted string; a zero-hit
grep for a remembered identifier that had shipped hours earlier under a different name
(`webPushKinds`, not `PUSH_KINDS`); a `minimal_output` flag that silently STRIPPED the field being
audited; and `grep "search-input"` matching `page-search-input` as a substring. **If a scan returns
zero, sanity-check the scan against known-present source before believing it.**

**MECHANICAL PROOF BEATS REVIEW where one exists.** For a comments-only annotation pass, `php -w`
strips comments — so identical `php -w | md5sum` before and after PROVES zero behaviour change. Look
for that kind of gate before reaching for a reviewer.

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

## From the 2026-07-31 remediation programme (branch `claude/wave3-fixes`)

- **THE METHOD LESSON, and the most expensive thing learned here.** Four consecutive rounds shipped a
  guard that was **green while the thing it guarded was broken**, every time for one reason: *source
  inspection was used as primary evidence for a property that has a runtime handle.* When a property
  lives in a **pure function, test the function.** `tests/php/test-transaction-fatal.php` is the
  worked example — it constructs exceptions and asserts booleans, reads no source, and killed a
  bypass that had defeated two previous guard generations. Proof: gut the predicate to `return false;`
  leaving `1213`/`1205`/`mysqli_sql_exception` sitting in a dead array, and the source-inspecting
  guard stays GREEN while the behavioural one goes RED. Reserve source inspection for what genuinely
  has no runtime handle ("every call site does X", "no fragment carries an inline script").
- **A remediation can introduce the bug it removes.** `songRedirectsTableReady($db, true)` — added in
  the same pass as `songRelocateIsTransactionFatal()` — wraps its cause in a plain `RuntimeException`,
  and the predicate only type-checked the OUTERMOST exception. A deadlock inside that probe was
  swallowed again, through a brand-new code path added by its own fix. Predicates that classify
  exceptions must walk `getPrevious()`.
- **A mutation that "passes" may never have applied.** Two separate agents (and I) reported a green
  mutation that had patched a **doc-comment** rather than the code, or passed `F=$MOD` as an argument
  instead of an env var. Always diff the file after mutating and assert the needle was found.
- **`delete_songs` is admin-only, and that is the ONE deliberate non-equivalence in the entitlement
  map** (#1692/#1693). Every other key Batch 6 wired answers exactly what its raw gate answered.
  Song deletion is a HARD `DELETE FROM tblSongs`; there is no soft-delete column; 38 of 41 FKs cascade
  **including `fk_Revisions_Song`**, so the whole revision history dies with the song. INTERIM until
  #1694 ships soft delete — and **restore must be PREVENTION, not repair**: once the row goes, nothing
  the app holds can rebuild the children.
- **The remediation plan was WRONG about `delete_songs`** (claimed a raw `admin` gate; it was
  `editor`). Acting on it unverified would have stripped every curator's delete. `.claude/*.md` are
  **claims to check**, never truth — that is now twice this branch that a plan or handoff misled.
- **All ten decorative entitlements are wired or deleted**; the orphan allowlist's `entitlements`
  bucket is EMPTY. `manage_org_licences` was the tenth, invisible because it had no label. Its intent
  came from the originating commit (#462): *"so licence edits can be delegated without granting full
  org admin"* — SITE-ADMIN delegation, not org membership. I had inferred the opposite and the primary
  source settled it in minutes.
- **An orphan is not idle code, it is untested code that looks tested.** Building a UI for
  `song_key_save` — dispatched since #298, never once called — found **three guaranteed 500s** (NULL
  into a `NOT NULL` column, a 10-char cap on `VARCHAR(5)`, an int-cast into `INT UNSIGNED`) plus an
  OpenAPI page describing an API that does not exist.
- **Web Push crypto is proven against RFC 8291 §5's published vector, byte-for-byte** — an independent
  oracle, not a self-consistent round trip. That is the only acceptable evidence for hand-rolled
  crypto. **But no push has ever reached a device**; delivery is entirely unexercised.
- ⚠️ **NOTHING ON THIS BRANCH HAS BEEN RUN.** No MySQL, no browser, no network. ~95 commits are
  reasoned correct and observed correct nowhere. That is **P0** in `.claude/proposals-2026-07-31.md`
  and it outranks every feature.
- ⚠️ **The Wiki (`iHymns.wiki/`) is NOT in this container**, so standing-task item 4 could not be done
  for any of this work. Tracked, not silently skipped.

## 2026-07-31 — guards that lie, and how to catch them

- ⚠️ **A heuristic scanner MUST have a "did the analysis complete?" check.**
  `test-api-client-usage.js`'s comment/string stripper had no state for a **regex literal**, so
  `replace(/"/g, …)` (live in `modules/request.js`) opened a string state that never closed and
  **blanked the rest of the file** — reported as *zero violations, a clean pass*. **12 files ended the
  walk broken, 10 already in scope**, for a year (#1701). Fixed with regex handling **plus** the
  load-bearing half: **a derailed scan is now a build FAILURE**. A heuristic has gaps; that turns every
  gap into a loud false failure instead of half a file's coverage reading as all of it.
  🔑 **Its self-tests were GOOD** — they proved comments, strings, `apiFetch(`, `window.fetch(` and
  `//`-in-URLs were handled — and still missed the one construct that switched the mechanism off.
  **Mutating the guard's input FILES would never have found this; it needed a mutation against the
  input GRAMMAR.**
- **A guard that fails on CORRECT code is the other half of rule #34, and I tripped it three times in
  one day.** (a) `$r['target'] ?? 'x'` returns `'x'` when the value **IS null** — `??` cannot
  distinguish an absent key from a present-but-null one; use `array_key_exists`. (b) An assertion
  requiring a call and its `return null;` to be textually **adjacent** failed on an ordinary named
  local. (c) Its replacement scanned forward **from** the call, so the `if (` wrapping it — earlier in
  the file — was outside the window. The fix each time was a **different question**, not a cleverer
  regex: balanced-paren scanning, then requiring the condition to be the claim **alone**.
- **A mutation harness must compare against a pre-taken BACKUP, never `git diff` against HEAD**, when
  the baseline is uncommitted — `git diff` reports the whole working change for a one-line mutation
  and cannot tell "applied" from "already dirty". And **if the harness can throw, a throw must never
  be printable as a test result**: that has now produced a false GREEN four times on this branch.
- **A mutant survives when no SCENARIO reaches it, not only when the assertion is weak.** The
  five-column lockstep check (`=== 5` → `>= 1`) would have lived if the implementer hadn't noticed
  mid-work that **no test covered a PARTIAL apply** — which is exactly the state a web-run migration
  system produces. Ask "what states can this actually be in?" before trusting a green.
- **Verify agent findings, especially security-shaped ones.** A report flagged the migration scripts
  as having no auth gate. They need none: **`appWeb/.sql/` is a SIBLING of `public_html/`, outside the
  web root**, reachable only by `require` from a page gated on `run_db_install`. Two minutes of
  checking; would have been a wasted issue and a wasted fix.
- **`songVisibleSqlFor()`'s `'1=1'` degrade** is the pattern for retrofitting a predicate across
  hand-built SQL: return a VALID fragment when un-migrated, so ~166 call sites append it
  unconditionally with **zero per-site branching**.
- **Owner's definition of a public count (#1694 D1):** songbook `SongCount` means *"songs that can
  IDEALLY be accessed"* — it excludes soft-deleted rows but deliberately does **NOT** account for
  per-user gating (CCLI/tier). "Ideally accessible", never "accessible to you". Making it per-viewer
  would also break the shared-cache home fragment (rule #6).

## 2026-07-31 (later) — the environment limit that was never tested

- 🗄️ **THE CONTAINER CAN RUN MySQL.** `apt-get install mariadb-server` (~1 min) then `mariadbd
  --user=mysql --socket=…` — no systemd, no Docker (the docker CLI exists but its daemon does not).
  For ~95 commits every plan on this branch opened with *"no MySQL in this container"* and deferred
  verification to an alpha rehearsal. **Nobody had ever checked.** It took one command.
  🔑 **Always test a claimed environment limit before designing around it.** The cost of the
  assumption was ~95 commits of reasoned-correct, observed-nowhere work.
- 🔥 **The first thing it found: `schema.sql` could not install — 16 of 136 tables** (#1708). Five
  inline FKs referenced tables created LATER in the same file; two columns were `BIGINT UNSIGNED`
  against an `INT UNSIGNED` PK. **Both mismatched columns carried a COMMENT asserting they matched**
  — #1604's thesis, live. And the migration creating them had the same declaration, so it had never
  run anywhere: two Apple Phase-2 features had no storage, and #1642 had mis-diagnosed it as a
  deploy problem.
- **A text-agreement check is not a build check.** `test-schema-coverage.php` proves schema.sql and
  the migrations AGREE. It cannot prove either one BUILDS A DATABASE — and it was green throughout.
  When a guard compares two artefacts, ask what neither of them is being executed against.
- **Ask what the smallest safe evidence is, not for the biggest.** Offered a 292 MB production dump,
  the right answer was a ~2 KB read-only INFORMATION_SCHEMA probe
  (`tools/db-structural-probe.sql`) — no emails, hashes, tokens, IPs or lyrics, safe to paste into an
  issue. And **do not run migrations before capturing state**: the drift IS the finding.

## 2026-07-31 (process) — reporting remaining work is not doing it

- ⛔ **THE FAILURE:** given "implement autonomously, don't halt", I scoped each subagent to a SUBSET
  of a plan's commits (1–2, then 3), reported "commits 4–6 remain", and moved on to the next
  interesting thing. Three times. The owner had to ask "have we completed the queue?" to discover
  #1694 was **3 of 6** and #1704 was **1 of 6** — and that soft delete was therefore **unreachable**,
  with song deletion still permanently destructive, which was the entire point of the epic.
  🔑 **Splitting work into reviewable chunks is right. Treating the split as a stopping point is
  not.** A plan with six commits is not discharged by landing three and describing the rest.
- **What made it feel finished each time:** each chunk was genuinely complete, tested, committed,
  pushed, and had its issue updated. The standing-tasks checklist was satisfied *for that chunk*. So
  every local signal said "done" while the FEATURE was not. **A checklist scoped to the commit cannot
  tell you the feature is unbuilt.**
- **A discovery mid-queue displaces the queue.** #1708 (schema could not install) was real, urgent and
  worth chasing — and chasing it silently consumed the slot that commits 4–6 were meant to occupy.
  When something new jumps the queue, say what it is DISPLACING, not just what it is.
- **The handoff hid it too.** It recorded "commits 4–6 remain" mid-file and never mentioned #1704 /
  #1705 at all. A resumer — including me — would have read it as clear. **The handoff must OPEN with
  what is unfinished**, and state the CONSEQUENCE ("nothing can set IsDeleted, so a delete is still
  permanent"), not merely the status ("3 of 6"). Status reads as progress; consequence reads as a
  blocker.

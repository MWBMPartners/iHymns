# 🧠 iHymns — Claude Memory

> **Portable working memory** for picking the project up on any device. High-signal
> quick-reference: current state, the live workflow, and hard-won gotchas. Pairs with
> `CLAUDE.md` (rules / guardrails — auto-loaded), `ProjectBrief.md` (full state snapshot),
> `project-rules.md` (detailed rules), and `sessions/<date>-HANDOFF.md` (session history).
> When something here goes stale, fix it **here and in the file it mirrors**.

_Last updated: 2026-07-28._

## Where things stand
- **Version:** `0.4000.0` (alpha, Phase 1) — authoritative source is `includes/infoAppVer.php`
  (auto-bumped by `version-bump.yml` on push to **beta**, not alpha; the PWA service-worker cache
  version auto-syncs off it, #81). Docs that hardcode a version rot within days — point at the file.
- **Environments / deploy:** single shared MySQL; `alpha` → `dev.ihymns.app` (auto-merge + SFTP),
  `beta` → `beta.ihymns.app`, `main` → `www.ihymns.app`. Most work targets **`alpha`**.
- **Reads are LIVE MySQL** — there is NO `songs.json` corpus cache (epic #1010). Scoped reads only
  (`getSongsSlimIndex` / `getSongs($abbr)` / `getSongById`); a DB outage is a themed 503, never stale.
- **Active branch (2026-07-28):** **`claude/observability-alpha-3k9wqz`** — THE single WIP branch
  (90+ commits ahead of alpha, pushed, no PR). It subsumed
  `claude/apple-branches-cleanup-export-7mxhpo` via merge `753ed895`; that branch's PR #1578 must be
  CLOSED as superseded, not merged. See the handoff for the exact branch-deletion list.
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

## Recent landings (2026-07-28 — on `claude/observability-alpha-3k9wqz`, NOT yet on alpha)
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
  `api-docs.yaml` re-checked against the code. See the gotcha above.
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

## Offline download is broken in 7 ways (not yet fixed — see the handoff)
Worst: the SW caps `RECENT_CACHE` at 2000 and trims on **every song view**, so bulk downloads silently
self-destruct (14k-song download → 12,001 entries deleted on the next song opened). Offline navigation
is also dead for every non-song fragment, and each deploy wipes all downloaded audio.

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
  `'json'` line — never a new column, never a second tier matrix.
- After every substantive piece of work, run **`.claude/standing-tasks.md`** (issues, milestones,
  wiki, .md docs, and these `.claude/` files).

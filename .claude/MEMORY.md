# 🧠 iHymns — Claude Memory

> **Portable working memory** for picking the project up on any device. High-signal
> quick-reference: current state, the live workflow, and hard-won gotchas. Pairs with
> `CLAUDE.md` (rules / guardrails — auto-loaded), `ProjectBrief.md` (full state snapshot),
> `project-rules.md` (detailed rules), and `sessions/<date>-HANDOFF.md` (session history).
> When something here goes stale, fix it **here and in the file it mirrors**.

_Last updated: 2026-06-10._

## Where things stand
- **Version:** `0.990.0` (alpha, Phase 1) — authoritative source is `includes/infoAppVer.php` (bumped from 0.880.0 on 2026-06-10; the PWA service-worker cache version auto-syncs off it, #81).
- **Environments / deploy:** single shared MySQL; `alpha` → `dev.ihymns.app` (auto-merge + SFTP),
  `beta` → `beta.ihymns.app`, `main` → `www.ihymns.app`. Most work targets **`alpha`**.
- **Reads are LIVE MySQL** — there is NO `songs.json` corpus cache (epic #1010). Scoped reads only
  (`getSongsSlimIndex` / `getSongs($abbr)` / `getSongById`); a DB outage is a themed 503, never stale.
- **Active programs:** Song Editor full rewrite (#1200, branch `claude/song-editor-rewrite-phase0`,
  shipped as ONE PR at the very end); lyrics-platform one-pass schema (#1066 / #1088 — additive,
  dormant tables, VARCHAR-not-ENUM).

## Workflow (locked)
- **One PR per piece of work → `alpha`**, multiple atomic commits. Descriptive branch
  `claude/<topic>-<suffix>`, **always branched off the latest `origin/alpha`** — PRs **squash-merge**,
  so branching off a stale feature branch makes the next PR re-show already-merged commits.
- Alpha PRs **auto-merge** once CI is green (`Lint & Validate` + `PHP 8.4` + `PHP 8.5`);
  `github-actions[bot]` does the merge. Webhooks DON'T deliver CI-success / merge transitions.
- After merge, **close the tracking issue** with the squash SHA + evidence — `Closes #N` does NOT
  auto-close from an `alpha` merge (GitHub only auto-closes on the default branch).
- Every user-reported bug / feature gets a **tracking issue BEFORE** its closing commit.
- Push with `git push -u origin <branch>`; retry on network error with backoff.

## Gotchas (hard-won — read before debugging a blank/odd page)
- **mysqli THROWS; it does NOT return `false`.** `getDbMysqli()` sets
  `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)`, so `if ($res = $db->query(...))` /
  `if ($stmt = $db->prepare(...))` guards are **dead code** — a failing query raises
  `mysqli_sql_exception`. Gate optional tables with an INFORMATION_SCHEMA existence probe and wrap
  page-load detection in `try/catch`, so schema/migration drift degrades to a themed error card,
  **never a blank page**. This white-screened `/manage/duplicate-songs` (#1228 → fixed #1229).
- **Migrations are NOT auto-applied on deploy.** Code ships to `alpha`; the matching migration must be
  run via `/manage/setup-database`. Any page querying a freshly-added table/column must tolerate it
  being absent until then.
- **`git stash` during a branch switch with only UNTRACKED changes saves nothing** → a later
  `git stash pop` pops a STALE stash. Stage explicit pathspecs; avoid blind `git stash pop`
  (cost us a conflicted file 2026-06-08).
- **Never write `<?=` / `<?php` / `<?` inside HTML comments or backticks in `.php`** — PHP parses the
  open-tag regardless of `<!-- -->`; on 8.1+ `func(...)` is first-class-callable → fatal + truncated
  response (took down alpha in #536). CI greps for this.
- **Never hardcode `data-bs-theme` on an admin page** — `admin-theme-init.php` resolves it from
  `localStorage` at runtime (#955).

## Recent landings (2026-06-10, all on `alpha`)
- **#1224** Duplicate & Counterpart Review (`/manage/duplicate-songs` unified).
- **#1226** standard theme vocabulary (#1152) + tag canonicalisation (#1222) + Catalogue→"Collection"
  UI rename (#1223). Internals stay `catalogue`.
- **#1227** unofficial-songbook badge (`.songbook-unofficial-badge`, #1223) + the deferred docs for
  #1226.
- **#1229** fix: `/manage/duplicate-songs` blank-page (the mysqli-STRICT gotcha above, #1228).
- Issues closed: #1152, #1222, #1223; #1228 fixed.

## Key pointers
- Duplicate / counterpart scoring → `includes/song_similarity.php` (the ONE scorer; never re-fork).
  Exact dedup fold → `ihymns_normalize_title()` (`title_normalize.php`); fuzzy fold → `ihymns_sim_normalise()`.
- DB access → `includes/db_mysql.php::getDbMysqli()` ONLY (no PDO, no raw `new mysqli`); always `bind_param`.
- Songbook official/unofficial → `tblSongbooks.IsOfficial` (DEFAULT 0 = curated/pseudo-songbook);
  badge via shared `.songbook-unofficial-badge` on home + `/songbooks` + songbook header.
- After every substantive piece of work, run **`.claude/standing-tasks.md`** (issues, milestones,
  wiki, .md docs, and these `.claude/` files).

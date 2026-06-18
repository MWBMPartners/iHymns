# Lyrics cutover — beta + production PROMOTION checklist (#1235 P4)

> **Goal:** get the drop-safe code (≥ C4 read-gating + C5 write-inversion) live on **all three**
> environments that share the one MySQL, so the C6 `DROP COLUMN` can run **once** on the shared DB
> without breaking a lagging env. **This is the hard prerequisite for the drop** — it cannot happen
> until this checklist is complete.
>
> Companions: `lyrics-normalisation-strategy.md` (§0/§11) · `lyrics-cutover-dress-rehearsal.md` ·
> `sessions/2026-06-12-HANDOFF-session4.md` (the 2026-06-13 audit that established the topology).

## The situation (verified 2026-06-13)
- **One shared DB.** `origin/alpha` runs **P3** (`7168606d`); **`origin/beta` and `origin/main` carry
  NO lyric-normalisation code at all (pre-P1)**; drop-safe C4/C5/C6 is only on the unpushed
  `feat/lyrics-1235-p4`. So **none of the three live envs is drop-safe today** (all reference
  `tblSongComponents.LinesJson` in ungated SQL).
- alpha is **55 commits ahead of beta** and **268 ahead of main**, so this is a **large general
  alpha→beta→main catch-up** (the whole DB-direct rewrite + everything since), of which the lyric
  stack is one part — NOT a lyric-only promotion. (The "beta→main promotion" already noted as pending
  for the live-prod `songs_json`/sitemap 500s is the same promotion.)

## Two separate tracks (don't conflate them)
Because the DB is **shared**, schema and code move independently:

**Track A — schema/migrations (run ONCE, on the shared DB, via alpha).** Migrations are not
auto-applied; whoever runs them mutates the one shared DB, so beta/prod do **not** re-run them — they
inherit the migrated schema. Ensure these are applied (via alpha `/manage/setup-database → Apply all`):
`song-part-types` (PartTypeSlug), `lyric-lines-mirror` (ChordsJson/Note + backfill),
`component-line-languages`, `lyric-lines-parttypeslug`. After this the shared DB is in the
**transitional dual state** (LinesJson + a fully-synced mirror). The C6 drop is NOT part of this track.

**Track B — code promotion (alpha→beta→main).** Get ≥C5 code onto beta then production so they read
from the mirror + gate every LinesJson access. **This is the long pole.** No migrations needed here —
the shared DB is already migrated (Track A).

## Checklist (in order)

### 0. Land the cutover on alpha
- [ ] Push `feat/lyrics-1235-p4`; review + merge **PR #1262** → alpha. Alpha is now the first drop-safe env (C6 code present but dormant/gated).
- [ ] Run the **dress rehearsal** (`lyrics-cutover-dress-rehearsal.md`) on a local copy first.

### 1. Soak on alpha (≥7 nights)
- [ ] Track-A migrations applied on the shared DB (above).
- [ ] `php appWeb/.sql/verify-lyrics-cutover.php --phase=pre` green; nightly `--phase=soak` green (parity 0).
- [ ] **Guardrail:** keep lyric/component editing **alpha-only** for the whole cutover. Beta/prod run
      pre-P1 code; if a curator edited lyrics there, the write would update `LinesJson` only and orphan
      `tblLyricLines` (breaking the soak's G2 parity). Non-lyric writes (users/setlists/config) are fine.

### 2. Promote alpha → beta
- [ ] Open the alpha→beta promotion PR (carries the full accumulated stack incl. P1→C6).
- [ ] **Resolve the known conflicts** (recipe, validated on #913): `CHANGELOG.md` = lossless union,
      newest-first; `infoAppVer.php` = take the **ahead** (alpha) version; merge target-into-ahead +
      FF push to unblock.
- [ ] Confirm the deploy actually fired — **GITHUB_TOKEN anti-recursion**: an auto-merge push doesn't
      trigger `deploy.yml` (`on: push`); the bridge dispatches it via `workflow_dispatch`. Verify beta
      redeployed (don't assume).
- [ ] **Verify beta against the shared DB:** beta now runs ≥C5 against the already-migrated shared DB.
      Open a beta song page (reads must assemble from `tblLyricLines`), and confirm a beta save round-trips
      (it now goes through `lyricLinesWriteComponents`). `--phase=soak` still green.

### 3. Promote beta → main (production)
- [ ] Open the beta→main promotion PR; resolve CHANGELOG/infoAppVer the same way; confirm the prod deploy fired.
- [ ] **Verify production:** public song pages render from `tblLyricLines`; spot-check a few songs match alpha.
- [ ] Now **all three envs are ≥C5** and the shared DB is migrated → the shared DB is drop-safe.

### 4. The drop — ONCE, on the shared DB (Gate C/D)
- [ ] Fresh **tested** backup taken (`backup.php`) + restore rehearsed (per the dress rehearsal).
- [ ] **Freeze all three UIs** — each has its OWN flag: set `maintenance_mode_alpha`, `_beta`,
      `_production` (don't miss one; the freeze is per-env even though the DB is shared).
- [ ] `php appWeb/.sql/verify-lyrics-cutover.php --phase=pre-drop` → green sentinel (<24h).
- [ ] Run `retire-component-lines-json` (setup-database card with `confirm=1`, or CLI). One `DROP COLUMN`
      on the shared DB — affects all three at once; all three are drop-safe.
- [ ] `php appWeb/.sql/verify-lyrics-cutover.php --phase=post-drop` (Gate D) green; smoke all three envs.
- [ ] Lift the three maintenance flags.
- [ ] (Recovery, if needed) `php appWeb/.sql/regenerate-lines-json-from-lines.php`.

## Owner decisions / confirms (not derivable from the repo)
- [ ] **Confirm the shared DB at the live layer** — the `/manage/setup-database` connection banner shows
      identical host/DB on dev.ihymns.app, beta.ihymns.app, www.ihymns.app (the gitignored creds can't be
      proven from the repo).
- [ ] **Schedule the alpha→beta→main promotion** — the drop is blocked behind it. It's a large promotion
      (268 commits to main) carrying the whole DB-direct + lyric stack; decide whether to do it as the
      already-pending general promotion or a dedicated one.
- [ ] **Maintenance-window approval** for step 4 (minutes for the `DROP COLUMN`, but inside a freeze).

## Why not a dump-import instead (decided)
Rejected: it relocates the same code-version-skew risk without removing it (a thin-schema import breaks
pre-C5 code on the shared DB identically), `restore.php` replaces ALL tables in the one shared DB
(hitting all three envs), the freeze is per-env (three flags), and it's hours of downtime vs a minutes
`DROP COLUMN`. The dump's value is the **local dress rehearsal**, not the live mechanism.

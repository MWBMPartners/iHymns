# Lyrics cutover — local DRESS REHEARSAL runbook (#1235 P4)

> Validate the **entire** P4 cutover chain — read switch (C4) · write inversion (C5) · the
> `DROP COLUMN` (C6) · the recovery (regenerate) — end-to-end on a **local throwaway copy** of the
> real database, BEFORE anything runs on the shared live DB. This is not optional polish: because
> alpha/beta/production **share one MySQL** (`ihymns@mysql.MWBMpartners.ltd`), there is **no live
> environment you can safely test the drop on** — alpha *is* production's data. The local copy is
> the only place the irreversible drop can be rehearsed.
>
> Companion docs: `lyrics-normalisation-strategy.md` (§0 RESUME, §11 plan) ·
> `lyrics-cutover-promotion-checklist.md` (getting beta/prod drop-safe) ·
> `sessions/2026-06-12-HANDOFF-session4.md` (the build + the audit).

## ⚠ Safety / PII
The DB dump contains **user PII** (accounts, emails, setlists). Keep it **local only**, never
commit it, and **purge it** (and the throwaway DB) when done. Do the whole rehearsal on a local
MySQL — never point it at any live host.

## What state you start from
A fresh dump of the shared DB is at roughly **P3** today: it already carries BOTH the legacy
`tblSongComponents.LinesJson`/`ChordsJson`/`NotesJson`/`LanguagesJson` columns AND the normalised
`tblLyricLines` mirror (alpha's P1 migration ran on the shared DB). So the rehearsal exercises the
realistic live path: *transitional dual state → finish migrations → C5 writes → drop → recovery.*

## Prerequisites
- A local MySQL 8 (Docker or native) you can freely create/drop databases on.
- PHP 8.x CLI.
- A **fresh** gzip dump of the shared DB. Take one via **/manage/setup-database → "Download DB
  backup" (#1255)** or `php appWeb/.sql/backup.php` → `appWeb/data_share/backups/ihymns-backup-*.sql.gz`.
- A checkout of **`feat/lyrics-1235-p4`** (the branch with C1–C6).

## Procedure

### 1. Stand up a throwaway DB + import the dump
```bash
mysql -e "CREATE DATABASE ihymns_rehearsal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c /path/to/ihymns-backup-YYYYMMDD-HHMMSS.sql.gz | mysql ihymns_rehearsal
```
(Or use `appWeb/.sql/restore.php` against the throwaway DB.)

### 2. Point a local checkout at the throwaway DB
Create `appWeb/.auth/db_credentials.php` (gitignored) with `DB_HOST=127.0.0.1`, `DB_NAME=ihymns_rehearsal`,
a local `DB_USER`/`DB_PASS`, `DB_PORT=3306`. Check out `feat/lyrics-1235-p4`.

### 3. Finish the additive migrations (get the mirror fully present + backfilled)
Run each, idempotent, in order — CLI:
```bash
php appWeb/.sql/migrate-song-part-types.php            # PartTypeSlug column (#1138 — the C4/C5 gate REQUIRES it)
php appWeb/.sql/migrate-lyric-lines-mirror.php         # ChordsJson/Note on tblLyricLines + backfill (no-op if done)
php appWeb/.sql/migrate-component-line-languages.php   # tblSongComponents.LanguagesJson
php appWeb/.sql/migrate-lyric-lines-parttypeslug.php   # PartTypeSlug backfill
```
(Or load `/manage/setup-database` locally and click **Apply all pending** — the destructive C6 card
is excluded from it by design.) After this, `lyricLinesMirrorPresent()`/`lyricLinesSyncReady()`
return true (ChordsJson + Note + PartTypeSlug all present on `tblLyricLines`).

### 4. Prove pre-drop parity (Gate A)
```bash
php appWeb/.sql/verify-lyrics-cutover.php --phase=pre
```
**Expect:** all gates `PASS` + `sentinel: lyrics_cutover_gate written (green)`. If any gate fails,
STOP — the mirror isn't a faithful superset; investigate before going further.

### 5. (Optional) exercise the C5 inverted write path
Load the editor locally, save a few songs (edit lyrics, chords, per-line language), restore a
revision, run an import. Then:
```bash
php appWeb/.sql/verify-lyrics-cutover.php --phase=soak
```
**Expect:** parity still 0 — proving C5 keeps `tblLyricLines` (authoritative) and the `LinesJson`
shadow byte-consistent through real saves. This is the local analogue of the alpha soak.

### 6. Rehearse the drop (Gate C)
```bash
php appWeb/.sql/verify-lyrics-cutover.php --phase=pre-drop     # writes the pre-drop/green sentinel (<24h)
php appWeb/.sql/migrate-retire-component-lines-json.php   # CLI: no confirm=1 needed (CLI is deliberate); web needs &confirm=1
```
**Expect:** Stage 0 prints `[OK]` for sentinel + live-count match + line-count parity + 0 NULL
ComponentId + `✔ Gate satisfied`, then drops the 4 columns (LinesJson FIRST). **Negative checks to
try:** run it WITHOUT the pre-drop sentinel (delete the `tblAppSettings['lyrics_cutover_gate']` row)
→ expect `[REFUSE]`; run it via a browser without `&confirm=1` → expect `[REFUSE]`.

### 7. Prove the post-drop state (Gate D)
```bash
php appWeb/.sql/verify-lyrics-cutover.php --phase=post-drop
```
**Expect:** green (G2 skipped — no JSON source left; structural gates pass). Then **smoke-test** the
app against the now-thin schema: open a song page (reads assemble from `tblLyricLines`), edit + save a
song, restore a revision, run an import. None should reference the dropped columns (the CI guard
`tests/php/test-component-json-guard.php` already proves the code is gated; this confirms it at runtime).

### 8. Rehearse the recovery (reversibility layer 3)
```bash
php appWeb/.sql/regenerate-lines-json-from-lines.php
php appWeb/.sql/verify-lyrics-cutover.php --phase=pre          # re-prove parity after rebuild
```
**Expect:** the 4 columns are re-added + rebuilt from `tblLyricLines`, LinesJson re-tightened to
NOT NULL, and `--phase=pre` green again — proving the drop is reversible without a backup restore.

### 9. Teardown
```bash
mysql -e "DROP DATABASE ihymns_rehearsal;"
shred -u /path/to/ihymns-backup-*.sql.gz   # purge the PII-bearing dump (or rm)
```
Remove the throwaway `.auth/db_credentials.php` / restore your normal one.

## Success criteria (all must hold)
- §4 `--phase=pre` green; §5 `--phase=soak` parity 0 after real saves.
- §6 Stage-0 gate passes on the happy path AND **refuses** without the sentinel / without `confirm=1`.
- §7 `--phase=post-drop` green + app smoke (read/save/restore/import) works on the thin schema.
- §8 recovery rebuilds the columns + re-proves parity.

If every box is ticked, the live cutover is de-risked to "the same procedure on the shared DB, inside
a freeze, after C4/C5 is on all three envs." If any step fails, capture the output and fix before the
live run — the local copy is exactly where that's cheap.

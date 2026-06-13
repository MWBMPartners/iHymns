# Session handoff — 2026-06-12 (session 4): #1235 P4 — C4 cleanup + C5 write inversion BUILT + reviewed

> Continues `2026-06-12-HANDOFF-session3.md`. Plan of record: `.claude/lyrics-normalisation-strategy.md`
> §11 (P4 cutover) + §12 (data-quality). This session finished **C4 cleanup** and built the big
> **C5 write inversion**, then ran an adversarial multi-agent review and fixed all 5 confirmed bugs.
> **★ RESUME = commit C4-cleanup + C5 → owner review + ≥7-night alpha soak → C6 (the drop).**

## TL;DR — exact resume point
- Branch `feat/lyrics-1235-p4` (draft PR **#1262** → alpha). C1–C4 were already committed (sessions 1–3).
  **This session's C4-cleanup + C5 work is in the WORKING TREE, NOT yet committed** (the global rule:
  no commit without an explicit ask — the user has not yet said "commit").
- **CI-green locally**: `php -l` clean on all touched files; `test-lyric-lines-read` (9), `test-lyric-lines-diff`
  (51, +4 new for `lyricLinesBuildDesiredFromComponents`), `test-line-enrichment` (21), the NEW
  `test-component-json-guard` (passes + negative-tested), `test-schema-coverage`, `test-migration-registry`
  all PASS.
- **Next:** commit (two atomic commits suggested — C4-cleanup, then C5+fixes) → owner soak on alpha → C6 drop.

## What was built

### C4 cleanup — repoint the 3 preview-line bypass readers off `LinesJson`
- `includes/lyric_lines_read.php`: `lyricLinesFirstLine()` now returns the first **non-empty** line;
  added `lyricLinesFirstLineMap()` (chunked bulk, #929) + the shared `lyricLinesMirrorPresent()` gate +
  `lyricLinesEditableComponents()` (editor/snapshot shape from `tblLyricLines`, drop-safe).
- `SongOfTheDay::firstLine`, `manage/duplicate-songs.php` (candidate chunks), `tools/build-song-link-suggestions.php`
  (whole corpus, 2-pass) → assembler-first with a marked `lines-json-fallback` legacy branch.
- `SongData::_hasLyricLinesMirror()` delegates to the shared probe (removed the duplicate INFORMATION_SCHEMA).

### C5 — write inversion (`tblLyricLines` becomes the write source of truth; JSON columns are a shadow)
- **Keystone `includes/lyric_lines_sync.php`:**
  - `lyricLinesApplyDesired()` — extracted the Id-preserving diff/dirty-check/PF2 write loop (shared by the
    legacy backfill projector AND the cutover path).
  - `lyricLinesWriteComponents($db,$songId,$components)` — the cutover write entry: (1) Id-stable thin-component
    upsert BY POSITION (`lyricLinesUpsertComponents`, never DELETE+reinsert) + column-existence-gated shadow JSON
    (`lyricLinesShadowColumnsPresent`); (2) `lyricLinesBuildDesiredFromComponents()` (PURE, payload-sourced, slug
    resolver injected) builds desired lines WITHOUT reading LinesJson; (3) `lyricLinesApplyDesired()`.
  - `lyricLinesProjectSong()` kept LinesJson-sourced for the BACKFILL migration only (now no-ops post-drop).
- **Live funnels inverted** (mirror → shared write; un-migrated → marked legacy LinesJson fallback):
  `save_song` (manage/editor/api.php — the live primary; PF1 carry re-sourced from the assembler; chord-clean kept
  at the funnel boundary; component DELETE removed from the shared child-table loop), `song_importers.php`,
  `lyrics_ingest.php`.
- **v2 ed2 helpers (api2.php) made drop-safe:** `ed2_rebuildLyricsText` rebuilds LyricsText from `tblLyricLines`
  (no projector call); `ed2_buildSongSnapshot` + `ed2_currentComponents` source components from the assembler;
  `ed2_persistComponents` is the one drop-safe write; `ed2_applySongSnapshot` (restore_revision, LIVE) + the
  granular funnels (`component_upsert/delete/reorder/components_replace`) read-modify-write through the shared path.
- **CI grep guard** `tests/php/test-component-json-guard.php` (token_get_all, skips comments; guards the
  unambiguous trio LinesJson/NotesJson/LanguagesJson; allows gated/`lines-json-fallback`-marked sites; file-allowlists
  the one engine file) + wired into both `test.yml` jobs. +4 unit tests for the new pure builder.

## Adversarial review → 5 confirmed bugs, ALL FIXED
A 4-skeptic + per-finding-verifier workflow (wf_443132d1) confirmed 5 real bugs (and dismissed 3 as not-bugs):
1. **BLOCKER — read/write gate divergence.** `lyricLinesMirrorPresent` checked only table-existence, but the
   assembler `SELECT ll.ChordsJson` throws if the mirror columns aren't there (the table is built across 3
   migrations). **FIX:** both `lyricLinesMirrorPresent` (read) and `lyricLinesSyncReady` (write) now require
   `tblLyricLines.ChordsJson + Note + PartTypeSlug` — aligned, so reads + writes flip to the mirror together.
2. **HIGH — over-length chord array broke G2.** Shadow ChordsJson stored the whole array; the per-line write
   only stores cells for existing lines. **FIX:** shadow ChordsJson/NotesJson CLAMPED to line-count.
3. **HIGH — `component_upsert` mid-list insert** scrambled ComponentId + returned the wrong id (stable usort +
   position-match). **FIX:** new components append (no usort); update replaces in place → position-match stays Id-stable.
4. **HIGH — `save_song` PF1 reattach key** used the raw POST type while the snapshot key used the stored
   (normalised) type. **FIX:** normalise type/number in the reattach key identically.
5. **MEDIUM — `lyricLinesProjectSong` ungated post-drop** (reachable via the "Run Lyric-lines Mirror Migration"
   button). **FIX:** no-ops when `LinesJson` is gone. (+ defensive `array_values` on the pure builder's arrays.)

**Not-bugs (confirmed):** empty 0-line component divergence (pre-existing C4, corpus-safe, intentional);
guard whole-file allowlist (the allowlisted refs ARE gated/backfill); api2 `isset` vs api.php `array_key_exists`
asymmetry (no loss).

## Deferred → ACTIONED (post-review, this session)
- **Legacy `load_song` raw per-line `languages` post-drop — ACTIONED** (`manage/editor/api.php` load_song):
  when `LanguagesJson` is gone (post-C6), the per-line override array is now DERIVED from the assembler's
  effective `comp.lineLanguages` (effective ≡ override under the inherit rule), so the legacy editor keeps
  per-line language editing after the drop instead of silently dropping the overrides.
- **editor.js R7b chord lineId re-anchor — FILED #1263 (`for consideration`), not actioned in code.** Investigation
  concluded it's NOT validly actionable in the legacy TEXTAREA editor: chords/languages are parallel textareas
  (positional by design), and load-time `comp.lineIds` go stale on any reorder, so lineId-keying is unreliable
  from free text. The data-integrity edge is already closed server-side (C5 fix B2 clamp + under-length padding).
  True per-line chord anchoring needs the per-line-DOM rewrite editor (#1200); #1263 captures the full requirement.

## C6 — THE DROP — BUILT + reviewed + fixed (uncommitted; RUN is owner/soak-gated)
The final code phase. **All CI-green locally; the RUN is owner-gated (≥7-night soak + Gate-C freeze).**
- **`appWeb/.sql/migrate-retire-component-lines-json.php`** — staged, idempotent. Stage 0 REFUSES (clean no-op
  return, no drop) unless: a `confirm=1` web gate (so "Apply all" can never trigger it); the C3 sentinel
  `tblAppSettings['lyrics_cutover_gate']` is phase=pre-drop / green / <24h; the sentinel fingerprint counts
  {songs,components,lines} still match live; per-song `SUM(JSON_LENGTH(LinesJson))` == mirror line count;
  0 NULL ComponentId. Stages 1–4 `columnExists`-guarded DROP, **LinesJson FIRST** (its absence is the canonical
  "retired era" signal the resurrection guards key on, robust to a partial run).
- **`appWeb/.sql/regenerate-lines-json-from-lines.php`** — reversibility layer 3: re-ADDs the 4 columns +
  rebuilds them from `tblLyricLines` (re-tightens LinesJson to NOT NULL). Recovery without a backup restore.
- **`schema.sql`** thin tblSongComponents mirror (4 column lines deleted + retag) + **`@migration-drops`
  Signal-5** in `schema_audit.php` (removes dropped cols from migration coverage → `test-schema-coverage`
  passes the deletion). The stale tblLyrics header comment fixed.
- **Probe-resurrection fix**: the interchange-fidelity + component-line-languages registry probes now
  `columnExists(LinesJson) && !columnExists(X)` — never "pending" post-drop (no re-add). **Retired-era no-op
  guards** added to migrate-{interchange-fidelity, component-line-languages, normalize-lyrics, json,
  lyric-lines-mirror} (skip when LinesJson is gone).
- **Registry entry** `retire-component-lines-json` (LAST; probe = `columnExists(LinesJson)`).

### C6 adversarial review → 4 real bugs, ALL FIXED
A 4-dimension skeptic+verifier workflow confirmed 4 bugs — all from the same root (a destructive, manual-only,
gated migration doesn't fit the binary pending-probe + "Apply all" model):
1. **HIGH** — JS "Apply all" would EXECUTE the irreversible drop (probe perpetually pending), relying only on
   the Stage-0 gate which is OPEN during the freeze.
2. **MEDIUM** — the no-JS "Apply all" loop hard-FAILS/halts on the always-pending probe.
3. **MEDIUM** — the pending counter never reaches zero on pre-drop installs.
4. **MEDIUM** — `generate-full-sql.php` (legacy full dump) emits the retired LinesJson / no tblLyricLines → a
   regenerated dump throws + is structurally divergent.
**Fix:** a **`'manual' => true`** registry flag honoured by setup-database (`$migrationManual`) — EXCLUDES the
drop from the JS bulk-runner list, the no-JS apply-all loop, and the pending counter; danger-styles its card;
and gates its card/pending-list run-links + the script itself on **`confirm=1`** (the `drop-legacy` pattern).
`generate-full-sql.php` now refuses to run (deprecated; points to schema.sql + Apply-all). Bugs 1–3 = the
`manual` flag; bug 4 = the deprecation.

## Still deferred (documented, NOT cutover-blocking)
- **Empty-component G1/G2** — if a curator creates a 0-line component, the public assembler drops it while the
  editor/shadow keep it; consider pruning empties in the write path or surfacing them in the assembler.
- **Legacy `ihymns-full.sql`** is long-stale (pre-dates ~39 tables incl. tblLyricLines) — the generator is now
  disabled, but the committed dump could be removed in a cleanup; README/DEV_NOTES "instant install via the
  full dump" should be marked deprecated (C7 doc task, when P4 lands).

## Owner actions pending
1. **Review + commit** this session's C4-cleanup + C5 (the work is verified + reviewed, awaiting the commit ask).
2. **≥7-night alpha soak** of the inverted write path. To light it up, ensure these migrations are applied on
   alpha via Setup-Database: `migrate-song-part-types` (PartTypeSlug column, #1138 — the gate now REQUIRES it),
   `migrate-lyric-lines-mirror` (ChordsJson/Note), `migrate-component-line-languages`, `migrate-lyric-lines-parttypeslug`
   (C2 backfill). Then `php tools/verify-lyrics-cutover.php --phase=pre` and nightly `--phase=soak` (parity 0).
   **Code degrades gracefully (falls back to LinesJson) if any mirror column is absent — no breakage, just the
   mirror isn't used until applied.**
3. **C6 (the drop)** — owner/time-gated after the soak: `migrate-retire-component-lines-json.php` (staged,
   abort-on-mismatch + sentinel<24h) + schema.sql byte-mirror + `@migration-drops` scanner support +
   probe-resurrection fix + retired-era no-op guards in the 4 legacy migrations + `regenerate-lines-json-from-lines.php`.
   Behind a #1234 freeze + tested backup (Gate C). Then Gate D post-drop.

## Key facts for the resumer
- **The mirror gate now means "ChordsJson+Note+PartTypeSlug all present"** (not just the table) — `lyricLinesMirrorPresent`
  (read, lyric_lines_read.php) and `lyricLinesSyncReady` (write, lyric_lines_sync.php) are kept in lockstep on purpose.
- **`lyricLinesWriteComponents` is the ONE cutover write path**; `lyricLinesApplyDesired` is the ONE line-write loop;
  `lyricLinesBuildDesiredFromComponents` is its PURE payload→desired core (unit-tested). Never re-fork them.
- **Live funnels:** `save_song` (legacy api.php) is the primary save; `restore_revision`→`ed2_applySongSnapshot` is
  live; `component_upsert/delete/reorder/components_replace` are NOT wired in editor.js (dormant) but are fully
  inverted + drop-safe anyway.
- **Verify tools** (`tools/verify-lyrics-cutover.php`, `tools/export-fidelity-snapshot.php`) are DB-coupled — they
  run on alpha at the gates, not locally.
- Real DB dump (gitignored, PII) from session 3 is gone with the temp dir; re-decompress the latest backup for analysis.

## Standing-tasks status
- ✅ Memory (this handoff + MEMORY.md pointer + project memory updated). Strategy §0 + §11 progress updated.
- ⏸ Issues (#1261 P4 tracking) — update with the C4-cleanup+C5 SHAs AFTER commit.
- ⏸ CLAUDE.md rule (the "assembler/writeComponents is the ONE read/write path" rule) — add when P4 MERGES to alpha
  (not before — it would describe unlanded reality, per the session-3 decision).
- ⏸ Wiki / CHANGELOG / version bump — C7 (docs), after the drop.

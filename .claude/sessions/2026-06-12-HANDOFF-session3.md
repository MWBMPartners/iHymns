# Session handoff — 2026-06-12 (session 3): #1235 P3 SHIPPED + P4 cutover C1–C4 built

> Continues `2026-06-12-HANDOFF-session2.md`. **Plan of record for everything below:
> `.claude/lyrics-normalisation-strategy.md` §11 (the P4 cutover) + §12 (the data-quality
> finding).** This session shipped P3 to alpha and built the first four commits of the
> P4 cutover. **★ RESUME = the C4 bypass-reader cleanup, then C5 (write inversion).**

## TL;DR — exact resume point
- **`feat/lyrics-1235-p4`** (draft PR **#1262** → alpha, **kept draft so auto-merge can't land it**) has **C1–C4** of the P4 cutover (4 commits, all CI-green, zero-data-loss, revertable).
- **Next: C4 cleanup** (3 bypass readers still read `LinesJson`) → **C5 write inversion** (the big, complex phase) → **C6 the drop** (owner/time-gated: ≥7-night soak + manual gated run on alpha).
- Owner sign-off for P4 was **given** ("proceed with P4"); decisions locked: **C-variant**, **cutover-first**, **`tblSongChords` = chord home**, **`Source='ihymns'`-keyed reads**.

## What SHIPPED to alpha this session
**PR #1259 (MERGED, `7168606d`)** — the committed-but-unpushed #1235 **P2b + P3** (Id-preserving diff, per-line language + translation/annotation editor) **plus two data-loss fixes the P4 planning pass found:**
- **PF1 / R1** (#1257 **CLOSED**): a stale SW-cached editor POSTing components without `chords`/`languages` wiped them song-wide. Fixed with server-side **carry-forward** across all three write paths — `api.php save_song` (snapshot-before-delete, FIFO by `Type|Number|line-count`, reattach when the key is OMITTED), `api2.php component_upsert` (conditional UPDATE), `api2.php components_replace` (position-matched, and it now persists per-line languages, which it never did).
- **PF2 / R3** (#1258 **OPEN** follow-up): `lyricLinesSnapshotDeletedEnrichment()` in `lyric_lines_sync.php` records any about-to-be-cascade-deleted enrichment into `tblActivityLog` (`Action='lyrics.enrichment_orphaned'`) before the diff DELETE — best-effort, no-op while dormant. #1258 tracks the fuller enrichment-aware diff + re-attach UX + annotation-offset clamping.

## The key strategic finding (§12) — pure-C is RE-OPENED
The owner challenged "pure-C is foreclosed by 23.8% duplicate part keys." Investigation of the real dump proved that figure is **scraped garbage**: **94%** (3,613 groups / 13,394 components) are identical-repeat refrains (hymnal "refrain after every verse" → collapse to 1 + an arrangement repeat); only **208 songs (1.3%)** have distinct same-key sections, and those are mostly *more* garbage (CP-0220's five `chorus` comps are `ALL`/`FIRST`/`SECOND` stage directions → belong in `tblVocalParts`; CP-0110 = two hymns merged + a `CHRISTMAS` heading-as-chorus). So on **clean** data pure-C is realistic. Logged as the **data-cleanup epic #1260** (`for consideration`): Phase 1 collapse repeats→arrangement, Phase 2 triage the 208, Phase 3 re-decide pure-C. **P4 ships the C-variant regardless** (it cements nothing).

## P4 cutover — what's built (branch `feat/lyrics-1235-p4`, draft PR #1262)
| Commit | What | Behaviour change? |
|---|---|---|
| **C1** `eb3dd686` | `includes/lyric_lines_read.php` — the shared line-first assembler (pure core `lyricLinesAssembleFromRows` + `Source='ihymns'`-keyed, chunked DB wrappers `fetchPrimary/+Map / assembleComponents/+Map / firstLine`). Byte-identical to `_getComponents`. +9 unit tests (`tests/php/test-lyric-lines-read.php`), CI-wired both `test.yml` call sites. | none |
| **C2** `303cf3d2` | `migrate-lyric-lines-parttypeslug.php` (idempotent allow-list backfill) + **slug-at-write** in the projector (`lyricLinesPartTypeSlug()` + `PartTypeSlug` threaded through buildDesired/SELECT/INSERT/UPDATE/dirty-check — bind strings re-counted: INSERT `iissiissssi`, UPDATE `issiissssii`) + ONE registry entry. Closes the #1138 NULL-everywhere gap. Data-only, no schema.sql delta. | none |
| **C3** `10c2e407` | `tools/verify-lyrics-cutover.php` (gates G1/G2/G3/G5/G6/G7/G8/G9; **G2 = the losslessness proof**: assembler output == LinesJson source byte-for-byte, NFC-aware; writes the `tblAppSettings['lyrics_cutover_gate']` sentinel the drop migration requires) + `tools/export-fidelity-snapshot.php` (`--out`/`--compare` per-song manifest). DB-coupled — runs at the gates on alpha. | none |
| **C4** `241a30e5` | **Read switch**: `SongData::_getComponents`/`_getComponentsMap` delegate to the assembler when the mirror is present; `LinesJson` survives only as `_getComponentsFromJson`/`…MapFromJson` (un-migrated fallback). Orphaned `_mirrorLinesByComponent[Map]()` removed. Revertable. | **reads only** (byte-identical) |

## P4 — what REMAINS (the risky part)
- **C4 cleanup** (small): 3 preview/compare bypass readers still crack `LinesJson` — `SongOfTheDay.php:246` (→ `lyricLinesFirstLine`), `manage/duplicate-songs.php:595` (SQL subquery — trickier), `includes/tools/build-song-link-suggestions.php` (→ firstLine/full text). Each needs a mirror-with-`LinesJson`-fallback switch.
- **C5 — write inversion** (THE big phase): the 5 save funnels (`api.php save_song`, `api2.php component_upsert/components_replace/restore`, `song_importers.php`, `lyrics_ingest.php`) invert to write lines first + **shadow-write** `LinesJson/ChordsJson/NotesJson/LanguagesJson` from the just-written lines (until the drop; `LinesJson` is `NOT NULL`). PF1 carry-forward must survive the inversion. `ed2_rebuildLyricsText` (api2.php:309) + the **R2 sites** — `ed2_buildSongSnapshot` (api2.php:371, runs on every mutation), `ed2_applySongSnapshot` (:438), the revision writers — must be **column-existence-gated** (under STRICT the drop would brick saves + make revisions unrestorable). The chord editor (`editor.js:1122`) re-anchors on `comp.lineIds` not array index (R7b). **The CI grep guard** (bans raw payload-column SQL outside migrations) lands HERE — it can't go earlier because writes still reference the columns until now.
- **C6 — the drop** (owner/time-gated): `migrate-retire-component-lines-json.php` (staged, Stage-0 abort-on-mismatch probes + sentinel `<24h` guard, `columnExists`-guarded DROPs) + schema.sql byte-mirror (delete the 4 column lines) + `@migration-drops` doctag support in `schema_audit.php` + the **probe-resurrection fix** (`columnExists(LinesJson) && !columnExists(target)` era detection) + retired-era no-op guards in the 4 legacy migrations + `regenerate-lines-json-from-lines.php` rebuild. Then **≥7-night soak** on alpha, then the drop **RUN MANUALLY per env** behind Gate C (freeze #1234 + tested backup).

## Verification gates (the discipline)
Gate A (read switch) → B (write inversion) → C (the drop is RUN) → D (post-drop). The DROP migration refuses to run unless the C3 sentinel says `phase=pre-drop, result=green, ranAt<24h` and the fingerprint matches live counts. **The gate is code, not process discipline.**

## Owner actions pending (nothing blocked on me)
1. **Review + soak #1262** (C1–C4) on alpha. To light it up: apply the **`component-line-languages`** (P3 `LanguagesJson`) + the new **`lyric-lines-parttypeslug`** migrations via Setup-Database "Apply all", then run `php tools/verify-lyrics-cutover.php --phase=pre`. (Code degrades gracefully without the migrations — column-existence-gated.)
2. **beta→main promotion** (live-prod songs_json/sitemap 500s) — independent of the lyrics epic.
3. Decide whether/when to start the **data-cleanup epic #1260** (cutover-first means after P4).

## Issues touched this session
- **#1257** R1 carry-forward — **CLOSED** (fixed on alpha via #1259).
- **#1258** enrichment-integrity follow-up (R3) — open.
- **#1260** data-cleanup epic — open, `for consideration`.
- **#1261** P4 cutover tracking — open (the C1→C7 plan).
- **#1259** PR (P3 + PF1/PF2) — MERGED. **#1262** PR (P4 C1–C4) — OPEN DRAFT.

## Key facts for the resumer
- **The assembler is the ONE line reader** — `includes/lyric_lines_read.php`. Never re-fork mirror-reading logic (the old `_mirror*` methods were removed for exactly this reason). The pure core is unit-tested; DB wrappers chunk per-songbook (never whole-corpus, #929).
- **C-variant**: `tblSongComponents` stays as a THIN `Type/Number/SortOrder/Language` row; `tblLyricLines.ComponentId` is the grouping anchor (proven losslessly reconstructable — A1: 0 non-contiguous runs, 0 NULL ComponentId, group order ≡ SortOrder).
- **Real DB dump**: `appWeb/data_share/backups/ihymns-backup-20260612-070611.sql.gz` (gitignored; has user **PII**). Decompress to `/tmp` for analysis (purged at session end). Schema-only extract was at `/tmp/lyrics-ddl.txt`; the planning-pass outputs at `/tmp/p4_*.md`.
- **Corpus facts**: 16,083 songs / 70,132 components / 291,634 lines / parity 0 / 1:1 song:lyrics (all `Source='ihymns'`) / `ChordsJson`+`NotesJson` empty everywhere / `PartTypeSlug` was NULL everywhere (C2 fixes) / all enrichment tables 0 rows (the cheap-cutover window).
- **Deploy gotcha**: a `.sql/` migration requiring a NEW `public_html/includes/` file resolves it **`DOCUMENT_ROOT`-first** (the verify tools follow this). A runtime sibling-include (SongData → lyric_lines_read) is fine — both deploy together.
- **`.claude/lyrics-normalisation-strategy.md`**: §0 progress, §11 the full P4 plan-of-record (commits, gates, owner decisions, effort), §12 the data-quality finding. §11.5 has the G-gate table.
- Sibling **#1243** (musical metadata Key/Tempo/Time-sig/Capo) must consume the C1 assembler, never re-grow a parallel JSON array.

## Standing-tasks status
- ✅ Issues, Memory (auto + MEMORY.md), Context (ProjectBrief + strategy §0/§11/§12), History (this handoff).
- ⏸ CLAUDE.md: deliberately NOT adding a P4 "assembler is the one reader" rule yet — P4 isn't merged to alpha, so the rule would describe unlanded reality. Add it when #1262 (or the full P4) merges.
- ⏸ Wiki / CHANGELOG / version bump: deferred until P4 lands (C7 does docs).

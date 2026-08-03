# #1749 FULL UNIFICATION (option 3) — build spec: `tblSongExternalIds` becomes the AUTHORITY for recording IDs; `tblSongs.Isrc` becomes a kept-in-sync denorm

**Status:** build spec (owner escalation 2026-08-03: "#1749 do full unification now also").
**Branch:** `claude/wave3-fixes` (this spec verified against `e65b92a4`).
**Scope source:** issue #1749 option 3 — "Make `tblSongExternalIds` the single authority and demote
`tblSongs.Isrc` to a denorm — the fullest unification". Epic #1741; builds ON the #1747 backfill,
the P5d mirror and the Phase-1 union (`e65b92a4`) — none of which are redone here.
**Written for:** the implementing session. Every claim below was re-verified against the live tree
on 2026-08-03; corrections to stale claims are called out in §0.

---

## 0. Ground truth — every load-bearing claim verified (file:line), stale claims corrected

| # | Claim | Verdict | Evidence |
|---|-------|---------|----------|
| 0.1 | The store is populated by the #1747 backfill (5 sources, `SourceRef` names the origin column) | **TRUE** | `appWeb/.sql/migrate-backfill-song-external-ids.php:166-221`; registry entry + data-derived probe `manage/includes/migration-registry.php:3469-3552` |
| 0.2 | `/isrc/` resolves column∪store via `_ihymns_resolve_songs()` + the pure `_ihymns_resolve_use_store()` gate | **TRUE** | `includes/identifier_resolve.php:190-213` (pure builder), `:238-241` (gate), `:280-283` (bind), `:425-429` (isrc case) |
| 0.3 | The mirror is wired into editor + ingest funnels | **TRUE** | `songExternalIdMirrorIsrc()` `includes/song_external_ids.php:190-234`; called from `manage/editor/api2.php:1629` (metadata_field_update, in-txn), `:1131-1134` (snapshot restore), `includes/lyrics_ingest.php:648` + `:752` (both `'ihymns-ingest'`) |
| 0.4 | Ownership key is `SourceRef='tblSongs.Isrc'` | **TRUE** | const `SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF`, `song_external_ids.php:128`; byte-compared to the backfill's literal by `tests/php/test-song-external-id-mirror.php` (PASS on this branch) |
| 0.5 | **The native/API `isrc` key must stay byte-identical** (task framing) | **DISSOLVES — there is NO `isrc` key in any song payload.** | `grep -c Isrc includes/SongData.php` → **0**. `getSongs()` SELECT (`SongData.php:2167-2172`) and `_fetchSongRow()` (`:3777-3786`) emit `ccli`/`iswc` but never `isrc`. Zero `isrc` references in `appApple/` and `appAndroid/`. The ONLY public reads of `tblSongs.Isrc` are `api.php` `song_by_identifier` (`:1290-1322`, returns a song LIST — already multi-valued by shape) and the `/isrc/` page. MBID/Spotify/Genius are exposed NOWHERE (`tblSongIdentityMap` has no live reader — see 0.7). **The expected single-vs-array owner decision therefore does not exist** (§6). |
| 0.6 | Suites: "102 PHP / 49 node" | **STALE — 104 files in `tests/php/`**, 49 node. CI globs (`.github/workflows/test.yml:253-254`, `php tools/run-php-tests.php`), so the count self-corrects; use the runners, not the number. |
| 0.7 | `tblSongIdentityMap` is a live read path needing preservation | **FALSE — it is dormant.** No SELECT/INSERT/UPDATE anywhere outside migrations/probes; only mentions are `song_relocate.php:456` (re-key cascade list) and registry probes. Nothing ever wrote it (dormant #1066 batch, gated on the #1010 DB-merge decision). Drives the §2.3 freeze decision. |
| 0.8 | **NEW FINDING — merge silently DESTROYS store rows.** | `manage/duplicate-songs.php` `MERGE_FK_TABLES_SINGLE` (`:77-83`) omits `tblSongExternalIds`; the merge hard-deletes the duplicate (`:243-247`) and `fk_SongExternalIds_Song … ON DELETE CASCADE` (`schema.sql:3454-3455`) wipes its external-ID rows. Pre-existing data loss; fatal under store-authority. Fixed in §5.4; **file a discovery issue before the fixing commit** (§8). |
| 0.9 | **NEW FINDING — the panel already desyncs the column, by design-of-the-old-model.** | `api2.php:3044-3047`: deleting the mirror row via `song_external_id_delete` knowingly leaves `tblSongs.Isrc` stale ("the next ISRC save re-mints it"); `song_external_id_add` of an isrc row never populates an empty column. Both are exactly the desync class option 3 exists to kill (§5.3). |
| 0.10 | **NEW FINDING — a pre-P5a revision restore writes a MISMATCHED pair today.** | `ed2_applySongSnapshot()` writes the snapshot's RAW Isrc into the column (`api2.php:1104-1113`, trim only) but mirrors the CANONICAL fold (`:1131-1134`). An old `US-ABC-…` snapshot → column ≠ store IdValue. The §1 recompute-last invariant heals this automatically. |
| 0.11 | **NEW FINDING — ingest's ISRC identity-resolve reads the column only, raw-bound.** | `lyricsIngest_resolveSong()` `includes/lyrics_ingest.php:458-469`: `WHERE Isrc = ?` with the payload value trimmed but NOT canonicalised, no store arm, no ORDER BY under LIMIT 1. §5.5 fixes all three. |
| 0.12 | `tblSongs.Isrc` internal consumers that keep working via the denorm | **VERIFIED**: `song_by_identifier` (`api.php:1293`), duplicate detection (`manage/duplicate-songs.php:628,670,802,1073`), scorer input (`includes/song_similarity.php:180`), suggestion builder (`includes/tools/build-song-link-suggestions.php:122,187,257`), auto-linker (`includes/tools/auto-link-hard-id-counterparts.php:145`), `v_ChristianSongs` (`schema.sql:3478`), ingest resolve (0.11). None needs a store JOIN once the denorm is lockstep-synced. |
| 0.13 | Column values are already canonical | **TRUE** — the P1 card canonicalised them (`appWeb/.sql/migrate-song-identity-fields.php:195-204`); live writes canonicalise (`api2.php:1490-1501`, `lyrics_ingest.php:570,713`). `tblSongs.Isrc` is `VARCHAR(15)` (`schema.sql:284`); a canonical ISRC is 12 chars (`media_identifiers.php` regex `/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/`). |
| 0.14 | Relocation (songbook move / SongId re-key) keeps store rows attached | **TRUE** — `tblSongExternalIds` is in `song_relocate.php`'s cascade list (`:464`) and the FK is `ON UPDATE CASCADE` (`schema.sql:3454-3455`). No desync from moves. |
| 0.15 | Both existing guards pass on this branch | **TRUE** — `test-song-external-id-mirror.php` and `test-identifier-resolve-union.php` both PASS (run this session). Mirror guard's exempt list is **empty** (`:472`). |
| 0.16 | Migrations may `require_once` public_html includes | **TRUE** — precedent `appWeb/.sql/migrate-backfill-canonical-songids.php:78-83`. The §5.7 reconcile card uses the same idiom. |

---

## 1. Design — the target authority model (D-1, decided)

**The store (`tblSongExternalIds`) is the source of truth for recording IDs. `tblSongs.Isrc` is a
derived single-value projection of it — the "primary ISRC" denorm — kept in lockstep by ONE
projection function that has the LAST WORD inside every transaction that mutates the store.**

### 1.1 Write-path shape: column-write order KEPT, store gets the last word

The prompt's question — "store FIRST then sync the denorm, or column-first-then-mirror with the
store canonical for reads?" — is answered with the **third, safest shape**:

> Every existing funnel keeps its current statement order (column UPDATE → `songExternalIdMirrorIsrc()`),
> and the mirror itself gains a **closing store→column re-projection**
> (`songExternalIdSyncIsrcDenorm()`, §5.1). Store mutations that DON'T go through the mirror
> (panel add/delete, merge) call the sync explicitly. The projection is therefore always the final
> write against `tblSongs.Isrc` in any transaction that touched isrc data — the store has the last
> word, which is what "authority" operationally means.

Why this beats a literal store-first inversion:

- **(a) un-migrated degradation is free.** `songExternalIdSyncIsrcDenorm()` no-ops when the store
  table is absent (same memoised probe, `songExternalIdsTableExists()`, `song_external_ids.php:101`),
  leaving the funnel's direct column write as the value source — **byte-identical to today's
  behaviour** on an install that never ran the D5 card. A store-first inversion would leave that
  install with NO isrc write path at all, violating the "un-migrated install degrades to today's
  column-authoritative behaviour" requirement.
- **(b) single-ISRC back-compat is structural.** `tblSongs.Isrc` still always holds exactly one
  canonical value (or NULL) — every 0.12 consumer unchanged.
- **(c) no funnel can half-do it.** Folding the sync into the mirror means all four existing mirror
  call sites (0.3) acquire the invariant with zero call-site edits; the only NEW obligations are
  the three non-mirror store mutations (§5.3, §5.4), and the tree-derived guard (§7.2) makes a
  future fourth one un-forgettable.
- **(d) it heals, not just prevents.** Any pre-existing disagreement (0.9, 0.10) is corrected the
  next time the song's isrc data is touched, and corpus-wide by the one-shot reconcile card (§5.7).

**Post-cutover invariant (state it verbatim in the helper's doc-block):**
`NULLIF(tblSongs.Isrc,'')` ⟺ the store projection of §2 — after every commit of every funnel:
editor field update, whole-song save (writes no Isrc — verified: zero `Isrc` in
`manage/editor/save_song_core.php`), revision restore, ingest create, ingest COALESCE fill,
panel add/delete, merge, relocate, backfill-card re-run, reconcile card.

### 1.2 Read-path authority

- `/isrc/` keeps the Phase-1 union **and it is re-documented as authority, not supplement**: the
  store arm is the canonical lookup; the column arm survives ONLY as the un-migrated-install
  degradation (post-reconcile, column ⊆ store, so the column arm adds nothing on a migrated DB).
  Doc-block edit only in `identifier_resolve.php` (§5.6) — the SQL is already right.
- `song_by_identifier type=isrc` and `lyricsIngest_resolveSong()` step 2 — the two other
  "which song has this ISRC?" readers — gain the same existence-gated store arm (§5.5), via ONE
  shared pure predicate builder (§5.1), so a store-only second-recording ISRC resolves everywhere
  or nowhere, never "on one endpoint but not another".

---

## 2. Design — the primary-ID rule (D-2, decided) and the identity-map freeze (D-3, default taken)

### 2.1 The deterministic projection (this exact rule, one place in code)

When the store holds N `IdType='isrc'` rows for a song, `tblSongs.Isrc` is populated by:

```
SELECT e.IdValue FROM tblSongExternalIds e
 WHERE e.SongId = <song> AND e.IdType = 'isrc' AND CHAR_LENGTH(e.IdValue) <= 15
 ORDER BY (e.SourceRef <=> 'tblSongs.Isrc') DESC, e.Id ASC
 LIMIT 1
```

- **Rank 1 — the mirror-owned "marker" row** (`SourceRef = 'tblSongs.Isrc'`, the 0.4 ownership
  class: backfill Source-1 rows + live-mirror rows). The `<=>` NULL-safe equality is mandatory:
  a manual row's `SourceRef` is NULL and `(NULL = 'x')` is NULL, which would sort unpredictably;
  `<=>` yields a clean 0/1.
- **Rank 2 — lowest `Id`** among the rest (manual + `tblSongIdentityMap.IsrcCode`-backfilled rows).
  Lowest-Id (not highest) so a curator adding a SECOND manual recording later never hijacks an
  established denorm — the projection is stable under additions.
- **`CHAR_LENGTH(e.IdValue) <= 15`** — belt-and-braces against a future >15-char IdValue reaching a
  `VARCHAR(15)` column under STRICT (today all isrc-typed values are ≤15 per 0.13; the guard makes
  that a non-assumption).
- **No isrc rows ⇒ NULL.** The column is a true projection: `Isrc IS NULL` ⟺ the store has no
  (≤15-char) isrc row. Consequence, decided deliberately: **clearing the editor's ISRC field while
  a manual second-recording row exists PROMOTES that row into the column** (the store still knows
  an ISRC ⇒ the denorm says so; a curator wanting NO ISRC deletes the manual row too, in the panel
  on the same Metadata tab). §5.2's response/`onIsrcDenorm` plumbing makes this visible immediately
  instead of on next load.
- **Multi-marker states are an anomaly**, not an input to cleverer tie-breaking. Live code cannot
  create one (the mirror's DELETE-then-INSERT is atomic in its caller's transaction); only
  pre-P5d drift + a backfill re-run can (old marker Id=5 value X, new Id=90 value Y). The §5.7
  reconcile heals them by re-minting from the column (the column IS the fresher fact for any
  pre-cutover state), and both the §5.7 probe and the §7.2 guard treat >1 marker rows per song as
  pending/red.

The ORDER BY lives ONCE as `SONG_EXTERNAL_ID_ISRC_PRIMARY_ORDER` and the whole SELECT once as the
pure builder `songExternalIdIsrcProjectionSql()` (§5.1); the reconcile card and the registry probe
CALL the builder — never a second copy (rule #22; the §7.2 guard bans a re-forked
`ORDER BY (e.SourceRef` literal anywhere else).

### 2.2 Marker semantics after the flip — no data migration, meaning inverted by documentation

`SourceRef='tblSongs.Isrc'` keeps its bytes (0.4's byte-equality guard keeps passing; zero row
rewrites) but its DOCUMENTED meaning inverts: pre-cutover it said "this row was copied FROM the
column"; post-cutover it says "**this is the row the column is projected FROM** — the primary
marker". Update the doc-blocks in `song_external_ids.php`, the backfill script header and the
registry card body copy accordingly. This is why the ownership literal is NOT renamed to
`'primary'`: a rename would need a data migration + guard churn for zero behavioural gain.

### 2.3 `tblSongIdentityMap`'s four columns — FROZEN LEGACY, not actively synced (D-3, defensible default — trivially changeable, blocks nothing)

The escalation phrasing groups the 4 identity-map columns with `tblSongs.Isrc` as "kept-in-sync
denorms". **Do NOT build a live store→identity-map sync.** Decided default: they are **frozen
legacy** — absorbed one-way into the store by #1747, never written again, documented as historical.

Why this is the right default (0.7 is the evidence):

1. **Nothing reads them and nothing has ever written them** — a sync would maintain a projection
   with zero consumers.
2. **The shapes are incompatible for projection.** Each provider column carries a TABLE-WIDE
   `UNIQUE` key (`uk_MBRecording`/`uk_Spotify`/`uk_Genius`/`uk_Isrc`, `schema.sql:3402-3406`); the
   store legitimately allows the same IdValue on two songs (`uq_Song_Type_Value` is per-song). Any
   faithful sync must resolve cross-song collisions — real design work for a dormant table.
3. **The table itself is gated** on the #1010 iLyricsDB DB-merge decision (`schema.sql:3367-3375`);
   investing in it pre-decision is the "guessed bridge" rule #20 forbids.
4. **Nothing is lost.** The idempotent #1747 backfill card remains re-runnable; the store is a
   superset of the map forever.

Deliverables for D-3: a doc-comment on the `schema.sql` block + `media_identifiers.php` mention
(“frozen legacy — superseded by tblSongExternalIds (#1749 full unification); do not write; removal
gated on #1010”) and the same note in the #1749 closing comment. If the owner later wants active
sync, it is an isolated add-on (a store→map projector) — nothing in this spec blocks it.

**Out of scope, stated deliberately:** `tblSongs.Upc` (product-grain, never backfilled, not in the
owner's option-3 list). The `'upc'` IdType exists in the vocabulary
(`media_identifiers.php:248`), so extending unification to UPC later is mechanical; do not do it
in this pass.

---

## 3. Adversarial sweep — "what would desync the two, or break a consumer?" (every funnel, verdict)

| Funnel / event | Store mutation? | Column mutation? | Post-change invariant holds because |
|---|---|---|---|
| `metadata_field_update` field=isrc (`api2.php:1490-1501`, `:1628-1630`) | mirror | direct + sync | column UPDATE → mirror re-mints marker → embedded sync projects (no-op on set; PROMOTES on clear-with-manual). One txn (`:1610-1644`) |
| `metadata_field_update` other fields | no | no (Isrc untouched) | n/a |
| Whole-song save (`editorSaveSongCore`) | no | **no** — zero `Isrc` refs in `save_song_core.php` (verified) | n/a — state in the guard's doc why it's absent from the sweep |
| Revision restore (`ed2_applySongSnapshot`, `api2.php:1104-1135`) | mirror | raw scalar write + sync | embedded sync REPAIRS the 0.10 raw-vs-canonical mismatch: canonical marker value is projected back over the raw column write |
| Ingest create (`lyrics_ingest.php:621-648`) | mirror | INSERT + sync | canonical at `:570`; sync no-op |
| Ingest COALESCE fill (`:713-752`) | mirror | COALESCE + sync | mirror already re-reads the STORED value (`:744-752`); sync no-op |
| Ingest identity-resolve (`:458-469`) | read | read | §5.5 store arm — a store-only ISRC now resolves instead of falling through to fuzzy title matching (a wrong-song-attach risk today) |
| Panel `song_external_id_add` isrc (`api2.php:2944-3016`) | INSERT | **stale today (0.9)** → sync | §5.3: sync in the existing txn; response carries `isrcDenorm` |
| Panel `song_external_id_delete` (`:3019-3051`) | DELETE | **stale today (0.9)** → sync | §5.3: pre-read IdType, sync when isrc, new small txn; marker deletion DEMOTES to next rank or NULL |
| Panel add/delete non-isrc types | INSERT/DELETE | no | no isrc projection involved; identity map frozen (D-3) |
| Merge (`duplicate-songs.php:181-247`) | **CASCADE-DELETES store rows today (0.8)** | survivor untouched | §5.4: gated repoint (duplicate's marker DEMOTED to non-marker so the survivor never goes multi-marker) + sync survivor, inside the merge txn |
| Soft delete / restore (#1694) | no | no | rows stay attached to the hidden SongId; nothing to do |
| Relocate / songbook move | FK `ON UPDATE CASCADE` re-keys | row moves with song | 0.14 — consistent by construction |
| Backfill card re-run | INSERT IGNORE marker = current column | no | equal value collides on `uq_Song_Type_Value` post-cutover (marker ≡ column); the pre-cutover multi-marker window is §5.7's job |
| Reconcile card (§5.7) | mirror per anomalous song | sync per divergent song | idempotent; second run selects nothing |
| DB-direct / out-of-band edits | any | any | not preventable; §5.7 card is re-runnable and its probe flips to pending on divergence — the operator-visible signal |
| Rollback mid-funnel | both roll back | both roll back | mirror + sync are deliberately UNSWALLOWED (`song_external_ids.php:170-173` posture kept) and transaction-scoped at every call site |
| Consumers (0.12) | — | read the denorm | single canonical value preserved; no query changes needed |

---

## 4. Cutover — code-only PLUS one data-only reconcile card; NO dormant flag (D-4, decided); fully reversible

### 4.1 No schema change, no flag

- **No DDL.** Store, column, keys, FK all exist. `schema.sql` untouched
  (`test-schema-coverage.php` stays green by construction — the new migration is data-only, same
  posture as the backfill's "NO schema.sql CHANGE" note, `migrate-backfill-song-external-ids.php:105-109`).
- **No `tblAppSettings` staging flag.** The `content_gating_enabled` pattern earns its complexity
  when the dormant path could damage output while switched on accidentally. Here the behavioural
  deltas are exactly three, all of them the INTENDED semantics and all additive-or-healing:
  (i) panel add/delete now maintain the column (fixes 0.9), (ii) clear-with-manual promotes
  (§2.1, decided), (iii) merge preserves store rows (fixes 0.8). Each is exercised by guards + the
  live probe, and the whole change reverts as ONE commit (§4.3). A flag would double the test
  matrix (flag on/off × migrated/un-migrated) for a switch nobody would ever set back. The
  un-migrated-install degradation (§1.1a) is the real safety valve, and it is structural, not
  configured.

### 4.2 Deploy/run order (3 docroots share ONE MySQL; migrations are web-run)

1. Land the code (one PR to `alpha`, one commit for the feature + one for the migration/registry +
   one for guards — atomic, individually revertable).
2. Deploy to all three docroots. **Between deploy and card-run the DB is in the pre-cutover data
   state and every new code path is safe in it**: the sync only ever writes the §2.1 projection,
   which, wherever the mirror has been keeping lockstep since P5d, equals the column already; where
   it doesn't (0.9/0.10 drift), the write is the heal, not a regression. A NOT-yet-deployed docroot
   running old code concurrently cannot fight it either — old code's worst act is re-creating a
   0.9-class stale column, which the next new-code touch or card run re-heals.
3. Run the **`reconcile-isrc-denorm`** card once at `/manage/setup-database` (§5.7). Its probe
   goes green and STAYS green (post-cutover funnels preserve the invariant; the probe doubling as
   a drift detector is the same posture as the backfill probe, `migration-registry.php:3493-3504`).
4. Run the §8 live probe script + alpha smoke.

### 4.3 Reversibility — exactly one revert

- `git revert <feature-sha>` restores column-authoritative behaviour everywhere. **No data written
  under the new model is invalid under the old one**: the column always holds a canonical single
  ISRC or NULL; store rows are exactly the classes the old model already knew (marker/manual).
  Promoted columns (§2.1 clear case) simply persist as ordinary column values.
- The reconcile card needs no reverse migration — it only ever wrote values both models consider
  legitimate. Its registry entry rides along in the revert (or is left: a data-only card that is
  green and harmless).
- If only the MERGE change misbehaves, it is its own commit and reverts alone.

---

## 5. Implementation — exact edits

Every new/changed block gets the project's two-register annotation (ELI5 + DETAILED/WHY with
MDN/PHP-manual/`#issue` links), as sketched below. `#1749` in every doc-block.

### 5.1 `appWeb/public_html/includes/song_external_ids.php` — the ONE write core grows the projection

Add (below the existing const at `:128`):

```php
/**
 * ELI5: when a song has several ISRC rows in the store, this is the ONE rule
 * for which of them shows up in the single tblSongs.Isrc box.
 * DETAILED (#1749 full unification, D-2): marker row first via NULL-safe <=>
 * (a manual row's SourceRef is NULL; (NULL='x') is NULL and would sort
 * unpredictably — https://dev.mysql.com/doc/refman/8.0/en/comparison-operators.html#operator_equal-to),
 * then lowest Id so a later-added second recording never hijacks an
 * established denorm. Declared ONCE; the reconcile migration and its registry
 * probe consume it via songExternalIdIsrcProjectionSql() below — the guard
 * (tests/php/test-song-external-id-mirror.php §7.2) bans any re-forked
 * "ORDER BY (e.SourceRef" literal elsewhere in the tree (rule #22/#35).
 */
const SONG_EXTERNAL_ID_ISRC_PRIMARY_ORDER =
    "(e.SourceRef <=> 'tblSongs.Isrc') DESC, e.Id ASC";

/**
 * PURE SQL builder for the §2.1 primary-ISRC projection — the testable seam
 * (rule #34; the _ihymns_resolve_songs_sql() precedent).
 *
 * @param string $songIdExpr A PHP-SOURCE CONSTANT expression for the song id
 *                           position: '?' (bound single-song form) or
 *                           's.SongId' (correlated form for the probe /
 *                           reconcile divergence scans). NEVER user input
 *                           (rule #5 carve-out — both call-site spellings are
 *                           hardcoded literals).
 */
function songExternalIdIsrcProjectionSql(string $songIdExpr = '?'): string
{
    return 'SELECT e.IdValue FROM tblSongExternalIds e'
         . " WHERE e.SongId = {$songIdExpr} AND e.IdType = 'isrc'"
         . ' AND CHAR_LENGTH(e.IdValue) <= 15'          /* VARCHAR(15) target under STRICT — schema.sql:284 */
         . ' ORDER BY ' . SONG_EXTERNAL_ID_ISRC_PRIMARY_ORDER
         . ' LIMIT 1';
}

/**
 * PURE SQL builder for the store union arm shared by every "which song has
 * this ISRC?" reader (#1749 §1.2): /isrc/ (via _ihymns_resolve_songs_sql),
 * api.php song_by_identifier, lyricsIngest_resolveSong. ONE predicate, three
 * consumers — never re-inline it (rule #22). Emits 2 placeholders
 * (IdType, IdValue); the caller binds.
 *
 * @param string $songIdExpr PHP-source constant, e.g. 's.SongId' — the column
 *                           expression tested for membership.
 */
function songExternalIdUnionArmSql(string $songIdExpr): string
{
    return "{$songIdExpr} IN (SELECT e.SongId FROM tblSongExternalIds e"
         . ' WHERE e.IdType = ? AND e.IdValue = ?)';
}

/**
 * ELI5: look at ALL the ISRC rows the store has for this song, pick the
 * primary one by the ONE rule, and make the tblSongs.Isrc box say exactly
 * that (or go empty when the store has none).
 * DETAILED (#1749 full unification, D-1): THE one store→column projection —
 * the statement that makes the store authoritative. Runs as the LAST write
 * of every transaction that mutates isrc store rows (embedded in
 * songExternalIdMirrorIsrc() below; called directly by the non-mirror store
 * mutations: song_external_id_add/_delete, the duplicate-songs merge, the
 * reconcile card). Table-absent ⇒ untouched column ⇒ the caller's own direct
 * write stands — the un-migrated install keeps pre-#1749 behaviour verbatim.
 * The `NOT (Isrc <=> ?)` predicate makes the UPDATE a true no-op (0 affected
 * rows, no UpdatedAt churn) when already in lockstep. UNSWALLOWED on purpose,
 * like the mirror: a half-projected pair must roll back with the funnel.
 *
 * @return string|null The projected value now in the column (null = cleared),
 *                     or null on an un-migrated install (callers that echo a
 *                     final value fall back to their own written value then).
 */
function songExternalIdSyncIsrcDenorm(\mysqli $db, string $songId): ?string
{
    if (!songExternalIdsTableExists($db)) {
        return null;                         /* un-migrated — column stays whatever the funnel wrote */
    }
    $sel = $db->prepare(songExternalIdIsrcProjectionSql('?'));
    $sel->bind_param('s', $songId);
    $sel->execute();
    $row = $sel->get_result()->fetch_row();
    $sel->close();
    $projected = ($row === null || (string)$row[0] === '') ? null : (string)$row[0];

    $u = $db->prepare('UPDATE tblSongs SET Isrc = ? WHERE SongId = ? AND NOT (Isrc <=> ?)');
    $u->bind_param('sss', $projected, $songId, $projected);
    $u->execute();
    $u->close();
    return $projected;
}
```

Change `songExternalIdMirrorIsrc()` (`:190-234`):

- Return type `void` → `?string`; final statement becomes
  `return songExternalIdSyncIsrcDenorm($db, $songId);` — including on the early
  cleared-value return at `:212` (the DELETE-only path must also re-project: that is the §2.1
  promotion). The table-absent early-return at `:193` returns
  `$canonicalIsrc === '' ? null : $canonicalIsrc` so un-migrated callers still get a sensible echo.
- Doc-block: fold in §2.2's inverted marker semantics + the D-1 invariant sentence; existing
  ownership/DELETE-then-INSERT prose stays (all still true).

### 5.2 `manage/editor/api2.php` — editor funnels echo the projection

- `metadata_field_update` isrc branch: `:1628-1630` becomes
  `if ($column === 'Isrc') { $isrcFinal = songExternalIdMirrorIsrc($db, $songId, $value); }` and the
  success response (`:1646`) for field=isrc becomes
  `ed2_respond(['ok' => true, 'field' => $field, 'value' => $isrcFinal ?? $value]);`
  (additive key; `tests/php/test-editor-api2-contract.php` unaffected — additive response keys are
  outside its assertions; still run it).
- `ed2_applySongSnapshot()` `:1131-1135`: no structural change (the embedded sync now heals 0.10);
  update the `#1749 P5d` comment to name the healing.
- `song_external_id_add` (`:2973-3007`): inside the existing transaction, after the re-select and
  before commit:
  `if ($idType === 'isrc') { $isrcDenorm = songExternalIdSyncIsrcDenorm($db, $songId); }`
  Response gains `'isrcDenorm' => $isrcDenorm` **only when** `$idType === 'isrc'` (key-presence is
  the client's branch signal — rule #35, a flag/shape, not prose).
- `song_external_id_delete` (`:3019-3051`): pre-read the row's IdType
  (`SELECT IdType FROM tblSongExternalIds WHERE Id = ? AND SongId = ?`) BEFORE the DELETE; wrap
  DELETE (+ conditional sync when the pre-read said `'isrc'`) in a `begin_transaction()/commit`
  (it stops being a single-statement action — update the `:3029-3037` comment, whose "no
  transaction needed" rationale expires). Response gains `'isrcDenorm'` under the same
  key-presence rule. Delete the now-false `:3044-3047` "harmless — the next ISRC save re-mints it"
  comment: the sync makes it ACTUALLY harmless, immediately.

### 5.3 Client — `manage/editor/v2/metadata-tab.js` + `external-ids-panel.js`

- `metadata-tab.js` mount site (`:520-525`): pass a callback —
  `mountExternalIdsPanel(container, { songId, toast, onIsrcDenorm })` where `onIsrcDenorm(v)`
  writes `v == null ? '' : v` into the `#meta-isrc` input **iff it exists and is not focused**
  (never fight a curator mid-keystroke; the same next-load consistency covers the focused case).
- `metadata-tab.js` `save()` (`:129-149`): after a successful isrc save, if
  `res && typeof res.value !== 'undefined'` and the field input is not focused, set it to
  `res.value ?? ''` — this is how a clear-with-manual promotion (§2.1) becomes visible instantly.
- `external-ids-panel.js` `onAdd` (`:230-249`) / `onRemove` (`:211-220`): when the response has an
  `isrcDenorm` key (`'isrcDenorm' in res`), invoke `opts.onIsrcDenorm`. Key-presence branch, never
  prose (rule #35). No hardcoded provider strings (the existing
  `tests/test-v2-external-ids-ui.js` no-literal assertion must stay green).

### 5.4 `manage/duplicate-songs.php` — merge preserves + demotes, then projects

At the top (with the other includes): `require_once … includes/song_external_ids.php;`.
Inside the merge transaction, immediately after the `MERGE_FK_TABLES_SINGLE` loop (`:195`) —
**gated** (the const list is consumed unconditionally; an ungated entry would STRICT-throw on an
un-migrated install — the same reason `songRedirectRepoint` at `:229` is gated):

```php
/* #1749 — the store is the recording-ID authority; a merge must MOVE the
   duplicate's rows, not let fk_SongExternalIds_Song ON DELETE CASCADE eat
   them (issue #<discovery-issue>). The duplicate's own MARKER row (its
   column projection artefact) is DEMOTED to a plain second-recording row
   (SourceRef → NULL, Source kept for provenance) so the survivor can never
   end up with two marker rows — the survivor's own marker, if any, stays
   the §2.1 primary. UPDATE IGNORE + DELETE-leftover is the same collision
   idiom as the loop above (uq_Song_Type_Value). */
if (songExternalIdsTableExists($db)) {
    $srcRef = SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF;
    $u = $db->prepare(
        "UPDATE IGNORE tblSongExternalIds
            SET SongId = ?, SourceRef = IF(IdType = 'isrc' AND SourceRef <=> ?, NULL, SourceRef)
          WHERE SongId = ?"
    );
    $u->bind_param('sss', $survivor, $srcRef, $duplicate);
    $u->execute(); $u->close();
    $d = $db->prepare('DELETE FROM tblSongExternalIds WHERE SongId = ?');
    $d->bind_param('s', $duplicate);
    $d->execute(); $d->close();
    songExternalIdSyncIsrcDenorm($db, $survivor);   /* store last word — may promote into an empty survivor column */
}
```

Do NOT add the table to `MERGE_FK_TABLES_SINGLE` (ungateable there). Note in the handler comment
that the duplicate's `tblSongs.Isrc` needs nothing: the row is hard-deleted at `:243-247`.

### 5.5 The two remaining column-only ISRC readers gain the store arm

- **`api.php` `song_by_identifier` (`:1290-1322`)** — for `$idType === 'isrc'` only, and only when
  `songExternalIdsTableExists($db)` (require `includes/song_external_ids.php`; api.php already
  requires heavier includes), widen the WHERE to
  `(s.Isrc = ? OR ` + `songExternalIdUnionArmSql('s.SongId')` + `)` and bind `'sss'`
  (`$idValue, 'isrc', $idValue`). Response shape unchanged (already a list — 0.5). Keep the raw
  bind (no canonicalisation) for BOTH arms — parity with today and with the union's documented
  accepted limitation (`identifier_resolve.php:176-180`).
- **`lyrics_ingest.php` `lyricsIngest_resolveSong()` step 2 (`:458-469`)** — three fixes in one:
  canonicalise the payload value through the ONE fold
  (`$isrc = ihymns_canonical_isrc(trim((string)($payload['isrc'] ?? '')));` — the file already
  uses it at `:570`/`:713`; rule #22), add the gated `songExternalIdUnionArmSql('s.SongId')` arm,
  and pin determinism with `ORDER BY s.SongId ASC LIMIT 1` (today's bare `LIMIT 1` is
  storage-order roulette when two songs share an ISRC — possible via manual store rows). The
  ingest file already requires `song_external_ids.php` at both write sites (`:647`, `:751`); hoist
  one `require_once` above the resolver. Update `api-docs.yaml:1258-1298` (the ingest endpoint's
  isrc-resolution description) to mention the store arm — and note there that store-only ISRCs now
  resolve.

### 5.6 `includes/identifier_resolve.php` — documentation-only re-anchoring

- Refactor `_ihymns_resolve_songs_sql()`'s union arm (`:204-206`) to call
  `songExternalIdUnionArmSql('s.SongId')` (add the `require_once … song_external_ids.php` beside
  `:77-78`). **The returned SQL text must stay byte-identical** — `test-identifier-resolve-union.php`
  asserts on the builder's output; if whitespace differs, prefer adjusting the helper's spacing to
  match the existing text over touching the test (the test is the contract witness here).
  If byte-identity is impractical, updating the test's EXPECTED text is acceptable ONLY together
  with re-running its mutation self-checks.
- Doc-block edits (`:60-63` and the `:425-428` case comment): the store arm is now the AUTHORITY;
  the `tblSongs.Isrc` arm is the un-migrated degradation (§1.2). The `:60` sentence about
  `tblSongIdentityMap.uk_Isrc` stays true — extend it with the D-3 freeze pointer.

### 5.7 The reconcile card — `appWeb/.sql/migrate-reconcile-isrc-denorm.php` + registry entry

**Script** (data-only; NO schema.sql edit — same declared posture as the backfill, 0.1; header
doc-block explains it so `test-schema-coverage.php`'s silence is documented, not lucky):

1. Boilerplate = the backfill's (`_mig…_output`, cred load, `mysqli_report(STRICT)`,
   `SHOW TABLES LIKE` guard refusing politely when `tblSongExternalIds` is absent —
   `migrate-backfill-song-external-ids.php:126-164` verbatim shape).
2. `require_once dirname(__DIR__) . '/public_html/includes/song_external_ids.php';` and
   `…/identifier_normalize.php` (precedent 0.16).
3. **Step A — column→store re-mint (pre-cutover states heal from the column, which was the
   authority when they were written).** Select anomalous songs:

   ```sql
   SELECT s.SongId, s.Isrc FROM tblSongs s
    WHERE (NULLIF(s.Isrc,'') IS NOT NULL AND NOT EXISTS (
             SELECT 1 FROM tblSongExternalIds e
              WHERE e.SongId = s.SongId AND e.IdType = 'isrc'
                AND e.SourceRef = 'tblSongs.Isrc' AND e.IdValue = s.Isrc))
       OR (NULLIF(s.Isrc,'') IS NULL AND EXISTS (
             SELECT 1 FROM tblSongExternalIds e
              WHERE e.SongId = s.SongId AND e.IdType = 'isrc' AND e.SourceRef = 'tblSongs.Isrc'))
       OR (SELECT COUNT(*) FROM tblSongExternalIds e
            WHERE e.SongId = s.SongId AND e.IdType = 'isrc'
              AND e.SourceRef = 'tblSongs.Isrc') > 1
   ```

   For each: `songExternalIdMirrorIsrc($db, $songId, ihymns_canonical_isrc((string)$isrc) ?: null)`
   — the ONE write core (its DELETE-by-ownership clears multi-marker states; its embedded sync
   finishes the projection). The `'tblSongs.Isrc'` literals here are
   `SONG_EXTERNAL_ID_ISRC_MIRROR_SOURCE_REF` interpolated from the required helper — never
   re-typed.
4. **Step B — store→column promotion** for everything Step A didn't touch: select
   `SongId` where `NOT (NULLIF(s.Isrc,'') <=> (` + `songExternalIdIsrcProjectionSql('s.SongId')`
   + `))`; for each call `songExternalIdSyncIsrcDenorm($db, $songId)`. (Post-A this is exactly the
   store-only-manual cohort — the columns 0.9 left empty.)
5. Print per-step counts; idempotent (both selects return zero rows on a second run).

**Registry** (`manage/includes/migration-registry.php`, ONE entry appended after
`'backfill-song-external-ids'` — the registry derives `$scriptMap`/`$migrationOrder`/
`$migrationCards`/`$migrationProbes` from it, rule #19):

```php
'reconcile-isrc-denorm' => [
    'script' => 'migrate-reconcile-isrc-denorm.php',
    'card' => [
        'title'  => 'Reconcile ISRC denorm ↔ external-ID store (#1749)',
        'body'   => 'One-shot cutover pass for the #1749 full unification:'
                  . ' <code>tblSongExternalIds</code> becomes the authority for'
                  . ' recording ISRCs and <code>tblSongs.Isrc</code> its'
                  . ' kept-in-sync projection. Re-mints the mirror row from any'
                  . ' column the pre-cutover era left drifted, then projects'
                  . ' store-only ISRCs into empty columns. Data-only (no DDL);'
                  . ' idempotent — safe to re-run; the probe doubles as a live'
                  . ' drift detector afterwards. Requires the two D5 cards above.',
        'button' => 'Reconcile ISRC Denorm',
    ],
    /* Real, data-derived probe (rule #19): pending when the store table is
       missing (chain not applied yet — the backfill probe's own posture), when
       any song carries >1 marker rows (§2.1 anomaly), or when any song's
       column disagrees with the ONE projection. The projection SQL is BUILT by
       songExternalIdIsrcProjectionSql() — never a second copy (rule #22). */
    'probe' => static function (\mysqli $db): bool {
        if (!_migProbe_tableExists($db, 'tblSongExternalIds')) return true;
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_external_ids.php';
        try {
            $r = $db->query(
                "SELECT 1 FROM tblSongExternalIds e
                  WHERE e.IdType = 'isrc' AND e.SourceRef = 'tblSongs.Isrc'
                  GROUP BY e.SongId HAVING COUNT(*) > 1 LIMIT 1"
            );
            if ($r && $r->fetch_row() !== null) return true;
            $r2 = $db->query(
                'SELECT 1 FROM tblSongs s WHERE NOT (NULLIF(s.Isrc, \'\') <=> ('
                . songExternalIdIsrcProjectionSql('s.SongId')
                . ')) LIMIT 1'
            );
            if ($r2 && $r2->fetch_row() !== null) return true;
        } catch (\Throwable $e) {
            return true;
        }
        return false;
    },
],
```

(`dirname(__DIR__, 2)` from `manage/includes/` = `public_html/`. Verify against a neighbouring
probe's path idiom when implementing.)

---

## 6. Owner decisions — NONE blocking; the expected one dissolved

**The decision** (pre-answered): whether the native/API contract can keep single-valued ID keys
under store authority. **Why it needed checking, not deciding:** it was an empirical question
about the payloads. **Finding (0.5):** no song payload has EVER carried `isrc` (or
MBID/Spotify/Genius) — `SongData.php` contains zero `Isrc` references; the Apple and Android trees
contain zero `isrc` references; the only surfaces are a song-LIST lookup (`song_by_identifier`,
shape untouched, matches widened additively) and the `/isrc/` page. **Options:** none needed — the
contract is preserved by keeping the denorm, with nothing to flag. **Recommendation/default
taken:** keep payloads as they are; if the owner ever wants song payloads to expose external IDs,
that is a new additive feature (an `externalIds` array on `song_detail`, trivially built on this
store) — file it as `for consideration`, do not build it here. **Blocks:** nothing.

**D-3 (§2.3, default taken, non-blocking, trivially changeable):** `tblSongIdentityMap`'s four
provider columns are frozen legacy, not live-synced denorms. Reasoning and the cost of the
alternative are in §2.3; note this explicitly when closing #1749 so the owner can overrule with a
one-line reply if "kept-in-sync" was meant literally for that table too.

---

## 7. Guards (rule #34 — tree-derived, comment-stripped, mutation-proven)

### 7.1 Extend `tests/php/test-identifier-resolve-union.php`

- Assert (comment-stripped) that `api.php`'s `song_by_identifier` isrc path and
  `lyrics_ingest.php`'s `lyricsIngest_resolveSong()` both contain a call to
  `songExternalIdUnionArmSql(` AND an existence gate (`songExternalIdsTableExists`) in the same
  region — window ≥300 chars (the rule-#34 lesson from `test-editor-api2-contract.php`).
- Pure-call the new builders: `songExternalIdUnionArmSql('s.SongId')` contains both placeholders
  and the table name; `songExternalIdIsrcProjectionSql('?')`/`('s.SongId')` contain the
  `CHAR_LENGTH` guard, the `<=>` marker term, `e.Id ASC`, `LIMIT 1`.
- Mutation self-tests: flip the gate polarity / drop a placeholder / drop the `<=>` in in-memory
  fixture strings and assert the checking functions go RED. **Then prove the real assertions can
  fail**: temporarily break each real source condition locally, watch red, restore (record this in
  the commit message — a guard whose first green was never challenged is the repo's named disease).

### 7.2 Extend `tests/php/test-song-external-id-mirror.php` (same family, same file — one guard for the one write core)

- **Projection single-sourcing:** parse `SONG_EXTERNAL_ID_ISRC_PRIMARY_ORDER` out of
  `song_external_ids.php`; comment-strip `appWeb/public_html/**/*.php` +
  `appWeb/.sql/migrate-reconcile-isrc-denorm.php` + `manage/includes/migration-registry.php` and
  FAIL on any `ORDER BY (e.SourceRef` occurrence outside `song_external_ids.php` (a re-fork), and
  FAIL if the reconcile script or the registry probe lacks a
  `songExternalIdIsrcProjectionSql(` call.
- **Mirror ends with the sync:** the sliced `songExternalIdMirrorIsrc()` must call
  `songExternalIdSyncIsrcDenorm(` on BOTH its return paths (cleared + inserted) — assert two
  occurrences in the slice, or one per return region.
- **Derived store-mutation sweep** (the new sibling of the existing `tblSongs.Isrc` write sweep at
  `:470-493`): scan `appWeb/public_html/**/*.php` (skip `.sql`, one-shot precedent `:210`) for a
  comment-stripped SQL `INSERT INTO tblSongExternalIds` / `UPDATE … tblSongExternalIds` /
  `DELETE FROM tblSongExternalIds`; every hit file must also reference
  `songExternalIdSyncIsrcDenorm` or `songExternalIdMirrorIsrc` (which embeds it), or appear in an
  issue-numbered exempt list. Expected initial exempt: `[]` — `song_external_ids.php` itself is
  the funnel (exclude the funnel file from the sweep the way the existing sweep excludes itself),
  and api2.php/duplicate-songs.php qualify by calling the sync.
- **Merge specifics** (dynamic SQL the sweep cannot see is asserted structurally): the merge
  handler's slice contains the gated `tblSongExternalIds` repoint UPDATE, the
  `SourceRef = IF(IdType = 'isrc'…NULL…)` demotion, and a `songExternalIdSyncIsrcDenorm(` call;
  mutation-prove each (delete the demotion in an in-memory copy → red).
- **api2 endpoint wiring:** `song_external_id_add` and `song_external_id_delete` slices each call
  the sync; `_delete` pre-reads `IdType` before its DELETE.
- Keep every existing assertion green — the SourceRef byte-equality, the ownership predicate, the
  `'ihymns-mirror'` default, the ingest ≥2 call count all remain true post-change.

### 7.3 Node — `tests/test-v2-external-ids-ui.js` + metadata-tab coverage

- Panel: asserts the `'isrcDenorm' in res` key-presence branch exists and that `onIsrcDenorm` is
  invoked from BOTH `onAdd` and `onRemove` paths (source-scan + the suite's existing style);
  still zero hardcoded provider literals.
- Metadata tab: asserts the mount site passes `onIsrcDenorm` and that `save()` consumes
  `res.value` for isrc with a focus guard. Prove each can fail before first green.

### 7.4 Registry sanity

`test-migration-registry.php` (already in CI) validates the new entry has a real probe
automatically — no edit needed, but RUN it and watch it pass, and temporarily stub the probe to
`=> true` locally to confirm it reds (mutation-prove the derived four-way expansion too).

---

## 8. Verification plan (implementer runs; DB-dependent step packaged for the PARENT session)

1. **Static:** `find appWeb -name '*.php' -exec php -l {} \;` (zero errors);
   `find appWeb -name '*.js' -exec node --check {} \;`.
2. **Suites:** `php tools/run-php-tests.php` (the full `tests/php/*.php` glob — 104 files pre-change,
   +0 new files under §7's extend-don't-add approach) and `node tools/run-node-tests.js` (49).
3. **Mutation-proof session** per §7 (break → red → restore), noted in the commit body.
4. **Live behavioural probe — dev MySQL is intermittently unreachable from subagents; this is a
   SCRIPT for the parent session to run when the DB is up, not something to attempt inline.**
   Save the block below to the session scratchpad (e.g. `probe-1749-unification.php`) and run
   `php probe-1749-unification.php` from the repo root. It is TRANSACTION-WRAPPED WITH ROLLBACK —
   zero residue; the helpers never begin/commit their own transactions (verified §5.1 design), so
   one outer transaction owns everything.

```php
<?php
declare(strict_types=1);
/* #1749 live probe — run by the PARENT session against dev MySQL. ROLLS BACK. */
require_once __DIR__ . '/appWeb/.auth/db_credentials.php';
require_once __DIR__ . '/appWeb/public_html/includes/song_external_ids.php';
require_once __DIR__ . '/appWeb/public_html/includes/identifier_normalize.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
$db->set_charset('utf8mb4');
$fail = 0;
$ok = function (bool $c, string $m) use (&$fail) { echo ($c ? 'PASS  ' : 'FAIL  ') . $m . "\n"; if (!$c) $fail++; };
$col = function (string $sid) use ($db): ?string {
    $s = $db->prepare('SELECT Isrc FROM tblSongs WHERE SongId = ?'); $s->bind_param('s', $sid);
    $s->execute(); $r = $s->get_result()->fetch_row(); $s->close();
    return ($r === null || $r[0] === null || $r[0] === '') ? null : (string)$r[0];
};
$db->begin_transaction();
try {
    /* Scratch song in a real songbook (FK needs a valid SongbookAbbr). */
    $abbr = (string)$db->query('SELECT Abbreviation FROM tblSongbooks LIMIT 1')->fetch_row()[0];
    $sid  = 'ZZ-9749'; /* never rendered — rolled back */
    $ins = $db->prepare("INSERT INTO tblSongs (SongId, Title, SongbookAbbr, Language) VALUES (?, '#1749 probe', ?, 'en')");
    $ins->bind_param('ss', $sid, $abbr); $ins->execute(); $ins->close();

    /* 1. Editor-funnel shape: column write + mirror ⇒ marker row + lockstep. */
    $u = $db->prepare("UPDATE tblSongs SET Isrc = 'USZZZ2612345' WHERE SongId = ?");
    $u->bind_param('s', $sid); $u->execute(); $u->close();
    $ok(songExternalIdMirrorIsrc($db, $sid, 'USZZZ2612345') === 'USZZZ2612345', 'mirror echoes projection');
    $ok($col($sid) === 'USZZZ2612345', 'column = projected after set');

    /* 2. Manual second recording does NOT hijack the marker. */
    $m = $db->prepare("INSERT INTO tblSongExternalIds (SongId, IdScope, IdType, IdValue, Source, SourceRef)
                       VALUES (?, 'recording', 'isrc', 'USZZZ2699999', 'manual', NULL)");
    $m->bind_param('s', $sid); $m->execute(); $m->close();
    $ok(songExternalIdSyncIsrcDenorm($db, $sid) === 'USZZZ2612345', 'marker beats manual');

    /* 3. Clear-with-manual PROMOTES (§2.1). */
    $u = $db->prepare('UPDATE tblSongs SET Isrc = NULL WHERE SongId = ?');
    $u->bind_param('s', $sid); $u->execute(); $u->close();
    $ok(songExternalIdMirrorIsrc($db, $sid, null) === 'USZZZ2699999', 'clear promotes manual row');
    $ok($col($sid) === 'USZZZ2699999', 'column carries promoted value');

    /* 4. Deleting the last isrc row demotes to NULL. */
    $d = $db->prepare("DELETE FROM tblSongExternalIds WHERE SongId = ? AND IdType = 'isrc'");
    $d->bind_param('s', $sid); $d->execute(); $d->close();
    $ok(songExternalIdSyncIsrcDenorm($db, $sid) === null && $col($sid) === null, 'empty store ⇒ NULL column');

    /* 5. Idempotence: sync twice, second is a no-op. */
    songExternalIdMirrorIsrc($db, $sid, 'USZZZ2612345');
    $ok(songExternalIdSyncIsrcDenorm($db, $sid) === 'USZZZ2612345', 'sync idempotent');

    /* 6. Corpus divergence census (read-only — what the reconcile card will touch). */
    $n = (int)$db->query('SELECT COUNT(*) FROM tblSongs s WHERE NOT (NULLIF(s.Isrc, \'\') <=> ('
        . songExternalIdIsrcProjectionSql('s.SongId') . '))')->fetch_row()[0];
    echo "INFO  divergent songs pre-reconcile (probe row excluded on rollback): {$n}\n";
} finally {
    $db->rollback();
    echo $fail === 0 ? "ALL PASS (rolled back)\n" : "{$fail} FAILURE(S) (rolled back)\n";
}
exit($fail === 0 ? 0 : 1);
```

5. **Alpha, post-deploy:** run the `reconcile-isrc-denorm` card; confirm the pending counter
   reaches 0 and STAYS 0 after an editor ISRC edit, a panel add/delete, and one merge; smoke
   `/isrc/<a-store-only-code>` and `api.php?action=song_by_identifier&type=isrc&value=<same>`.

---

## 9. Standing-tasks deltas (run the full checklist; these are the specifics)

- **File at the moment of discovery, BEFORE the fixing commit** (CLAUDE.md §2a): a bug issue for
  0.8 (merge cascade-destroys `tblSongExternalIds` rows — data loss since P5b), referenced by the
  §5.4 commit. Optionally one for 0.11 (ingest ISRC resolve raw-bound/column-only) if landed as
  its own commit.
- **#1749**: close with commit SHAs + this file's path + the §6 D-3 note; correct the issue's own
  "larger, later" framing with the escalation date.
- **Docs**: `api-docs.yaml` (`song_by_identifier` description + the ingest isrc-resolution note,
  §5.5); Wiki API/Schema pages (store = authority; column = denorm; identity map frozen);
  `CHANGELOG.md`; `.claude/ProjectBrief.md` continuation note; handoff.
- **This file** is the plan of record; `.claude/catalogue-1741-followups-small-plan.md` §1's
  "Option (3) … is **deferred**" sentence should gain a pointer here (do not rewrite history —
  append a dated correction line, the rule-#26-⚠️ lesson about stale adjacent claims).

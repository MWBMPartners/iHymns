# Plan — #1694 Stage 2: soft delete for songs

Fable 5 deep plan, 2026-07-31, against `claude/wave3-fixes` @ `34893e75`. Stage 2 of epic **#1692**
(owner approved the three-stage approach). Stage 1 shipped: `delete_songs` narrowed to
`['admin','global_admin']` as an interim, *because* a delete is unrecoverable. Stage 3 is **#1695**.

⚠️ **No MySQL in the container.** Every step marked ⚗ needs the alpha rehearsal (§7).

---

## The constraint that shapes everything

**Restore must be PREVENTION, not repair.** 38 of the 41 FKs referencing `tblSongs(SongId)` are
`ON DELETE CASCADE`. The moment the row goes, components, lyric lines, credits, media links, tags,
external links, work membership **and the entire revision history** go with it, and nothing the app
holds can rebuild them. So on the soft path the row is **never deleted**: `IsDeleted` keeps it and
every read filters it. A "snapshot before delete" cannot work — it would have to reproduce ~40 tables
faithfully, which is restore-from-backup by another name.

---

## 1. Schema — one migration, `appWeb/.sql/migrate-song-soft-delete.php`

Five columns + one index + one FK on `tblSongs`, each individually guarded/idempotent (the
`migrate-song-public-id.php` pattern — `_mig*_columnExists` probe before each `ALTER`).

```sql
ALTER TABLE tblSongs ADD COLUMN IsDeleted TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '...' AFTER UpdatedAt;
ALTER TABLE tblSongs ADD COLUMN DeletedAt DATETIME NULL DEFAULT NULL      AFTER IsDeleted;
ALTER TABLE tblSongs ADD COLUMN DeletedBy INT UNSIGNED NULL DEFAULT NULL  AFTER DeletedAt;
ALTER TABLE tblSongs ADD COLUMN DeletedReason VARCHAR(50) NULL DEFAULT NULL AFTER DeletedBy;
ALTER TABLE tblSongs ADD COLUMN DeleteNote VARCHAR(255) NOT NULL DEFAULT '' AFTER DeletedReason;
ALTER TABLE tblSongs ADD KEY idx_IsDeleted (IsDeleted, DeletedAt);
ALTER TABLE tblSongs ADD CONSTRAINT fk_Songs_DeletedBy
  FOREIGN KEY (DeletedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE;
```

- **Byte-identical** mirror (incl. COMMENT text) into the `tblSongs` CREATE TABLE block of
  `appWeb/.sql/schema.sql:162-217`, **same commit** (rule #19; `test-schema-coverage.php` enforces).
- One `@migration-adds tblSongs.<Col>` doctag **per column** — the scanner only catches the first
  `ADD COLUMN` per ALTER.
- ONE `migration-registry.php` entry; the four setup-database arrays derive from it. Probe reads the
  LIVE schema, multi-object OR so a partial apply never shows green. `_migProbe_constraintExists`
  already exists (migration-registry.php:88). **Triggers deliberately NOT in the probe** — they are
  host-optional and a probe they can never satisfy pins the card pending forever on trigger-denied
  hosts (#793 used a `tblAppSettings` sentinel for exactly this).

### SongCount triggers are part of this migration (VERIFIED, drives D1)

`trg_songs_songcount_ai/_ad/_au` (`migrate-songcount-triggers.php:151-177`) recompute an
**unfiltered `COUNT(*)`**, and the AU trigger fires **only on `SongbookAbbr` change**.
`SongData::getSongbooks()` reads `b.SongCount` (SongData.php:349, 1248) for the home tiles. So a soft
delete leaves hidden songs counted. Fix: DROP/CREATE the three triggers with `AND IsDeleted = 0` in
the recompute and `OR NEW.IsDeleted <> OLD.IsDeleted` in the AU condition, tolerant of
trigger-denied hosts exactly as #793 was (the recompute fallback gets the same predicate). Also make
`migrate-songcount-triggers.php` build the predicate conditionally on the column existing, so a fresh
install running cards in historical order does not create a trigger referencing a column that is not
there yet.

### Adversarial pass — "what would force a second migration?" (rule #20's method)

| Threat | Answer |
|---|---|
| #1695 queue of who/when/why | `WHERE IsDeleted = 1` **is** the queue; the four companion columns cover it. No ALTER. |
| #1695 wants a growing reason vocabulary | `DeletedReason VARCHAR(50)` + `SONG_DELETE_REASONS` map — one PHP line per reason, never an ALTER (rule #20). |
| #1695 notification | App-level. `PUSH_KINDS` from #1671 has **not landed** (grepped: zero hits). Nothing on `tblSongs`. |
| A future "proposed for deletion, still live" state | That is a **workflow** state, not a visibility state → its own queue table (the `tblLyricsReviewQueue` shape), not a third value on the flag. Visibility is genuinely binary, so `TINYINT` is safe. |
| Scheduled auto-purge after N days | Computable from `DeletedAt`. No column. |
| "Reviewed by admin" bookkeeping | Restore/Purge are both already `logActivity()`-logged. No column. |
| #1698 account erasure via the new FK | **Verified safe**: `accountEraseActionFor()` (`account_lifecycle.php:214-226`) maps a `SET NULL` FK not in `accountEraseBehaviouralColumns()` to `'keep'` — attribution resolves to the tombstone. `test-account-delete-fk-coverage.php` allows SET NULL. |
| iLyricsDB reuse | The predicate helper + column names are the portable unit; nothing DB-specific baked in. |

---

## 2. THE RISKY PART — read paths, DERIVED from the tree

**Real count: 166 `FROM tblSongs` / `JOIN tblSongs` sites across 37 files** (`grep -r`, since `rg`
cannot see `appWeb/.sql/`). The issue's typed list named ~10 surfaces. No exotic spellings
(`, tblSongs`, backticked, `AS`-aliased) evade the FROM/JOIN grep; remaining mentions are
INSERT/UPDATE/DELETE verbs, comments or prose.

### MUST filter

**`SongData.php` (31 sites)** — covers `songs_index`, `songs`, `songs_list`, `search`, `search_num`,
`random`, `songbook_export`, `bulk_songs`, **`bulk_audio`** (api.php:1821 funnels through
`getSongs()`), **`sitemap.xml.php`** (:70/:127), **`og-image.php`** (:101/:460), and every page
fragment using the class: `getMeta()` :232/:246, `getSongTranslations()` :302,
`_songbookSongLanguagesMap()` :747, `findSongIdByNumber()` :1214, `getSongCounterparts()`
:1574/:1612, `getSongsIndex()` :1704/:1718, `getSongsSlimIndex()` :1786, `getSongs()` :1902,
`getSongsByCreditName()` :2031, `getSongByNumber()` :2175, tune extras :2239, the search family
(:2610/:2674/:2720/:2909/:2926/:2990/:3056/:4221), `searchByNumber()` :3134, `getRandomSong()`
:3179/:3184, `_fetchSongRow()` :3344, `_worksMap()` :4363, `getWork()` :4547, `getCreditPerson()`
:4749.
**NOT** `_songIdForPublicId()` :3643 (resolver — the follow-up `_fetchSongRow` filters) and **NOT**
`getMissingSongNumbers()` :3264.

**`SongOfTheDay.php`** — `pickId()` :190/:204 (composes onto `WHERE 1=1`) and `buildResult()` :245.

**`api.php` direct SQL** — `song_by_identifier` :1234, `song_links` :1349, `song_translations`
:5978/:6010/:6137, `catalogue_language_subtags` :6375, `popular_songs` :8662, `song_history` :8730,
**`songs_exist` :8785**, `songs_by_tag` :9078, `related_songs` :9454/:9473/:9494/:9515/:9541,
`song_correction_submit` :11076, live-follow/service current-song validators
:15919/:16026/:16675.

**Fragments with their own SQL (easy to miss)** — `pages/song.php:382` (work siblings),
`songbook.php:86`, `person.php:185`, `tag.php:67`, `tune.php:48/:66`, `iswc.php:44`,
`partials/songbook-language-filter.php:96`, `live_activity_push.php:311`.

**Curation surfaces** (a deleted song must not be offered for merge/link) —
`tools/build-song-link-suggestions.php`, `manage/duplicate-songs.php` candidate + survivor queries,
`tools/auto-link-hard-id-counterparts.php`, `manage/ccli-report.php`, `manage/index.php` count,
`manage/languages.php`, `manage/works.php` / `catalogues.php` song lists,
`manage/gating-noop-verify.php`.

**`songs_exist` trade-off, stated honestly:** `js/utils/song-existence.js` prunes localStorage
Recently Viewed from this answer. Filtering means a soft-deleted-then-restored song silently drops
off device-local Recently Viewed. Favourites are safe (server-side rows survive — no cascade fires).
Filter it: the loss is cosmetic and pruning deleted songs is the endpoint's purpose (#1329).

### MUST NOT filter (each gets a `@deleted-visible: <reason>` marker)

- **`songRelocateIdTaken()`** (`song_relocate.php:321`) — a soft-deleted id **is** taken, and already
  is with **zero code change** (unfiltered probe + the `SongId` UNIQUE key). The work is to **PIN**
  it so a future sweep does not "helpfully" add the predicate — which would make a mint believe the
  id free and then 500 on the duplicate key.
- All id/number **mint seeds** — `ed2_allocateSongId` (api2.php:371-382), `lyrics_ingest.php:584`,
  the `_bulkImport_*` `MAX(Number)+1` seeds. A soft-deleted song keeps id AND number slot reserved.
- **`getMissingSongNumbers()`** :3264 + `admin_songbook_health` :9812 + `missing_songs` — filtering
  would list a deleted song's number as "missing", invite a curator to fill it, and produce a
  duplicate number on restore (the index is non-unique — nothing would stop it).
- `admin_songbook_delete` :12150 / `_cascade` :12236/:12251 — physical row counts (FK is RESTRICT).
- `manage/revisions.php` title join — the audit surface must keep showing a deleted song's history.
- Importer/ingest identity resolvers — matching the hidden row preserves single identity instead of
  minting a duplicate. *Defensible default, trivially changeable.*
- Editor **write-path** existence checks (`ed2_songExists`, `save_song_core.php` upserts) — a write
  into a hidden row is harmless and restore-preserving; the editor's *lists* go dark via
  `getSongs()`/`getSongsSlimIndex()`, giving a restore-first workflow without touching save funnels.
- FK pre-checks before user-data writes (`song_key_save` :8078), `songbook_maintenance.php` :83/:117,
  `manage/diagnostics.php` (free-form SQL console), everything under `appWeb/.sql/`.
- The new admin screen + restore + purge cores themselves.

### The mechanism — ONE shared unit for hand-built SQL

New `appWeb/public_html/includes/song_soft_delete.php` (shaped like `song_redirects.php`):

```php
songSoftDeleteReady(\mysqli $db): bool                    // memoised I_S probe, ALL FIVE columns in
                                                          // lockstep (the rule #25 gate shape)
songVisibleSqlFor(bool $ready, string $alias): string     // PURE: 's.IsDeleted = 0' | '1=1';
                                                          // alias \A[A-Za-z_][A-Za-z0-9_]*\z or ''
songVisibleSql(\mysqli $db, string $alias = 's'): string  // DB-bound driver
songSoftDeletedHolds(\mysqli $db, string $id): bool       // gated, fails OPEN — powers 410 +
                                                          // fallback suppression
songSoftDelete() / songRestore() / songPurge()            // write cores, §4
```

**The `'1=1'` degrade is the whole trick** for hand-built SQL: call sites embed it unconditionally
after `WHERE`/`AND`, so the SQL stays valid on an un-migrated install with zero per-site branching.
Inside `SongData`, one private `_visible(string $alias = 's'): string` delegates (`require_once` +
`$this->db`, exactly as :2144-2148 does for redirects). One shared unit, not thirty inlined
`AND IsDeleted = 0` — and it is the fragment-returning pattern the codebase already trusts
(`_songbookDisplayAbbrSelect()`, SongData.php:510).

### Two wrong-content traps inside `getSongById()`

1. **Number-fallback resurrection — exactly the #1689 class.** A filtered exact miss on a
   soft-deleted `CP-0412` falls through to `getSongByNumber('CP', 412)` (:2151) and serves
   **whatever now holds that number**. Fix: before the fallback,
   `if (songSoftDeletedHolds($this->db, $id)) { return null; }` — **as a SEPARATE `if` from the
   redirect claim.** ⚠️ Load-bearing: `tests/php/test-song-redirect-claim.php:265-284` asserts the
   existing `return null` is gated on `songRedirectClaimsId()` **alone** and rejects any added
   `||` conjunct. Widening that condition turns the existing guard RED; a second statement keeps it
   green.
2. **404 vs 410.** Both readers of a null (`pages/song.php:44-55`, `api.php` song_detail
   :1030-1043) already distinguish tombstone→410 from miss→404 via `songRedirectResolve()`. Add one
   `songSoftDeletedHolds()` consultation to each (owner decision D2; default 410).

---

## 3. Column-existence gating (#1228 class)

Migrations are WEB-RUN, three docroots share ONE MySQL, and `MYSQLI_REPORT_ERROR |
MYSQLI_REPORT_STRICT` makes an ungated `IsDeleted` reference **throw** — white-screening the page.
House pattern, three existing examples: `userStatusColumnReady()` (`user_status.php:329-344`),
`setlistSlotsColumnReady()` (`setlist_templates.php:502`), `lyricLinesMirrorPresent()`
(`lyric_lines_read.php:62`). `songSoftDeleteReady()` reads identically.

- **Reads** — `songVisibleSqlFor(false, …)` → `'1=1'` → un-migrated behaves exactly as today, and no
  leak is possible there because deleted songs cannot exist yet (same argument `userOwnerState()`
  makes at user_status.php:384-388).
- **`songSoftDeletedHolds()`** — fails **OPEN** (false), same reasoning as `songRedirectClaimsId()`
  (song_redirects.php:191-208): it only upgrades a 404 to a 410 and suppresses a heuristic; a broken
  probe must not 404 live pages.
- **Writes** — `songSoftDelete()`/`songRestore()` on an un-migrated install **refuse with HTTP 409**
  ("migration pending"), never silently fall back to the hard delete, which would quietly defeat the
  entire feature. Status is the contract, not prose (rule #35).
- **Deploy order** ⚗ — code to all three docroots first (verified no-op, gates return `'1=1'`), then
  run the card once on the shared DB.

---

## 4. Admin screen, purge, redirects, collateral

### `manage/deleted-songs.php`
Registered in `admin-links.php` (Songs group), entitlement **`delete_songs`**; the page gate must
name the same entitlement (`test-admin-gate-parity.php` derives this pairing and covers the new page
the day it is added). Table opts into `.admin-table-responsive` + `data-col-priority` + sortable
headers (#842/#844, rule #13). **Restore** POSTs to self under `validateCsrfRequest()` (rule #29),
clears all five columns, `logActivity('song.restore', …)`. **Purge** is admin-only
(`purge_songs` — D3), reachable **only from the deleted state** (two-step by construction), gated by
the §2 type-to-confirm control from `duplicate-songs.php:1066-1143`.

### Extract the lifecycle cores (modularity rule)
Today's hard delete exists **twice**, near-identically: `manage/editor/api.php:3384-3495` and
`manage/editor/api2.php:865-932`. Move into `song_soft_delete.php`:
- `songSoftDelete($db,$songId,$userId,$reason,$note)` — one UPDATE in a transaction; refuses if
  already deleted or un-migrated. **Writes NOTHING to `tblSongRedirects`, calls no
  `songRedirectRepoint()`.**
- `songPurge($db,$songId,$userId,$redirectTo)` — the *entire existing* delete body moved verbatim
  (PublicId capture gated on `songPublicId_columnReady()`, `songRedirectRepoint()` when relinking,
  cascade DELETE, `songRedirectWrite()` for SongId + PublicId). Requires `IsDeleted=1` first.
- Both v1 and v2 `delete_song` delegate to `songSoftDelete()`; the `redirectTo` body param is ignored
  server-side and the editor modal copy changes to "moves to Deleted songs — restorable; relink
  happens at purge".

### The #1679 redirect interaction, worked through
**Soft delete touches `tblSongRedirects` not at all** — load-bearing. If it wrote the rows today's
delete writes, restore would have to un-write them, and it **cannot un-write a
`songRedirectRepoint()`**: after A→B (move) then B deleted-with-relink-to-C, repoint rewrites A→C
*before* the delete, and restoring B has no record that A ever pointed at it. That is precisely the
stranded chain the issue warns about. Writing nothing makes delete→restore a **provable no-op on
redirect state**. While deleted, an inbound A→B resolves to B, the filtered read misses,
`songSoftDeletedHolds()` answers, the page says 410 — honest, and self-heals on restore. Purge is
where the existing well-tested redirect dance runs, unchanged.

### Setlists, shared setlists, works, favourites
- `tblUserSetlists.SongsJson` / `tblSharedSetlists.Data` hold bare ids with **no FK**
  (schema.sql:1127, 1182-1200): the id stays, the song reads as removed while deleted (as today for a
  hard delete), and **comes back intact on restore** — strictly better than today. No writes needed.
  `og-image.php:460`'s setlist preview already tolerates a null `getSongById()`.
- `tblUserFavorites` (CASCADE, schema.sql:1218): the row **survives** a soft delete — the favourite
  reappears on restore. Purge cascades it away, as today.
- `tblWorkSongs` (CASCADE): membership survives; the work page filters it out while deleted.
- Live Follow `CurrentSongId` (SET NULL, schema.sql:3169): untouched; the broadcast validators filter
  so a deleted song cannot be *newly* broadcast.

---

## 5. The guard — two files, split by whether a runtime handle exists

### `tests/php/test-song-soft-delete.php` — BEHAVIOURAL
- `songVisibleSqlFor()` (pure): both branches, alias validation (empty / valid / hostile), exact
  strings.
- `songSoftDeleteReady()` / `songSoftDeletedHolds()`: the doubles already exist —
  `ClaimProbeFailingMysqli` (test-song-redirect-claim.php:293-302) for fails-open,
  `GateRecorderMysqli` (test-song-relocate-gates.php:163, faithful STRICT-mode recording double) for
  statement shapes. Assert holds-probe fails OPEN; ready-probe memoises success only.
- Write cores via the recording double: `songSoftDelete()` prepares an `UPDATE`, never a `DELETE`,
  and **no statement touching `tblSongRedirects`**; `songPurge()` prepares the DELETE + redirect
  write; both refuse in the wrong state. Decision cores kept pure (state in, verdict out) and CALLED
  across live/deleted/absent × migrated/un-migrated.
- **The `songRelocateIdTaken()` pin**: drive with the recording double, assert the executed
  `tblSongs` probe recognises a soft-deleted row as taken — the double throws loudly by its own
  design if the query mutates to a filtered spelling.
- The `getSongById` suppression: `songSoftDeletedHolds` is CALLable; the **wiring order** (before
  fallback, separate `if`) is a call-graph property → source assertion using the **balanced-paren**
  technique already built in test-song-redirect-claim.php:223-246. Do NOT regex — that file
  documents why, twice.

### `tests/php/test-song-visibility-guard.php` — TREE-DERIVED scan
Modelled on `test-component-json-guard.php`, built on `tests/php/lib/php_source_units.php` (its
`sqlOnly` view reconstructs SQL across concatenation, so multi-line/interpolated statements cannot
hide).
- Derive every function unit under `appWeb/public_html` whose `sqlOnly` contains a SELECT naming
  `tblSongs`. Require each to contain `songVisibleSql(` / `_visible(` / `songSoftDeleteReady(` **or**
  a `@deleted-visible: <reason>` marker. `appWeb/.sql/` not scanned; `song_soft_delete.php` the
  single file-level allowlist entry.
- **Self-check against silent under-report** (the #1696/#1700/#1701 lesson): assert the scanner found
  ≥ a floor (~150 today, derived at write time, asserted `>=` not `==`) so a broken parser reports
  RED, not a confident empty green.
- **Mutation-test both directions before the guard's first commit**: (a) strip the helper from one
  filtered site → red; (b) plant an unmarked new `FROM tblSongs` SELECT → red; (c) stays green on the
  correct tree *including* the deliberately-unfiltered sites (rule #34's other half). Record the
  mutations in the test header, house style.

**Existing guards that constrain this work:** `test-song-redirect-claim.php` (the separate-`if`
rule), `test-schema-coverage.php`, `test-migration-registry.php`, `test-admin-gate-parity.php`,
`test-entitlement-parity.php` (+ #1693's `$e1Baseline`/MUT-6 fixtures if D3 adds `purge_songs`),
`test-openapi-actions-exist.php`. Wire both new tests into `.github/workflows/test.yml` (rule #35 —
the npm-vs-CI list drift).

### What genuinely has NO runtime handle
Whether the filtered SQL is correct against the live schema, whether the triggers behave, and whether
any surface leaks end-to-end. No MySQL in CI → the alpha rehearsal, §7. The plan says so rather than
letting the guard's tick be over-read.

---

## 6. Commit sequence (ONE PR to `alpha`)

1. **`feat(db)`** — migration + byte-identical schema.sql mirror + registry entry + trigger update +
   doctags. *Dormant; nothing reads the columns.*
2. **`feat(songs)`** — `includes/song_soft_delete.php` + `test-song-soft-delete.php`. *Dormant.*
3. **`feat(songs)`** — the read-path sweep, `@deleted-visible` markers, the two `getSongById` traps,
   the 410 answers, + `test-song-visibility-guard.php`. *Largest; mechanical per site;
   behaviour-identical until a song is soft-deleted, byte-identical un-migrated via `'1=1'`.*
4. **`feat(editor)`** — v1/v2 `delete_song` delegate to `songSoftDelete()`; modal copy;
   api-docs.yaml. *The behaviour change; single revertable commit.*
5. **`feat(admin)`** — `manage/deleted-songs.php` + nav + Restore/Purge (+ `purge_songs` across both
   maps, labels, baseline fixtures — pending D3).
6. **`docs/test`** — CI wiring, CHANGELOG, help, wiki, handoff, issue updates.

---

## Owner decisions — ALL FOUR ANSWERED 2026-07-31

> **D1 = (a) visible.** Owner: *"Song count should be songs visible. It may not be that the user has
> access to all songs (due to gating such as for CCLI or any other reason), but is meant to indicate
> the total number of songs that can (ideally) be accessed by users. Song Count should therefore
> exclude soft-deleted/hidden songs."*
>
> ⚠️ Note the nuance, because it constrains any future "improvement": the count is **"ideally
> accessible"**, NOT "accessible to you". It must NOT be made per-user — entitlement/CCLI gating is
> deliberately excluded from it. A future change that tried to subtract gated songs per viewer would
> break the shared-cache home fragment (rule #6) as well as contradicting this decision.
>
> **D2 = 410.** Owner: *"If we want to be honest, a deleted song should return a HTTP 410."*
> **D3 = yes** (add `purge_songs` now). **D4 = yes** (`admin_export` includes soft-deleted rows).

Original framing kept below for the reasoning.

### Owner decisions

**D1 — Trigger redefinition: does `SongCount` mean visible songs or physical rows?** *(blocks
commit 1 only)* — (a) redefine to count `IsDeleted = 0`: tiles stay truthful, trigger DDL churn on
the shared DB; (b) leave physical: zero trigger risk, tiles overcount by the number of hidden songs
until purge. Doing nothing = (b). **Recommendation (a)** — it is a public-facing number and the
trigger migration is already re-runnable/host-tolerant.

**D2 — Public answer for a soft-deleted song's URL: 410 vs 404.** *(non-blocking)* — 410 tombstone
(honest, matches the redirect-tombstone UX, one gated probe on an already-missed request) vs 404
(zero code, reads as "never existed"). **Recommendation 410**, applied as the default.

**D3 — Add a `purge_songs` entitlement now?** *(blocks commit 5's shape)* — stage 3 re-widens
`delete_songs` to editors (#1695); without a separate key, purge widens with it or needs a hardcoded
role check — the exact red-flag pattern the review list bans. **Recommendation: add it now** — one
line in each map + labels, guarded by the existing parity tests.

**D4 — `admin_export` (api.php:9590): include soft-deleted rows?** *(non-blocking)* —
**Recommendation: include, with an `IsDeleted` column when migrated.**

---

## 7. ⚗ Alpha rehearsal (nothing here is runtime-verifiable in this container)

Deploy code to all three docroots → verify byte-identical behaviour un-migrated (no white screens,
the #1228 class) → run the card once → soft-delete a test song → confirm absent from `songs_index`,
`songbook_export`, `bulk_songs`, `bulk_audio`, search (fulltext / LIKE / soundex / alt-title /
scripture), Song of the Day (seeded date), related songs, sitemap, OG image, tag/person/tune/work/
iswc pages, popular/history → confirm `/song/<id>` and `/song/<PublicId>` answer per D2 **and the
NUMBER url does NOT serve a different song** → confirm still listed in Deleted songs, revisions
intact, favourite row intact, id reported taken by the v2 mint → restore → confirm fully intact
including revision history and favourites → purge → confirm tombstone redirect row + 410, and
SongCount per D1. Then the standing-tasks checklist.

## Honest unknowns (planner's own words)

- Not every one of the 12 `manage/editor/api.php` + 7 `manage/songbooks.php` sites was traced
  individually; the sweep classifies each at implementation time and **the derived guard refuses any
  site left unclassified** — the mechanism does the remembering, not the list.
- The live AU-trigger body may predate the current migration source (trigger-denied hosts use the
  recompute fallback); the rehearsal must check which mode alpha is in.
- Whether `SongData`'s `jsonMode` (:143) is reachable in production could not be determined; the
  sweep leaves it unfiltered (no `IsDeleted` in fixtures) and it predates soft delete on every path
  found.

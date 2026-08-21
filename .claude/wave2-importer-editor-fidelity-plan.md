# Wave 2 — Importer / Editor Fidelity — Implementation Plan

**Program:** 2026-08-21 queued-gap program, Wave 2 (sequential Fable-5 planning pass → Sonnet implementation).
**Branch:** `claude/ilyrics-identity-work-model` (the one working branch — standing-directives: no PR stacking).
**Planned:** 2026-08-21. All file:line anchors verified against the working tree at commit `1aa1b84b`.
**Execute:** commit-by-commit, in the order given. Every commit must pass `php -l` on touched PHP and
`node --check` on touched JS before it is made (CLAUDE.md commit expectations).

> ⚠️ **Sequencing constraint vs Wave 1 (Unicode, #1908):** Wave 1's TXT-UTF-16 fix edits
> `appWeb/public_html/includes/song_importers.php` (the `_bulkImport_parseTxt()` region at :267 and the
> JSON-importer entry points). Wave 2's Commits **C1, C6–C9** edit the SAME FILE (regions :350–370,
> :434–780, :875–954, :2781–2925, :4757–4763 — all disjoint from Wave 1's region, so conflicts are
> unlikely but not impossible). **Implement Wave 2 only after Wave 1's `song_importers.php` commit has
> landed on the branch**; if Wave 1 is re-sequenced, rebase Wave 2's importer commits on top of it,
> never the other way round.

---

## Verification verdicts (the four candidates)

| # | Candidate | Verdict | Wave-2 action |
|---|---|---|---|
| #1904 | Bulk import drops writers/composers credits | **DROPPED — already done** (fixed as #1736, closed 2026-08-05, landed `0b7d8ecf` 2026-08-11) | Close the issue with evidence + fix one stale comment (C1) |
| #1743 | `PreviousData = NULL` in v2 revisions | **REAL** — `ed2_touchRevision()` hardcodes NULL; legacy restore 409s with a misleading message | Spec'd — C2–C3 |
| #1669 | Alt-titles have a complete read half, no writer | **REAL** (song half) — nothing anywhere can create a `tblSongAlternativeTitles` row | Spec'd — C4–C6, C9 |
| #1674 | Bulk import has no dry-run | **REAL** (core) — no `dryRun` anywhere; the "chordpro missing from dropdown" side-item is **already fixed** (#1264) | Spec'd — C7–C8 |

**3 of the 4 are real gaps.** Details and evidence below.

---

## §0 Dropped as already-done / misfiled

### #1904 — "Bulk importers don't persist writers/composers credits" → ALREADY DONE

The issue (filed 2026-08-18T13:14:42Z) claims `_bulkImport_saveSong()` "still never persists a song's
writers / composers … credits". **That is false against the current tree.** Evidence:

1. **The credit write exists and is complete** — `appWeb/public_html/includes/song_importers.php:676-737`
   (comment header cites #1736): writes all **five** role tables
   (`tblSongWriters` / `tblSongComposers` / `tblSongArrangers` / `tblSongAdaptors` / `tblSongTranslators`;
   `tblSongArtists` deliberately omitted — #587-migration-gated and no importer parses artists),
   per-role case-insensitive dedup (#1178 posture), all bound params (rule #5), inside the import
   transaction.
2. **Rule #43 (registry find-or-create) is satisfied** — each name goes through
   `creditEntryNormalise()` (`includes/musician_helpers.php:1461`) and `musicianPromote()`
   (`musician_helpers.php:1544`), which **returns the existing or newly-inserted `tblMusicians.Id`** —
   the exact find-or-create the editor's credit path uses (rule #22: it IS the shared core, not a fork).
   The "richest parts across roles" merge at :706/:720-731 handles a name typed differently in two roles.
3. **The PD-suggestion hook is wired** — `pdRecomputeForSong()` post-commit at :757-764 (#1862).
4. **It landed via #1736** — issue #1736 ("Importer `_bulkImport_saveSong()` parses writers/composers
   then DROPS them"), closed **completed 2026-08-05**; code landed in the consolidated batch
   `0b7d8ecf` (2026-08-11, PR #1810). #1904 was filed **a week later**, 32 seconds after the
   #1673-column-half fix `8807152d`, from #1673's stale body text — without re-reading the tree.
   This is precisely the audit's warning ("apparent gaps are often already partly done").
5. **Guards already cover it** — `tests/php/test-credit-registry-promote.php` (the #960 tree-derived
   guard: every credit-writing file must call `musicianPromote`) and
   `tests/php/test-editor2-metadata-1862.php` (scans every `INSERT INTO tblSongWriters` and asserts
   the `pdRecomputeForSong` reference — `song_importers.php:760-762` names it).
6. **The "arrangers/adaptors/translators dropped by the interchange importer" reading is not a gap** —
   `tests/fixtures/songs.schema.json` defines only `writers` + `composers` (its `composers`
   description reads "Music composers / **arrangers**" — arrangers are folded in by format design,
   :119-125). The iHymns-JSON mapper (`song_importers.php:4782-4792`) maps both through; the empty
   arrays for the other three roles reflect the format, not a discard.

**Residual truth worth keeping (folded into C1):** the comment at `song_importers.php:4757-4763`
still claims "saveSong still hardcodes copyright / ccli / verified / the public-domain + media flags
to empty on INSERT" — **stale**: `8807152d` fixed exactly that (`:459-472` now reads them via
`_bulkImportRightsFromSong()`) but did not update this comment (verified: its diff contains no
`REMAINING LIMITATION` hunk).

**Implementer action (C1):** fix the stale comment; close #1904 on GitHub as *completed / duplicate
of #1736*, citing `0b7d8ecf`, `song_importers.php:676-737`, and points 1–6 above. Do NOT invent
credit work.

### #1674 side-item — chordpro missing from the import2 dropdown → ALREADY DONE

`manage/editor/import2.php:36-60` now contains `'chordpro' => 'ChordPro (.cho, .chopro, .crd,
.chord, .pro)'` with a #1264 comment describing exactly the #1674 side-note, and the file-input
`accept` list (:104) includes the ChordPro extensions. Nothing to do; mention it when closing #1674.

---

## §1 Gap A — #1743: v2 revisions write `PreviousData = NULL`; legacy restore 409s misleadingly

### Established facts (decide-with-evidence, as tasked)

- `manage/editor/save_song_core.php:415` — **NOT a bug.** `$previousData = null;` is initialisation
  only; lines **:416-423 immediately load the pre-edit `tblSongs` row** and set
  `$previousData = json_encode($prevRow)` whenever the song exists. NULL survives only for a
  brand-new song (`$action = 'create'`, :424) — correct.
- `includes/song_importers.php:528` — **NOT a bug.** `_bulkImport_saveSong()` is INSERT-ONLY
  (existence + title-dedupe pre-flights return `'skipped'` at :494-516 before the transaction), so
  every revision it writes is a genuine create. NULL is the correct "no previous state".
- **THE bug:** `ed2_touchRevision()` — `manage/editor/api2.php:1811-1849` — hardcodes the column in
  its INSERT: `VALUES (?, ?, ?, NULL, ?, "approved")` (:1830-1831). Every v2 granular edit
  (`metadata_field_update`, `credit_upsert`, restore-audit rows, ~15 call sites) writes a revision
  with no prior state.
- **The consumer:** legacy `manage/editor/api.php` — `get_revisions` emits `previousData` (:1216,
  :1234); `restore_revision` restores **PreviousData** (:1275, :1286-1292) and answers
  **409 "This revision has no prior state to restore (likely the initial create)."** (:1293-1297) —
  a wrong diagnosis for an ordinary v2 metadata edit (the issue's behavioural proof, revision 9856).
- **v2 is internally consistent:** `revision_restore` (api2.php:5792+) restores **NewData**, and
  `v2/revisions-tab.js:37-55` documents the v1/v2 semantics difference in its confirm dialog. v2 has
  **no diff view** ("revision_list returns metadata only … A real side-by-side diff … is tracked
  separately").
- **Snapshot shapes in `tblSongRevisions` (critical):** three shapes coexist —
  1. **editor-payload shape** (lowercase keys `id`/`title`/`components`…): `save_song_core.php:1246`
     (`NewData = json_encode($song)`) and `song_importers.php:743`;
  2. **v2 full-snapshot shape** `{song:{<tblSongs row, Uppercase keys>}, components, credits, tags,
     links}`: `ed2_buildSongSnapshot()` returns this at api2.php:1568;
  3. **bare tblSongs-row shape** (Uppercase keys): every correctly-written `PreviousData`
     (save_song_core :422, legacy restore's own re-capture api.php:1305-1309, :1380).
  The legacy restore's scalar path is gated on `isset($restorePayload['Title'])` (api.php:1319) —
  it silently **skips everything and still claims success** for shapes 1 and 2. `ed2_applySongSnapshot()`
  already has the inverse tolerance (`$songRow = $snap['song'] ?? $snap`, api2.php:1600).

### Decision (with recommendation, per the owner-decision protocol)

The issue offered (1) populate `PreviousData` vs (2) retire the legacy restore with the v1
retirement. **Wave 2 implements a scoped form of (1): chain-populate.** Reasons: (2) belongs to the
v1-retirement epic, not an importer/editor-fidelity wave; (1) is a single-point change that makes
every new revision row self-describing (also what a future diff view needs), leaves v2 behaviour
byte-identical, and does not block (2) later. **Full pre-edit snapshot capture (restructuring ~15
call sites to snapshot before mutating) is explicitly rejected** — invasive and doubles the snapshot
cost per edit for no consumer that needs the extra precision.

**The chain rule:** revision N's `PreviousData` := revision N-1's `NewData`, verbatim, whatever its
shape; NULL only when no prior revision exists. Because `ed2_touchRevision` coalesces (~15 s window,
:1813-1823), "the previous revision's NewData" IS the last audited state — the correct pre-state at
the audit trail's own granularity (document this in the doc-block).

### C2 — populate `PreviousData` in `ed2_touchRevision()` (api2.php)

*File:* `appWeb/public_html/manage/editor/api2.php`, function `ed2_touchRevision()` (:1811-1849).

1. After the coalesce check and before the INSERT: prepare + execute
   `SELECT NewData FROM tblSongRevisions WHERE SongId = ? ORDER BY Id DESC LIMIT 1` (bound `s`,
   rule #5), fetch into `$previousData` (string|null; NULL when no row or stored NewData is NULL).
2. Change the INSERT (:1829-1834) from the literal `NULL` to a bound 5th placeholder:
   `(SongId, UserId, Action, PreviousData, NewData, Status) VALUES (?, ?, ?, ?, ?, "approved")`,
   `bind_param('sisss', $songId, $userId, $actionTag, $previousData, $newData)`.
3. Extend the doc-block (:1803-1810): the chain rule, the coalesce-granularity caveat, and that a
   song with no prior revision rows (legacy pre-revision data) legitimately stays NULL.
4. Do NOT touch the `songRelocateIsTransactionFatal` re-throw (:1846) — the new SELECT lives inside
   the same try, so a transaction-fatal error still propagates (#1679 A1).

*No historical backfill.* Old NULL rows stay NULL: the v1 UI is retiring, and v2 restores NewData —
which every historical row has. (If the owner ever wants it, it is a one-shot `'manual'` migration
card chaining `Id`-adjacent rows; explicitly out of Wave 2 scope.)

### C3 — legacy restore: shape tolerance + honest 409 (api.php)

*File:* `appWeb/public_html/manage/editor/api.php`, `restore_revision` case (:1247-1400).

1. Immediately after decoding `$restorePayload` (:1290-1292) and the existing NULL-409 (:1293-1297),
   add the **'song'-key unwrap** mirroring api2.php:1600:
   `if (is_array($restorePayload['song'] ?? null)) { $restorePayload = $restorePayload['song']; }`
   → a chained v2 full snapshot restores its scalars through the existing `isset($restorePayload['Title'])`
   path (:1319+) unchanged.
2. Add a **shape guard** so the pre-existing silent-success hole closes (rule #30/#33's silent-no-op
   family): after the unwrap, `if (!isset($restorePayload['Title'])) { 409 }` with an accurate
   message, e.g. *"This revision's snapshot is stored in the v2 editor format and cannot be restored
   here — use the v2 editor's Revisions tab."* Branch on status, never prose (rule #35). This
   replaces today's behaviour for an editor-payload-shaped (lowercase) PreviousData, which previously
   skipped the scalar UPDATE **and still answered success**.
3. Leave the scalar column list, the `SongbookAbbr` exclusion (#1679, :1337-1351), the components
   comment and the searchFold call (:1366-1372) untouched. **Rule #25 is untouched** — this commit
   writes no lyric-line or component path.
4. Method note from the issue: exercising this endpoint manually needs `X-Requested-With` **and** an
   `Origin`/`Referer` header (#1388-era CSRF gate).

### C3 verification (both commits)

- New test `tests/php/test-revision-previous-data.php` (source-contract, **mutation-proven** per rule
  #34 — break each assertion, watch red, restore):
  - api2.php's `ed2_touchRevision` INSERT no longer contains a literal `NULL` in its VALUES list and
    a prior-revision `SELECT NewData … ORDER BY Id DESC LIMIT 1` exists inside the function body
    (bounded regex window ≥300 chars — the #1671 lesson: narrow windows under-report);
  - api.php's `restore_revision` contains the `['song']` unwrap AND a `!isset(...['Title'])` 409
    guard between JSON-decode and the scalar UPDATE;
  - `save_song_core.php` still binds a real `$previousData` (guards against a regression that
    re-hardcodes NULL there).
- Stays green: `tests/php/test-editor-api2-contract.php`, `test-transaction-fatal.php` (the #1679 A1
  predicate), `test-php-source-units.php`, full `php -l`.
- Manual (alpha): make a v2 metadata edit → legacy editor revision list → Restore → succeeds;
  restore a genuine `create` revision → still the (now truthful) "no prior state" 409.

**Effort:** Small — ~25 changed lines + one test file. Half a day including verification.

---

## §2 Gap B — #1669: `tblSongAlternativeTitles` write path (editor + API; importer optional)

### Established facts

- **No creator exists.** Tree-wide, the only INSERT is api2.php:2055-2056 — the `duplicate` action's
  `INSERT … SELECT` copy, which can never create a FIRST row. (The songbook half has since gained a
  writer — `manage/songbooks.php:2026-2040` — the song half has not.)
- **Read half is live and dictates the contract:** `SongData::_songAltTitlesMap()`
  (`includes/SongData.php:1383-1438`, emits `{title, note, language}` ordered
  `SortOrder ASC, Title ASC`), attach at :4431-4432 (`song_detail`), public render
  `includes/pages/song.php:581-586`, OG image `index.php:378-382`, the #832 search boost
  (`SongData.php:3771`, :3833, :3850-3883 — alt-title matches rank top).
- **Schema (already migrated + carded):** `schema.sql:2934-2950` —
  `tblSongAlternativeTitles(Id, SongId VARCHAR(20) FK CASCADE, Title VARCHAR(255), Language VARCHAR(35) NULL,
  SortOrder INT DEFAULT 0, Note VARCHAR(255) NULL, CreatedAt)`, **`UNIQUE uq_song_title (SongId, Title)`**
  under `utf8mb4_unicode_ci` (case-insensitive uniqueness). Migration card `'alternative-titles'`
  exists (`manage/includes/migration-registry.php:843-859`) — **no schema work, rule #19 satisfied
  by doing nothing.**
- **The issue's ⚠️ questions, answered from the read half:** uniqueness = per-song, case-insensitive
  (the UNIQUE key); alt titles do NOT participate in `NormalizedTitle` dedup (no fold column, no
  reference from `title_normalize.php` callers); merge collisions are already handled — the
  `/manage/duplicate-songs` re-point loop uses `UPDATE IGNORE` + DELETE-leftover
  (`manage/duplicate-songs.php:192-203`), so a colliding row is dropped as a duplicate fact. No
  design risk there.
- **Rule #43 statement (pre-empting review):** an alt title is per-song free text — a *title string*,
  not a reference to a registry entity — so NO find-or-create picker applies. Credits/tunes/publishers
  discipline is not implicated.
- **Revision-snapshot posture:** alt titles stay **OUT of `ed2_buildSongSnapshot()`/`ed2_applySongSnapshot()`**,
  the documented external-IDs posture (api2.php:157-163: "external IDs sit outside the content
  snapshot, the same posture as tblSongLinks … and media file metadata"). Rationale: identical
  add/delete row lifecycle, and keeping the strict snapshot contract byte-stable. Record the posture
  in the new endpoints' doc-block exactly as :161-163 does. (The `duplicate` copy at :2055 already
  covers the clone path.)

### C4 — the ONE shared core: `includes/song_alt_titles.php` (new file)

Rule #22: api2, the importer (C9) and any future admin surface all delegate here — never a second
INSERT site. Functions (all prepared + bound, rule #5; mysqli STRICT throws — no false-checks):

- `songAltTitlesTableExists(\mysqli $db): bool` — static-cached INFORMATION_SCHEMA probe (mirror
  `publisherTableExists()` / `songExternalIdsTableExists()` shape).
- `songAltTitlesList(\mysqli $db, string $songId): array` — rows
  `{id:int, title:string, language:string, note:string, sortOrder:int}` ordered
  `SortOrder ASC, Title ASC` (the read half's own ordering, SongData.php:1401-1403).
- `songAltTitleAdd(\mysqli $db, string $songId, string $title, ?string $language, ?string $note): array`
  → `{id:int, created:bool, row:array}`. Contract:
  - `title` trimmed, non-empty, `mb_strlen ≤ 255` (chars, not bytes);
  - `language` trimmed; `''` → NULL; else must match the soft BCP-47 grammar already used at
    `song_importers.php:932` (`/^[a-z]{2,3}(-[A-Za-z0-9]+)*$/i`), capped `mb_substr(…, 0, 35)` —
    invalid input is the CALLER's 422, the core returns a distinguishable error (see endpoint);
  - `note` trimmed, `mb_strlen ≤ 255`, `''` → NULL;
  - `SortOrder` = per-song `MAX(SortOrder)+1` (preserves add order; read path breaks ties by Title);
  - write = `INSERT IGNORE` (uq_song_title absorbs the dupe) + **canonical re-select** by
    `(SongId, Title)` so the echo is the stored row — the exact `song_external_id_add` idiom
    (api2.php:4441-4468); `created` = `affected_rows > 0`.
- `songAltTitleDelete(\mysqli $db, string $songId, int $id): int` — `DELETE … WHERE Id = ? AND
  SongId = ?` (cross-song defence-in-depth, mirroring api2.php:4536-4545); returns affected rows
  (0 = idempotent double-click, not an error).
- `songAltTitleIsRedundant(string $alt, string $mainTitle): bool` — ONE mb case-insensitive
  equality check (plain CI compare, deliberately NOT the aggressive `ihymns_normalize_title()` fold —
  punctuation-variant alts are legitimate data). Used by C5 and C9.

File doc-block: project annotation standard (ELI5 + detailed registers, #1669/#832 links).

### C5 — api2 endpoints + v2 Metadata-tab panel + api-client

*Files:* `manage/editor/api2.php`, `manage/editor/v2/api-client.js`,
`manage/editor/v2/alt-titles-panel.js` (new), `manage/editor/v2/metadata-tab.js`,
`appWeb/public_html/api-docs.yaml`.

1. **Three api2 actions**, mirroring the external-ID trio byte-for-byte in structure
   (GET `song_external_ids` :4336+; POST add :4404-4503; POST delete :4505-4573), same blanket
   auth/CSRF/entitlement posture as those cases sit under (edit_songs; `X-Requested-With` gate,
   rule #29 — nothing new to build):
   - `GET song_alt_titles?id=<songId>` → `{ok, altTitles:[…]}` (delegates `songAltTitlesList`);
   - `POST song_alt_title_add {songId, title, language?, note?}` → `{ok, altTitle, created}`.
     Status contract (rule #35): **404** unknown song (`ed2_songExists`), **409** un-migrated table
     (`songAltTitlesTableExists` false — message names the `'alternative-titles'` migration card at
     `/manage/setup-database`, mirroring :4413-4415), **422** empty/over-length title, invalid
     language tag, or `songAltTitleIsRedundant($title, <current tblSongs.Title>)` ("That is already
     the song's main title."); `created:false` on a duplicate ("Already recorded" client-side —
     the :4441-4444 posture);
   - `POST song_alt_title_delete {songId, id}` → `{ok, deleted}`.
   - `logActivity` keys: `song.alt_title.add` / `song.alt_title.delete` (mirror
     `song.external_id.add`, :4488). Add the three actions to the api2 header doc-block action list
     (:149-207) **including the out-of-snapshot posture note** (mirror :161-163).
   - **No revision row** (`ed2_touchRevision` NOT called) — same as external IDs; the doc-block note
     is the mechanism-adjacent record.
2. **api-client** (`v2/api-client.js`, after :303): `listAltTitles(songId)`,
   `addAltTitle(songId, title, language, note)`, `deleteAltTitle(songId, id)` — same
   `getJson`/`postJson` shapes as :301-303.
3. **Panel** `v2/alt-titles-panel.js` (new): `mountAltTitlesPanel(container, {api, songId, toast})
   -> teardown fn`, modelled line-for-line on `external-ids-panel.js` (list on mount, add form —
   title + optional language + optional note —, per-row delete, 409-un-migrated rendered as an info
   alert naming the migration card, `created:false` toast "Already recorded"). Keyboard/ARIA to the
   same standard as the model panel.
4. **Mount** from `metadata-tab.js` exactly as external-ids-panel is mounted (dynamic `import()` at
   :1513-1516), placed with the title/composition block (the :844 comment region) so alt titles sit
   beside the Title field. Teardown wired into the tab's existing detach flow.
5. **OpenAPI:** document all three actions in `api-docs.yaml` (the file already documents
   `song_external_id_add` — mirror those entries). `tests/php/test-openapi-actions-exist.php`
   enforces documented→exists; the standing directive requires the docs regardless.

### C6 — tests for B (own commit so C4/C5 stay revertable)

- `tests/php/test-song-alt-titles.php` (source-contract, tree-derived, mutation-proven — rule #34):
  - the ONLY `INSERT` targets for `tblSongAlternativeTitles` in the tree are
    `includes/song_alt_titles.php` and the pre-existing duplicate-copy at api2.php:2055
    (count-exact allowlist of 1 for the latter, comment-stripped scan);
  - all three api2 cases delegate to the core (no inline SQL in the case bodies);
  - the core's add contains `INSERT IGNORE` + a canonical re-select + bound params;
  - every core function's SQL touches the table only behind the probe (grep the gate);
  - PHP↔JS action-name lockstep: the three action strings in api2.php match api-client.js (rule #35).
- `tests/test-v2-alt-titles-ui.js` (mirror `test-v2-external-ids-ui.js`): panel exports
  `mountAltTitlesPanel`, metadata-tab imports it, teardown returned, no raw `fetch` (panel goes
  through the injected `api`).
- Stays green: `test-editor-api2-contract.php`, `test-openapi-actions-exist.php`,
  `test-duplicate-copy-set.php` (duplicate's copy-set untouched), `test-song-fixture-shape.js`,
  `test-component-json-guard.php` (no lyric-line involvement — rule #25 untouched).
- Manual (alpha, un-migrated + migrated): panel on an install WITHOUT the table → 409 info card;
  after running the card → add "Faith's Review and Expectation" to Amazing Grace → appears on the
  public song page (song.php:581), and searching it returns the song top-ranked (the #832 boost,
  live for the first time).

**Effort C4-C6:** Medium — one new include (~150 lines), three api2 cases, one panel module, two
test files. ~1–1.5 days.

### C9 — OPTIONAL importer path (sequenced LAST; see §4 ordering)

OpenLyrics is the one supported format whose spec carries multiple `<title>` elements; the current
parser takes only the first (`song_importers.php:2807`). Scope:

1. `_bulkImport_parseOpenLyrics()` (:2781): iterate `$props->titles->title`; first non-empty = main
   title (unchanged); remaining distinct, non-empty entries →
   `'altTitles' => [{title, language}]` (language from the element's `lang` attribute when present,
   soft-validated exactly like the C4 core will).
2. `_bulkImport_assembleSong()` (:2894): pass `'altTitles' => $parsed['altTitles'] ?? []` through.
3. Empty-song template (:350-370): add `'altTitles' => []`.
4. `_bulkImport_saveSong()`: inside the transaction, after the credit block (:737) and before the
   revision row (:739): `require_once` the C4 core; if `!empty($song['altTitles']) &&
   songAltTitlesTableExists($db)` loop `songAltTitleAdd()`, skipping entries where
   `songAltTitleIsRedundant($alt, $title)`. Absent key / un-migrated table = clean no-op (the #1673
   no-throw caveat: a parser that supplies none must import identically).
5. Extend `tests/php/test-openlyrics-parser.php` with a two-title fixture asserting the parsed
   `altTitles`; extend `test-song-alt-titles.php`'s allowlist knowledge (the saveSong call site
   delegates to the core, so the INSERT-site count does NOT grow).

The iHymns interchange format does NOT carry alt titles (`songs.schema.json` has no key) — adding it
means touching the EXPORT side too; **file a follow-up issue** ("interchange round-trip for
alternativeTitles") rather than half-implementing (the :4804-4807 `translations` precedent).

**Effort C9:** Small — ~60 lines + fixture. Half a day. Genuinely optional: if wave budget runs out,
file it as its own issue and stop after C8.

---

## §3 Gap C — #1674: bulk-import dry-run for `import_file`

### Established facts

- **No dry-run exists anywhere** — no `dryRun`/`preview` in `api2.php`, `api.php`,
  `song_importers.php`, `import2.php` (re-verified). The stale "preview endpoint" comment the issue
  found now sits at `song_importers.php:789`.
- **The flow:** `import_file` (api2.php:4933-5076) → format resolution (:4963-5003) →
  `_bulkImport_dedupeMode($dedupe)` **request-scoped static flag** (:5006, defined :875-882 — the
  established precedent for exactly this kind of mode) → one `_bulkImport_process*()` per format
  (:5019-5033) → each wrapper calls `_bulkImport_upsertSongbook()` (:895-954) +
  `_bulkImport_saveSong()` (:434-780) and aggregates the summary → handler runs
  `ed2_runSongbookMaintenance` when `songs_created > 0` (:5051-5053) → `logActivity` (:5060) →
  respond (:5074-5075).
- **Why transaction-rollback fidelity is IMPOSSIBLE here** (the issue's honest difficulty, resolved):
  `_bulkImport_saveSong()` opens its OWN per-song transaction (:518); MySQL has no nested
  transactions — an inner `begin_transaction` implicitly commits any outer wrapper, so "wrap the
  import and roll back" cannot work without restructuring every saver. The workable seam that keeps
  ONE code path: run every real *decision* (existence pre-flight :494-501, title-dedupe :510-516 —
  the code that determines created/skipped) and suppress only the write block, via **one
  early-return at the transaction boundary**. Everything after :518 (INSERT, lyric write, credits,
  `ilidStampNewRow`, revision row, `pdRecomputeForSong`, `logActivity`) is skipped by position, not
  by scattered `if`s — no parallel simulate branch to drift.
- **ZIP dry-run is DEFERRED**: the async job spans requests, so the flag must persist on
  `tblBulkImportJobs`, which has **no spare params column** (schema.sql:2252-2281) — a migration
  (rule #19) for a secondary surface. File a follow-up issue (see §6); `import_zip` must **reject**
  a dry-run request rather than silently ignore it (rule #33: a param the destination doesn't read
  is either honoured or refused).

### C7 — the dry-run seam in `song_importers.php` + the `import_file` handler

*Files:* `includes/song_importers.php`, `manage/editor/api2.php`, `api-docs.yaml`.

1. **`_bulkImport_dryRun(?bool $set = null): bool`** — new static-flag function directly below
   `_bulkImport_dedupeMode()` (:875-882), same shape, same doc-block register. Setting it (either
   value) also resets the seen-set in (2) (expose via a by-reference static or a tiny
   `_bulkImport_dryRunSeenReset()` — implementer's choice, ONE mechanism).
2. **`_bulkImport_saveSong()`** — insert between the title-dedupe block (:510-516) and
   `$db->begin_transaction()` (:518):
   - if `_bulkImport_dryRun()`: consult a per-run static `$seen[SongId]` set — if seen, return
     `['skipped', null]` (in-file duplicate fidelity: a real run's second insert hits the existence
     check against the first's committed row; the dry run must mirror that); else record and return
     `['create', null]`.
   - Placement is load-bearing: BEFORE `begin_transaction()` (no dangling transaction), AFTER both
     pre-flights (identical created/skipped decisions). The mutation test in C8 proves both.
3. **`_bulkImport_upsertSongbook()`** — after the existence SELECT concludes `!$exists` (:905-907
   region), if `_bulkImport_dryRun()` return `'created'` without the INSERT/`ilidStampNewRow`
   (:937-952). The wrappers' per-created-book SongCount refreshes (e.g. :1677-1690, :2290-2300)
   need NO change: under dry-run those abbreviations don't exist, so each
   `UPDATE … WHERE Abbreviation = ?` matches 0 rows — a harmless no-op (existing books are never in
   the created-list by construction). Document this reasoning at the early-return.
4. **`import_file` handler** (api2.php:4933-5076):
   - parse `$dryRun = ((string)($_POST['dryRun'] ?? '0') === '1');` beside `dedupeMode` (:4935-4936);
   - **always** call `_bulkImport_dryRun($dryRun)` (explicit both ways, beside :5006 — never rely on
     a default);
   - gate the maintenance call: `if (!$dryRun && created > 0) ed2_runSongbookMaintenance(...)` (:5051-5053);
   - add `'dryRun' => $dryRun` to the `logActivity` detail (:5060-5065);
   - inject `$summary['dry_run'] = $dryRun;` before the respond merge (:5074-5075) — a **key**, not
     prose (rule #35), so the UI branches on it.
5. **`import_zip` handler** (:5086+): if `dryRun=1` posted → **422**
   `"Dry run is not yet supported for ZIP imports — import a single file to preview, or see #<followup>."`
   before any job/file work.
6. **Comment fixes:** rewrite :789 ("reused by the preview endpoint" → the dry-run mode + #1674);
   the `_bulkImport_saveSong` doc-block (:425-433) gains the dry-run contract, including the honest
   limitation: **dry-run `songs_failed` reflects parse/mapping failures only** — DB-level failures
   (duplicate-key races, FK surprises) are unreproducible without writing.
7. **OpenAPI:** `import_file` gains the `dryRun` form param + `dry_run` response field;
   `import_zip` documents the 422.

### C8 — import2 UI + tests

*Files:* `manage/editor/import2.php`, new `tests/php/test-import-dry-run.php`.

1. UI: a third checkbox `imp-dryrun` — label "Dry run (preview only — nothing is written)" — beside
   `imp-dedupe` (:115-120). `importSingle()` (:242-271) appends
   `fd.append('dryRun', dryRunEl.checked ? '1' : '0')`. The ZIP branch of the click handler (:274+):
   when dry-run is checked and the file is `.zip`, show the 422 message client-side without
   uploading (and the server 422 from C7.5 backstops it).
2. `renderSummary()` (:154-181): when `data.dry_run` — prepend a distinct `alert-info` "DRY RUN —
   nothing was written." and relabel the counts line to "would be created · already in DB (would
   skip) · failed to parse"; songbook line to "Songbooks that would be created: …". Branch ONLY on
   the `dry_run` key.
3. **`tests/php/test-import-dry-run.php`** (source-contract, mutation-proven — rule #34; wide regex
   windows per the #1671 lesson):
   - `_bulkImport_dryRun` exists adjacent-in-shape to `_bulkImport_dedupeMode`;
   - in `_bulkImport_saveSong`, the dry-run early-return appears textually AFTER the
     `_bulkImport_dedupeMode() === 'skip-title'` block and BEFORE `begin_transaction()` (positional
     assertion — this is the commit's highest-risk line, so the guard is positional, not existential);
   - `_bulkImport_upsertSongbook` early-returns before its INSERT;
   - the `import_file` case reads `dryRun`, calls `_bulkImport_dryRun(`, injects `dry_run`, and the
     maintenance call is `!$dryRun`-gated; the `import_zip` case contains the 422 refusal;
   - import2.php appends `dryRun` and branches `renderSummary` on `data.dry_run` (PHP↔JS lockstep,
     rule #35).
   Mutation-prove each clause (move the early-return above the dedupe block → red; drop the zip 422
   → red; restore).
- Stays green: `test-importer-rights-fields.php`, `test-ihymns-json-import.php`,
  `test-import-format-coverage.php`, all parser tests (`openlyrics`/`opensong`/`videopsalm`/
  `freeshow`/`chordpro`/`xml-import-routing`), `test-credit-registry-promote.php`,
  `test-editor2-metadata-1862.php`, `test-openapi-actions-exist.php`.
- Manual (alpha): dry-run an OpenSong file twice → identical "would create" counts, zero rows in
  `tblSongs`/`tblSongbooks`/`tblSongRevisions`/`tblMusicians`; uncheck → real import → counts match
  the dry run; dry-run the same file again → all "already in DB".

**Effort C7-C8:** Medium — ~120 lines across three files + one test. ~1 day.

---

## §4 Commit sequence (smallest / safest first)

| # | Commit | Gap | Files | Risk | Effort |
|---|---|---|---|---|---|
| C1 | `docs(#1904): close as done-by-#1736; fix stale rights-limitation comment` — rewrite song_importers.php:4757-4763 to state rights ARE persisted since 8807152d; close #1904 on GitHub with the §0 evidence | #1904 | song_importers.php (comment only) | none | XS |
| C2 | `fix(#1743): chain PreviousData from the prior revision's NewData in ed2_touchRevision` | A | api2.php | low | S |
| C3 | `fix(#1743): legacy restore unwraps v2 snapshots + honest shape 409` + `test-revision-previous-data.php` | A | api.php, tests | low | S |
| C4 | `feat(#1669): includes/song_alt_titles.php — the ONE alt-titles write core` | B | new include | none (dormant until C5) | S |
| C5 | `feat(#1669): song_alt_titles / _add / _delete api2 actions + v2 Metadata-tab panel` | B | api2.php, api-client.js, alt-titles-panel.js (new), metadata-tab.js, api-docs.yaml | low (additive endpoints) | M |
| C6 | `test(#1669): tree-derived alt-titles wiring guard + panel UI test` | B | 2 test files | none | S |
| C7 | `feat(#1674): dry-run seam — _bulkImport_dryRun flag, saveSong/upsertSongbook early-returns, import_file param, import_zip 422` | C | song_importers.php, api2.php, api-docs.yaml | **medium** (touches the live saver) | M |
| C8 | `feat(#1674): import2 dry-run checkbox + summary; test-import-dry-run.php` — close #1674 (note the chordpro side-item shipped in #1264), file the ZIP follow-up | C | import2.php, tests | low | S |
| C9 | *(optional)* `feat(#1669): OpenLyrics multi-title import via the shared core` — then close #1669 | B | song_importers.php, tests | low | S |
| C10 | `docs: wave close-out` — CHANGELOG, wiki editor/import pages, `.claude/ProjectBrief.md`, session handoff, issue comments with SHAs | — | docs | none | XS |

Ordering rationale: A first (two files, smallest blast radius); B's additive endpoints before C
because new endpoints cannot regress existing behaviour while C modifies `_bulkImport_saveSong()`,
the saver every import funnels through; ALL `song_importers.php`-touching commits (C1 comment, C7,
C9) sit so the file is edited late and only after Wave 1's edit to the same file has landed.
Each commit: descriptive summary + WHY body, atomic, individually revertable.

---

## §5 Adversarial review — what could regress, and the counter in the plan

1. **C7's early-return placement is the single most dangerous line of the wave.** One position too
   early (before the existence check) and dry-run counts diverge from reality; worse, if a future
   refactor hoists it above the pre-flights, REAL imports still work but previews lie. One position
   too late (after `begin_transaction`) leaks an open transaction per song. Counter: the positional
   assertion in `test-import-dry-run.php` (C8.3), mutation-proven in both directions.
2. **A dry run that writes ANYTHING destroys the feature's trust.** Audited write sites inside the
   suppressed region: tblSongs INSERT, lyric write (both branches), credit tables +
   `musicianPromote`, `ilidStampNewRow`, revision row, `logActivity('song.create')`,
   `workAutolinkSafe`, `pdRecomputeForSong` — all after :518, all skipped by position. Outside it:
   songbook INSERT + `ilidStampNewRow` (skipped by C7.3), wrapper SongCount refreshes (0-row no-ops,
   §3 C7.3), handler maintenance (gated C7.4). The handler's own `logActivity('song.import_file')`
   deliberately STAYS (an audit row about a preview is correct) with `dryRun` in its detail.
3. **In-file duplicates** would count as two creates without the seen-set (a real run's second
   insert is skipped by the first's committed row). Counter: C7.2's per-run seen-set, reset on flag
   set. Residual honesty gap: dry-run cannot see DB-level failures — documented in the doc-block,
   the OpenAPI text, and implicitly in the UI's "failed to parse" relabel.
4. **#1743 chaining changes what the legacy restore DOES** — from a guaranteed 409 to an actual
   write. If the C3 unwrap/guard were skipped, a chained v2 snapshot would make the legacy restore
   **silently no-op with a success response** (worse than the 409). Counter: C2 and C3 land in
   adjacent commits on one branch, and the C3 test asserts the guard exists — do not reorder C2
   after C3's test is the safe direction if paranoid (test would fail red until C2, acceptable
   within one push).
5. **#1743 storage growth:** each v2 revision now carries a second full snapshot (~2× row size,
   bounded by the 15 s coalesce). `tblSongRevisions` has no pruning today (pre-existing). Accepted;
   noted for the future v1-retirement pass.
6. **#1669 wakes dormant read code for the first time** — the #832 search boost (SongData:3771+) and
   the merge re-point path will process real rows. The boost is shipped, deliberate behaviour; the
   merge idiom (`UPDATE IGNORE` + DELETE-leftover) is collision-safe by construction. The panel's
   409 on an un-migrated install is handled UI (C5.3) — never a white screen (mysqli STRICT: every
   table access sits behind the probe).
7. **Rule #25 audit:** nothing in this wave reads or writes lyric lines, `LinesJson`, or components
   outside pre-existing code paths. `test-component-json-guard.php` must stay green after every commit.
8. **Cross-wave file contention:** `song_importers.php` is edited by Wave 1 (#1908, :267 region) and
   Wave 2 (C1/C7/C9). Disjoint regions; sequence W1 → W2 (header note). If Wave 1 also lands its
   "shared to-UTF-8 sniff helper" near the file top, re-anchor C7's function placement by function
   name, never by line number.
9. **New guards must not be wrong-but-green** (rule #34): every new test in C3/C6/C8 ships only
   after a demonstrated red (break → red → restore), with ≥300-char regex windows and tree-derived
   lists (the C6 INSERT-site scan derives from the tree with a count-exact allowlist of one).

---

## §6 GitHub / docs close-out obligations (standing-tasks §2a — do not skip)

- **#1904**: close as completed-elsewhere at C1, citing #1736, `0b7d8ecf`, `song_importers.php:676-737`.
- **#1743**: comment the chosen design (chain + shape-guard; option 2 remains open for the v1
  retirement) at C2; close at C3 with SHAs against both acceptance boxes.
- **#1669**: comment at C5 (write path live; importer pending); close at C9 — or, if C9 is cut,
  close with a NEW follow-up issue "OpenLyrics multi-title import for alt titles" so the remaining
  sliver is tracked, plus the "interchange round-trip for alternativeTitles" idea from §2 C9.
- **#1674**: close at C8 (note the chordpro side-item shipped separately as #1264; note the stale
  comment fixed) and **file the follow-up**: "Dry-run for import_zip (async) — needs a DryRun flag
  on tblBulkImportJobs (migration + registry card, rule #19) read by the worker before
  `_bulkImport_processZip()`", referencing this plan.
- **Docs:** CHANGELOG entries per feature; wiki editor/import page (dry-run + alt-titles panel);
  `api-docs.yaml` already updated in C5/C7; `.claude/ProjectBrief.md` + session handoff at C10.

## §7 Definition of done

- [ ] C1–C8 pushed (C9 optional, C10 always); each commit atomic with WHY-bodies.
- [ ] `php -l` clean on all touched PHP; `node --check` clean on all touched JS.
- [ ] New tests exist, each demonstrated red-then-green (mutation-proven): 
      `test-revision-previous-data.php`, `test-song-alt-titles.php`, `test-v2-alt-titles-ui.js`,
      `test-import-dry-run.php` (+ OpenLyrics fixture if C9).
- [ ] Stays green: `test-editor-api2-contract.php`, `test-credit-registry-promote.php`,
      `test-editor2-metadata-1862.php`, `test-importer-rights-fields.php`,
      `test-ihymns-json-import.php`, `test-import-format-coverage.php`, all importer parser tests,
      `test-component-json-guard.php`, `test-openapi-actions-exist.php`, `test-transaction-fatal.php`,
      `test-duplicate-copy-set.php`, `test-schema-coverage.php` (no schema changes anywhere in the wave).
- [ ] Manual alpha passes from §1/§2/§3 executed and recorded in the issue comments.
- [ ] A v2-created revision restores through BOTH editors; no non-create edit produces the
      "initial create" 409 (the #1743 acceptance, verbatim).
- [ ] An alt title added in Editor2 renders on the public song page and top-ranks in search.
- [ ] A dry-run import writes zero rows (spot-check the four tables in §3's manual pass) and a
      subsequent real import matches its counts.
- [ ] All four issues updated/closed per §6; follow-up issues filed; docs updated.

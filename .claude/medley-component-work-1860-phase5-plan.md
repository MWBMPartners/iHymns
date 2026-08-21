# Medley / Per-Section Work / Component Label — #1860 Phase 5 Implementation Spec

**Status: LOCKED SPEC (sequential planning pass, 2026-08-21) — execute verbatim, commit by commit, on branch `claude/ilyrics-identity-work-model`.**
Validates + tightens the parallel design analysis (task `wf7xld01j`); every file:line below was re-verified against the working tree at commit `e589d250`. Companion docs: `.claude/ilyrics-internal-ids-work-model-plan.md` (the #1860 design — §3.6, §3.6b, §3.7, §5.5, §5.7), `work-model-spec.md` (Phase 3, already shipped). This document is the ONLY spec the implementer needs; do not re-derive.

---

## §0. Built vs gap (one paragraph each)

- **REQ 3a — per-section Language: ALREADY LIVE. ZERO work.** `structure-tab.js:245-258` (input) → `component_upsert` → `tblSongComponents.Language` → `lyric_lines_read.php` `'language'` → `song.php` lang attr + badge (#858/#1206). Confirmed end-to-end; nothing in this epic touches it except sitting new controls beside it.
- **REQ 2 — `tblSongComponents.SourceWorkId`: schema DORMANT, zero readers/writers.** Column + `idx_SourceWork` + `fk_Component_SourceWork` landed (`schema.sql:732/:736/:3927-3935`, `migrate-work-identity-model.php:204-240`, registry probe `migration-registry.php:4262-4291`). Needs the read-shape SELECT, the write-funnel threading, the Structure-tab picker, and the §3.6b.2 lockstep.
- **REQ 1 — `tblWorkComponents` medley: schema DORMANT, helpers an explicit stub.** Table at `schema.sql:3277-3299`; stub comment `includes/work_admin.php:1082-1086` ("ship in Phase 5, WITH their `/manage/works` consumer"). Phase-3 spine already shipped: `workAdminReady():138`, `workExists():576`, `workSnapshot():598`, `workFindOrCreateByTitle():635`, `workFindOrLinkByIdentifier():914`, api2 `song_work_autolink:2590` / `song_work_set:2655` / `work_search:3426`.
- **REQ 3b — `tblSongComponents.Label`: GENUINELY NEW end-to-end.** No Label/Caption/CustomName column exists (grep-confirmed); needs one additive column + registry entry, both read shapes, all five write funnels, the Structure-tab input, and the rule-#33 display sweep. Display name is independently re-derived in ~8 client/server places (§6, Commit 8) — the sweep is the point.

## §0.1 Locked decisions (settled — encode, do not re-open)

| # | Decision |
|---|---|
| **D1** | A set Label **REPLACES** the derived "Verse 1" heading. Store **NULL when the typed Label equals the derived name** (rule #27 hide-when-equal, enforced **server-side** in `component_upsert` so every funnel honours it). `Type` stays authoritative for CSS classes, arrangement resolution, chorus-repeat, and every machine-export keyword. |
| **D2** | Language hint is **OPT-IN**: a "Use language name" button fills the Label input from the section's existing `Language` (client-side autonym via `Intl.DisplayNames`, §5.4). NO automatic render-time substitution — an unset song stays byte-identical. |
| **D3** | Medley composition editor lives on **`/manage/works`** (gate `manage_works`, `works.php:63`). Editor2 shows a **read-only** "Medley of: A, B, C". |
| **D4** | REQ 2 write path = **Option A**: thread `SourceWorkId` through the shared thin-row writer `lyricLinesUpsertComponents()` exactly like `Language`. Rule-#25-safe: the thin-row writer persists component **metadata** (Type/Number/SortOrder/Language, `lyric_lines_sync.php:558-650`) and never touches lyric-LINE content. The read-side change in `lyricLinesEditableComponents()` is mandatory regardless. |
| **D5** | **ONE epic, ~10 commits, one PR** on `claude/ilyrics-identity-work-model`. Commit 9 (Editor2 Part-of-work) is the one separable tail. |
| **D6** | Editor2 "Medley of" is fed by **extending `ed2_buildSongSnapshot()`** (`api2.php:1309`) to attach `works` (+ constituents) to `load_song` — a lean query, not a second fetch. |

**Sub-decisions locked by this pass** (defensible defaults, each trivially changeable, none blocking):

- **SD1** — an unknown/invalid `sourceWorkId` is **coerced to NULL, never a 422**: the save must not fail on a work-link problem (#1860 §3.4's non-blocking posture). The response carries `sourceWorkIdIgnored: true` when coerced so the client can toast.
- **SD2** — the §3.6b.2 lockstep's operational definition of "medley-shaped": for **every** work `W` in the song's `tblWorkSongs` memberships with `W.Id !== sourceWorkId`, additively upsert `(W.Id, sourceWorkId)`. A section sourcing a *different* work than its song's own membership IS the stitching evidence. `SortOrder` on a lockstep INSERT seeds from the section's `sortOrder`; an existing row is **never** updated by the lockstep (`ON DUPLICATE KEY UPDATE MedleyWorkId = MedleyWorkId`), and clearing a section link **never** deletes (§3.3.1 never-auto-unlink).
- **SD3** — the **public/export shape** emits `label` **SPARSELY** (key present only when non-null), mirroring the sparse `lineLanguages` rule (`lyric_lines_read.php:135-137`). The **editor shape** emits `label` + `sourceWorkId` **always** (null default). Rationale: `tests/php/test-lyric-lines-read.php` compares full expected arrays with strict `===` (`:22-30`, fixtures `:54+`) — sparse emit keeps every existing fixture AND the 16,083-song byte-parity claim true for the entire un-labelled corpus.
- **SD4** — the **legacy (no-mirror) fallback branches read AND write both new columns, column-gated** (`ed2_currentComponents` fallback, `ed2_persistComponents` legacy INSERT, `save_song_core` legacy branch, `SongData::_getComponentsFromJson`). A partial "Apply all" can leave `Label` present with no mirror; without this, the Label input would be a rule-#30-class silent no-op on that install.
- **SD5** — v1 editor's `componentHeaderLabel()` (`editor.js:1660`) **is included** in the sweep (one-line change; "every display render site" means every one).
- **SD6** — restoring an **OLD** (pre-Label) revision **preserves** the current Label/SourceWorkId (absent-key semantics, matching the identity-column skip posture `api2.php:1469-1479`). A NEW snapshot always carries the keys, so a restore of a new revision restores the values exactly.
- **SD7** — **v1 exports carry NO Label anywhere** — including the display-only ChordPro `{comment}` / FreeShow `group` slots the analysis marked optional. This makes the CI guard maximally simple (zero `.label` in both exporter files) and is trivially relaxable later.
- **SD8** — the works.php constituents picker uses a **LOCAL `?action=work_search`** GET handler (mirrors `song_search`, `works.php:380-430`) with a server-side `exclude` param (never offer a work as its own constituent).
- **SD9** — Editor2's "Medley of" links point to the public **`/work/<slug>`** and, for management, **plain `/manage/works`** — `works.php` reads NO `?id=`/`?edit=` GET param (verified: only `action`/`q`/`limit`), so per rule #33 no such param may be emitted.
- **SD10** — the "Use language name" autonym comes from the browser-native `Intl.DisplayNames` (no dependency, no server round-trip); fallback = the raw tag.

## §0.2 Load-bearing rules (the ones this epic can violate — re-read before each commit)

**#19** schema.sql byte-identical mirror + ONE registry entry + real probe, same commit · **#20** VARCHAR-not-ENUM, one-pass forward-looking DDL · **#22** ONE shared core (medley helpers in `work_admin.php`; both consumers delegate) · **#25** `tblLyricLines` ONE read/write path — Label/SourceWorkId are THIN-ROW metadata, never line content; no new `LinesJson` reads outside gates (`test-component-json-guard.php` enforces) · **#27** hide-when-equal · **#29** state-changing AJAX under `validateCsrfRequest()` / api2's X-Requested-With gate (already in place for both funnels) · **#33** every render site honours the new field or the feature is a silent partial; never emit an unhonoured URL param · **#34** the new guard is tree-derived AND mutation-proven · **#35** cross-file agreement by mechanism; client branches on HTTP status; read back what the server stored · **#41** the new migration is a pure ALTER needing no shared include (IHYMNS_INCLUDES_DIR N/A — state this in its doc-block) · **#43** the Source-work control is the shared find-or-create picker, commit-on-change, never a debounced keystroke · **#44** no vanity fields — Label collects exactly one thing the app renders.

---

## §1. The one new DDL (REQ 3b) — Commit 1 payload

### §1.1 Column (byte-identical in migration AND schema.sql, incl. COMMENT — rule #19)

```sql
ALTER TABLE tblSongComponents
    ADD COLUMN Label VARCHAR(100) NULL DEFAULT NULL COMMENT 'Optional custom DISPLAY name for this section, overriding the derived "Type Number" heading (e.g. a Zulu verse shown as "isiZulu", a "Kyrie"). DISPLAY-ONLY: Type stays authoritative for CSS highlighting, arrangement resolution, chorus-repeat and machine-export section keywords. NULL = derive from Type+Number (stored NULL when a typed label equals the derived name, rule #27). Rendered inside the section''s own lang/dir context (#858). Per-section override sibling of Language (#858) and SourceWorkId (#1860 §3.6b). DORMANT until the #1860 Phase-5 wiring reads it';
```

- **`VARCHAR(100)`, not ENUM** (rule #20 — unbounded free text). 100 code points is the forward-looking width; the rule-#20 stress found nothing that forces a second migration (label-language, per-format captions, searchability are all deferred ADDITIVE refinements).
- **schema.sql mirror location**: inside the `tblSongComponents` CREATE block, **immediately after the `SourceWorkId` line** (`appWeb/.sql/schema.sql:732`) and before the blank line at `:733`. No index (never filtered on), no FK.

### §1.2 Migration — NEW `appWeb/.sql/migrate-add-component-label.php`

Model it on `migrate-work-identity-model.php`'s Object-C branch (`:204-216`):

- Doc-block: purpose, `@migration-adds tblSongComponents.Label` doctag, and an explicit note that this pure ALTER **requires no shared include, so rule #41's `IHYMNS_INCLUDES_DIR` resolution is N/A** (say so, so a future editor doesn't "fix" it).
- Idempotency: `if (colExists('tblSongComponents','Label')) { [SKIP]; } else { ALTER … ; [OK]; }` — copy `_migWorkIdent_colExists()`'s INFORMATION_SCHEMA probe shape into a local `_migCompLabel_colExists()`.
- The ALTER DDL string is **byte-identical** to §1.1 (incl. COMMENT).

### §1.3 Registry — ONE entry in `manage/includes/migration-registry.php`

Append after the latest entry (house convention — chronological; this pure ALTER has no ordering dependency):

```php
'component-label' => [
    'script' => 'migrate-add-component-label.php',
    'card' => [
        'title'  => 'Custom component labels (#1860 Phase 5)',
        'body'   => 'Adds <code>tblSongComponents.Label</code> — an optional custom DISPLAY'
                  . ' name for one section ("Kyrie", "isiZulu"), overriding the derived'
                  . ' "Verse 1" heading. Display-only: <code>Type</code> stays authoritative'
                  . ' for styling, arrangement and machine exports. Additive, idempotent,'
                  . ' dormant until a curator sets a label. Safe to re-run.',
        'button' => 'Run Component Label Migration',
    ],
    /* Single-object probe (rule #19) — never `=> true`. */
    'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongComponents', 'Label'),
],
```

**Commit 1 verification**: `php -l` the migration; `php tests/php/test-schema-coverage.php`; `php tests/php/test-migration-registry.php`; `php tests/php/test-component-json-guard.php` (must stay green — the migration mentions no doomed column).

---

## §2. Commit 2 — read seam: both shapes carry `label` + `sourceWorkId`

**Files**: `includes/lyric_lines_read.php`, `manage/editor/api2.php` (`ed2_currentComponents` fallback), `includes/SongData.php` (`_getComponentsFromJson`/`…MapFromJson`, SD4), `tests/php/test-lyric-lines-read.php`.

### §2.1 ONE shared column probe — `lyricLinesComponentExtrasPresent()`

New memoised function in **`lyric_lines_read.php`** (beside `lyricLinesMirrorPresent():62`):

```php
/** @return array{Label:bool,SourceWorkId:bool} */
function lyricLinesComponentExtrasPresent(\mysqli $db): array
```

INFORMATION_SCHEMA `COLUMN_NAME IN ('Label','SourceWorkId')` on `tblSongComponents`; catch posture: `if (function_exists('songRelocateIsTransactionFatal') && songRelocateIsTransactionFatal($e)) { throw $e; }` then default both false (it IS called inside write transactions from `lyric_lines_sync.php` — the #1688 A1 deadlock lesson; the `function_exists` guard covers the public read path where `song_relocate.php` isn't loaded). This is the ONE probe both the read AND write modules use (rule #35 — no second copy; `lyric_lines_sync.php` gets it via a `require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';` inside `lyricLinesUpsertComponents()`, mirroring the in-function `line_enrichment.php` require at `:509`).

### §2.2 Public/export shape (feeds song page, `song_detail`, `songbook_export`, native apps)

- `lyricLinesFetchPrimary()` (`:197-221`) and `lyricLinesFetchPrimaryMap()` (`:230-268`): when `$extras['Label']`, add `sc.Label AS comp_label` to the SELECT (build the column list conditionally — the LEFT-JOINed `sc.Label` would throw under STRICT on an un-migrated install). `SourceWorkId` is deliberately NOT added to the public shape (it is provenance metadata for the editor/rights path, not a public render field — nothing public consumes it in this epic).
- `lyricLinesAssembleFromRows()` (PURE, `:108-187`): in the `$cur` build (`:162-170`) add `'label' => (isset($row['comp_label']) && $row['comp_label'] !== null && $row['comp_label'] !== '') ? (string)$row['comp_label'] : null`; in the `$flush` output (`:123-129`), **sparse emit** (SD3): `if ($c['label'] !== null) { $out['label'] = $c['label']; }` placed after `'language'`.
- **Doc-block revision** (`:25-28`): the "BYTE-IDENTICAL CONTRACT" paragraph gains one sentence: *"Since #1860 Phase 5 the shape additionally carries an OPTIONAL sparse `label` key (present only when `tblSongComponents.Label` is set) — absent on every un-labelled component, so the pre-Phase-5 byte parity holds verbatim for the whole un-labelled corpus."*

### §2.3 Editor shape — `lyricLinesEditableComponents()` (`:464-540`)

- SELECT (`:467-470`): conditionally append `, Label` / `, SourceWorkId` from the probe.
- Output (`:522-537`): always-present keys (SD3):
  ```php
  'label'        => ($extras['Label'] && ($c['Label'] ?? null) !== null && $c['Label'] !== '') ? (string)$c['Label'] : null,
  'sourceWorkId' => ($extras['SourceWorkId'] && ($c['SourceWorkId'] ?? null) !== null) ? (int)$c['SourceWorkId'] : null,
  ```
- Doc-block `:448-462`: add the two keys to the documented shape + `@return` annotation.

### §2.4 The two fallback readers (SD4)

- `ed2_currentComponents()` un-migrated branch (`api2.php:1238-1256`): extend the SELECT with the gated columns (mirror the `$langCol` conditional at `:1239`) and emit both keys (null when column absent).
- `SongData::_getComponentsFromJson()` / `…MapFromJson()`: gated `Label` SELECT + the same sparse `label` emit as §2.2, so an install with the Label column but no mirror still renders stored labels. (Locate via the pointer in `SongData.php:80-81`.)

### §2.5 Fixtures

Extend `tests/php/test-lyric-lines-read.php`: (a) all EXISTING fixtures unchanged (sparse emit — this is itself the proof of SD3); (b) NEW case: rows carrying `comp_label` → assembled component includes `'label' => 'Kyrie'`; (c) NEW case: `comp_label` null/'' → NO `label` key (strict `===` on the full array proves sparseness); (d) extend the `:197-212` source-scan to assert `editableFn` contains `'label'` and `'sourceWorkId'`.

**Verification**: `php -l` both PHP files; `php tests/php/test-lyric-lines-read.php`; `php tests/php/test-component-json-guard.php` (the fallback edits touch `LinesJson`-adjacent code — they are inside the existing gated branches, so it must stay green); `php tests/php/test-editor-api2-contract.php`.

---

## §3. Commit 3 — write seam: five funnels + the thin-row writer, silent-wipe-proof

**Files**: `includes/lyric_lines_sync.php`, `manage/editor/api2.php` (`component_upsert`, `components_replace`, `ed2_persistComponents` legacy), `manage/editor/save_song_core.php`.

The preserve model is **three independent layers** (each covers funnels the others miss — see §8.3):

### §3.1 Normaliser + writer (`lyric_lines_sync.php`)

`lyricLinesWriteComponents()` normalise loop (`:514-532`) — add to `$norm[]`:

```php
'label'                => (isset($c['label']) && trim((string)$c['label']) !== '')
    ? (function_exists('mb_substr') ? mb_substr(trim((string)$c['label']), 0, 100)
                                    : substr(trim((string)$c['label']), 0, 100))
    : null,
'labelProvided'        => array_key_exists('label', $c),
'sourceWorkId'         => (isset($c['sourceWorkId']) && (int)$c['sourceWorkId'] > 0) ? (int)$c['sourceWorkId'] : null,
'sourceWorkIdProvided' => array_key_exists('sourceWorkId', $c),
```

`lyricLinesUpsertComponents()` (`:558-650`):

1. Top of function: `require_once` `lyric_lines_read.php` (for the §2.1 probe); `$extras = lyricLinesComponentExtrasPresent($db);`.
2. Existing-rows SELECT (`:605-609`): extend to `SELECT Id` + gated `, Label` / `, SourceWorkId`, fetch assoc, keep `$existingIds` (Ids) plus `$existingExtras[$i]` (per-position Label/SourceWorkId).
3. Column sets: `$extraCols` = gated `['Label']` + `['SourceWorkId']`; `$extraTypes` = `'s'` + `'i'` correspondingly. `$insCols` (`:612`) becomes `array_merge([...base...], $shadowCols, $extraCols)`; `$insTypes` (`:614`) = `'ssiis' . str_repeat('s', count($shadowCols)) . $extraTypes`. `$updSet` (`:617-618`) appends `", {$c} = ?"` per extra col AFTER the shadows; `$updTypes` (`:619`) = `'siis' . str_repeat('s', count($shadowCols)) . $extraTypes . 'i'`.
4. Per-component values (`:623-636`): **provided-else-preserve** —
   - UPDATE branch: `$labelVal = !empty($c['labelProvided']) ? $c['label'] : ($existingExtras[$i]['Label'] ?? null);` (same for SourceWorkId, cast int-or-null). Append in `$extraCols` order between `$shadow` and `[$compId]`.
   - INSERT branch: provided → value, absent → NULL.
5. Doc-blocks (`:544-556` and `:484-501`): document the two extra thin-metadata columns, the provided-flag preserve, and that this remains component METADATA — rule #25's line path untouched.

*Why writer-level preserve at all, given the handler layers below*: it is the only layer that protects funnels calling `lyricLinesWriteComponents()` with key-less components against rows that HAVE values — a stale-cached v1 editor whole-song save, a `lyrics_ingest` re-ingest over an existing song, an OLD revision restore (SD6). Preserve is per-POSITION (the writer's whole matching model); a funnel that both omits the keys AND reorders can mis-attach — exactly the residual risk chords carry-forward already accepts, and the funnels that reorder (the editors) always send the keys after this epic.

### §3.2 `component_upsert` (`api2.php:2731-2794`)

- Accept, after `:2742`:
  ```php
  $hasLabel   = array_key_exists('label', $comp);
  $labelIn    = $hasLabel ? mb_substr(trim((string)($comp['label'] ?? '')), 0, 100) : '';
  /* D1 / rule #27 — server-side hide-when-equal: fold a label equal to the
     derived display name back to NULL so no funnel can store the redundancy.
     Compare against the ALIASED display derivation (refrain renders "Chorus"),
     case-insensitively. */
  $derived = ucfirst($type === 'refrain' ? 'chorus' : $type) . ($number > 0 ? ' ' . $number : '');
  $label   = ($labelIn !== '' && mb_strtolower($labelIn) !== mb_strtolower($derived)) ? $labelIn : null;
  $hasSrcWork    = array_key_exists('sourceWorkId', $comp);
  $srcWorkIn     = $hasSrcWork ? (int)($comp['sourceWorkId'] ?? 0) : 0;
  $sourceWorkId  = $srcWorkIn > 0 ? $srcWorkIn : null;
  $srcWorkIgnored = false;
  if ($sourceWorkId !== null && function_exists('workExists') && workAdminReady($db) && !workExists($db, $sourceWorkId)) {
      $sourceWorkId = null; $srcWorkIgnored = true;   /* SD1 — coerce, never fail the save */
  }
  ```
  (`work_admin.php` is already loaded by api2 for the work endpoints; guard with `function_exists` anyway so the accept path never fatals if the include set changes.)
- `$entry` (`:2751-2760`): add `'label' => $label` / `'sourceWorkId' => $sourceWorkId` **only when** `$hasLabel` / `$hasSrcWork` (key-present = intent; absent keys let §3.1 preserve).
- Target-preserve block (`:2764-2765`), extend:
  ```php
  if (!$hasLabel)   { $entry['label']        = $c['label']        ?? null; }
  if (!$hasSrcWork) { $entry['sourceWorkId'] = $c['sourceWorkId'] ?? null; }
  ```
- Response (`:2792`): rule-#35 read-back from the already-fetched `$after` (`:2783`) — `'label' => $after[$targetPos]['label'] ?? null, 'sourceWorkId' => $after[$targetPos]['sourceWorkId'] ?? null, 'sourceWorkIdIgnored' => $srcWorkIgnored`.
- Header contract doc (`api2.php:71` area): update the `component_upsert` shape line.

### §3.3 `components_replace` (`api2.php:4564-4633`)

Paste & Reflow rebuilds STRUCTURE — incoming rows never carry the new keys, so without carry every reflow wipes every label. Extend the existing FIFO carry (the exact chords/languages mechanism):

- Carry build (`:4580-4586`): `$carry[$ck][] = ['c' => $pc['chords'], 'l' => $pc['languages'], 'lb' => $pc['label'] ?? null, 'sw' => $pc['sourceWorkId'] ?? null];`
- Normalise loop (`:4589-4614`): explicit-wins-else-carried for both keys (mirror the `$chords` shape at `:4600-4602`); add `'label'` / `'sourceWorkId'` to the `$incoming[]` entry (`:4606-4613`).

### §3.4 `save_song_core.php` — the PF1 carry + payload rebuild

The whole-song save REBUILDS a fixed component shape (`:971-978`) — the new keys must be threaded or every legacy-path save wipes them:

- Carry maps (`:754-755`): add `$carryLabels = []; $carrySourceWorks = [];`. Mirrored source loop (`:781-785`): also push `$pc['label'] ?? null` / `$pc['sourceWorkId'] ?? null` under the same `type\x1fnumber\x1flineCount` key. Legacy snapshot branch (`:804-820`): add the two columns to `$snapCols` gated on `lyricLinesComponentExtrasPresent()` (require `lyric_lines_read.php` is already loaded on the mirrored branch `:780`; on the legacy branch add the require) and push their raw values.
- `$writeComps[]` build (`:944-979`): explicit-vs-carry for both keys (the `array_key_exists('chords', …)` shape at `:958-963`), then add `'label'` / `'sourceWorkId'` to the entry at `:971-978`.
- Legacy write branch (`:987+`): add the two columns to the re-INSERT, gated exactly like the `$hasComponentLanguage` probe pattern at `:995` (SD4).

### §3.5 `ed2_persistComponents()` legacy branch (`api2.php:1284-1295`)

Gated add of `Label` + `SourceWorkId` to the legacy INSERT column list + binds (SD4).

### §3.6 Funnels needing NO change (verify, don't touch)

- `song_importers.php` / `lyrics_ingest.php`: build components without the keys → normaliser marks not-provided → INSERT NULL on new rows, writer-preserve on re-ingested rows. Correct by construction.
- Revision restore `ed2_applySongSnapshot()` (`:1438`, components at `:1544-1547`): passes snapshot components straight to `ed2_persistComponents` — new snapshots carry the keys (post-§2.3), old ones preserve via §3.1 (SD6). The snapshot's future `works` block (Commit 9) is ignored by restore — it only reads its known keys.

**Verification**: `php -l` all four files; `php tests/php/test-lyric-lines-read.php`; `php tests/php/test-lyric-lines-diff.php`; `php tests/php/test-component-json-guard.php`; `php tests/php/test-editor-api2-contract.php`; `php tests/php/test-song-relocate-funnels.php` (funnel-coverage guard). Manual: save a component with `label`, re-save WITHOUT the key (curl), confirm the label survives; reflow-replace, confirm carry.

---

## §4. Commit 4 — Editor2 Structure-tab controls (REQ 2 + REQ 3b UI)

**Files**: `manage/editor/v2/structure-tab.js` (all changes local to it).

### §4.1 `saveComponent()` payload (`:162-189`)

Add beside `language` (`:171`): `label: comp.label || null,` and `sourceWorkId: comp.sourceWorkId || null,`. The keys are ALWAYS sent (like `language`) — the client's own state is authoritative for its own saves; §3's preserve layers protect everyone else. After the await (`:183`), adopt the server's stored values (rule #35 read-back): `if (Object.prototype.hasOwnProperty.call(res, 'label')) { comp.label = res.label; }` (covers the D1 server-side NULL fold), and `if (res.sourceWorkIdIgnored) { toast('Source work not found — cleared.', 'warning'); comp.sourceWorkId = null; }`.

### §4.2 Label input + "Use language name" button (in `buildCard()`, header `:197-269`)

- Effective header name: change the three derivations (`:202`, `:223`, `:236`) to `const derived = …existing expr…; label.textContent = (comp.label && comp.label.trim()) ? comp.label.trim() : derived;` — extract a local `headerText(comp)` helper so it lives once in this module.
- New `<input type="text">` `labelInput` beside `langInput`: `placeholder` = the LIVE derived name (recomputed on type/number change — the store-NULL-when-equal affordance: an empty box always shows what would render); `aria-label="Custom section label (display only)"`; `title` explaining D1 ("Replaces the derived heading for display. Styling and exports still follow the section type."); `maxlength="100"`; value `comp.label || ''`; on `'change'` (commit, not keystroke): `comp.label = labelInput.value.trim() || null; headerText refresh; saveComponent(comp);`.
- Button `Use language name` (icon button, `aria-label="Use language name as label"`, disabled when `!comp.language`): fills `labelInput.value = languageAutonym(comp.language)`, sets `comp.label`, refreshes header, `saveComponent(comp)`. Local helper (SD10; extract to `js/utils/` only when a second consumer appears — rule #22 posture):
  ```js
  function languageAutonym(tag) {
      try {
          const base = String(tag).split('-')[0];
          return new Intl.DisplayNames([tag], { type: 'language' }).of(base) || String(tag);
      } catch (_e) { return String(tag); }
  }
  ```
- Append both into `header.append(…)` (`:269`) between `langInput` and `btns`.

### §4.3 Per-section "Source work" picker (rule #43)

- The exact Copyright-Holder shape (`metadata-tab.js:909-923`): a small text input `workInput` + `window.iHymnsPlaceSearch.attach(workInput, { minChars: 2, pickMode: 'value', noun: { singular: 'work', plural: 'works' }, searchUrl: (q) => '/manage/editor/api2?action=work_search&q=' + encodeURIComponent(q) + '&limit=10', parseResults: (d) => (d.suggestions || []).map((s) => ({ id: s.id, display_name: s.title, hint: s.iswc || s.ccli || '' })), onSelect: (c) => { comp.sourceWorkId = c.id; workInput.value = c.display_name; saveComponent(comp); } })` — `work_search`'s response shape verified at `api2.php:3464-3476`. Guard the attach with the same `window.iHymnsPlaceSearch &&` feature test.
- Free-typing/clearing: an `'input'` listener clears the picked id state; on `'change'` with an empty value → `comp.sourceWorkId = null; saveComponent(comp);` (commit event — never a mint on keystroke, #1679). A typed-but-unpicked name is NOT resolved to a work (no find-or-create for provenance v1 — a wrong auto-mint here would pollute the works registry; the picker only links EXISTING works, which `work_search` + the works.php CRUD supply).
- **Progressive disclosure** (§3.7.4): the picker sits in a second, collapsed header row (a small "Source work" disclosure toggle beside the language input) so a non-medley section's card looks unchanged; auto-expanded when `comp.sourceWorkId` is set. Initial display: when set, resolve the title lazily — send NO extra request at mount; show `Work #<id>` until the curator opens the control, then a one-shot `work_search`-by-title is NOT possible by id, so instead: Commit 9's snapshot `works` block includes constituents' titles; simpler and sufficient v1: store the picked `display_name` into `comp._sourceWorkTitle` at pick time and show `Work #<id>` after a reload. Flag as acceptable v1 roughness (the picker is a curator tool).
- **Teardown**: capture each card's detach fn (the attach's return value, `metadata-tab.js:910` precedent) in a module-level array; call all of them in the returned `teardown()` (`:413`).

**Verification**: `node --check manage/editor/v2/structure-tab.js`; `node tests/test-place-search-keyboard.js`; `node tests/test-tune-typeahead-ui.js` (nearest picker-contract guards); `node tests/test-editor-deep-links.js`.

---

## §5. Commit 5 — medley write core + the §3.6b.2 lockstep (REQ 2 server side)

**Files**: `includes/work_admin.php` (fills the `:1082-1086` stub), `manage/editor/api2.php` (`component_upsert` tail).

### §5.1 The ONE shared medley core (rule #22 — consumed by BOTH the lockstep and works.php)

New functions in `work_admin.php`, placed at the stub, all framework-free + caller's-transaction (the file's established contract):

```php
function workMedleyReady(\mysqli $db): bool
    // memoised tableExists('tblWorkComponents'); catch posture = workAdminReady()'s.
function workMedleyConstituents(\mysqli $db, int $medleyWorkId): array
    // [{workId,title,slug,sortOrder,note}] — JOIN tblWorks, ORDER BY SortOrder, Title. [] when !ready.
function workMedleyConstituentsMap(\mysqli $db, array $medleyWorkIds): array
    // bulk variant for _worksMap / the snapshot (one IN() query), medleyWorkId => rows.
function workMedleyWouldCycle(\mysqli $db, int $medleyId, int $componentId, int $maxDepth = 8): bool
    // BFS from $componentId over ComponentWorkId->its own constituents with a visited set +
    // depth cap: true when $medleyId is reachable (A contains B contains A — design §3.6's
    // bounded-depth guard; the tblSongRedirects "transitive + cycle-guarded" posture).
function workMedleyAttach(\mysqli $db, int $medleyId, int $componentId, int $sortOrder = 0, ?string $note = null): bool
    // Guards: ready; $medleyId !== $componentId; both workExists(); !wouldCycle. Then
    // INSERT ... ON DUPLICATE KEY UPDATE MedleyWorkId = MedleyWorkId  (keep-existing no-op —
    // SD2: the lockstep NEVER updates a curator's row). Returns whether a row exists after.
function workMedleyReplace(\mysqli $db, int $medleyId, array $rows): int
    // The works.php full-form reconcile: DELETE WHERE MedleyWorkId = ?, then INSERT each
    // validated {workId:int, sortOrder:int, note:?string} (same guards as Attach; invalid
    // rows are skipped with error_log, never a throw that costs the curator the save).
```

### §5.2 The lockstep in `component_upsert` (after `$compId` resolves, `api2.php:2784`, inside the existing transaction, before `ed2_touchRevision`)

```php
/* #1860 §3.6b.2 — additive work-grain lockstep (SD2). Setting a section's source
   work on a song that belongs (tblWorkSongs) to other work(s) upserts the matching
   (MedleyWorkId, ComponentWorkId) rows. ADDITIVE ONLY: never removes on clear
   (§3.3.1); never overwrites an existing row; skips silently on any guard
   failure (non-blocking — the section save must never fail on a work concern). */
if ($sourceWorkId !== null && workAdminReady($db) && workMedleyReady($db)) {
    // SELECT WorkId FROM tblWorkSongs WHERE SongId = ?
    foreach ($memberWorkIds as $mw) {
        if ($mw !== $sourceWorkId) {
            workMedleyAttach($db, $mw, $sourceWorkId, $sortOrder /* the section's */, null);
        }
    }
}
```

Gate note: this runs under `component_upsert`'s file-level editor gate + X-Requested-With — deliberately NOT clamped to `manage_works` (it is an additive consequence of an edit the user can already make; the destructive/orderly medley editing stays behind `manage_works` on works.php). Flag in the PR body for owner veto.

**Verification**: `php -l`; a NEW `tests/php/test-work-medley-core.php` unit-testing the guards with the `tests/php/lib/mysqli_doubles.php` doubles where feasible (self-link reject, cycle reject at depth 1 and depth 3, keep-existing on duplicate, ready-gate short-circuit) — mutation-prove by inverting the cycle check once (test must go red).

---

## §6. Commit 6 — `/manage/works` constituents editor (REQ 1)

**File**: `manage/works.php` (gate `manage_works` `:63`; POST already under `validateCsrfRequest` `:465`).

1. **Local `?action=work_search`** (SD8): a GET JSON handler mirroring `song_search` (`:380-430`) — `q`, `limit`, plus `exclude` (int, the work being edited) → `WHERE w.Title LIKE ? AND w.Id <> ?`; returns `{results:[{id,title,slug,iswc,usage}]}`. Read-only GET, no CSRF (the `song_search` precedent).
2. **Edit-save reconcile**: read `$_POST['constituent_work_ids'][]`, `$_POST['constituent_sort'][<workId>]`, `$_POST['constituent_note'][<workId>]` (mirror the member_* shapes `:646-657` — note member sort/note are keyed by id, keep that). Inside the SAME `begin_transaction` (`:659-731`), after the membership block (`:694-714`): `if (workMedleyReady($db)) { workMedleyReplace($db, $id, $rows); }`. (Require `includes/work_admin.php` at top alongside the file's existing includes.)
3. **Edit-form UI**: a "Constituent works (medley)" card section after Member songs (`:1344-1365`): same table shape, `tbody id="edit-work-constituents-tbody"`, a `constituentRowHtml()` mirroring `memberRowHtml()` (`:1485-1506`) emitting the three field names, a typeahead input wired like the member picker (`:1541-1566`) but at `?action=work_search&exclude=<currentId>`, and hydration in `openWorkEditModal` mirroring `:1686` from `row.constituents`. Reuse the existing row remove/reorder JS shape verbatim.
4. **List-load**: a gated `workMedleyConstituentsMap()` read beside `workMembersMap` (`:796-870`) feeding `row.constituents` into the list payload (`:1000` area); optional `Medley (N)` count badge in the list table.
5. Help text: one sentence distinguishing constituents (M:N "contains", `tblWorkComponents`) from the ParentWorkId variant hierarchy and from member songs — the §3.2 table condensed.

**Verification**: `php -l manage/works.php`; `node --check` if any extracted JS; `php tests/php/test-admin-gate-parity.php`; `php tests/php/test-csrf-same-origin.php`; manual round-trip on a local DB: add two constituents, reorder, save, reopen — persisted order; attempt self-add via curl — rejected server-side.

---

## §7. Commit 7 — public display (REQ 1): "Medley of" + work-page constituents

**Files**: `includes/SongData.php` (`_worksMap` `:5615-5770`), `includes/pages/song.php` (works panel `:1616-1671`), `includes/pages/work.php` (`:262+`).

1. `_worksMap()`: after the members/children assembly, a gated step — `if (workMedleyReady(...))`-style probe (use its own INFORMATION_SCHEMA try/catch matching the method's existing posture — `_worksMap` already try/catches the whole body to `error_log` `:5767`; requiring `work_admin.php` here is acceptable, or inline a tableExists probe — prefer requiring the core, rule #22) → `workMedleyConstituentsMap()` over the collected work ids → `$w['constituents'] = [{id,title,slug,sortOrder}]`.
2. `song.php` Part-of-work panel: after the title/ISWC row (inside the `song-work-block`, before the siblings block), when `!empty($w['constituents'])`:
   ```
   Medley of: <a /work/<slug> data-navigate="work" data-work-slug=…>A</a>, <B>, <C>
   ```
   — comma-separated in SortOrder, link markup byte-matching the existing work link (`:1627-1633`). Plain fragment markup only — NO inline `<script>` (rule #30).
3. `work.php`: a "Contains (medley)" section beside the Derivative-works children block (`:262-266`), same list markup, from the work loader's new gated constituents attach (the loader is in the same fragment file — locate where `$work['children']` is assembled and mirror its gating). The INVERSE listing ("part of medleys") is a tracked follow-up, not v1.
4. Cache note: `page=song` / `page=work` are `$_cacheablePages`; the ETag is a hash of the rendered body (`api.php:886`) — a data change re-hashes, so no purge work. (Identical to how the #1206 language badge already behaves.)
5. Deep-link sweep (rule #33): the only emitted URLs are `/work/<slug>` (resolved by `work.php`) — run `node tests/test-editor-deep-links.js`.

**Verification**: `php -l` all three; `php tests/php/test-fragment-inline-scripts.php`; `node tests/test-editor-deep-links.js`.

---

## §8. Commit 8 — the Label display sweep (REQ 3b, rule #33) + machine-export abstinence

### §8.1 Sites to change (each independently — `js/utils/components.js` is NOT a chokepoint for most)

| Site | File:line | Change |
|---|---|---|
| Public song page (canonical) | `includes/pages/song.php:1236-1241` | `$custom = trim((string)($component['label'] ?? '')); $label = $custom !== '' ? $custom : ucfirst($displayType) . (num>0 ? ' '.num : '');` — `$typeClass` (`:1243`), the #858 badge, and the `aria-label` (which reuses `$label`, so it inherits) all UNCHANGED. Escaping already `htmlspecialchars`. |
| Shared client helper | `js/utils/components.js:104-109` (`fullLabel`) | Custom-first: non-empty `comp.label` (trimmed) returned verbatim, else derived. `shortTag()` (`:91-95`) UNCHANGED (structural chip). Consumers (setlist.js) inherit. |
| Print + server/batch PDF (ONE renderer) | `js/modules/print.js:198-204` (`componentLabel`) | Custom-first check; `typeClass` at `:234` unchanged; output already `esc()`d (`:235-236`). `pdf_renderer.php` consumes the POSTed bodyHtml — no server change (rule #39, verified). |
| Service-mode operator chips | `js/modules/service-broadcast.js:411-420` (`_sectionLabel`) | Custom-first check; UPDATE its "mirrors song.php's label logic exactly" comment to name the label override too. Verify insertion is `textContent` (escape audit). |
| Editor2 arrangement chips | `manage/editor/v2/arrangement-editor.js:80-90` (`getComponentLabel`) | Chip TEXT stays structural (`V1`/`Chorus` — space-constrained, identifies STRUCTURE); add the custom label to the chip `title` tooltip at the chip build sites (`:296`/`:324` area): `chip.title = comp.label ? comp.label + ' (' + getComponentLabel(comp) + ')' : getComponentLabel(comp)`. |
| Editor v1 card headers (SD5) | `manage/editor/editor.js:1660` (`componentHeaderLabel`) | Custom-first check (one line). |
| Editor2 structure-tab header | (already done in Commit 4 — `headerText()`) | — |
| DOM-inheriting (FREE, verify only) | `present-mode.js:40-47`, `service-follow.js:372-373`, `compare.js:200-201` | No change — they read the rendered `.lyric-label`. Note: textContent includes the nested language badge short-code ("Kyrie ES") — ACCEPTED (locked); a `data-` attribute refinement is a tracked follow-up only if it annoys in practice. |
| Not sites (no change) | `og-image.php`, search FULLTEXT (`SongData.php:3157`), `display.js:371-377`, `reflow.js` | Documented non-sites: Label never on the OG card; NOT searchable (separate owner decision — file the issue, do not wire); the Verses toggle hides custom labels together with derived ones (fine); a pasted free-text label does not reflow-map to a type (UX note). |

### §8.2 Machine exports — ABSTINENCE (SD7)

`manage/editor/format-export.js` (olVerseName `:366-424`, openSongMarker `:105-116`, vpTag `:192-227`, fsGroup `:264-306`, Proclaim `:463-484`, Pro6 `:556-586`, ChordPro `:790-813`) and `manage/editor/propresenter-export.js` (componentLabel `:299-320`): **zero changes; zero `.label` references** — the round-trip importers (#1052/#883/#1057/#1062) parse these tokens back to type+number. This is asserted by the Commit 10 guard.

### §8.3 API/docs

`api-docs.yaml`: `song_detail` component schema gains the optional `label` (sparse) key; `component_upsert` request/response gain `label`/`sourceWorkId`/`sourceWorkIdIgnored` (rule #35's documentation duty — while remembering documentation is not the mechanism; the guard is).

**Verification**: `node --check` every touched JS; `node tests/test-chordpro-export.js`; `node tests/test-propresenter-export.js`; `php tests/php/test-openlyrics-parser.php`; `php tests/php/test-opensong-parser.php`; `php tests/php/test-freeshow-parser.php`; `node tests/test-setlist-playback.js` + `test-setlist-print.js` (fullLabel consumers); grep audit that every JS site inserts the label via `textContent` or the site's own escaper.

---

## §9. Commit 9 — Editor2 Part-of-work + read-only "Medley of" (§3.7 items 1–3; the separable tail)

**Files**: `manage/editor/v2/api-client.js`, `manage/editor/v2/metadata-tab.js`, `manage/editor/api2.php` (`ed2_buildSongSnapshot`).

1. **api-client methods** (insert after `setCopyrightHolder`, `api-client.js:~253`; endpoints already exist server-side):
   ```js
   searchWorks:  (q, limit)      => getJson('work_search', { q: q || '', limit: limit || 10 }),
   autolinkWork: (songId)        => postJson('song_work_autolink', { songId: songId }),
   setSongWork:  (songId, opts)  => postJson('song_work_set', Object.assign({ songId: songId }, opts || {})),
   ```
2. **Snapshot works block (D6)**: in `ed2_buildSongSnapshot()` (`:1309-1326`), after components — gated on `ed2_worksTableExists()`: a lean query (memberships JOIN tblWorks; + `workMedleyConstituentsMap()` when `workMedleyReady()`) attaching `'works' => [{id,title,slug,iswc,isCanonical,songCount,constituents:[{id,title,slug,sortOrder}]}]`. **Restore-safety verified**: `ed2_applySongSnapshot()` reads only its known keys — the extra `works` block in `NewData` is inert on restore.
3. **metadata-tab**: (a) `'change'` listeners on the CCLI + ISWC FIELDS inputs (`:62-64`; the field's debounced save stays as-is — the listener is the SIDE-EFFECT hook, #1679 discipline per plan §3.3) calling `api.autolinkWork(songId)` and rendering a read-only "Part of work: *Title* (N songs)" line from the RESPONSE (the Tune-badge server-truth pattern, `:795-815`); conflict → non-blocking warning toast; `err.status === 409` (un-migrated) → hide the affordance silently (rule #35 status branching). (b) A manual "Part of work" picker — the copyright-holder attach shape (`:909-923`) over `searchWorks`, committing via `setSongWork` (pick → `{workId}`; typed-new-title → `{title}` — the endpoint's existing contract). (c) When the snapshot's `works[].constituents` is non-empty: a read-only "Medley of: A, B, C" line, names linked to `/work/<slug>`, plus a plain "Manage works" link to `/manage/works` — NO query params (SD9/rule #33). (d) Detach fns into the tab teardown.

**Verification**: `node --check` both JS; `php -l api2.php`; `php tests/php/test-editor-api2-contract.php`; `node tests/test-api-client-usage.js`; `node tests/test-editor-deep-links.js`; manual: type a CCLI, blur → badge appears from response; restore an old revision → works block absence harmless.

---

## §10. Commit 10 — CI guard (rules #33/#34) + close-out

### §10.1 NEW `tests/test-component-label-sites.js` (tree-derived, mutation-proven)

Comment-strip all scanned sources first (the `test-qr-cuercode.js` model). Three assertions:

1. **Display-deriver completeness (tree-derived, not a typed list)**: enumerate every `.js` file under `appWeb/public_html` (excluding `vendor/`, minified, and the two exporter files) whose stripped source matches the structural-derivation fingerprint (a regex for the `charAt(0).toUpperCase()` + `slice(1)` capitalisation applied near a `type` token — calibrate the window against real sources per rule #34's #1676 lesson). EVERY matched file must ALSO reference `.label` (the custom-first check). **Floor assertion**: the derived set must include at least the known five (`js/utils/components.js`, `js/modules/print.js`, `js/modules/service-broadcast.js`, `manage/editor/v2/arrangement-editor.js`, `manage/editor/v2/structure-tab.js`, `manage/editor/editor.js`) — an under-matching regex then fails LOUDLY instead of green-lying (the "scanner that under-reports is worse than no scanner" clause).
2. **PHP canonical site**: `includes/pages/song.php` (stripped) reads `$component['label']`; `includes/lyric_lines_read.php` SELECTs `Label` in both fetchers and the editable shape (string-contains on the stripped source, the `test-lyric-lines-read.php:197-212` technique).
3. **Machine-export abstinence**: `manage/editor/format-export.js` and `manage/editor/propresenter-export.js` (stripped) contain **zero** `.label` references (SD7 makes the file-level assertion exact; narrow enough to never fail on correct code — revisit only if a display-only export slot later adopts Label deliberately).

**Mutation proof (perform, then restore; record in the test header + commit body)**: (m1) delete the custom-first check from `print.js` → assertion 1 red; (m2) add `comp.label` into `olVerseName()` → assertion 3 red; (m3) neuter the fingerprint regex → the floor assertion red. Register the test in the runner set `package.json` uses (rule #35 — the npm-vs-CI list is a known drift pair; verify it actually runs under `npm run test:js`).

### §10.2 Full-suite + close-out

`npm run test:all` (JS + PHP suites incl. every guard named in Commits 1–9). Version bump ONCE for the batch in `includes/infoAppVer.php` + mirrors (the #1860 versioning note). Standing tasks: issues (below) closed with SHAs; CHANGELOG/DEV_NOTES/PROJECT_STATUS; Wiki (Schema + Editor pages); `.claude/ProjectBrief.md` + handoff.

---

## §11. Adversarial validation — risk ⇄ baked-in mitigation

| Risk (from the analysis) | Where this spec kills it |
|---|---|
| **Label leaks into round-trip machine exports** (HIGH) | SD7 zero-`.label` in both exporter files; §8.2 no-change; §10.1 assertion 3 + the export/parser suites in §8's verification. |
| **~8 independent derivers → silent partial** (HIGH) | §8.1's exhaustive table (re-verified file:line this pass); §10.1 assertion 1 derives the list from the TREE with a loud under-match floor. |
| **Silent wipe via omitted key** (MEDIUM) | THREE layers (§3): handler target-preserve (`component_upsert`), handler full-set read-modify-write via the §2 read shapes (non-target comps), writer provided-flag preserve (foreign funnels: stale clients, `lyrics_ingest` re-ingest, old snapshots). Plus the two funnel-specific carries: `components_replace` FIFO (§3.3) and save_song PF1 (§3.4) — both were REAL gaps the generic "isset-preserve" phrasing under-specified: each funnel REBUILDS a fixed shape and has its own carry mechanism that had to be extended by name. |
| **Byte-identical contract / golden fixtures** (MEDIUM) | SD3 sparse emit — concrete finding this pass: `test-lyric-lines-read.php` compares with strict `===`; sparse emit keeps every existing fixture green and the corpus-wide parity claim literally true; §2.2 doc-block revision + §2.5 new fixtures in the SAME commit. |
| **Option A vs B write path** | LOCKED D4 (Option A); §3.1 is the exact writer diff; the read-side change (§2.3) confirmed unavoidable either way. |
| **Entitlement drift** | `manage_works` verified at `works.php:63` (NOT `manage_songbooks`); lockstep deliberately on the editor gate (§5.2, flagged for veto); `test-admin-gate-parity.php` in Commit 6's verification. |
| **`.lyric-label` textContent includes the badge** (LOW) | Accepted (locked); noted at §8.1 with the `data-` attribute follow-up named. |
| **Label not searchable** (LOW) | Out of scope; §8.1 non-site row; a `for consideration` issue filed (§12 item 10) — never silently wired. |
| **Arrangement-chip mismatch** (LOW) | §8.1: chips stay structural, custom label in the tooltip. |
| **IsNumbered unwired** (LOW) | Informational only: numbering is the `number>0` sentinel (#795), per-row and independent — a label never renumbers siblings. Documented; no action. |

**Gaps the analysis missed — now explicit commit steps** (the additions of this pass):

1. **Strict-`===` fixture mechanics** → SD3 + §2.5 (the "update the golden replay" hand-wave is now a concrete sparse-emit design that requires NO fixture rewrites).
2. **`components_replace` FIFO carry** (`api2.php:4580-4605`) → §3.3. Without it, every Paste & Reflow wipes every label — the analysis only said "accept the keys", which does not survive a reflow that (correctly) omits them.
3. **`save_song_core` rebuilds a fixed shape** (`:971-978`) + its PF1 carry (`:754-785`) → §3.4. "Forward from save_song_core" alone would still have wiped labels on legacy whole-song saves.
4. **Old-revision restore semantics** → SD6 (absent-key preserve; verified `ed2_applySongSnapshot` passes snapshot components straight through at `:1544-1547`) and the Commit 9 note that the new snapshot `works` block is inert on restore.
5. **The Label-column-without-mirror install** → SD4: all four legacy branches read+write gated, killing a rule-#30-class silent no-op the dormant-column design would otherwise create.
6. **Native/API contract + docs** → sparse additive `label` in `song_detail` (unknown-key-safe for the apps); `api-docs.yaml` in §8.3.
7. **Fragment cache** → no work needed and now PROVEN why: content-derived ETag (`api.php:886`), same path #1206's badge already rides.
8. **A11y + XSS** → §4.2 input/button accessible names; `aria-label` on the section group inherits the effective label automatically (`song.php` reuses `$label`); per-site escape audit in §8's verification.
9. **Server-side hide-when-equal** (D1) → §3.2's fold in the ONE accept path, so client/server never disagree about redundancy (rule #35: the server is the mechanism, not a matching client implementation).
10. **`works.php` has no `?id=` deep link** → SD9 verified by grep; Editor2 emits only URLs the destinations honour.
11. **Lockstep "medley-shaped" was undefined when the medley has no rows yet** → SD2's operational definition + seed-SortOrder + never-update detail.

---

## §12. GitHub issues (file before the commits that close them — epic parent = the #1860 Phase-5 epic)

1. EPIC — Wire the dormant work-identity model + custom component labels (REQ 1/2/3b; 3a confirmed live).
2. `tblSongComponents.Label` — migration + schema.sql mirror + registry entry/probe (Commit 1).
3. Read/write seams carry Label + SourceWorkId (silent-wipe-proof, 3 layers) (Commits 2–3).
4. Editor2 Structure tab — Label input + "Use language name" + Source-work picker (Commit 4).
5. Medley write core + §3.6b.2 lockstep (Commit 5).
6. `/manage/works` constituent-works editor (Commit 6).
7. Public "Medley of" + work-page constituents (Commit 7).
8. Label display sweep + machine-export abstinence (Commit 8).
9. Editor2 Part-of-work badge/picker + read-only Medley of (Commit 9).
10. `for consideration`: component-Label searchability (separate indexing decision) + `.lyric-label` badge-in-textContent refinement + "part of medleys" inverse listing on `/work`.
11. CI guard `test-component-label-sites.js` (Commit 10).

## §13. Files-touched index

| Area | Files |
|---|---|
| Schema/migration | `appWeb/.sql/migrate-add-component-label.php` (NEW), `appWeb/.sql/schema.sql` (:732-area), `manage/includes/migration-registry.php` |
| Read seam | `includes/lyric_lines_read.php`, `includes/SongData.php` (`_getComponentsFromJson`/Map, `_worksMap`), `manage/editor/api2.php` (`ed2_currentComponents`) |
| Write seam | `includes/lyric_lines_sync.php`, `manage/editor/api2.php` (`component_upsert`, `components_replace`, `ed2_persistComponents`, `ed2_buildSongSnapshot`), `manage/editor/save_song_core.php` |
| Work core | `includes/work_admin.php` (medley helpers at the `:1082` stub) |
| Admin UI | `manage/works.php`, `manage/editor/v2/structure-tab.js`, `manage/editor/v2/metadata-tab.js`, `manage/editor/v2/api-client.js`, `manage/editor/v2/arrangement-editor.js`, `manage/editor/editor.js` |
| Public UI | `includes/pages/song.php`, `includes/pages/work.php`, `js/utils/components.js`, `js/modules/print.js`, `js/modules/service-broadcast.js` |
| Docs/contract | `api-docs.yaml`, `includes/infoAppVer.php` (+ mirrors, once at merge) |
| Tests | `tests/php/test-lyric-lines-read.php` (extend), `tests/php/test-work-medley-core.php` (NEW), `tests/test-component-label-sites.js` (NEW), `package.json` runner registration |

## §14. Definition of done

- [ ] Commit 1: migration + mirror + registry; schema-coverage + migration-registry tests green; card applies + probe flips on a scratch DB.
- [ ] Commit 2: both shapes emit the keys (sparse public / always editor); ALL existing `test-lyric-lines-read.php` fixtures pass UNCHANGED; new fixtures added; doc-block revised.
- [ ] Commit 3: all five funnels + writer carry the keys; omit-key curl round-trip preserves; reflow-replace carries; `test-component-json-guard.php` green.
- [ ] Commit 4: Label input (placeholder = live derived name), autonym button, Source-work picker (commit-on-change, teardown-registered); server read-back adopted.
- [ ] Commit 5: medley core with mutation-proven guard test; lockstep additive-only, non-blocking, inside the txn.
- [ ] Commit 6: works.php editor round-trips constituents; exclude-self search; self/cycle rejected server-side; `manage_works` gate intact.
- [ ] Commit 7: "Medley of" on song page + "Contains" on work page, gated + fragment-safe (`test-fragment-inline-scripts.php` green).
- [ ] Commit 8: every §8.1 site honours Label; both exporter files still contain zero `.label`; export/parser suites green; `api-docs.yaml` updated.
- [ ] Commit 9: badge/picker/medley-line from server truth; snapshot `works` block inert on restore; no unhonoured URL params emitted.
- [ ] Commit 10: new guard registered in the runner, mutation-proven (all three mutations recorded), `npm run test:all` green; version bumped once; issues closed with SHAs; CHANGELOG/Wiki/ProjectBrief/handoff updated.
- [ ] PR audit: `find appWeb -name '*.php' -exec php -l {} \;` + `find appWeb -name '*.js' -exec node --check {} \;` clean; one PR to `alpha`, atomic revertable commits.

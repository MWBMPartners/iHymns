# Epic #1741 — P5: editor fields for the new IDs + tune typeahead + the #1749 dual-write — BUILD SPEC

> **Status: PLANNED (deep pass, 2026-08-03). Implementation-ready.** Branch `claude/wave3-fixes`,
> same single PR as P1–P4. Parent plan: `.claude/catalogue-expansion-1741-plan.md` — §3B
> (tune live-search design, :301-323), §3C (P5 row, :334: deps P1, P2, B.2), §1 (editor
> current-state, :93-97 Tunes / :99-108 Songs), §2.4 (tblSongs identity columns, :209-215).
> Sibling spec consumed here: `.claude/catalogue-1741-P4-plan.md` §0 (cross-cutting facts)
> and §2.4.3 (:455-464 — the `tuneFindOrCreateByName()` funnel P4b built FOR P5 to consume).
> Every line number below verified by direct read against `claude/wave3-fixes` at `f1e79d98`.
>
> **Ground-truth deltas vs the parent plan the implementer must know:**
> (a) `includes/tune_helpers.php` EXISTS (P4b, 221 lines) with `ihymns_tune_slugify()` (:92),
>     `tuneTunesTableExists()` (:115) and `tuneFindOrCreateByName()` (:177) — P5 consumes,
>     never re-forks (the file's own doc-block :43-47 names P5 as its designed second caller).
>     It does NOT yet contain `ihymns_meter_normalize()` — P5c builds that (parent §3B :321).
> (b) `tblWorks` lockstep is LIVE (works.php `$persistWorkExtraFields` :270-330, TuneName+TuneId
>     written together :307-313) — the exact call-site pattern P5c's song-side lockstep mirrors.
> (c) The #1747 D5 backfill landed (`migrate-backfill-song-external-ids.php`): tblSongExternalIds
>     already holds mirrored rows with `Source='ihymns-backfill'`, `SourceRef='tblSongs.Isrc'`
>     (:169-173) — the P5d mirror MUST use the same SourceRef ownership literal (§4.2).
> (d) The P1 `song-identity-fields` card already CANONICALISED stored `tblSongs.Iswc` + `Isrc`
>     (migration-registry.php:2051) — new editor writes must canonicalise the same way
>     (`ihymns_canonical_isrc()`, identifier_normalize.php:223-228) or `/isrc/` exact-match breaks.
> (e) Grep confirms NO `tune_search` / `song_tune_set` / `song_external_id*` action exists
>     anywhere yet (only doc-comments in tune_helpers.php:45 and tune.php:38/:277 promising them).
>
> **Recommended build order: P5a → P5d → P5b → P5c** (§5). One reviewable commit each.
> New guard files auto-run (node `tests/*.js` glob, PHP `tests/php/*.php` glob — P4 plan header).

---

## §0 — Cross-cutting facts every sub-part relies on

### 0.1 The editor is a bespoke-head ADMIN page — rule #30/#6 do NOT apply; here is what does

`manage/editor/editor2.php` is a full admin page with its own `<head>`, NOT an SPA fragment:
it already carries a classic inline `<script>` emitting `window._iHymnsLinkTypes` (:233) and an
inline `<script type="module">` shell (:264-…). There is no nonce CSP on `/manage/*`, and the
rule-#30 CI guard (`tests/php/test-fragment-inline-scripts.php`) only scans `includes/pages/` +
`includes/partials/` — the editor is structurally out of its scope. **The constraints that DO
bind here:** (1) no floating-version / SRI-less CDN assets (#1587) and Bootstrap tags only via
`includes/bootstrap_assets.php` (rule #36) — P5 adds NO new third-party asset, so this is
satisfied by not touching the `<head>`'s library loads; (2) admin theme via
`admin-theme-init.php`, already wired (rule #16) — untouched; (3) new page JS ships as v2 ES
modules under `manage/editor/v2/` imported from the module shell, or as classic
`/js/modules/*.js` scripts loaded like place-search.js (:243) — both established patterns.

### 0.2 CSRF for new api2 endpoints — inherit the module-level gate, add NOTHING

api2.php applies ONE module-level POST gate BEFORE dispatch: every POST lacking
`X-Requested-With: XMLHttpRequest` is refused 403 (api2.php:271-273; rationale doc :251-270).
This IS the rule-#29 defence (the same-origin header route — `validateCsrfRequest()` in
`manage/includes/auth.php` is the equivalent for manage-page form handlers like
places-api.php:170; api2's gate is the identical mechanism inlined for a JSON API). **Every new
POST case added to the api2 switch inherits it automatically — write NO per-action CSRF code,
and never re-introduce a baked `$_SESSION` token compare (the exact regression rule #29 bans).**
The client side is already correct: `v2/api-client.js` `postJson()`/`postForm()` send the header
(:113-126, :132-144 — the #1677 fix), and `tests/php/test-editor-api2-contract.php` derives both
sides from source, so any new action literal routed through `editorApi` is auto-covered.

### 0.3 Client plumbing — v2 has its OWN client; route everything through it

All new endpoints get one method each on `editorApi` in `manage/editor/v2/api-client.js`
(:147-269). Its `unwrap()` attaches `err.status` (:47-71); **clients branch on STATUS
(400/404/409/422), never on error prose** (rule #35). Do NOT import the public SPA's
`js/utils/api-client.js` into the editor — api-client.js:283-291 documents why rule #31 means
"one client per tree", and /manage's one client is this file. Routing panel calls through
`editorApi` (not bare fetch) is also what makes `test-editor-api2-contract.php` auto-derive the
action-name coverage (its parse source is api-client.js).

### 0.4 Existence-gating idioms to copy (rule #5 / mysqli STRICT; migrations are web-run)

1. **Memoised table probe** — `ed2_songMediaTableExists()` (api2.php:344-358); tune side:
   `tuneTunesTableExists()` (tune_helpers.php:115-131).
2. **Per-column present-set probe + gated separate UPDATE** — works.php `$worksExtraCols`
   (column list :119) feeding `$persistWorkExtraFields` (:270-330): each field drops out of the
   SET list independently; the main bind is never touched.
3. **Generic column probe** — `placeColumnExists(mysqli $db, string $table, string $column)`
   (includes/places.php:49), already loaded by save_song_core.php (:43) — reuse there; api2 gets
   its own self-contained memoised helper (§1.3) to avoid pulling places.php in at module scope.
4. **Un-migrated read degrades, un-migrated write refuses** — GET answers
   `{ok:true, …:[], tableMissing:true}` (the song_link_suggestions precedent, api2.php:98-106);
   POST answers **409** (the delete_song un-migrated convention, api2.php:50-51). HTTP status is
   the contract (rule #35).

### 0.5 load_song is `SELECT *` — the new columns free-ride into the client

`ed2_buildSongSnapshot()` reads the row with `SELECT * FROM tblSongs` (api2.php:623), and the
`song` store slice is that raw PascalCase row (metadata-tab.js:12-13, editor2.php:315). So on a
migrated install the P1 columns arrive with NO server change, and on an un-migrated install the
KEYS are simply absent — which is the client's zero-cost existence gate: **render a gated field
only when its column key exists in the `song` slice** (§1.4). The revision snapshot NewData is
the same `SELECT *` row, so the new columns are already CAPTURED by every revision; §1.3 makes
restore also WRITE them (gated).

### 0.6 Who links/calls here (rule #33 grep results — contracts P5 must not break)

- `metadata_field_update` with `field=tuneName`: the ONLY caller is metadata-tab.js's FIELDS
  row :40 (tree grep — editor.js's `tuneName` refs are the v1 whole-song `save_song` payload,
  a different action). The field KEY is still a wire contract (a Service-Worker-stale
  metadata-tab.js keeps sending it), so it is retired by ALIASING into the new funnel
  (§3.3-3), mirroring the `SongbookAbbr` branch precedent (api2.php:1100-1141) — never by
  deleting the key (a stale client would 400 on every tune edit).
- `TuneName` SQL write sites (tree grep, the lockstep obligation set): api2
  `metadata_field_update` (:1154/:1160 via the map :299), `save_song_core.php` UPSERT
  (:582/:603/:622), `includes/song_importers.php` universal saver (:499), `manage/works.php`
  (already lockstepped :307-313). Migration scripts under `appWeb/.sql/` are exempt (one-shot).
- `tblSongs.Isrc` write sites (the §4 mirror obligation set): NONE today in either editor
  (parent §1: "not editable in either editor"); `includes/lyrics_ingest.php` INSERT :612/:618 +
  COALESCE-fill :679; the re-runnable backfill card. P5a CREATES the editor write site, so P5d
  wires the mirror into it at birth.
- No page deep-links into the editor with a tune/ID param (`$_ED2_TABS` :93-103 covers `?tab=`;
  nothing new needed; `tests/test-editor-deep-links.js` stays green untouched).

---

## §1 — P5a: Song-editor identity fields (Isrc + the P1 five)

### 1.1 Current state (file:line) + exact gap

**Schema — every column the brief names EXISTS; NO P1 gap found** (schema.sql tblSongs block
:260-351): `Subtitle VARCHAR(500) NULL` (:267), `Disambiguation VARCHAR(255) NOT NULL DEFAULT ''`
(:268), `CopyrightYears VARCHAR(100) NOT NULL DEFAULT ''` (:272), `CopyrightHolder VARCHAR(255)
NOT NULL DEFAULT ''` (:273), `FirstPublishedYear SMALLINT UNSIGNED NULL` (:274) — all tagged
`#1741 P1`; `Isrc VARCHAR(15) NULL` (:284, #1064 — pre-P1, present on every install that ran the
#1064 card) + `idx_Isrc` (:319). Adjacent: `Upc VARCHAR(20)` (:285) — NOT in the brief; §1.6 rider.

**Server:** `ED2_META_FIELDS` (api2.php:291-307) contains none of the six. The generic UPDATE
(:1160) interpolates only allow-listed column names (rule #5 carve-out); empty→NULL coercion
list is `['TuneName','Iswc','OriginCity']` (:1154); nullable-int branch covers only
`number`/`originCityId` (:1146-1148). `ed2_applySongSnapshot()` restores exactly the
ED2_META_FIELDS set (:743-761) with UNGATED `UPDATE`s — adding P1 columns to the map without a
gate would make revision_restore THROW under STRICT on an un-migrated docroot (#1228 class).

**Client:** metadata-tab.js `FIELDS` (:33-47) has none of the six.

### 1.2 Server changes — api2.php

1. Extend `ED2_META_FIELDS` (:291-307) with six rows (camelCase key = the api contract):
   ```php
   'isrc'               => ['Isrc',               's'],
   'subtitle'           => ['Subtitle',           's'],
   'disambiguation'     => ['Disambiguation',     's'],
   'firstPublishedYear' => ['FirstPublishedYear', 'i'],
   'copyrightYears'     => ['CopyrightYears',     's'],
   'copyrightHolder'    => ['CopyrightHolder',    's'],
   ```
2. Coercions in `metadata_field_update` (:1144-1155), per the schema's null semantics:
   - add `'Isrc','Subtitle'` to the empty-string→NULL list at :1154 (both columns NULL-able;
     `Disambiguation`/`CopyrightYears`/`CopyrightHolder` are `NOT NULL DEFAULT ''` — they keep
     the plain `''`, mirroring works.php's split :265-267);
   - add `'firstPublishedYear'` to the nullable-int branch at :1146 (empty/`<=0` → NULL), plus a
     range check `500..2100` → **422** `{'ok'=>false,'error'=>'firstPublishedYear must be a
     4-digit year (500–2100).'}` (mirrors works.php's SMALLINT-not-YEAR bounds, P4 plan §2.4.3);
   - **ISRC branch** (before the generic UPDATE, in the same style as the TuneName branch §3.3):
     `require_once includes/identifier_normalize.php`; `$canon = ihymns_canonical_isrc((string)$raw)`
     (:223-228 — uppercase, strip non-alnum); empty → NULL; non-empty must then pass
     `mediaIdentifierValidateValue('isrc', $canon)` (media_identifiers.php:294-301, the
     `/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/` shape :168) else **422** — a decided default, trivially
     loosenable if a curator ever hits a genuine nonstandard code (the RESOLVE path stays
     tolerant by design, identifier_normalize.php:209-216; only the WRITE is strict). The
     canonical value is what the UPDATE binds — keeps `/isrc/`'s indexed exact match
     (identifier_resolve.php:183/:341) and the P1 canonical backfill (:0-delta (d)) honest.
     The P5d mirror call (§4.3) lands inside this same branch/transaction.
3. **Existence gate** — new memoised helper beside `ed2_songMediaTableExists()` (:344):
   ```php
   /** Per-column present-map for the #1741 P1 tblSongs identity columns. ONE
    *  INFORMATION_SCHEMA.COLUMNS query (IN-list of hardcoded constants, rule #5),
    *  memoised. Isrc (#1064) is deliberately NOT in this map — it predates P1. */
   function ed2_songIdentityColsPresent(\mysqli $db): array   // ['Subtitle'=>bool, …]
   ```
   probing exactly `('Subtitle','Disambiguation','FirstPublishedYear','CopyrightYears','CopyrightHolder')`.
   - In `metadata_field_update`: when the resolved `$column` is one of those five and its map
     entry is false → **409** `{'ok'=>false,'error'=>'This install has not applied the
     song-identity-fields migration card yet (run it at /manage/setup-database).'}` — status is
     the contract (rule #35); clients show the message, branch on 409.
   - In `ed2_applySongSnapshot()` (:743): `continue` past an absent-column field (silent skip —
     a restore of everything else must still land; matches the works partial-apply posture,
     P4 plan §2 gating note). One added line inside the existing loop, after the `SongbookAbbr`
     skip (:745).
4. No change to `logActivity` shape — the existing `['field' => $field]` payload (:1183) already
   covers the new fields.

### 1.3 save_song_core.php (v1 whole save) — deliberately NOT extended, and why that is safe

The v1 editor has no UI for these fields and its UPSERT names its columns explicitly
(:600-635); `ON DUPLICATE KEY UPDATE` only touches the named set (:578-588), so a v1 whole-save
of a song whose Subtitle/Isrc were set via v2 **preserves them untouched**. Adding
payload-absent-vs-empty semantics to the 15/16-param UPSERT for fields no caller sends would be
pure risk (the #1679 M2 "omission ≠ instruction" class) for zero value. State this in the commit
message. (The TUNE lockstep is different — save_song_core DOES write TuneName — see §3.4.)

### 1.4 Client changes — metadata-tab.js

1. Extend `FIELDS` (:33-47) — insert, keeping related fields adjacent:
   - after `['title',…]` (:34): `['subtitle','Subtitle','Subtitle','text']`,
     `['disambiguation','Disambiguation (short parenthetical)','Disambiguation','text']`;
   - after `['iswc',…]` (:39): `['isrc','ISRC','Isrc','text']`;
   - after `['copyright',…]` (:41): `['copyrightYears','Copyright year(s)','CopyrightYears','text']`,
     `['copyrightHolder','Copyright holder','CopyrightHolder','text']`,
     `['firstPublishedYear','First published (year)','FirstPublishedYear','number']`.
2. **Client-side gate** (§0.5): a module-level `const GATED_COLUMNS = new Set(['Subtitle',
   'Disambiguation','FirstPublishedYear','CopyrightYears','CopyrightHolder'])`; in the render
   loop (:229) skip a FIELDS row when `GATED_COLUMNS.has(column) && !(column in song)` — an
   un-migrated env shows no dead control (and can therefore never even attempt the 409 path;
   the server 409 remains for the stale-client case). `Isrc` is ungated (pre-P1 column).
3. No new save plumbing — the existing `debouncedSave()`/`save()` (:95-119) handle text/number
   kinds; the 422/409 refusals surface through the existing failure toast (:112) with the
   server's sentence.

### 1.5 Existence-gating summary (P5a)

| Read/write | Gate |
|---|---|
| load_song emit of the five P1 columns | none needed — `SELECT *` (:623) emits what exists |
| metadata_field_update of the five | `ed2_songIdentityColsPresent()` → 409 when absent |
| metadata_field_update of `isrc` | none (column #1064-old); value-shape 422 |
| revision-restore scalar write of the five | same present-map → silent skip |
| metadata-tab render of the five | `column in song` key check (client, zero requests) |

### 1.6 Out of scope + riders (P5a)

- **`upc` rider (optional, flag in commit, droppable):** `'upc' => ['Upc','s']` + a FIELDS row —
  the sibling #1064 column (:285) with the identical no-editor gap; NOT in the P5 brief, so a
  rider, not scope. Empty→NULL (column is NULL-able). No canonicaliser exists for UPC — length
  ≤20 trim only.
- **Public song-page surfacing is NOT built and not P5**: grep confirms `includes/pages/song.php`
  renders none of Subtitle/Disambiguation/FirstPublishedYear/CopyrightYears (0 hits in 1,737
  lines). The editor can now enter values nothing public renders — **file one follow-up issue
  under #1741** ("Song page: render Subtitle/Disambiguation/© split/FirstPublishedYear") at
  build time (standing-tasks §2a); P6 or its own slice.
- v1 editor fields (deliberate, §1.3).

### 1.7 Guard — `tests/php/test-song-identity-editor-fields.php` (rule #34)

Tree-derived input: slice schema.sql's `CREATE TABLE IF NOT EXISTS tblSongs` block (anchor by
table name, :260-351) and extract column names whose declaration line contains `#1741 P1` →
currently the five. Assertions:
- every derived column name appears in api2.php's `ED2_META_FIELDS` region (slice from
  `const ED2_META_FIELDS` to the closing `];`) AND in metadata-tab.js's `FIELDS` region;
- `'isrc'` appears in both regions (`Isrc` is #1064-tagged so the derivation would miss it —
  assert it explicitly, with a comment saying why it is the one hand-named entry);
- the `metadata_field_update` case region (slice `case 'metadata_field_update'` → next `case `)
  references `ihymns_canonical_isrc` and `mediaIdentifierValidateValue`;
- both the `metadata_field_update` region AND the `ed2_applySongSnapshot` function region
  reference `ed2_songIdentityColsPresent`;
- metadata-tab.js references `GATED_COLUMNS` and every derived column name appears inside its
  declaration (the client gate can't silently cover a subset).
**Mutation checklist (run pre-merge, restore byte-identical):** (1) delete the `'subtitle'` row
from ED2_META_FIELDS → red; (2) delete the `ihymns_canonical_isrc` call → red; (3) remove the
gate call from `ed2_applySongSnapshot` → red; (4) drop `FirstPublishedYear` from GATED_COLUMNS
→ red. (`test-editor-api2-contract.php` needs no change — no new action names in P5a.)

---

## §2 — P5b: Recording external-ID entry (tblSongExternalIds' FIRST write path)

### 2.1 Current state (file:line) + exact gap

`tblSongExternalIds` (schema.sql:3439-3457): key/value store, `uq_Song_Type_Value
(SongId,IdType,IdValue)` (:3450), `IdScope`/`IdType` app-validated against
`includes/media_identifiers.php` (`SONG_EXTERNAL_ID_SCOPES` :96, `RECORDING_EXTERNAL_ID_TYPES`
:164-254, validators :273/:283/:294). The ONLY writer is the one-shot D5 backfill
(`migrate-backfill-song-external-ids.php`, INSERT IGNORE, Source='ihymns-backfill'); the only
reader is that card's probe. **No UI read or write path exists anywhere; no api2 action; the
editor never shows a song's external IDs.** This sub-part is the store's first live write path.

### 2.2 api2.php — three new actions (names, params, status codes, response keys)

All three follow the house case shape (bind_param everything, `ed2_respond`, per-case
transaction for writes). New memoised probe `ed2_songExternalIdsTableExists(\mysqli $db): bool`
— byte-pattern of `ed2_songMediaTableExists()` (:344-358) with the table name swapped.

1. **GET `song_external_ids?id=<SongId>`** → `{ ok:true, externalIds:[…], tableMissing?:true }`
   - table absent → `{ ok:true, externalIds: [], tableMissing: true }` (the
     song_link_suggestions degrade precedent, §0.4.4). Song absent → 404.
   - `SELECT Id, IdScope, IdType, IdValue, Source, SourceRef FROM tblSongExternalIds
      WHERE SongId = ? ORDER BY IdType ASC, Id ASC` (bound).
   - Row shape: `{ id:int, idType, idValue, scope, source, label, url }` where `label` =
     `RECORDING_EXTERNAL_ID_TYPES[$idType]['label'] ?? $idType` and `url` = the registry's `%s`
     template `sprintf`-filled with `rawurlencode($idValue)` when non-null, else null
     (media_identifiers.php:139-151's documented contract — never invent a URL for a
     null-template provider).
2. **POST `song_external_id_add`** `{ songId, idType, idValue }` →
   `{ ok:true, externalId:{…row shape…}, created:bool }`
   - 400 missing params; 404 song not found; **409** table missing (`'…run the Song external
     IDs (#1741 D5) migration card…'`); **422** unknown `idType`
     (`mediaIdentifierIdTypeValid()` :273) or value shape fail
     (`mediaIdentifierValidateValue()` :294 — remember: a null-validate type accepts any
     non-empty value, so this never blocks the format-less providers).
   - When `idType === 'isrc'`, canonicalise the value through `ihymns_canonical_isrc()` FIRST
     (the validator expects the bare 12-char form :168) — one fold, shared with §1.2/§4.
   - `IdScope` := `mediaIdentifierScopeForType($idType)` (:283) — server-derived, never a
     client param. `Source` := `'manual'`, `SourceRef` := NULL (the free-coexistence design,
     schema.sql:3445-3446; manual rows are exactly what NULL SourceRef means).
   - `INSERT IGNORE` (the uq handles the dupe) → `created = affected_rows > 0`; when
     `created === false` re-select the existing row so the echo is canonical. NO
     `ed2_touchRevision` — external IDs are outside the content snapshot, exactly like `media`
     (api2.php:942-944's reasoning); `logActivity('song.external_id.add','song',$songId,
     ['idType'=>…,'idValue'=>…])`.
3. **POST `song_external_id_delete`** `{ songId, id }` → `{ ok:true, deleted:int }`
   - `DELETE FROM tblSongExternalIds WHERE Id = ? AND SongId = ?` (songId in the WHERE =
     defence-in-depth against a cross-song id). Already-gone → `{ok:true, deleted:0}` (the
     idempotent-double-click posture of song_link_remove, api2.php:92-97). 409 table missing.
   - `logActivity('song.external_id.delete', …)`.
   - **Deleting the P5d mirror row is allowed and harmless** — the next Isrc save re-mints it
     (§4.2); do not special-case it.

Entitlement: the file-level editor-role gate (:206-214) only, matching credit_upsert/tag_attach
(no per-action entitlement) — adding IDs is ordinary curation, not a destructive class.

### 2.3 The IdType vocabulary reaches the client server-derived (rule #35 — no second list)

editor2.php already emits the link-type registry as `window._iHymnsLinkTypes` (:233). Mirror
that exactly: `require_once includes/media_identifiers.php` in editor2.php's PHP prologue and
emit, in the same `<script>` block region:
```php
window._iHymnsRecordingIdTypes = <?= json_encode(array_map(
    static fn(array $t) => ['label' => $t['label'], 'scope' => $t['scope']],
    mediaIdentifierRecordingTypes()
), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
```
Slug keys + labels only — do NOT ship the PCRE `validate` patterns to JS (PCRE↔JS regex
delimiters/flags differ; a half-translated pattern is a silent divergence — the server's 422 is
the ONE validator, rule #35). The dropdown is built from this object; the panel contains ZERO
hardcoded provider slugs (§2.6 guard enforces it).

### 2.4 Client — new module `manage/editor/v2/external-ids-panel.js`

**Mount point: inside the Metadata pane, below the scalar grid — via the song-key-panel
dynamic-import pattern** (metadata-tab.js:314-327), NOT a new tab. Rationale: recording IDs are
identifiers-about-the-song (metadata), the Links tab is URL rows with `typeId` FKs into
`tblExternalLinkTypes` (links-tab.js:4-19 — a different store and a different editor contract;
reusing `iHymnsExtLinksEditor` here would mean faking typeIds, a fork-by-misuse), and a new tab
would touch editor2.php's nav, panes AND `$_ED2_TABS` (:93-103) for one fieldset. Add a second
`import('./external-ids-panel.js')` chain after the song-key import in `render()` (:322-327),
with its own `extIdsDetach` teardown var handled identically to `keyPanelDetach` (:86, :224, :339).

`mountExternalIdsPanel(container, { songId, toast }) -> teardown fn`:
- imports `{ editorApi }` from `./api-client.js` (the song-key-panel precedent);
- on mount: `editorApi.listExternalIds(songId)` → `tableMissing` ⇒ render one muted line
  "External-ID storage is not migrated on this install." and stop (branch on the FLAG for the
  200-read; on `err.status === 409` for the writes);
- renders: `<fieldset>` heading "Recording / external IDs", a list of rows
  (`<code>label</code> value [↗ when url] [Remove]`; the `url` opens
  `target="_blank" rel="noopener nofollow external"`), and an add row: `<select>` from
  `window._iHymnsRecordingIdTypes` (options sorted by label; value = slug) + `<input>` +
  "Add" button;
- Add → `editorApi.addExternalId(songId, idType, idValue)`; on success prepend the ECHOED row
  (never the typed input — the server canonicalises isrc); `created === false` ⇒ toast
  "Already recorded." (info, not error). 422 ⇒ toast the server sentence (the prose is display,
  the status is the branch). Remove → `editorApi.deleteExternalId(songId, id)`, drop the row on
  `ok`;
- a11y: the add row's select+input get `<label class="visually-hidden">`s; the remove buttons
  get `aria-label="Remove <label> <value>"`; the list is `role="list"`/`role="listitem"`.
- api-client.js additions (auto-covered by the contract guard):
  ```js
  listExternalIds:  (songId)            => getJson('song_external_ids', { id: songId }),
  addExternalId:    (songId, idType, idValue) => postJson('song_external_id_add', { songId, idType, idValue }),
  deleteExternalId: (songId, id)        => postJson('song_external_id_delete', { songId, id }),
  ```

### 2.5 Existence-gating summary (P5b)

| Read/write | Gate |
|---|---|
| GET list | `ed2_songExternalIdsTableExists()` → `tableMissing:true`, empty list |
| POST add/delete | same probe → 409 |
| IdType/IdValue | central-map validators (media_identifiers.php :273/:294) → 422 |
| Client dropdown | `window._iHymnsRecordingIdTypes` presence check (absent → hide the add row, keep the read list) |

### 2.6 Guard — `tests/test-v2-external-ids-ui.js` (node; the test-v2-enrichment-ui.js shape)

Tree-derived assertions (parse, never hand-copy — the enrichment guard's stated method):
- every `song_external_id*` action literal in api-client.js has a `case '…':` in api2.php
  (the contract guard also covers this; keep ONE focused re-assert here so this guard is
  self-contained and can be mutation-tested in isolation);
- extract the slug keys of `RECORDING_EXTERNAL_ID_TYPES` from media_identifiers.php source and
  assert **none** appears as a quoted literal inside external-ids-panel.js (the no-second-list
  mechanism — a hardcoded `'spotify'` fallback array is the regression);
- external-ids-panel.js references `window._iHymnsRecordingIdTypes`; editor2.php contains
  `mediaIdentifierRecordingTypes(` and `_iHymnsRecordingIdTypes`;
- api2.php's `song_external_id_add` case region references `mediaIdentifierIdTypeValid`,
  `mediaIdentifierValidateValue` AND `mediaIdentifierScopeForType` (server-derived scope);
- the add case answers 409 + 422 (regex the two `ed2_respond(..., 409)` / `..., 422)` calls in
  the region — bound the slice window generously per rule #34's #1676 lesson, ≥300 chars).
**Mutation checklist:** (1) hardcode `['isrc','spotify']` in the panel → red; (2) typo the
action literal in api-client.js → red (and the PHP contract guard also red); (3) delete the
`mediaIdentifierScopeForType` call → red; (4) change the 409 to a 400 → red.

### 2.7 Out of scope (P5b)

- Editing an existing row's value (delete + re-add covers it; a granular edit endpoint can come
  with real demand).
- Any write-back from a store `isrc` row into `tblSongs.Isrc` — **the authority direction is
  one-way** (tblSongs.Isrc authoritative → store mirrored, §4); a store-only isrc row is a
  legitimate second-recording id, not drift.
- Work/party-scoped rows (reserved slugs — media_identifiers.php:37-42 posture unchanged).
- Surfacing these IDs on the public song page (same follow-up issue family as §1.6).

---

## §3 — P5c: Tune live-search typeahead + `song_tune_set` + retiring the tuneName drift

### 3.1 Current state (file:line) + exact gap

- metadata-tab.js `['tuneName', 'Tune Name', 'TuneName', 'text']` (:40) → debounced
  `metadata_field_update` → generic UPDATE writes `TuneName` alone (api2.php:299, :1154, :1160)
  — **strands `TuneId`** (parent §3B :317 names this the drift to fix). tune.php's ladder
  tolerates it (`TuneId = ? OR TuneName = ?`, tune.php:324) but every such edit de-links the
  registry row and (for a NEW name) creates no registry row at all (tune.php:274-277).
- save_song_core.php UPSERT writes `TuneName` (:246-247 extraction; :582 VALUES-tail; :603/:622
  column lists) with **no TuneId write anywhere in the file** — the v1 whole save has the same
  drift.
- `includes/song_importers.php` universal saver writes `TuneName` (:499) — same drift on import.
- The consumable funnel exists: `tuneFindOrCreateByName()` (tune_helpers.php:177-221; name
  find via `Name = ?` collation fold :188; slug + collision loop :197-209; null on absent table
  :181). works.php:307-313 is the worked call-site: **TuneName + TuneId always in ONE statement.**
- No `tune_search`, no `song_tune_set` (grep, §0-delta (e)). No `ihymns_meter_normalize()`.
- The typeahead donor `js/modules/place-search.js` hardcodes: URL shape
  `endpoint + '?action=search&q=…&limit=…'` (:512), results array `data.results` (:540),
  candidate keys `display_name`/`type`/`address` (:425-436), pick = POST `?action=upsert` then
  `data.place.id` → hidden input (:576-595), "place(s)" strings (:522/:536/:542/:549/:554/:564).
  It is loaded on editor2.php as a classic script (:243) and consumed by metadata-tab.js via
  `window.iHymnsPlaceSearch.attach(pinput, { hiddenIdInput: phidden })` (:310-312).

### 3.2 Server — `tune_search` (GET, api2.php; mirror of `tag_search` :1667-1700)

Params: `q` (string, may be empty ⇒ browse-mode top-N, the tag_search precedent :1673-1680),
`limit` (1..20, default 10), `meter` (optional). Response:
`{ ok:true, suggestions:[{id,name,slug,meterCode,usage,matchedAlias}], tableMissing?:true }`.
- `tuneTunesTableExists()` false → `{ ok:true, suggestions: [], tableMissing: true }`.
- Core query (alias-JOINed so spelling variants surface the CANONICAL tune — the actual dedup
  mechanism, parent §3B :314-316), tblTuneAliases probed separately (memoised
  `ed2_tuneAliasesTableExists()`; absent ⇒ the alias LEFT JOIN + its WHERE leg are omitted):
  ```sql
  SELECT t.Id, t.Name, t.Slug, t.MeterCode,
         COUNT(DISTINCT s.Id) AS UsageCount,
         MIN(CASE WHEN a.Name LIKE ? THEN a.Name END) AS MatchedAlias
    FROM tblTunes t
    LEFT JOIN tblTuneAliases a ON a.TuneId = t.Id
    LEFT JOIN tblSongs s       ON s.TuneId = t.Id
   WHERE (t.Name LIKE ? OR a.Name LIKE ?)
   GROUP BY t.Id, t.Name, t.Slug, t.MeterCode
   ORDER BY UsageCount DESC, t.Name ASC LIMIT ?
  ```
  (all `?` bound; empty-q variant drops the WHERE; `s.TuneId` leg wrapped in its own gate —
  `placeColumnExists($db,'tblSongs','TuneId')`-style probe via a local memoised helper, since
  tblSongs.TuneId is #1090-migration-added; absent ⇒ select `0 AS UsageCount` and no tblSongs
  join). `matchedAlias` is non-null only when the alias (not the canonical name) matched —
  the client renders "also known as …".
- `meter` param: when non-empty, `require_once includes/tune_helpers.php`, fold both sides with
  `ihymns_meter_normalize()` (§3.5) and PHP-filter the fetched rows (fetch `limit`×5 then
  filter+slice — MeterCode is stored display-form, SQL cannot apply the fold; the meter-carrying
  subset of tblTunes is tiny today, §P4c 3.6 dormancy).

### 3.3 Server — `song_tune_set` (POST, api2.php) + the alias branch that retires the drift

1. Extract the shared core as a file-level function (beside the other `ed2_*` helpers):
   ```php
   /** The ONE tblSongs tune write: TuneName + TuneId in lockstep (#1741 P5, parent §3B).
    *  Consumes tuneFindOrCreateByName() (P4b) — never a second lookup fork. */
   function ed2_songTuneApply(\mysqli $db, string $songId, string $rawName): array
   ```
   - `$name = trim($rawName)`; `''` ⇒ both NULL. Cap `mb_substr($name, 0, 120)`
     (tblSongs.TuneName VARCHAR(120), :280 — the same cap tuneFindOrCreateByName applies :185).
   - `require_once includes/tune_helpers.php`; `$tuneId = $name === '' ? null :
     tuneFindOrCreateByName($db, $name)` (null also when tblTunes absent — the documented
     degrade :146-152).
   - Writes: when `tblSongs.TuneId` column exists (memoised probe — the column is
     #1090-migration-added), ONE statement `UPDATE tblSongs SET TuneName = ?, TuneId = ? WHERE
     SongId = ?`; when absent, `UPDATE tblSongs SET TuneName = ? WHERE SongId = ?` (the
     works.php asymmetry, P4 plan §2.5 last row — TuneName-only is permitted ONLY because the
     id column itself does not exist to drift).
   - Returns `['tuneId' => ?int, 'tuneName' => ?string]` plus, when `$tuneId` resolved, the
     row's `slug` + `meterCode` (one extra bound SELECT) so the client can update its meter
     affordance without a second round-trip.
2. **`case 'song_tune_set'`**: `{ songId, tuneName }` → 400 missing songId / key absent
   (`array_key_exists('tuneName', $body)` — empty string is a legal CLEAR, absent is a mistake);
   404 song; transaction { `ed2_songTuneApply()`, `ed2_touchRevision($db,$songId,$ed2UserId,
   'metadata')` }, `logActivity('song.metadata','song',$songId,['field'=>'tune',
   'tuneId'=>$r['tuneId']])`; respond `{ ok:true, field:'tune', tuneId, tuneName, slug, meterCode }`.
3. **Retire-by-alias in `metadata_field_update`** (rule #33 — the field key is a contract for
   SW-stale clients): keep `'tuneName' => ['TuneName','s']` in ED2_META_FIELDS (:299); add,
   directly after the `SongbookAbbr` branch (:1100-1141) and before the generic coercion
   (:1144), a `if ($column === 'TuneName') { … }` branch that runs the SAME transaction body as
   `song_tune_set` (delegating to `ed2_songTuneApply()`) and responds
   `{ ok:true, field:'tuneName', tuneId }`. Remove `'TuneName'` from the generic empty→NULL
   list at :1154 (dead after the branch; leaving it implies the generic path can still run).
   Net effect: NO caller of either action can ever again write TuneName without TuneId.

### 3.4 save_song_core.php + song_importers.php — close the remaining drift funnels

1. **save_song_core** (both v1 api.php and api2 `save_song` :3469-3473 run this): after the
   places gated UPDATE (:643-652), add the mirror-image block:
   ```php
   /* #1741 P5 — TuneName↔TuneId lockstep (parent §3B). The UPSERT above wrote
      TuneName; resolve + write the registry id in the same transaction so a v1
      whole-save can never strand TuneId (the works.php :307 rule, song-side).
      placeColumnExists() is the generic column probe already loaded at :43. */
   if (placeColumnExists($db, 'tblSongs', 'TuneId')) {
       require_once dirname(__DIR__, 2) . '/includes/tune_helpers.php';
       $tuneIdVal = $tuneName === null ? null : tuneFindOrCreateByName($db, $tuneName);
       $tStmt = $db->prepare('UPDATE tblSongs SET TuneId = ? WHERE SongId = ?');
       $tStmt->bind_param('is', $tuneIdVal, $songId);
       $tStmt->execute();
       $tStmt->close();
   }
   ```
   Unguarded inside the transaction (the places-UPDATE posture :643-652 — a genuine failure
   must roll the save back, and `tuneFindOrCreateByName` already degrades to null internally).
2. **song_importers.php** (:499 writes TuneName in the universal saver) — the SAME gated
   TuneId resolve immediately after its tblSongs write, using the same helper. This is a small
   rider completing the funnel set; if the saver's structure makes it non-trivial at build time
   (shared statement across rows), drop the rider and **file a follow-up issue** ("bulk import
   strands TuneId") instead — never half-wire it.

### 3.5 `ihymns_meter_normalize()` — new in includes/tune_helpers.php (parent §3B :321)

```php
const IHYMNS_METER_NAMED = [           // named metres → digit form (central map, rule #20)
    'CM' => '86.86',  'CMD' => '86.86D',  'LM' => '88.88', 'LMD' => '88.88D',
    'SM' => '66.86',  'SMD' => '66.86D',
];
function ihymns_meter_normalize(string $code): string
```
Fold: trim, uppercase, collapse internal whitespace; strip trailing punctuation; map a bare
named key (with optional attached/detached `D`: `"C.M."`→`CM`, `"CM D"`→`CMD`) through
`IHYMNS_METER_NAMED`; otherwise extract the digit runs, join with `.`, and append `D` when a
standalone `D` (or `DOUBLED`) trails — so `"87 87 D"`, `"87.87.D"`, `"8787D"` all → `'87.87D'`,
and `CM` ≡ `86.86`. Returns `''` for input with no digits and no named match (callers treat ''
as "no meter filter"). Pure function, no DB. Consumed by `tune_search`'s meter filter (§3.2)
now and the tune page's "Tunes with this meter" section later (P4c §3.3-6 named this exact
upgrade path).

### 3.6 Client — generalise place-search.js (options; defaults byte-equivalent) + the tune control

**place-search.js changes — additive options only, every default preserving today's behaviour
verbatim** (the module has 2 other live consumers: manage/musicians.php drawers + this tab's
origin picker; `tests/test-place-search-keyboard.js` must stay green untouched):
1. `searchUrl(query, settings)` — function returning the full search URL. DEFAULT:
   `settings.endpoint + '?action=search&q=' + encodeURIComponent(query) + '&limit=' +
   settings.maxResults` (the current :512 line, extracted). The tune caller passes its own
   builder (which also injects `&meter=` when the toggle is on — §below).
2. `parseResults(data)` — maps the fetched JSON to the candidates array. DEFAULT:
   `(data) => Array.isArray(data.results) ? data.results : null` (null keeps the current
   "no data" hint path :540-543). Candidate rendering: the main row keeps `c.display_name`
   (:425); generalise the meta row (:431-436) to render `c.hint` when present, else the
   existing `type`/`address.country` logic — so parseResults maps foreign shapes to
   `{ display_name, hint, id }` and touches nothing else.
3. `pickMode: 'upsert' | 'value'` — DEFAULT `'upsert'` (today's POST-`?action=upsert` →
   `data.place.id` flow :576-601, untouched). `'value'`: `pickCandidate()` performs NO network
   call — sets the input to `c.display_name`, `setHiddenId(String(c.id ?? ''))`, calls
   `settings.onSelect(c)`, `closePanel()`. (The pick's persistence is the CALLER's job — for
   tunes that is the `song_tune_set` POST, which must not be raced by a second implicit upsert.)
4. `noun: {singular:'place', plural:'places'}` — threads through the five user-facing strings
   (:522/:536/:542/:549/:554/:564) and the live-region announcements so the tune instance reads
   "2 tunes found." Defaults keep 'place(s)'.
No other behaviour changes — the #1594 keyboard/ARIA machinery is shape-agnostic already.

**metadata-tab.js — replace the `tuneName` FIELDS row (:40 — DELETE it) with a bespoke control**
rendered after the origin-city picker block (:276-312), mirroring its shape:
- visible `<input id="meta-tuneName">` (value `song.TuneName ?? ''`) + hidden input (value
  `song.TuneId ?? ''`) + a meter badge `<span class="badge bg-body-secondary">` (hidden until a
  meter is known) + a "Matching meter only" `<input type="checkbox">` toggle (hidden until a
  meter is known — the swap-lyrics-between-tunes affordance, parent §3B :320-322).
- attach: `window.iHymnsPlaceSearch.attach(tinput, { hiddenIdInput: thidden, minChars: 2,
  pickMode: 'value', noun: {singular:'tune', plural:'tunes'},
  searchUrl: (q) => '/manage/editor/api2.php?action=tune_search&q=' + encodeURIComponent(q)
      + '&limit=10' + (meterToggle.checked && currentMeter ? '&meter=' + encodeURIComponent(currentMeter) : ''),
  parseResults: (d) => (d.suggestions || []).map(s => ({
      id: s.id, display_name: s.name,
      hint: [s.meterCode ? 'Meter ' + s.meterCode : '', s.matchedAlias ? 'aka ' + s.matchedAlias : '',
             s.usage ? s.usage + ' songs' : ''].filter(Boolean).join(' · '),
      meterCode: s.meterCode || '' })),
  onSelect: (c) => saveTune(c.display_name, c.meterCode) })`.
- **Save semantics — `change` (blur) + pick, deliberately NOT debounced-per-keystroke**: a
  debounced `song_tune_set` would find-or-CREATE a junk tblTunes row per keystroke pause
  ("HYF", "HYFRY", …). A side-effectful write must not fire on a pause — the exact reasoning
  that made `songbook` a change-event select (metadata-tab.js:22-32, #1679 H1). So:
  `tinput.addEventListener('change', () => saveTune(tinput.value, null))` (fires on blur/Enter
  when the value changed) + `onSelect` above; free-typing meanwhile clears the hidden id (the
  module's own :615-620 behaviour, correct here too).
- `saveTune(name, meterFromPick)`: `editorApi.setSongTune(songId, name)` → on success set
  `song.TuneName = res.tuneName; song.TuneId = res.tuneId; thidden.value = res.tuneId ?? '';`
  update `currentMeter = res.meterCode || meterFromPick || null` and show/hide the badge+toggle;
  on failure the standard toast (409 tableMissing never occurs here — `song_tune_set` degrades
  by writing TuneName-only server-side, §3.3-1).
- Meter hydration on mount: when `song.TuneName` non-empty, one
  `editorApi.searchTunes(song.TuneName, 1)` and adopt the exact-name match's `meterCode` (a
  read; cheap; failure silently leaves the toggle hidden).
- api-client.js additions:
  ```js
  searchTunes: (q, limit, meter) => getJson('tune_search',
      Object.assign({ q: q || '', limit: limit || 10 }, meter ? { meter } : {})),
  setSongTune: (songId, tuneName) => postJson('song_tune_set', { songId, tuneName }),
  ```

### 3.7 Existence-gating summary (P5c)

| Read/write | Gate |
|---|---|
| tune_search | `tuneTunesTableExists()` → `tableMissing:true`; alias JOIN gated on its own table probe; UsageCount leg gated on tblSongs.TuneId column probe |
| song_tune_set / metadata_field_update tuneName alias | tblSongs.TuneId column probe → TuneName-only write when absent; `tuneFindOrCreateByName()` self-degrades to null (:181) |
| save_song_core lockstep UPDATE | `placeColumnExists($db,'tblSongs','TuneId')` (:643-652 idiom) |
| song_importers rider | same column probe |
| Client meter toggle | rendered only once a meterCode is actually known (dormant-by-data, like P4c's meter section) |

### 3.8 Guards (rule #34) — one PHP, one node

**`tests/php/test-tune-lockstep.php`** — tree-derived: scan `appWeb/public_html/**/*.php`
(excluding `appWeb/.sql/`) for files containing an SQL WRITE touching TuneName (regex over
source: `INSERT\s+INTO\s+tblSongs[^;]{0,600}\bTuneName\b` or `SET[^;]{0,300}\bTuneName\s*=`,
multiline, window ≥300 chars per the #34 lesson). For EVERY matched file assert it also
references `tuneFindOrCreateByName` (works.php, save_song_core.php, api2.php, song_importers.php
today — the list is derived, never typed; a NEW TuneName writer added later fails until it
adopts the funnel). Further assertions:
- api2.php's `metadata_field_update` region contains a `TuneName` branch that references
  `ed2_songTuneApply` BEFORE the generic `UPDATE tblSongs SET` line, and `'tuneName'` is still
  a key of ED2_META_FIELDS (the rule-#33 alias — deleting the key is the regression);
- api2.php contains `case 'song_tune_set'` and `case 'tune_search'`;
- behavioural asserts on `ihymns_meter_normalize()` (require the file, call it):
  `CM→86.86`, `'87 87 D'→87.87D`, `'8787D'→87.87D`, `''→''`, `'LMD'→88.88D`.
**Mutation checklist:** (1) delete the save_song_core lockstep block → red; (2) revert the
metadata_field_update branch (let tuneName hit the generic UPDATE) → red; (3) break the CM
mapping → red; (4) delete `'tuneName'` from ED2_META_FIELDS → red.

**`tests/test-tune-typeahead-ui.js`** (node) — asserts:
- place-search.js still declares the four new option defaults AND its `DEFAULTS` block is
  unchanged in content (parse the object literal; endpoint/minChars/debounceMs/maxResults
  present with today's values — the defaults-unchanged contract for the 2 other consumers);
- metadata-tab.js does NOT contain a `['tuneName'` FIELDS row (the drift regression) and DOES
  call `setSongTune` and pass `pickMode: 'value'`;
- the `tune_search`/`song_tune_set` literals in api-client.js each have an api2 `case`
  (self-contained re-assert; `test-editor-api2-contract.php` covers it too);
- metadata-tab.js's tune save is wired on `'change'` (regex: `meta-tuneName` block contains
  `addEventListener('change'` and does NOT contain `debouncedSave('tuneName'`).
**Mutation checklist:** (1) re-add the FIELDS row → red; (2) change pickMode to 'upsert' → red;
(3) rename the action literal → red; (4) swap the change-listener to input+debounce → red.
Also run the EXISTING `tests/test-place-search-keyboard.js` before/after — it must stay green
with zero edits (the generalisation's no-behaviour-change proof).

### 3.9 Out of scope (P5c)

- Tune admin CRUD / MeterCode write path (the P4c §3.6 filed follow-up owns it; the meter
  toggle is dormant-by-data until then).
- Adopting the generalised typeahead on works.php's plain `tune_name` text input (P4 plan
  §2.4.2 deliberately shipped plain-text; adopting the module there is a natural follow-up —
  note it in the commit, file under `for consideration`).
- Fuzzy scoring in tune_search (parent §3B: "NO fuzzy scorer in v1" — LIKE + alias JOIN only).
- Emitting registry slugs from song.php's tune links (P4c §3.7 deferred it; the ladder makes it
  unnecessary for correctness).

---

## §4 — P5d: the #1749 dual-write (tblSongs.Isrc → tblSongExternalIds mirror)

### 4.1 Current state + the issue's exact ask

#1749 (verified against the live issue, 2026-08-03): the D5 backfill made the store
comprehensive at a point in time; an ISRC edit then strands the mirror until the card re-runs.
Issue options: (1) dual-write on edit (issue-recommended, "P5 — the natural funnel"),
(2) resolver-union, (3) store becomes single authority. `tblSongs.Isrc` write funnels today:
none in the editors (P5a creates the first), lyrics_ingest.php (:612/:618/:679), the backfill
card. `/isrc/` reads ONLY `tblSongs.Isrc` (identifier_resolve.php:183/:341, `idx_Isrc`).

### 4.2 The mirror helper — NEW `includes/song_external_ids.php`

A small write-helper include (media_identifiers.php stays pure vocabulary — its doc-block
:51-56 scopes it to maps + validators; DB writes get a sibling file, the
tune_helpers/musician_helpers pattern). Contents:

1. `songExternalIdsTableExists(\mysqli $db): bool` — memoised probe (tune_helpers.php:115
   shape). (api2's `ed2_songExternalIdsTableExists()` from §2.2 becomes a thin delegate to this,
   or is simply replaced by requiring this file — ONE probe, not two; decide at build time by
   which lands first, the guard checks the reference not the name.)
2. **`songExternalIdMirrorIsrc(\mysqli $db, string $songId, ?string $canonicalIsrc): void`** —
   the ONE mirror funnel:
   - no-op when the table is absent (probe) — the backfill card remains the catch-all;
   - **ownership model**: the mirror owns AT MOST ONE row per song, identified by
     `SourceRef = 'tblSongs.Isrc'` — the LITERAL the #1747 backfill already writes
     (migrate-backfill-song-external-ids.php:170-172), so backfilled rows and live-mirrored
     rows are the same ownership class. Manual rows (§2.2: `Source='manual'`,
     `SourceRef NULL`) are NEVER touched — a curator-recorded second-recording ISRC survives
     every edit;
   - `DELETE FROM tblSongExternalIds WHERE SongId = ? AND IdType = 'isrc'
      AND SourceRef = 'tblSongs.Isrc'` + (when `$canonicalIsrc` non-null/non-empty)
     `INSERT IGNORE INTO tblSongExternalIds (SongId, IdScope, IdType, IdValue, Source, SourceRef)
      VALUES (?, 'recording', 'isrc', ?, 'ihymns-mirror', 'tblSongs.Isrc')` — INSERT IGNORE so a
     manual row already holding the same value uq-blocks harmlessly (value present either way,
     uq_Song_Type_Value :3450). `Source='ihymns-mirror'` distinguishes live-mirrored from
     backfilled rows in provenance queries; the OWNERSHIP key is SourceRef alone. A NULL/''
     new value = the DELETE only (clearing tblSongs.Isrc clears the owned mirror row).
   - The scope/type literals `'recording'`/`'isrc'` are asserted against the central map by the
     guard (§4.5), not duplicated as a runtime lookup — this helper is itself part of the
     one-vocabulary regime.

### 4.3 Wire-up (the funnels, in P5 scope)

1. **api2 `metadata_field_update` isrc branch** (§1.2-2): after the tblSongs UPDATE, INSIDE the
   same transaction (atomic — a rollback never leaves a half-mirrored pair), call
   `songExternalIdMirrorIsrc($db, $songId, $canon)`. Unswallowed — a genuine failure rolls the
   save back honestly (the table-absent case is already a probe no-op, so the only throwable
   left is a real DB fault).
2. **`ed2_applySongSnapshot()`**: after the scalar loop (:761), when the snapshot carried an
   `Isrc` key AND the field was actually written (not gate-skipped), call the mirror with the
   canonicalised restored value — a revision restore is an Isrc write funnel too.
3. **lyrics_ingest.php (:612/:618/:679) — NOT wired in P5; file the follow-up issue** ("ingest
   writes tblSongs.Isrc without the tblSongExternalIds mirror") at build time. Rationale:
   ingest rows carry their own provenance conventions (Source should arguably be the ingest
   system, not 'ihymns-mirror'), the COALESCE-fill only writes blanks, and the re-runnable
   backfill card covers the gap meanwhile. A one-line wire without deciding the Source
   convention would smuggle a provenance decision — the §2.7-adjacent "verify before filing"
   discipline says issue-first.

### 4.4 Recommendation on the #1749 decision (dual-write vs resolver-union) — SURFACE TO OWNER

**Recommend option (1) dual-write ONLY, exactly as specced above — do NOT build the resolver
union in P5.** (Concurs with the issue's own recommendation.)
- *Why not union now:* `/isrc/` already resolves every value the editors can produce, because
  the editor funnel writes `tblSongs.Isrc` (authoritative) and the mirror follows — a union adds
  a second read-home (`identifier_resolve.php` would need a gated second query + song de-dup)
  whose only new hits are STORE-ONLY isrc rows (a §2 manual second-recording id). That is a
  real-but-tiny cohort with a product question attached (should a second recording's ISRC
  resolve to this song's page?) — the issue itself defers union to "when a non-ISRC
  recording-ID route is first wanted", and no `spotify`/`musicbrainz-recording` route exists in
  `IHYMNS_ID_SCHEMES` (:110-117).
- *Why not option (3):* re-points the resolver, both editors, duplicate-songs (:628/:670),
  build-song-link-suggestions (:122), auto-link-hard-id (:145) and the `v_ChristianSongs` emit
  (schema.sql:3478) — a program, not a P5 item.
- *Cost of doing nothing at all:* the mirror goes stale on first edit and the store's
  "comprehensive" claim silently rots — the exact "longer term" gap the owner named.
- *What we need back:* nothing — #1749 explicitly says "owner call when picked up" but its own
  recommendation matches this spec; proceed on (1), comment the decision + this spec's §4.4 on
  #1749, and leave the union as the issue's remaining open half. **Blocks nothing.**

### 4.5 Guard — `tests/php/test-song-external-id-mirror.php` (rule #34)

Assertions (tree-derived where a list exists):
- `includes/song_external_ids.php` exists; its INSERT literal carries `'recording'`/`'isrc'`
  and both values are asserted PRESENT in media_identifiers.php's parsed
  `SONG_EXTERNAL_ID_SCOPES` / `RECORDING_EXTERNAL_ID_TYPES` keys (cross-file agreement
  mechanised, rule #35);
- the `SourceRef` literal in the helper is BYTE-EQUAL to the one parsed out of
  `appWeb/.sql/migrate-backfill-song-external-ids.php`'s tblSongs INSERT (`'tblSongs.Isrc'`) —
  parse both, compare (a comment saying "keep in sync with the backfill" is the failure mode);
- the helper's DELETE contains `SourceRef = ` (the ownership predicate — a bare
  `IdType='isrc'` DELETE would eat manual rows);
- api2.php's `metadata_field_update` region AND `ed2_applySongSnapshot` region both reference
  `songExternalIdMirrorIsrc`;
- derived write-site sweep: every file under `appWeb/public_html` matching an SQL write of
  `tblSongs`…`Isrc` (same bounded-window regex discipline as §3.8) must reference
  `songExternalIdMirrorIsrc` OR appear in the explicit exempt list
  `['includes/lyrics_ingest.php' /* follow-up issue #… — fill the number at build time */]`
  (an exemption REQUIRES an issue number in the same line; the guard fails on an empty one).
**Mutation checklist:** (1) drop the SourceRef predicate from the DELETE → red; (2) change the
helper's SourceRef literal → red; (3) remove the applySongSnapshot call → red; (4) add a fake
`UPDATE tblSongs SET Isrc` to a scratch include without the helper → red (then delete it).

---

## §5 — Build order + commit shape

**P5a → P5d → P5b → P5c**, one commit each on `claude/wave3-fixes`:
- **P5a first** — smallest server+client delta; creates the isrc branch P5d hooks into and
  establishes the P1-column gate helpers.
- **P5d second** — rides directly on P5a's branch (same file region, same transaction);
  creating `includes/song_external_ids.php` here also hands P5b its table probe. Comment the
  §4.4 decision on #1749 in this commit.
- **P5b third** — independent of P5c; consumes the P5d probe + the media_identifiers
  validators; first UI for the store.
- **P5c last** — the largest and only cross-cutting diff (a shared module used by three
  surfaces + two save cores + two new endpoints); sequencing it last keeps the risky change
  isolated and lets its two guards land beside it.
Independence: P5b ∥ P5c could build in either order (no shared files except api-client.js
method additions); P5a must precede P5d.
Per-commit audit: `php -l` + `node --check` over touched files; run the new guard(s) +
`tests/php/test-editor-api2-contract.php` + `tests/test-place-search-keyboard.js` (P5c); execute
every mutation checklist and restore byte-identical. Standing tasks (issues / CHANGELOG / wiki /
handoff) per `.claude/standing-tasks.md` after each sub-part — including FILING: the §1.6
song-page-surfacing issue, the §4.3 lyrics_ingest mirror issue, and (if the §3.4 rider drops)
the song_importers lockstep issue.

## §6 — P1 gaps + owner items surfaced by this pass

1. **No P1 schema gap**: every column the brief names exists in schema.sql exactly as §2.4
   designed (verified :267/:268/:272/:273/:274/:284). No migration is created anywhere in P5.
2. **#1749 decision** — §4.4: dual-write only, union deferred; proceed without a blocking ask,
   record on the issue.
3. **`Upc` editor field** — optional P5a rider (§1.6); one line each side; flag in the commit.
4. **Public song page renders NONE of the new song fields** (§1.6) — file the follow-up issue;
   without it P5a's fields are curator-visible only.
5. **lyrics_ingest Isrc-mirror wiring** (§4.3-3) — file the follow-up issue (provenance/Source
   convention decision embedded).
6. **works.php typeahead adoption** (§3.9) — `for consideration`.
7. **ISRC strict-shape 422 on the editor field** (§1.2-2) — defensible default, flagged
   trivially loosenable; no owner reply needed.

---

## Adversarial: what would force a rework?

In descending order of realism. (1) **The junk-tune-row hazard is the sharpest design edge**
(§3.6): if a reviewer insists the tune field must autosave like every sibling text field, the
change-event decision flips and `song_tune_set` then NEEDS a `create:false` probe mode to avoid
minting a row per keystroke pause — mitigated by having centralised the write in
`ed2_songTuneApply()` (adding a `$createMissing` flag is a two-line change; the wire contract
gains one optional key). (2) **The mirror ownership model** (§4.2) rests on the backfill's
`SourceRef='tblSongs.Isrc'` literal being the ownership key; if the owner later wants backfilled
rows treated as manual (never auto-replaced), the DELETE predicate narrows to
`Source='ihymns-mirror'` only — one line, and the guard's byte-equality assert is updated with
it, but existing backfilled rows would then duplicate on first edit (old value kept + new value
inserted) — acceptable, not corrupting, and reversible by re-running the card. (3) **place-search
generalisation regressing a place consumer** — the mitigations are structural (all four options
default to today's code paths; `test-place-search-keyboard.js` runs untouched pre/post; the
musicians drawer + origin picker call sites pass no new options), but a subtle behavioural
coupling (e.g. the :526 superseded-request guard interacting with a caller-thrown parseResults)
would surface only in manual testing — smoke both admin surfaces before merge. (4) **tune_search
performance** — the UsageCount LEFT JOIN onto ~14k tblSongs rows per keystroke is indexed
(`idx_TuneId` :316) and bounded by the GROUP-BY on tunes, but if it measures slow the fallback
is dropping UsageCount from the browse-mode query only — the response key stays, ranking
degrades to name order; no contract change. (5) **A stale SW-cached metadata-tab.js posting
`tuneName` through metadata_field_update** is handled by the alias branch (§3.3-3) — but a
stale api-client.js is NOT a thing (same-origin module, no SW under /manage; editor2.php:237-242
documents the cache-bust idiom `?v=filemtime` for classic scripts — apply the same to any new
classic-script load, though P5 adds none). (6) **guards going wrong-but-green** — every guard
here parses bounded source windows (≥300 chars, never "no `>`") and each has a 4-item mutation
checklist; skipping those checklists under time pressure is, per rule #34's history, the single
most likely way this plan fails silently.

# #1748 — Tune admin CRUD: `/manage/tunes` + `manage_tunes` — build spec

**Status:** ready to implement (Sonnet-executable, no re-deciding needed)
**Branch:** `claude/wave3-fixes` · **Parent epic:** #1741 (issue #1748, label `for consideration` —
move to in-progress when picked up)
**Model precedent:** `manage/works.php` (#840 + #1741 P4b) is the page shape; `manage/musicians.php`
(#545/#1741 P2/P4a) is the newest sibling (merge + `validateCsrfRequest()` conventions);
`manage/tags.php` `case 'merge':` (:216) is the merge-modal shape. This spec mirrors those rather
than inventing anything.

---

## §0 Verified current state (every claim re-checked 2026-08-03 against the tree; corrections to the issue/tasking noted ⚠️)

| Claim | Verified | Evidence |
|---|---|---|
| No write path for the tune family exists | ✅ | `manage/tunes.php` absent (`ls manage/`); `grep -r manage_tunes appWeb` → 0 hits. Only writers today: the one-shot backfill `appWeb/.sql/migrate-tunes-entity.php` and `tuneFindOrCreateByName()` (Name+Slug only, `includes/tune_helpers.php:189`). `tblTuneCredits`/`tblTuneAliases`/`tblTuneExternalLinks` have **zero** write sites. |
| All four tables + registry cards exist — **NO schema change needed** | ✅ | `schema.sql`: `tblTunes` :3623-3642 (Name 120 `uq_Name`, Slug 140 `uq_Slug`, Subtitle 255 NULL, Disambiguation 255 `NOT NULL DEFAULT ''`, MeterCode 60 NULL, MusicBrainzWorkMBID 50 `uq_MbWork`, HymnaryTuneId 64 NULL, Notes TEXT); `tblTuneAliases` :3647-3661 (`uq_TuneName(TuneId,Name)`, CASCADE); `tblTuneCredits` :3670-3691 (Role VARCHAR(20) app-validated, Name 255, MusicianId nullable FK SET NULL, no unique key); `tblTuneExternalLinks` :3697-3714 (mirrors `tblWorkExternalLinks`). FKs: `tblSongs.TuneId`/`tblWorks.TuneId` → tblTunes **ON DELETE SET NULL** (:3719-3729). Migration cards already registered: `migration-registry.php` :1922 (tunes-entity), :2019-2040 (tune-enrichment, multi-object OR-probe), :2127 (MusicianId probe). |
| ⚠️ `admin_work_*` API actions **do not exist** | ✅ Corrected | `grep admin_work_ api.php` → 0 hits. The tasking said "mirroring `admin_musician_*`/`admin_work_*`" — only the `admin_musician_*` family exists (`api.php:14923-15674`). §4 mirrors that family alone. |
| ⚠️ `/manage/external-link-types` has **no AppliesTo edit UI** | ✅ Corrected | Its only POST action is `save_type_patterns` (IsActive + pattern rows, `external-link-types.php:66-91`); AppliesTo renders read-only as a badge (:294-296). The issue's "curator ticks `tune` in AppliesTo via manage/external-link-types.php" presumes a tick UI that does not exist. `migrate-tune-enrichment.php:40-42` explicitly deferred adding `'tune'` to any row "as a curator decision for when the tune page's external-links editor ships" — that is **now**, so §5 builds the tick UI. |
| `saveExternalLinksForRow()` whitelist lacks the tune table | ✅ | `includes/external_link_helpers.php:201-206` — `$allowedTables` has songbook/work/song/musician only. §5.1 adds `'tblTuneExternalLinks' => 'TuneId'`. |
| Tune credit Role vocabulary is inlined **twice** with no guard | ✅ | `includes/pages/tune.php:377` (`FIELD(c.Role,'composer','arranger','harmoniser','source')`) and :455-460 (label map). The comment at :368-369 claims "kept in lockstep by the guard's rule-#35 cross-file check" — **no such assertion exists**; `test-tune-lockstep.php` guards TuneName↔TuneId only. §2 centralises the vocabulary; §6 adds the missing check. |
| `manage_works`/`manage_musicians` defaults | ✅ | Both `['admin','global_admin']` — `includes/entitlements.php:194,198`; JS mirror `js/modules/entitlements.js:80,124`. `test-entitlement-parity.php` (behavioural) + `tests/test-entitlement-parity.js` (map diff) enforce the pair. **`manage_tunes` mirrors both exactly.** |
| Nav↔page gate parity is already tree-derived | ✅ | `tests/php/test-admin-gate-parity.php` parses `admin-links.php` rows at run time and asserts each page names its nav entitlement — a new row + page are covered the day they land, no new parity guard needed (its own doc-block, :36-42). §6's new guard covers what it doesn't (ordering, vocab, whitelist, API cases). |
| Chip-list + combobox modules load globally on admin pages | ✅ | `manage/includes/head-libs.php:96-97` ships `external-links-editor.js` + `combobox-a11y.js` to every page using it; the partial is `manage/includes/partials/external-links-section.php` consumed exactly as `works.php:1284-1294` does. |
| Identifier validation registry | ✅ | `mediaIdentifierWorkValidate('musicbrainz-work', …)` (`includes/media_identifiers.php:329-332,402`) — the MBID shape check; a tune **is** a MusicBrainz Work (schema COMMENT :3631). HymnaryTuneId has **no registry entry and no documented shape** → length-check only, the exact BOWI precedent (`WORK_IDENTIFIER_TYPES['bowi']['validate'] === null`, works.php:150-153). No new registry entry; **no inline regex anywhere** (rule #35). |
| `test-tune-lockstep.php` write-site sweep | ✅ | Tree-derived: **every** `appWeb/public_html/**.php` file that writes `tblSongs.TuneName` must reference `tuneFindOrCreateByName` (:387-397, no exempt list). §3's merge/rename cascades write `tblSongs.TuneName` → the new page satisfies the sweep automatically because its `create` action calls the funnel (§3.4). Do not "fix" a red here with an exempt list. |
| Public page consumes everything this CRUD writes | ✅ | `includes/pages/tune.php` — slug-first ladder (:186+; rung (c) resolves `tblTuneAliases` name-folds), credits card (:371-394), meter siblings (:399-414), external-links panel (:417-448). Admin list linking `/tune/<slug>` is rule-#33-safe. |
| ⚠️ Live dev DB **not** reachable from this sandbox | ✅ Corrected | `getDbMysqli()` → "Connection refused" here (same as the #1750 spec noted). The tasking's "dev MySQL is reachable" is wrong for this sandbox; §7.5's live probe runs on an env with DB. Immaterial: every optional-object read below is existence-gated (rules #5/#9). |
| Suites baseline | ⚠️ | 101 PHP test files exist (`ls tests/php/*.php`); the 101/49 pass counts were not re-run in this sandbox (no DB; some suites may still be CLI-cheap). Implementer: run both suites BEFORE touching anything and record the baseline. |

---

## §1 Entitlement + nav + labels (four small edits, all mechanically guarded)

1. **`includes/entitlements.php`** — insert directly after the `manage_works` line (:198):
   ```php
   'manage_tunes'         => ['admin', 'global_admin'],   // #1748 — tune-registry CRUD; mirrors manage_works/manage_musicians exactly
   ```
2. **`js/modules/entitlements.js`** — insert beside `manage_works` (:124):
   `manage_tunes:               ['admin', 'global_admin'],`
   (Both `test-entitlement-parity.php` and `tests/test-entitlement-parity.js` go red if either side is
   missed — the rule-#35 mechanism already exists; do not add a comment telling anyone to sync.)
3. **`manage/entitlements.php`** — append `'manage_tunes'` to the `'Content structure'` group array
   (:89) and add to `$ENTITLEMENT_LABELS` (:111+), beside `manage_works` (:181):
   `'manage_tunes' => ['Manage tunes', 'Edit / merge hymn tunes and their credits, aliases and links (#1748)'],`
4. **`manage/includes/admin-links.php`** — new row directly under `works` (:64), Catalogue group:
   ```php
   ['tunes',                '/manage/tunes',                  'bi-music-note-beamed', 'Tunes',               'manage_tunes',                'Catalogue'  ],
   ```
   Extensionless route needs nothing else (`manage/.htaccess:14` — `/manage/foo` serves `foo.php`).
   `test-admin-gate-parity.php` now automatically requires `manage/tunes.php` to name
   `userHasEntitlement('manage_tunes'` (#1587 — the two gates are the same check by construction).

---

## §2 Centralise the credit-role vocabulary — `includes/tune_helpers.php` (+ refactor `tune.php`)

Add to `tune_helpers.php` (after `IHYMNS_METER_NAMED`, two-register annotated, `@see #1748`):

```php
/* Role => human label, in canonical display order. VARCHAR-not-ENUM vocabulary
   (rule #20) app-validated against THIS one map — the schema COMMENT on
   tblTuneCredits.Role names the same four tokens; tests/php/test-tune-admin-surface.php
   derives the schema list and asserts it equals array_keys() of this const (rule #35:
   a mechanism, not a comment). */
const IHYMNS_TUNE_CREDIT_ROLES = [
    'composer'   => 'Composer',
    'arranger'   => 'Arranger',
    'harmoniser' => 'Harmoniser',
    'source'     => 'Source',
];
```

Also add (same file):

```php
/* Slug collision loop extracted from tuneFindOrCreateByName() (:212-221) so the
   /manage/tunes update path can re-derive a unique slug without re-forking the
   loop (rule #22). $excludeId skips the row's own slug on update. */
function tuneSlugEnsureUnique(\mysqli $db, string $base, ?int $excludeId = null): string
```
— same try-bare-then-`-N` logic, `SELECT 1 FROM tblTunes WHERE Slug = ? [AND Id <> ?]`, suffix
`substr($base,0,134).'-'.$n`. **Refactor `tuneFindOrCreateByName()` to delegate to it** (behaviour
byte-identical; `test-tune-lockstep.php` only asserts references, unaffected — re-run it to prove).

**Refactor `includes/pages/tune.php`** (require of `tune_helpers.php` is already present):
- :377 — build the `FIELD()` list from the const:
  `"ORDER BY FIELD(c.Role, " . implode(', ', array_map(static fn($r) => "'" . $r . "'", array_keys(IHYMNS_TUNE_CREDIT_ROLES))) . "), c.SortOrder, c.Id"`
  (rule-#5 carve-out: the interpolated tokens come from a PHP-source const, never input).
- :455-460 — delete the local `$tuneCreditRoleLabels` map; use `IHYMNS_TUNE_CREDIT_ROLES` at the
  render sites, and fix the :368-369 comment that falsely claims a guard exists (it will be true
  after §6).

---

## §3 The page — `appWeb/public_html/manage/tunes.php` (model: `works.php` end-to-end; ~1,500 lines)

Every block below names its `works.php` anchor. Annotate everything two-register (ELI5 + detailed
why) with `#1748` + the anchor. All admin pages are full documents, NOT SPA fragments — the
`DOMContentLoaded` inline script pattern (works.php:1344-1361 rationale) is correct here; rule #30
does not apply to `/manage/*`.

### §3.1 Bootstrap + gate (works.php:23-60)
Requires: `manage/includes/auth.php`, `includes/db_mysql.php`, `includes/external_link_helpers.php`,
`includes/media_identifiers.php`, `includes/tune_helpers.php`. Gate exactly as works.php:45-54 but
`userHasEntitlement('manage_tunes', …)` and the 403 body says `manage_tunes`. `$activePage = 'tunes';`
**The gate check must precede any output** (§6 asserts position). `$csrf = csrfToken();`

### §3.2 Probes (works.php:90-139 idiom, rules #5/#9 — mysqli is STRICT, migrations are web-run)
- `$hasSchema = tuneTunesTableExists($db)` (reuse the helper — do not re-probe).
- One cached multi-table probe (the works `$worksExtraCols` shape, generalised to TABLE_NAME IN):
  `$tuneTables = ['tblTuneAliases'=>bool,'tblTuneCredits'=>bool,'tblTuneExternalLinks'=>bool,'tblMusicians'=>bool]`
  via one prepared `INFORMATION_SCHEMA.TABLES … TABLE_NAME IN (?,?,?,?)` query, try/catch → all false.
  An install where `migrate-tunes-entity` ran but `migrate-tune-enrichment` didn't has `tblTunes` +
  `tblTuneAliases` only — every sub-panel below gates on its own table, never assumes the batch.
- Column probe for the P1 tblWorks mirror + tblSongs.TuneId: `$hasSongsTuneId`, `$hasWorksTuneCols`
  (INFORMATION_SCHEMA.COLUMNS, same idiom) — the merge/rename cascades in §3.4 gate on these.
- `$hasSchema === false` → the friendly CTA card (works.php:866-879) pointing at
  `/manage/setup-database`, naming `tblTunes` + the tunes-entity card.
- External links: `$linkTypesForTune = $tuneTables['tblTuneExternalLinks'] ? loadExternalLinkTypesFor($db, 'tune') : [];`
  When the array is EMPTY but the table exists, render the §3.6 links panel with an info line:
  *"No link types apply to tunes yet — tick 'tune' on the relevant providers in
  [External-Link Types](/manage/external-link-types)."* (the §5 editor). Never hard-code a provider list.

### §3.3 GET `?action=musician_search` typeahead (works.php:344-395 `song_search` shape)
JSON, `Cache-Control: no-store`, gated inside the page's own entitlement gate. Query
`SELECT Id, Name, Slug FROM tblMusicians WHERE Name LIKE ? ORDER BY Name LIMIT ?` (≤50), bound
params, try/catch → 500 JSON. Only rendered/used when `$tuneTables['tblMusicians']`. Rule #33:
param emitted and consumed by this same page only.

### §3.4 POST actions — **all** gated by `validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))`
(musicians.php:333 precedent; strictly a superset of works.php's `validateCsrf()` — classic form
posts pass via the token route, long-lived-tab AJAX via the same-origin route; rule #29). One
`try/catch (\Throwable)` around the switch → `$error` (works.php:711-714). All multi-table writes
transactional (works.php:587-661).

**Shared validator closure** `$parseTuneFields(array $post): array{0:array,1:?string}`
(the `$parseWorkExtraFields` shape, works.php:159-199 — ONE validation for create+update, rule #35):
- `name` required, `mb_substr(…,0,120)`.
- `slug` optional: if supplied, must match `/^[a-z0-9-]{1,140}$/`; else derived later.
- `subtitle` ≤255 (NULL when ''), `disambiguation` ≤255 (stays `''` — NOT NULL DEFAULT ''),
  `notes` ≤65000 (NULL when '').
- `meter_code` ≤60, stored **raw as typed** — MeterCode is a display column; normalisation
  (`ihymns_meter_normalize()`) is compare-time only (`tune_search`'s filter). Do NOT normalise on store.
- `musicbrainz_work_mbid`: `'' || mediaIdentifierWorkValidate('musicbrainz-work', $v)` else error
  "MusicBrainz Work MBID must look like a UUID (8-4-4-4-12 hex digits)." — **no inline regex**.
- `hymnary_tune_id` ≤64, length-check only (BOWI precedent, §0).

**Uniqueness closure** (the `$checkWorkExtraUniqueness` shape, works.php:213-241): Name, Slug,
MusicBrainzWorkMBID, each `WHERE {$col} = ? [AND Id <> ?]` from a hardcoded triple list.

**`case 'create':`** — validate; check Name/Slug/MBID uniqueness; then
`$newId = tuneFindOrCreateByName($db, $fields['name']);` (the ONE funnel, rule #22 — this reference
is also what keeps `test-tune-lockstep.php`'s derived sweep green for this file, §0) followed by ONE
UPDATE setting Slug (curator-supplied → `tuneSlugEnsureUnique()`d; else keep the funnel's) +
Subtitle/Disambiguation/MeterCode/MusicBrainzWorkMBID/HymnaryTuneId/Notes. `logActivity('tune.create', 'tune', …)`
(works.php's `work.create` key shape).

**`case 'update':`** — validate; uniqueness excluding self; fetch the row's current Name; then in ONE
transaction:
1. `UPDATE tblTunes SET Name=?, Slug=?, Subtitle=?, Disambiguation=?, MeterCode=?, MusicBrainzWorkMBID=?, HymnaryTuneId=?, Notes=? WHERE Id=?`
   (slug: supplied → validated; blank → `tuneSlugEnsureUnique(ihymns_tune_slugify($name), $id)`; a
   form-text warns that changing the slug changes `/tune/<slug>` — old links still resolve via the
   tune.php name/alias ladder, rung (b)/(c)).
2. **Rename cascade (the denorm-mirror lockstep, rule #22/#25 spirit + works.php:251-258's TuneName↔TuneId
   doctrine):** when Name changed — gated `if ($hasSongsTuneId)`:
   `UPDATE tblSongs SET TuneName = ? WHERE TuneId = ?`; gated `if ($hasWorksTuneCols)`:
   `UPDATE tblWorks SET TuneName = ? WHERE TuneId = ?`. The FK makes the cascade precise, which is
   why tunes need **no separate `rename` action** (unlike name-keyed musicians — state this in the
   annotation).
3. Aliases (gated `tblTuneAliases`): delete-then-insert from `alias_names[]` —
   trim, `mb_substr(…,0,120)`, drop empties + duplicates (`array_unique` on the collation-ish
   `mb_strtolower` fold) + any equal to the tune's own Name; `INSERT … (TuneId, Name)`.
4. Credits (gated `tblTuneCredits`): delete-then-insert from parallel arrays
   `credit_roles[]`/`credit_names[]`/`credit_musician_ids[]` — role must be
   `array_key_exists($role, IHYMNS_TUNE_CREDIT_ROLES)` (skip row otherwise), name required ≤255,
   `SortOrder = $idx * 10`; MusicianId: `(int)>0` AND `$tuneTables['tblMusicians']` AND a bound
   `SELECT 1 FROM tblMusicians WHERE Id=?` existence check, else NULL.
5. External links (gated `tblTuneExternalLinks`): `saveExternalLinksForRow($db, 'tblTuneExternalLinks', 'TuneId', $id, $_POST['ext_link_type_ids'] ?? [], …)`
   — requires the §5.1 whitelist widening FIRST (it throws `InvalidArgumentException` otherwise).
6. Commit; `logActivity('tune.edit', …)` with counts.

**`case 'delete':`** — fetch Name + usage counts (`tblSongs WHERE TuneId`, gated; `tblWorks` same);
delete (`aliases/credits/links` CASCADE; `tblSongs.TuneId`/`tblWorks.TuneId` **SET NULL** — songs keep
their free-text TuneName and simply return to the un-curated heuristic state, which is safe and is
why no force-gate is needed; the modal states this). `logActivity('tune.delete', …)`.

**`case 'merge':`** (source → target; the tags.php:216 modal + musicians.php:1365 semantics, adapted
to an FK-keyed entity) — one transaction:
1. Load both rows (`Id, Name`), reject missing / identical.
2. Repoint songs — gated `$hasSongsTuneId`:
   `UPDATE tblSongs SET TuneId = ?, TuneName = ? WHERE TuneId = ?` (target id, **target Name**, source
   id — TuneName and TuneId move in the SAME statement, the works.php:251-258 lockstep). Also catch
   free-text-only citations: `UPDATE tblSongs SET TuneId = ?, TuneName = ? WHERE TuneId IS NULL AND TuneName = ?`
   (source name; collation folds case — mirrors the musician merge's name cascade, musicians.php:1409-1422).
3. Same two statements for `tblWorks`, gated `$hasWorksTuneCols`.
4. Aliases (gated): move source aliases to target skipping any that would violate
   `uq_TuneName(TuneId,Name)` or equal target's Name (`UPDATE IGNORE`-free approach: SELECT source
   alias names, SELECT target's existing set, INSERT the difference, DELETE the rest happens via
   cascade at step 7); then **insert the source's own Name as a target alias** (skip if equal/existing)
   — this is what keeps old `/tune/<sourceSlug-ish>` deep links resolving via ladder rung (c) (rule #33).
5. Credits (gated): move rows whose `(Role, Name)` pair isn't already on the target; leave duplicates
   to cascade.
6. External links (gated): move rows whose `(LinkTypeId, Url)` isn't already on the target.
7. `DELETE FROM tblTunes WHERE Id = <source>` (cascade sweeps the rest).
8. `logActivity('tune.merge', 'tune', (string)$targetId, ['source'=>…, 'target'=>…, 'repointed'=>…])`
   with per-table affected counts (musicians.php:1491-1507 shape).
Merge stays under `manage_tunes` — see §8 defaults.

### §3.5 Read + list (works.php:717-830, 882-983)
One gated SELECT over `tblTunes` with correlated counts (each count sub-select gated on its table via
the probe, else a literal `0 AS …` — rule #5 carve-out, fixed PHP fragments):
`SongCount` (gated `$hasSongsTuneId`), `AliasCount`, `CreditCount`, `LinkCount`. Per-tune child maps
(aliases/credits/links) loaded in three gated bulk queries keyed by TuneId (works.php:775-826 shape;
links join `tblExternalLinkTypes` exactly as :802-809). Row payload JSON → `openTuneEditModal(...)`
via `htmlspecialchars(json_encode(...), ENT_QUOTES)` (works.php:900-931).

Table: `class="table table-sm align-middle cp-sortable mb-0 admin-table-responsive"` (#842/#844),
columns: Name (link `/tune/<Slug>`, `target="_blank"`) `primary`/text · Meter `secondary`/text ·
Songs `primary`/number · Aliases `tertiary`/number · Credits `tertiary`/number · Links
`tertiary`/number · Actions `primary`. Empty-state row. A "Merge tunes" button above the table opens
the merge modal (tags.php:460 placement).

### §3.6 Forms + modals (works.php:985-1335)
- **Create form** (card): Name*, Slug (auto), Meter code, MusicBrainz Work MBID, Hymnary tune id,
  Subtitle, Disambiguation, Notes — with the works.php field/`maxlength` conventions; helper text on
  Meter: "as printed — CM, 87.87 D, 86.86…". Aliases/credits/links: "add via Edit after creating"
  (works.php:1124-1126 precedent).
- **Edit modal** (`modal-xl`): the same fields; then three sub-panels, **each rendered only when its
  table probe is true**:
  - *Aliases* — simple repeated rows: `<input name="alias_names[]" maxlength="120">` + remove button
    + "Add alias" button (client-side row clone; the member-row pattern works.php:1391-1418 minus the
    typeahead).
  - *Credits* — rows of `<select name="credit_roles[]">` (options from
    `IHYMNS_TUNE_CREDIT_ROLES` — PHP-rendered, never a JS literal list), `<input name="credit_names[]" maxlength="255">`,
    hidden `credit_musician_ids[]` + a musician typeahead per row using
    `window.iHymnsComboboxA11y` against §3.3's `musician_search` (the works.php:1432-1552 combobox
    shape; if `tblMusicians` absent the typeahead input is simply not rendered and the hidden id
    posts empty). Every row input gets an `aria-label` naming the row (works.php:1397-1410 a11y note).
  - *External links* — the shared partial, verbatim consumption (works.php:1284-1294):
    `$containerId='edit-tune-ext-links-rows'; $addBtnId='edit-tune-ext-link-add-btn'; … require …partials/external-links-section.php;`
    plus `window._iHymnsLinkTypes = <?= json_encode($linkTypesForTune, …) ?>` (works.php:1337-1340)
    and `window.iHymnsExtLinksEditor.mount(...)` (works.php:1424-1430). **Never fork the row builder.**
- **Delete modal**: shows Name + song/work usage counts + the SET-NULL consequence sentence.
- **Merge modal** (tags.php:630-660 shape): source select + target select (both populated from
  `$rows`), consequence copy ("source's aliases, credits and links move; its name becomes an alias of
  the target; every song and work re-points; the merge is transactional"), submit posts
  `action=merge`.
- Inline page script: ONE `DOMContentLoaded` IIFE with the lazy `ensureModal()` guard
  (works.php:1361-1378 — Bootstrap loads in `admin-footer.php` AFTER this script; do not
  eager-instantiate). `openTuneEditModal`/`openTuneDeleteModal` populate fields + call
  `editor.setRows(...)` for links and rebuild alias/credit rows.
- Head/footer: `head-libs.php` + `head-favicon.php` + `admin-nav.php` + `admin-footer.php` only —
  no bespoke Bootstrap tags (rule #36), no `data-bs-theme` hardcode (rule #16), `<meta name="csrf-token">`.

---

## §4 API — `admin_tune_*` in `api.php` (mirror `admin_musician_*`, corrected per §0)

**Placement:** a new banner-commented block immediately after `case 'admin_musician_delete':`'s
closing brace (api.php:15674), before the ADMIN ANALYTICS banner (:15675).

**Shared core first (rule #35 — one validation, two consumers):** new file
`appWeb/public_html/includes/tune_admin.php` (direct-access 403 guard like `tune_helpers.php:72-75`)
containing the pure/DB cores both surfaces call — `tuneAdminValidateFields(array $in): array{0:array,1:?string}`,
`tuneAdminPersistFields(\mysqli, int $id, array $fields): void`,
`tuneAdminReplaceAliases/Credits(\mysqli, int $id, array $rows): void`,
`tuneAdminMerge(\mysqli, int $sourceId, int $targetId, array $gates): array` (returns the affected-count
summary), `tuneAdminDelete(\mysqli, int $id): array`, `tuneAdminUsageCounts(\mysqli, int $id, array $gates): array`.
§3.4's handlers are thin wrappers over these; the API cases call the SAME functions — the page and
the API cannot drift because there is nothing to drift (contrast: musicians duplicates its logic
between musicians.php and api.php, an acknowledged wart — do not copy that part of the model).

**Four actions** — `admin_tune_add`, `admin_tune_update`, `admin_tune_merge`, `admin_tune_delete`:
- POST-only (405 otherwise), JSON body.
- Gate: `$authUser = getAuthenticatedUser(); if (!$authUser || !userHasEntitlement('manage_tunes', $authUser['Role'] ?? null)) → 403`.
  **Deliberate deviation from `admin_musician_*`'s raw `in_array($Role, ['admin','global_admin'])`:**
  the codebase is migrating role gates to `userHasEntitlement()` (see api.php:8215's own migration
  note and every #1590-era action), and this keeps the API gate identical to the page gate + nav
  entry (#1587's spirit). Since `manage_tunes` defaults to exactly `['admin','global_admin']`, the
  admitted set is identical at ship time.
- Field keys mirror the page's POST names (`name`, `slug`, `subtitle`, `disambiguation`,
  `meter_code`, `musicbrainz_work_mbid`, `hymnary_tune_id`, `notes`, `aliases: [string]`,
  `credits: [{role,name,musician_id?}]`). External links are page-only for now (the API musician
  family precedent covers links, but the chip-list contract is page-shaped; ship without, note in the
  yaml).
- HTTP status IS the contract (rule #35): 400 validation, 403 gate, 404 unknown id, 409 uniqueness
  conflict, 200 ok. Error prose is free to change.
- Activity keys: `api.admin.tune.add|update|merge|delete` (the `api.admin.musician.*` convention,
  api.php:14899-14903).
- **`api-docs.yaml`**: add the four paths (clone the `admin_musician_add` block shape, :9432+).
  `test-openapi-actions-exist.php` then mechanically asserts each documented action exists.

---

## §5 External-links plumbing (two edits)

### §5.1 `includes/external_link_helpers.php` — widen the whitelist (:201-206)
Add `'tblTuneExternalLinks' => 'TuneId',` to `$allowedTables` inside `saveExternalLinksForRow()`
(:201-206) **and** to the identical whitelist inside `loadExternalLinksForRow()` (:276-281 —
verified same shape, same throw). §6 asserts the save-side entry exists. Without this the §3.4 save
throws `InvalidArgumentException`.

### §5.2 `manage/external-link-types.php` — the AppliesTo tick UI (the gap §0 corrected)
- Add to `external_link_helpers.php` a central entity vocabulary (rule #35 — no page-local list):
  ```php
  /* Entity types a link-type can apply to (tblExternalLinkTypes.AppliesTo CSV tokens).
     VARCHAR-not-SET (rule #20, widened by #1741 P1) — growing this is one line here. */
  const IHYMNS_LINK_ENTITY_TYPES = ['song', 'songbook', 'musician', 'work', 'tune'];
  ```
- In each type's `<details>` edit form (beside the existing IsActive checkbox), render one checkbox
  per `IHYMNS_LINK_ENTITY_TYPES` entry, checked via `in_array($tok, explode(',', $t['appliesTo']))`.
- In `case 'save_type_patterns':` (:66-91): read `applies_to[]`, intersect with the const
  (allow-list), **preserve any existing tokens NOT in the const** (the legacy `'person'` back-compat
  token, schema COMMENT :2541 / `migrate-musicians-rename.php:76-80` — dropping it would zero the
  musician editor's type list on a pre-rename install), implode, `UPDATE tblExternalLinkTypes SET AppliesTo = ? WHERE Id = ?`
  bound. If the resulting set would be empty, keep the row's current value and surface a warning
  (never write an empty AppliesTo — it would hide the type from every editor).
- **Seed nothing.** Which providers apply to tunes is the curator decision
  `migrate-tune-enrichment.php:40-42` explicitly reserved — the UI is the deliverable (§8.1).

---

## §6 CI guard — `tests/php/test-tune-admin-surface.php` (tree-derived + mutation-proven, rule #34)

**Model:** copy the pure cores of `tests/php/test-tune-lockstep.php` (slicer `ttlSliceFunction`/
`ttlSliceCase`, `ttlStripComments`) with a **`tas` prefix** (test files are standalone scripts;
prefixes must not collide). Harness auto-discovers by glob (`tools/run-php-tests.php`) — no
registration. No DB. **Every substring assertion runs against comment-stripped source** — a needle
surviving only in an annotation must not satisfy a check.

**Derivations (never hand-typed lists):**
- **D1 — role vocabulary from the schema:** slice `tblTuneCredits`' CREATE TABLE block out of
  `appWeb/.sql/schema.sql`, extract the `Role … COMMENT '…'` pipe-separated tokens
  (`composer | arranger | harmoniser | source`). Then `require tune_helpers.php` and assert the
  token list `=== array_keys(IHYMNS_TUNE_CREDIT_ROLES)` (behavioural, like
  `test-entitlement-parity.php`'s function-call posture — the const is ANSWERED, not grepped).
- **D2 — consumers:** stripped `includes/pages/tune.php` AND `manage/tunes.php` each reference
  `IHYMNS_TUNE_CREDIT_ROLES`; NEITHER contains the raw sequence `'composer', 'arranger'` (the
  re-inlined-list regression).

**Point assertions (each mutation-provable):**
1. Stripped `manage/tunes.php`: `userHasEntitlement('manage_tunes'` appears at an offset BEFORE the
   first `<!DOCTYPE` (the ordering hole `test-admin-gate-parity.php`'s own doc-block admits it does
   not check); `validateCsrfRequest(` present; `validateCsrf(` (bare, non-Request) ABSENT;
   `tuneFindOrCreateByName` present (keeps the lockstep sweep's contract explicit);
   `mediaIdentifierWorkValidate` present; the literal MBID regex fragment `[0-9a-f]{8}-` ABSENT
   (no inline fork of the registry's shape, rule #35).
2. Stripped `external_link_helpers.php`: `saveExternalLinksForRow` slice contains
   `'tblTuneExternalLinks'` AND `'TuneId'`; `IHYMNS_LINK_ENTITY_TYPES` contains `'tune'`.
3. Stripped `api.php`: for EACH of the four action names (list derived by scanning `api-docs.yaml`
   for `admin_tune_` action enums — so documenting a fifth later forces implementing it here too),
   a `case '<name>':` exists and its case slice references `userHasEntitlement('manage_tunes'`.
4. `admin-links.php` (stripped) contains a `'tunes'` row whose entitlement field is
   `'manage_tunes'` (presence; the pairing itself is `test-admin-gate-parity.php`'s job).

**In-memory mutation self-tests (run first, every invocation):** slicer FAILS-HIGH/LOW fixtures;
stripper removes a `/* 'composer' */`-only needle and keeps a code one; D1 extractor fixtures that
fail-high and fail-low (extra token / missing token).

**One-time tree mutation proof (do, record in the PR, restore):** (a) remove `'harmoniser'` from the
const → D1 red; (b) delete the whitelist entry → #2 red; (c) move the `userHasEntitlement` call below
the DOCTYPE in a scratch copy → #1 red. Also prove the EXISTING guards catch their part: comment out
the `manage_tunes` line in `entitlements.js` → `test-entitlement-parity` red; change the nav row's
entitlement to `manage_works` → `test-admin-gate-parity` red.

---

## §7 Verification plan

1. **Syntax:** `php -l` on: `manage/tunes.php`, `includes/tune_admin.php`, `includes/tune_helpers.php`,
   `includes/pages/tune.php`, `includes/external_link_helpers.php`, `manage/external-link-types.php`,
   `includes/entitlements.php`, `manage/entitlements.php`, `manage/includes/admin-links.php`,
   `api.php`, the new test. `node --check js/modules/entitlements.js`.
2. **Suites:** `php tools/run-php-tests.php` (baseline first — expect baseline+1 after) and
   `node tools/run-node-tests.js` (unchanged count). Re-run the sensitive neighbours explicitly:
   `test-tune-lockstep.php` (new TuneName write sites in tunes.php must stay green via the funnel
   reference), `test-admin-gate-parity.php`, `test-entitlement-parity.php`,
   `tests/test-entitlement-parity.js`, `test-openapi-actions-exist.php`, `test-media-identifiers.php`.
3. **Mutation proofs** per §6 (both directions, restored, recorded).
4. **Live behavioural probe** (⚠️ needs an env with DB — `getDbMysqli()` is connection-refused in
   this sandbox, §0):
   - As admin: `/manage/tunes` loads; nav shows "Tunes" under Catalogue. As an `editor`-role user:
     no nav entry AND direct URL → 403 (#1587 both directions).
   - Create tune "SPECTEST" with meter `87.87 D`, an MBID, one alias, one composer credit (typeahead
     against a real musician), then tick `tune` on e.g. the Hymnary provider via
     `/manage/external-link-types` and attach a link. Verify `/tune/spectest` renders header,
     meter badge, credits card, external-links panel (all previously dormant-by-data, tune.php §0).
   - `/tune/<alias-fold>` resolves to the same page (ladder rung (c)).
   - Point a scratch song's TuneName/TuneId at SPECTEST (editor `song_tune_set`), create "SPECTEST B",
     merge B ← SPECTEST: confirm song repointed with TuneName updated in the same row, alias
     "SPECTEST" now on B, old `/tune/spectest` still resolving. Delete B: song's TuneId NULL,
     TuneName text intact.
   - API: `admin_tune_add` with a bad MBID → 400; duplicate name → 409; as a `user`-tier token → 403.
   - Clean up scratch rows.
5. **Standing tasks:** issue #1748 close with SHAs + evidence; api-docs.yaml; wiki
   (Schema/API pages); CHANGELOG; handoff.

---

## §8 Owner decision points (everything else above is a taken, defensible default)

**8.1 Which providers get `tune` ticked in AppliesTo — DEFERRED TO CURATOR (the only real product
question).** Non-blocking. The decision: seed `'tune'` onto a provider set by migration vs. ship the
tick UI and let the curator choose per provider. Options: (a) UI only, seed nothing — **chosen**
(this is exactly the "curator decision" `migrate-tune-enrichment.php` reserved; zero risk of
polluting unrelated editors' dropdowns; cost of nothing = the curator spends two minutes ticking);
(b) seed hymnary/musicbrainz/wikipedia by data migration (faster first-run, but presumes the
curator's taxonomy and needs a registry card). Recommendation: (a). Needed back: nothing — tick at
leisure; say the word if you'd rather have (b) seeded.

**Defaults taken, flagged as trivially changeable (no reply needed):**
- Merge is gated by `manage_tunes` itself, mirroring musicians/tags — NOT a separate destructive
  entitlement like duplicate-songs' `manage_duplicate_songs`. Tune merges are low-blast-radius
  (FK repoint, no row deletion outside the source tune) and the default roles are admin+ anyway.
- Delete needs no force-gate: `ON DELETE SET NULL` means songs degrade gracefully to free-text state.
- Nav icon `bi-music-note-beamed`; entitlement default `['admin','global_admin']` (= manage_works
  AND manage_musicians, which are identical).
- API gate uses `userHasEntitlement('manage_tunes')` rather than the older raw role list (§4).
- API ships without external-link editing (page-only), documented in the yaml.

---

## §9 Files touched (complete list)

| File | Change |
|---|---|
| `appWeb/public_html/manage/tunes.php` | **new** — §3 (~1,500 lines incl. annotations) |
| `appWeb/public_html/includes/tune_admin.php` | **new** — §4 shared cores (~450 lines) |
| `appWeb/public_html/includes/tune_helpers.php` | §2 — `IHYMNS_TUNE_CREDIT_ROLES` + `tuneSlugEnsureUnique()` + funnel delegation |
| `appWeb/public_html/includes/pages/tune.php` | §2 — consume the const at :377 + :455-460; fix the false guard claim at :368 |
| `appWeb/public_html/includes/external_link_helpers.php` | §5.1 whitelist ×2 + `IHYMNS_LINK_ENTITY_TYPES` |
| `appWeb/public_html/manage/external-link-types.php` | §5.2 AppliesTo tick UI + save |
| `appWeb/public_html/includes/entitlements.php` | §1.1 one line |
| `appWeb/public_html/js/modules/entitlements.js` | §1.2 one line |
| `appWeb/public_html/manage/entitlements.php` | §1.3 group + label |
| `appWeb/public_html/manage/includes/admin-links.php` | §1.4 nav row |
| `appWeb/public_html/api.php` | §4 four `admin_tune_*` cases after :15674 |
| `appWeb/public_html/api-docs.yaml` | §4 four paths |
| `tests/php/test-tune-admin-surface.php` | **new** — §6 (~400 lines) |
| GitHub / wiki / CHANGELOG | §7.5 |

**No migration, no `schema.sql` change, no new shared-cache fragment, no router change, no new
public URL param.** All optional-object reads existence-gated (rules #5/#9); all values bound
(rule #5); vocabulary VARCHAR + central const (rule #20); shared modules reused, none forked
(rule #22); cross-file agreements each carry a mechanism (§1 parity suites, §6 D1/D2, §4 shared
core, §6 #3's yaml-derived case list) — never a "keep in sync" comment (rule #35).

# Epic #1741 — P4: per-entity public pages + admin CRUD — BUILD SPEC

> **Status: PLANNED (deep pass, 2026-08-02). Implementation-ready.** Branch `claude/wave3-fixes`,
> same single PR as P1–P3 (owner decision D1, plan §Open-decisions item 1). Parent plan:
> `.claude/catalogue-expansion-1741-plan.md` — §1 (current-state), §2 (the P1 schema this phase
> surfaces), §3C (phase table: P4a/P4b/P4c all depend on P1+P2, all landed; P3 landed
> `dc9b5067`). Every line number below verified by direct read against `claude/wave3-fixes`
> at `fe5bcd1c`.
>
> **Ground-truth deltas vs the parent plan the implementer must know:** (a) the P2 rename is
> DONE — files are `includes/pages/musician.php`, `manage/musicians.php`,
> `includes/musician_helpers.php`; tables are `tblMusicians`/`tblMusicianIdentifiers`/
> `tblMusicianRelations` (compat views carry the old names, schema.sql:2769-2802).
> (b) §2.1 M1's `creditPersonTypeApply()` was **never built** — no `musicianTypeApply` /
> `creditPersonTypeApply` exists anywhere in the tree (grep: 0 hits); P4a builds it.
> (c) `tblWorks.Bowi` shipped in the **D5 batch** (`b428f590`, migrate-work-bowi.php), NOT the
> P1 works-identity batch — it gets its **own** column gate (schema.sql:3044 comment).
>
> **Recommended build order: P4b → P4c → P4a** (rationale in §5). Each sub-phase is one
> reviewable commit. New guard files auto-run: node tests via the `tests/*.js` glob
> (`tools/run-node-tests.js:48-51`), PHP tests via test.yml's `tests/php/*.php` glob
> (test.yml:233) — no runner registration needed (rule #35's npm-vs-CI lesson is already
> mechanised).

---

## §0 — Cross-cutting facts every sub-phase relies on

### 0.1 Caching status per route (rule #6) — state, don't guess

| Page | `$_cacheablePages`? | Evidence |
|---|---|---|
| `musician` (+ `person`/`people` aliases) | **YES — shared-cache** | api.php:586; alias-normalised BEFORE the cache key (api.php:410-421) |
| `writer` | **YES — shared-cache** (until D4 consolidation removes the entry) | api.php:586 |
| `work` | **YES — shared-cache** | api.php:586 |
| `tune` | **NO — uncached** | absent from api.php:584-597; precedent noted at api.php:756-759 |
| `iswc`/`ipi`/`isni`/`ccli`/`bowi`/`isrc` | **NO — uncached, deliberate** | api.php:756-759 comment |

Consequences: musician/work/writer fragments can NEVER be server-personalised and can never
carry a per-request nonce (rule #6/#30). The cache is a content-hash ETag + `max-age=300`
(api.php:798-810), so newly-saved curator fields surface within 5 minutes / on revalidate —
no cache-busting work needed in P4. Do NOT add `tune` or the identifier pages to
`$_cacheablePages` in this phase (explicit precedent, api.php:756-759).

### 0.2 Rule-#30 JS decision, globally

**Every P4 page change is static markup — NO new JS module is needed anywhere in P4.**
The one interactive affordance on these pages, the admin Edit-button reveal, already exists
as a router-side hook (`router.js:792`, `#btn-edit-musician`) — the correct
cached-fragment pattern (client-side reveal, server re-checks on the target page,
musician.php:444-455). All new sections (chips, credits, relations, meter list) are plain
anchors with `data-navigate`. The only `<script>` in any touched fragment stays the inert
`application/ld+json` block in musician.php:730-733 (exempt in
`tests/php/test-fragment-inline-scripts.php`, per rule #30). Admin-page JS (works edit
modal, musicians drawer) is NOT fragment JS — those are full admin pages where inline
scripts are the established pattern (works.php:906-1163).

### 0.3 Existence-gating idioms to copy (rule #5 / mysqli STRICT)

Migrations are web-run, three docroots share one MySQL — a P1/D5 column is NOT guaranteed
present. The repo's four established idioms, cited so the implementer copies rather than
reinvents:

1. **Conditional select fragment** — musician.php:51-56 (`$mbidCol` probe →
   `{$mbidCol}` interpolation of a hardcoded constant; rule #5's carve-out).
2. **Cached table probe** — SongData.php:4700-4721 (`_hasWorksSchemaCache`).
3. **Column-gated separate UPDATE on save** — works.php:253-262 and :346-355
   (`placeColumnExists($db,'tblWorks','OriginCityId')` → second UPDATE, main bind untouched).
4. **Gated helper family** — musician_helpers.php's `musicianSlugColumnExists()` /
   `musicianFlagsColumnsExist()` / `musicianMembersTableExists()` (used at :1578-1579,
   :1652, :1667), each cached per-request.

Every page-load read wraps in `try/catch` → themed `renderErrorFragment()` fallback
(musician.php:45-79, work.php:29-46, tune.php:38-93 already do; keep that shape).

### 0.4 Shared extraction REQUIRED before new copies appear (modularity rule)

The categorised external-links panel is currently inlined **twice** — musician.php:564-616
(with its `$pCatLabels` map :572-583) and work.php:190-221 (with `$wCatLabels` :70-81).
P4c would add a third copy for tunes. **P4b extracts it first**: new partial
`appWeb/public_html/includes/partials/external-links-panel.php` taking `$panelLinks`
(the unified shape: slug/name/category/url/note/verified/iconClass — exactly what
`SongData::getWork()` :5052-5061 and musician.php:259-280 already build), `$panelHeading`
(e.g. "Find this work elsewhere") and `$panelAriaLabel`. One category-label map lives in
the partial. work.php (P4b) and tune.php (P4c) consume it; musician.php converts in P4a.
No behaviour change — byte-similar output, one source.

### 0.5 Who links here (rule #33 grep results — the contracts P4 must not break)

- `/tune/<slug>` is emitted with a **name-fold slug** (`strtolower(preg_replace('/[^A-Za-z0-9]+/','-',$tuneName))`)
  from song.php:719-731 AND song.php:1258-1272 (two inline copies). `tblTunes.Slug` from the
  backfill uses `_migTunes_slugify()` (migrate-tunes-entity.php:106-113) which iconv-transliterates
  first and appends `-N` collision suffixes — **the two folds diverge** for accented names and
  collisions. P4c MUST therefore keep resolving name-fold slugs (see P4c §3.3 lookup ladder).
- `/work/<slug>` emitters: works.php admin list :639, song.php's "Part of work" panel,
  identifier.php:145 "Linked Work", work.php's own parent/children links — all slug-only, no
  params; P4b's render changes break none.
- `/musician/<slug>` emitters: song.php:700 + :1252 (name-fold), musician.php members :473,
  songbook.php compiled-by, identifier.php:165. musician.php already handles both registry
  slugs and name-fold slugs (fallback :81-84).
- `/writer/<slug>` emitters: **only** sitemap.xml.php:151-160 (name-fold slugs derived from
  song credits) + external bookmarks. The `writer` page reads `?id=` (api.php:671), the
  `musician` page reads `?slug=` (api.php:692) — the D4 alias MUST translate the param
  (see P4a §1.6).
- `/manage/musicians?id=<Id>` is deep-linked from musician.php:451 and handled
  (manage/musicians.php:3049) — unchanged.
- `/manage/works` has no inbound deep-link params other than its own
  `?action=song_search` fetch (works.php:1016) — P4b is free to extend the form.

---

## §1 — P4a: Musician profile page (+ the write paths that make it real)

### 1.1 Current state (file:line) + exact gaps

**Public page** `appWeb/public_html/includes/pages/musician.php` (737 lines):
- Registry select :58-65 reads `Id, Name, Notes, BirthPlace, BirthDate, DeathPlace,
  DeathDate, IsSpecialCase, IsGroup` + gated `MusicBrainzArtistMBID` (:51-56). **Does NOT
  read `Type`, `Biography`, `Disambiguation`** (grep: zero hits in the file), though all
  three exist in schema (schema.sql:544, :555, :545) — P1 shipped them dormant.
- Bio card :552-560 renders **`Notes`** as the public "About" text — the exact read-path
  switch plan §2.1 M2 deferred to P4a (schema.sql:555 COMMENT documents this).
- Classification icon ladder :380-386 keys on the legacy `IsGroup`/`IsSpecialCase` flags;
  lifespan wording :303-319 keys on `IsGroup`.
- Members card :459-483 renders name+slug only; `loadMusicianGroupMembers()`
  (musician_helpers.php:1576-1596) selects **no** `RelationType`/`DateFrom`/`DateTo`/`Note`
  (columns exist, schema.sql:2715-2720) — no "portrayed-by" anywhere.
- Identifier chips :485-549: registry-derived map (:504-516), IPI/ipi-base/CAE appended
  unlinked (:514-516). ISNI links OUT to isni.org (musician_helpers.php:125); **nothing links
  to the P3 `/ipi/` `/isni/` routes** (grep `/ipi/` in musician.php: 0 hits).
- JSON-LD :714-733 — no `disambiguatingDescription`.

**Admin CRUD** `appWeb/public_html/manage/musicians.php` (4,434 lines):
- `add` (:514) / `update_person` (:793-912) read `is_special_case`/`is_group` checkboxes
  (:821-823) and write the flags directly (:879-903). **No `type`/`biography`/
  `disambiguation` POST fields anywhere** (grep: 0 hits). No `musicianTypeApply()` funnel
  exists (grep across tree: 0 hits) — §2.1 M1's "flags become derived mirrors" is unstarted.
- Members endpoints `add_member`/`remove_member` (:327-395) → `addMusicianGroupMember()`
  (musician_helpers.php:1650-1679, requires `IsGroup=1` subject :1677) — **no
  `relation_type`, no dates, no note** params.

**Writer page** `appWeb/public_html/includes/pages/writer.php` (224 lines): heuristic
name-variant match via `getSongsByCreditName()` (:38; sole caller of
SongData.php:2293), writer+composer roles only, cacheable, `?id=` param. Duplicate of
musician.php's broader fallback — the D4 consolidation target.

**Gap list:** (1) Type vocabulary not read/written; (2) Biography read-path switch +
Notes→internal repurpose not done; (3) Disambiguation not read/written; (4) relations
(portrays + dates) have no read, write, or UI; (5) ipi/isni chips don't cross-link to the
P3 routes; (6) writer/musician page duplication (D4); (7) flags have no single write funnel.

### 1.2 Precise changes — P4a-1: write paths (helpers + admin)

`includes/musician_helpers.php`:
1. `const MUSICIAN_TYPES = ['person'=>'Person','group'=>'Group / collective',
   'character'=>'Character / persona','orchestra'=>'Orchestra / ensemble','other'=>'Other / special']`
   — THE central map (schema.sql:544 vocabulary; VARCHAR-not-ENUM, rule #20). Plus
   `const MUSICIAN_RELATION_TYPES = ['member'=>'Member of','portrays'=>'Portrays']`
   (schema.sql:2715).
2. `musicianProfileColumnsExist(\mysqli $db): array` — ONE cached
   INFORMATION_SCHEMA.COLUMNS query for `('Type','Biography','Disambiguation')` on
   `tblMusicians`, returning a per-column bool map (per-column, not all-or-nothing —
   partial applies are possible; mirrors the cached-probe idiom §0.3.4).
3. `musicianRelationColumnsExist(\mysqli $db): array` — same, for
   `('RelationType','DateFrom','DateFromPrecision','DateTo','DateToPrecision','Note')` on
   `tblMusicianRelations`.
4. **`musicianTypeApply(\mysqli $db, int $id, string $type): bool`** — the ONE write funnel
   (§2.1 M1): validates against `MUSICIAN_TYPES`; writes `Type` (when column present) AND
   the derived mirrors `IsGroup = (int)in_array($type,['group','orchestra'],true)`,
   `IsSpecialCase = (int)in_array($type,['character','other'],true)` (mapping fixed by
   schema.sql:539-544 COMMENT). When the Type column is absent it writes the flags only.
   **From this commit on, NOTHING else writes `IsGroup`/`IsSpecialCase`** — the guard
   (§1.5) enforces it; the direct writes at manage/musicians.php:879-903 are rewired
   through this funnel.
5. `addMusicianRelation(\mysqli $db, int $subjectId, int $objectId, string $relationType,
   ?string $dateFrom, ?string $dateFromPrec, ?string $dateTo, ?string $dateToPrec,
   ?string $note): array` — generalisation of `addMusicianGroupMember()` (:1650), which
   becomes a thin `'member'`-typed wrapper (keeps its IsGroup-subject guard :1677;
   `'portrays'` skips that guard, keeps the self-relation guard :1658). Column-gated: on an
   install without `RelationType` the function only accepts `'member'` and writes the
   legacy column set. **Direction semantics — record verbatim in the doc-block:** the
   relation verb reads FROM subject TO object, matching `member` ("subject group
   HAS-MEMBER object person", schema.sql:2709-2710): for `portrays`, **Subject = the
   portrayer (actor), Object = the portrayed figure**. A character page's "Portrayed by"
   section therefore queries `ObjectMusicianId = <this row> AND RelationType='portrays'`.
6. `loadMusicianGroupMembers()` / `...Bulk()` (:1576-1631): gated select extension —
   when `RelationType` exists, also select
   `RelationType, DateFrom, DateFromPrecision, DateTo, DateToPrecision, Note` and filter
   `WHERE ... AND m.RelationType = 'member'` (so a portrays row never renders as a band
   member); absent → current shape with `'relationType'=>'member'` defaults.
7. NEW `loadMusicianPortrayedBy(\mysqli $db, int $id): array` (subjects where
   `ObjectMusicianId=? AND RelationType='portrays'`, join `tblMusicians` for
   name+slug+dates) and `loadMusicianPortrays(\mysqli $db, int $id): array` (the inverse).
   Both return `[]` when `musicianRelationColumnsExist()` lacks `RelationType`.

`manage/musicians.php`:
8. Drawer: when `Type` column exists, REPLACE the two checkboxes
   (`is_special_case`/`is_group`) with ONE `<select name="type">` built from
   `MUSICIAN_TYPES` (pre-filled from the row's Type — P1 backfilled it); when absent, keep
   the checkboxes (existing gated branches :879-903 already handle that install state).
   Handlers `add`/`update_person`: map the posted `type` through `musicianTypeApply()`
   AFTER the main INSERT/UPDATE; when only checkboxes posted (old install), synthesise
   `$type` from them (group→'group', special→'other', else 'person') and still call the
   funnel — one write path either way.
9. `Biography` (textarea, label "Biography (public)") + `Disambiguation` (input,
   maxlength 255) fields in the drawer; relabel the existing Notes field
   "Notes (internal — not shown publicly)". Persist BOTH via a **separate column-gated
   UPDATE** after the main statement (§0.3.3 pattern — do NOT multiply the existing
   three-way bind branches at :879-912).
10. Members panel: when `musicianRelationColumnsExist()['RelationType']`, add per-add-row
    optional inputs `relation_type` (select from `MUSICIAN_RELATION_TYPES`), `date_from`,
    `date_to` (parsed via the existing `partialDateParse()` — manage/musicians.php:805),
    `note`; POST `add_member` grows those params and delegates to
    `addMusicianRelation()`. Additionally a **"Portrayed by" sub-panel** rendered when the
    row's resolved type is `character` or `other`: same person-picker, submits new POST
    `action=add_relation` with `subject_id=<picked>`, `object_id=<this row>`,
    `relation_type=portrays` (+ dates/note); `remove_member` generalises to
    `action=remove_relation` by relation Id (keep `remove_member` as an alias —
    rule #33, links/JS outlive code). These endpoints return JSON like the existing
    :327-395 pair and are same-origin POSTs from the drawer's JS — use
    `validateCsrfRequest()` (rule #29) exactly as the existing member endpoints do
    (verify at build time; if they currently use `validateCsrf()` on a baked token,
    upgrade them in the same commit — they are long-lived-page AJAX, the precise
    rule-#29 failure class).
11. `logActivity` payloads for add/update gain `type`, `has_biography`,
    `disambiguation`; relation endpoints log `relation_type`+dates.

### 1.3 Precise changes — P4a-2: public read (musician.php)

1. Extend the gated select :51-65: from `musicianProfileColumnsExist()` build
   `$extraCols` appending `, Type` / `, Biography` / `, Disambiguation` per present column
   (hardcoded constants — rule #5 carve-out, same as `$mbidCol`).
2. **Bio switch** (§2.1 M2): `$bioText = trim((string)($person['Biography'] ?? ''));
   if ($bioText === '') { /* notes-as-bio-fallback */ $bioText = trim((string)$person['Notes']); }`
   — render `$bioText` in the About card (:552-560). The literal marker comment
   `notes-as-bio-fallback` MUST appear on the fallback branch (the guard greps for it,
   and it mirrors the `lines-json-fallback` convention, rule #25). Once Biography is
   non-empty, Notes never renders publicly.
3. **Type presentation** replacing the icon ladder :380-386 (fallback to flags when Type
   absent): `person`→`fa-user-pen`, no badge; `group`→`fa-users`, badge "Group";
   `orchestra`→`fa-users-line`, badge "Orchestra"; `character`→`fa-masks-theater`, badge
   "Character"; `other`→`fa-circle-question`, italic name (current special-case styling
   :387). Badges are `<span class="badge bg-body-secondary">` with the label INSIDE the
   `<h1>`'s flex row, folded into the accessible name (the #1223 badge-a11y pattern).
   Lifespan wording (:303-319): treat `group|orchestra` as the "Active/Founded" branch —
   compute `$isGroupish` from Type when present, else `IsGroup`.
4. **Disambiguation**: `<small class="text-muted fw-normal">(<?= … ?>)</small>` directly
   after the name span in the h1 (:387); add `'disambiguatingDescription'` to the JSON-LD
   array (:720-725) when non-empty.
5. **Portrayed-by / Portrays cards** (after the Members card :459-483, same card markup):
   "Portrayed by" from `loadMusicianPortrayedBy()`; "Portrays" from
   `loadMusicianPortrays()`. Each entry: linked name (slug → `/musician/<slug>`
   `data-navigate="musician"`, else plain text — the members' slug-or-plain fallback
   :472-478) + a date range formatted per precision (year-only when precision `year`;
   en-dash; open-ended → "since YYYY") + the note in `<small class="text-muted">`.
   Members card entries likewise append their date range when present.
6. **Identifier chips → P3 routes**: in the chip loop :534-545, derive internal
   routability from the registry — `require_once identifier_normalize.php`;
   `$internal = array_key_exists($type, IHYMNS_ID_SCHEMES) &&
   IHYMNS_ID_SCHEMES[$type]['entity'] === 'musician'` (identifier_normalize.php:110-116;
   today ipi+isni — tree-derived, NO second hardcoded list, rule #35). When `$internal`:
   the VALUE anchors to `/<type>/<rawurlencode(value)>` with `data-navigate="<type>"`
   `class="song-meta-link"` title "See everyone sharing this <label>"; any authority URL
   (ISNI's isni.org, :125) moves to a trailing
   `<a … target="_blank" rel="noopener nofollow external"><i class="fa-solid
   fa-arrow-up-right-from-square"…>` icon with an aria-label. Non-internal types keep
   today's behaviour exactly (authority link or unlinked — IPN/ipi-base/CAE stay plain,
   musician_helpers.php:151, musician.php:514-516).
7. Convert the external-links panel :564-616 to the shared partial (§0.4).

### 1.4 P4a-3: writer consolidation (D4-gated slice — build to the DEFAULT)

**D4 default (recommended, flagged trivially-changeable): consolidate; `/writer/` survives
as an alias.** If the owner reverses, drop ONLY this slice — nothing in §1.2/§1.3 touches it.

1. api.php:419-421 alias block extends:
   `if ($page === 'writer') { $page = 'musician'; if (trim((string)($_GET['slug'] ?? '')) === '')
   { $_GET['slug'] = trim((string)($_GET['id'] ?? '')); } }` — **the `id`→`slug` param
   translation is the load-bearing line** (writer passes `?id=`, api.php:671; musician reads
   `?slug=`, api.php:692 — rule #33). Placed in the same pre-cache-key block so old and new
   URLs share cache semantics (api.php:410-418 comment).
2. Delete `case 'writer'` (api.php:669-678), delete `includes/pages/writer.php`, remove
   `'writer'` from `$_cacheablePages` (api.php:586 — dead after normalisation).
3. router.js:318-319: `case 'writer':` joins the alias group — return
   `{ page: 'musician', params: { slug: segments[1] || '' } }` (the people/person shape
   :320-331). Remove the `'writer'` DOCUMENT_TITLES entry (:649) — the page value can no
   longer be `writer`.
4. sitemap.xml.php:151-160: emit `/musician/<slug>` instead of `/writer/<slug>` (same
   name-fold slugs; musician.php's fallback :81-84 renders them identically to today's
   writer page, but with the full six-role discography instead of writer+composer only —
   an accepted, strictly-broader behaviour change).
5. `SongData::getSongsByCreditName()` (SongData.php:2293) loses its only caller — re-grep
   at build time; if still zero external callers, delete it (a whole-ish-scan read path
   nobody uses is rule-#17-adjacent debt).
6. NO server 301 in this phase — the bot/301 layer is deliberately P6
   (parent plan §4 "Deliberately OUT of P3"; same posture as the person/people aliases).
   Update musician.php's stale doc-comment :19-23 which still describes the
   /writer/ fallback as a separate page.

### 1.5 Guard — `tests/php/test-musician-profile-fields.php` (rule #34)

Tree-derived inputs: slice schema.sql's `CREATE TABLE IF NOT EXISTS tblMusicians` block
(:522-585, anchor by table name) and extract column names whose declaration line contains
`#1741 P1` → currently `Type`, `Disambiguation`, `Biography`; slice `tblMusicianRelations`
(:2707-2741) the same way → `RelationType`, `DateFrom`, `DateTo`, `Note`, precisions.
Assertions:
- every derived tblMusicians column name appears in BOTH `includes/pages/musician.php`
  and `manage/musicians.php`;
- `musician.php` contains the literal `notes-as-bio-fallback` marker AND calls
  `musicianProfileColumnsExist`;
- `RelationType` appears in `musician_helpers.php`'s loader region AND
  `manage/musicians.php`;
- **flag-funnel ban**: regex `/SET\s[^;]{0,300}\b(IsGroup|IsSpecialCase)\s*=/s` over all
  `appWeb/public_html/**/*.php` EXCLUDING `includes/musician_helpers.php` and
  `appWeb/.sql/` must match **zero** times (count-exact; SELECTs don't match — only
  UPDATE SET clauses);
- chips: `musician.php` must reference `IHYMNS_ID_SCHEMES` (the tree-derived internal-route
  source) — a hand-typed `['ipi','isni']` list is the regression;
- D4 slice (assert only when `includes/pages/writer.php` is absent, so the guard doesn't
  pre-fail if the owner reverses D4): writer.php gone; api.php's alias block contains
  `'writer'`; router.js's `case 'writer'` returns page `musician`; sitemap.xml.php
  contains `/musician/` and NOT `/writer/`.
**Mutation-test checklist (run before merge, restore byte-identical after):** (1) delete
`, Biography` from musician.php's extra-cols builder → red; (2) delete the
`notes-as-bio-fallback` comment → red; (3) re-add a raw `SET IsGroup = 1` to
manage/musicians.php → red; (4) retype the chips gate as a hardcoded array → red;
(5) restore writer.php as an empty file → the D4 assertions must SKIP (not fail).

### 1.6 Existence-gating summary (P4a)

| Read/write | Gate |
|---|---|
| musician.php select of Type/Biography/Disambiguation | `musicianProfileColumnsExist()` per-column map → conditional select fragment (§0.3.1) |
| Bio render | falls back to Notes when Biography absent OR empty (`notes-as-bio-fallback`) |
| Relations dates/type (read + write) | `musicianRelationColumnsExist()`; absent → legacy member-only shape |
| Drawer Type select vs checkboxes | `musicianProfileColumnsExist()['Type']` |
| Admin writes of the three new columns | separate column-gated UPDATE (§0.3.3), never added to the main bind |
| Chips internal-route derivation | none needed — identifier_normalize.php is code, not schema |

### 1.7 Out of scope for P4a (state in the commit message)

- Wikipedia bio fallback (parent §1 gap) — file a follow-up issue; app-logic feature, unscheduled.
- Credits FK-ify (D3) — profile discography stays name-matched (musician.php:182-205);
  the reserved `MusicianId` columns absorb either answer.
- An `/ipn/` route — IPN is not in `IHYMNS_ID_SCHEMES`; adding a scheme is a P3-shaped
  change, not a page change.
- Any server 301 for `/writer/` or `/person/` (P6).
- Apple `CanonicalURL` / AASA changes (P2 already froze the wire contract).

---

## §2 — P4b: Work page + admin CRUD

### 2.1 Current state (file:line) + exact gaps

**Read path** `SongData::getWork()` (SongData.php:4901-5071): selects only
`Id, ParentWorkId, Title, Slug, Iswc, Notes, CreatedAt, UpdatedAt` (:4923, :4930) —
**none of** `Ccli`/`Bowi`/`Subtitle`/`Disambiguation`/`TuneName`/`TuneId`/
`FirstPublishedYear`/`CopyrightYears`/`CopyrightHolder` (all present in schema,
schema.sql:3043-3051) — and **no credits of any kind** (parent §1: "getWork returns no
credits"). Returns parent/children/members/links.

**Public page** `includes/pages/work.php` (224 lines): lone ISWC rendered as an UNLINKED
`<code>` (:89-92); **no breadcrumb at all**; no CCLI/BOWI/tune/subtitle/year/copyright/
credits; list-groups lack `role="list"` (:126, :165); external-links panel inlined
(:190-221, §0.4).

**Admin CRUD** `manage/works.php` (1,187 lines), gate `manage_works` (:44; nav parity
manage/includes/admin-links.php:64, entitlements.php:198): create/update handle only
`title/slug/iswc/notes/parent_id/origin_city(+id)` (:206-273, :275-414); the ISWC fold
already delegates to the shared `ihymns_canonical_iswc()` (:80-82, P3). **No field for any
new column, and none for the pre-existing `MusicBrainzWorkMBID`** (#1066, schema.sql:3036).

### 2.2 Precise changes — read path (SongData.php)

1. New cached probe `private function _worksExtraCols(): array` (property
   `?array $_worksExtraColsCache`, idiom §0.3.2): ONE INFORMATION_SCHEMA.COLUMNS query
   `WHERE TABLE_NAME='tblWorks' AND COLUMN_NAME IN
   ('Ccli','Bowi','Subtitle','Disambiguation','TuneName','TuneId','FirstPublishedYear',
   'CopyrightYears','CopyrightHolder')` → present-set. (Per-column because Bowi ships in a
   DIFFERENT migration than the other eight — schema.sql:3044 vs :3043-3051.)
2. `getWork()` main select (:4923/:4930) appends a fragment built from the present-set
   (hardcoded constant names, rule #5). Output keys added to the array :4941-4954, with
   absent-column defaults so work.php renders shape-blind:
   `'ccli'=>'','bowi'=>'','subtitle'=>'','disambiguation'=>'','tuneName'=>'',
   'tuneId'=>null,'firstPublishedYear'=>null,'copyrightYears'=>'','copyrightHolder'=>'',
   'tune'=>null,'credits'=>[]`.
3. Tune link resolution: when `tuneId` non-null AND tblTunes exists (try/catch probe),
   `SELECT Name, Slug FROM tblTunes WHERE Id = ?` → `'tune'=>['name'=>…,'slug'=>…]`.
4. **Aggregated credits from member songs** (the only data source — there is NO
   tblWorkCredits; §2.7 escape-hatch was accepted-not-built): for the member SongIds
   already collected (:5011-5021), query each of `tblSongWriters`/`tblSongComposers`/
   `tblSongArrangers` with `SELECT DISTINCT Name FROM <t> WHERE SongId IN (<?,?,…>)`
   (placeholders via `array_fill` — rule #5), each in its own try/catch (tables are old
   and near-certain, but STRICT-safe costs nothing). Output
   `'credits'=>['writers'=>[names],'composers'=>[…],'arrangers'=>[…]]`, each list sorted,
   capped at 50. Skip entirely when the work has no members.

### 2.3 Precise changes — public page (work.php)

1. **Breadcrumb** (new, before the header): `<nav aria-label="Breadcrumb" class="mb-3">`
   Home (`data-navigate="home"`) › work title — the identifier.php:116-124 shape.
2. Header (:86-97): h1 keeps the title; append
   `<small class="text-muted fw-normal">(disambiguation)</small>` when set; a muted
   subtitle line under the h1 when `subtitle` set.
3. **Identifier chips row** replacing :89-92 — one flex row, `.song-meta-link` styling
   (rule #18), each chip `<strong>LABEL:</strong> <a>` matching song.php's ISWC chip
   (:864-872): ISWC → `href="/iswc/<?= rawurlencode($work['iswc']) ?>"`
   `data-navigate="iswc"`; CCLI → `/ccli/…` `data-navigate="ccli"`; BOWI → `/bowi/…`
   `data-navigate="bowi"`. Each rendered only when non-empty. (Internal routes, NOT
   SongSelect — the /ccli/ page itself offers the work+song cross-links; note song.php's
   CCLI chip still links out to SongSelect :851 — leave it, out of scope.)
4. **Tune row** in the header meta: `Tune: <a href="/tune/<slug>" data-navigate="tune">NAME</a>`
   using `$work['tune']['slug']` when present, else the name-fold slug of
   `$work['tuneName']` (the song.php:719 fold) — else omitted.
5. **Publication/copyright line** in the header meta block (:88-96): "First published
   YYYY" when set; `© <CopyrightYears> <CopyrightHolder>` when either set (years-only and
   holder-only both render sensibly).
6. **"Writers & composers" section** (after the notes card :112-118): three sub-groups
   (Words / Music / Arrangement) from `$work['credits']`, each name linking
   `/musician/<?= rawurlencode(strtolower(str_replace(' ','-',$name))) ?>`
   `data-navigate="musician"` (the song.php:700 fold — name-strings, D3 caveat inherited).
   Hidden when all three lists are empty.
7. a11y: add `role="list"` to the children (:126) and members (:165) `.list-group`s and
   `role="listitem"` on their anchors (identifier.php:162-172 convention); the existing
   single `<h1>` stays the only h1.
8. External-links panel → the NEW shared partial (§0.4 — extraction happens in this
   commit; musician.php converts in P4a).

### 2.4 Precise changes — admin CRUD (manage/works.php)

1. New shared validator in `includes/media_identifiers.php`:
   `mediaIdentifierWorkValidate(string $slug, string $value): bool` (mirrors
   `mediaIdentifierValidateValue()` :273-280, reads `WORK_IDENTIFIER_TYPES[$slug]['validate']`
   :295-323 — CCLI `/^\d+$/`, BOWI null=any-non-empty). works.php calls it — NO inline
   regex (rule #35: the vocabulary already exists; a re-typed regex is the drift).
2. New form fields (create :691-754 and edit modal :756-872, same names):
   `subtitle` (maxlength 255), `disambiguation` (255), `ccli` (50, numeric),
   `bowi` (30), `tune_name` (120, plain text input this phase — the typeahead is P5 §3B),
   `first_published_year` (4-digit), `copyright_years` (100), `copyright_holder` (255).
   Edit-modal prefill: extend `$rowPayload` (:618-629) + `openWorkEditModal()` (:1124-1155)
   with the new keys; extend the read SELECT (:472-477) with a gated fragment built the
   same way as `$placeSelect` (:468-471), emitting `NULL AS Subtitle` etc. when absent.
3. Handler additions (both `create` :206 and `update` :275):
   - validate: `ccli` via `mediaIdentifierWorkValidate('ccli',…)` (empty allowed);
     `bowi` trimmed ≤30; `first_published_year` empty→NULL else int in 500..2100 range
     (schema is SMALLINT UNSIGNED — schema.sql:3049 — and hymn works predate MySQL YEAR);
   - uniqueness: `ccli`/`bowi` each checked against `uq_ccli`/`uq_bowi` excluding self
     (mirror the ISWC check :309-316); store **NULL not ''** (`NULLIF` bind or null var —
     schema.sql:3043-3044's NULL-coexistence design);
   - persistence: leave the existing INSERT/UPDATE binds UNTOUCHED; write all new fields
     via ONE separate column-gated UPDATE whose SET list is built from the present-column
     map (the OriginCityId pattern :253-262 / :346-355, generalised — probe once per
     request, reuse for create+update);
   - **TuneName→TuneId lockstep**: NEW `includes/tune_helpers.php` (the file parent §3B
     already earmarks) with `tuneFindOrCreateByName(\mysqli $db, string $name): ?int` —
     returns null for empty name or absent tblTunes (table probe); finds by
     `Name = ?` (CI collation does the folding, migrate-tunes-entity.php:196-212);
     else INSERTs with `ihymns_tune_slugify()` (the `_migTunes_slugify` fold
     :106-113 re-homed as THE app-side fold + the collision-suffix loop :225-231; the
     migration's private copy stays — one-shot script, not a live fork) — and works.php
     writes `TuneName` + `TuneId` **together** in the gated UPDATE (writing the name
     without the id recreates the exact drift §3B's `song_tune_set` exists to kill; P5's
     editor funnel will consume this same helper — one funnel, built once).
   - `logActivity` payloads (:265-270, :402-411) gain the new fields.
4. CSRF: works.php uses classic full-page form POSTs with `validateCsrf()` (:172) — NOT
   the long-lived-AJAX class rule #29 targets; no change.
5. **Optional-but-recommended rider** (flag in the commit, trivially droppable):
   a `musicbrainz_work_mbid` field with `mediaIdentifierWorkValidate('musicbrainz-work',…)`
   + `uq_mbwork` uniqueness — the #1066 column (schema.sql:3036) has NEVER been editable;
   we are already inside both forms. Not in the P4b brief's field list, so it is a rider,
   not scope.

### 2.5 Existence-gating summary (P4b)

| Read/write | Gate |
|---|---|
| `getWork()` new columns | `_worksExtraCols()` cached per-column present-set → select fragment |
| `getWork()` tune name/slug | tuneId non-null + try/catch on tblTunes select |
| `getWork()` credits | per-table try/catch (existing tables; belt-and-braces) |
| works.php list SELECT | gated fragment with `NULL AS <col>` fallbacks (:468-471 pattern) |
| works.php saves | ONE separate gated UPDATE from the present-column map; Bowi gated independently of the P1 eight |
| `tuneFindOrCreateByName()` | tblTunes table probe → null (TuneName still written alone in that case is FORBIDDEN — when the table is absent the gated UPDATE simply omits TuneId and writes TuneName only if its column exists; document the asymmetry inline) |

### 2.6 Guard — `tests/php/test-work-identity-fields.php` (rule #34)

Tree-derived input: slice schema.sql's `tblWorks` block (:3032-3074, anchor by name),
extract columns tagged `#1741 P1` or `#1741 D5` in their comment → currently
`Ccli, Bowi, Subtitle, Disambiguation, TuneName, TuneId, FirstPublishedYear,
CopyrightYears, CopyrightHolder`. Assertions:
- every derived column appears in SongData.php's `getWork` region (slice from
  `function getWork` to the next `function `) AND in `manage/works.php`;
- work.php contains render keys for the display subset
  (`subtitle`,`disambiguation`,`ccli`,`bowi`,`tuneName`,`firstPublishedYear`,
  `copyrightYears`,`copyrightHolder` — `TuneId` is exempt, it surfaces as the `tune` link);
- work.php contains `/iswc/`, `/ccli/`, `/bowi/` hrefs each with a `data-navigate`
  attribute in the same anchor tag (bounded regex — heed the #34 lesson: bound the
  window generously, ~300 chars, and never anchor on "no `>`");
- work.php contains `aria-label="Breadcrumb"` and `role="list"`;
- manage/works.php contains `mediaIdentifierWorkValidate` (the no-inline-regex mechanism)
  and `tuneFindOrCreateByName`;
- the shared partial `includes/partials/external-links-panel.php` exists AND work.php
  requires it AND work.php no longer contains its own `$wCatLabels` map.
**Mutation checklist:** (1) drop `Subtitle` from `_worksExtraCols()`'s IN-list → red;
(2) remove the `/ccli/` chip from work.php → red; (3) replace the validator call with an
inline `preg_match('/^\d+$/'` → red; (4) re-inline `$wCatLabels` in work.php → red;
(5) confirm the guard STAYS GREEN on the untouched pre-P4b tree ONLY for assertions that
should pass there (it won't — it's a new-feature guard; first run happens post-implementation,
so mutation-testing is the only proof it can fail: do all four).

### 2.7 Out of scope for P4b

- A `tblWorkCredits` curator-credit table (accepted escape hatch, parent §2.7) — credits
  are aggregated from member songs only.
- Editor create-work-on-ISWC/CCLI-entry (parent §1 Works gap) — P5/§3B territory.
- The tune typeahead input (P5 — this phase ships the plain text input + server lockstep).
- Work JSON-LD (`MusicComposition`) — nice-to-have; not in the brief; file under
  `for consideration` if desired.
- song.php's outbound SongSelect CCLI chip (:851) — separate consistency question.

---

## §3 — P4c: Tune page keyed on the registry

### 3.1 Current state (file:line) + exact gaps

`includes/pages/tune.php` (165 lines): pulls `DISTINCT TuneName` from tblSongs (:47-54),
PHP-folds each name and matches the URL slug (:57-63), then lists songs
`WHERE s.TuneName = ?` (:66-75). **Zero contact with tblTunes** (its own doc-block :18-20
says the registry would make it "a single SELECT keyed on slug"). No Subtitle/
Disambiguation/MeterCode/credits/links/aliases; breadcrumb aria-label is lowercase
`"breadcrumb"` (:96); list-group lacks `role="list"` (:144).

**Confirmed:** there is **NO** `manage/tunes.php` (manage/ dir listing), **NO**
`manage_tunes` entitlement (grep across *.php/*.js: 0 hits; entitlements.php has only
`manage_works`:198 / `manage_musicians`:194 in this family), and the only tblTunes reader
anywhere is `getSongDetailExtras()`'s dormant JOIN (SongData.php:2573). tblTunes data
exists only from the #1090 backfill (migrate-tunes-entity.php:195-243); `MeterCode`,
`Subtitle`, `Disambiguation`, `tblTuneCredits`, `tblTuneExternalLinks` have **no write
path at all** — see §3.6.

### 3.2 Registry columns available (exact, from schema.sql)

`tblTunes` (:3623-3641): `Id, Name(uq), Slug(uq), Subtitle(#1741 P1), Disambiguation
(#1741 P1), MeterCode(+idx_Meter :3639), MusicBrainzWorkMBID(uq), HymnaryTuneId, Notes`.
`tblTuneAliases` (:3647-3659): `TuneId, Name` (idx_Name). `tblTuneCredits` (:3670-3689):
`TuneId, Role('composer|arranger|harmoniser|source' :3673), Name, MusicianId(dormant FK),
SortOrder`. `tblTuneExternalLinks` (:3697-3715): mirrors tblWorkExternalLinks.
Note tblTunes+tblTuneAliases predate P1 (#1090) — **`Subtitle`/`Disambiguation` can be
absent while the table exists** (P1's migrate-tune-enrichment card un-run): column-gate
them separately from the table probe.

### 3.3 Precise changes — tune.php rewrite (registry-first, heuristic-preserved)

Keep the file's overall shape (empty-slug / not-found / found branches :106-165). New
resolution ladder, each step try/catch-wrapped, falling through on failure:

1. **Probe** tblTunes (INFORMATION_SCHEMA, local try/catch — the works.php:86-96 idiom)
   → `$hasTuneRegistry`. Column-probe `Subtitle`,`Disambiguation` (one query) →
   `$tuneExtraCols` select fragment (`NULL AS Subtitle,…` when absent). Probe
   tblTuneAliases / tblTuneCredits / tblTuneExternalLinks tables individually.
2. **Lookup ladder** (registry row `$tuneRow`, when `$hasTuneRegistry`):
   a. `SELECT Id, Name, Slug{extras}, MeterCode, MusicBrainzWorkMBID, HymnaryTuneId, Notes
      FROM tblTunes WHERE Slug = ? LIMIT 1` (the backfill slug);
   b. else pull `SELECT Id, Name, Slug FROM tblTunes` (small — one row per distinct tune)
      and match `nameFold(Name) === $tuneSlug` in PHP, where `nameFold` is the EXISTING
      :58 fold — this is what keeps every song.php:719/:1261-emitted name-fold slug
      resolving (rule #33, §0.5); re-select the full row by Id on match;
   c. else (tblTuneAliases present) the same PHP-fold match over
      `SELECT a.Name AS Alias, t.Id FROM tblTuneAliases a JOIN tblTunes t ON t.Id=a.TuneId`
      → canonical tune (the alias table is the spelling-variant mechanism, parent §3B);
   d. **no registry row (or no registry at all) → the CURRENT heuristic verbatim**
      (:47-63) — mandatory: a TuneName typed post-backfill via `metadata_field_update`
      creates NO registry row until P5's `song_tune_set` lands, and those songs' tune
      links must keep working. Mark the branch with a literal comment
      `tune-registry-fallback` (guard token, §3.5).
3. **Song list**: registry path → `WHERE (s.TuneId = ? OR s.TuneName = ?)` +
   `songVisibleSql` (:70-72 shape; the OR covers post-backfill rows whose TuneId was
   never linked — migrate-tunes-entity.php:237-241 only links once); heuristic path
   unchanged (`s.TuneName = ?`).
4. **Header**: h1 = Name (+ `(Disambiguation)` small, + Subtitle line — the §1.3.4
   conventions); a meta row with: `Meter: <MeterCode>` as a
   `<span class="badge bg-body-secondary">` when set; MusicBrainzWorkMBID chip linking
   `https://musicbrainz.org/work/<mbid>` (the well-known MusicBrainz per-entity shape —
   the repo already templates the sibling `musicbrainz.org/recording/%s` /
   `musicbrainz.org/artist/%s` forms at media_identifiers.php:190 and musician.php:520;
   note `WORK_IDENTIFIER_TYPES` itself carries NO url key, so hardcode the template at
   the render site like musician.php:520 does) with `rel="noopener nofollow external"`;
   HymnaryTuneId as an
   **unlinked** chip (no independently-confirmed URL template — the D5 "do NOT invent
   deep-link shapes" posture, media_identifiers.php:143-151; matches the IPI/IPN
   unlinked-chip precedent musician.php:514-516). Notes render in a card like
   work.php:112-118 when non-empty.
5. **Credits card** (tblTuneCredits present + rows exist):
   `SELECT c.Role, c.Name, m.Slug AS MusicianSlug FROM tblTuneCredits c LEFT JOIN
   tblMusicians m ON m.Id = c.MusicianId WHERE c.TuneId = ? ORDER BY FIELD(c.Role,
   'composer','arranger','harmoniser','source'), c.SortOrder, c.Id` — grouped by Role
   with labels Composer/Arranger/Harmoniser/Source; each name links to
   `/musician/<MusicianSlug>` when the dormant FK is set, else the name-fold
   (song.php:700 fold). (`FIELD()` args are hardcoded constants — rule #5.)
6. **"Tunes with this meter" section** (registry row + MeterCode non-empty):
   `SELECT Name, Slug FROM tblTunes WHERE MeterCode = ? AND Id <> ? ORDER BY Name
   LIMIT 100` (rides idx_Meter :3639) → a wrap of chip links `/tune/<Slug>`
   `data-navigate="tune"`, with a one-line explainer ("Lyrics written in this meter can be
   sung to any of these tunes" — the swap-lyrics affordance, parent §3B). **Exact-match
   v1**: `ihymns_meter_normalize()` (CM ≡ 86.86) is parent-§3B/P5 work in
   tune_helpers.php; nothing writes MeterCode yet (§3.6) so the section is dormant-by-data
   — correct and cheap now, upgraded matching later.
7. **External-links panel** (tblTuneExternalLinks present): the query shape of
   `getWork()`:5038-5047 retargeted (`el.TuneId = ?`, JOIN tblExternalLinkTypes,
   `COALESCE(t.IsActive,1)=1`), rendered via the P4b shared partial (§0.4).
8. a11y: breadcrumb `aria-label="Breadcrumb"` (normalise :96's lowercase); `role="list"`
   on the list-group (:144) + `role="listitem"` on rows; single h1 unchanged (:127).
9. Caching + JS: stays **uncached** (§0.1); pure static markup, NO module (§0.2).

### 3.4 Existence-gating summary (P4c)

| Read | Gate |
|---|---|
| Registry lookup / meter section | tblTunes table probe; whole ladder try/catch → heuristic |
| Subtitle/Disambiguation | column probe → `NULL AS` fragment (table may pre-date P1) |
| Aliases step | tblTuneAliases table probe |
| Credits card | tblTuneCredits table probe; LEFT JOIN tolerates absent tblMusicians rows |
| Links panel | tblTuneExternalLinks table probe |
| Song list by TuneId | only on the registry path; heuristic path never references TuneId (column is #1090-gated on tblSongs) |

### 3.5 Guard — `tests/php/test-tune-registry-page.php` (rule #34)

Tree-derived input: slice schema.sql's `tblTunes` block (:3623-3641) → assert the columns
this page renders (`Slug`,`MeterCode`,`Subtitle`,`Disambiguation`,`Notes`) exist in the
block, then assert each appears in tune.php; extract the `Role` vocabulary from
tblTuneCredits' COMMENT (:3673) and assert tune.php's `FIELD(` ordering names exactly
that set (cross-file agreement mechanised — rule #35). Further assertions:
- tune.php contains `FROM tblTunes` + a `Slug = ?` prepare (registry-first);
- tune.php contains the literal `tune-registry-fallback` marker AND the
  `DISTINCT TuneName` query (the preserved heuristic — deleting it silently kills every
  song.php-emitted link whose tune has no registry row);
- tune.php contains `TuneId = ? OR` in the song-list prepare (the never-linked-row net);
- tune.php requires the shared external-links partial, contains NO local category-label map;
- `aria-label="Breadcrumb"` + `role="list"` present; NO executable inline `<script>`
  (already CI-enforced by test-fragment-inline-scripts.php — do not duplicate, just rely on it);
- zero occurrences of `manage_tunes` anywhere under appWeb (guards against a phantom
  entitlement being invented ahead of the §3.6 decision).
**Mutation checklist:** (1) delete the heuristic branch → red; (2) change `Slug = ?` to
`Name = ?` → red; (3) reorder/retype the `FIELD()` roles → red; (4) drop `role="list"` → red.

### 3.6 The tune-admin hole — follow-up issue to FILE (not a P1 schema gap)

Confirmed zero write path for `MeterCode`, `Subtitle`, `Disambiguation`, `tblTuneCredits`,
`tblTuneExternalLinks`, `tblTuneAliases` (and no `manage_tunes` entitlement, no nav
entry). This is **deliberate P1 dormancy plus an unassigned program gap**, not a schema
gap: P5 (§3C) covers only the song-editor typeahead + `song_tune_set`, NOT tune-entity
curation. **Action for the implementer: file one GitHub issue under epic #1741**
("Tune admin CRUD — /manage/tunes + manage_tunes entitlement") covering: list+edit page
modelled on works.php (fields: Name/Slug/Subtitle/Disambiguation/MeterCode/MBID/
HymnaryTuneId/Notes; credits card-list; external links via the shared editor module,
rule #15/#12 — which also needs curators to tick `tune` in `tblExternalLinkTypes.AppliesTo`
on relevant types via manage/external-link-types.php, enabled by the P1 SET→VARCHAR
conversion); entitlement line in entitlements.php + admin-links.php entry (gate parity —
#1587 red flag); merge/alias tooling later. Until it lands, P4c's new sections are
dormant-by-data — they render only when rows exist (import/DB-seeded), which is the
rule-#20 shipping posture, and the page's core value (registry-keyed lookup + meter
section + preserved links) does not depend on it.

### 3.7 Out of scope for P4c

- Tune admin CRUD (§3.6 — filed, not built).
- `tune_search` api2 action + editor typeahead + `song_tune_set` (P5, parent §3B).
- `ihymns_meter_normalize()` matching (P5; exact-match v1 here).
- Changing song.php:719/:1261 to emit registry slugs (needs the song payload to carry the
  tune slug — revisit with P5; the P4c ladder makes it unnecessary for correctness).
- Adding `tune` to `$_cacheablePages` (explicit precedent, §0.1).

---

## §4 — P1-gaps and owner items surfaced by this pass

1. **No `musicianTypeApply()` exists** (parent §2.1 M1 promised it "Phase 3"; the P3 that
   landed was re-scoped to identifiers) — not a schema gap; P4a builds it (§1.2.4). No new
   migration needed (rule #19/#20 respected: zero schema changes anywhere in P4).
2. **`MusicBrainzWorkMBID` has never been curator-editable** (#1066 column,
   schema.sql:3036; absent from works.php) — adjacent gap; P4b carries it as an optional
   rider (§2.4.5). Not P1's fault; note in the P4b commit.
3. **Tune-entity curation is entirely write-path-less** (§3.6) — file the follow-up issue.
   The `AppliesTo` `tune` token exists in the column type (P1's SET→VARCHAR) but no
   registry row is marked for tunes yet — curator action via the existing
   manage/external-link-types.php once the admin page exists.
4. **Wikipedia bio fallback** (parent §1 Musicians gap) — never scheduled; file under
   `for consideration`.
5. **D4 (owner)** — the only decision in P4; §1.4 builds the recommended default
   (consolidate; `/writer/` alias survives, no 301 until P6), isolated so a reversal
   drops one slice. Non-blocking for P4b/P4c and for P4a-1/-2.
6. No genuinely missing column was found: every field the brief names exists in
   schema.sql exactly as §2 designed it (verified against :522-585, :2707-2741,
   :3032-3074, :3623-3715).

## §5 — Build order + commit shape

**P4b → P4c → P4a**, one commit each on `claude/wave3-fixes`:
- **P4b first**: zero owner gates; creates the two shared artefacts the others consume
  (`includes/partials/external-links-panel.php` §0.4; `includes/tune_helpers.php` with
  `tuneFindOrCreateByName()`/`ihymns_tune_slugify()` §2.4.3) and establishes the
  gated-select pattern at its cleanest site.
- **P4c second**: consumes both shared artefacts; page-only; its guard locks the
  heuristic-preservation contract before anything else touches tune links.
- **P4a last**: the largest diff (public page + admin drawer + relations + helpers) and
  the only owner-gated slice (D4) — sequencing it last maximises the confirmation window
  at zero cost; if D4 is still unanswered at build time, ship P4a-1/-2 and hold ONLY
  P4a-3 (it is a self-contained final commit).
Per-commit audit: `php -l` + `node --check` over touched files, run the new guard +
`test-fragment-inline-scripts.php` + `test-identifier-routes.js`, and execute each guard's
mutation checklist (restore byte-identical). Standing tasks (issues/CHANGELOG/wiki/
handoff) per `.claude/standing-tasks.md` after each sub-phase.

---

## Adversarial: what would force a rework?

The plan's soft spots, in descending order of realism. (1) **The `portrays` direction**
(§1.2.5: Subject=portrayer, Object=portrayed) is my reading of schema.sql:2695-2715's
comment, not an owner-confirmed semantic — if the owner meant the reverse, every query and
the admin sub-panel flip; mitigated by centralising the direction in exactly two helpers +
one doc-block, and by the fact that no data exists yet, so a flip is a two-line change
with no migration. (2) **D4 reversal** would strand §1.4 — contained by design (isolated
slice, guard skips when writer.php exists). (3) **`tuneFindOrCreateByName()` in P4b could
collide with P5's `song_tune_set` design** if P5 wants different find semantics (e.g.
alias-aware matching) — mitigated by putting it in tune_helpers.php as the declared shared
funnel now (P5 extends, never forks), but if P5 needs a different signature the works.php
call site changes too. (4) **Structural guards asserting "column name appears in file"**
can go green on a wrong-but-present reference (the rule-#34 under-report class) — the
mutation checklists are the real proof; skipping them under time pressure is how these
guards join the repo's wrong-but-green history. (5) **The aggregated work credits (§2.2.4)
scan three credit tables per work render on an uncached-until-ETag route** — fine at
current member counts (works have single-digit members), but a 200-member mega-work would
make this the page's dominant cost; if that ever materialises the fix is a bounded LIMIT +
"and others", not a schema change. (6) If a docroot has the **P1 batch partially applied**
(a manually-run half-migration), per-column gating saves the reads but the works save-path
writes only the present subset silently — acceptable (the migration card stays pending,
rule #19's probe design), but worth one line in the commit message so a curator's
"I saved a subtitle and it vanished" report is diagnosable in seconds.

# Epic #1741 — Catalogue data-model expansion — PLAN

> **Status: PLANNING (deep analysis + design, pre-implementation).** Multi-session epic.
> The owner's issue #1741 says this must **NOT** be bundled into the pre-merge `claude/wave3-fixes`
> branch — implementation lands on its own branch, its own program. This doc is the durable design
> record (survives context compaction); it lives in `.claude/` per the `*-plan.md` convention.
>
> **Process (owner-mandated):** deep analysis + planning via **sequential (not parallel) Fable 5
> agents**, falling back to Opus 4.8 (never Opus 5), retrying Fable next phase. Implementation later
> via Sonnet/Haiku. GIRFT.
>
> **Scope (issue #1741 + owner clarifications):** MusicBrainz-shaped rework of Musicians (rename from
> People/Credit People — owner explicitly agreed to a FULL schema+route+log-key rename, 2026-08-02,
> not just UI copy), Works (decoupled from songs, ISWC→CCLI→internal precedence, nesting), Tunes
> (first-class, meter-matching, live-search), Songs (ISRC/subtitle/disambiguation/publication-year/
> copyright-split), and shared alias-URL routing (`/musician/ /ipi/ /isni/ /iswc/ /ccli/ /isrc/`).
> Recommended order (issue): schema (one pass) → resolver/alias infra → per-entity pages → editor
> fields → docs/API.

---

## Open decisions for the owner (surface AFTER planning completes — none block planning)

1. **#1741 branch base** — branch off `alpha` (clean, but lacks the held wave3-fixes catalogue work
   like #960 credits / #1608 song-links) vs off `claude/wave3-fixes` (has that work, but stacks on
   unmerged history). Leaning: off `wave3-fixes` IF it merges first; else off `alpha` and rebase.
2. **Musicians full-rename confirmation** — owner agreed in principle; the measured blast radius
   (~1,100 appWeb + 116 external occurrences, 8 FK tables, shipped Apple API contract) makes this the
   riskiest single change in the epic. Confirm scope: does the rename include the **Apple/Android**
   API response keys (needs response-key aliases to avoid breaking shipped native clients) or stop at
   the web tables/routes/log-keys?
3. **Credits: name-strings vs FK-ify** — the six per-role credit tables store `Name VARCHAR(255)` with
   deliberately no FK to `tblCreditPeople`. The Musician profile's "credited songs by role" inherits
   name-collision behaviour. Does #1741 also FK-ify credits, or stay name-match? (Issue is silent.)
4. **`writer` vs `person` page consolidation** — two public pages for the same concept today
   (heuristic `writer.php` bare list vs registry `person.php`). The spec's "profile page replaces the
   bare song list" implies consolidation. Confirm.

---

## §1 — Current-state analysis (Phase 1, Fable 5, 2026-08-02) — GROUND TRUTH

All line numbers verified by direct read against `claude/wave3-fixes`.

### 1. Musicians (People / Credit People)
**Exists:** `tblCreditPeople` (schema.sql:510-565) — Id, Name(UNIQUE), Slug(UNIQUE, `/people/<slug>`),
MusicBrainzArtistMBID, `IsSpecialCase`/`IsGroup` TINYINT flags, FirstNames/Surname/Suffix/MaidenSurname,
`Notes TEXT` (**rendered as the bio** on person.php:548), birth/death place+id+date+precision.
Satellites: `tblCreditPersonIdentifiers` (**IPI/ISNI already modelled, `IdentifierType VARCHAR(20)`**,
schema.sql:634-651), `tblCreditPersonExternalLinks`, `tblCreditPersonLinks` (legacy), `tblCreditPersonIPI`
(legacy rollback snapshot :607), `tblCreditPersonAliases` (has a grandfathered `Type ENUM`, :2650),
`tblCreditPersonMembers` (**no RelationType, no dates**, :2686). Registry `CREDIT_IDENTIFIER_TYPES`
in `includes/credit_people_helpers.php:117-131`. Routes: page `case 'person'` (api.php:667) + separate
name-slug `case 'writer'` (api.php:656 → writer.php heuristic name-match); both cacheable. JSON:
`action=credit_person`, `action=person_by_identifier` (api.php:1280, **dormant, type+value→people**),
admin `admin_credit_person_add/update/rename/merge/delete`. CRUD: `manage/credit-people.php` (4,431
lines, gate `manage_credit_people`). Public `person.php` (733 lines) renders bio/aliases/roles/members/
identifier-chips/links/discography (6 name-matched credit tables)/JSON-LD. Editor: v2 Credits tab +
`credit_search`/`credit_upsert` (#960).
**Gaps:** entity-type vocab (only 2 TINYINT flags); dedicated Biography + Wikipedia fallback (none);
disambiguation (none); "Portrayed by"+dates (members table has neither); `/musician/`+`/ipi/`+`/isni/`
routes (none; server lookup building block = `person_by_identifier`).
**Rename blast radius** (grep, appWeb): tblCreditPeople 340/34, tblCreditPerson* satellites 209/21,
CreditPersonId 221/22, credit-people 165/48, credit_person 71/6, credit_people 49/21, creditPerson
(camelCase) 24/9, manage_credit_people 13/8, admin.credit* log keys 14/2 (**two families:
`admin.credit_people.*` + `admin.credit_person.*`**). Outside appWeb: 116/… incl. Apple `CreditPerson*.swift`,
`DeepLink.swift`, `CanonicalURL.swift`. `CreditPersonId` FKs on **8 tables** incl. `tblSongbookCompilers`
+ `tblVocalParts` (issue comment omitted these). `'person'` is a member of `tblExternalLinkTypes.AppliesTo
SET('song','songbook','person','work')` (schema.sql:2523). AASA claims `/person/*`; sitemap emits `/writer/<slug>`.

### 2. Works
**Exists:** `tblWorks` (schema.sql:2931-2957) — Id, **ParentWorkId self-FK (nesting works)**, `Iswc
CHAR(15) UNIQUE`, MusicBrainzWorkMBID UNIQUE, Title, Slug UNIQUE, Notes, OriginCity+Id. `tblWorkSongs`
(WorkId,SongId PK, IsCanonical, SortOrder). `tblWorkExternalLinks`. Routes: page `case 'work'` +
`action=work` (thin `getWork()`: id/parent/children/members/links — **no credits, no tune**).
`/iswc/<code>` page EXISTS (#940, iswc.php) — **charset-strip normalise only, exact-matches
tblSongs.Iswc**, cross-links the tblWorks row. CRUD `manage/works.php` (gate `manage_works`) —
canonicalises ISWC to `T-NNN.NNN.NNN-C` on save. Batch `backfill-works-from-iswc.php` find-or-creates
works. Song page "Part of work" panel (song.php:1515). **Editor: NO work endpoint anywhere.**
**Gaps:** CCLI on Works (none — CCLI lives only on tblSongs.Ccli; precedence has no CCLI leg);
create-work-on-ISWC/CCLI-entry in the editor save path (only the batch exists); disambiguation; work-page
credits (getWork returns none — iswc.php's "richer metadata" claim is already inaccurate); `/ccli/`
route; **separator-insensitive `/iswc/`** (stored canonical dotted vs undotted input won't match —
exact WHERE).

### 3. Tunes
**Exists:** `tblTunes` (schema.sql:3464-3480, #1090 P4) — Id, Name UNIQUE, Slug UNIQUE, **`MeterCode
VARCHAR(60)`+idx**, MusicBrainzWorkMBID, HymnaryTuneId, Notes. `tblTuneAliases`. Song link is **N:1 via
`tblSongs.TuneId` FK + `tblSongs.TuneName` denorm mirror** (no junction table). Backfill
`migrate-tunes-entity.php`. Routes: page `case 'tune'` + router. **Public tune.php does NOT use
tblTunes** — heuristic DISTINCT TuneName match (its own doc-block says a registry would make it a single
slug SELECT). Only tblTunes reader = `getSongDetailExtras('tune')` (dormant native song_detail block).
Editor: Tune is a plain text input, **no typeahead, no tblTunes write path** (v2 metadata-tab:40 →
`metadata_field_update` → TuneName only). **NO admin CRUD, NO `manage_tunes` entitlement, NO tune JSON action.**
**Gaps:** `/tune/` keyed on tblTunes (heuristic only today); subtitle+disambiguation; meter surfacing
in Song/Tune/Work editors (MeterCode written by nothing); Work-carries-tune; tune writer info (no
tune↔credit table); live-search typeahead; tune external links (no table + `'tune'` not in AppliesTo SET).

### 4. Songs — exact identifier/copyright columns (tblSongs, schema.sql:258-342)
`SongId VARCHAR(20)` UNIQUE; `PublicId`; **`Copyright VARCHAR(500)` — one free-text field, no year/holder
split**; `Ccli VARCHAR(50)`; `Iswc VARCHAR(15)`; **`Isrc VARCHAR(15)` + non-unique idx_Isrc (multiple
songs may share)**; `Upc`; `TuneName`/`TuneId`; `LyricsPublicDomain`/`MusicPublicDomain`. Recording
identity `tblSongIdentityMap` (#1066, dormant — **`IsrcCode` UNIQUE `uk_Isrc`**, SourceOfTruth/MappingStatus
ENUMs self-flagged as rule-#20 debt). PRO ids `tblSongRoyaltyIds`. **ISRC + UPC stored but rendered
nowhere; not editable in either editor.**
**Gaps:** `/isrc/` alias (multi-match→song-list; must read tblSongs.Isrc NOT the UNIQUE map); subtitle;
disambiguation; first-published year (only songbook-level PublicationYear exists); copyright split
Year(s)+Holder; ISRC editor field.

### 5. Alias-URL routing + typeahead
Routing: `.htaccess` catch-all → `index.php` (SPA shell + OG/JSON-LD detection only) → `router.js`
parseRoute switch (:318-352: writer, people/person, work/works, tag, tune, iswc) → `/api?page=<x>` →
api.php page switch → `includes/pages/<x>.php` (person/work/writer/tune/iswc all **shared-cache
fragments** — rule #30 applies to anything added). **Only ONE alias family implemented (`/iswc/`)**, and
ISWC normalisation is **already duplicated** (iswc.php:28 inline vs works.php:68 canonicaliser — the
divergence the epic's "one normaliser, one resolver" targets). No `includes/identifier_*.php` shared
module. `person_by_identifier` (dormant) already matches the `/ipi//isni/` resolver shape.
Typeahead patterns to mirror: (1) **place-search** — `manage/places-api.php` (`?action=search` +
`?action=upsert` pick-sets-FK) + `js/modules/place-search.js` (ARIA combobox, 250ms debounce, hidden-id
input); (2) **credit-name** — api2 `credit_search` + `v2/credits-tab.js` dropdown. Tune live-search can
mirror either (api2 `tune_search` like `tag_search`, or place-style pick-sets-TuneId). `song_similarity.php`
available if fuzzy dedup ranking wanted.

### 6. Schema-extension verdicts (raw material for the one-pass DDL)
- Musician **Type** → NEW COLUMN (must reconcile with IsGroup/IsSpecialCase — dual-truth risk).
- Musician **Biography** → NEW COLUMN or repurpose Notes (decide); Wikipedia fallback = app logic.
- Musician **disambiguation** → NEW COLUMN.
- **"Portrayed by"+dates** → EXTEND tblCreditPersonMembers (RelationType+DateFrom/To) or NEW relation table.
- Work **disambiguation** → NEW COLUMN.
- Work **CCLI** → NEW COLUMN (nullable UNIQUE) — required for `/ccli/`→Work.
- Work **tune name** → NEW COLUMN (TuneId FK, mirror tblSongs pattern).
- Tune **subtitle+disambiguation** → NEW COLUMNS (extend the dormant table — rule #20).
- Tune **writer + external links** → NEW TABLE(s); ⚠️ AppliesTo SET needs `'tune'` = ALTER (SET is the
  ENUM-class landmine; consider converging SET→VARCHAR/CSV-validated in this pass).
- Song **ISRC** → ALREADY PRESENT (missing only editor field + `/isrc/`); multi-match must read tblSongs.Isrc.
- Song **subtitle+disambiguation** → NEW COLUMNS.
- Song **first-published year** → NEW COLUMN — issue says "Song AND Work editors", so decide BOTH now
  (adding to one only forces the second migration).
- **Copyright split** (Years+Holder) → NEW COLUMNS, keep Copyright as legacy/denorm; same both-editors
  double-home question.
- Alias resolver → NO SCHEMA (routing + one shared normaliser); ISWC drift must be normalised both sides.

**Second-migration traps (rule #20):** AppliesTo SET; tblCreditPersonAliases.Type ENUM (don't copy for
tune/work aliases); tblSongIdentityMap ENUMs (self-flagged); Type-column-without-IsGroup-convergence;
pubyear/copyright added to Songs-not-Works.

### 7. Risks & open questions
1. Musicians rename = spine migration (see blast radius); a server rename without **response-key
   aliases** breaks shipped Apple/Android clients (`action=credit_person`, `person` payload key).
2. `writer` vs `person` duplicate pages predate the epic — profile work is also a consolidation.
3. Issue "already exists" table accurate for schema, but: tune page doesn't use tblTunes (no CRUD/write
   path); getWork() returns no credits (work-page "richer metadata" is aspirational).
4. Credits are name-strings, not FKs (deliberate, schema.sql:500-509) — profile "by role" inherits
   name collisions; FK-ify is an open scope question.
5. ISWC storage drift (works.php canonicalises, editor does not).
6. tblCreditPersonIPI is a dead "rollback snapshot" whose drop never shipped.
7. rule #30/#6: new public profile-page interactivity must be a router-wired ES module reading `data-*`.
8. `person_by_identifier` dormant-by-design → the `/ipi//isni/` pages are its first real consumer.

---

## §2 — One-pass forward-looking schema design (Phase 2 — TO FOLLOW)

_(Fable 5, next.)_

## §3 — Rename blast-radius plan + alias resolver + phased implementation sequence (Phase 3 — TO FOLLOW)

_(Fable 5, after §2.)_

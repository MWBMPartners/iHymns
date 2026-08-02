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

1. ~~**#1741 branch base**~~ — **RESOLVED 2026-08-02 (owner):** lands on the SAME pre-merge branch
   `claude/wave3-fixes` — one branch, one PR to `alpha` (no-PR-stacking rule). GitHub issue #1741 body
   updated to match.
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

## §2 — One-pass forward-looking schema design (Phase 2, Fable 5, 2026-08-02)

> **Buildable.** Four additive/idempotent/dormant migration scripts grouped by entity family, applied
> together in registry order. All DDL is written in the byte-style of its target block in `schema.sql`
> (anchor by table name, not line). VARCHAR-not-ENUM throughout; every mirror byte-identical (rule #19);
> every future reader/writer column-existence-gated (3 docroots, one MySQL, web-run migrations).

### 2.0 Batch overview
| # | Slug | Script | Touches | Depends on |
|---|------|--------|---------|-----------|
| 1 | `musician-profile` | `migrate-musician-profile.php` | tblCreditPeople (+3 cols, Type-backfill, Notes→Biography move), tblCreditPersonMembers (+7 cols, UNIQUE re-key), tblCreditPersonAliases (ENUM→VARCHAR) | — |
| 2 | `works-identity` | `migrate-works-identity.php` | tblWorks (+8 cols, uq_ccli, idx_TuneId, trailing fk_Works_Tune) | tunes-entity |
| 3 | `tune-enrichment` | `migrate-tune-enrichment.php` | tblTunes (+2), NEW tblTuneCredits, NEW tblTuneExternalLinks, tblExternalLinkTypes (SET→VARCHAR + ENUM→VARCHAR) | tunes-entity |
| 4 | `song-identity-fields` | `migrate-song-identity-fields.php` | tblSongs (+5 cols, idx_Iswc, idx_Ccli, ISWC canonical backfill) | — |

### 2.1 Musicians
- **M1 `Type` VARCHAR(20) DEFAULT 'person'** (`person|group|character|orchestra|other`), authoritative;
  `IsGroup`/`IsSpecialCase` demoted to **derived mirrors** written only by `creditPersonTypeApply()`
  (Phase 3) — `IsGroup = Type IN ('group','orchestra')`, `IsSpecialCase = Type IN ('character','other')`.
  Backfill flags→Type (WHERE-guarded). CI guard bans direct flag writes outside the helper (rule #34/#35).
- **M2 `Biography MEDIUMTEXT`** — **P1 shipped this as a COPY, not a move** (`Biography = Notes` where
  `Biography IS NULL`; Notes UNTOUCHED): `person.php:549` still reads Notes as the live public bio, so
  clearing Notes in P1 would blank every bio immediately (a behaviour change P1 forbids). **The
  read-path switch (person.php→Biography via an INFORMATION_SCHEMA-gated select + a `notes-as-bio-fallback`
  branch) and the Notes→curator-internal repurposing move to P4a.** Documented in the migration + schema.sql.
- **M3 "Portrayed by" EXTENDS tblCreditPersonMembers** (+`RelationType` VARCHAR `member|portrays`,
  `DateFrom/DateFromPrecision/DateTo/DateToPrecision`, `Note`, `UpdatedAt`); re-key
  `uq_group_member`→`uq_group_member_rel (GroupPersonId,MemberPersonId,RelationType,DateFrom)` (ADD new
  then DROP old). Member-specific column names left for the Phase-3 rename's single table rebuild
  (→`SubjectMusicianId`/`ObjectMusicianId`). +`Disambiguation VARCHAR(255)` on tblCreditPeople.
- **M4 rider:** `tblCreditPersonAliases.Type` ENUM→VARCHAR(20) (data-preserving MODIFY).

### 2.2 Works (tblWorks)
+`Ccli VARCHAR(50) NULL` UNIQUE `uq_ccli` (NULL not '' so absent values coexist; the /ccli/→Work key),
`Subtitle`, `Disambiguation`, `TuneName`+`TuneId`+`idx_TuneId` (mirror tblSongs pair; **trailing**
`fk_Works_Tune` ALTER because tblWorks precedes tblTunes in the file — the fk_Songs_Tune idiom),
`FirstPublishedYear SMALLINT UNSIGNED` (never MySQL YEAR — starts 1901; hymns predate it),
`CopyrightYears`+`CopyrightHolder` (no legacy Copyright column here). ISWC unchanged (already canonical).

### 2.3 Tunes
+`Subtitle`,`Disambiguation` on tblTunes. **NEW `tblTuneCredits`** — ONE Role-discriminated table
(`Role` VARCHAR `composer|arranger|harmoniser|source`, `Name` name-string, **`CreditPersonId` reserved
now, nullable, dormant** so the credits-FK decision costs zero ALTER either way), NOT six per-role
clones. **NEW `tblTuneExternalLinks`** mirrors tblWorkExternalLinks (real FK, rule #15). **T3: convert
`tblExternalLinkTypes.AppliesTo` SET→VARCHAR(255) CSV** (adding `'tune'` to a SET is the rule-#20 ALTER;
MySQL casts SET→CSV free, `FIND_IN_SET` reads unchanged) + `Category` ENUM→VARCHAR riding along.

### 2.4 Songs (tblSongs)
`Isrc` already exists (**no schema change** — editor field + /isrc/ route are Phase 3+; multi-match
reads `tblSongs.Isrc` non-unique, NEVER `tblSongIdentityMap.uk_Isrc`). +`Subtitle VARCHAR(500)`,
`Disambiguation`, `FirstPublishedYear` (same as Works — both in one batch, "Song AND Work editors"),
`CopyrightYears`+`CopyrightHolder` (legacy `Copyright` kept as as-printed denorm; NOT auto-parsed).
+`idx_Iswc`,`idx_Ccli` (neither indexed today; both resolvers hit ~14k rows on cacheable routes).

### 2.5 Alias resolver — NO new schema beyond §2.4 indexes
ISWC drift fixed by **normalise-on-write**: extract works.php's canonicaliser (:60-77) into shared
`includes/identifier_normalize.php::ihymns_canonical_iswc()`, kill the duplicate in iswc.php:28, apply
on every Iswc write funnel + a §2.4 backfill; /iswc/ canonicalises INPUT then one indexed exact match.
`/ccli/` Work-first (uq_ccli) then song multi-match (idx_Ccli). `/ipi//isni/` = first consumers of the
dormant `person_by_identifier` (api.php:1280, generic over IdentifierType — no change). `/musician/<slug>`
= tblCreditPeople.Slug (exists).

### 2.6 Musicians rename — schema side: NOT in this pass
Batch lands on CURRENT names; rename ships as its own Phase-3 lockstep migration with response-key +
route aliases (blast radius §1; the batch must be safe on a DB whose 3 docroots run un-updated code).
New columns free-ride the later `RENAME TABLE`. **Rider list for Phase 3:** `tblTuneCredits.CreditPersonId`
(+fk/idx) and the new PHP maps in `credit_people_helpers.php`.

### 2.7 Adversarial "what forces a second migration?" (rule #20) — all 13 traps fixed or escape-hatched
New-entity-kind/relation-kind/alias-kind/tune-role → VARCHAR+map (fixed); member rejoin → UNIQUE
re-keyed now (fixed); pubyear/copyright/subtitle/disambiguation on **both** Songs+Works (+ subtitle on
Works, added by this pass) (fixed); AppliesTo SET + Category ENUM converted (fixed); credits-FK-either-way
→ reserved column (fixed); Works.Ccli UNIQUE → tblWorkIdentifiers escape hatch (accepted); work-level
curator credits → tblWorkCredits escape hatch (accepted); publication month/day precision → belongs in
the release/identity family, not these columns (accepted, named); `tblSongIdentityMap` ENUMs → adjacent
pre-existing debt, **file its own issue, don't smuggle** (flagged).

### 2.8 Migration-registry — 4 entries + 2 new probe helpers
New helpers beside `_migProbe_constraintExists`: `_migProbe_indexExists()` and
`_migProbe_columnDataType()` (both bound-param INFORMATION_SCHEMA, mutation-testable). Each of the 4
entries has a REAL multi-object OR-probe (e.g. musician-profile checks Type+Biography+Disambiguation+
RelationType cols, the uq_group_member_rel index, AND `aliases.Type != 'enum'`). Tune-dependent cards
sit after `tunes-entity`; out-of-order clicks handled by each script's tableExists(tblTunes)
skip-and-warn + the probe staying pending. **Per rule #34 every probe must be mutation-tested pre-merge.**

### 2.9 Out of DDL scope (flagged): credits name-string vs FK-ify (owner decision 3 — attach point
specified: nullable `CreditPersonId` FK on the six song-credit tables, the tblTuneCredits pattern);
`tblSongIdentityMap` ENUM convergence (own issue); the rename DDL/aliases (Phase 3).

**This §2 summary is the authoritative durable spec** — it carries every column, type, key, backfill,
gotcha (trailing `fk_Works_Tune` ALTER; SET/ENUM→VARCHAR conversions; Notes→Biography MOVE; SMALLINT
not YEAR; NULL not '' for `uq_ccli`; backfill-before-index step order; the OR-probe shapes) and the two
new probe helpers. At implementation time the exact COMMENT wording + column POSITIONS are regenerated
to house style by re-reading the live `schema.sql` block for each table (anchor by table name); do NOT
rely on any ephemeral agent transcript.

## §3 — Rename blast-radius + alias resolver + phased sequence (Phase 3, Fable 5, 2026-08-02)

> **⚠ finding that shrinks decision D2:** the shipped native contract is **Apple-only** —
> `grep -rn credit_person appAndroid --include=*.kt` = **0 hits**. Only `appApple/Packages/iHymnsKit`
> consumes `action=credit_person` / the `{"person":…}` envelope / `/person/*` (AASA + `CanonicalURL`).
> So the "frozen contract" a rename must not break is one Swift package; Android has nothing to freeze.
> Entitlements are code-only (PHP map + JS mirror, no DB rows). The service worker has no person-route
> special-casing.

### A — Musicians full rename
**Map:** 7 tables → `tblMusicians` + `tblMusician{Identifiers,ExternalLinks,Aliases,Relations,Links,IPI}`
(`tblCreditPersonMembers`→`tblMusicianRelations` because §2.1 M3 made it a `member|portrays` relation
table). `CreditPersonId`→`MusicianId` on all 8 FK tables (incl. `tblSongbookCompilers`, `tblVocalParts`,
+ the §2 rider `tblTuneCredits`); `GroupPersonId`/`MemberPersonId`→`SubjectMusicianId`/`ObjectMusicianId`.
Routes `/people//person//writer/`→canonical `/musician/<slug>` (old kept as aliases + 301). Actions
`credit_person`→`musician`, `person_by_identifier`→`musician_by_identifier`, `admin_credit_person_*`→
`admin_musician_*`. Log keys → `admin.musicians.*` / `api.admin.musician.*`; entity type `credit_person`→
`musician` (history NEVER rewritten; viewer maps old↔new via one shared constant). Entitlement
`manage_credit_people`→`manage_musicians` (code-only, one atomic commit, no alias). `AppliesTo` `'person'`
token→`'musician'` (rides §2.3's SET→VARCHAR). Internal PHP/JS (`credit_people_helpers.php`→
`musician_helpers.php`, `getCreditPerson()`→`getMusician()`, `person.php`→`musician.php`) renamed with NO
aliases.
**Back-compat (what must NOT break):** `action=credit_person`/`person_by_identifier` stay as **alias
dispatches returning the byte-identical OLD envelope** (action-determines-shape, NOT dual keys) —
indefinite; shipped Apple binaries never change. AASA keeps `/person/*` AND adds `/musician/*`; `/people//person/` 301 to `/musician/` for non-fragment loads; router keeps SPA-internal aliases. `page=person/writer`
alias `page=musician` (normalised before the cache key). `api-docs.yaml` marks old endpoints `deprecated`.
**Migration mechanics (`migrate-musicians-rename.php`):** precondition-gate that all 4 §2 cards are green
(else abort — an FK to a soon-to-be-view is illegal); atomic `RENAME TABLE` (7); `RENAME COLUMN`
(metadata-only, FKs auto-update in MySQL 8); `RENAME INDEX` + `DROP/ADD` for constraint names (no RENAME
for FK names) + `MODIFY` for COMMENT prose; **updatable compat VIEWs with explicit old-name column
aliases** (the 3-docroot skew shield — old code keeps working: views are insert/update/delete-able and
show in INFORMATION_SCHEMA so existence-probes pass); AppliesTo token rewrite; byte-identical schema.sql
mirror INCLUDING the views; real multi-object OR-probe. **Deploy order: run migration first, then code**
(new code reads only new names; views make old-code+new-schema safe). Views dropped later by a
`'manual'`+`confirm=1` cleanup card (rule #25 pattern). CI guard `test-musician-rename-guard.php`:
**bidirectional count-exact** (a new old-name reference fails high; a deleted back-compat alias fails low)
+ a tree-derived `schema.sql` assertion; mutation-tested.
**Recommendation (A.5): do the rename in THIS epic, EARLY — as P2, straight after the schema batch.**
Deferring GROWS the surface (every later phase writes new `creditPerson` code); the scary parts are
decomposed away (Apple frozen behind aliases; skew shielded by views; stragglers caught by the
bidirectional guard). Splitting to a follow-up epic = the rule-#24 "UI-only halfway state" the owner
rejected. Only D2 reversal justifies deferral.

### B — Shared alias resolver + Tune live-search
`includes/identifier_normalize.php` — a facade DELEGATING to existing write-side canonicalisers (one fold
per scheme, rule-#22 lesson): `iswc` EXTRACTED from works.php:72 (**kills the duplicate in iswc.php:30**,
§1 bug), `ipi`/`isni` delegate to `credit_people_helpers.php`'s folds, `ccli`/`isrc` new; plus the
`IHYMNS_ID_SCHEMES` registry constant everything derives from. `includes/identifier_resolve.php` maps
normalised→(entity,target), per-scheme precedence (iswc/ccli: Work-first then song multi-match; isrc:
`tblSongs.Isrc` non-unique, never the UNIQUE identity-map key; ipi/isni: `tblMusicianIdentifiers` — the
dormant `person_by_identifier`'s first real consumer, delegated). Routing: `index.php` 301 layer for
single-match (bots/shared links) + a cacheable SPA fragment `includes/pages/identifier.php` that
**absorbs iswc.php** (route/page aliases kept), single-match-in-SPA handled by a router-wired ES module
`identifier-page.js` reading `data-*` (rule #30/#6). **⚠ §2.4 amendment to file:** add an ISRC-canonicalise
backfill to the `song-identity-fields` card (parallels the ISWC one) so `/isrc/` gets an indexed exact
match — one line now vs the forbidden second migration.
**Tune live-search:** api2 `tune_search` (mirrors `tag_search`, JOINed through `tblTuneAliases` so spelling
variants surface the canonical tune — the actual dedup mechanism; `meterCode` rides along; NO fuzzy scorer
in v1) + `song_tune_set` (the ONE funnel keeping `TuneId`+`TuneName` in lockstep, find-or-creates by name;
**retire `tuneName` from `metadata_field_update`** — today it strands `TuneId`, the drift this fixes).
Client: **generalise `place-search.js`** (add `parseResults` + `pickMode` options, defaults unchanged) —
NOT fork it, NOT the credits-tab dropdown (that picks name-strings, no FK). Meter-matching via one
`ihymns_meter_normalize()` (CM/LM/… → digit form) in new `includes/tune_helpers.php`; a "matching meter"
editor toggle re-runs `tune_search?meter=` (the swap-lyrics-between-tunes affordance); tune-page "Tunes
with this meter" section.

### C — Phased implementation sequence (single branch `claude/wave3-fixes`, single PR)
| # | Phase | Depends on | Owner-decision gate |
|---|---|---|---|
| **P1** | Additive schema batch (§2) + ISRC-backfill amendment + 2 probe helpers | — | none |
| **P2** | Musicians rename (A) — schema (RENAME+views), routes, actions, log keys, entitlement, guard | P1 | **D2** (confirm A.5) |
| **P2b** | Manual cleanup card: drop compat views once all docroots run renamed code | P2 | none |
| **P3** | Identifier normalise/resolve + `/ipi//isni//iswc//ccli//isrc/`, iswc.php absorbed | P1 (indexes), P2 (new names) | none |
| **P4a** | Musician profile page (type/bio/disambig/portrayed-by/id-chips) | P2, P3 | **D4** (writer-consolidation slice only) |
| **P4b** | Work page + admin (CCLI/tune/subtitle/year/copyright) | P1, P2 | none |
| **P4c** | Tune page on registry + meter section | P1, P2 | none |
| **P5** | Editor: tune typeahead + ISRC/subtitle/disambig/year/copyright fields | P1, P2, B.2 | **D3** noted, non-blocking |
| **P6** | Docs / API spec / native + debt follow-up filing | all | none |
Sequential: P1→P2→P3→rest. Parallelisable (human): P4a ∥ P4b ∥ P4c ∥ P5. Sub-issues to file under
#1741: one per phase (P2b folded into P2). **D3 blocks nothing** (the `tblTuneCredits.MusicianId` reserved
column absorbs either answer; the profile ships name-matched like today). **D4** gates only the P4a writer
slice; the rest of P4a proceeds regardless.

### Remaining owner decisions (D1 resolved)
- **D2 — Musicians rename scope** — gates P2. Recommend A.5: full WEB rename now (early), Apple wire
  contract frozen behind action aliases indefinitely (Android has nothing to freeze — verified). One-word confirm.
- **D3 — credits name-string vs FK-ify** — gates NOTHING in this epic (reserved column). No-rush.
- **D4 — writer/person page consolidation** — gates only P4a's writer slice. Recommend consolidate
  (`/writer/` survives as a 301-alias). Confirm before that slice; rest of P4a unaffected.
- **D5 — additional external/catalogue IDs (owner request 2026-08-02, per Luminate Data's "External
  IDs" KB article).** Fold into #1741's identifier model. ⚠️ The article is WebFetch-403 (Luminate portal
  bot-block, not the proxy) — do NOT implement a guessed list. Architectural fit: because the identifier
  types are VARCHAR + central-map-validated (rule #20), most new IDs are **central-map + resolver entries,
  NOT schema** (musician IDs → the `CREDIT_IDENTIFIER_TYPES` registry [→ `musician_helpers.php` after P2];
  recording/release IDs → `tblSongIdentityMap`/`tblSongRoyaltyIds` which are already VARCHAR-keyed; work
  IDs → `tblWorks`). Only a genuinely new-shape ID (e.g. a release/product entity iHymns doesn't model)
  would need schema. Sequenced AFTER P2 (the registries live in files P2 renames). Owner to confirm the
  exact list — proposed starting set + fit recorded on #1741. Blocks nothing now.

**Planning COMPLETE.** Implementation begins with P1 (unblocked). Full Part-A/B/C detail regenerates from
this summary + a live `schema.sql`/route read at build time; do not rely on any ephemeral agent transcript.

---

## §4 — P3 build scoping (2026-08-02, ground-truth re-verified post-P2 rename)

P1 (#1745, closed) + P2 (#1746) + D5 storage (`b428f590`, #1747) are landed. P3 now implements the
shared identifier normalise/resolve + alias routes. Ground truth re-read against `claude/wave3-fixes`:
- Route parsing: `router.js` parseRoute switch (`/iswc/` at :352; `/musician//people//person/`→`musician`
  at :320; no `/ipi//isni//ccli//bowi//isrc/` yet). `api.php` page switch (`case 'iswc'` :736 requires
  `includes/pages/iswc.php`; `$_cacheablePages` :584 — **iswc + tune are NOT cacheable today**).
- Folds to reuse: `manage/works.php:68 $validateIswc` (the canonical ISWC fold); `iswc.php:28-31` has the
  **duplicate** inline strip (the one to kill); `musician_helpers.php::canonicaliseIsni()` (:525) + the
  `person_by_identifier`/`musician_by_identifier` query (api.php:1332-1348, JOIN tblMusicianIdentifiers→
  tblMusicians) = the ipi/isni resolver shape. `media_identifiers.php` (D5) has the vocabulary.
- Columns confirmed present: `tblSongs.{Iswc,Isrc,Ccli}` + `idx_Iswc/idx_Isrc/idx_Ccli` (Ccli is
  `NOT NULL DEFAULT ''` → resolver must guard `Ccli<>''`); `tblWorks.{Iswc(uq_iswc),Ccli(uq_ccli),Bowi(uq_bowi)}`.

**P3 scope THIS commit (single, focused):**
1. `includes/identifier_normalize.php` — `IHYMNS_ID_SCHEMES` registry (iswc/ccli/bowi/isrc/ipi/isni) +
   one canonical fold per scheme; `ihymns_canonical_iswc()` is the EXTRACTED works.php fold; isni/ipi
   delegate to `musician_helpers.php`. `works.php $validateIswc` rewired to delegate (kills fold #1).
2. `includes/identifier_resolve.php` — `ihymns_resolve_identifier($db,$scheme,$raw)`: column/table-
   existence-gated, degrades to empty (never throws under STRICT). iswc/ccli/bowi = Work-first;
   iswc/ccli/isrc = song multi-match (`tblSongs.Isrc` non-unique, **never** `tblSongIdentityMap.uk_Isrc`);
   ipi/isni = `tblMusicianIdentifiers` (the dormant query's first real consumer).
3. `includes/pages/identifier.php` — ONE cacheable-shaped fragment that **absorbs iswc.php**; renders
   song-list (iswc/ccli/isrc) / work-header / musician-list (ipi/isni). No inline `<script>` (rule #30).
   `iswc.php` DELETED (only api.php required it); `page=iswc` repointed here (route contract kept, rule #33).
4. `api.php` page cases `ipi/isni/ccli/bowi/isrc` + repoint `iswc` → identifier.php (`$idScheme=$page`).
   `router.js` parseRoute cases + DOCUMENT_TITLES for the 5 new segments.
5. Guards: `tests/php/test-identifier-normalize.php` (fold behaviour + works.php delegation + registry
   coverage) and `tests/test-identifier-routes.js` (tree-derived: every IHYMNS_ID_SCHEMES scheme has a
   router case + an api.php case requiring identifier.php + the page CSP-clean/a11y) — both mutation-tested.

**Deliberately OUT of P3 (scoped, reversible, noted):** the index.php bot/301 single-match layer (SEO
nicety; adds risk to a critical file — defer to P6); Tune live-search (§3.B second half is editor work →
P5); leaving the identifier routes **uncached** to match the existing iswc/tune precedent (cheap indexed
anonymous read; adding to `$_cacheablePages` is a safe later optimisation, not needed for the feature).

**P3 LANDED + independently verified — commit `dc9b5067` (2026-08-02).** All five files built as specced:
`identifier_normalize.php` (registry + folds, works.php delegates, kills the duplicate ISWC strip),
`identifier_resolve.php` (STRICT-safe, bind_param, column allow-list, never `tblSongIdentityMap.uk_Isrc`),
`identifier.php` (absorbs+deletes iswc.php), api.php + router.js alias routes, two guards. My verification
(not the agent's report): PHP 93/93 + node 46/46; **I mutation-proved BOTH guards** (route guard red on a
removed router case, normalise guard red on a broken fold, both byte-identical after restore); behavioural
probe ran the resolver for all six schemes + edge cases against the live DB under STRICT with no throw, and
every resolver SELECT proven schema-valid raw (no catch). **Remaining for the D5/ID ask:** P5 (editor entry
for the new IDs) + P6 (docs); the P4 per-entity pages surface them to users. Then the 3 Meedya repos.

# iLyrics Internal IDs + Work Identity Model — Design Doc

**Status: DESIGN — Phase 0 sign-off pending. No migrations, no schema changes, no implementation in this doc.**
Owner decisions treated as settled requirements: (A) a single sequential `IL*` internal-ID scheme across iHymns + the future iLyrics DB, built now; (B) an auto find-or-link Work identity model keyed by ISWC/CCLI, including medley composition and section-level source provenance; (C) dual-addressing — every entity reachable by BOTH its existing public id and its new `IL*` id.

---

## 1. Goals & the iLyrics context

iHymns is the first working child of the future parent "iLyrics DB". The plan of record is that **iHymns' backend BECOMES the iLyrics DB**, and iHymns continues as a public-facing mask that surfaces only Christian content via the existing Christian-corpus axis — which lives at **songbook grain**: `tblSongbooks.IsChristian TINYINT(1) NOT NULL DEFAULT 1` ("iHymns surfaces only WHERE IsChristian=1; the shared iLyricsDB core applies no filter", `appWeb/.sql/schema.sql:195`), fenced by the read-layer view `v_ChristianSongs` (`schema.sql:3586-3599`, `WHERE sb.IsChristian = 1`). ⚠️ The brief said "`tblSongs.IsChristian`" — **no such column exists**; the #1045 axis is on `tblSongbooks` (confirmed by `tblSongs.Genre`'s own comment: "NOT the Christian-filter axis — that is tblSongbooks.IsChristian", `schema.sql:299`).

The schema has **deliberately gated** the cross-DB identity layer pending the DB-merge decision (#1010 follow-on): `tblSongIdentityMap`'s doc-comment — "The iLyricsDB link column + bridge views are GATED on the DB-merge decision (see issue #1066-gated)" (`schema.sql:3476-3477`), its `SourceOfTruth ENUM('ihymns','ilyricsdb',…)` (`schema.sql:3509` — a flagged known ENUM inconsistency, `schema.sql:3499-3508`), and CLAUDE.md rule #20's "never ship a guessed bridge view".

**This design IS the deliberate un-gating of the identity layer — and only the identity layer.** What made the gate necessary was *guessing the bridge*: a link column or view whose shape depends on the other side of a merge that hasn't been decided. An internal-ID scheme has no such dependency — iHymns' DB is the future iLyrics DB, so the "central" allocator built here is not a bridge to another system; it is the system. It stays safe by the same three properties every gated batch in this repo already uses: **additive** (new NULL-able columns + new tables, zero re-keys), **idempotent** (backfill touches only NULL rows), and **dormant where cross-DB** (no bridge views, no `tblSongIdentityMap` changes — that table stays frozen legacy per #1749, `schema.sql:3479-3490`; the future-children allocation API stays unshipped until a second child exists).

---

## 2. The internal-ID scheme (`IL*`)

### 2.1 Prefix taxonomy

| Prefix | Entity | Backing table | Current PK / public id |
|---|---|---|---|
| **ILS** | Song | `tblSongs` (`schema.sql:266`) | `Id INT` PK; `SongId VARCHAR(20)` UNIQUE (`MP-1008`, songbook-scoped, re-keyed on move); `PublicId VARCHAR(16)` opaque permalink (`schema.sql:269`) |
| **ILW** | Work | `tblWorks` (`schema.sql:3132`) | `Id INT` PK; public `/work/<Slug>` |
| **ILM** | Musician | `tblMusicians` (`schema.sql:532`) | `Id INT` PK; public `/people/<Slug>` |
| **ILT** | Tune | `tblTunes` (`schema.sql:3738`) | `Id INT` PK; public `/tune/<Slug>` |
| **ILP** | Publisher | `tblPublishers` (`schema.sql:3874`) | `Id INT` PK; public `/publisher/<Slug>` (rule #37 ladder) |
| **ILC** | Collection | `tblCatalogues` (`schema.sql:2537`) — "Collection" is UI copy only, rule #24 | `Id INT` PK; `Slug` UNIQUE |
| **ILB** | Songbook | `tblSongbooks` (`schema.sql:176`) | `Id INT` PK; `Abbreviation VARCHAR(10)` UNIQUE = the SongId prefix (rule #27) |
| **ILD** | Document (song media/files) | `tblSongMedia` (`schema.sql:3086` — audio / sheet-music / midi / musicxml / notation-source / pdf) | `Id INT` PK; served by `/song-media/<id>` |

The prefix→table map lives ONCE in PHP as `IHYMNS_ILID_TYPES` in a new `includes/ilyrics_id.php` (VARCHAR-vocabulary-style central map, rule #20 / the `IHYMNS_ID_SCHEMES` shape at `includes/identifier_normalize.php:110-117`). A ninth entity later = one map line + one sequence row, no ALTER.

### 2.2 ID format

**`ILS-0000012345`** — 3-letter prefix + hyphen + **10-digit zero-padded** decimal, stored as the formatted string in a per-table column:

```
IlId VARCHAR(16) NULL DEFAULT NULL, UNIQUE KEY uq_IlId (IlId)
```

- **Why 10 digits**: iHymns is ~14k songs, but iLyrics DB's ambition is an unmasked all-genres corpus — MusicBrainz holds ~50M recordings, Spotify ~100M tracks. 8 digits (99M) is plausibly exhaustible by a full secular lyrics DB; 10 digits (9.99B) is not, while staying human-readable/dictatable. Fixed width keeps lexicographic order = numeric order (useful for index range scans and eyeball-sortable exports).
- **Why store the formatted string, not a BIGINT**: mirrors `SongId` — the string IS what appears in URLs, API payloads, and support tickets; storing exactly what is displayed removes a format-derivation seam that could drift across future children. `VARCHAR(16)` = 14 chars used + headroom. The UNIQUE key is NULL-distinct, so un-backfilled rows coexist (rule #20's multiple-NULLs pattern, same as `tblWorks.uq_ccli`).
- **Why sequential, not UUID**: owner requirement (referenceability). Sequentiality is per-type, not gap-free — a rolled-back create may burn a number, exactly as `ed2_allocateSongId()`'s skip-loop burns candidates (`manage/editor/api2.php:933-937`). Gap-free is explicitly a non-goal.
- **Prefix-namespace reservation (load-bearing)**: `ILS-0000012345` matches the SongId shape `^([A-Z]+)-0*(\d+)$` that the PWA router (`js/modules/router.js:420-434`), `og-image.php` and the API validators parse (rule #27), and songbook `Abbreviation` is `[A-Z0-9]{1,10}` — so a curator could today legally create a songbook abbreviated "ILS" and collide with the entire scheme. The 8 prefixes (plus `IL` generally — recommend reserving `/^IL[A-Z]?$/` and the 8 exact prefixes) MUST be rejected by the songbook-abbreviation validators (`manage/songbooks.php` create/edit, and `ed2_allocateSongId()`'s allow-list is unaffected since it takes an existing book). This is a Phase 2 app-level guard + CI test, no schema change.

### 2.3 The central allocator — `tblIlyricsIdSequence`

**One unified table, one row per entity type** (not per-type tables, not pre-carved ranges):

```sql
CREATE TABLE IF NOT EXISTS tblIlyricsIdSequence (
    EntityType VARCHAR(20)     NOT NULL COMMENT 'song|work|musician|tune|publisher|collection|songbook|document — app-validated against IHYMNS_ILID_TYPES (VARCHAR not ENUM, rule #20)',
    Prefix     VARCHAR(4)      NOT NULL COMMENT 'ILS|ILW|ILM|ILT|ILP|ILC|ILB|ILD — denorm of the registry, informational',
    NextValue  BIGINT UNSIGNED NOT NULL DEFAULT 1,
    UpdatedAt  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (EntityType)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Allocation (`ilidAllocate(\mysqli $db, string $entityType): string` in `includes/ilyrics_id.php`), inside the **caller's** transaction, mirroring the transactional discipline of the two existing mints:

1. `SELECT NextValue FROM tblIlyricsIdSequence WHERE EntityType = ? FOR UPDATE` — the same row-lock discipline as `ed2_allocateSongId()`'s `… ORDER BY … DESC LIMIT 1 FOR UPDATE` seed read (`api2.php:911-915`).
2. `UPDATE … SET NextValue = NextValue + 1 WHERE EntityType = ?`.
3. Format + **claim-check before returning**: verify the candidate isn't already held by the entity table's `uq_IlId` (belt-and-braces after a manually-seeded or restored counter), the same "the counter is not the claim set" lesson `songRelocateIdTaken()` encodes for SongIds (`includes/song_relocate.php:277-348` — the mint seed and the claim check are separate concerns; the CHECK is the shared unit). On a hit, advance and retry (bounded loop, throw after N — `api2.php:933-938`'s exact shape).

Why this shape and not the alternatives:

- **vs. per-namespace ranges** (each future child pre-assigned a block, e.g. iHymns 1–100M): ranges require guessing every child's eventual size, exhaust unevenly, need a range-registry table anyway to record who owns what, and turn exhaustion into a re-negotiation event. Rejected. The "iHymns now, shared later" path makes central trivially correct: **iHymns' DB IS the future central DB**, so today's children (=1) allocate by function call; tomorrow's children allocate via an authenticated API endpoint against this same table (idempotent retries via the already-shipped, dormant `tblApiKeyIdempotency`, #1066). Nothing about the table changes at the merge.
- **vs. eight per-type tables**: one table with a `EntityType` discriminator is one migration, one probe, one backup unit, and row-level `FOR UPDATE` means an ILS allocation never blocks an ILW allocation anyway. (This is the same unified-with-discriminator choice `tblSongExternalIds` made over `tblSongIdentityMap`'s column-per-provider, `schema.sql:3533-3552`.)
- **vs. `AUTO_INCREMENT`/`LAST_INSERT_ID()` tricks**: workable but couples the counter to connection state; the explicit `FOR UPDATE` + `UPDATE` pair is what the codebase already does and audits.
- **In-caller-txn trade-off**: a rolled-back save rolls the counter back too (no burn), at the cost of holding the type's row lock until commit. Create volume is curator-driven (single-digit per minute at peak), so serialising same-type creates for the duration of a save transaction is negligible. If a future bulk importer ever measures contention, allocation can move to short self-committed transactions (burning numbers on rollback) without changing the table or the callers' contract.

**Backfill**: one migration card mints `IlId` for every existing row **in `Id` order** (deterministic, roughly creation order), only where `IlId IS NULL` (idempotent), then seeds `NextValue` = max allocated + 1. ~14k songs + hundreds of works/musicians/tunes — trivial runtime.

### 2.4 Additive, never a re-key — and the three song ids

Nothing existing changes. `tblSongs.SongId` stays the PK-adjacent public id with its exact semantics (`MP-1008`, `Abbreviation` IS the prefix — rule #27; re-keyed on songbook move by `songRelocate()` with a `tblSongRedirects` forwarding row, #1679, `song_relocate.php:19-40`, `schema.sql:2398-2415`). Songbook `Abbreviation` charset stays untouched. The `IL*` ids are new nullable columns alongside.

**The concrete benefit**: a songbook move re-keys `SongId` via one `UPDATE tblSongs SET SongId = ?` cascading ~41 child FKs (`song_relocate.php:30-40`, `SONG_RELOCATE_EXPECTED_SONGID_FKS` at `:426-469`) and leaves the old id dead behind a redirect. The song's **row** never changes identity — so `IlId`, sitting on that row, **survives the move untouched**, with no redirect hop and no cascade. An `ILS-…` reference in an external system, a printed order of service, or a future iLyrics child stays valid across any number of moves, merges-of-books, or renumbers.

A song therefore carries **three ids with three jobs** — the design must say this plainly because two stable ids already exist:

| Id | Shape | Job | Move-stable? |
|---|---|---|---|
| `SongId` | `MP-1008` | Human/context id: book + number, the everyday URL | No — redirect on move (#1343/#1679) |
| `PublicId` | opaque Crockford base32 (`schema.sql:269`, uniq `:333`) | Anonymous share permalink (#1343-B) — deliberately unguessable, no sequence information | Yes (`song_relocate.php:117-122` — "deliberately untouched") |
| `IlId` (new) | `ILS-0000012345` | **Catalogue-master identity**: sequential, referenceable, uniform across all 8 entity families and across every future iLyrics child | Yes |

`PublicId` is NOT made redundant: it is opaque by design (sharing it leaks nothing about catalogue size or insertion order; an `ILS` id leaks both, which is fine for a reference id but not for an anonymous share link). All three coexist; none replaces another.

### 2.5 Addressing / resolution — dual-addressing (owner requirement C)

Every entity must be reachable by BOTH its existing public id AND its `IL*` id, resolving to the **same record and the same page** — the `IL*` URL being the permanently-stable deep link that survives the moves/renames that break the public form.

**The model to mirror** is the existing SongId resolution ladder, which already solves "several id shapes, one record" — `SongData::getSongById()` (`includes/SongData.php:2695-2760`) runs: (1) exact `SongId`; (2) opaque `PublicId` — only for hyphen-less input, **gated on the column existing** so un-migrated installs skip it cleanly (`:2712-2721`); (3) redirect/soft-delete suppression (#1689/#1694); (4) the number heuristic. The `IL*` branch is the same idea as the `PublicId` branch — a first-class alternate key on the ladder — NOT the same idea as `tblSongRedirects`, which forwards a *dead* id; an `IL*` id is never dead, so there is no redirect row, no hop, no `redirectedFrom`, and nothing to expire.

Per-entity design (each is one column-existence-gated branch added to the entity's existing resolver — never a second resolver, rule #22):

- **Song (`ILS`)** — new strategy **1.5** in `getSongById()`, between exact-SongId and PublicId: input matching `/^ILS-\d{1,10}$/` → canonicalise (zero-pad to 10) → `SELECT … WHERE IlId = ?`, gated on `_hasIlIdColumn()` (clone of `_hasPublicIdColumn()`, `SongData.php:212`). Padding tolerance is **load-bearing, server-side**: the PWA router's `normalizeSongId()` (`js/modules/router.js:420-434`) matches `^([A-Za-z]+)-0*(\d+)$` and re-pads to **4** digits, so a client will hand the server `ILS-12345` for `/song/ILS-0000012345` — the server must accept any padding (this also matches the documented "MP-1 / MP-0001 all resolve" tolerance, `SongData.php:2661-2663`). A cosmetic client fix (pass `IL*` ids through un-mangled, padded to 10) ships in the wiring phase, but the server tolerance is what makes stale clients and native apps (which parse the same shape, rule #27) safe. The API validators that parse `<letters>-<digits>` (`related_songs`/`remove_favorite`/`song_view`, rule #27) accept the `ILS` form via the same shared server-side normaliser. Note the ordering consequence: because `ILS` is now a reserved prefix (§2.2), strategy 4's number heuristic can never misfire on an `ILS` id that missed — there is no songbook "ILS" for `getSongByNumber('ILS', …)` to hit.
- **Work (`ILW`)** — `/work/<slug>` (`includes/pages/work.php`, `?page=work&slug=…`, rule #14) gains a pre-step: slug matching `/^ILW-\d{1,10}$/i` resolves by `tblWorks.IlId` first, else falls through to the slug lookup. Same pattern for **Musician (`ILM`)** on `/people/<slug>` (`includes/pages/musician.php`), **Tune (`ILT`)** on `/tune/<slug>` (`includes/pages/tune.php`), **Publisher (`ILP`)** on `/publisher/<slug>` — where the `IL` branch simply becomes rung 0 of the existing exact-slug → name-fold → alias-fold ladder (rule #37). No slug can collide with the `IL` shape in practice, but for determinism the `IL`-shape test runs first and a miss falls through (a real slug that happens to look like `ilw-123…` still resolves).
- **Songbook (`ILB`)** — `/songbook/<abbr>` (`includes/pages/songbook.php`) accepts `ILB-…` → resolve `tblSongbooks.IlId` → proceed with the resolved `Abbreviation`. The page's canonical URL stays the abbreviation form.
- **Collection (`ILC`)** — resolved the same way wherever collections are publicly addressed; today the public surface for `tblCatalogues` is limited, so the `ILC` route ships **dormant-until-consumed**: mint + store the ids now, wire a resolver only when/where a public collection page exists — rule #33: never emit a link the destination can't resolve.
- **Document (`ILD`)** — `/song-media/<id>` (`song-media.php`) accepts `ILD-…` alongside the numeric `tblSongMedia.Id`; the tier-gating call (`contentGatingMediaAllowed()`, rule #28) runs identically on the resolved row.
- **Universal resolver (recommended, cheap)** — one route `/il/<IlId>` that dispatches on the prefix via `IHYMNS_ILID_TYPES` and history-replaces to the entity's canonical page, mirroring the `identifier.php` scheme-registry pattern (`IHYMNS_ID_SCHEMES` drives `/iswc/ /ccli/ …`, `identifier_normalize.php:110-117`). This gives every `IL*` id one guaranteed entry point even before each per-entity route lands, and its route coverage is derivable from the registry for the CI guard (rule #34).

**Canonical-URL policy**: resolvers accept `IL*`; pages keep emitting their existing canonical URLs (`/song/MP-1008`, `/work/<slug>`). The `IL*` form is the *stable reference* form, surfaced explicitly (e.g. a copyable "Permanent ID" row in the admin editors and, later, the public pages) — it does not replace the friendly URL, exactly as `PublicId` behaves today.

### 2.6 IsChristian

- Current state: `tblSongbooks.IsChristian TINYINT(1) NOT NULL DEFAULT 1` (`schema.sql:195`), indexed (`:232`), enforced at read time by `v_ChristianSongs` (`:3599`). Default 1 = "Every iHymns songbook is Christian" (the column's own comment).
- **No backfill is needed**: the default is already 1 and the `migrate-songbook-ischristian.php` migration exists and shipped (#1045). Everything iHymns creates is Christian **by songbook membership**; songbook create paths keep the default. The only action for this program: the future unmasked iLyrics ingest paths must set `IsChristian=0` on non-Christian books — a one-line requirement to record now, zero code today.
- Song-grain `IsChristian` is deliberately NOT added (a non-Christian song inside a Christian songbook is not a real case in this corpus; adding a second axis now would be the speculative column rule #20 forbids). If the merge ever needs it, it is one additive column + the view predicate.

---

## 3. The Work identity model

### 3.1 What already exists (and two corrections to the brief)

`tblWorks` (`schema.sql:3132-3174`): `Id`, `ParentWorkId` self-FK (`fk_work_parent … ON DELETE SET NULL`, `:3169-3170`), `Iswc CHAR(15) NULL` + **`UNIQUE KEY uq_iswc`** (`:3160`), `Ccli VARCHAR(50) NULL DEFAULT NULL` + **`UNIQUE KEY uq_ccli`** (`:3143`, `:3162` — "NULL rather than empty string so absent values coexist under uq_ccli"), `Bowi` + `uq_bowi`, `MusicBrainzWorkMBID` + `uq_mbwork`, `TuneName`/`TuneId` (#1741 P1), `Title`, `Slug` + `uq_slug`. `tblWorkSongs` (`:3180-3196`): PK `(WorkId, SongId)`, `IsCanonical`, `SortOrder`, `fk_work_song_song … ON DELETE CASCADE ON UPDATE CASCADE`. CRUD is `manage/works.php` (ISWC validated via the shared fold — `$validateIswc` delegates to `ihymns_canonical_iswc()`, `works.php:86-87`); **no Work control exists in Editor2** (`manage/editor/v2/` has 20 modules — metadata/credits/tags/links/media/… — none for works).

**Correction 1 — the uniqueness "discrepancy" is already resolved on `tblWorks`.** The brief said `tblWorks.Ccli` is `NOT NULL DEFAULT ''` with a non-unique `idx_Ccli` and that a doc-comment merely "aspires" to `uq_ccli`. In the actual tree, `migrate-works-identity.php` (#1741 P1, `.sql/migrate-works-identity.php:112-165`) already shipped `Ccli VARCHAR(50) NULL DEFAULT NULL` + `ADD UNIQUE KEY uq_ccli`, mirrored in `schema.sql:3143/:3162`. The `NOT NULL DEFAULT '' + idx_Ccli` column is **`tblSongs.Ccli`** (`schema.sql:288`, `:329`) — and that one is correctly non-unique: many songs legitimately share a CCLI (`IHYMNS_ID_SCHEMES['ccli']['multiSong'] => true`, `identifier_normalize.php:112`). **No uniqueness fix is needed** — the remaining obligation is only that the write core must **gate on the columns existing** (`INFORMATION_SCHEMA`, the `tuneTunesTableExists()` pattern at `tune_helpers.php:127-143`), because migrations are web-run, not auto-applied (rules #19/#41): an un-migrated install has a `tblWorks` with no `Ccli` at all.

**Correction 2 — the normalisers already exist.** `ihymns_canonical_ccli()` (digits-only fold, `identifier_normalize.php:170-175`) and `ihymns_canonical_iswc()` (canonical `T-NNN.NNN.NNN-C` or `null` on malformed, `:142-152`) are the ONE folds (rule #22 — `works.php` already delegates). Nothing new is needed; the write core consumes them.

### 3.2 The relationship model — three orthogonal work relationships, plus section provenance

| Relationship | Table | Meaning | Example |
|---|---|---|---|
| **Variant hierarchy** | `tblWorks.ParentWorkId` (self-FK) | "this work is a variant/child of that work" — the ISWC parent ↔ CCLI-variant children of §3.3, plus suites/movements (#840's original intent, `schema.sql:3118-3121`) | *Amazing Grace* (ISWC parent) ← *Amazing Grace (My Chains Are Gone)* (its own CCLI) |
| **Rendering membership** | `tblWorkSongs` (Work↔Song M:N) | "this song record is a rendering of that work" | MP-31 and CP-208 are both renderings of one work |
| **Medley composition** | `tblWorkComponents` (NEW, Work↔Work M:N, §3.6) | "this work (with its own CCLI) is COMPOSED OF those independent works" | a *Crown Him / All Hail* medley with its own CCLI, composed of two works that also exist standalone |
| **Section source provenance** | `tblSongComponents.SourceWorkId` (NEW nullable FK, §3.6b) | "this SECTION of this song's rendering excerpts that work" — the block-by-block stitching of a medley rendering | verse 1 of the medley song sources *Crown Him*; the chorus sources *All Hail* |

They never substitute for each other: `ParentWorkId` is 1:N and means *is-a-variant-of*; `tblWorkComponents` is M:N and means *contains*; `tblWorkSongs` crosses the work/song grain boundary; `SourceWorkId` refines `tblWorkComponents` down to section grain on the *rendering* side (§3.6b spells out how the two reconcile).

### 3.3 Auto find-or-link — the decision list

Trigger: **commit** (change/blur) of the CCLI or ISWC field in Editor2's metadata tab — never a debounced keystroke. This is #1679 H1's exact reasoning restated (`metadata-tab.js:35-45`: an irreversible side-effect must not fire on a keystroke pause) and the Tune control's exact discipline (`saveTune()` fires on `'change'`, `metadata-tab.js:594-630`, backed by the find-or-create core `tuneFindOrCreateByName()`, `tune_helpers.php:189-226`). Note today CCLI/ISWC/ISRC are plain debounced `'text'` FIELDS rows (`metadata-tab.js:62-64`, kinds defined `:32`) — the field's own *save* may stay debounced; the *work-link side effect* hooks a separate `'change'` listener.

Inputs: the song's committed CCLI + ISWC. Normalise first: `$ccli = ihymns_canonical_ccli(...)`, `$iswc = ihymns_canonical_iswc(...)` (`null` = malformed ISWC → skip the ISWC branch, warn non-blocking; the save itself never fails on a work-link problem). Then:

1. **Both empty** → no-op. The auto-linker **never auto-unlinks** (clearing an identifier does not negate membership established by it or by hand; unlink is a manual action in the Works UI).
2. **ISWC present** → find-or-create the **parent work** `W_iswc` keyed by `Iswc = $iswc` (on create: `Title` = song title, `Slug` via the existing slug + collision-suffix loop — the `tuneSlugEnsureUnique()` shape).
3. **ISWC + CCLI** → find work `W_ccli` by `Ccli = $ccli`:
   - found and `W_ccli.Id === W_iswc.Id` (a hand-curated work carrying both ids) → link song to it; done.
   - found, `ParentWorkId` NULL or already `W_iswc.Id` → set/keep `ParentWorkId = W_iswc.Id` (**the re-home rule** — covers "CCLI first, ISWC later": precedence holds regardless of entry order); link song to `W_ccli`.
   - found, parented under a **different** ISWC work → **conflict**: do NOT re-home; link song to `W_ccli` anyway, log + surface a review hint (v1: `error_log` + editor toast pointing at `/manage/works`; a review-queue row is a follow-up, §5.10).
   - not found → create child work (`Ccli = $ccli`, `ParentWorkId = W_iswc.Id`, title/slug from the song); link song to it.
4. **CCLI only** → find-or-create a standalone work keyed by `Ccli = $ccli`; `ParentWorkId` untouched (NULL on create); link song.
5. **Link** = `INSERT … ON DUPLICATE KEY UPDATE WorkId=WorkId` into `tblWorkSongs` — the `(WorkId, SongId)` PK makes re-save a structural no-op (**idempotency guaranteed by the PK**, `schema.sql:3188`). The song links to the **most specific** work only (the CCLI work when a CCLI exists, else the ISWC work); parent-level song listings derive by `ParentWorkId` traversal — no double-linking, so "Part of work: X (N songs)" counts stay honest (§5.5).
6. **Races**: an ISWC/CCLI `uq_*` collision between find and create (two curators, same new identifier) is caught as mysqli 1062 under STRICT reporting and resolved by re-SELECT — byte-for-byte the TOCTOU discipline `tuneFindOrCreateByName()` documents (`tune_helpers.php:172-180`).

**The invariant this yields** (and why "adopt the CCLI onto the ISWC work" was rejected): the auto-linker never writes a `Ccli` onto an ISWC-keyed parent. If it adopted the first CCLI and childed subsequent ones, the same two facts entered in different orders would produce different graph shapes — violating the owner's "precedence holds regardless of entry order". One-work-row-per-distinct-CCLI, one-parent-row-per-distinct-ISWC is **order-independent and convergent**: any entry sequence reaches the same final graph. Hand-curated works carrying both ids remain first-class via branch 3's first bullet.

### 3.4 The ONE shared write core

`workFindOrLinkByIdentifier(\mysqli $db, string $songId, string $ccliRaw, string $iswcRaw): array` in a **new `includes/work_admin.php`** (rule #22 — one shared core, all callers delegate; the file naming mirrors `publisher_admin.php`/`org_logo_admin.php`). Contract:

- Framework-free, runs inside the **caller's transaction** (the `songRelocate()` contract, `song_relocate.php:159-166`); mysqli STRICT means statements throw, never return false.
- Gated: `tblWorks` absent, or the `Ccli` column absent (pre-`works-identity` install) → return a typed "environment not ready" result, never a throw that costs the curator their save (the `tuneFindOrCreateByName()` degrade posture).
- Pure decision core split from the I/O (the `songRelocateCascadeGaps()`/`…Verdict()` pattern, `song_relocate.php:103-115`): `workLinkPlan(?array $byIswc, ?array $byCcli, string $ccli, string $iswc): array` is a pure function from "what the DB currently holds" to "the writes to perform" — so §3.3's decision table is **called in a test**, not read out of the source (rule #34).
- Callers: (a) the new api2 endpoint `song_work_autolink` (state-changing → `validateCsrfRequest()`, rule #29) fired by Editor2's commit listener; (b) the manual "Part of work" picker's `song_work_set`; (c) later, the importer funnels (`song_importers.php`, `lyrics_ingest.php`) — same core, phase-gated (§4).
- Returns `{workId, workTitle, songCount, created:bool, rehomed:bool, conflict:?string}` so the client renders the badge from what the server actually stored, never from what it sent (rule #35).

### 3.5 Secondary / other-catalogue ids — `tblWorkExternalIds`

For ASCAP/BMI/…, MusicBrainz, and future registries: an extensible key/value table mirroring `tblSongExternalIds` (`schema.sql:3554-3572`) rather than a column per registry (rules #15/#20):

```sql
CREATE TABLE IF NOT EXISTS tblWorkExternalIds (
    Id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    WorkId    INT UNSIGNED NOT NULL,
    IdType    VARCHAR(40)  NOT NULL COMMENT 'app-validated against WORK_IDENTIFIER_TYPES (media_identifiers.php:326) — VARCHAR not ENUM, rule #20',
    IdValue   VARCHAR(191) NOT NULL,
    Source    VARCHAR(40)  NULL DEFAULT NULL,
    SourceRef VARCHAR(191) NULL DEFAULT NULL COMMENT 'idempotent re-import key — the (Source, SourceRef) pattern, rule #20',
    CreatedAt TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Type_Value (IdType, IdValue),
    INDEX idx_Work (WorkId),
    CONSTRAINT fk_WorkExtIds_Work FOREIGN KEY (WorkId) REFERENCES tblWorks(Id) ON DELETE CASCADE
) …
```

Two deliberate deltas from `tblSongExternalIds`: the UNIQUE is **table-wide `(IdType, IdValue)`**, not per-entity — a work identifier maps to at most ONE work globally (unlike recordings, where `uq_Song_Type_Value` is per-song because ISRCs repeat across songs); and no `IdScope` column — everything here is work-grain by construction. The vocabulary is the **existing** `WORK_IDENTIFIER_TYPES` registry (`media_identifiers.php:326-355`), which today maps `iswc/bowi/ccli/musicbrainz-work` to `tblWorks` columns and parks the eight PRO/society ids on the per-song `tblSongRoyaltyIds` — this table gives those a work-grain home later by flipping their registry `storage` entry, no new vocabulary list. **The denorm `Iswc`/`Ccli`/`Bowi`/`MusicBrainzWorkMBID` columns stay the primary keys the §3.3 parent/child logic reads** (they carry the `uq_*` keys and the resolver already queries them — `_ihymns_resolve_work()`, `identifier_resolve.php:149-162`); `tblWorkExternalIds` is the extensible overflow, not a replacement.

### 3.6 Medley — `tblWorkComponents`

A medley is a Work with its **own CCLI**, COMPOSED OF several independent constituent works — many-to-many, NOT `ParentWorkId` (§3.2):

```sql
CREATE TABLE IF NOT EXISTS tblWorkComponents (
    MedleyWorkId    INT UNSIGNED NOT NULL,
    ComponentWorkId INT UNSIGNED NOT NULL,
    SortOrder       INT UNSIGNED NOT NULL DEFAULT 0,
    Note            VARCHAR(255) NULL,
    CreatedAt       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (MedleyWorkId, ComponentWorkId),
    INDEX idx_component (ComponentWorkId),
    CONSTRAINT fk_medley_work    FOREIGN KEY (MedleyWorkId)    REFERENCES tblWorks(Id) ON DELETE CASCADE,
    CONSTRAINT fk_component_work FOREIGN KEY (ComponentWorkId) REFERENCES tblWorks(Id) ON DELETE CASCADE
) …
```

App-level guards in the write core (the DB cannot express them): `MedleyWorkId !== ComponentWorkId`, and a bounded-depth traversal rejecting cycles (A contains B contains A) — the "transitive + cycle-guarded" posture `tblSongRedirects` resolution documents (`schema.sql:2396`). The medley work itself still links its own song record(s) via `tblWorkSongs` (a recorded medley IS a rendering of the medley work); the constituents keep their own memberships untouched.

### 3.6b Section-level source provenance — `tblSongComponents.SourceWorkId`

A medley is stitched **block-by-block** — verse from Work A, chorus from Work B. The work-grain `tblWorkComponents` says *which* works a medley contains but not *which section came from which* — which is exactly what per-constituent CCLI/rights apportionment needs. One additive nullable column on the section table:

```sql
ALTER TABLE tblSongComponents
    ADD COLUMN SourceWorkId INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Optional provenance: the Work this section excerpts (medley stitching). Links the WORK, not a songbook song, so it survives songbook re-keys (#1679). NULL = whole-song default (the song''s own tblWorkSongs membership).',
    ADD INDEX idx_SourceWork (SourceWorkId),
    ADD CONSTRAINT fk_Component_SourceWork
        FOREIGN KEY (SourceWorkId) REFERENCES tblWorks(Id) ON DELETE SET NULL;
```

- **Why the WORK, not a song**: a `SongId` FK would re-key on every songbook move (`song_relocate.php` re-keys `SongId`; the cascade would carry it, but the *meaning* — "this verse comes from that composition" — is work-grain, not rendering-grain). `tblWorks.Id` is immutable. `ON DELETE SET NULL` (not CASCADE): deleting a work must never delete a song's section.
- **Fits the existing shape**: `tblSongComponents` is now the thin structural table (`Id, SongId, Type, Number, SortOrder, Language` — the JSON payload columns were retired in C6, rule #25), and it already carries exactly one precedent for a per-section override: `Language VARCHAR(35) NULL … "Used for multi-language medleys (#858)"` (its own comment). `SourceWorkId` is the same idea — a nullable per-section refinement of a song-level default, saved through the SAME `component_upsert` funnel (which "PRESERVES" untouched fields — `structure-tab.js:253`), NOT through the lyric-lines write core (it is component metadata, not line content, so rule #25's one-write-path is unaffected).
- **NULL semantics**: NULL = "no per-section claim" — the section inherits the song's ordinary work membership (`tblWorkSongs`). Non-medley songs never set it; the column is dormant for the whole existing corpus.

**Reconciling `SourceWorkId` with `tblWorkComponents`** — the two sit at different grains (section-of-a-*rendering* vs work-contains-*work*), so neither can simply replace the other: a medley *work* can exist in the registry with no stitched song record in iHymns at all, so `tblWorkComponents` cannot be purely derived; equally the section links are strictly more informative where they exist. The design:

1. **Section links are the fine-grained source of truth** wherever a stitched rendering exists. Rights/CCLI apportionment reads them first: each section attributes to `SourceWorkId`'s work (and its `Ccli`); sections with NULL fall back to the song's work membership.
2. **`tblWorkComponents` is kept in lockstep, additively**: when a section's `SourceWorkId` is set on a song that is a member (via `tblWorkSongs`) of a medley-shaped work, the write core upserts the matching `(MedleyWorkId, ComponentWorkId)` row — the same idempotent `INSERT … ON DUPLICATE KEY` shape as §3.3.5. Removal is **manual only** (clearing a section link never auto-deletes the work-level component row — the §3.3.1 "never auto-unlink" posture; the work-level statement may have been made independently). So the work-level set = (hand-curated rows) ∪ (rows derived from section links), and a summary view can flag work-level rows no section link supports for curator review.
3. Where only the coarse statement exists (no stitched song, or a legacy medley entered before this lands), `tblWorkComponents` alone answers "what does this medley contain" — degraded but correct.

### 3.7 Editor2 wiring

1. **Auto-link, silent-but-shown**: `'change'` listeners on the CCLI + ISWC inputs (`metadata-tab.js:62-63`) call `api.workAutolink(songId)` after the field save lands; the response renders a **"Part of work: \<Title\> (N songs)"** line under the fields — exactly the Tune badge pattern (read-only enrichment rendered from server truth, `metadata-tab.js:755-758`). Conflict → warning toast, save unaffected.
2. **Manual "Part of work" picker** (public-domain hymns with no CCLI/ISWC): a bespoke live-search control reusing the shared typeahead attach (`window.iHymnsPlaceSearch.attach` with `pickMode:'value'` — the Tune control's exact reuse, `metadata-tab.js:730-750`) against a new `work_search` action; find-or-create on commit via the same write core (a manual pick is `workFindOrLinkByIdentifier` with an explicit `workId` shortcut path). Never a plain FIELDS row (the `tuneName`-deletion lesson, `metadata-tab.js:65-71`).
3. **Constituent-works (medley) editor**: lives on **`/manage/works`** (work-grain data belongs on the work's own CRUD page; `manage/works.php` already owns member-song management) as a card-list reusing the established chip-list conventions; Editor2 shows constituents **read-only** inside the "Part of work" line ("Medley of: A, B, C"). Putting a work↔work editor inside a *song* editor crosses grains and is deferred unless the owner overrules (§5.7).
4. **Per-section "Source work" picker** (§3.6b): an optional control on each component card in the Structure tab (`structure-tab.js` — cards are built once and self-update, `structure-tab.js:1-9`), rendered exactly where the per-section language override already sits (`structure-tab.js:185-195`) and **reusing the same find-or-create Work picker as item 2** (one typeahead wiring, rule #22). Saved through the existing `component_upsert` granular save (like `langInput`), so no new endpoint; the write core's lockstep upsert (§3.6b.2) runs server-side inside that handler. Hidden (or collapsed) unless the song is linked to a work or the curator expands it — a non-medley song's Structure tab looks unchanged.
5. Rule #33 sweep at build time: any deep link emitted at the new panels (`/work/<slug>`, `/manage/works?id=…`) is grepped for consumers, and `tests/test-editor-deep-links.js` picks up new entries automatically.

---

## 4. Phased migration plan (each phase additive, idempotent, independently shippable)

Ordering rule: **schema first and dormant, cores second, backfills third, UI last** — at no point does a half-applied state misbehave (an un-migrated install simply keeps NULL `IlId`s and an inert work-linker, all gated on `INFORMATION_SCHEMA` probes).

**Phase 0 — design sign-off.** This document + the §5 decisions. Blocks nothing else; nothing ships before it.

**Phase 1 — the identity DDL batch (one migration script, one registry entry).** `tblIlyricsIdSequence` + the eight `IlId VARCHAR(16) NULL` columns/`uq_IlId` keys + `tblWorkExternalIds` + `tblWorkComponents` + `tblSongComponents.SourceWorkId` (§3.6b — additive, nullable, dormant), designed one-pass with an adversarial "what would force a second migration?" stress (rule #20; the #1066/#1088 worked examples). Obligations (rule #19): byte-identical `schema.sql` mirrors in the same commit; `@migration-adds` doctags per column (multi-column ALTERs); ONE `migration-registry.php` entry with a **multi-object OR-probe** (`!tableExists(tblIlyricsIdSequence) || !columnExists(tblSongs,'IlId') || … || !tableExists(tblWorkComponents)`) so a partial apply never shows green; any shared-include `require` follows the `IHYMNS_INCLUDES_DIR` rule (#41). CI: `test-schema-coverage.php`, `test-migration-registry.php` pass; everything dormant — zero readers.

**Phase 2 — allocator core + backfill + mint-on-create + namespace guard.** `includes/ilyrics_id.php` (`IHYMNS_ILID_TYPES`, `ilidAllocate()`, `ilidFormat()`, `ilidParse()` — parse is the shared normaliser §2.5's resolvers reuse); backfill migration card (Id-order, NULL-only, seeds `NextValue`); wire `ilidAllocate()` into every create funnel — songs: `save_song_core.php`, `api2.php`, `song_importers.php`, `lyrics_ingest.php` (the four `INSERT INTO tblSongs` sites); plus the works/musicians/tunes/publishers/catalogues/songbooks/media create paths — each column-existence-gated (un-migrated → NULL, backfill card catches up). Reserve the `IL*` prefixes in the songbook-abbreviation validators (§2.2). Tests: allocator concurrency (two-connection `FOR UPDATE` test), format/parse truth table, a **tree-derived** guard that every `INSERT INTO tbl<entity>` funnel mints (rule #34 — derived from the tree, then mutation-proven by breaking one funnel).

**Phase 3 — work write core.** `includes/work_admin.php` (`workFindOrLinkByIdentifier()` + the pure `workLinkPlan()`), api2 `song_work_autolink` / `work_search` / `song_work_set` (CSRF per rule #29; failure kinds by HTTP status per rule #35). Tests: the §3.3 decision table as unit tests over `workLinkPlan()` (all six branches incl. re-home, conflict, order-independence: assert both entry orders converge to the identical graph), idempotency (second call = zero writes), 1062-race handling.

**Phase 4 — dual-addressing resolvers.** The §2.5 ladder branches: `getSongById()` strategy 1.5; the slug-page pre-steps (work/musician/tune/publisher/songbook); `song-media.php` `ILD`; the `/il/<id>` universal route; API-validator acceptance; the cosmetic `normalizeSongId()` pass-through. Tests: a registry-derived route-coverage test (the `test-identifier-routes.js` pattern, `identifier_normalize.php:104-107`), plus per-entity "both forms, same record" assertions including the un-padded client form.

**Phase 5 — Editor2 + works UI.** §3.7 items 1–4 (auto-link badge, manual picker, medley card-list on `/manage/works`, per-section Source-work picker in the Structure tab + the `component_upsert` handler's `SourceWorkId` acceptance and lockstep upsert); the admin "Permanent ID" display rows. Deep-link sweep (rule #33).

**Phase 6 — deferred, gated on the DB-merge decision (#1010).** The cross-child allocation API endpoint (auth via `tblApiKeys`, idempotent retries via the dormant `tblApiKeyIdempotency`); any `tblSongIdentityMap` convergence; public surfacing of `IL*` ids beyond the resolvers. None of this is designed-in-detail here — deliberately (rule #20: no guessed bridge).

---

## 5. Open owner decisions (recommended default in bold; none blocks Phase 1's DDL except #1–#3)

1. **ID width/format/separator** — **`ILS-0000012345`: 3-letter prefix, hyphen, 10 digits** (§2.2). Alternatives: 8 digits (risks exhaustion in an all-genres corpus); no hyphen (harder to read/dictate; loses the visual kinship with `MP-1008`).
2. **Unified vs per-type allocator table** — **one `tblIlyricsIdSequence` with an `EntityType` row per type** (§2.3). Per-type tables buy nothing (row locks already isolate types) and cost 8× the registry surface.
3. **Per-type counters vs one global counter** — **per-type** (numbers stay dense within each family; the prefix already disambiguates). Global would let a bare number be unambiguous without its prefix, but the prefix is mandatory in the format anyway.
4. **Adopt-first-CCLI onto the ISWC parent, or always a child work** — **always a child** (order-independence argument, §3.3). Trivially changeable pre-Phase-3; changing it later reshapes graphs.
5. **Link song to most-specific work only, or to parent AND child** — **most-specific only**; parent listings derive by traversal (§3.3.5). Double-linking inflates song counts and complicates unlink.
6. **Auto-unlink when an identifier is cleared** — **never**; unlink is manual (§3.3.1).
7. **Medley constituent editor surface** — **`/manage/works`**, read-only in Editor2 (§3.7.3).
8. **Public exposure of `IL*` ids** — **resolvers accept them from Phase 4; pages don't advertise them yet** beyond an admin "Permanent ID" row; public display is a copy decision to take later (§2.5 canonical-URL policy).
9. **`ILD` scope** — **`tblSongMedia` only for now**; if a non-song document entity ever lands, it gets its own row in `IHYMNS_ILID_TYPES` then (one line).
10. **Work-parentage conflict handling** — **v1: log + toast pointing at `/manage/works`**; a persisted review queue (a `tblWorkLinkConflicts` or reuse of the duplicate-review pattern) only if conflicts occur in practice. Filed as a follow-up issue at Phase 3.
11. **Section-provenance ↔ `tblWorkComponents` reconciliation** — **section links are the fine source of truth where a stitched rendering exists; the work-level set is kept in lockstep additively (auto-upsert on section-link set, manual removal only)** (§3.6b). The pure-derived alternative was rejected because a medley work can exist with no stitched song record in iHymns.

None of these blocks the others' phases except that #1–#3 shape the Phase 1 DDL — they need answering first; the rest have safe defaults that are cheap to change at their own phase boundary.

---

## 6. Risks & future-proofing

- **The merge rides on nothing speculative.** The allocator table, `IlId` columns and `IL*` resolvers are all facts about *this* database; when iHymns' backend becomes iLyrics DB, they are already the central scheme. What stays dormant until the merge decision: the child-allocation API, bridge views, `tblSongIdentityMap` (frozen legacy, #1749 — untouched here), and `SourceOfTruth` convergence. No `IL*` column references iLyricsDB-keyed objects, so rule #20's gate is respected.
- **Counter integrity across restores.** A DB restore that rewinds `tblIlyricsIdSequence` behind the entity tables would re-mint taken ids — this is why `ilidAllocate()` claim-checks against `uq_IlId` before returning (§2.3), the exact lesson `songRelocateIdTaken()` encodes (`song_relocate.php:283-301`: the counter seed and the claim set are different things).
- **Prefix collision with songbook abbreviations** is the one genuinely sharp edge (§2.2); it is closed by the Phase 2 validator guard + a mutation-proven CI test. Until Phase 2 lands, no `IL*` id exists to collide, so ordering is safe.
- **Client mangling of `IL*` URLs** (`normalizeSongId()` re-pads to 4 digits) is absorbed by server-side padding tolerance (§2.5) — stale PWA caches and native apps need no lockstep upgrade (rule #35: the server contract, not client prose, is the mechanism).
- **Un-migrated installs** (3 docroots, one shared MySQL, web-run migrations — rule #28C's environment): every reader/writer gates on `INFORMATION_SCHEMA`; degraded behaviour is "no IlId yet / no auto-link yet", never an error, never a fatal in the setup-database runner (rule #41 for the migration scripts themselves).
- **Guard quality**: every CI guard this program adds must be tree-derived and mutation-proven (rule #34) — the funnel-coverage test in Phase 2 and the route-coverage test in Phase 4 are the two that would otherwise be wrong-but-green.
- **Line-level source provenance — design-compatible, EXPLICITLY DEFERRED.** Individual lyric *lines* could in principle carry a source-work link (a medley that splits mid-verse). The infrastructure already exists: per-line enrichment anchors on `tblLyricLines.Id` (BIGINT PK) — `tblLyricLineTranslations` and `tblLyricLineAnnotations` are the shipped siblings (#1088/#1235, rule #21) — so a future `tblLyricLineSourceWork` (`LineId` FK → `tblLyricLines.Id`, `SourceWorkId` FK → `tblWorks.Id`, span semantics mirroring the annotations table's `StartLineId`/`EndLineId` if ranges are wanted) is a purely **additive sibling table requiring NO re-migration of anything in this program**: nothing in §3.6b assumes section grain is final — a line-grain row simply *overrides* its section's `SourceWorkId` in the apportionment read, the same NULL-inherits ladder. Deferred deliberately (NOT in Phases 1–5): low ROI today (few medleys split mid-verse), editorial-heavy to populate, and shipping the table now with no consumer would be dormancy without a design driver — the wrong side of rule #20's line. Revisit only if section-grain apportionment proves too coarse in practice.
- **What this deliberately does not do**: no re-key of any existing id; no change to `PublicId` or `tblSongRedirects` semantics; no `tblWorks` storage merge with songs; no per-song `IsChristian`; no line-grain provenance yet (above); no ENUMs anywhere in the new DDL (every vocabulary is VARCHAR + central map, rule #20).

---

*Grounding files read for this doc: `appWeb/.sql/schema.sql` (tables cited by line), `appWeb/.sql/migrate-works-identity.php`, `includes/song_relocate.php`, `includes/SongData.php`, `includes/tune_helpers.php`, `includes/identifier_normalize.php`, `includes/identifier_resolve.php`, `includes/media_identifiers.php`, `includes/song_external_ids.php`, `manage/works.php`, `manage/editor/api2.php` (`ed2_allocateSongId`), `manage/editor/v2/metadata-tab.js`, `js/modules/router.js` (`normalizeSongId`); CLAUDE.md rules #14, #19, #20, #22, #24, #27, #28, #29, #33, #34, #35, #37, #41.*

# Lyrics & song-info storage normalisation — strategy

> Status: **APPROVED + IN BUILD.** Owner chose **Option C (pure normalisation)** —
> `tblLyricLines` is the single source of truth; part identity on the line;
> components derived; JSON arrays retired. Tracking epic: **#1235**.
> Companion to the #1200 Song Editor rewrite. Author: pairing 2026-06-10..12.

## 0. Progress & RESUME POINT (2026-06-12 — session 3)

**NEW (session 3): the full P4 cutover is now planned against a real production DB dump
(16,083 songs / 70,132 components / 291,634 lines / parity 0 / all 7 line-FK enrichment
tables at 0 rows) — see the §11 PLAN OF RECORD (DRAFT, awaiting owner sign-off). NO P4 code
is written. Three findings change the picture and need an owner call:**

1. **"Pure C" is foreclosed → the C-variant is FORCED (re-confirm §4).** 23.7% of the corpus
   (3,815 songs / 14,036 components) have duplicate `(PartType, PartNumber)` keys — interleaved
   repeat-choruses (CP-0220 has five *different* `chorus` components), adjacent same-key/
   different-text (CP-0056), and entirely separate verse-sets (CP-0110 has two different
   verse-1/2/3 blocks). So deriving components by `GROUP BY PartType, PartNumber` (the §4
   pure-C model) is **lossless-impossible**. P4 keeps `tblSongComponents` as a THIN metadata/
   ordering row; `tblLyricLines.ComponentId` is the grouping anchor (proven lossless on the
   real data). Pure-C component-removal becomes a separately-gated future phase, if ever.
2. **Two real data-loss bugs in the CURRENT write path** (independent of P4) must be fixed on
   the P3 branch BEFORE it is pushed: **R1** (a stale SW-cached editor POSTing lines-only wipes
   `ChordsJson`/`LanguagesJson` song-wide) and **R3** (a retype+edit save can CASCADE-delete
   enrichment once P3's translation/annotation UIs go live). Both are surgical server-side
   guards, not rework — see §11.1 PF1/PF2.
3. **Now is the cheapest cutover window that will ever exist** — all 7 line-FK enrichment tables
   are dormant (0 rows), so making lines authoritative orphans nothing. The window closes the
   moment P3's enrichment UIs start writing line-anchored data.

**UPDATE (session 3): P1+P2a+P2b+P3 (incl. the R1/R3 PF1/PF2 fixes) SHIPPED to alpha via
PR #1259 (`7168606d`, MERGED). P4 is now IN BUILD on `feat/lyrics-1235-p4` (draft PR #1262):
C1 assembler + C2 PartTypeSlug + C3 verification tooling + C4 read switch DONE (zero-data-loss,
CI-green). RESUME = C4 bypass-reader cleanup → C5 write inversion → C6 drop (soak + manual run).
Full session detail: `.claude/sessions/2026-06-12-HANDOFF-session3.md`. The P3 editor-UI detail
is in §0.1; the P4 plan-of-record is §11; the data-quality finding (pure-C re-opened) is §12.**

| Phase | What | Status |
|---|---|---|
| **P1a** #1247 | shared projector `lyricLinesProjectSong()` + `tblLyricLines` `ChordsJson`/`Note` + whole-catalogue backfill | ✅ verified on alpha (16,081 songs / 291,478 lines, parity 0) |
| **P1b** #1251 | transitional dual-write across all component-write paths | ✅ verified |
| **P2a** #1252 | read-switch — line TEXT from `tblLyricLines`, byte-identical | ✅ verified on 6 songs |
| **P2b** Id-preserving diff | `lyricLinesProjectSong()` now diffs pre→post lines by CONTENT (part identity + text, NOT `ComponentId` — it churns on legacy save), 3-pass (same-part exact → any-part exact → same-part fuzzy ≥0.5 code-point), dirty-checked + idempotent. Pure helpers unit-tested (`tests/php/test-lyric-lines-diff.php`, 47 assertions, fuzz-reviewed). **`lineIds[]` exposed** in `SongData` + `data/songs.schema.json` | ✅ **committed `c3c372d7`** |
| **P3 enrichment write API** | shared `includes/line_enrichment.php` (translations + annotations CRUD; vocab allow-lists, derived LyricsId, ownership-enforced, code-point offsets, `bindParamSafe` labelled binds) + 4 api2.php handlers (`line_translation_upsert/delete`, `line_annotation_upsert/delete`) + `load_song` returns `lineTranslations`/`lineAnnotations`. READ side already shipped via #1099 `getSongDetailExtras`. Validators unit-tested (`tests/php/test-line-enrichment.php`, 21) | ✅ **committed `d545f6cf`** |
| **P3 per-line language** | `tblSongComponents.LanguagesJson` (parallel array, durable home that survives reprojection) — migration + schema.sql + registry + projector reads it (`LanguagesJson[i] ?? component lang`) + `component_upsert`/snapshot write it + `SongData` emits sparse `lineLanguages[]` | ✅ **committed `65c9c818`** |
| **P3 editor UIs** | (1) #1253 per-component + per-line language in `editor.js` (datalist input accepting full BCP 47 + collapsible per-line-languages textarea) + api.php save/load wiring; (2) collapsible per-line **translation + annotation editor** (chips + add/delete) POSTing to the CSRF-protected api2.php. | ✅ **committed `d2b11bb4` (language) + `9bd5743d` (enrichment)** — backend e2e-verified vs a local MySQL (17/17); editor.js node-checked; **UI click-through still wants a live browser pass** (collapsibles, datalist render, api2 round-trips) |
| **P4** | drop `LinesJson` / `ChordsJson` / `NotesJson` after a verify gate | TODO |

> **Active vs rewrite editor (important):** the live editor is `editor.js` + **api.php**
> (whole-song save, session-only, no CSRF). The P3 backend first landed on **api2.php**
> (the in-progress granular rewrite). So the language axis + enrichment load were ALSO
> wired into api.php (reusing the shared `line_enrichment.php` / `lyric_lines_sync.php`),
> and the enrichment EDITOR posts to api2.php with a CSRF token now exposed via
> `window.IHYMNS_EDITOR_CSRF`. Because the legacy editor edits lyrics as a textarea (no
> per-line DOM) and line Ids exist only post-save, enrichment is a **save-then-enrich**
> workflow there; the richer inline experience belongs to the rewrite editor.

### 0.1 P3 editor UIs — precise plan (the only thing left; backend is done)

The entire P3 backend + read API is committed and CI-green. What remains is purely
front-end wiring to endpoints that already exist. Build with the app running
(`/run` + `/verify`).

1. **BCP 47 picker #1253 (per-component + per-line).** The shared picker
   (`js/modules/ietf-language-picker.js` + `manage/includes/partials/ietf-language-picker.php`,
   full lang+script+region+variant, IANA/CLDR-seeded) already works at SONG level.
   - **Per-component:** replace the language-only `<select>` in `editor.js` (~L1079-1114,
     bound to `comp.language`) with a compact instance of the shared picker. The
     module needs a **compact mode** (single control or popover, not the 4-input row);
     extend the partial/module with a `$compact` flag rather than forking.
   - **Per-line:** new high-density control writing into `comp.languages[i]` (the
     parallel array). `component_upsert` already accepts `component.languages[]` and
     `load_song` returns `component.languages` — so the editor just needs the control;
     the save path is done.
   - Route component + line language validation through the canonical
     `_ietfBcp47Validate` (already done server-side in `ed2_buildLanguagesJson`).
2. **Translation editor UI.** Per lyric line (the editor now has `lineIds` via
   `load_song`'s components + the new `lineTranslations` array): add/edit/delete
   translation rows. POST `action=line_translation_upsert` `{ songId, translation:{
   id?, lineId, kind, targetLanguage, text, translationType?, isPrimary?, status? } }`
   and `line_translation_delete { songId, id }`. Use the BCP47 picker for
   `targetLanguage`; `kind` ∈ {translation, transliteration}.
3. **Annotation editor UI.** Select a line (or a span: `startLineId`+`endLineId`,
   optional code-point `startOffset`/`endOffset`) and write a gloss. POST
   `action=line_annotation_upsert { songId, annotation:{ id?, startLineId, endLineId?,
   startOffset?, endOffset?, annotationType, body, bodyFormat?, languageCode? } }` and
   `line_annotation_delete`. `annotationType` ∈ {explanation, reference, scripture,
   history, translation, trivia}. **Render note:** `bodyFormat='html'` must be
   sanitised on render (stored-XSS otherwise); default `markdown`.

**Auth note:** api2.php is session+CSRF editor auth. The native/token (`ihymns_auth`)
write path the strategy §10 envisions is tracked with **#1201** (reuse the SAME
`includes/line_enrichment.php` functions — they take a `\mysqli`, no session
coupling). Don't fork the write logic.

**Key facts for the resumer:**
- The shared projector is `appWeb/public_html/includes/lyric_lines_sync.php` — reuse it; never re-fork. `_mirrorLinesByComponent[Map]()` in `SongData.php` already fetch each line's `Id` internally (P3 just exposes them).
- **DEPLOY GOTCHA (cost us hours):** a `.sql/` migration that `require`s a NEW `public_html/includes/` file must resolve it **`DOCUMENT_ROOT`-first**, not `dirname(__DIR__)/public_html` — the sibling tree on the server is stale for brand-new includes. Pattern is in `migrate-lyric-lines-mirror.php`. Also: the SFTP deploy now content-compares (no `--only-newer`) + has a `timeout-minutes` cap (PRs #1249/#1250). Codified in `project-rules.md`.
- **#1243 (musical metadata — Key/Tempo/Time-sig/Capo)** is the sibling `ArrangementJson`-vs-table split-brain; same Option-C treatment, plan together.
- Sibling owner-decision still open: **beta→main promotion** (fixes live-prod songs_json/sitemap 500s) — independent of the lyrics epic.

## 1. Why this exists

The rework's founding principle is **a single source of truth** — it exists to kill
the DB-vs-JSON/SQLite-file drift that caused cross-environment staleness (epic #1010).
Lyric line storage currently violates that principle: there are **two parallel
representations of the same lyric lines**, and they are not kept in sync.

The owner's directive: do it **properly now**, while the editor save-path is being
rebuilt from scratch — *not* a dual JSON-plus-table compromise. This is also the
**shared data contract for iLyricsDB** (the lyrics backend iHymns will merge with),
so the canonical line model is load-bearing well beyond the iHymns editor.

## 2. The current model (split-brain)

**Representation A — component-centric (authoritative TODAY; what the editor + public render use):**

```
tblSongs (SongId)
  └─ tblSongComponents (FK SongId)         one row per verse/chorus/bridge
       • Type / Number / SortOrder / Language(#858)
       • LinesJson   JSON  ← the lyric lines, as an array        ← AUTHORITATIVE
       • ChordsJson  JSON  ← per-line chords, parallel array
       • NotesJson   JSON  ← per-line presenter notes, parallel array
```

**Representation B — version-centric (normalised; currently INGEST-ONLY, written only by `lyrics_ingest.php`):**

```
tblSongs (SongId)
  └─ tblLyrics (FK SongId)                 a lyrics VERSION: Source / IsPrimary /
       │                                   Status / HasTiming/HasWordTiming/HasSyllableTiming
       └─ tblLyricLines (FK LyricsId, nullable ComponentId)   one row per line  ← NORMALISED
            • LineText / SortOrder
            • PartType / PartTypeSlug / PartNumber  (verse/chorus identity ON the line)
            • StartTimeMs / EndTimeMs               (sync/karaoke timing)
            • LanguageCode                          (per-line language)
            • MetaJson                              (lossless TTML attrs)
            ├─ tblLyricLineTranslations (FK tblLyricLines.Id)   per-line translations/romanisations
            └─ tblLyricLineAnnotations  (FK tblLyricLines.Id)   per-line annotations (Genius-style spans)
```

Plus whole-song siblings (out of scope to change here): `tblSongTranslations` (separate
translated song records), `tblSongChords` (legacy chord table), `tblSongs.Language`.

**The problem:** the line text lives in BOTH `tblSongComponents.LinesJson` and (when ingested)
`tblLyricLines.LineText`. The editor/render use A; rich timed ingest uses B; `ComponentId`
is a nullable "transitional" bridge. Anything needing stable per-line identity (timing,
per-line language, translations, annotations) can only anchor on B — but B isn't authoritative
or kept in sync, so those features can't go live without first resolving the split-brain.

## 3. The decision

**`tblLyricLines` becomes THE single source of truth for lyric lines.** The JSON arrays
(`LinesJson` / `ChordsJson` / `NotesJson`) are **retired as storage** — the line array is
*assembled on demand* at the export boundary (ProPresenter / OpenLyrics / LRC / TTML), never
persisted as a rival truth. Chords, notes, timing, language, translations and annotations all
hang off the line's stable `Id`. One place. Nothing to sync, because there is no second copy.

Rejected: keeping `LinesJson` authoritative + a synced `tblLyricLines` projection — that is the
exact dual-source-of-truth pattern the rework is meant to eliminate, and it *will* drift.

Note: a line *array* is a **transport format**, not a storage requirement. A blob is not faster
than `SELECT … WHERE LyricsId = ? ORDER BY SortOrder` (one indexed query, a few hundred rows max);
the "one blob read" argument is PoC-era. So normalisation costs us essentially nothing on read.

## 4. The one decision the owner must make — version / structure / line relationship

`tblLyrics` is a **version** (provenance + primary + status + timing flags); a song can legitimately
have several (canonical ihymns + an Apple-Music-TTML import + a user submission). The open question
is how the **verse/chorus structure** relates to versions and lines. Three options:

- **Option A — version owns structure.** `tblLyrics → components(per version) → tblLyricLines`.
  Most flexible (each version can break lines/structure differently — common: a TTML import differs
  from the hymnal layout). Requires `tblSongComponents` to FK `LyricsId` (today it FKs `SongId`).
- **Option B — song owns structure, version owns lines.** Components stay per-song (shared structure);
  each version's lines map onto them. Simpler only if all versions share one structure — they often
  don't, so this gets awkward fast. Not recommended.
- **Option C — no component table; part identity lives on the line (RECOMMENDED).**
  `tblLyrics → tblLyricLines (FK LyricsId)`, each line carrying `PartType` / `PartTypeSlug` /
  `PartNumber` (it already has these). A "component" (verse 1, chorus) is a **derived grouping**
  (`GROUP BY PartType, PartNumber` in `SortOrder`), reconstructed for the editor + render. Chords /
  notes / translations / annotations FK the line. `tblSongComponents` (+ its JSON columns) is retired.
  Cleanest single-source model, naturally per-version, and `tblLyricLines` already carries everything
  Option C needs. The cost is a bigger editor refactor (the editor is component-centric today) and a
  derive-components-from-lines read layer.

**Recommendation: Option C** (or "C with a thin optional `tblSongComponents` kept *only* as
component metadata — type/number/order/language — and the lines moved to `tblLyricLines (FK ComponentId)`,
JSON columns dropped" as a less invasive variant if the editor refactor proves too large). The doc
asks the owner to pick C vs the C-variant before any code.

> **UPDATE (session 3, §0 / §11):** the real-data planning pass forecloses *pure* C — 23.7% of the
> corpus has duplicate `(PartType, PartNumber)` keys, so `GROUP BY PartType, PartNumber` cannot
> losslessly reconstruct components. **The C-variant is therefore the P4 scope** (keep the thin
> `tblSongComponents`; anchor grouping on `tblLyricLines.ComponentId`). Owner decision D-1 (§11.6)
> is to re-confirm this and record pure-C as a separately-gated future phase.

## 5. What hangs off the line (target)

| Concern | Where it lives (target) |
|---|---|
| Line text + order | `tblLyricLines.LineText` / `SortOrder` |
| Verse/chorus identity | `tblLyricLines.PartType` / `PartTypeSlug` / `PartNumber` (Option C) |
| Per-line language (#1206) | `tblLyricLines.LanguageCode` |
| Sync / karaoke timing | `tblLyricLines.StartTimeMs` / `EndTimeMs` (+ word/syllable later) |
| Per-line translations | `tblLyricLineTranslations` (FK line Id) |
| Annotations | `tblLyricLineAnnotations` (FK line Id) |
| Chords | per-line column / child on the line (replaces `ChordsJson`) |
| Presenter notes | per-line column / child on the line (replaces `NotesJson`) |
| Lossless TTML attrs | `tblLyricLines.MetaJson` |

## 6. Migration path (eyes-open — this is the real work)

**Existing data migrates IN PLACE — no re-import, no re-entry.** Every song, component, line, plus the
chords / notes / per-component language already in the catalogue is carried into the normalised model by
an automated backfill; curators never re-key or re-import anything. The source (`LinesJson`) is kept as a
fallback until the backfill is **verified**, so the migration is **safe, re-runnable, and reversible**
during the transition.

1. **Backfill migration (data — P1, the "no re-import" guarantee).** A one-shot, **idempotent**,
   re-runnable `migrate-*.php`, run via the standard Setup-Database "Apply all" mechanism with a real
   completion probe (rule #19). For every song it:
   - ensures a primary `tblLyrics` version (creates one, `Source='ihymns'`, if absent — idempotent via the
     `(SongId, Source)` unique, so re-runs + any prior ingest rows never duplicate);
   - reads each `tblSongComponents` (in `SortOrder`) and writes its `LinesJson` lines as `tblLyricLines`
     rows, carrying **part identity** (`PartType`/`PartNumber`), **per-component language**, **chords**
     (`ChordsJson`) and **presenter notes** (`NotesJson`) onto the line, preserving order;
   - skips components already projected, so it is safe to re-run after a fix.
   Batched + `set_time_limit(0)` for the ~12k-song catalogue (like the other long migrations). The whole
   existing corpus comes across automatically — **nothing is re-entered or re-imported.**
2. **Id-preserving editor save (the crux).** `component_upsert` (and the structure-tab) **diff** the
   edited text into line rows — insert new, update changed, delete removed, and **preserve `Id`s for
   unchanged lines** so existing timings/translations/annotations survive an edit. Naïve delete-all +
   reinsert would silently orphan all enrichment; this diff is the hardest, most important piece.
3. **Read-model switch.** `getSongById` / the public render / every reader + exporter read from
   `tblLyricLines` (grouped into components for display), and emit JSON arrays only at the export
   boundary. Fallback to `LinesJson` only for rows not yet backfilled (transition window).
4. **Editor refactor.** The structure-tab edits a derived component view; saves go through the diff.
5. **Verify, THEN drop.** Before any JSON column is dropped (P4): an automated consistency check (every
   component's line count == its `LinesJson` length; spot-checks of text / part / language / chords /
   notes) + a clean Schema-Audit. `LinesJson` stays as the read fallback until this passes, so a wrong
   backfill is fixable with **zero data loss**. Only then drop `LinesJson` / `ChordsJson` / `NotesJson`.

## 7. iLyricsDB shared-contract implications

`tblLyrics` (version/provenance) + `tblLyricLines` (the canonical line) + the per-line enrichment tables
become the **shared schema contract** for iLyricsDB. Getting the line model right here is getting the
multi-app, translation-and-sync-capable lyrics database right. A JSON blob would be a poor shared
foundation; a normalised per-line model is exactly what a shared lyrics DB wants. iLyricsDB versioning
already tracks iHymns until the backends merge — this is the merge's data contract.

## 8. Phasing (slots into #1200)

- **P0 (this doc):** owner approves the model (Option C vs the C-variant) + the phasing.
- **P1:** backfill migration + the read-model switch with `LinesJson` fallback (no behaviour change).
- **P2:** Id-preserving editor save + the structure-tab refactor → normalised writes.
- **P3:** per-line language (#1206 remainder) + sync timing + per-line translations go first-class.
- **P4:** drop the JSON columns; export-time array assembly only.
- Sequencing: lands **before** the Phase-5 editor cutover (otherwise we cut over on the half-model and
  migrate twice). Lower urgency than stabilising production (the songs_json/sitemap 500s) — that
  promotion should not wait on this.

## 9. Risks / open questions

- **The Id-preserving diff** is the make-or-break detail. Mis-matching lines on edit loses enrichment.
  Needs a clear matching rule (by `SortOrder` + text similarity, preserving Ids on move/minor-edit).
- **Multi-version songs** — confirm the editor's mental model (edit the primary version; other versions
  are imports/translations). Option C makes this natural; B does not.
- **Performance** — verify the grouped per-song line read is fine at scale (it is, indexed) before P4.
- **`tblSongChords` (legacy)** vs per-line chords — decide whether to fold it in or leave it.
- **Owner decision required:** Option C vs the C-variant (keep `tblSongComponents` as metadata-only).

## 10. API surface — the contract for ALL clients (web/PWA + native; read AND write)

**Hard requirement (owner):** the normalised model is exposed ONLY through the API — no client
touches the DB directly — and the **same API serves the web/PWA AND the native apps for EVERY
interaction**: retrieve, display, *and* administer/edit. The read + write API is therefore in scope
**with the model**, not a follow-on. It dovetails with the editor's `api2.php` and the broader
public/PWA API + OpenAPI redo (**#1201**), and it *is* the iLyricsDB shared contract.

**Read API** — one canonical lyrics shape every client renders from:
- A song's lyrics as the normalised **lines grouped into parts** (verse/chorus), with per-line
  enrichment addressable: language, line/word/syllable timing, translations, annotations, chords,
  notes, `MetaJson`. Slim/index reads for lists; full read for the song page.
- A song's lyric **versions** (`tblLyrics`) + the primary; fetch a specific version.

**Write API** — granular, atomic, used by NATIVE editors too (not just the web editor):
- Per-line / per-part CRUD on the api2.php model, extended for the normalised schema: create / update
  / delete a line; reorder; set part identity + number; set per-line language + timing; manage per-line
  translations + annotations; manage chords + notes. The **Id-preserving diff (P2)** is exposed so a
  native client editing text gets the same line-`Id` stability (timings/translations survive edits).
- Version ops: create/import a version, set primary, status transitions.
- **Dual auth:** the web editor uses CSRF; native apps use the `ihymns_auth` token — the write API must
  accept BOTH consistently.

**Contract stability:** native apps ship and lag, so the line/lyrics API must be **versioned, stable,
and OpenAPI-documented** — a breaking change to the line shape breaks deployed native apps. Same reason
it's the iLyricsDB contract: **one schema, one API, many clients** (web, native, iLyricsDB consumers).

**Phasing impact:** the **read API (P1)** and the **granular write API (P2)** land WITH the model,
fully covering retrieve / display / administer, before native clients build against it. Tracked
jointly with #1201.

> **P4 makes this a hard gate (§11.4):** the cutover must be invisible on the wire — the
> `song_detail`/`songbook_export` shapes are hashed pre/post (byte-identical required), `api-docs.yaml`
> is updated in the same PR, new line-anchored fields are additive-only, and one shared assembler
> (`lyricLinesAssembleComponents()`) is the single cross-client serializer (web/PWA, native, exports,
> iLyricsDB).

## 11. P4 cutover — PLAN OF RECORD (DRAFT 2026-06-12 session 3; awaiting owner sign-off)

> Built from a real production DB dump (16,083 songs / 70,132 components / 291,634 lines /
> parity 0 / all 7 line-FK enrichment tables at 0 rows) via a Fable-5 planning pass
> (3 ground-truth maps → migration + verification + adversarial designs → synthesis).
> **No P4 code is written.** This is the implementation contract once approved. Risk IDs
> (R1–R12) and gate IDs (G1–G13) below come from the adversarial pass and are load-bearing.

### 11.0 Recommendation & scope

Complete #1235 P3→P4 on the existing schema — **no redesign** — as the **C-variant**:
drop the four `tblSongComponents` JSON payload columns (`LinesJson`, `ChordsJson`, `NotesJson`,
`LanguagesJson`), **keep** `tblSongComponents` as the thin `Type/Number/SortOrder/Language`
metadata row, and use `tblLyricLines.ComponentId` as the grouping anchor (A1 proved component
boundaries + order are 100% reconstructable from lines alone: 0 non-contiguous ComponentId runs,
0 NULL ComponentId, group order ≡ component SortOrder). Pure-C is foreclosed by the 23.7%
duplicate-part-key measurement (§0 finding 1) — re-confirm in §4.

### 11.1 Pre-flight — amend P3 BEFORE push, then land it

- **PF1 — R1 stale-client parallel-array wipe (MUST; blocks P3 push).** `save_song` only
  re-persists `ChordsJson`/`LanguagesJson` when the POST carries them; a stale SW-cached pre-P3
  `editor.js` POSTing lines-only recreates components with NULL arrays, and the always-dirty
  projector UPDATE propagates the wipe to the line. **Fix (server-side carry-forward):** when a
  POST omits `chords`/`languages` for a position-matched existing component, re-attach the
  pre-delete component's arrays. Loses data *today*, independent of P4.
- **PF2 — R3 enrichment-cascade guards (MUST; blocks P3 push).** Before the diff DELETEs a line
  that has enrichment, snapshot line+enrichment into the dormant `tblLyricsReviewQueue` (exists —
  zero new DDL); on a text-changing in-place UPDATE, clamp/flag out-of-range annotation
  code-point offsets into the same queue. (Optional pass-2.5 fuzzy match for retype+edit saves.)
- **PF3 — read-version policy (owner; before P4a code).** `lyrics_ingest.php` demotes the
  `'ihymns'` row's `IsPrimary` on the first TTML ingest, so **all P4 reads/gates/assembly key on
  `Source='ihymns'` only, never `IsPrimary`**; the 1:1/IsPrimary invariants are point-in-time
  pre-cutover gate checks, never schema constraints.
- **PF4** push + PR + land `feat/lyrics-1235-p3` (P2b diff + P3 + PF1/PF2); CI green. P2b must be
  the live write path before any enrichment row exists.
- **PF5** apply `component-line-languages` on alpha (the one live schema drift; also lets us
  rehearse the R10 drop-ordering failure).
- **PF6** corpus invariant spot-run (formalised as Gate A): 1:1 song↔lyrics, all `Source='ihymns'`,
  0 NULL/orphan ComponentId, parity 0.

### 11.2 The cutover — ONE PR, commits C1–C7, four phases, **drop LAST**

Migrations are **never auto-applied** (rule #19); the operator runbook is the real irreversibility
gate. Each commit atomic + revertable.

- **P4a — Foundations (C1–C3; zero behaviour change).**
  - **C1** shared line-first assembler `includes/lyric_lines_read.php` (`lyricLinesFetchPrimary()`/
    `…Map()` bulk-chunked `IN(?)` per-songbook — **never whole-corpus, #929**; `…AssembleComponents()`;
    `…FirstLine()`), `Source='ihymns'`-keyed, LEFT-JOIN-shaped (0-line lyrics → `[]`). **Blank-line
    rule (R9): emit `LineText` verbatim, ALWAYS — never reconstruct `""` from `IsInstrumental`**
    (that flag is presentation metadata, not a text source). Unit tests
    `tests/php/test-lyric-lines-roundtrip.php`.
  - **C2** `PartTypeSlug` backfill (`UPDATE … JOIN tblSongPartTypes` — all 7 live Type values map;
    291,634 rows; unmapped stays NULL, rule #20) **+ slug-at-write in the projector/write path**
    (a backfill alone rots on the next save). Data-only, no DDL. **Closes #1138 typing.**
  - **C3** verification tooling: `tools/verify-lyrics-cutover.php` (modes
    `--phase=pre|soak|pre-drop|post-drop`, runs G1–G13, writes the `tblAppSettings`
    `lyrics_cutover_gate` sentinel), `tools/export-fidelity-snapshot.php` (hashes all 26
    `songbook_export` payloads + a committed ~380-song sample across the export surfaces — this
    sample IS the wire-shape + serializer contract), and a **CI grep test** failing on any raw
    `LinesJson|ChordsJson|NotesJson|LanguagesJson` `tblSongComponents` reference outside migrations.
  - **▶ GATE A** (before P4b): L1 green · `--phase=pre` all-green · L3 baselines captured.
- **P4b — Read switch (C4).** `SongData::_getComponents`/`_getComponentsMap` delegate to the C1
  assembler; repoint the bypass readers (`SongOfTheDay`, `duplicate-songs`,
  `build-song-link-suggestions`, `ed2_rebuildLyricsText`). **R2 (must-not-miss):**
  `ed2_buildSongSnapshot`/`ed2_applySongSnapshot` + both revision writers SELECT/INSERT the doomed
  columns *by literal name* — under `MYSQLI_REPORT_STRICT` the drop would brick every save and make
  revisions unrestorable; gate every component-JSON SQL reference behind an INFORMATION_SCHEMA
  column-existence probe so one deployed build works before AND after the drop. The four export
  surfaces (`song.php`, `pdf_export.php`, `easyworship_export`, `songbook_export`) are **unchanged**
  — they consume `components[N].lines` fed by the assembler: one assembler, four formats, the
  iLyricsDB line contract. Revertable (LinesJson still dual-written). **▶ GATE B** (before P4c):
  diff tests + **G12** live Id-stability smoke green.
- **P4c — Write inversion (C5) — lines become authoritative.** The save funnels build desired lines
  from the payload (P2b diff), upsert **thin** component rows Id-stably (match by SortOrder; never
  blanket DELETE+reinsert — that mints fresh Ids), stamp `ComponentId`, keep SortOrder contiguous
  `0..n-1` (keeps the 13 `ArrangementJson` ordinal arrays valid), and **shadow-write the JSON columns
  FROM the just-written lines** (direction inverted; `LinesJson` is `NOT NULL` so it must keep being
  written until the drop). PF1 carry-forward survives into the inverted path; per-line language
  repoints to `tblLyricLines.LanguageCode` (R10); the chord editor re-anchors on `comp.lineIds` not
  array index (R7b). Revertable **both** directions (shadow keeps both stores byte-consistent).
  **▶ SOAK ≥7 nights** of real alpha saves with nightly `--phase=soak` (parity 0; churn ≈
  genuinely-new lines only). **Never reseed/renumber Ids** — 889,769 of a BIGINT keyspace is noise;
  P2b-as-only-write-path IS the churn remediation; renumbering would break the enrichment anchor +
  the iLyricsDB shared-line contract.
- **P4d — Retirement (C6 = code; the RUN is the last operator step).**
  `migrate-retire-component-lines-json.php`, idempotent/re-runnable, internally staged:
  **Stage 0** abort-don't-drop probes (full parity on ALL projected columns incl. ChordsJson — "0
  chords today" can't be assumed at run time since the #1094 chord UI is live; **plus** refuse to run
  unless the gate sentinel says `phase=pre-drop, result=green, ranAt<24h`). **Stages 1–4** each
  `columnExists`-guarded `DROP COLUMN` (an unguarded drop of a missing column *throws* under STRICT).
  **Mandatory companions in the same commit:** schema.sql byte-mirror (delete the 4 column lines +
  retag comments); `@migration-drops` doctag support in `schema_audit.php` so CI `test-schema-coverage`
  passes the deletion; the **probe-resurrection fix** (`columnExists(LinesJson) && !columnExists(target)`
  era detection so "Apply all pending" can't re-ADD the dropped columns); retired-era no-op guards in
  the 4 legacy migrations that read the columns. **Reversibility, three layers:** Stage-0 self-abort;
  the Gate-C `mysqldump` restore; and `regenerate-lines-json-from-lines.php` (lines ⊇ JSON content, so
  the columns can be rebuilt from `tblLyricLines` any time). **C7** docs.
  **▶ GATE C** (before the drop is RUN): Gate A re-run `--phase=pre-drop` inside a #1234 maintenance
  freeze · L3 manifests byte-identical · enrichment counts unchanged since B · fresh backup taken AND
  restore-tested · inverse script proven on staging · sentinel <24h. **▶ GATE D** (post-drop): G2 vs
  the sentinel's pre-drop corpus SHA · schema-audit clean both ways · app smoke incl. revision-restore.

### 11.3 Which JSON is dropped vs kept (the "no embedded data" rule, applied with judgement)

- **DROP — dead or duplicate** (the whole point): `LinesJson` (duplicates `tblLyricLines.LineText`),
  `ChordsJson` + `NotesJson` (empty on all 70,132 components — never populated), `LanguagesJson`
  (folds to `tblLyricLines.LanguageCode`).
- **KEEP — lossless passthrough with NO relational home, *not* a rival source of truth:** the
  `MetaJson` columns (verbatim TTML/format attrs on lines/words/syllables/vocal-parts, needed for
  loss-free re-export) and `tblLyricsSourceDocuments.RawPayload` (verbatim carrier round-trip). These
  are interchange side-cars, not queryable application data — keeping them is consistent with the rule,
  not an exception to it.
- **⚖ Under review (transport vs storage):** the component-order array (`tblSongArrangements.ComponentOrderJson`,
  `tblSongs.ArrangementJson`). A short array of ordinals is defensible as a *transport/ordering* value
  (like the line array at the export boundary), not embedded relational data — but if "no JSON" is to be
  absolute, it normalises to a `tblSongArrangementItems` join table. Near-empty today (13 songs), so
  cheap to settle. Owner decision D-4; the §6/§9 note that arrangement migration is **P5**, not P4
  (moving it while the editor still writes `ArrangementJson` would create the very dual-source drift
  this epic kills).

### 11.4 API & native-app contract — a first-class P4 acceptance gate (extends §10)

The storage change must be **invisible to every consumer**, because the read contract
(`/api?action=song_detail · songs_index · songbook_export`) feeds the PWA today and the **native
Apple/Android apps next**, and the editor/enrichment write contract is the same surface the native
token path (#1201) will reuse. Therefore P4 treats the wire contract as a hard gate, not a by-product:

- **Wire-shape regression = a P4 gate.** C3's L3 export-fidelity manifest hashes the actual
  `song_detail`/`songbook_export` (+ PP7/PDF/EasyWorship) payloads **pre vs post** — byte-identical is
  required. This is the native-app stability guarantee in executable form.
- **`api-docs.yaml` (OpenAPI) updated in the SAME PR.** The line/lyrics/enrichment shapes the apps
  build against stay documented and in lockstep with the schema — a breaking line-shape change breaks
  deployed native apps that ship-and-lag.
- **Additive-only versioning.** Any richer line-anchored fields (`lineIds[]`, per-line `language`,
  translations/annotations) land *additively* — never by reshaping the existing `components[].lines`
  response. (`lineIds[]` + sparse `lineLanguages[]` already do this.)
- **Dual auth preserved** (§10): web editor = CSRF; native = `ihymns_auth` token; the shared
  `includes/line_enrichment.php` functions take a `\mysqli` (no session coupling) so #1201 reuses them.
- **One shared assembler = the cross-client serializer.** `lyricLinesAssembleComponents()` (C1) is the
  single place lines become a `components[].lines` array, for the API, every export format, and the
  future OpenLyrics/LRC/TTML serializers + the iLyricsDB merge — built once, versioned, reused.
- **Perf guardrail:** `song_detail` is one song; `songbook_export` assembles hundreds — the assembler
  bulk-fetches per songbook (chunked `IN(?)`), so assemble-on-demand never N+1s the DB-direct read path.

### 11.5 Verification gates (mapped to the adversarial risks)

All checks scoped `Source='ihymns'`; Tier-1 = raw byte equality (code-point slicing, rule #21);
Tier-2 classifies `NORMALISATION-DRIFT` (still FAIL, diagnostic).

| Gate | Assertion (expected on this corpus) | Closes |
|---|---|---|
| G1 | Per-component count parity both ways; 0 dangling/NULL ComponentId | R8 |
| G2 | Full-corpus Tier-1 byte identity, fail=0/16,083; **LineText verbatim** | core no-loss; R9 |
| G3 | Blank bijection 28↔28; whitespace-vs-empty distinguished | R9 |
| G4′ | All 13 ArrangementJson arrays index valid ordinals 0..n-1; P4c keeps SortOrder contiguous | R11 |
| G5 | 0 mappable-but-NULL PartTypeSlug; holds after fresh saves | R8 |
| G6 | Enrichment counts of all 7 line-FK tables unchanged per phase; 0 orphan refs | R3 |
| G7 | 1:1 / IsPrimary / Source — point-in-time checks, never constraints | R6 |
| G8/G9 | 0 non-contiguous ComponentId runs; group order ≡ SortOrder; 0 NULL ComponentId pre+post | grouping bridge |
| G10 | Per-line LanguageCode ≡ projection of component language (today 12 lines / Psalty); monotone | R10 |
| G11 | Corpus fingerprint frozen across the drop, inside the #1234 freeze | gate/drop race |
| G12 | Live Id-stability smoke: 3 sentinel songs, double re-projection, identical Id sets | R1/R3 |
| G13 | Byte/semantic parity on ALL projected cols incl. ChordsJson — vacuous today, load-bearing once a curator touches the live #1094 chord UI | R7a |
| CI grep | 0 raw payload-column SQL references outside migrations/guards | R2 |
| L3 | Export manifests byte-identical: 26 songbook exports + ~380-song sample × 5 surfaces; 31 empty songs present as rows | API/native + surface fidelity |

### 11.6 Owner decisions blocking code (recommend logging unactioned ones as `for consideration`)

- **D-1 — Confirm C-variant; record pure-C as foreclosed** (the 23.7% number). `tblSongComponents`
  survives as thin metadata. *Recommend: confirm.*
- **D-2 — Chord home, BEFORE the drop runs (R12).** A whole-section chord progression has no post-drop
  home if component `ChordsJson` drops. *Recommend default:* declare the dormant `tblSongChords` the
  section/song chord home (zero new DDL) and drop all four columns. *Alternative:* drop only three
  (keep `ChordsJson`) and defer. **Never drop a column whose replacement is undecided.**
- **D-3 — Read-version + ingest-primacy policy (R6).** Reads key on `Source='ihymns'`; separately
  decide whether `lyrics_ingest.php` should keep usurping `IsPrimary` and the display policy once timed
  TTML versions exist. (Not blocking P4a if reads are Source-keyed.)
- **D-4 — P5 definition** (doc gap — §8 ends at P4): ArrangementJson→`tblSongArrangements` move atomic
  with the editor write path; pure-C component-retirement decision; `tblSongs.ArrangementJson` drop.
- **D-5/D-6 — Soak length** (plan: ≥7 nights) and **one PR vs stacked drop-PR** (plan: one PR; the
  manual-migration run is the real control).

**Residual risks accepted:** a retype+rewrite save can still *queue* (not silently lose) enriched lines
until pass-2.5 lands; revision `NewData` keeps the `components[].lines` shape forever (by design — it's
interchange data, not SQL); #1243 musical-metadata must consume the C1 assembler, never re-grow a
parallel array; iLyricsDB-gated objects stay gated (#20); a non-frozen beta/prod drop re-introduces the
manifest race — the runbook freeze is not optional.

### 11.7 Effort & the two owner gates

| Step | Size |
|---|---|
| PF1+PF2 (P3 amendments + tests) | ~1 session (M) — blocks P3 push; R3 repro is a fixture |
| C1 assembler + tests | M (keystone) |
| C2 slug migration + write | S |
| C3 verifier + snapshotter + CI grep | M–L |
| C4 read switch (incl. R2 snapshot/revision sites) | M |
| C5 write inversion | **L** (5 funnels, carry-forward, lineId chord anchor, shadow-write) |
| C6 drop migration + scanner + probe fix + guards + schema.sql | M (high blast radius, small diff) |
| Soak + runbook per env | ≥7 nights/env |

**Owner gate 1** = sign-off on this plan + decisions D-1/D-2/D-3 before C1 is written.
**Owner gate 2** = post-soak go/no-go before running the drop card on each env (alpha → beta →
production per normal promotion). Total: ~4–6 build sessions + the soak windows.

## 12. Data-quality investigation (session 3) — the scraping debt re-opens pure-C

The owner's challenge to "pure-C is foreclosed" was correct: the 23.8% duplicate-part-key figure was
measured against **scraped data whose source had no consistent structural design**, so it conflates
real structure with parse garbage. Classifying the real dump (identical vs distinct LinesJson within
each `(SongId, Type, Number)` group):

| Class | Dup-groups | Songs | Components | Nature |
|---|---|---|---|---|
| **Identical-repeat** (e.g. `refrain` ×N) | 3,613 (94%) | 3,607 | 13,394 | Hymnal "refrain after every verse" scraped as N copies — **mechanically collapsible to 1 component + an arrangement repeat** |
| **Distinct-text same-key** | 220 (6%) | 208 (1.3% of corpus) | 642 | Mostly *more* scraping garbage; small genuine-curation residue |

**Distinct-case spot-check (the "forced C-variant" evidence dissolves on inspection):**
- **CP-0220** — five `chorus` components are literally `ALL` / `FIRST` / `SECOND` / `THIRD`:
  voice-part stage directions mis-parsed as choruses (belong in `tblVocalParts`).
- **CP-0110** — **two different hymns concatenated** under one SongId + a `CHRISTMAS` section heading
  mis-parsed as a chorus.
- **JP-0380 / CP-0056** — genuine-but-messy segmentation; the real-curation residue.

**Revised conclusion (supersedes §0 finding 1 / §11.6 D-1):** pure-C is **not foreclosed by legitimate
data**. ~94% of the blocker is trivially-collapsible repeat-garbage; ~1.3% needs cleaning; the residual
songs that *genuinely* need duplicate part keys (true call-and-response, medleys) are likely a tiny set.

**Implications / work-streams:**
- **P4 still ships the C-variant** (lossless, keeps the thin `tblSongComponents`) — it cements nothing
  and is the safe cutover. pure-C stays a *post-cleanup* future phase, not a foreclosed one.
- **New: a data-cleanup epic (the scraping debt).** (a) Collapse identical-repeat refrains → 1
  component + a generated `tblSongArrangements` repeat order (removes ~9,800 redundant component rows);
  (b) triage the 208 distinct cases (voice-labels → `tblVocalParts`; headings → drop/relabel; merged
  songs → split; genuine → keep). This is the same work-stream as the arrangement model (D-4 / P5).
- **⚖ Sequencing decision (new):** *cutover-first* (P4 C-variant now — lossless — then clean on the
  normalised line model with the verification harness already in place; **recommended**, lower risk)
  vs *clean-first* (scope+run the cleanup before P4; purist, but delays P4 and cleans the messier JSON
  model). Either way **PF1/PF2 come first** (write-path bug fixes, prerequisite to both).
- **D-1 is therefore not "confirm C-variant forever"** but "ship C-variant for P4; re-decide pure-C on
  *clean* data after the cleanup epic." The cleanup volume (94% mechanical) makes pure-C realistic.

---
*Next: (1) PF1/PF2 amendments on `feat/lyrics-1235-p3` + tests (owner-approved) → push P3; (2) the
cutover-first-vs-clean-first sequencing call + a data-cleanup epic issue; (3) ONE P4 PR (C1→C7, C-variant,
`tblSongChords` as chord home per D-2) targeting `alpha`, drop run manually per env behind the gates.*

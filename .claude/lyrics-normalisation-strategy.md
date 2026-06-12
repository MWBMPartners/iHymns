# Lyrics & song-info storage normalisation — strategy

> Status: **APPROVED + IN BUILD.** Owner chose **Option C (pure normalisation)** —
> `tblLyricLines` is the single source of truth; part identity on the line;
> components derived; JSON arrays retired. Tracking epic: **#1235**.
> Companion to the #1200 Song Editor rewrite. Author: pairing 2026-06-10..12.

## 0. Progress & RESUME POINT (2026-06-12)

**Phase 1 + the read-switch are DONE and verified on alpha. Resume from P3.**

| Phase | What | Status |
|---|---|---|
| **P1a** #1247 | `includes/lyric_lines_sync.php` (the ONE shared projector `lyricLinesProjectSong()` + `lyricLinesEnsurePrimaryVersion()` + `lyricLinesSyncReady()`); `tblLyricLines` += `ChordsJson`+`Note`; `migrate-lyric-lines-mirror.php` backfilled the whole catalogue | ✅ **verified: 16,081 songs / 291,478 lines, Query-2 parity = 0** |
| **P1b** #1251 | transitional dual-write — guarded `lyricLinesProjectSong()` at the end of `ed2_rebuildLyricsText` (all api2.php editor paths) + legacy `api.php` save_song + `song_importers.php` + `lyrics_ingest.php createSong` | ✅ verified (edit keeps mirror in sync) |
| **P2a** #1252 | read-switch — `SongData::_getComponents`/`_getComponentsMap` source line TEXT from `tblLyricLines` (JOIN component for metadata), **byte-identical output**, per-component `LinesJson` fallback guarded by `_hasLyricLinesMirror()` + a **line-count check** | ✅ verified visually on 6 songs (3 official + 2 unofficial books + editor) |
| **P2b** (the Id-preserving diff) | replace the naive whole-song reproject so line `Id`s survive edits | ⏳ **COUPLE WITH P3** — its only value is keeping P3 enrichment from orphaning; harmless to defer (nothing references line `Id`s yet; P2a keeps them internal) |
| **P3** ← RESUME HERE | per-line **translations / annotations / language** first-class (`tblLyricLineTranslations` / `tblLyricLineAnnotations` / `tblLyricLines.LanguageCode`), expose `lineIds[]` in the read output (**update `data/songs.schema.json`** — it is `additionalProperties:false`, which is why P2a withheld `lineIds`), + the **Id-preserving diff** (P2b), + the full **BCP 47 picker #1253** | TODO |
| **P4** | drop `LinesJson` / `ChordsJson` / `NotesJson` after a verify gate | TODO |

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

---
*Next once approved: create the implementation epic + per-phase issues; do P1 (backfill + read-model)
behind the `LinesJson` fallback so it ships with zero behaviour change.*

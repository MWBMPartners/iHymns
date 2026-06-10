# Lyrics & song-info storage normalisation — strategy (DRAFT)

> Status: **DRAFT for owner review.** No code, no branches, no migrations until the
> model + phasing below are approved. Companion to the #1200 Song Editor rewrite.
> Tracking epic: **#1235**. Author: pairing session 2026-06-10.

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

1. **Backfill.** One-shot, idempotent migration: every `tblSongComponents.LinesJson` (+ Chords/Notes)
   → `tblLyricLines` rows under each song's primary `tblLyrics` version (creating one if absent),
   carrying part identity, chords and notes onto the line. CI guards (#19/#20) apply.
2. **Id-preserving editor save (the crux).** `component_upsert` (and the structure-tab) **diff** the
   edited text into line rows — insert new, update changed, delete removed, and **preserve `Id`s for
   unchanged lines** so existing timings/translations/annotations survive an edit. Naïve delete-all +
   reinsert would silently orphan all enrichment; this diff is the hardest, most important piece.
3. **Read-model switch.** `getSongById` / the public render / every reader + exporter read from
   `tblLyricLines` (grouped into components for display), and emit JSON arrays only at the export
   boundary. Fallback to `LinesJson` only for rows not yet backfilled (transition window), then remove.
4. **Editor refactor.** The structure-tab edits a derived component view; saves go through the diff.
5. **Drop** `LinesJson` / `ChordsJson` / `NotesJson` once everything reads/writes the normalised model.

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

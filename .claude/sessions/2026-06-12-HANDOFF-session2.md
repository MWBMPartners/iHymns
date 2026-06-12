# Session handoff — 2026-06-12 (session 2): lyrics #1235 P3 backend

> Continuation of the 2026-06-12 session. **The entire P3 BACKEND is built, tested,
> and committed** on `feat/lyrics-1235-p3` (NOT pushed). Only the editor UIs remain.

## TL;DR resume

Branch **`feat/lyrics-1235-p3`** (off `origin/alpha`, `--no-track`), **3 commits, not
pushed, no PR**. All 4 CI PHP suites green locally. Resume at the **P3 editor UIs** —
full plan in `.claude/lyrics-normalisation-strategy.md §0.1`. The UIs wire to
endpoints that already exist; build them with the app running (`/run` + `/verify`).

## What shipped (committed this session)

- **`c3c372d7` — P2b Id-preserving diff + `lineIds[]`.** `lyricLinesProjectSong()`
  diffs pre→post lines by CONTENT (part identity + text, never `ComponentId` — it
  churns on legacy `save_song` / v2 `components_replace` / snapshot-restore). 3-pass
  match (same-part exact → any-part exact → same-part fuzzy ≥0.5 **code-point**
  distance), dirty-checked, idempotent. Pure helpers → `tests/php/test-lyric-lines-diff.php`
  (47, fuzz-reviewed). `lineIds[]` exposed in `SongData` + `data/songs.schema.json`.
- **`d545f6cf` — enrichment write API.** New `includes/line_enrichment.php` (the ONE
  shared translation/annotation CRUD layer — iLyricsDB/native contract): vocab
  allow-lists (rule #20), LyricsId derived from the line, ownership enforced,
  code-point offset validation (rule #21), `bindParamSafe` + per-column labelled
  type strings. 4 api2.php handlers + `load_song` returns `lineTranslations`/
  `lineAnnotations`. READ side already existed (#1099 `getSongDetailExtras`).
  `tests/php/test-line-enrichment.php` (21).
- **`65c9c818` — per-line language.** `tblSongComponents.LanguagesJson` parallel
  array (durable home so per-line language survives reprojection — the projector
  would otherwise clobber a per-line `tblLyricLines.LanguageCode` from the
  component). Migration + schema.sql + registry + projector + `component_upsert`/
  snapshot write + sparse `lineLanguages[]` read.

All four CI php tests wired (`test.yml` lint + php-compat jobs).

## Key design findings (this session)

- **`tblSongComponents.Id` is NOT durable** — legacy `save_song` DELETE+INSERTs all
  components every save; so the diff matches by content, never component Id.
- **The enrichment READ path already existed** (#1099) — less to build than the menu implied.
- **Per-line language collided** with the projector (it rewrites line language from
  the component) → solved with `LanguagesJson` (parallel-array home), not a raw
  per-line write.
- **api2.php is session+CSRF editor auth.** Native/token write path = #1201 follow-on,
  reusing the SAME `line_enrichment.php` functions (no session coupling). Don't fork.

## Remaining — P3 editor UIs (see strategy §0.1 for the exact API contracts)

1. **BCP 47 picker #1253** — swap `editor.js`'s per-component language `<select>`
   (~L1079) for a compact instance of the shared `ietf-language-picker` (needs a
   compact mode); add a per-line control writing `comp.languages[i]`. Save path done.
2. **Translation editor UI** — per line: `line_translation_upsert`/`_delete`.
3. **Annotation editor UI** — per line/span: `line_annotation_upsert`/`_delete`.
   Render must sanitise `bodyFormat='html'` (stored-XSS otherwise).

These need the app running to build + verify; backend is complete so it's wiring.

## Not done / needs owner or network

- **No push / PR** (per owner: branch + commit only). Branch is based on `origin/alpha`;
  local `alpha` ref is stale.
- **GitHub issues / milestone / wiki** not yet updated for the P3 backend (offered).
- **`for consideration`** candidates surfaced: orphaned `tests/php/*` not in CI
  (song-similarity, musicxml, opensong, videopsalm); a DB-backed integration test for
  `lyricLinesBuildDesired` (chord/note/language parallel-array indexing).

## Non-lyrics this session

- Fixed a **disk-full emergency** (Data volume was 100%/1.3 GiB free → 30 GiB): cleared
  ~11 GiB of safe app caches (Claude `vm_bundles`, VS Code/Edge caches). Root cause is
  **132 GiB OneDrive local sync** (use Files On-Demand) + a 50 GiB Parallels VM —
  user's to triage.

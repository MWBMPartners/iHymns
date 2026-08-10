# #1783 — Duplicate a song as a starting point for a new songbook

> **AS-BUILT (2026-08-10).** Shipped on `claude/issue-sweep-fixes-89` (Option C — hidden
> `PENDING` staging book). Commits: `34b86654` (server endpoint + editor reach, 1-2),
> `81471f66` (Metadata empty Songbook+Number, 3), `1f54583d` (3 CI-gap fixes),
> `b6a74519` (commit 4 — per-line enrichment + scripture-ref re-anchor), `841e9217`
> (commit 5 — mutation-proven guards). Guards: `tests/test-editor-duplicate-contract.js`,
> `tests/php/test-duplicate-copy-set.php`, `?duplicate=` leg in `test-editor-deep-links.js`.
> **Deferred (for consideration):** per-line presenter `Note` carry (§2.4), a
> `/manage/duplicate-songs` `?duplicate=` emitter, a `songRelocate()` no-redirect flag for
> staging-origin moves, and D5 counterpart auto-link (re-decided: best at ASSIGN time, needs
> a source marker — not auto-linked at duplicate time, where a PENDING draft link is
> premature). Wiki editor page NOT updated (no `iHymns.wiki/` checkout in this environment).

**Deep analysis + implementation plan (read-only pass, 2026-08-05).**
Feature (owner's words): a song often exists in multiple songbooks almost identically. Add
the ability to DUPLICATE a song; in the duplicate the **Songbook** and **Song number**
fields must be **EMPTY**; the duplicate must **open immediately in the Song Editor** so the
curator can change anything (lyrics, chords, credits, external IDs) *including* setting the
songbook & number, then save it as a brand-new song.

All paths below are relative to `appWeb/public_html/` unless noted. Line numbers verified
2026-08-05 against the working tree.

---

## 1. Current-state map (verified)

### 1.1 The hard constraint, confirmed

**There is NO unsaved new-song draft mode in the v2 editor. Every open song is a persisted
server row loaded by id.** Evidence:

- `manage/editor/editor2.php:582-597` — the New-song modal handler calls
  `editorApi.createSong(sb, title)` and immediately `loadSong(res.songId)`. The modal's own
  copy says so: *"The server assigns the canonical id (`<ABBR>-NNNNNN`)"*
  (`editor2.php:260`).
- `manage/editor/api2.php:1473-1530` (`case 'create_song'`) — requires a `songbook`
  (defaults `'MISC'` at :1478), verifies it exists (:1485-1490), mints the id INSIDE a
  transaction via `ed2_allocateSongId($db, $abbr)` (:1492), INSERTs the `tblSongs` row
  (with a fresh `PublicId` when `songPublicId_columnReady()`, :1497-1504), writes a
  `'create'` revision (:1507), recomputes the book's `SongCount` (:1521-1525, #1742), and
  answers `{ok, songId, title, songbook}` (:1528).
- `manage/editor/api2.php:833-880` (`ed2_allocateSongId`) — the id **is**
  `<ABBR>-<NNNNNN>`: abbr allow-listed `^[A-Z0-9]{1,10}$` (:840, rule #27 charset),
  6-digit per-book sequence locked `FOR UPDATE` (:852-861), collision-checked via the
  shared `songRelocateIdTaken()` which also consults `tblSongRedirects` (:874-878, #1679
  A9). So the id cannot exist without a songbook — `tblSongs.SongbookAbbr` is `NOT NULL`
  with an FK to `tblSongbooks.Abbreviation` (`appWeb/.sql/schema.sql:275,355-357`).
- Every tab is mounted with a fixed `songId` in its ctx (`editor2.php:408-417`,
  `mountTabs(songId)`) and every edit is an instant, atomic POST carrying that id
  (e.g. `metadata-tab.js:133-168` `save()` → `api.updateMetadata(songId, field, value)`).
  An empty/absent id makes every endpoint 400 (`api2.php:1583-1585` and siblings) — there
  is nothing resembling a client-held pending song.

**Contrast — the v1 legacy editor DOES have a draft mode**, and the shared save core still
honours it: `manage/editor/editor.js:5563-5580` (`addNewSong()`) mints a client-side
`'song-' + base36` draft id, and `manage/editor/save_song_core.php:353-398` (#1380)
detects the `song-` prefix on first save and promotes it to a canonical
`<Abbr>-NNNN` via `songRelocateMintId()`, defaulting a missing songbook to `'Misc'`
(:160-195). That is the whole-song-save architecture v2 deliberately replaced (the
auto/manual save race, #1178 — `api2.php:10-14`), so "add a draft mode to v2" means
re-importing the model v2 exists to retire.

### 1.2 How v2 loads a song

- `editor2.php:444-478` (`loadSong(id)`) → `editorApi.loadSong(id)`
  (`v2/api-client.js:160`, GET `load_song`) → tears down tabs, hydrates the store slices
  `song / components / credits / tags / links / media / lineTranslations /
  lineAnnotations / songbookRightsDefaults` (:452-467), remounts tabs, history-replaces
  `?song=<id>` (:470).
- `api2.php:1423-1470` (`case 'load_song'`) — the payload is
  `ed2_buildSongSnapshot()` (:1429) **plus** `media` (:1434-1447, separate file
  lifecycle), per-line enrichment (:1453, `lineEnrichmentForSong()`), and the songbook
  rights-default hint (:1460-1461).
- `api2.php:1030-1130` (`ed2_buildSongSnapshot`) — **the full editable shape and the ONE
  builder for both `load_song` and the `tblSongRevisions.NewData` snapshot** (:1024-1029):
  - `song` — the raw `SELECT * FROM tblSongs` row (:1038-1042), PascalCase columns.
  - `components` — `ed2_currentComponents()` (:1047 → :952-980): on a migrated install
    `lyricLinesEditableComponents()` (`includes/lyric_lines_read.php:378-454`) —
    `{id, type, number, sortOrder, lines[], chords?, language, languages?, lineIds[]}`,
    sourced from the authoritative `tblLyricLines` (rule #25). NOTE: this shape carries
    **no per-line `Note`** (`lyricLinesFetchPrimary`, `lyric_lines_read.php:197-221`,
    selects no `ll.Note`) — see §2.4.
  - `credits` — the six role tables (`ED2_CREDIT_TABLES`, `api2.php:369-376`), each
    `{id, name}` + registry-decomposed `first/surname/suffix` when
    `musicianNamePartsColumnsExist()` (:1086-1113, #960).
  - `tags` — `tblSongTagMap` ⋈ `tblSongTags` (:1115-1125).
  - `links` — `loadExternalLinksForRow(... 'tblSongExternalLinks' ...)` (:1127).

### 1.3 How v2 saves

Granularly, one endpoint per concern (`api2.php` dispatch, :1417+): `metadata_field_update`
(:1580, allow-list `ED2_META_FIELDS` :381-433; the songbook branch :1638-1679 is a full
re-key via `songRelocate()`, #1679), `component_upsert/_delete/_reorder/components_replace`
(:1892-…, all read-modify-write through `ed2_currentComponents()` →
`ed2_persistComponents()` :993-1021 → the ONE line write
`lyricLinesWriteComponents()`, `includes/lyric_lines_sync.php:503-542`), `credit_upsert`
(:2227), `tag_attach/detach` (:2511/:2557), `link_save_all` (:2585), media (:3330-3573),
arrangement (:2048), line enrichment (:2128-2226). The legacy whole-song `save_song`
survives as `case 'save_song'` (:4528-4532) delegating to the shared
`editorSaveSongCore()` (`save_song_core.php:127`), used by the v1 editor.

**Load-bearing for this feature:** `ed2_applySongSnapshot()` (`api2.php:1159-1362`) — the
revision-restore engine — already knows how to take a full snapshot and write **scalars
(allow-listed) + components/lines + ArrangementJson + credits (with `musicianPromote`) +
tags + links** onto a target song, atomically, with these properties:

- it **never writes `SongbookAbbr`** (:1174, the #1679 exclusion — exactly what a
  duplicate needs);
- Tune restores through the ONE lockstep core `ed2_songTuneApply()` (:1256-1258, #1741
  P5c);
- ISRC re-canonicalises + mirrors into `tblSongExternalIds` (:1243-1247, #1749 P5d);
- un-migrated identity/rights columns are silently skipped (:1194-1200, partial-apply
  posture);
- `ArrangementJson` is restored inside the components branch and re-validated against the
  restored component count (:1289-1298);
- `LyricsText` is rebuilt by `ed2_persistComponents()` → `ed2_rebuildLyricsText()`
  (:1020, :891-914).

**A duplicate is therefore `create_song`-shaped mint + `ed2_applySongSnapshot(source
snapshot)` — the copy machinery already exists and is the same code a revision restore
exercises daily.** `lyricLinesWriteComponents()` ignores incoming component `id`s entirely
(it matches the TARGET song's own rows by position — `lyric_lines_sync.php:558-652`,
`lyricLinesUpsertComponents`), so feeding it another song's editable components into a
fresh song simply INSERTs everything cleanly. Verified: no cross-song id leakage is
possible through this path.

### 1.4 Deep-link surface today

`editor2.php` reads: `?song=` / `?open=` (:43, #1623 alias), `?songbook=` + `?number=` /
`#number=` (:62-65, #1680 Missing-Numbers prefill), `?tab=` (:93-105, #1628), `?legacy=1`
(router-level, #1601). `tests/test-editor-deep-links.js` derives every emitted
`/manage/editor/…` href from the tree and asserts the shell reads each param (rule #33/#34).

---

## 2. The copy set

Derived from the full FK-child catalogue of `tblSongs(SongId)` (`appWeb/.sql/schema.sql`,
41 inbound FKs — the same catalogue `songRelocate()` cascades, `includes/song_relocate.php:650`)
plus the `tblSongs` columns (schema.sql:266-360).

### 2.1 COPY — content that makes the duplicate a faithful starting point

| What | Table / column | Mechanism |
|---|---|---|
| Title, Subtitle, Disambiguation, Language, Copyright, CopyrightYears, CopyrightHolder, FirstPublishedYear, OriginCity(+Id), LyricsPD/MusicPD flags, rights-fact keys | `tblSongs` scalars in `ED2_META_FIELDS` (api2.php:381-433) | `ed2_applySongSnapshot()` scalar loop (existence-gated) |
| Tune (TuneName + TuneId in lockstep) | `tblSongs` | snapshot apply → `ed2_songTuneApply()` (api2.php:1256) |
| Components + lyric lines (text, per-line chords, per-line language) | `tblSongComponents`, `tblLyrics`, `tblLyricLines` | snapshot apply → `ed2_persistComponents()` → `lyricLinesWriteComponents()` (the ONE write path, rule #25) |
| Arrangement (running order) | `tblSongs.ArrangementJson` | snapshot apply (api2.php:1289-1298, re-sanitised) |
| Credits, all six roles | `tblSongWriters/Composers/Arrangers/Adaptors/Translators/Artists` | snapshot apply (:1301-1331, incl. `musicianPromote`) |
| Tags | `tblSongTagMap` | snapshot apply (:1335-1348) |
| External links (YouTube/Spotify/… rows) | `tblSongExternalLinks` | snapshot apply (:1350-1361) — **default: copy** (D2) |
| Musical key / tempo / time signature | `tblSongKeys` (schema.sql:1811, UNIQUE SongId) | one `INSERT … SELECT` in the new endpoint (not in the snapshot) |
| Genre, IsExplicit, Availability | `tblSongs` (not in `ED2_META_FIELDS`; import-populated, #1046) | one extra UPDATE in the endpoint |
| Work-level identifiers: Ccli, Iswc | `tblSongs` | snapshot apply — **default: copy** (D2) |
| Per-line translations + annotations | `tblLyricLineTranslations`, `tblLyricLineAnnotations` (anchored on `tblLyricLines.Id`, rule #21) | positional line-id map, §5 commit 4 — **default: copy** (D6) |
| Alternative titles | `tblSongAlternativeTitles` (schema.sql:2832; no live app writer — import-populated) | `INSERT … SELECT` — **default: copy** (cheap, pure content) |
| Scripture references | `tblSongScriptureRefs` (schema.sql:4341) | `INSERT … SELECT` — **default: copy** (same reasoning) |

### 2.2 RESET — identity and lifecycle, never copied

| What | Why |
|---|---|
| `SongId` | freshly minted `PENDING-NNNNNN` (§3) — the id IS `<SongbookAbbr>-<Number-ish>` (rule #27) |
| `PublicId` | a permalink identity (#1343-B); mint fresh via `songPublicId_mintUnique()` exactly as `create_song` does (api2.php:1497-1500) |
| `Number` | **NULL** — the owner's "empty song number"; set by the curator at assign time |
| `SongbookAbbr` | the staging book (§3) — presented as EMPTY in the editor |
| `NormalizedTitle` | recomputed from the (same) title via `ed2_normalizeTitle()` — lands identical, but derived, never copied |
| `Verified` | **reset to 0** (D4) — the duplicate has not been reviewed in its new context |
| `CreatedAt/UpdatedAt` | fresh row |
| `IsDeleted/DeletedAt/DeletedBy/DeletedReason/DeleteNote` | defaults (0/NULL/'') — and `duplicate_song` must REFUSE a soft-deleted source (409), matching the restore-first workflow (#1694) |
| `LyricsText` | rebuilt by `ed2_rebuildLyricsText()` from the new song's own lines |
| `LyricsTextFolded` | left NULL — only the migration backfill ever writes it (verified: sole writer is `manage/includes/migration-registry.php`); parity with `create_song` |
| Recording-level identifiers: `Isrc`, `Upc`, recording-scope `tblSongExternalIds` rows, `tblSongRoyaltyIds` | **default: clear / don't copy** (D2) — they identify a specific recording/release tied to media we are not copying |

### 2.3 NEVER COPY — id-derived, user-scoped, or process state

- `tblSongRevisions` — fresh trail; the endpoint writes exactly one forced `'duplicate'`
  revision (`Action` is `VARCHAR(20)`, schema.sql:1925 — fits).
- `tblSongRedirects` — permalink forwarding for THE SOURCE id; copying would hijack it.
- `tblUserFavorites` (schema.sql:1472), `tblSongUsageEvents`, `tblSongHistory` — user/usage
  data.
- `tblSongLinkSuggestions` / `…Dismissed` — batch-scored process state; the nightly
  builder will re-score the new song on its own.
- `tblLiveFollowSessions.CurrentSongId`, presenter assignments (`fk_PresAssign_Song`,
  schema.sql:4550) — live/process state.
- `tblSongQualityFindings`, `tblSongEmbeddings`, `tblSongIdentityMap`,
  `tblLyricsConflicts`, `tblLyricsReviewQueue` — pipeline state (re-derived).
- `tblSongbookEntries`, `tblCatalogueSongs`, `tblSongLanguages` — membership/index rows;
  `create_song` writes none of these either (tblSongLanguages' only writer is the
  migration backfill — verified), so the duplicate matches create parity.
- `tblSongTranslations`, `tblWorkSongs` — curated song↔song / work relationships; a
  duplicate is a *new* record whose relationships the curator declares. (`tblSongLinks`
  is the deliberate exception — D5.)
- `tblSongMedia` + `HasAudio`/`HasSheetMusic` — **default: don't copy, reset flags to 0**
  (D3). Media rows own real bytes (FS/DB backends via `SongMediaStorage`, #853);
  duplicating them duplicates storage, and the flags must not claim media that isn't
  there.
- `tblSongRequests.ResolvedSongId/SongId` — request-resolution pointers.

### 2.4 Known-loss caveat (accepted, documented)

Per-line presenter `Note`s (`tblLyricLines.Note`, ingest-populated) do not travel: the
editable read shape has never carried them (`lyric_lines_read.php:197-221` selects no
`Note`; :378-454 emits none), so **revision restore already loses them the same way** —
the duplicate is exactly as faithful as a restore, via the same funnel. Widening the
editable shape is out of scope here (it would touch the P4 read/write gates, rule #25) —
if the owner wants Notes preserved, that is a separate `lyric_lines_read.php` issue that
would then benefit restore AND duplicate together. File it as a `for consideration` issue.

---

## 3. Design options

### Option A — client-side draft (literal "unsaved copy" in the editor)

Duplicate loads the source snapshot into the store with no id; every tab renders it; a
new "Create" action (enabled once a songbook is picked) runs `create_song` + applies the
possibly-edited state.

Scoped precisely, this requires a second save model inside the instant-save editor:

- Every one of the ~14 mounted modules (`editor2.php:418-439`) calls `api.*` with the
  ctx `songId` on every edit. In draft mode each write must be intercepted. The one clean
  seam is the injected `ctx.api` — a `draftApi` facade implementing the `editorApi`
  surface (`api-client.js:147-293`, ~35 methods) that mutates the store instead of the
  server. But the tabs **adopt server echoes as truth by design**: `credit_upsert`
  answers the REGISTRY's name parts, never the input (api-client.js:200-208);
  `song_tune_set` find-or-creates a `tblTunes` row and echoes `tuneId/meterCode`
  (metadata-tab.js:442-451); `tag_attach` returns the canonical tag; `arrangement_update`
  echoes the STORED value. A facade must emulate all of those response shapes — a
  hand-maintained parallel of the server contract, i.e. the exact "two things that must
  agree with nothing enforcing it" class rule #35 exists to kill.
- Media upload, the revisions tab, counterpart suggestions and export are meaningless or
  impossible without a server row — each needs a disabled/deferred state.
- First-save is a replay of dozens of granular calls (or one new snapshot-apply
  endpoint), with partial-failure semantics v2 was built to avoid (#1178).
- A page reload loses the draft (or needs localStorage persistence — more machinery).

**Cost: large (touches every v2 module + a new save orchestration), risk: high, and it
reintroduces the deferred-save model the v2 rewrite retired.** Fully literal on "empty
fields", but at the price of the editor's architecture.

### Option B — plain server-side `duplicate_song` into a real book

Copy the source into a new row in the source's own book (or `Misc`) and open it. Two-line
verdict: everything works today with zero client machinery, **but the Songbook field
shows a real book ("Misc" or the source book) and can never be empty** — the id needs a
prefix (rule #27). This fails the owner's stated requirement as UI truth, and "Misc" is a
real, publicly visible collection (`save_song_core.php:160-195` treats it as the seeded
default home), so half-made duplicates would leak into the public catalogue.

### Option C — RECOMMENDED: server-side duplicate into a hidden staging book + draft presentation

Marry B's cheapness to A's user experience:

1. **Storage**: `duplicate_song` copies the source into a new row in a dedicated,
   **publicly-hidden staging songbook** — `Abbreviation='PENDING'`,
   `IsOfficial=0`, `IsDisabled=1`. `IsDisabled` (schema.sql:185, epic #1765) already
   hides a book from every public read via `songbookVisibleSql()` while keeping it
   visible and editable under `/manage` — exactly the semantics a draft home needs. The
   id is `PENDING-000001` (14 chars, fits `VARCHAR(20)`; charset satisfies rule #27 and
   `ed2_allocateSongId`'s `^[A-Z0-9]{1,10}$` gate). `Number` is genuinely `NULL`.
2. **Presentation**: `load_song` answers a server-derived `isPendingDuplicate: true` for
   songs in that book (one server constant; the client never hardcodes `'PENDING'` —
   rule #35). The Metadata tab then replaces the ordinary Songbook select + Number input
   with an **"Assign to songbook" panel whose two fields start EMPTY** (placeholder
   `— choose songbook —`, blank number). The owner's requirement is honoured where the
   owner sees it: in the editor, both fields are empty until the curator acts.
3. **Completion** ("save it as a brand-new song"): picking a book + number and clicking
   **Assign** runs the *existing, battle-tested* machinery in sequence —
   `metadata_field_update field=songbook` → `songRelocate()` re-keys `PENDING-000001` →
   `MP-000456` (api2.php:1638-1679, #1679: cascades all 41 FK children, clears Number,
   writes the redirect row) → `updateMetadata(newId, 'number', n)` (the exact
   create-then-number sequencing `runPrefill` already uses, editor2.php:663-679) →
   `onSongIdChange` re-opens the song under its permanent id (editor2.php:384-389).
4. Meanwhile **every tab works normally from the first second** — lyrics, chords,
   credits, external IDs, tags, links, media, export — because the draft IS a real song;
   no second save model, no facade, no replay.

Residuals, stated honestly:

- "Empty songbook" is presentation over a hidden staging home, not a NULL column. The
  column cannot be NULL (`NOT NULL` + FK + the id grammar); this is the closest any
  server-side design can get, and the curator-visible behaviour matches the ask exactly.
- The assign step leaves a permanent `tblSongRedirects` row `PENDING-000001 → MP-000456`.
  Harmless (the staging id is never public, never bookmarkable) and it is precisely the
  mechanism that keeps any stray reference safe. Accept it; a "skip redirect when leaving
  the staging book" option on `songRelocate()` is a possible later cleanup, not worth
  touching the well-tested core now.
- Abandoned drafts accumulate in the staging book — visible in the admin sidebar under
  "Pending duplicates", deletable via the ordinary soft delete. Self-managing.

**Recommendation: Option C.** It is additive and dormant (nothing changes until the
button is pressed), reuses three existing, heavily-exercised cores
(`ed2_buildSongSnapshot` / `ed2_applySongSnapshot` / `songRelocate`) instead of building
a parallel save model, and honours the owner's literal requirement at the surface the
owner described it: the two editor fields are empty and the curator sets them. Option A
is only worth its cost if the owner insists the draft must not exist server-side before
assignment — see D1.

---

## 4. Reach (rule #33 — params are contracts)

1. **Editor toolbar button** (primary): a `Duplicate` button in `editor2.php`'s toolbar
   (next to `New`, :194), enabled when a song is open. Confirm dialog → 
   `editorApi.duplicateSong(currentSongId)` → `sidebar.refresh()` (`sidebar.js:322`) →
   `loadSong(res.songId)`.
2. **`?duplicate=<sourceId>` deep-link** on `editor2.php`, sanitised with the same
   `[^A-Za-z0-9\-]` strip as `?song=` (:43). Boot order: `duplicate` param → confirm
   modal → run duplicate → open. **The confirm is mandatory**: `editor2.php` is a
   navigable GET page whose JS would otherwise perform a write on load — a forced
   top-level navigation (`<a href>`, `window.open`) could mint rows in a signed-in
   curator's name. One click ("Duplicate MP-1008 as a starting point for a new songbook?")
   converts navigation into intent. Low damage either way (a hidden draft), but cheap and
   correct.
3. **Emitters**: none in the first PR beyond the editor's own button (a handled param
   with no emitter is safe under rule #33; the reverse is the bug). Natural follow-up
   emitters, each a one-line link once the param exists: `/manage/duplicate-songs`
   cluster rows ("start a counterpart from this one") and the songbook page's admin
   affordances. `tests/test-editor-deep-links.js` derives emitted links from the tree, so
   any future emitter is auto-covered; commit 5 adds the shell-side "reads `duplicate`"
   assertion so the handler itself is guarded from day one.

---

## 5. Entitlement + safety

- **Entitlement**: `edit_songs` via `ed2_requireEntitlement('edit_songs')`
  (api2.php:326-332). Equivalence-neutral: the default map is exactly the file's
  editor-role gate (`includes/entitlements.php:36`), so nothing changes for anyone today,
  but an operator's revocation is honoured (the #1590 posture). `create_song` itself has
  no extra gate; `edit_songs` on duplicate is defensible because a duplicate *writes
  content* (the copy), not just an empty shell.
- **CSRF**: the endpoint is a POST behind api2's global same-origin gate — every POST
  without `X-Requested-With: XMLHttpRequest` is refused (api2.php:361-363, #1307/#1677).
  This IS rule #29's `validateCsrfRequest()` posture expressed in this endpoint family;
  no baked session token is introduced. The client goes through `postJson()`
  (api-client.js:113-126), which already sends the header.
- **Soft-deleted / missing source**: 404 unknown id; 409 when `IsDeleted=1` (restore
  first, #1694). Status is the contract (rule #35) — the client branches on the number.
- **Duplicate-on-same-number guard (#1680 interaction)**: the Assign panel reuses
  `sidebar.findByBookAndNumber(abbr, n)` (`sidebar.js:351`) before applying the number —
  same check `runPrefill` performs (editor2.php:656-661) — and warns ("MP already has a
  song 123 — assign anyway?") rather than silently minting a same-number sibling.
  `idx_SongbookNumber` is non-unique so the DB permits it; the warn keeps the curator in
  charge. The songbook move itself cannot collide: `songRelocate()` mints a fresh id.
- **No cross-env leak**: everything is keyed on the shared MySQL; no channel semantics
  involved (this is not a Live-Follow surface).
- **Un-migrated tolerance**: the copy inherits `ed2_applySongSnapshot`'s partial-apply
  gates (identity/rights columns skipped when absent, api2.php:1194-1200); `PublicId`
  gated on `songPublicId_columnReady()`; enrichment copy gated on
  `lyricLinesEnrichmentTablesPresent()` (`lyric_lines_sync.php:960`); the staging-book
  find-or-create probes `IsDisabled` existence before naming it (pre-#1765 installs
  degrade to a visible staging book — acceptable; all three docroots share the one
  migrated DB in practice).

---

## 6. Implementation plan (one PR to `alpha`, ordered commits)

> Branch `feat/duplicate-song-1783`. Issue #1783 tracks; each commit references it.
> Model tier (project-rules §17): default implementation tier; commit 1 is the only one
> with real design weight.

### Commit 1 — server: `duplicate_song` endpoint (api2.php)

Files: `manage/editor/api2.php` only.

1. Constant near `ED2_CREDIT_TABLES` (:369):
   `const ED2_PENDING_SONGBOOK = 'PENDING';` — the ONE definition; every other surface
   receives it from a server response, never retypes it.
2. Helper `ed2_ensurePendingSongbook(\mysqli $db): void` — find-or-create the staging row
   (`Abbreviation='PENDING'`, `Name='Pending duplicates'`, `IsOfficial=0`,
   `IsDisabled=1` when the column exists — INFORMATION_SCHEMA probe, memoised, the
   `ed2_songMediaTableExists` shape :470-484). Find-or-create (the
   `tuneFindOrCreateByName` row precedent) rather than a migration card: idempotent,
   self-healing across the three docroots, and a data row is not DDL — schema.sql is
   untouched, so rule #19 imposes nothing. If the owner prefers a visible setup card,
   swapping to a `migrate-seed-pending-songbook.php` + registry entry later is trivial.
3. `case 'duplicate_song':` (POST `{sourceId}` → `{ok, songId, sourceId, title}`),
   modelled on `create_song` + `revision_restore`:
   - `ed2_requireEntitlement('edit_songs')`; 400 empty id.
   - `$snap = ed2_buildSongSnapshot($db, $sourceId)`; 404 if null; 409 if
     `IsDeleted == 1`.
   - Mutate the snapshot copy: `$snap['song']['Number'] = null;`
     `$snap['song']['Verified'] = 0;` and (per D2 defaults)
     `$snap['song']['Isrc'] = null;` (Ccli/Iswc stay). `SongbookAbbr` needs no strip —
     `ed2_applySongSnapshot` never writes it (:1174).
   - Transaction: `ed2_ensurePendingSongbook()` → `ed2_allocateSongId($db, ED2_PENDING_SONGBOOK)`
     → INSERT the shell row exactly as `create_song` does (:1492-1506, incl. PublicId
     gate) → `ed2_applySongSnapshot($db, $newId, $snap)` → extra copies:
     `tblSongKeys` (`INSERT … SELECT` new id), `Genre/IsExplicit/Availability` UPDATE
     from the source row, `tblSongAlternativeTitles` + `tblSongScriptureRefs`
     `INSERT … SELECT` (each wrapped in the table-exists probes the file already uses) →
     (D5 default) counterpart link: extend the source's `tblSongLinks` group or mint
     `{source, dup}` (the `song_link_add` case's insert shape, :2735; keep it a small
     shared private helper if the case body can't be called directly — do NOT fork the
     group-resolution logic, lift it) → `ed2_touchRevision($db, $newId, $uid,
     'duplicate', true)` → commit.
   - Post-commit best-effort `songbookRecomputeSongCount($db, ED2_PENDING_SONGBOOK)`
     (#1742 parity with create_song :1521-1525);
     `logActivity('song.duplicate', 'song', $newId, ['source' => $sourceId, …])`.
4. `case 'load_song'` (:1463): add top-level
   `'isPendingDuplicate' => ((string)($snapshot['song']['SongbookAbbr'] ?? '') === ED2_PENDING_SONGBOOK)`.
   Added in the CASE, not in `ed2_buildSongSnapshot()`, so revision snapshots never
   carry it.
5. `case 'load_index'` (:4391): add `'pendingSongbook' => ED2_PENDING_SONGBOOK` so the
   client can exclude it as a move TARGET without a literal (rule #35).

Verify: `php -l`; behavioural against the live dev DB — duplicate a rich song (credits,
tags, links, arrangement, key, tune, per-line chords), then diff
`load_song(source)` vs `load_song(dup)` field-by-field: identical except
`SongId/PublicId/Number/SongbookAbbr/Verified/Isrc/CreatedAt` (+ media empty). Confirm the
staging book is absent from the public `/api?page=songbooks` and present in the admin
sidebar. Duplicate a soft-deleted song → 409. Re-run duplicate twice → two distinct
`PENDING-…` ids.

### Commit 2 — client: button + deep-link + api method

Files: `manage/editor/v2/api-client.js`, `manage/editor/editor2.php`.

- `api-client.js`: `duplicateSong: (sourceId) => postJson('duplicate_song', { sourceId })`
  under "Song lifecycle" (:162-164).
- `editor2.php`: toolbar `Duplicate` button after `New` (:194); handler = confirm →
  `duplicateSong(currentSongId)` → `sidebar.refresh()` → `loadSong(res.songId)` → status
  copy explaining the empty-songbook state. PHP-side: read `?duplicate=` with the :43
  sanitiser; boot branch (before the `initialSongId` branch, :686) → confirm modal →
  same flow. Store the shell flag on load: in `loadSong()` (:452-467) add
  `store.set('pendingDuplicate', !!data.isPendingDuplicate);` and keep
  `sidebar`'s songbook list intact (do NOT filter `PENDING` out of the sidebar — admin
  surfaces show it; only the *move-target* select excludes it, commit 3).

Verify: `node --check`; button on an open song → editor re-opens on `PENDING-…` with all
content present; `?duplicate=MP-1008` cold load → confirm → same; cancel → no row minted
(check DB); cross-check no other page emits `?duplicate=` yet
(`grep -rn "duplicate=" appWeb/public_html` — clean as of this analysis).

### Commit 3 — Metadata tab: draft presentation + Assign panel

Files: `manage/editor/v2/metadata-tab.js`, `manage/editor/editor2.php` (pass
`pendingDuplicate` + `pendingSongbook` through ctx).

- When `store.get('pendingDuplicate')`: skip the normal `number` + `songbook` FIELDS rows
  (:37-38) and render an **Assign fieldset** first: songbook `<select>` with a selected
  empty placeholder (`— choose songbook —`; options = `getSongbooks()` minus the
  staging abbr, re-filled via `whenSongbooksReady()` exactly like
  `renderSongbookSelect` :242-246), an empty number `<input>`, and an **Assign** button.
- Assign handler (sequenced, mirrors `runPrefill` :663-679):
  `findByBookAndNumber` warn → `api.updateMetadata(songId, 'songbook', abbr)` (the
  relocate; NO extra confirm here — the draft-specific copy replaces the scary generic
  move dialog, which talks about redirects and cleared numbers that don't apply to a
  minutes-old draft) → on `res.songId !== res.previousId`, if a number was entered,
  `await api.updateMetadata(res.songId, 'number', n)` → `onSongIdChange(previousId,
  res.songId)` re-opens under the permanent id; the re-loaded song is no longer
  `isPendingDuplicate`, so the tab renders normally.
- Failure surfaces per rule #35: branch on `err.status` (422 bad book, 409 un-migrated),
  never the sentence; the runPrefill precedent for "created but number failed" wording
  (:668-673) applies to "assigned but number failed".

Verify: draft shows both fields EMPTY; assign with number → song lands as `MP-…`,
number set, tab shows normal fields; assign without number → number stays NULL; server
error mid-sequence leaves an accurate status line.

### Commit 4 — enrichment copy (D6 default; drop cleanly if declined)

Files: `manage/editor/api2.php` (inside the `duplicate_song` transaction).

Gated on `lyricLinesEnrichmentTablesPresent()`: read
`lyricLinesFetchPrimary($db, $sourceId)` and `…($db, $newId)` (both in global
`SortOrder`, `lyric_lines_read.php:197-221`); the two lists are same-length by
construction (both derive from the same component payload); build
`srcLineId → newLineId` by index; `INSERT` mapped copies of `tblLyricLineTranslations`
(LineId) and `tblLyricLineAnnotations` (StartLineId + nullable EndLineId, offsets copied
verbatim — they are code-point offsets into identical text, rule #21). Any mapping miss
(defensive): skip that row, never abort the duplicate.

Verify: annotate + translate two lines on a test song, duplicate, `load_song(dup)` shows
`lineTranslations`/`lineAnnotations` anchored on the NEW line ids.

### Commit 5 — guards + tests (rule #34: derived + mutation-proven)

Files: `tests/` (+ npm/CI list wherever the neighbouring tests are registered — check
`package.json`/CI config, rule #35's npm-vs-CI lesson).

- `tests/test-editor-duplicate-contract.js` (static, comment-stripped): asserts
  (a) `api-client.js` has `duplicateSong` posting `duplicate_song`; (b) `api2.php` has
  `case 'duplicate_song'` gated by `ed2_requireEntitlement('edit_songs')`;
  (c) `editor2.php` reads `duplicate` (add the param to the shell-reads set in
  `tests/test-editor-deep-links.js` so a future emitter is covered); (d) the client
  never contains the literal `'PENDING'` (the abbr must arrive from a server response).
  **Mutation-prove each leg**: rename the case, watch it go red, restore; ditto the
  entitlement line and the param read.
- `tests/php/test-duplicate-copy-set.php` (static): asserts the `duplicate_song` case
  nulls `Number`, resets `Verified`, delegates to `ed2_applySongSnapshot` (no second
  copy loop — the modularity red-flag), and touches no banned table
  (`tblSongRevisions`/`tblSongRedirects`/`tblUserFavorites` INSERTs inside the case).
  Keep it narrow enough not to fail on correct code (rule #34's second edge).
- Manual behavioural checklist against alpha (the commit-1/2/3 verifies), recorded in the
  PR description.

### Commit 6 — standing tasks (`.claude/standing-tasks.md`)

Issue #1783 updated with commit SHAs + evidence; follow-up issues filed at discovery
moment: (i) per-line `Note` not carried by the editable shape (restore + duplicate,
§2.4, `for consideration`); (ii) optional emitters for `?duplicate=` on
`/manage/duplicate-songs`; (iii) optional `songRelocate()` no-redirect flag for
staging-origin moves. CHANGELOG, DEV_NOTES, Wiki editor page, `ProjectBrief.md` delta;
this plan file updated to "as-built".

---

## 7. Owner decisions

**D1 — the mechanism: hidden staging book (Option C) vs a truly-unsaved client draft (Option A).**
*Why it needs deciding*: the schema cannot store a song without a songbook (the id IS
`<book>-<n>`, rule #27), so "empty" is either presentation over a hidden real row (C) or
a client-side draft that exists nowhere until saved (A). This is a product-feel question
the code cannot answer.
*Options*: **C** — duplicate is a real hidden row; editor fields start empty; assign
re-keys it; every editor feature works from second one; ~4 focused commits. **A** — no
row until "Create"; every v2 tab needs a draft write-layer emulating ~35 server response
shapes; media/revisions/export disabled in draft; a reload loses work; weeks not days,
and it re-imports the deferred-save model v2 replaced (#1178). **Do nothing** — curators
keep re-typing songs by hand.
*Recommendation*: **C**, strongly — it delivers the described experience (empty fields,
edit anything, then commit) at ~10% of A's cost and reuses three battle-tested cores.
*Reply needed*: "C" (or "A"). **This is the one decision worth having before commit 1;
everything else proceeds on defaults.** It does not block the branch being cut.

**D2 — identifiers: what does a duplicate keep?** *Default (non-blocking)*: copy
**work-level** ids (CCLI, ISWC — same underlying hymn) and the external LINKS rows;
clear **recording-level** ids (ISRC, UPC, recording-scope `tblSongExternalIds`,
`tblSongRoyaltyIds`) because they identify a specific recording tied to media we don't
copy. *Reply*: "default fine" or name exceptions. Trivially changeable later.

**D3 — media**: copy `tblSongMedia` (audio/sheet bytes) or not? *Default
(non-blocking)*: **no** — storage duplication, and the new book's rendition usually
differs; `HasAudio`/`HasSheetMusic` reset to 0 accordingly. *Reply*: "default fine" /
"copy media too".

**D4 — `Verified`**: reset to 0 on the duplicate? *Default*: yes. One-word reply.

**D5 — auto-link the duplicate to the source as cross-book counterparts
(`tblSongLinks`)?** *Default (non-blocking)*: **yes** — the feature's premise is "same
hymn, another songbook", which is literally what that table records (#1608), and linking
suppresses the pair from the duplicate-review queue instead of re-surfacing it as a
suspected accident. *Reply*: "default fine" / "no link".

**D6 — copy per-line translations/annotations?** *Default (non-blocking)*: yes (commit
4, cleanly droppable). *Reply*: one word.

**D7 — staging book label**: `PENDING` / "Pending duplicates". *Default*: as stated;
rename any time (the abbr is load-bearing only until the first assign; existing drafts
would need a relocate if it ever changed, so pick once).

---

## 8. Red-flag self-check (CLAUDE.md)

- No second copy loop: the duplicate delegates to `ed2_applySongSnapshot` (one apply
  engine) and `lyricLinesWriteComponents` (one line write, rule #25).
- No client literal for the staging abbr (rule #35) — server-emitted.
- No `IsOfficial=0` filtering (rule #24) — hiding uses `IsDisabled` (#1765), and only
  the *move-target* select omits the staging book.
- Status-code branching, never prose (rule #35); `X-Requested-With` on the new POST
  (rule #29 posture, #1677).
- New deep-link param is read by its destination and covered by the derived guard
  (rules #33/#34); the guard is mutation-proven before it counts as coverage.
- No migration DDL → schema.sql untouched (rule #19 n/a); the staging row is
  find-or-create data, not schema.

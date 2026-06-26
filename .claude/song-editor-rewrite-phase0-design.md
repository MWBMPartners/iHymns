# Song Editor Rewrite — Phase 0 server design (from the design workflow, 2026-06-08)

> Durable capture of the Phase 0 design workflow (run `waubx26co`). Implement against this. All line refs are as of 2026-06-08; verify before relying.

## A. `delete_song` — bare cascade delete (CORRECTS the earlier "16/40 cascade" grep)

**Finding:** every inbound FK to `tblSongs(SongId)` is **ON DELETE CASCADE or SET NULL — none RESTRICT.** (My earlier `grep -c "ON DELETE CASCADE"` only matched same-line cascades and missed the multi-line FK definitions.) 40 inbound FK constraints across 32 tables; 4 direct children are themselves parents (deep chains all CASCADE):

- **Components:** `tblSongs → tblSongComponents → tblSongChords / tblPresentation{ThemeAssignments,SlideOverrides,FormatFidelity}`. (Note `tblLyricLines.ComponentId` is a **soft link, NO FK** — cleaned via the Lyrics chain.)
- **Lyrics (deepest):** `tblSongs → tblLyrics → tblLyricLines → tblLyricWords → tblLyricSyllables`, plus `tblLyricLine{Translations,Annotations,VocalParts}`, `tblLyricWordVocalParts`, `tblLyricsSourceDocuments`, `tblLyricAnnotationVotes`. (`tblVocalParts` is a GLOBAL registry — do NOT delete; only its junction rows go.)
- **Arrangements:** `tblSongs → tblSongArrangements → tblPresentationThemeAssignments`.
- SET NULL (row survives, pointer nulled — correct): `tblSongRequests.ResolvedSongId/SongId`, `tblLiveFollowSessions.CurrentSongId`, `tblLyricsConflicts.IncomingLyricsId`, `tblSongScriptureRefs.StartLineId`.

**Implementation:** a single `DELETE FROM tblSongs WHERE SongId = ?` inside a transaction is FK-safe + orphan-free — InnoDB walks the whole subtree. Do **not** hand-order child deletes, do **not** disable FK checks. Verify `affected_rows === 1` (else "not found" → rollback). Best-effort `logActivity('song.delete', …)`. **Validate against a local MySQL** (create a song + children, delete, confirm cascade + zero orphans) before trusting — the all-cascade claim is load-bearing.

## B. Canonical SongId scheme — `<ABBR>-<NNNNNN>` (server-owned)

- Numberless/Misc songs: **`<ABBR>-<6-digit zero-padded per-songbook decimal sequence>`** (e.g. `MISC-000001`). ≤17 chars (fits `SongId VARCHAR(20)`), same `<PREFIX>-<digits>` grammar as official `<ABBR>-<NNNN>` ids → no new router/URL parsing. Official numbered songbooks keep their existing `sprintf('%s-%04d')` path unchanged.
- **Allocator** `editorAllocateSongId($db, $abbr)` — inside the create txn: validate `$abbr` exists in `tblSongbooks` (reuse the IsOfficial probe at api.php:1056); `SELECT … FOR UPDATE` the current max numeric tail for that `<ABBR>` (`WHERE SongbookAbbr=? AND SongId REGEXP '^<ABBR>-[0-9]+$' ORDER BY CAST(SUBSTRING(...) AS UNSIGNED) DESC LIMIT 1`), `next = tail+1`, format `%s-%06d`; retry loop (max 5) on the rare UNIQUE clash. `<ABBR>` is an allow-listed constant from `tblSongbooks` (regex-escaped) — the only non-bound fragment, satisfies CLAUDE.md rule #5.
- **Create-vs-update protocol:** add an explicit **`create_song`** action that allocates + INSERTs + returns the new `SongId`; all granular `*_update`/`*_delete` actions key on the **server** id. `save_song` already echoes `'songId' => $songId` (api.php:1858) — the handshake channel exists; the client just needs to adopt it (update `editor.js` `autoSaveSongsPerSong` to read `data.songId` and swap the local draft id + the URL). Existing songs + their FK refs are **unaffected** (no id migration).

## C. Granular endpoint contracts (each atomic, guarded, revision+activity)

`component_upsert` (one tblSongComponents row: type/number/lines/chords/language/sortorder) · `component_reorder` (sortorder across a song's components + arrangement remap) · `component_delete` (one component; its presentation/chord children cascade) · `credit_upsert` / `credit_delete` (one row in tblSong{Writers,Composers,Arrangers,Adaptors,Translators,Artists} — role + structured name parts; reuse save_song's credit-normalisation, extract to a shared helper) · `metadata_field_update` (one scalar tblSongs field — title/number/songbook/language/ccli/iswc/tuneName/copyright/verified/PD flags/origin) · `tag_add`/`tag_remove`, `link_add`/`link_remove`. Each returns `{ok, ...}`. Extract from save_song into shared helpers: credit normalisation, component sanitisation, the revision writer.

## D. Security + audit "follow this" (MANDATORY for every new endpoint)

1. **Auth guard** (top of api.php, already present): `isAuthenticated()` → 401; `getCurrentUser()` + `hasRole($u['role'],'editor')` → 403. `$currentUser['id']` is the audit actor.
2. **CSRF — GAP TO CLOSE:** editor/api.php does **NO CSRF validation today**; editor.js sends no token (only the session cookie + `X-Requested-With`). **New Phase 0 endpoints MUST validate CSRF.** Helpers exist in auth.php: `csrfToken()` (line 934), `validateCsrf($t)` (line 949). Plan: emit `<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(),ENT_QUOTES,'UTF-8') ?>">` in editor/index.php; client adds `X-CSRF-Token` header to POSTs; server reads `$_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''` and `validateCsrf()` → 403 on every state-changing POST (not GET reads).
3. **bind_param** everything (the save_song UPSERT is the template); handle via `getDbMysqli()`; type chars match the value list 1:1.
4. **Revision (#400):** snapshot `SELECT * FROM tblSongs WHERE SongId=?` BEFORE the write (`$previousData`); after the write, best-effort INSERT into `tblSongRevisions (SongId,UserId,Action,PreviousData,NewData,Status='approved')` (try/catch — a revision failure must NOT roll back the edit). **Coalesce** for granular saves so we don't write one revision per keystroke — recommend per-song debounce (one revision per logical edit / per N-seconds idle), not per field-change.
5. **logActivity** + JSON `{ok}` / `{error, error_detail(admin-only)}` response (admin gated on `$currentUser`).

## Phase 0 commit plan (atomic commits on `claude/song-editor-rewrite-phase0`; ONE PR at the very end)
1. CSRF infrastructure (meta token + server helper) — the security foundation.
2. `delete_song` (bare cascade + guards + CSRF + affected_rows + activity) — validated vs local MySQL.
3. `create_song` + `editorAllocateSongId` (server-owned id) + the client handshake.
4. Granular CRUD endpoints (component_*, credit_*, metadata_field_update, tag/link) + extracted shared helpers.

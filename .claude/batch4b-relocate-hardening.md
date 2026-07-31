# Batch 4b — hardening the #1679 songbook-move re-key

**Status:** in progress, 2026-07-31.
**Parent:** `.claude/remediation-plan-2026-07-30.md` §4.4 (Batch 4, commit `d8ecfa35`).
**Branch:** `claude/wave3-fixes`.

Two adversarial reviews (Opus, lenses "data integrity" and "guard integrity + cascade safety") were
run against `d8ecfa35`. Both concluded the **runtime mechanism is sound** — the cascade reasoning,
the redirect ordering and the `PublicId` claim all survived checking — but the **guard is
defeatable**, and there are real data-integrity gaps the guard is structurally unable to see.

Every finding below was **re-verified by hand against the tree** before being written down. The
reviews claimed more than this; what is listed here is what I confirmed myself.

---

## Verified findings

### H1 — the v2 songbook field makes an irreversible re-key a per-keystroke event  · HIGH

`manage/editor/v2/metadata-tab.js:20`

```js
['songbook', 'Songbook (abbr)', 'SongbookAbbr', 'text'],
```

is a plain text input; `input` → `debouncedSave` at `SAVE_DEBOUNCE_MS = 500`. Since `d8ecfa35`
that POST branches into `songRelocate()`.

Before the commit each debounce tick wrote one scalar column, recoverable by retyping. Now **every
tick that happens to name a real songbook** mints a new SongId, cascades ~41 child tables, clears
`Number`, rewrites content restrictions and writes a permanent `tblSongRedirects` row.

A curator clearing the field and typing `C`, `P` who pauses ≥500 ms after `C` moves the song to
songbook `C`. Moving back does not restore the id or the Number, and each hop leaves a redirect row.

v1's equivalent is an explicit button validating against the loaded songbook list
(`editor.js:6444`). v2 has no validation, no confirmation, and fires per keystroke-pause.

**Fix:** make `songbook` a `<select>` of real songbooks, saved on `change` (never debounced), behind
an explicit confirm that names the consequences. The field kind needs a new `'select'` branch.

### H2 — the guard's CHECK 1 is a per-FILE presence test  · HIGH

`tests/php/test-song-relocate-funnels.php:238`

```php
if (!preg_match('/\bsongRelocate\s*\(/', $src)) { $missing[] = …; }
```

Once a file contains **one** call anywhere, every per-song songbook write in that file passes. The
two files most likely to grow a new funnel — `save_song_core.php` and `api2.php` — are exactly the
two that now contain a call, so both are permanently exempt.

Three further bypasses in `relocGuardUpdateStatements()`:

| # | Bypass | Why |
|---|---|---|
| a | `UPDATE tblSongs SET SongbookAbbr = ?, X = 'y' WHERE SongId = ?` | `:162` bounds the statement with `strcspn($tail, "'\"")`, so a quoted value in the SET clause cuts the statement before its `WHERE`; `count($parts) < 2` and it is `continue`d. Real code already trips this (`includes/lyrics_ingest.php:659`). |
| b | `UPDATE tblSongs SET SongbookAbbr = ? WHERE Id = ?` | `:184` requires `SongId\s*=` in the WHERE. `tblSongs.Id` is the AUTO_INCREMENT PK and `migrate-song-normalized-title.php:143` already uses that idiom on this table. |
| c | ``UPDATE `tblSongs` SET `SongbookAbbr` = ? …`` | `:159` matches `UPDATE\s+tblSongs\b`, so a backticked identifier is invisible. `api2.php` already writes backticked identifiers. |

### H3 — `songRelocate()` depends on `ON UPDATE CASCADE` without verifying it  · HIGH

`schema.sql` is 41/41 cascading, so a **fresh** install is fine. But four FKs were created by
migrations **without** `ON UPDATE CASCADE` (confirmed by reading each):

| migration | constraint |
|---|---|
| `migrate-external-links.php:322` | `fk_link_song` |
| `migrate-alternative-titles.php:133` | `fk_alt_song` |
| `migrate-song-media.php:144` | `fk_media_song` |
| `migrate-works.php:220` | `fk_work_song_song` |

Only `migrate-songid-prefix-fixup.php` retro-fits them, and its registry probe tests for a
**prefix mismatch**, not for the cascades — so an install that never renamed an abbreviation shows
that card as *not pending* forever and keeps four RESTRICT FKs. "Apply all pending" never surfaces it.

On such an install, moving any song with a media row / external link / alt title / work membership
throws `ER_ROW_IS_REFERENCED_2`, and `save_song_core.php` rolls back the **whole** save — the curator
loses lyrics, components and credits, with a generic "Failed to save song".

**Every other re-key path in the tree defends**: `migrate-backfill-canonical-songids.php:96` refuses
with a pointer to the fixup; `includes/songbook_maintenance.php:152` catches and reports `deferred`
with the same pointer. `songRelocate()` does neither.

**Fix:** a pre-check that reads `INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS` for FKs referencing
`tblSongs` whose `UPDATE_RULE <> 'CASCADE'`, and refuses the move (before any write) with the same
pointer the precedents use. Fail-closed and diagnosable, instead of fail-closed and baffling.

### F3 — every move strands `tblSongbookEntries`  · MEDIUM-HIGH

`schema.sql:228` — `SongId` is `ON UPDATE CASCADE` so it re-keys, but `SongbookAbbr`, `SongNumber`
and `IsHome` are not reachable from a `SongId` change. After a move the row reads
**(old book, NEW id, old number, IsHome=1)**: the junction claims the song's home is the book it
just left, `uq_book_song` holds an impossible pair, and `uq_book_number` keeps the vacated slot
occupied in the old book while `tblSongs.Number` was cleared.

`schema.sql:233`'s own COMMENT states `IsHome` is *"kept in sync with tblSongs.SongbookAbbr"* — the
move breaks that stated invariant with nothing detecting it.

Latent today (the table is documented "not yet read by the app"), but unrecoverable later: once
something reads it there is no way to tell a stale home entry from a genuine multi-book membership.
The helper's doc-block names `tblContentRestrictions.EntityId` as "the ONE soft reference the
cascade cannot reach" — that is wrong, there are two.

### M1 — the mint can re-issue an id a live redirect still claims  · MEDIUM

`songRelocateMintId()` (`song_relocate.php:145`) probes only `tblSongs`; it never consults
`tblSongRedirects.OldSongId`. `_bulkImport_nextSongNumberFor()` seeds from `MAX(Number)+1` and
`songRelocate()` sets `Number = NULL`, so moving the highest-numbered song out of a book frees its
slot and the next mint in that book can re-issue the exact id the redirect points away from.
`getSongById()` then resolves it by exact match, the redirect is never consulted, and an old
bookmark gets **200 OK with a different song**. Wrong content is worse than the 404 this feature
set out to remove.

### M2/F6 — an omitted `songbook` key is now a destructive move to `Misc`  · MEDIUM

`save_song_core.php:137` reads `$song['songbook'] ?? ''`, `:145` coerces `''` → `'Misc'`, and
`:380` then compares the **defaulted** value against the previous book. So the guard
`$songbookAbbr !== ''` can never be false. A partial `save_song` POST for a song in `MP` re-keys it
into `Misc`, clears `Number` and writes a permanent redirect; pre-commit the same payload wrote a
wrong-but-reversible column value. The test must be `array_key_exists('songbook', …)` on the raw
payload.

### M3 — the one security-relevant step is the one made non-fatal  · MEDIUM

`song_relocate.php:250` wraps the `tblContentRestrictions` rewrite in `try { … } catch (\Throwable)`
+ `error_log`. The doc-block itself says leaving a restriction on the dead id "silently DROPS the
restriction" — withheld content becomes accessible, the move commits anyway, and the only trace is
`error_log`.

### F8 — swallowing a mysqli exception inside the caller's transaction, then committing  · MEDIUM

Steps 5 and 7 both catch-and-continue **inside the caller's transaction**. A deadlock (1213) or
lock-wait timeout (1205) rolls back the *entire* InnoDB transaction, not just the statement;
execution then reaches `$db->commit()` and the endpoint answers `{ok:true, songId:<new>}` for a
song that no longer exists under that id.

### F5 — v1 writes the stale `Number` back after a move  · MEDIUM

`editor.js:5140` re-keys `id`, `currentSongId`, `modifiedSongIds`, `_renderedSongId` — **not
`number`**, and `#edit-number`'s DOM value is never refreshed. `bindMetadataListeners()` binds
`edit-number → song.number`, so the next save in the same session posts the OLD number back. The
`Number` clear is silently undone and can collide in the target book (`tblSongs` has only
`INDEX idx_SongbookNumber`, not UNIQUE).

### F9 — `song_data`'s OpenAPI path was not updated  · LOW

`api.php:969` is `case 'song_detail': case 'song_data':` — one handler. The yaml added
`redirectedFrom` + `410` only to `song_detail`; `song_data` still documents 404 only, and its own
summary calls `song_detail` an "alias of song_data", so the primary documented endpoint is the
undocumented one.

### F10 — CHECK 2 fails on correct code, and its self-test is decoupled  · LOW

Rewriting the skip to the semantically identical `if ($field === 'songbook') { continue; }` turns
CHECK 2 red. Self-test M5 hardcodes the exact literal, so its verdict is independent of whether the
production skip exists.

---

## Not fixed here, and why

- **F4 — the `getSongByNumber` fallback can mask a redirect.** `SongData::getSongById()` falls back
  to `getSongByNumber($prefix, $number)` on an exact miss, and both readers consult the redirect
  layer only when that returns null. This is genuinely **pre-existing** (it predates #1679) and
  changing lookup-fallback ordering touches the hottest read path in the app. Filed separately
  rather than bundled into a hardening commit. M1's fix removes the case #1679 *creates*.
- **F11/F12, L2, L3 — doc-block overstatements.** Corrected in place as part of the fixes above,
  not tracked separately.

---

## Method note

Three consecutive batches shipped wrong-but-green assertions, and this is the fourth. The pattern is
now unmistakable: **guards written in the same pass as the code they guard get graded by their
author against the shapes their author already thought of.** M1–M7 of the Batch 4 guard all pass
because each mutates a shape the parser already handles; none probes the per-file scope or the
statement bounding.

The counter-measure that actually worked here was an adversarial reader with a different lens and a
mandate to mutate the real tree. Keep doing that, and keep writing down what was checked and found
**correct** — one review's "what I tried to break and could not" list is what stopped this batch
re-deriving the cascade argument from scratch.

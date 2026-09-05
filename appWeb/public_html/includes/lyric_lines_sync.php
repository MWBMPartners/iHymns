<?php

declare(strict_types=1);

/* #1688 A1/§1a — `songRelocateIsTransactionFatal()`.
   ELI5: the one place that knows which database errors mean "your transaction
   is already dead, stop pretending it worked".
   Every catch below asks it, so it has to be loaded before any of them runs; a
   `function_exists()` fallback would be worse than a require, because the
   fallback silently reinstates the swallow this include exists to remove.
   No cycle: song_relocate.php pulls in only db_mysql.php + song_redirects.php,
   neither of which reaches back here. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_relocate.php';

/* #2073 commit 5 — the new carry-over write helpers below (only) call
   bindParamSafe() (#928's bind_param count-guard) rather than the raw
   mysqli method every OTHER function in this file has always used — added
   here, unconditionally, rather than assumed-already-loaded, because this
   file has never required db_mysql.php itself before now. Defines
   functions only; opens no connection of its own on include. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/**
 * iHymns — tblLyricLines mirror/sync helper (#1235 P1, lyric-line normalisation)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Projects a song's CURRENTLY-AUTHORITATIVE `tblSongComponents`
 * (LinesJson / ChordsJson / NotesJson + Type/Number/Language) into the
 * normalised `tblLyricLines` mirror that #1235 is making the single source of
 * truth (Option 1 — part identity lives ON the line).
 *
 * PHASE 1–2 (now): `tblSongComponents` stays authoritative; `tblLyricLines` is a
 * kept-in-sync MIRROR (the backfill migration + a transitional dual-write on
 * every component write) and, since P2a, the READ source for line text. The
 * projection here is an **Id-preserving diff** (#1235 P2b): it matches the song's
 * pre-edit lines to its post-edit lines BY CONTENT and UPDATEs them in place, so
 * a line's `Id` — and every per-line enrichment FK'd to it (timing #141,
 * translations / annotations #1088, exposed in P3) — survives an edit instead of
 * being orphaned by the old whole-song delete + reinsert.
 *
 * ONE projection function, reused by the backfill migration AND every editor /
 * import write path — so the two can never diverge (the modularity rule).
 *
 * Requires getDbMysqli() (includes/db_mysql.php). The DB layer runs mysqli under
 * MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, so failing statements throw.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Is the P1 mirror schema present (the per-line chord/note columns added by
 * migrate-lyric-lines-mirror.php)? Dual-write callers MUST skip when this is
 * false — migrations are not auto-applied, so an un-migrated install keeps
 * working on `tblSongComponents` alone rather than throwing on a missing column.
 * Memoised per request.
 */
function lyricLinesSyncReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        /* Require ALL THREE late-added per-line columns — a half-applied mirror
           (ChordsJson added, Note not) OR a missing PartTypeSlug (#1138, added by the
           separate migrate-song-part-types) must NOT report ready, because the projector
           (lyricLinesApplyDesired) SELECTs + writes PartTypeSlug, ChordsJson AND Note — a
           missing one throws under STRICT. DELIBERATELY the same set as
           lyricLinesMirrorPresent() so the WRITE gate and the READ gate are aligned (#1235
           P4/C5 review): reads + writes flip to the mirror together. */
        $r = $db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblLyricLines'
                AND COLUMN_NAME  IN ('ChordsJson', 'Note', 'PartTypeSlug')"
        );
        $row   = $r ? $r->fetch_row() : null;
        $ready = ($row !== null && (int)$row[0] >= 3);
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        /* #1688 A1/§1a — a deadlock is not "best effort failed", it is the whole
           transaction already gone. Every catch in this file can run inside the
           caller's transaction (both save funnels call lyricLinesWriteComponents
           between begin_transaction() and commit()), so swallowing 1213/1205 here
           lets the caller commit nothing and still answer ok:true. The code list
           lives once, in song_relocate.php — a copied list is the "keep these in
           sync" comment rule #35 calls the failure rather than the fix.
           A MISSING table still returns false (1146 is not in the fatal set), so
           the fail-open behaviour on an un-migrated install is unchanged. */
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
        $ready = false;
    }
    return $ready;
}

/**
 * Is the per-line language column (tblSongComponents.LanguagesJson, #1235 P3 /
 * #1253) present? The projector reads it for per-line language overrides; when
 * absent (un-migrated install) every line inherits the component Language, so the
 * SELECT must omit the column rather than error. Memoised per request.
 */
function lyricLinesComponentsLangReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblSongComponents'
                AND COLUMN_NAME  = 'LanguagesJson' LIMIT 1"
        );
        $ready = ($r && $r->fetch_row() !== null);
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        /* #1688 A1/§1a — a deadlock is not "best effort failed", it is the whole
           transaction already gone. Every catch in this file can run inside the
           caller's transaction (both save funnels call lyricLinesWriteComponents
           between begin_transaction() and commit()), so swallowing 1213/1205 here
           lets the caller commit nothing and still answer ok:true. The code list
           lives once, in song_relocate.php — a copied list is the "keep these in
           sync" comment rule #35 calls the failure rather than the fix.
           A MISSING table still returns false (1146 is not in the fatal set), so
           the fail-open behaviour on an un-migrated install is unchanged. */
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
        $ready = false;
    }
    return $ready;
}

/**
 * Find (or create) the primary `tblLyrics` version row for a song — the
 * `Source = 'ihymns'` canonical version, unique per song via uq_song_source —
 * and return its Id. Idempotent: re-runs return the existing row.
 *
 * ELI5: look up the row for "our own copy of the words" for this song, and
 * make one if it has never had one before.
 *
 * DETAILED (#2076): the "find" half delegates to the ONE shared resolver
 * `lyricLinesPrimaryLyricsId()` in `lyric_lines_read.php`, so this write-side
 * lookup and every read-side one use the literal same SELECT — they cannot
 * drift apart the way `SongData::_primaryLyricsId()` once did.
 *
 * ERROR POLICY (regression fix — an independent review caught this before
 * ship): this is a find-OR-CREATE running INSIDE the caller's transaction
 * (`lyricLinesApplyDesired()` calls it between that transaction's
 * `begin_transaction()` and `commit()`), so it does TWO things the resolver
 * doc-block's "ANSWER OR FAIL" contract exists to make possible:
 *  1. It passes `$useCache = false` — a find-or-create must always see LIVE
 *     database state. A cached "found" answer from earlier in this same
 *     transaction could be a row that a later ROLLBACK undoes, and a cached
 *     "found" answer would then be a lie for the rest of the request (see
 *     the resolver's "WHY A FOUND ROW..." doc-block for the full reasoning).
 *  2. It does NOT catch anything around the resolver call. A genuine DB
 *     failure (deadlock, lock-wait timeout) must propagate — deliberately
 *     UNCAUGHT here, exactly like the INSERT/UPDATE/DELETE statements
 *     elsewhere in this file that have no try/catch of their own — so the
 *     caller's own `catch (\Throwable $e) { $db->rollback(); throw $e; }`
 *     runs, instead of this function silently treating "the transaction is
 *     already dead" as "no row exists yet, create one": that swallow is
 *     exactly what let a dead transaction survive into a duplicate INSERT
 *     and a save that reported success over a partial write.
 */
function lyricLinesEnsurePrimaryVersion(\mysqli $db, string $songId): int
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
    $id = lyricLinesPrimaryLyricsId($db, $songId, false);
    if ($id > 0) {
        return $id;
    }
    /* No 'ihymns' version yet — create the canonical primary one. Approved so it
       renders once reads switch in P2; the (SongId,'ihymns') unique makes this
       race-safe-ish (a duplicate INSERT would throw and the caller retries). */
    $ins = $db->prepare(
        "INSERT INTO tblLyrics (SongId, Source, FormatVersion, IsPrimary, Status)
         VALUES (?, 'ihymns', '1.0', 1, 'approved')"
    );
    $ins->bind_param('s', $songId);
    $ins->execute();
    $id = (int)$db->insert_id;
    $ins->close();
    return $id;
}

/**
 * Project ALL of a song's components into `tblLyricLines` (the primary version)
 * using an **Id-preserving diff** (#1235 P2b) so per-line enrichment — line/word
 * timing (#141), per-line translations / annotations (#1088) — survives an edit
 * instead of being orphaned by a delete-all + reinsert.
 *
 * Why a diff and not a wipe: `tblLyricLineTranslations` / `tblLyricLineAnnotations`
 * (and `tblLyricWords` timing) FK `tblLyricLines.Id`. The old P1 reproject deleted
 * every line on every save, so each line's new Id broke those FKs (CASCADE wiped
 * the enrichment). The diff matches the song's PRE-edit lines (what is currently
 * in `tblLyricLines`) to its POST-edit lines (freshly derived from the now-
 * authoritative `tblSongComponents`), UPDATEs the matched rows IN PLACE (Id — and
 * therefore every dependent FK — preserved), INSERTs genuinely new lines, and
 * DELETEs removed ones (CASCADE then drops their now-orphaned enrichment, which is
 * correct — that line is gone).
 *
 * MATCHING IS BY CONTENT, NOT `ComponentId`. `tblSongComponents` has no stable
 * natural key, and the legacy editor `save_song` (plus the v2 `components_replace`
 * "Paste & Reflow" / single-song-import and snapshot-restore paths) DELETE +
 * re-INSERT every component on save, minting fresh component Ids. So `ComponentId`
 * is a soft traceability hint only — lines are aligned by part identity
 * (`PartType` + `PartNumber`) + line text. See lyricLinesDiff().
 *
 * Idempotent: a re-run with no lyric change matches every line exactly and the
 * dirty-check skips the no-op UPDATEs (zero writes, `UpdatedAt` untouched). Safe
 * to call on every component write and to re-run in the backfill. Call only when
 * lyricLinesSyncReady() is true (the columns exist).
 *
 * @param \mysqli $db
 * @param string  $songId
 * @return int  number of lines now stored for the version (== desired count)
 */
function lyricLinesProjectSong(\mysqli $db, string $songId): int
{
    /* LEGACY / BACKFILL path — desired lines are derived by RE-READING the
       (pre-C5-authoritative) tblSongComponents JSON columns. The #1235 P4/C5
       cutover write path is lyricLinesWriteComponents() below: it builds desired
       lines from the in-memory edit PAYLOAD (never re-reading LinesJson) and makes
       tblLyricLines the source of truth, so it survives the C6 JSON-column drop.
       This LinesJson-sourced projector stays for the backfill migration (which has
       no payload — it reprojects what is already stored, pre-drop). */
    /* Post-C6 self-guard: once tblSongComponents.LinesJson is dropped there is no
       legacy source left to reproject from, and lyricLinesBuildDesired()'s SELECT would
       throw under STRICT. The mirror is already authoritative, so a re-run of the backfill
       migration (its setup-database card still offers a "safe to re-run" button) is a
       genuine no-op — return early rather than throw (#1235 P4/C5 review). */
    if (!lyricLinesShadowColumnsPresent($db)['LinesJson']) {
        return 0;
    }
    return lyricLinesApplyDesired($db, $songId, lyricLinesBuildDesired($db, $songId));
}

/**
 * Apply a pre-built DESIRED line list to a song's primary version using the
 * Id-preserving diff (#1235 P2b). The ONE place lines are written — shared by the
 * legacy/backfill projector (lyricLinesProjectSong, desired ← LinesJson) AND the
 * cutover write path (lyricLinesWriteComponents, desired ← edit payload), so the
 * diff/dirty-check/PF2-snapshot logic can never diverge between them.
 *
 * #2072 — for a MATCHED (UPDATE) line, lyricLinesMergePreserved() is spent on
 * the desired row BEFORE either the dirty-check (lyricLinesRowClean) or the
 * UPDATE bind reads it, so a per-line field the caller's payload never
 * mentioned (Note/ChordsJson `_preserve`, set by
 * lyricLinesBuildDesiredFromComponents()) reclaims its currently-stored value
 * instead of being silently NULLed by this function's otherwise-unconditional
 * full-row UPDATE. See lyricLinesMergePreserved()'s own doc-block for the full
 * reasoning.
 *
 * @param list<array<string,mixed>> $desired  lyricLinesBuildDesired()-shaped entries
 * @return int  number of lines now stored for the version (== desired count)
 */
function lyricLinesApplyDesired(\mysqli $db, string $songId, array $desired): int
{
    $lyricsId = lyricLinesEnsurePrimaryVersion($db, $songId);

    /* PRE-edit lines for this version — what is currently mirrored, in order.
       Pull every projected column so the dirty-check can skip no-op UPDATEs. */
    $exStmt = $db->prepare(
        "SELECT Id, ComponentId, PartType, PartTypeSlug, PartNumber, SortOrder,
                LineText, ChordsJson, Note, LanguageCode, IsInstrumental
           FROM tblLyricLines
          WHERE LyricsId = ?
          ORDER BY SortOrder, Id"
    );
    $exStmt->bind_param('i', $lyricsId);
    $exStmt->execute();
    $existing = $exStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $exStmt->close();

    /* Align pre→post by content, preserving Ids (and thus dependent FKs). */
    $plan = lyricLinesDiff($existing, $desired);

    /* #2073 commit 5 cross-review (F5) — DELIBERATELY NO SAME-SLOT CARRY HERE.
       An earlier version of this function paired an about-to-be-DELETED line
       with "the unmatched insertion at the same component-local ordinal"
       (`lyricLinesSameSlotCarryPairs()`, since REMOVED — do not re-add it,
       fuzzy-similarity or otherwise) and replayed that deleted line's voice
       marks / sub-line spans onto the pairing. That is a POSITIONAL GUESS
       wearing an identity-shaped coat: `[A, B] -> [X, B]` would carry A's
       voice marks onto X even when X is a completely unrelated new lyric
       that merely happens to land in A's old slot — same component, same
       ordinal, proves nothing about X actually BEING a rewrite of A. It
       compounds for sub-line spans, where replaying code-point offsets onto
       DIFFERENT words stays in-bounds, so a read-time clamp can never catch
       the mis-attribution either.
       This is the SAME bug class #2072 (and its still-open sibling #2087)
       already found and removed one field over: a hand-carry of
       Note/ChordsJson keyed by `(type, number, lineCount)` or by array
       position attached the WRONG line's data to a surviving line the
       instant a line was added, removed or reordered. Wrong enrichment is
       WORSE than missing enrichment — the save still succeeds and the data
       still looks plausible, so nobody ever notices until much later, if
       ever.
       The correct behaviour: when the content-matching diff above
       (`lyricLinesDiff()`) no longer recognises a line as the same line,
       that line is GONE — its voice marks and spans go with it. Nothing is
       lost forever: `lyricLinesSnapshotDeletedEnrichment()` below logs them
       to `tblActivityLog` BEFORE the DELETE runs, so a curator can recover
       them BY HAND, with a human confirming the pairing a computer cannot.
       Never replace this comment with a cleverer heuristic. */

    /* PF2 / R3 — before the deletes cascade away any per-line enrichment, snapshot it
       to tblActivityLog so a heavy edit (a rewrite scored below the pass-3 fuzzy floor,
       counted as delete+insert) or a genuine line removal never SILENTLY destroys a
       curated translation / annotation. No-op while enrichment is dormant; best-effort
       (never throws — a save must not fail because we couldn't snapshot). The fuller
       enrichment-aware match + re-attach UX is tracked as a P3 follow-up (#1235).
       #2073 commit 5 — EXTENDED to also snapshot the vocal-parts family (voice/echo
       line marks, sub-line spans, word timing, presentation slide overrides, and any
       round — or round VOICE — pointing at a deleted line) for RECOVERY ONLY: the F5
       fix above means nothing replays this snapshot automatically any more, so its
       one remaining job is (a) leaving a complete, restorable trail in tblActivityLog
       and (b) handing back which rounds need the 'needs-review' discoverability flip. */
    $vocalSnapshot = ['roundsToFlag' => []];
    if (!empty($plan['deleteIds'])) {
        $textById = [];
        foreach ($existing as $e) { $textById[(int)$e['Id']] = (string)$e['LineText']; }
        $vocalSnapshot = lyricLinesSnapshotDeletedEnrichment($db, $songId, array_map('intval', $plan['deleteIds']), $textById);
    }

    /* DELETE removed lines first; CASCADE drops their orphaned enrichment
       (translations / annotations / vocal-part rows / sub-line spans / word
       timing / presentation slide overrides — all already snapshotted above,
       #2073 commit 5 cross-review F4 added the last two) AND cascades a
       round whose OWN StartLineId is being deleted (also already
       snapshotted, and deliberately excluded from
       $vocalSnapshot['roundsToFlag'] — see that function's own note). */
    if (!empty($plan['deleteIds'])) {
        $del = $db->prepare("DELETE FROM tblLyricLines WHERE Id = ?");
        foreach ($plan['deleteIds'] as $delId) {
            $del->bind_param('i', $delId);
            $del->execute();
        }
        $del->close();

        /* #2073 commit 5 — the F4 discoverability flip: a round that just
           SURVIVED the delete above (its StartLineId untouched) but had its
           End/CodaStart/CodaEnd line SET NULL by that same delete — or one of
           its OWN VOICES' partner-song Start/EndLineId SET NULL the same way
           — now means something quietly different than it did a moment ago.
           Runs AFTER the delete so the NULLing has actually happened,
           matching the order a reviewer would expect ("the delete changed
           this round; here is where that gets flagged") even though the
           flag's OWN logic only needed the BEFORE-delete snapshot to decide
           which round ids qualify. Best-effort — its own try/catch. */
        lyricLinesFlagRoundsAfterLineDelete($db, $vocalSnapshot['roundsToFlag']);
    }

    /* INSERT new lines + UPDATE matched ones. Prepared once, reused per row. */
    $ins = $db->prepare(
        "INSERT INTO tblLyricLines
            (LyricsId, ComponentId, PartType, PartTypeSlug, PartNumber, SortOrder,
             LineText, ChordsJson, Note, LanguageCode, IsInstrumental)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $upd = $db->prepare(
        "UPDATE tblLyricLines
            SET ComponentId = ?, PartType = ?, PartTypeSlug = ?, PartNumber = ?, SortOrder = ?,
                LineText = ?, ChordsJson = ?, Note = ?, LanguageCode = ?, IsInstrumental = ?
          WHERE Id = ?"
    );

    /* Existing Id → its current row, for the dirty-check. */
    $existingById = [];
    foreach ($existing as $e) {
        $existingById[(int)$e['Id']] = $e;
    }

    $count = 0;
    foreach ($desired as $di => $d) {
        $matchId = $plan['matchedIds'][$di];   // existing Id to reuse, or null
        if ($matchId !== null) {
            /* #2072 — merge the currently-stored Note/ChordsJson back onto $d
               wherever the caller's payload stayed silent about them, BEFORE
               either lyricLinesRowClean() (the dirty-check, right below) or
               the UPDATE bind sees this row. An INSERT (matchId === null) has
               no existing row to reclaim from — a brand-new line has nothing
               to preserve, so it is left untouched. */
            $d = lyricLinesMergePreserved($d, $existingById[$matchId] ?? null);
        }
        if ($matchId === null) {
            /* #2073 commit 5 cross-review (F5) — a brand-new line (nothing
               matched it in the diff above) starts with NO voice marks and
               NO sub-line spans, full stop. See the "DELIBERATELY NO
               SAME-SLOT CARRY" comment above $plan's own computation for why
               that is the correct behaviour, not a gap to fill in. */
            $ins->bind_param(
                'iissiissssi',
                $lyricsId, $d['ComponentId'], $d['PartType'], $d['PartTypeSlug'], $d['PartNumber'], $d['SortOrder'],
                $d['LineText'], $d['ChordsJson'], $d['Note'], $d['LanguageCode'], $d['IsInstrumental']
            );
            $ins->execute();
        } elseif (!lyricLinesRowClean($existingById[$matchId] ?? null, $d)) {
            /* Only write when something about the line actually changed. */
            $upd->bind_param(
                'issiissssii',
                $d['ComponentId'], $d['PartType'], $d['PartTypeSlug'], $d['PartNumber'], $d['SortOrder'],
                $d['LineText'], $d['ChordsJson'], $d['Note'], $d['LanguageCode'], $d['IsInstrumental'],
                $matchId
            );
            $upd->execute();
        }
        $count++;
    }
    $ins->close();
    $upd->close();

    return $count;
}

/**
 * Map a free-text component `Type` ('verse', 'Chorus', …) to its controlled-vocab
 * `tblSongPartTypes.Slug` (#1138), or null when the type isn't a known slug (never
 * invent one — rule #20). The slug set is loaded ONCE per request (memoised). Used by
 * the projector to write `tblLyricLines.PartTypeSlug` on every line (#1235 P4 / C2),
 * paired with the migrate-lyric-lines-parttypeslug.php backfill.
 */
function lyricLinesPartTypeSlug(\mysqli $db, ?string $partType): ?string
{
    static $slugs = null;
    if ($slugs === null) {
        $slugs = [];
        try {
            $r = $db->query("SELECT Slug FROM tblSongPartTypes");
            if ($r) {
                while ($row = $r->fetch_row()) { $slugs[(string)$row[0]] = (string)$row[0]; }
                $r->close();
            }
        } catch (\Throwable $_e) {
            /* #1688 A1/§1a — a deadlock is not "best effort failed", it is the whole
               transaction already gone. Every catch in this file can run inside the
               caller's transaction (both save funnels call lyricLinesWriteComponents
               between begin_transaction() and commit()), so swallowing 1213/1205 here
               lets the caller commit nothing and still answer ok:true. The code list
               lives once, in song_relocate.php — a copied list is the "keep these in
               sync" comment rule #35 calls the failure rather than the fix.
               A MISSING table still returns false (1146 is not in the fatal set), so
               the fail-open behaviour on an un-migrated install is unchanged. */
            if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
            $slugs = [];   // un-migrated install: no vocab table → every slug stays null
        }
    }
    if ($partType === null || $partType === '') { return null; }
    $key = function_exists('mb_strtolower') ? mb_strtolower($partType) : strtolower($partType);
    return $slugs[$key] ?? null;
}

/**
 * Build the ordered DESIRED line list for a song from its now-authoritative
 * `tblSongComponents` (same per-line shape the projector writes). Pure read — no
 * writes. Each line is an assoc carrying exactly the columns lyricLinesProjectSong()
 * binds, so the build logic lives in one place.
 *
 * @param \mysqli $db
 * @param string  $songId
 * @return list<array{ComponentId:int,PartType:string,PartTypeSlug:?string,PartNumber:?int,SortOrder:int,LineText:string,ChordsJson:?string,Note:?string,LanguageCode:?string,IsInstrumental:int}>
 */
function lyricLinesBuildDesired(\mysqli $db, string $songId): array
{
    /* Per-line language (LanguagesJson, #1235 P3) is optional on un-migrated
       installs; select it only when present (the column name is a hardcoded
       constant, never input — rule #5). */
    $langCol = lyricLinesComponentsLangReady($db) ? 'LanguagesJson' : 'NULL AS LanguagesJson';
    $cs = $db->prepare(
        "SELECT Id, Type, Number, Language, LinesJson, ChordsJson, NotesJson, {$langCol}
           FROM tblSongComponents
          WHERE SongId = ?
          ORDER BY SortOrder, Id"
    );
    $cs->bind_param('s', $songId);
    $cs->execute();
    $comps = $cs->get_result()->fetch_all(MYSQLI_ASSOC);
    $cs->close();

    $desired = [];
    $sort    = 0;   // global line order within the version
    foreach ($comps as $c) {
        $compId     = (int)$c['Id'];
        $partType   = (string)$c['Type'];
        $partSlug   = lyricLinesPartTypeSlug($db, $partType);   // #1235 P4/C2 slug-at-write
        $number     = (int)$c['Number'];
        $partNumber = $number > 0 ? $number : null;          // 0 (e.g. a lone Chorus) => NULL
        $compLang   = ($c['Language'] !== null && $c['Language'] !== '') ? (string)$c['Language'] : null;

        $lines  = json_decode((string)$c['LinesJson'], true);
        if (!is_array($lines)) { $lines = []; }
        $chords = ($c['ChordsJson'] !== null) ? json_decode((string)$c['ChordsJson'], true) : null;
        $notes  = ($c['NotesJson']  !== null) ? json_decode((string)$c['NotesJson'],  true) : null;
        $langs  = ($c['LanguagesJson'] !== null) ? json_decode((string)$c['LanguagesJson'], true) : null;

        foreach ($lines as $i => $line) {
            $text   = (string)$line;
            $isInst = (trim($text) === '') ? 1 : 0;
            /* Per-line chord = the parallel array's element (null / string /
               array of strings) re-encoded as JSON; null when absent. */
            $chordVal = (is_array($chords) && array_key_exists($i, $chords) && $chords[$i] !== null)
                ? json_encode($chords[$i], JSON_UNESCAPED_UNICODE)
                : null;
            $noteVal  = (is_array($notes) && array_key_exists($i, $notes) && $notes[$i] !== null && $notes[$i] !== '')
                ? (string)$notes[$i]
                : null;
            /* Per-line language override; a null/absent/empty entry inherits the
               component Language (#858), which inherits tblSongs.Language. */
            $lineLang = (is_array($langs) && array_key_exists($i, $langs) && $langs[$i] !== null && $langs[$i] !== '')
                ? (string)$langs[$i]
                : $compLang;

            $desired[] = [
                'ComponentId'    => $compId,
                'PartType'       => $partType,
                'PartTypeSlug'   => $partSlug,
                'PartNumber'     => $partNumber,
                'SortOrder'      => $sort,
                'LineText'       => $text,
                'ChordsJson'     => $chordVal,
                'Note'           => $noteVal,
                'LanguageCode'   => $lineLang,
                'IsInstrumental' => $isInst,
            ];
            $sort++;
        }
    }
    return $desired;
}

/* ====================================================================
 * #1235 P4 / C5 — WRITE INVERSION (lines authoritative; JSON is a shadow)
 *
 * The cutover write path. Where the legacy projector RE-READS the
 * tblSongComponents JSON columns to derive lines (lyricLinesProjectSong →
 * lyricLinesBuildDesired), the inverted path builds desired lines from the
 * in-memory edit PAYLOAD and makes tblLyricLines the source of truth. The JSON
 * columns are shadow-written FROM the same payload (so the two stores stay byte-
 * consistent for the soak's G2 parity gate and full revertability) but ONLY while
 * they exist — every reference is column-existence-gated, so one deployed build
 * works BEFORE and AFTER the C6 drop (R2). LinesJson is NOT NULL, so it must keep
 * being written until the drop.
 * ==================================================================== */

/**
 * Which of the doomed tblSongComponents JSON payload columns still exist? Probed
 * once per request (memoised). Drives the shadow-write so a deployed build keeps
 * working across the C6 DROP COLUMN (a write naming a dropped column throws under
 * MYSQLI_REPORT_STRICT). Column names are hardcoded constants (rule #5).
 *
 * @return array{LinesJson:bool,ChordsJson:bool,NotesJson:bool,LanguagesJson:bool}
 */
function lyricLinesShadowColumnsPresent(\mysqli $db): array
{
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }
    $cols = ['LinesJson' => false, 'ChordsJson' => false, 'NotesJson' => false, 'LanguagesJson' => false];
    try {
        $r = $db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblSongComponents'
                AND COLUMN_NAME IN ('LinesJson','ChordsJson','NotesJson','LanguagesJson')"
        );
        if ($r) {
            while ($row = $r->fetch_row()) { $cols[(string)$row[0]] = true; }
            $r->close();
        }
    } catch (\Throwable $_e) {
        /* #1688 A1/§1a — a deadlock is not "best effort failed", it is the whole
           transaction already gone. Every catch in this file can run inside the
           caller's transaction (both save funnels call lyricLinesWriteComponents
           between begin_transaction() and commit()), so swallowing 1213/1205 here
           lets the caller commit nothing and still answer ok:true. The code list
           lives once, in song_relocate.php — a copied list is the "keep these in
           sync" comment rule #35 calls the failure rather than the fix.
           A MISSING table still returns false (1146 is not in the fatal set), so
           the fail-open behaviour on an un-migrated install is unchanged. */
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
        /* leave all false — caller writes a thin row only */
    }
    return $cols;
}

/**
 * The #1235 P4/C5 cutover write path: persist a song's components from the
 * in-memory edit PAYLOAD, making `tblLyricLines` the source of truth.
 *
 * Steps: (1) Id-stably upsert the THIN tblSongComponents rows (match by position /
 * SortOrder — never blanket DELETE+reinsert, which mints fresh Ids and churns every
 * line's ComponentId), shadow-writing the JSON columns that still exist; (2) build
 * the desired line list from the payload + the upserted ComponentIds (NEVER reading
 * LinesJson); (3) apply it to tblLyricLines via the shared Id-preserving diff
 * (lyricLinesApplyDesired) so per-line enrichment survives; (4) — #2072 finding 4 —
 * re-derive the ChordsJson/NotesJson SHADOW columns from the now-authoritative
 * tblLyricLines for any component whose payload omitted chords/notes
 * (lyricLinesResyncChordsNotesShadow()), because step (1)'s shadow write ran
 * BEFORE step (3)'s identity-based preserve knew the real per-line value — see
 * that function's doc-block for why a stale shadow is a live data-loss path via
 * the legacy re-projector, not merely a cosmetic mismatch. SortOrder is kept
 * contiguous 0..n-1 (so the #1066 ArrangementJson ordinal arrays stay valid).
 *
 * Each component entry: { type, number, language?, lines:string[], chords?:array,
 * notes?:array, languages?:array, label?:?string, sourceWorkId?:?int } — the same
 * shape save_song / the importers / the snapshot already hold. `label`
 * (#1860 Phase 5 REQ 3b) and `sourceWorkId` (REQ 2) are THIN-ROW metadata siblings
 * of `language` — rule #25: this write path never touches line content, only the
 * component's own columns — and are PRESENCE-gated (omit the key to leave the
 * stored value alone; send it, even `null`, to set/clear it explicitly). Call only
 * when lyricLinesSyncReady() is true.
 *
 * @param list<array<string,mixed>> $components  in display order
 * @return int  number of lines now stored
 */
function lyricLinesWriteComponents(\mysqli $db, string $songId, array $components): int
{
    /* The validated per-line LanguagesJson builder is shared with the funnels so
       the inverted path stores per-line language identically (line_enrichment.php
       is always loaded in the web/import contexts that call this; migrations use
       lyricLinesProjectSong, never this). */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'line_enrichment.php';

    /* Normalise each component ONCE — type/number/language coercion + the validated
       per-line language array — so the thin-row upsert (shadow) and the line build
       agree byte-for-byte. */
    $norm = [];
    foreach (array_values($components) as $c) {
        if (!is_array($c)) { continue; }
        $lines = is_array($c['lines'] ?? null) ? array_values(array_map('strval', $c['lines'])) : [];
        $type  = function_exists('mb_substr')
            ? (mb_substr(trim((string)($c['type'] ?? 'verse')), 0, 20) ?: 'verse')
            : (substr(trim((string)($c['type'] ?? 'verse')), 0, 20) ?: 'verse');
        $langsJson = lineEnrichmentBuildLanguagesJson($c['languages'] ?? null, count($lines));
        $norm[] = [
            'type'          => $type,
            'number'        => max(0, (int)($c['number'] ?? 0)),
            'language'      => (isset($c['language']) && trim((string)$c['language']) !== '') ? trim((string)$c['language']) : null,
            'lines'         => $lines,
            'chords'        => (isset($c['chords']) && is_array($c['chords'])) ? array_values($c['chords']) : null,
            'notes'         => (isset($c['notes'])  && is_array($c['notes']))  ? array_values($c['notes'])  : null,
            /* #2072 — ELI5: did the caller say ANYTHING about this component's
               per-line chords/notes, even "clear them"? A funnel that never
               mentions the key at all (array_key_exists false) means "I have
               nothing to say about this — leave whatever is already stored
               alone"; a funnel that sends the key, even as an explicit `null`,
               means "this IS the value now, even if that value is empty".
               DETAILED: this generalises rule #45's exact `labelProvided` /
               `sourceWorkIdProvided` idiom (a few lines below, for the
               component-level Label/SourceWorkId columns) down to the per-LINE
               Note/ChordsJson columns that lyricLinesApplyDesired() UPDATEs
               UNCONDITIONALLY otherwise (it writes every projected column on
               every matched line — there is no partial SET). Deliberately
               array_key_exists, NOT isset(): isset() treats an explicit `null`
               the same as "absent", which would make "send notes:null to clear
               the note" silently degrade back into "preserve" — defeating the
               whole point of the flag and reproducing the exact #2072 bug one
               level down. Spent by lyricLinesBuildDesiredFromComponents()'s
               `_preserve` flag below and, ultimately, by
               lyricLinesMergePreserved() inside lyricLinesApplyDesired(). */
            'notesProvided'  => array_key_exists('notes',  $c),
            'chordsProvided' => array_key_exists('chords', $c),
            'languagesJson' => $langsJson,                                                  // shadow string (or null)
            'validatedLangs'=> $langsJson !== null ? json_decode($langsJson, true) : null,  // null-padded validated array
            /* #1860 Phase 5 §3.1 — THIN-ROW metadata siblings of 'language' above
               (rule #25: never line content). 'label'/'sourceWorkId' are the
               NORMALISED value to write when provided; the *Provided flags record
               whether the caller's payload carried the key AT ALL
               (array_key_exists, not isset — an explicit `null` still counts as
               "provided", so a caller CAN deliberately clear a label/link, while an
               omitted key means "say nothing, preserve whatever is already
               stored"). lyricLinesUpsertComponents() below is the ONLY place that
               reads these two flags — this is the writer-level layer of §3's
               three-layer silent-wipe defence, the one that protects funnels which
               never learned about Label/SourceWorkId at all (a stale-cached v1
               editor whole-song save, a lyrics_ingest re-ingest over an existing
               song, an OLD pre-Label revision restore, SD6). */
            'label'                => (isset($c['label']) && trim((string)$c['label']) !== '')
                ? (function_exists('mb_substr') ? mb_substr(trim((string)$c['label']), 0, 100)
                                                : substr(trim((string)$c['label']), 0, 100))
                : null,
            'labelProvided'        => array_key_exists('label', $c),
            'sourceWorkId'         => (isset($c['sourceWorkId']) && (int)$c['sourceWorkId'] > 0) ? (int)$c['sourceWorkId'] : null,
            'sourceWorkIdProvided' => array_key_exists('sourceWorkId', $c),
            /* #2073 commit 5 — the IMPORTER VOICES TRANSPORT: a per-line
               parallel array of {kind,label?,bg?} cells (or `null`/`[]` to
               CLEAR every line in this component), mirroring the
               notes/chords *Provided idiom above EXACTLY — an importer only
               ever knows a line by its POSITION (no tblLyricLines.Id exists
               yet), so it hands this over positionally and
               vocalPartsApplyComponentVoices() (includes/vocal_parts.php,
               called below, AFTER a real Id exists for every line) is the
               ONE place position becomes an FK row. `voicesProvided` false
               (the key was never mentioned at all) means every line in this
               component is left UNTOUCHED — the same "say nothing, preserve
               whatever is already stored" contract as notesProvided/
               chordsProvided, extended one file over because unlike a
               scalar column, a line's voice rows can pre-exist independently
               of this write (see vocalPartsApplyComponentVoices()'s own
               doc-block for the full three-state cell semantics). */
            'voices'         => (isset($c['voices']) && is_array($c['voices'])) ? array_values($c['voices']) : null,
            'voicesProvided' => array_key_exists('voices', $c),
        ];
    }

    /* (1) Id-stable thin-component upsert + shadow JSON → position → ComponentId. */
    $cidMap = lyricLinesUpsertComponents($db, $songId, $norm);
    foreach ($norm as $i => &$c) { $c['cid'] = (int)($cidMap[$i] ?? 0); }
    unset($c);

    /* (2) Desired lines from the payload (never LinesJson). (3) Diff into tblLyricLines. */
    $desired = lyricLinesBuildDesiredFromComponents($norm, static fn(?string $t): ?string => lyricLinesPartTypeSlug($db, $t));
    $count   = lyricLinesApplyDesired($db, $songId, $desired);

    /* (4) #2072 finding 4 — MUST run AFTER (3): only once lyricLinesApplyDesired()
       has resolved the per-line identity-based preserve does tblLyricLines hold the
       TRUE final Note/ChordsJson for a component that omitted them in step (1)'s
       shadow write. Fixing up the shadow before this point would just encode
       another guess. */
    lyricLinesResyncChordsNotesShadow($db, $songId, $norm);

    /* (5) #2073 commit 5 — the importer voices transport: bind
       (component index, line index within it) -> tblLyricLines.Id, now that
       step (3) has definitely minted one for every line, and hand that
       position -> Id map to vocalPartsApplyComponentVoices() (the ONE seam
       that turns positional `voices` cells into FK rows). Skipped entirely
       — no extra SELECT, no extra require — unless at least one component
       actually mentioned `voices` at all, so an ORDINARY save (the
       overwhelming majority: no caller of this function sets `voices` yet)
       pays zero cost for a capability it never asked for (rule #A's
       "verified no-op" posture, applied to a feature rather than a whole
       migration). Own try/catch: a bug in voice application must never fail
       the section save it rides on (the SAME non-blocking posture rule #45
       already established for the work-medley lockstep one feature over). */
    $anyVoicesProvided = false;
    foreach ($norm as $c) {
        if (!empty($c['voicesProvided'])) { $anyVoicesProvided = true; break; }
    }
    if ($anyVoicesProvided) {
        try {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'vocal_parts.php';
            if (vocalPartsTablesReady($db)) {
                /* #2073 commit 5 cross-review finding F6 — the savepoint is set
                   as the FIRST statement inside this block, before anything
                   that could throw (including the read below), so the catch's
                   rollback-to-savepoint always has a real savepoint to unwind
                   to — see the fuller reasoning on the call itself, below. */
                $db->savepoint('ihymns_vp_apply');
                $lineIdStmt = $db->prepare('SELECT Id FROM tblLyricLines WHERE LyricsId = ? ORDER BY SortOrder, Id');
                $vLyricsId  = lyricLinesEnsurePrimaryVersion($db, $songId);
                $lineIdStmt->bind_param('i', $vLyricsId);
                $lineIdStmt->execute();
                $allLineIds = array_map(static fn($r) => (int)$r['Id'], $lineIdStmt->get_result()->fetch_all(MYSQLI_ASSOC));
                $lineIdStmt->close();

                $lineIdsByPos = [];
                $cursor = 0;
                foreach ($norm as $ci => $c) {
                    $n = count($c['lines']);
                    for ($li = 0; $li < $n; $li++) {
                        $lineIdsByPos[$ci][$li] = $allLineIds[$cursor] ?? 0;
                        $cursor++;
                    }
                }

                /* `_voiceSource` is an optional string key on the TOP-LEVEL
                   $components array (not per-component) — read straight off
                   the caller's original parameter, never off $norm (which
                   never carries it): array_values($components), used to
                   build $norm above, silently drops a string-keyed entry's
                   KEY, but the VALUE would still have landed in $norm as an
                   entry the `!is_array($c)` guard then skips — so this key
                   is invisible to $norm either way, by construction, exactly
                   as "Design pass 7" §4.2 specifies. */
                $voiceSource = (isset($components['_voiceSource']) && is_string($components['_voiceSource']) && $components['_voiceSource'] !== '')
                    ? $components['_voiceSource']
                    : 'ihymns';
                /* #2073 commit 5 cross-review finding F6 — SAVEPOINT-scoped
                   (set above, as this block's first statement).
                   vocalPartsApplyComponentVoices() performs several
                   independent writes across potentially many lines
                   (find-or-create a part, clear a line, assign a line); a
                   bug or a genuine DB error partway through must not leave
                   HALF of those writes committed while the catch below still
                   reports the whole song save as an unqualified success —
                   that is the exact "partial success reported as success"
                   shape #2073's own cross-review already found (and removed)
                   once today in the same-slot carry (F5). Releasing the
                   savepoint only once the call returns without throwing, and
                   rolling back TO it in the catch on any other outcome,
                   makes this ONE unit atomic: either every voices write this
                   call would have made lands, or none of them do — the
                   lyric-line/component write already committed to above is
                   untouched either way.
                   @see https://dev.mysql.com/doc/refman/8.0/en/savepoint.html */
                vocalPartsApplyComponentVoices($db, $songId, $norm, $lineIdsByPos, $voiceSource);
                $db->release_savepoint('ihymns_vp_apply');
            }
        } catch (\Throwable $_e) {
            if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
            /* Best-effort: the section save itself already succeeded above;
               a voice-application bug must not retroactively fail it. But an
               honest "best-effort" means undoing whatever this failed unit
               partially wrote, not leaving it in the database while claiming
               nothing happened — roll back to the savepoint set above so the
               voices step is genuinely all-or-nothing, exactly like every
               other best-effort step in this file already is by virtue of
               being a single atomic SQL statement. */
            try {
                /* ⚠️ NOT `$db->rollback(0, 'ihymns_vp_apply')`. In mysqli,
                   rollback()'s second argument names a TRANSACTION, not a
                   savepoint — that call would roll back the WHOLE transaction,
                   throwing away the song save this step was only a small part
                   of, while the caller still reported success. The only way to
                   unwind to a savepoint is the SQL statement itself. Found by
                   an independent review of this branch; the bug was in the very
                   change that introduced the savepoint to make this atomic. */
                $db->query('ROLLBACK TO SAVEPOINT ihymns_vp_apply');
            } catch (\Throwable $_e2) {
                if (songRelocateIsTransactionFatal($_e2)) { throw $_e2; }
                /* The rollback-to-savepoint itself failed (e.g. the
                   savepoint was never reached because vocalPartsTablesReady()
                   returned false, so it doesn't exist) — nothing further a
                   best-effort unit can do; the surrounding save still
                   commits, exactly as it did before this fix. */
            }
        }
    }

    return $count;
}

/**
 * #2072 finding 4 — collapse a null-padded per-line cell array (one entry per
 * line, `null` where that line has nothing to say) into the shadow-JSON
 * encoding this codebase uses for `ChordsJson`/`NotesJson`: `null` when EVERY
 * cell is null (nothing worth storing), else the whole array JSON-encoded.
 * **PURE** — no DB, no I/O.
 *
 * ELI5: turn a row of per-line boxes, most of them empty, into either "there's
 * genuinely nothing here" (null) or "here's the whole row, empty boxes and
 * all" (a JSON array) — the one rule both places that write this kind of
 * column need to agree on.
 *
 * DETAILED: extracted so the ORIGINAL shadow write in
 * `lyricLinesUpsertComponents()` (below, encoding the RAW/pre-merge payload)
 * and the POST-MERGE resync in `lyricLinesResyncChordsNotesShadow()` (which
 * re-derives the shadow from the now-authoritative `tblLyricLines` AFTER the
 * identity-based preserve has run) share the exact same "any non-null cell ⇒
 * encode the array, else null" rule — one encoding, never two divergent
 * copies of it (rule #35).
 *
 * @param list<mixed> $cells
 * @return string|null
 */
function lyricLinesShadowCellsToJson(array $cells): ?string
{
    foreach ($cells as $v) {
        if ($v !== null) {
            return json_encode($cells, JSON_UNESCAPED_UNICODE);
        }
    }
    return null;
}

/**
 * Id-stable upsert of a song's THIN tblSongComponents rows from the normalised
 * payload, shadow-writing whichever JSON columns still exist. Matches existing rows
 * to desired ones BY POSITION (SortOrder index): UPDATE matched rows in place
 * (Id-stable — the ComponentId every line carries does not churn), INSERT extras,
 * DELETE the surplus. Returns position → ComponentId.
 *
 * The INSERT/UPDATE column set is built once from lyricLinesShadowColumnsPresent()
 * so post-drop a thin row (Type/Number/SortOrder/Language only) is written and no
 * dropped column is named (R2). Helper for lyricLinesWriteComponents().
 *
 * #1860 Phase 5 §3.1 — `Label`/`SourceWorkId` are appended to the same INSERT/UPDATE,
 * GATED per-column via the ONE shared probe `lyricLinesComponentExtrasPresent()`
 * (rule #35 — no second INFORMATION_SCHEMA copy; rule #19 — a bare reference to
 * either would throw under MYSQLI_REPORT_STRICT on an install that hasn't run one
 * or both of their migrations). **PROVIDED-ELSE-PRESERVE** is the writer-level layer
 * of §3's three-layer silent-wipe defence: for an UPDATE, a component whose payload
 * did NOT carry the key (`labelProvided`/`sourceWorkIdProvided` false) reclaims the
 * value already sitting in that row (`$existingExtras[$i]`, fetched alongside
 * `$existingIds` below) instead of the write silently NULLing it; a component that
 * DID carry the key (even as an explicit `null`, meaning "clear it") stays
 * authoritative. This is what protects a caller that never learned about
 * Label/SourceWorkId at all — a stale-cached v1 editor whole-song save, a
 * `lyrics_ingest` re-ingest over an existing song, an OLD pre-Label revision
 * restore (SD6) — from wiping a curator's set label/work-link it doesn't know
 * exists. For a brand-new row (INSERT) there is nothing to preserve, so an omitted
 * key simply writes NULL. Still component METADATA only (rule #25) — this function
 * never reads or writes a line's text, chords or notes.
 *
 * @param list<array<string,mixed>> $norm  normalised components (lyricLinesWriteComponents)
 * @return array<int,int>  position → ComponentId
 */
function lyricLinesUpsertComponents(\mysqli $db, string $songId, array $norm): array
{
    /* #1860 Phase 5 §3.1 step 1 — the shared probe lives in lyric_lines_read.php;
       reached via a same-directory require (mirrors the line_enrichment.php require
       a few lines up in lyricLinesWriteComponents()) so this file never forks a
       second INFORMATION_SCHEMA query for the same two columns (rule #35). Safe to
       call inside the caller's transaction — the probe's own catch posture already
       re-throws a genuine deadlock via songRelocateIsTransactionFatal(). */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
    $extras = lyricLinesComponentExtrasPresent($db);

    $cols = lyricLinesShadowColumnsPresent($db);

    /* Shadow column list, in a fixed order, for both INSERT and UPDATE. */
    $shadowCols = [];
    foreach (['LinesJson', 'ChordsJson', 'NotesJson', 'LanguagesJson'] as $sc) {
        if ($cols[$sc]) { $shadowCols[] = $sc; }
    }

    /* Per-component shadow VALUES in $shadowCols order (all bound as 's'/null).
       ChordsJson / NotesJson are CLAMPED to exactly count(lines): the per-line write
       (lyricLinesBuildDesiredFromComponents) only stores a cell for each existing LINE, so a
       chord/note cell at an index >= line-count would otherwise live in the shadow but be
       dropped by the assembled read — breaking the G2 byte-parity gate and silently losing
       the trailing cell on the public read (the C5 review's over-length-chords finding). */
    $shadowVals = static function (array $c) use ($shadowCols): array {
        $lineCount = count($c['lines']);
        $out = [];
        foreach ($shadowCols as $sc) {
            if ($sc === 'LinesJson') {
                $out[] = json_encode($c['lines'], JSON_UNESCAPED_UNICODE);
            } elseif ($sc === 'ChordsJson') {
                /* #2072 finding 4 — NOTE: these are the RAW/pre-merge per-line values
                   ($c['chords'] on $norm, straight from the caller's payload, possibly
                   null/omitted). When the caller OMITTED chords for this component
                   (chordsProvided false), THIS write is only a placeholder — the
                   correct, identity-based MERGED value isn't known until step (3)
                   below (lyricLinesApplyDesired()'s per-line diff has run), so
                   lyricLinesResyncChordsNotesShadow() re-derives and overwrites this
                   column from the authoritative tblLyricLines right after. See that
                   function's doc-block for the full reasoning. */
                $cells = [];
                for ($k = 0; $k < $lineCount; $k++) {
                    $cells[] = (is_array($c['chords']) && array_key_exists($k, $c['chords'])) ? $c['chords'][$k] : null;
                }
                $out[] = lyricLinesShadowCellsToJson($cells);
            } elseif ($sc === 'NotesJson') {
                /* #2072 finding 4 — same placeholder caveat as ChordsJson immediately
                   above: resynced from tblLyricLines by
                   lyricLinesResyncChordsNotesShadow() when notesProvided is false. */
                $cells = [];
                for ($k = 0; $k < $lineCount; $k++) {
                    $v = (is_array($c['notes']) && array_key_exists($k, $c['notes']) && $c['notes'][$k] !== null && $c['notes'][$k] !== '')
                        ? $c['notes'][$k] : null;
                    $cells[] = $v;
                }
                $out[] = lyricLinesShadowCellsToJson($cells);
            } else { /* LanguagesJson — already clamped to line-count by lineEnrichmentBuildLanguagesJson */
                $out[] = $c['languagesJson'];
            }
        }
        return $out;
    };

    /* Existing thin rows in position order (the match anchor). #1860 Phase 5 §3.1
       step 2 — Label/SourceWorkId are appended to the SELECT ONLY when each column
       exists (gated, rule #19); $existingExtras carries the CURRENT stored value at
       each position so the provided-else-preserve logic below can reclaim it. */
    $extraSelCols = ($extras['Label'] ? ', Label' : '') . ($extras['SourceWorkId'] ? ', SourceWorkId' : '');
    $exStmt = $db->prepare("SELECT Id{$extraSelCols} FROM tblSongComponents WHERE SongId = ? ORDER BY SortOrder, Id");
    $exStmt->bind_param('s', $songId);
    $exStmt->execute();
    $existingRows = $exStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $exStmt->close();
    $existingIds    = array_map(static fn($r) => (int)$r['Id'], $existingRows);
    $existingExtras = array_map(static function (array $r) use ($extras): array {
        return [
            'Label'        => $extras['Label']        ? ($r['Label']        ?? null) : null,
            'SourceWorkId' => $extras['SourceWorkId'] ? ($r['SourceWorkId'] ?? null) : null,
        ];
    }, $existingRows);

    /* #1860 Phase 5 §3.1 step 3 — extra column set, gated + in a fixed order,
       appended AFTER the shadow columns on both statements. */
    $extraCols  = [];
    $extraTypes = '';
    if ($extras['Label'])        { $extraCols[] = 'Label';        $extraTypes .= 's'; }
    if ($extras['SourceWorkId']) { $extraCols[] = 'SourceWorkId'; $extraTypes .= 'i'; }

    /* Prepare INSERT + UPDATE once (column set is fixed for this call). */
    $insCols  = array_merge(['SongId', 'Type', 'Number', 'SortOrder', 'Language'], $shadowCols, $extraCols);
    $insPlace = implode(',', array_fill(0, count($insCols), '?'));
    $insTypes = 'ssiis' . str_repeat('s', count($shadowCols)) . $extraTypes;
    $ins = $db->prepare('INSERT INTO tblSongComponents (' . implode(',', $insCols) . ") VALUES ({$insPlace})");

    $updSet   = 'Type = ?, Number = ?, SortOrder = ?, Language = ?'
              . implode('', array_map(static fn($c) => ", {$c} = ?", $shadowCols))
              . implode('', array_map(static fn($c) => ", {$c} = ?", $extraCols));
    $updTypes = 'siis' . str_repeat('s', count($shadowCols)) . $extraTypes . 'i';
    $upd = $db->prepare("UPDATE tblSongComponents SET {$updSet} WHERE Id = ?");

    $cidMap = [];
    foreach ($norm as $i => $c) {
        $shadow = $shadowVals($c);
        if ($i < count($existingIds)) {
            $compId = $existingIds[$i];
            /* #1860 Phase 5 §3.1 step 4 — UPDATE: provided-else-preserve. A
               component whose payload carried the key (even as an explicit null)
               stays authoritative; an omitted key reclaims this exact row's
               CURRENTLY stored value (fetched above, BEFORE this write) rather
               than letting the write silently NULL it. */
            $extraVals = [];
            if ($extras['Label']) {
                $extraVals[] = !empty($c['labelProvided']) ? $c['label'] : ($existingExtras[$i]['Label'] ?? null);
            }
            if ($extras['SourceWorkId']) {
                $swPreserve  = !empty($c['sourceWorkIdProvided']) ? $c['sourceWorkId'] : ($existingExtras[$i]['SourceWorkId'] ?? null);
                $extraVals[] = ($swPreserve !== null) ? (int)$swPreserve : null;
            }
            $vals   = array_merge([$c['type'], $c['number'], $i, $c['language']], $shadow, $extraVals, [$compId]);
            $upd->bind_param($updTypes, ...$vals);
            $upd->execute();
            $cidMap[$i] = $compId;
        } else {
            /* INSERT — a brand-new row has nothing to preserve: provided writes the
               value, an omitted key writes NULL (which is also what $c['label'] /
               $c['sourceWorkId'] already normalise to when absent, per the
               normaliser above — both stay explicit here for symmetry with the
               UPDATE branch). */
            $extraVals = [];
            if ($extras['Label'])        { $extraVals[] = !empty($c['labelProvided'])        ? $c['label']        : null; }
            if ($extras['SourceWorkId']) { $extraVals[] = !empty($c['sourceWorkIdProvided']) ? $c['sourceWorkId'] : null; }
            $vals = array_merge([$songId, $c['type'], $c['number'], $i, $c['language']], $shadow, $extraVals);
            $ins->bind_param($insTypes, ...$vals);
            $ins->execute();
            $cidMap[$i] = (int)$db->insert_id;
        }
    }
    $ins->close();
    $upd->close();

    /* DELETE the surplus existing rows (desired set shrank). */
    if (count($existingIds) > count($norm)) {
        $del = $db->prepare("DELETE FROM tblSongComponents WHERE Id = ?");
        for ($j = count($norm); $j < count($existingIds); $j++) {
            $del->bind_param('i', $existingIds[$j]);
            $del->execute();
        }
        $del->close();
    }

    return $cidMap;
}

/**
 * #2072 finding 4 — re-derive the `ChordsJson`/`NotesJson` SHADOW columns on
 * `tblSongComponents` from the now-authoritative `tblLyricLines`, for every
 * component whose payload OMITTED chords/notes (`chordsProvided`/
 * `notesProvided` false on its `$norm` entry). Call this AFTER
 * `lyricLinesApplyDesired()` has run — never before.
 *
 * ELI5: the shadow copy gets written a little too early, before we've figured
 * out which notes/chords actually survived onto which line. This function
 * comes back afterwards and corrects the shadow copy to match what really
 * ended up stored, for exactly the components where that could have gone wrong.
 *
 * DETAILED (why this exists — the bug): `lyricLinesWriteComponents()` writes
 * the `ChordsJson`/`NotesJson` shadow INSIDE `lyricLinesUpsertComponents()`
 * (step 1), using the RAW payload value for each component — which is `null`
 * whenever the caller omitted the field. Only AFTER that, in step (3)
 * (`lyricLinesApplyDesired()`), does the per-LINE identity-based preserve
 * (`lyricLinesMergePreserved()`, matched by content-diffed line Id) decide the
 * REAL final value for each line — reclaiming a surviving line's stored Note/
 * ChordsJson from the database when the payload said nothing about it. So for
 * an omitted field, the shadow written in step 1 is at best a guess and is
 * frequently wrong (`null`, even though the authoritative `tblLyricLines.Note`
 * the merge just preserved is very much NOT null).
 *
 * This is not cosmetic: the LEGACY re-projector `lyricLinesProjectSong()` (run
 * by the backfill migration's "safe to re-run" button, or any future caller)
 * reads `NotesJson`/`ChordsJson` as ITS source of truth
 * (`lyricLinesBuildDesired()`, above) — so a stale `null` shadow would get
 * re-projected back onto `tblLyricLines`, silently ERASING the very value the
 * per-line merge just went to the trouble of preserving. That is a strictly
 * WORSE outcome than the original #2072 bug: instead of losing a note on ONE
 * save, a later re-projection loses it PERMANENTLY, with no payload anywhere
 * that ever asked for that.
 *
 * Scope: only components where `chordsProvided`/`notesProvided` is false are
 * touched — a component that explicitly provided the value already has the
 * correct shadow from step 1 (it wrote the caller's own authoritative value),
 * so re-deriving it here would be redundant work, never a correctness fix.
 *
 * @param \mysqli                    $db
 * @param string                     $songId
 * @param list<array<string,mixed>> $norm     the SAME normalised components
 *                                             lyricLinesWriteComponents() built,
 *                                             each carrying 'cid' (from the
 *                                             upsert) + 'chordsProvided' +
 *                                             'notesProvided'
 * @return void
 */
function lyricLinesResyncChordsNotesShadow(\mysqli $db, string $songId, array $norm): void
{
    $cols = lyricLinesShadowColumnsPresent($db);
    if (!$cols['ChordsJson'] && !$cols['NotesJson']) {
        return;   // post-C6 drop (or never migrated) — nothing to resync.
    }

    /* Only resolve/query when there is genuinely a component to fix — the
       common case (every field explicitly provided) does zero extra work. */
    $lyricsId = null;
    $sel = null;

    foreach ($norm as $c) {
        $needChords = $cols['ChordsJson'] && empty($c['chordsProvided']);
        $needNotes  = $cols['NotesJson']  && empty($c['notesProvided']);
        if (!$needChords && !$needNotes) {
            continue;
        }
        $cid = (int)($c['cid'] ?? 0);
        if ($cid <= 0) {
            continue;   // no upserted row to correlate to (shouldn't happen; defensive)
        }
        if ($lyricsId === null) {
            $lyricsId = lyricLinesEnsurePrimaryVersion($db, $songId);
            $sel = $db->prepare(
                "SELECT ChordsJson, Note FROM tblLyricLines
                  WHERE LyricsId = ? AND ComponentId = ?
                  ORDER BY SortOrder, Id"
            );
        }

        $sel->bind_param('ii', $lyricsId, $cid);
        $sel->execute();
        $rows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);

        $setCols = [];
        $vals    = [];
        $types   = '';
        if ($needChords) {
            $cells = [];
            foreach ($rows as $r) {
                $cells[] = ($r['ChordsJson'] !== null) ? json_decode((string)$r['ChordsJson'], true) : null;
            }
            $setCols[] = 'ChordsJson = ?';
            $vals[]    = lyricLinesShadowCellsToJson($cells);
            $types    .= 's';
        }
        if ($needNotes) {
            $cells = [];
            foreach ($rows as $r) {
                $cells[] = ($r['Note'] !== null && $r['Note'] !== '') ? (string)$r['Note'] : null;
            }
            $setCols[] = 'NotesJson = ?';
            $vals[]    = lyricLinesShadowCellsToJson($cells);
            $types    .= 's';
        }
        $vals[]  = $cid;
        $types  .= 'i';
        $upd = $db->prepare('UPDATE tblSongComponents SET ' . implode(', ', $setCols) . ' WHERE Id = ?');
        $upd->bind_param($types, ...$vals);
        $upd->execute();
        $upd->close();
    }
    if ($sel !== null) {
        $sel->close();
    }
}

/**
 * Build the ordered DESIRED line list from the in-memory normalised components
 * (each carrying its upserted `cid`), NEVER reading tblSongComponents.LinesJson.
 * **PURE** — no DB, no I/O (the part-type→slug lookup is injected as $slugResolver
 * so it is unit-testable) — and byte-for-byte the same shape lyricLinesBuildDesired()
 * derives from LinesJson, so lyricLinesApplyDesired()'s diff/dirty-check behaves
 * identically on both the legacy and the cutover paths.
 *
 * @param list<array<string,mixed>> $norm           normalised components with 'cid'
 * @param callable(?string):?string  $slugResolver  PartType → tblSongPartTypes.Slug
 * @return list<array<string,mixed>>
 */
function lyricLinesBuildDesiredFromComponents(array $norm, callable $slugResolver): array
{
    $desired = [];
    $sort    = 0;
    foreach ($norm as $c) {
        $compId     = (int)($c['cid'] ?? 0);
        $partType   = (string)$c['type'];
        $partSlug   = $slugResolver($partType);
        $number     = (int)$c['number'];
        $partNumber = $number > 0 ? $number : null;
        $compLang   = $c['language'] !== null && $c['language'] !== '' ? (string)$c['language'] : null;
        /* array_values so the parallel arrays are sequential 0..n-1 and align with the line
           index even if a caller passed a sparse-keyed array (the shadow write re-keys the
           same way, so the two encodings stay byte-consistent — C5 review). */
        $chords     = is_array($c['chords'] ?? null) ? array_values($c['chords']) : null;
        $notes      = is_array($c['notes']  ?? null) ? array_values($c['notes'])  : null;
        $langs      = is_array($c['validatedLangs'] ?? null) ? array_values($c['validatedLangs']) : null;
        /* #2072 — the writer-level "did the caller mention this field at all"
           signal (see the long comment on lyricLinesWriteComponents()'s
           normalisation, above, for the full reasoning). Defaulted to `true`
           ("yes, provided") when a caller's normalised array happens not to
           carry the flag at all, so this PURE function degrades to TODAY's
           behaviour (never preserve) for anything other than the ONE real
           caller (lyricLinesWriteComponents(), which always sets both) —
           never a silent PHP "Undefined array key" warning. */
        $notesProvided  = $c['notesProvided']  ?? true;
        $chordsProvided = $c['chordsProvided'] ?? true;

        foreach ($c['lines'] as $i => $line) {
            $text     = (string)$line;
            $isInst   = (trim($text) === '') ? 1 : 0;
            $chordVal = (is_array($chords) && array_key_exists($i, $chords) && $chords[$i] !== null)
                ? json_encode($chords[$i], JSON_UNESCAPED_UNICODE)
                : null;
            $noteVal  = (is_array($notes) && array_key_exists($i, $notes) && $notes[$i] !== null && $notes[$i] !== '')
                ? (string)$notes[$i]
                : null;
            $lineLang = (is_array($langs) && array_key_exists($i, $langs) && $langs[$i] !== null && $langs[$i] !== '')
                ? (string)$langs[$i]
                : $compLang;

            $desired[] = [
                'ComponentId'    => $compId,
                'PartType'       => $partType,
                'PartTypeSlug'   => $partSlug,
                'PartNumber'     => $partNumber,
                'SortOrder'      => $sort,
                'LineText'       => $text,
                'ChordsJson'     => $chordVal,
                'Note'           => $noteVal,
                'LanguageCode'   => $lineLang,
                'IsInstrumental' => $isInst,
                /* #2072 — writer-level layer of the per-line preserve-on-omit
                   defence: `true` on a field means "the in-memory payload
                   never mentioned it, so lyricLinesMergePreserved() must
                   reclaim whatever is already stored" for a MATCHED (UPDATE)
                   line, BEFORE either the dirty-check or the UPDATE bind sees
                   this row. The legacy LinesJson-sourced lyricLinesBuildDesired()
                   (the backfill/re-projection path, which re-reads what is
                   ALREADY stored rather than an in-memory edit payload) emits
                   NO `_preserve` key at all — lyricLinesMergePreserved()
                   treats a missing key as "nothing to merge", so that path's
                   behaviour stays byte-identical to before this fix. */
                '_preserve' => ['Note' => !$notesProvided, 'ChordsJson' => !$chordsProvided],
            ];
            $sort++;
        }
    }
    return $desired;
}

/**
 * #2072 — general per-line "omitted means preserve, explicit null means clear"
 * merge, spent by lyricLinesApplyDesired() for every MATCHED (UPDATE) line,
 * BEFORE either the dirty-check (lyricLinesRowClean) or the UPDATE bind reads
 * the row. **PURE** — no DB, no I/O — so it is unit-tested directly
 * (tests/php/test-lyric-lines-diff.php).
 *
 * ELI5: if nobody said anything new about this line's note or chords, keep
 * whatever is already sitting in the database for it, instead of letting the
 * next save silently blank it out.
 *
 * DETAILED: `lyricLinesApplyDesired()`'s UPDATE writes EVERY projected column
 * on every matched line (rule #25 — the ONE write path does a full-row column
 * list, never a partial SET) — so a caller that built its desired row without
 * knowing a per-line field exists (an importer that has never heard of Notes,
 * a stale-cached editor, a re-ingest over an existing song) would otherwise
 * NULL that field on every save. That is exactly the bug #2072 reported for
 * `tblLyricLines.Note`: the OpenLyrics importer WRITES it, nothing reads it
 * back, and the next whole-song save destroys it because the save payload
 * never carried a `notes` key at all.
 *
 * `_preserve` is set by lyricLinesBuildDesiredFromComponents() ONLY (never by
 * the legacy LinesJson-sourced lyricLinesBuildDesired() backfill projector,
 * which re-reads what is already stored rather than an in-memory edit
 * payload) from the `notesProvided` / `chordsProvided` flags computed with
 * `array_key_exists` — so an explicit `null` ("clear this") is distinguishable
 * from an absent key ("I have nothing to say about this"), the same
 * distinction rule #45 already draws for `tblSongComponents.Label` /
 * `SourceWorkId`, generalised here to per-LINE columns.
 *
 * A desired row with NO `_preserve` key (the legacy path, or any future
 * caller that has not opted in) is returned COMPLETELY UNCHANGED — this is
 * what keeps the legacy backfill projector byte-identical to its pre-#2072
 * behaviour, and what keeps this helper safe to call unconditionally.
 *
 * @param array<string,mixed>      $desired      one lyricLinesBuildDesiredFromComponents() entry
 * @param array<string,mixed>|null $existingRow  the CURRENT tblLyricLines row for the SAME
 *                                                matched Id (from $existingById in
 *                                                lyricLinesApplyDesired()), or null when there
 *                                                is nothing to reclaim (an INSERT, or a matched
 *                                                Id this call somehow can't find the row for)
 * @return array<string,mixed>  $desired, with Note/ChordsJson swapped back to the
 *                               currently-stored value wherever `_preserve` says the
 *                               caller stayed silent about that field
 */
function lyricLinesMergePreserved(array $desired, ?array $existingRow): array
{
    if ($existingRow === null || empty($desired['_preserve'])) {
        return $desired;
    }
    if (!empty($desired['_preserve']['Note'])) {
        $desired['Note'] = $existingRow['Note'] ?? null;
    }
    if (!empty($desired['_preserve']['ChordsJson'])) {
        $desired['ChordsJson'] = $existingRow['ChordsJson'] ?? null;
    }
    return $desired;
}

/**
 * Id-preserving alignment of a version's PRE-edit lines to its POST-edit lines
 * (#1235 P2b). **PURE** — no DB, no I/O — so it is unit-tested directly
 * (tests/php/test-lyric-lines-diff.php).
 *
 * Returns, per desired line (by index), the existing line Id to REUSE (UPDATE in
 * place, preserving the PK and every FK'd enrichment) or null (INSERT a new line),
 * plus the existing Ids that no desired line claimed (DELETE).
 *
 * Matching is by CONTENT, never `ComponentId`, in three passes of decreasing
 * confidence so the strongest evidence wins and each existing line is claimed at
 * most once:
 *   1. same part (PartType+PartNumber) + identical trimmed text — the common case
 *      (unchanged line, or a line reordered WITHIN its verse). Consumed in order,
 *      so repeated identical lines (e.g. a refrain) map 1:1.
 *   2. identical trimmed text in ANY part — a line moved BETWEEN parts unchanged,
 *      so it keeps its translation/annotation across the move.
 *   3. same part + text SIMILAR above a 0.5 floor — a typo / minor edit, so the
 *      line keeps its Id (and enrichment) rather than counting as a fresh line.
 *      Mis-pairing risk is bounded by restricting pass 3 to the same part and the
 *      similarity floor; below it a changed line is a clean delete + insert.
 * Whatever is left over: unmatched desired → INSERT, unmatched existing → DELETE.
 *
 * @param list<array{Id:int|string,PartType:?string,PartNumber:int|string|null,LineText:string}> $existing  ordered by SortOrder
 * @param list<array{PartType:?string,PartNumber:?int,LineText:string}>                            $desired   global order
 * @return array{matchedIds: array<int,?int>, deleteIds: list<int>}
 *
 * @see https://en.wikipedia.org/wiki/Levenshtein_distance  (the pass-3 similarity)
 */
function lyricLinesDiff(array $existing, array $desired): array
{
    $matchedIds = array_fill(0, count($desired), null);
    $usedEx     = [];   // existing-index => true once consumed

    /* Index existing line indices by (part+text) and by (text), each an ORDERED
       FIFO queue so duplicate identical lines pair positionally. */
    $byPartText = [];
    $byText     = [];
    foreach ($existing as $ei => $e) {
        $t = trim((string)$e['LineText']);
        $byPartText[lyricLinesBucketKey($e) . "\x1f" . $t][] = $ei;
        $byText[$t][] = $ei;
    }

    /* Pop the first not-yet-used index from a queue (queues share indices across
       the two maps, so a pass-1 consume must be skipped by pass 2). */
    $popUnused = static function (array &$queue) use (&$usedEx): ?int {
        while (!empty($queue)) {
            $ei = array_shift($queue);
            if (empty($usedEx[$ei])) { return $ei; }
        }
        return null;
    };

    /* PASS 1 — same part + identical text. */
    foreach ($desired as $di => $d) {
        $key = lyricLinesBucketKey($d) . "\x1f" . trim((string)$d['LineText']);
        if (!empty($byPartText[$key])) {
            $ei = $popUnused($byPartText[$key]);
            if ($ei !== null) { $matchedIds[$di] = (int)$existing[$ei]['Id']; $usedEx[$ei] = true; }
        }
    }

    /* PASS 2 — identical text in ANY part (unchanged cross-part move). */
    foreach ($desired as $di => $d) {
        if ($matchedIds[$di] !== null) { continue; }
        $t = trim((string)$d['LineText']);
        if (!empty($byText[$t])) {
            $ei = $popUnused($byText[$t]);
            if ($ei !== null) { $matchedIds[$di] = (int)$existing[$ei]['Id']; $usedEx[$ei] = true; }
        }
    }

    /* PASS 3 — same part, fuzzy (typo / minor edit). Greedy best available. */
    foreach ($desired as $di => $d) {
        if ($matchedIds[$di] !== null) { continue; }
        $dt = trim((string)$d['LineText']);
        if ($dt === '') { continue; }                       // blank lines never fuzzy-match
        $dBucket   = lyricLinesBucketKey($d);
        $bestEi    = null;
        $bestScore = 0.0;
        foreach ($existing as $ei => $e) {
            if (!empty($usedEx[$ei])) { continue; }
            if (lyricLinesBucketKey($e) !== $dBucket) { continue; }
            $et = trim((string)$e['LineText']);
            if ($et === '') { continue; }
            $s = lyricLinesSimilarity($dt, $et);
            if ($s > $bestScore) { $bestScore = $s; $bestEi = $ei; }
        }
        if ($bestEi !== null && $bestScore >= 0.5) {
            $matchedIds[$di] = (int)$existing[$bestEi]['Id'];
            $usedEx[$bestEi] = true;
        }
    }

    /* Whatever existing lines were never claimed are deletions. */
    $deleteIds = [];
    foreach ($existing as $ei => $e) {
        if (empty($usedEx[$ei])) { $deleteIds[] = (int)$e['Id']; }
    }

    return ['matchedIds' => $matchedIds, 'deleteIds' => $deleteIds];
}

/**
 * Part-identity bucket key for line matching: "PartType\x1fPartNumber" (a NULL /
 * absent number collapses to empty) so a "verse 1" line never matches a "chorus"
 * line or a "verse 2" line. Accepts either a desired line or an existing DB row.
 */
function lyricLinesBucketKey(array $line): string
{
    $pt  = isset($line['PartType']) && $line['PartType'] !== null ? (string)$line['PartType'] : '';
    /* Only a POSITIVE number is a real part number — 0 / NULL / '' all collapse to
       empty, mirroring the projector's `Number > 0 ? Number : null` (a lone Chorus). */
    $num = isset($line['PartNumber']) ? (int)$line['PartNumber'] : 0;
    $pn  = $num > 0 ? (string)$num : '';
    return $pt . "\x1f" . $pn;
}

/**
 * Text similarity in [0,1] for fuzzy (pass-3) line matching, measured by edit
 * distance over CODE POINTS (not bytes) so an accented / CJK one-character typo
 * scores like any other — load-bearing for #1088's non-Latin per-line
 * translations, and consistent with rule #21 (operate on code points). PHP's
 * built-in levenshtein() is byte-based and undefined past 255 bytes, so it can't
 * be used directly; we split to code points and run a small DP. Pathologically
 * long lines (lyrics never are) fall back to similar_text's percentage.
 *
 * @see https://en.wikipedia.org/wiki/Levenshtein_distance
 * @see https://www.php.net/manual/en/function.similar-text.php
 */
function lyricLinesSimilarity(string $a, string $b): float
{
    if ($a === $b)              { return 1.0; }
    if ($a === '' || $b === '') { return 0.0; }

    /* preg_split //u yields one element per UTF-8 code point. */
    $ca = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY);
    $cb = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
    if ($ca === false || $cb === false) {           // invalid UTF-8 → byte fallback
        $pct = 0.0;
        similar_text($a, $b, $pct);
        return $pct / 100.0;
    }

    $la  = count($ca);
    $lb  = count($cb);
    $max = max($la, $lb);
    if ($max === 0) { return 1.0; }
    if ($la > 256 || $lb > 256) {                   // huge line → cheap approximation
        $pct = 0.0;
        similar_text($a, $b, $pct);
        return $pct / 100.0;
    }

    return 1.0 - (lyricLinesLevenshteinCp($ca, $cb) / $max);
}

/**
 * Code-point Levenshtein distance between two arrays of single-code-point strings
 * (rolling two-row DP — O(la·lb) time, O(lb) space). Helper for
 * lyricLinesSimilarity(); lyric lines are short so the DP is trivially cheap.
 *
 * @param list<string> $a
 * @param list<string> $b
 */
function lyricLinesLevenshteinCp(array $a, array $b): int
{
    $la = count($a);
    $lb = count($b);
    if ($la === 0) { return $lb; }
    if ($lb === 0) { return $la; }

    $prev = range(0, $lb);
    for ($i = 1; $i <= $la; $i++) {
        $cur = [$i];
        $ai  = $a[$i - 1];
        for ($j = 1; $j <= $lb; $j++) {
            $cost    = ($ai === $b[$j - 1]) ? 0 : 1;
            $cur[$j] = min(
                $prev[$j] + 1,          // deletion
                $cur[$j - 1] + 1,       // insertion
                $prev[$j - 1] + $cost   // substitution
            );
        }
        $prev = $cur;
    }
    return $prev[$lb];
}

/**
 * Dirty-check: does the existing DB row already equal the desired line in EVERY
 * projected column? Lets lyricLinesProjectSong() skip no-op UPDATEs so an edit
 * that didn't touch a line leaves its row (and `UpdatedAt`) alone.
 *
 * @param array<string,mixed>|null $existingRow  the current tblLyricLines row, or null
 * @param array<string,mixed>      $desired       a lyricLinesBuildDesired() entry
 */
function lyricLinesRowClean(?array $existingRow, array $desired): bool
{
    if ($existingRow === null) { return false; }
    if ((int)$existingRow['ComponentId'] !== (int)$desired['ComponentId'])       { return false; }
    if ((string)$existingRow['PartType'] !== (string)$desired['PartType'])       { return false; }
    $exSlug = (array_key_exists('PartTypeSlug', $existingRow) && $existingRow['PartTypeSlug'] !== null)
        ? (string)$existingRow['PartTypeSlug'] : null;
    if ($exSlug !== ($desired['PartTypeSlug'] ?? null))                          { return false; }
    $exNum = $existingRow['PartNumber'] === null ? null : (int)$existingRow['PartNumber'];
    if ($exNum !== $desired['PartNumber'])                                       { return false; }
    if ((int)$existingRow['SortOrder'] !== (int)$desired['SortOrder'])           { return false; }
    if ((string)$existingRow['LineText'] !== (string)$desired['LineText'])       { return false; }
    if ((int)$existingRow['IsInstrumental'] !== (int)$desired['IsInstrumental']) { return false; }
    $exNote = $existingRow['Note'] === null ? null : (string)$existingRow['Note'];
    if ($exNote !== $desired['Note'])                                            { return false; }
    $exLang = $existingRow['LanguageCode'] === null ? null : (string)$existingRow['LanguageCode'];
    if ($exLang !== $desired['LanguageCode'])                                    { return false; }
    if (!lyricLinesJsonEqual($existingRow['ChordsJson'], $desired['ChordsJson'])) { return false; }
    return true;
}

/**
 * Compare two JSON-column values for SEMANTIC equality. MySQL may re-format a
 * stored JSON string (whitespace / key order), so compare decoded values rather
 * than raw text — otherwise the chord dirty-check would never report "clean".
 */
function lyricLinesJsonEqual($a, $b): bool
{
    if ($a === null && $b === null) { return true; }
    if ($a === null || $b === null) { return false; }
    if ((string)$a === (string)$b)  { return true; }   // byte-identical fast path
    $da = json_decode((string)$a, true); $ea = json_last_error();
    $db = json_decode((string)$b, true); $eb = json_last_error();
    /* Both-malformed-decode-to-null can't happen for a JSON column, but a future
       caller might pass junk — treat any decode error as "not equal". */
    if ($ea !== JSON_ERROR_NONE || $eb !== JSON_ERROR_NONE) { return false; }
    return $da === $db;
}

/**
 * Are the per-line enrichment tables (#1088 — tblLyricLineTranslations /
 * tblLyricLineAnnotations) present? PF2 / R3 only needs to snapshot enrichment for a
 * to-be-deleted line when those tables exist; an un-migrated install has nothing to
 * lose. Memoised per request. (Local probe so the shared projector never has to
 * depend on includes/line_enrichment.php being loaded in its caller's context —
 * migrations call lyricLinesProjectSong() too.)
 */
function lyricLinesEnrichmentTablesPresent(\mysqli $db): bool
{
    static $present = null;
    if ($present !== null) {
        return $present;
    }
    try {
        $r = $db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('tblLyricLineTranslations', 'tblLyricLineAnnotations')"
        );
        $row     = $r ? $r->fetch_row() : null;
        $present = ($row !== null && (int)$row[0] >= 2);
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        /* #1688 A1/§1a — a deadlock is not "best effort failed", it is the whole
           transaction already gone. Every catch in this file can run inside the
           caller's transaction (both save funnels call lyricLinesWriteComponents
           between begin_transaction() and commit()), so swallowing 1213/1205 here
           lets the caller commit nothing and still answer ok:true. The code list
           lives once, in song_relocate.php — a copied list is the "keep these in
           sync" comment rule #35 calls the failure rather than the fix.
           A MISSING table still returns false (1146 is not in the fatal set), so
           the fail-open behaviour on an un-migrated install is unchanged. */
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
        $present = false;
    }
    return $present;
}

/**
 * #2073 commit 5 — is `tblLyricLineVocalParts`/`tblLyricLineVocalSpans`/
 * `tblLyricRounds`/`tblLyricRoundVoices`/`tblPresentationSlideOverrides`
 * present? Local, memoised probe (mirrors `lyricLinesEnrichmentTablesPresent()`
 * immediately above) so `lyricLinesSnapshotDeletedEnrichment()` never needs
 * `vocal_parts.php` / `lyric_rounds.php` loaded on every path that reaches
 * it — the booleans are looked up in ONE query, independently of
 * `vocalPartsTablesReady()` / `vocalPartsSpansReady()` / `lyricRoundsReady()`
 * (which memoise their OWN per-table facts for their own callers) rather
 * than requiring those two files just to ask a question this file can
 * answer itself with a single `INFORMATION_SCHEMA` round trip (rule #22 is
 * about not re-deriving VALUES, not about never asking the schema a plain
 * existence question twice).
 *
 * `tblLyricWords` is DELIBERATELY not probed here: it ships in the SAME
 * migration as `tblLyricLines` itself (`migrate-normalize-lyrics.php`), so
 * by the time this file's write path runs at all, it is already guaranteed
 * present — unlike `tblPresentationSlideOverrides`, which ships in the
 * SEPARATE `migrate-presentation-themes.php` and genuinely can be absent
 * (#2073 commit 5 cross-review finding F4).
 *
 * @return array{parts:bool,spans:bool,rounds:bool,slideOverrides:bool}
 */
function lyricLinesVocalTablesPresent(\mysqli $db): array
{
    static $present = null;
    if ($present !== null) {
        return $present;
    }
    $present = ['parts' => false, 'spans' => false, 'rounds' => false, 'slideOverrides' => false];
    try {
        $r = $db->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('tblLyricLineVocalParts', 'tblLyricLineVocalSpans',
                                    'tblLyricRounds', 'tblLyricRoundVoices',
                                    'tblPresentationSlideOverrides')"
        );
        $found = [];
        if ($r) {
            while ($row = $r->fetch_row()) { $found[(string)$row[0]] = true; }
            $r->close();
        }
        $present['parts']          = !empty($found['tblLyricLineVocalParts']);
        $present['spans']          = !empty($found['tblLyricLineVocalSpans']);
        $present['rounds']         = !empty($found['tblLyricRounds']) && !empty($found['tblLyricRoundVoices']);
        $present['slideOverrides'] = !empty($found['tblPresentationSlideOverrides']);
    } catch (\Throwable $_e) {
        /* #1688 A1/§1a — a deadlock is not "best effort failed", it is the whole
           transaction already gone. Every catch in this file can run inside the
           caller's transaction (both save funnels call lyricLinesWriteComponents
           between begin_transaction() and commit()), so swallowing 1213/1205 here
           lets the caller commit nothing and still answer ok:true. The code list
           lives once, in song_relocate.php — a copied list is the "keep these in
           sync" comment rule #35 calls the failure rather than the fix.
           A MISSING table still returns false (1146 is not in the fatal set), so
           the fail-open behaviour on an un-migrated install is unchanged. */
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
        $present = ['parts' => false, 'spans' => false, 'rounds' => false, 'slideOverrides' => false];
    }
    return $present;
}

/**
 * PF2 / R3 — best-effort snapshot of the per-line enrichment that lyricLinesProjectSong()'s
 * DELETE is about to cascade away, written to tblActivityLog so it is never SILENTLY
 * lost and can be recovered. A NO-OP while enrichment is dormant (the tables are empty,
 * so the IN() queries return nothing) — the whole-catalogue backfill therefore does no
 * enrichment work. NEVER throws: a logging failure must not break the save it guards.
 *
 * The diff already preserves Ids (and thus enrichment) for unchanged / moved / lightly-
 * edited lines; this only fires for a GENUINE deletion or a rewrite below the pass-3
 * fuzzy floor (counted as delete+insert). Until the fuller enrichment-aware match +
 * re-attach UX lands (P3 follow-up), this is the safety net.
 *
 * #2073 commit 5 — EXTENDED to also snapshot the vocal-parts family so a
 * deleted line's voice/echo marks, and any round (or round VOICE) that
 * pointed at it, "would cascade away with no trace" no longer applies:
 * `tblLyricLineVocalParts` and `tblLyricLineVocalSpans` rows for the
 * deleted lines are captured (gated on `lyricLinesVocalTablesPresent()`,
 * same never-throw posture as translations/annotations above), and every
 * `tblLyricRounds` row whose OWN Start/End/Coda line intersects the
 * deletion, OR whose Nth VOICE's own partner-song span does, is captured
 * too — `lyricRoundsToFlagFromRows()` (`lyric_rounds.php`, pure) is what
 * decides which of those captured rounds actually need the round-side "F4"
 * discoverability flip (`IntegrityStatus -> 'needs-review'`), applied by
 * the CALLER, `lyricLinesApplyDesired()`, after the DELETE has actually run
 * (see that function and `lyricLinesFlagRoundsAfterLineDelete()` below).
 *
 * #2073 commit 5 cross-review finding F4 — three fixes over the version
 * that shipped first:
 *   1. A round VOICE whose OWN `StartLineId`/`EndLineId` intersects the
 *      deletion is now discovered even when the PARENT round's own four
 *      line columns are completely untouched (the original SQL only ever
 *      searched `tblLyricRounds`' own columns, so this round was never even
 *      fetched). See `lyricRoundsToFlagFromRows()`'s own doc-block.
 *   2. The `tblLyricRounds` / `tblLyricRoundVoices` captures are now
 *      `SELECT *`, not a hand-picked column list — the earlier list (Id,
 *      StartLineId, EndLineId, CodaStartLineId, CodaEndLineId, EndingMode /
 *      Id, RoundId, VoiceNumber, StartLineId, EndLineId) omitted Kind,
 *      Label, TimesThrough, Bpm, BeatsPerBar, BeatsPerLine, VocalPartId,
 *      EntryBasis, EntryLines, EntryBeats, EntryMs, IntervalSemitones,
 *      SortOrder — a snapshot that cannot restore those is decoration, not
 *      recovery. `SELECT *` also means a FUTURE column never has to be
 *      remembered here by hand (mirrors `lyricRoundsForVersion()`'s own
 *      `SELECT *`, one file over).
 *   3. `tblLyricWords` (per-word timing, `ON DELETE CASCADE` from
 *      `tblLyricLines`) and `tblPresentationSlideOverrides` (per-line/word
 *      style patches, ALSO `ON DELETE CASCADE`) genuinely cascade from this
 *      same delete and were not captured at all — the earlier version of
 *      this doc-block claimed the DELETE-time comment in
 *      `lyricLinesApplyDesired()` covered "everything that cascades"; it did
 *      not. Both are captured now (the second gated on
 *      `lyricLinesVocalTablesPresent()['slideOverrides']`, since it ships
 *      in a SEPARATE migration and can genuinely be absent — `tblLyricWords`
 *      ships in the SAME migration as `tblLyricLines` itself and is never
 *      gated, matching every other unconditional `tblLyricLines`-adjacent
 *      reference in this file).
 *
 * @param list<int>          $deleteIds  line Ids about to be deleted
 * @param array<int,string>  $textById   pre-edit LineText keyed by line Id (snapshot context)
 * @return array{roundsToFlag:list<int>}
 */
function lyricLinesSnapshotDeletedEnrichment(\mysqli $db, string $songId, array $deleteIds, array $textById): array
{
    $empty = ['roundsToFlag' => []];
    if (empty($deleteIds)) {
        return $empty;
    }

    $roundsToFlag = [];

    try {
        /* Placeholder string built from a hardcoded count (rule #5) — never input. */
        $place = implode(',', array_fill(0, count($deleteIds), '?'));
        $types = str_repeat('i', count($deleteIds));

        $trans = [];
        $annos = [];
        if (lyricLinesEnrichmentTablesPresent($db)) {
            $trStmt = $db->prepare(
                "SELECT Id, LineId, TargetLanguage, Kind, Text
                   FROM tblLyricLineTranslations WHERE LineId IN ({$place})"
            );
            $trStmt->bind_param($types, ...$deleteIds);
            $trStmt->execute();
            $trans = $trStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $trStmt->close();

            $anStmt = $db->prepare(
                "SELECT Id, StartLineId, EndLineId, AnnotationType, Body
                   FROM tblLyricLineAnnotations WHERE StartLineId IN ({$place}) OR EndLineId IN ({$place})"
            );
            $anStmt->bind_param($types . $types, ...array_merge($deleteIds, $deleteIds));
            $anStmt->execute();
            $annos = $anStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $anStmt->close();
        }

        /* #2073 commit 5 cross-review F4 fix 3 — tblLyricWords ships in the
           SAME migration as tblLyricLines (migrate-normalize-lyrics.php), so
           it is unconditionally present wherever this write path runs at
           all; unlike tblPresentationSlideOverrides below, it needs no
           existence probe of its own. */
        $wStmt = $db->prepare(
            "SELECT Id, LineId, SortOrder, WordText, StartTimeMs, EndTimeMs, MetaJson
               FROM tblLyricWords WHERE LineId IN ({$place})"
        );
        $wStmt->bind_param($types, ...$deleteIds);
        $wStmt->execute();
        $lyricWords = $wStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $wStmt->close();
        $wordIds = array_map(static fn($w) => (int)$w['Id'], $lyricWords);

        $vocalTables = lyricLinesVocalTablesPresent($db);

        $vocalLineParts = [];
        if ($vocalTables['parts']) {
            $vpStmt = $db->prepare(
                "SELECT Id, LineId, VocalPartId, LyricsId, IsBackground, SortOrder
                   FROM tblLyricLineVocalParts WHERE LineId IN ({$place})"
            );
            $vpStmt->bind_param($types, ...$deleteIds);
            $vpStmt->execute();
            $vocalLineParts = $vpStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $vpStmt->close();
        }
        $vocalSpans = [];
        if ($vocalTables['spans']) {
            $vsStmt = $db->prepare(
                "SELECT Id, LineId, VocalPartId, LyricsId, StartOffset, EndOffset, IsBackground, SortOrder
                   FROM tblLyricLineVocalSpans WHERE LineId IN ({$place})"
            );
            $vsStmt->bind_param($types, ...$deleteIds);
            $vsStmt->execute();
            $vocalSpans = $vsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $vsStmt->close();
        }

        /* #2073 commit 5 cross-review F4 fix 3 (continued) — presentation slide
           overrides anchored either directly on a to-be-deleted LINE, or on a
           WORD of that line (both FKs are ON DELETE CASCADE). Gated on the
           table's OWN existence (a SEPARATE migration — genuinely can be
           absent, rule #19). */
        $slideOverrides = [];
        if ($vocalTables['slideOverrides']) {
            if ($wordIds) {
                $wPlace = implode(',', array_fill(0, count($wordIds), '?'));
                $soStmt = $db->prepare(
                    "SELECT * FROM tblPresentationSlideOverrides
                      WHERE LyricLineId IN ({$place}) OR LyricWordId IN ({$wPlace})"
                );
                $soStmt->bind_param($types . str_repeat('i', count($wordIds)), ...array_merge($deleteIds, $wordIds));
            } else {
                $soStmt = $db->prepare(
                    "SELECT * FROM tblPresentationSlideOverrides WHERE LyricLineId IN ({$place})"
                );
                $soStmt->bind_param($types, ...$deleteIds);
            }
            $soStmt->execute();
            $slideOverrides = $soStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $soStmt->close();
        }

        /* #2073 commit 5 cross-review F4 fix 1 — fetch the UNION of "rounds
           whose OWN line columns intersect the deletion" and "rounds whose
           VOICES' own partner-song span intersects the deletion", so a round
           that only loses through a voice is neither skipped here nor missed
           by the flag decision below. */
        $rounds       = [];
        $roundVoices  = [];
        if ($vocalTables['rounds']) {
            $rIdStmt = $db->prepare(
                "SELECT Id FROM tblLyricRounds
                  WHERE StartLineId IN ({$place}) OR EndLineId IN ({$place})
                     OR CodaStartLineId IN ({$place}) OR CodaEndLineId IN ({$place})"
            );
            $rIdStmt->bind_param(
                $types . $types . $types . $types,
                ...array_merge($deleteIds, $deleteIds, $deleteIds, $deleteIds)
            );
            $rIdStmt->execute();
            $roundIdsFromRoundFields = array_map(
                static fn($row) => (int)$row['Id'],
                $rIdStmt->get_result()->fetch_all(MYSQLI_ASSOC)
            );
            $rIdStmt->close();

            $rvIdStmt = $db->prepare(
                "SELECT RoundId FROM tblLyricRoundVoices WHERE StartLineId IN ({$place}) OR EndLineId IN ({$place})"
            );
            $rvIdStmt->bind_param($types . $types, ...array_merge($deleteIds, $deleteIds));
            $rvIdStmt->execute();
            $roundIdsFromVoiceFields = array_map(
                static fn($row) => (int)$row['RoundId'],
                $rvIdStmt->get_result()->fetch_all(MYSQLI_ASSOC)
            );
            $rvIdStmt->close();

            $roundIds = array_values(array_unique(array_merge($roundIdsFromRoundFields, $roundIdsFromVoiceFields)));

            if ($roundIds) {
                /* #2073 commit 5 cross-review F4 fix 2 — SELECT * (never a
                   hand-picked column list) so this snapshot actually carries
                   every restoration-critical field: Kind, Label,
                   TimesThrough, Bpm, BeatsPerBar, BeatsPerLine, … for rounds;
                   VocalPartId, EntryBasis, EntryLines, EntryBeats, EntryMs,
                   IntervalSemitones, SortOrder, … for voices. */
                $rPlace = implode(',', array_fill(0, count($roundIds), '?'));
                $rStmt  = $db->prepare("SELECT * FROM tblLyricRounds WHERE Id IN ({$rPlace})");
                $rStmt->bind_param(str_repeat('i', count($roundIds)), ...$roundIds);
                $rStmt->execute();
                $rounds = $rStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $rStmt->close();

                $rvPlace = implode(',', array_fill(0, count($roundIds), '?'));
                $rvStmt  = $db->prepare("SELECT * FROM tblLyricRoundVoices WHERE RoundId IN ({$rvPlace})");
                $rvStmt->bind_param(str_repeat('i', count($roundIds)), ...$roundIds);
                $rvStmt->execute();
                $roundVoices = $rvStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $rvStmt->close();

                require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_rounds.php';
                if (function_exists('lyricRoundsToFlagFromRows')) {
                    $roundsToFlag = lyricRoundsToFlagFromRows($rounds, $roundVoices, $deleteIds);
                }
            }
        }

        if (empty($trans) && empty($annos) && empty($lyricWords) && empty($vocalLineParts)
            && empty($vocalSpans) && empty($slideOverrides) && empty($rounds) && empty($roundVoices)
        ) {
            return ['roundsToFlag' => $roundsToFlag];   // the common path: deleted lines carried none of this
        }

        $snapshot = json_encode([
            'songId'         => $songId,
            'reason'         => 'Enrichment cascade-deleted by an Id-preserving reprojection (#1235 PF2/R3, extended #2073 commit 5 for voices/spans/words/slide-overrides/rounds); recoverable from this row.',
            'deletedLines'   => array_map(
                static fn(int $id): array => ['id' => $id, 'text' => $textById[$id] ?? null],
                $deleteIds
            ),
            'translations'    => $trans,
            'annotations'     => $annos,
            'lyricWords'      => $lyricWords,
            'vocalLineParts'  => $vocalLineParts,
            'vocalSpans'      => $vocalSpans,
            'slideOverrides'  => $slideOverrides,
            'rounds'          => $rounds,
            'roundVoices'     => $roundVoices,
        ], JSON_UNESCAPED_UNICODE);

        $userId = null;                          // system action — projector has no user context
        $action = 'lyrics.enrichment_orphaned';
        $etype  = 'song';
        $ip     = null;
        $logStmt = $db->prepare(
            "INSERT INTO tblActivityLog (UserId, Action, EntityType, EntityId, Details, IpAddress)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $logStmt->bind_param('isssss', $userId, $action, $etype, $songId, $snapshot, $ip);
        $logStmt->execute();
        $logStmt->close();
    } catch (\Throwable $_e) {
        /* #1688 A1/§1a — a deadlock is not "best effort failed", it is the whole
           transaction already gone. Every catch in this file can run inside the
           caller's transaction (both save funnels call lyricLinesWriteComponents
           between begin_transaction() and commit()), so swallowing 1213/1205 here
           lets the caller commit nothing and still answer ok:true. The code list
           lives once, in song_relocate.php — a copied list is the "keep these in
           sync" comment rule #35 calls the failure rather than the fix.
           A MISSING table still returns false (1146 is not in the fatal set), so
           the fail-open behaviour on an un-migrated install is unchanged. */
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
        /* Best-effort: never break a save because the snapshot failed — but still
           hand back whatever round-flag decision WAS reached before the failure. */
        return ['roundsToFlag' => $roundsToFlag];
    }

    return ['roundsToFlag' => $roundsToFlag];
}

/**
 * #2073 commit 5 — the "F4" discoverability flip the migration's own
 * `tblLyricRounds.IntegrityStatus` COMMENT describes: a round whose
 * `EndLineId`/`CodaStartLineId`/`CodaEndLineId` is about to go NULL
 * (`ON DELETE SET NULL`) because that line is being deleted SURVIVES the
 * delete looking outwardly healthy but meaning something quietly
 * different — this flips it to `'needs-review'` so a curator can find it,
 * instead of it sitting there silently wrong. Never touches a round whose
 * `StartLineId` is the line being deleted — that round is CASCADE-deleted
 * in full (nothing survives to flag; the snapshot already logged it).
 *
 * Best-effort, same never-throw-except-transaction-fatal posture as every
 * other delete-time helper in this file.
 *
 * @param list<int> $roundIds  `lyricLinesSnapshotDeletedEnrichment()`'s own `roundsToFlag`
 */
function lyricLinesFlagRoundsAfterLineDelete(\mysqli $db, array $roundIds): void
{
    if (empty($roundIds)) {
        return;
    }
    try {
        $ids = array_values(array_unique(array_map('intval', $roundIds)));
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE tblLyricRounds SET IntegrityStatus = 'needs-review' WHERE Id IN ({$place})");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $_e) {
        /* #1688 A1/§1a — a deadlock is not "best effort failed", it is the whole
           transaction already gone. Every catch in this file can run inside the
           caller's transaction (both save funnels call lyricLinesWriteComponents
           between begin_transaction() and commit()), so swallowing 1213/1205 here
           lets the caller commit nothing and still answer ok:true. The code list
           lives once, in song_relocate.php — a copied list is the "keep these in
           sync" comment rule #35 calls the failure rather than the fix.
           A MISSING table still returns false (1146 is not in the fatal set), so
           the fail-open behaviour on an un-migrated install is unchanged. */
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
        /* Best-effort: a missed flag means a curator finds the problem later by
           eye instead of via this discoverability aid — never a failed save. */
    }
}

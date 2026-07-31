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
 */
function lyricLinesEnsurePrimaryVersion(\mysqli $db, string $songId): int
{
    $sel = $db->prepare(
        "SELECT Id FROM tblLyrics WHERE SongId = ? AND Source = 'ihymns' LIMIT 1"
    );
    $sel->bind_param('s', $songId);
    $sel->execute();
    $row = $sel->get_result()->fetch_row();
    $sel->close();
    if ($row !== null) {
        return (int)$row[0];
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

    /* PF2 / R3 — before the deletes cascade away any per-line enrichment, snapshot it
       to tblActivityLog so a heavy edit (a rewrite scored below the pass-3 fuzzy floor,
       counted as delete+insert) or a genuine line removal never SILENTLY destroys a
       curated translation / annotation. No-op while enrichment is dormant; best-effort
       (never throws — a save must not fail because we couldn't snapshot). The fuller
       enrichment-aware match + re-attach UX is tracked as a P3 follow-up (#1235). */
    if (!empty($plan['deleteIds'])) {
        $textById = [];
        foreach ($existing as $e) { $textById[(int)$e['Id']] = (string)$e['LineText']; }
        lyricLinesSnapshotDeletedEnrichment($db, $songId, array_map('intval', $plan['deleteIds']), $textById);
    }

    /* DELETE removed lines first; CASCADE drops their orphaned enrichment. */
    if (!empty($plan['deleteIds'])) {
        $del = $db->prepare("DELETE FROM tblLyricLines WHERE Id = ?");
        foreach ($plan['deleteIds'] as $delId) {
            $del->bind_param('i', $delId);
            $del->execute();
        }
        $del->close();
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
        if ($matchId === null) {
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
 * (lyricLinesApplyDesired) so per-line enrichment survives. SortOrder is kept
 * contiguous 0..n-1 (so the #1066 ArrangementJson ordinal arrays stay valid).
 *
 * Each component entry: { type, number, language?, lines:string[], chords?:array,
 * notes?:array, languages?:array } — the same shape save_song / the importers /
 * the snapshot already hold. Call only when lyricLinesSyncReady() is true.
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
            'languagesJson' => $langsJson,                                                  // shadow string (or null)
            'validatedLangs'=> $langsJson !== null ? json_decode($langsJson, true) : null,  // null-padded validated array
        ];
    }

    /* (1) Id-stable thin-component upsert + shadow JSON → position → ComponentId. */
    $cidMap = lyricLinesUpsertComponents($db, $songId, $norm);
    foreach ($norm as $i => &$c) { $c['cid'] = (int)($cidMap[$i] ?? 0); }
    unset($c);

    /* (2) Desired lines from the payload (never LinesJson). (3) Diff into tblLyricLines. */
    $desired = lyricLinesBuildDesiredFromComponents($norm, static fn(?string $t): ?string => lyricLinesPartTypeSlug($db, $t));
    return lyricLinesApplyDesired($db, $songId, $desired);
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
 * @param list<array<string,mixed>> $norm  normalised components (lyricLinesWriteComponents)
 * @return array<int,int>  position → ComponentId
 */
function lyricLinesUpsertComponents(\mysqli $db, string $songId, array $norm): array
{
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
                $cells = []; $any = false;
                for ($k = 0; $k < $lineCount; $k++) {
                    $v = (is_array($c['chords']) && array_key_exists($k, $c['chords'])) ? $c['chords'][$k] : null;
                    $cells[] = $v;
                    if ($v !== null) { $any = true; }
                }
                $out[] = $any ? json_encode($cells, JSON_UNESCAPED_UNICODE) : null;
            } elseif ($sc === 'NotesJson') {
                $cells = []; $any = false;
                for ($k = 0; $k < $lineCount; $k++) {
                    $v = (is_array($c['notes']) && array_key_exists($k, $c['notes']) && $c['notes'][$k] !== null && $c['notes'][$k] !== '')
                        ? $c['notes'][$k] : null;
                    $cells[] = $v;
                    if ($v !== null) { $any = true; }
                }
                $out[] = $any ? json_encode($cells, JSON_UNESCAPED_UNICODE) : null;
            } else { /* LanguagesJson — already clamped to line-count by lineEnrichmentBuildLanguagesJson */
                $out[] = $c['languagesJson'];
            }
        }
        return $out;
    };

    /* Existing thin rows in position order (the match anchor). */
    $exStmt = $db->prepare("SELECT Id FROM tblSongComponents WHERE SongId = ? ORDER BY SortOrder, Id");
    $exStmt->bind_param('s', $songId);
    $exStmt->execute();
    $existingIds = array_map(static fn($r) => (int)$r[0], $exStmt->get_result()->fetch_all(MYSQLI_NUM));
    $exStmt->close();

    /* Prepare INSERT + UPDATE once (column set is fixed for this call). */
    $insCols  = array_merge(['SongId', 'Type', 'Number', 'SortOrder', 'Language'], $shadowCols);
    $insPlace = implode(',', array_fill(0, count($insCols), '?'));
    $insTypes = 'ssiis' . str_repeat('s', count($shadowCols));
    $ins = $db->prepare('INSERT INTO tblSongComponents (' . implode(',', $insCols) . ") VALUES ({$insPlace})");

    $updSet   = 'Type = ?, Number = ?, SortOrder = ?, Language = ?'
              . implode('', array_map(static fn($c) => ", {$c} = ?", $shadowCols));
    $updTypes = 'siis' . str_repeat('s', count($shadowCols)) . 'i';
    $upd = $db->prepare("UPDATE tblSongComponents SET {$updSet} WHERE Id = ?");

    $cidMap = [];
    foreach ($norm as $i => $c) {
        $shadow = $shadowVals($c);
        if ($i < count($existingIds)) {
            $compId = $existingIds[$i];
            $vals   = array_merge([$c['type'], $c['number'], $i, $c['language']], $shadow, [$compId]);
            $upd->bind_param($updTypes, ...$vals);
            $upd->execute();
            $cidMap[$i] = $compId;
        } else {
            $vals = array_merge([$songId, $c['type'], $c['number'], $i, $c['language']], $shadow);
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
            ];
            $sort++;
        }
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
 * @param list<int>          $deleteIds  line Ids about to be deleted
 * @param array<int,string>  $textById   pre-edit LineText keyed by line Id (snapshot context)
 */
function lyricLinesSnapshotDeletedEnrichment(\mysqli $db, string $songId, array $deleteIds, array $textById): void
{
    if (empty($deleteIds) || !lyricLinesEnrichmentTablesPresent($db)) {
        return;
    }
    try {
        /* Placeholder string built from a hardcoded count (rule #5) — never input. */
        $place = implode(',', array_fill(0, count($deleteIds), '?'));
        $types = str_repeat('i', count($deleteIds));

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

        if (empty($trans) && empty($annos)) {
            return;   // the common path once enrichment exists: deleted lines had none
        }

        $snapshot = json_encode([
            'songId'       => $songId,
            'reason'       => 'Enrichment cascade-deleted by an Id-preserving reprojection (#1235 PF2/R3); recoverable from this row.',
            'deletedLines' => array_map(
                static fn(int $id): array => ['id' => $id, 'text' => $textById[$id] ?? null],
                $deleteIds
            ),
            'translations' => $trans,
            'annotations'  => $annos,
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
        /* Best-effort: never break a save because the snapshot failed. */
    }
}

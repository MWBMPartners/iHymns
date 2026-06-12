<?php

declare(strict_types=1);

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
        /* Require BOTH per-line columns — a half-applied migration (ChordsJson
           added, Note not) must NOT report ready, or the dual-write would throw
           on the missing Note column. */
        $r = $db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblLyricLines'
                AND COLUMN_NAME  IN ('ChordsJson', 'Note')"
        );
        $row   = $r ? $r->fetch_row() : null;
        $ready = ($row !== null && (int)$row[0] >= 2);
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
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
    $lyricsId = lyricLinesEnsurePrimaryVersion($db, $songId);

    /* PRE-edit lines for this version — what is currently mirrored, in order.
       Pull every projected column so the dirty-check can skip no-op UPDATEs. */
    $exStmt = $db->prepare(
        "SELECT Id, ComponentId, PartType, PartNumber, SortOrder,
                LineText, ChordsJson, Note, LanguageCode, IsInstrumental
           FROM tblLyricLines
          WHERE LyricsId = ?
          ORDER BY SortOrder, Id"
    );
    $exStmt->bind_param('i', $lyricsId);
    $exStmt->execute();
    $existing = $exStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $exStmt->close();

    /* POST-edit desired lines, derived from the now-authoritative components. */
    $desired = lyricLinesBuildDesired($db, $songId);

    /* Align pre→post by content, preserving Ids (and thus dependent FKs). */
    $plan = lyricLinesDiff($existing, $desired);

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
            (LyricsId, ComponentId, PartType, PartNumber, SortOrder,
             LineText, ChordsJson, Note, LanguageCode, IsInstrumental)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $upd = $db->prepare(
        "UPDATE tblLyricLines
            SET ComponentId = ?, PartType = ?, PartNumber = ?, SortOrder = ?,
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
                'iisiissssi',
                $lyricsId, $d['ComponentId'], $d['PartType'], $d['PartNumber'], $d['SortOrder'],
                $d['LineText'], $d['ChordsJson'], $d['Note'], $d['LanguageCode'], $d['IsInstrumental']
            );
            $ins->execute();
        } elseif (!lyricLinesRowClean($existingById[$matchId] ?? null, $d)) {
            /* Only write when something about the line actually changed. */
            $upd->bind_param(
                'isiissssii',
                $d['ComponentId'], $d['PartType'], $d['PartNumber'], $d['SortOrder'],
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
 * Build the ordered DESIRED line list for a song from its now-authoritative
 * `tblSongComponents` (same per-line shape the projector writes). Pure read — no
 * writes. Each line is an assoc carrying exactly the columns lyricLinesProjectSong()
 * binds, so the build logic lives in one place.
 *
 * @param \mysqli $db
 * @param string  $songId
 * @return list<array{ComponentId:int,PartType:string,PartNumber:?int,SortOrder:int,LineText:string,ChordsJson:?string,Note:?string,LanguageCode:?string,IsInstrumental:int}>
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

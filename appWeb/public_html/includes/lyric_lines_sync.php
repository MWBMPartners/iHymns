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
 * PHASE 1 (now): `tblSongComponents` stays authoritative; `tblLyricLines` is a
 * kept-in-sync MIRROR (the backfill migration + a transitional dual-write on
 * every component write). PUBLIC READS ARE UNCHANGED. The projection here is a
 * naive whole-song delete + reinsert — correct + simple while nothing depends on
 * line `Id` stability yet (timings / translations / annotations only attach in
 * P3). P2 flips reads to `tblLyricLines` and replaces this with an Id-preserving
 * diff so per-line enrichment survives an edit.
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
 * Project ALL of a song's components into `tblLyricLines` (the primary version):
 * wipe the version's existing lines, then reinsert one row per lyric line in
 * global order, carrying part identity (Type → PartType, Number → PartNumber),
 * the per-line chord (ChordsJson[i]) and presenter note (NotesJson[i]), and the
 * per-component language override (#858). Returns the line count written.
 *
 * Naive whole-song replace (P1). Idempotent — safe to call on every component
 * write and to re-run in the backfill. No-op-safe to call only when
 * lyricLinesSyncReady() is true (the columns exist).
 *
 * @param \mysqli $db
 * @param string  $songId
 * @return int  number of lines written
 */
function lyricLinesProjectSong(\mysqli $db, string $songId): int
{
    $lyricsId = lyricLinesEnsurePrimaryVersion($db, $songId);

    /* Replace the whole version's lines (P1 naive reproject). */
    $del = $db->prepare("DELETE FROM tblLyricLines WHERE LyricsId = ?");
    $del->bind_param('i', $lyricsId);
    $del->execute();
    $del->close();

    /* Authoritative source: the song's components, in display order. */
    $cs = $db->prepare(
        "SELECT Id, Type, Number, Language, LinesJson, ChordsJson, NotesJson
           FROM tblSongComponents
          WHERE SongId = ?
          ORDER BY SortOrder, Id"
    );
    $cs->bind_param('s', $songId);
    $cs->execute();
    $comps = $cs->get_result()->fetch_all(MYSQLI_ASSOC);
    $cs->close();

    $ins = $db->prepare(
        "INSERT INTO tblLyricLines
            (LyricsId, ComponentId, PartType, PartNumber, SortOrder,
             LineText, ChordsJson, Note, LanguageCode, IsInstrumental)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $sort  = 0;   // global line order within the version
    $count = 0;
    foreach ($comps as $c) {
        $compId     = (int)$c['Id'];
        $partType   = (string)$c['Type'];
        $number     = (int)$c['Number'];
        $partNumber = $number > 0 ? $number : null;          // 0 (e.g. a lone Chorus) => NULL
        $lang       = ($c['Language'] !== null && $c['Language'] !== '') ? (string)$c['Language'] : null;

        $lines  = json_decode((string)$c['LinesJson'], true);
        if (!is_array($lines)) { $lines = []; }
        $chords = ($c['ChordsJson'] !== null) ? json_decode((string)$c['ChordsJson'], true) : null;
        $notes  = ($c['NotesJson']  !== null) ? json_decode((string)$c['NotesJson'],  true) : null;

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

            $ins->bind_param(
                'iisiissssi',
                $lyricsId, $compId, $partType, $partNumber, $sort,
                $text, $chordVal, $noteVal, $lang, $isInst
            );
            $ins->execute();
            $sort++;
            $count++;
        }
    }
    $ins->close();
    return $count;
}

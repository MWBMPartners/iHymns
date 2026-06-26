<?php

declare(strict_types=1);

/**
 * iHymns — tblLyricLines mirror: per-line chord/note columns + full backfill (#1235 P1)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE (lyric-line normalisation, Phase 1 — Option 1):
 *   1. Add the two per-line homes Option 1 still needs on `tblLyricLines`:
 *      - ChordsJson (the line's chords, mirrored from tblSongComponents.ChordsJson[i])
 *      - Note       (the line's presenter/slide note, from tblSongComponents.NotesJson[i])
 *      Everything else Option 1 needs (PartType/PartNumber/SortOrder/LineText/
 *      LanguageCode/timing/MetaJson) already exists on the table.
 *   2. BACKFILL the whole catalogue into `tblLyricLines` from the authoritative
 *      `tblSongComponents` — IN PLACE, no re-import — so the normalised mirror is
 *      complete (an earlier `migrate-normalize-lyrics.php` populated line text +
 *      part identity but NOT chords/notes; this reprojects everything via the one
 *      shared projector, `lyricLinesProjectSong()`).
 *
 * P1 keeps `tblSongComponents` authoritative + reads unchanged; this mirror is
 * kept live by the transitional dual-write (P1b). Strictly additive + idempotent
 * (CREATE/ADD gated; the projection is delete + reinsert per song, re-runnable).
 *
 * @migration-adds tblLyricLines.ChordsJson
 * @migration-adds tblLyricLines.Note
 *
 * USAGE:
 *   Web:  /manage/setup-database → "Lyric-lines mirror (chords/notes + backfill)"
 *   CLI:  php appWeb/.sql/migrate-lyric-lines-mirror.php
 */

$isCli = (PHP_SAPI === 'cli');
if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
}

/* Locate the shared projector ROBUSTLY. The naive `dirname(__DIR__)/public_html`
   (sibling of .sql/) assumes the deploy keeps .sql and the SERVED public_html as
   siblings — but on the server the live web docroot is a DIFFERENT tree from that
   sibling path (the sibling carries long-lived files like db_mysql.php but NOT a
   brand-new include), so a single hardcoded path 500s on "Failed opening
   required". Try the live docroot (DOCUMENT_ROOT — where the dashboard runs and
   the deploy lands) first, then the sibling for CLI. */
$_llSyncCandidates = [];
$_llDocRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($_llDocRoot !== '') {
    $_llSyncCandidates[] = $_llDocRoot . '/includes/lyric_lines_sync.php';
}
$_llSyncCandidates[] = dirname(__DIR__) . '/public_html/includes/lyric_lines_sync.php';
$_llSyncPath = null;
foreach ($_llSyncCandidates as $_cand) {
    if (is_file($_cand)) { $_llSyncPath = $_cand; break; }
}
if ($_llSyncPath === null) {
    echo 'ERROR: could not locate lyric_lines_sync.php (tried: '
        . implode(' | ', $_llSyncCandidates) . ')' . ($isCli ? "\n" : "<br>\n");
    if ($isCli) { exit(1); }
    return;
}
require_once $_llSyncPath;

function _migLLMirror_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

/** Add a column to tblLyricLines only if absent (idempotent). */
function _migLLMirror_addCol(mysqli $db, string $col, string $ddl): void
{
    $res = $db->query("SHOW COLUMNS FROM tblLyricLines LIKE '" . $db->real_escape_string($col) . "'");
    if ($res && $res->num_rows > 0) {
        _migLLMirror_out("  [SKIP] tblLyricLines.{$col} already present.");
        return;
    }
    $db->query("ALTER TABLE tblLyricLines ADD COLUMN {$ddl}");
    _migLLMirror_out("  [OK] Added tblLyricLines.{$col}.");
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migLLMirror_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

_migLLMirror_out('=== iHymns — tblLyricLines mirror: chord/note columns + backfill (#1235 P1) ===');

try {
    /* ---- Step 1: per-line chord + note columns (DDL byte-identical to schema.sql) ---- */
    _migLLMirror_out('--- Step 1: per-line chord + note columns ---');
    _migLLMirror_addCol(
        $db,
        'ChordsJson',
        "ChordsJson JSON NULL DEFAULT NULL COMMENT 'Per-line chords mirrored from tblSongComponents.ChordsJson[i] (null/string/array); #1235 P1 normalisation' AFTER MetaJson"
    );
    _migLLMirror_addCol(
        $db,
        'Note',
        "Note TEXT NULL DEFAULT NULL COMMENT 'Per-line presenter/slide note mirrored from tblSongComponents.NotesJson[i]; #1235 P1 normalisation' AFTER ChordsJson"
    );

    /* ---- Step 2: backfill the whole catalogue (in place, no re-import) ---- */
    _migLLMirror_out('--- Step 2: backfilling tblLyricLines from tblSongComponents ---');
    /* #1235 P4/C6 retired-era guard: post-drop, tblSongComponents.LinesJson is gone — there
       is nothing to backfill FROM (tblLyricLines is authoritative). lyricLinesProjectSong()
       already self-no-ops in that state, but skip the whole-corpus loop so a manual re-run
       is instant instead of 16k pointless no-op projections. Step 1 (the column adds) is
       idempotent and already ran above. */
    $_llmLinesCol = $db->query("SHOW COLUMNS FROM tblSongComponents LIKE 'LinesJson'");
    $_llmLinesPresent = ($_llmLinesCol && $_llmLinesCol->num_rows > 0);
    if ($_llmLinesCol) { $_llmLinesCol->close(); }
    if (!$_llmLinesPresent) {
        _migLLMirror_out('  [SKIP] retired era (tblSongComponents.LinesJson gone) — tblLyricLines is authoritative; nothing to backfill (#1235 P4/C6).');
        return;
    }
    if (function_exists('set_time_limit')) { @set_time_limit(0); }

    /* Collect SongIds up front (buffered) so projection queries can run on the
       same connection without a "commands out of sync". */
    $songIds = [];
    $res = $db->query("SELECT SongId FROM tblSongs");
    while ($res && ($r = $res->fetch_row())) { $songIds[] = (string)$r[0]; }
    if ($res) { $res->close(); }

    $songs = 0;
    $lines = 0;
    $batch = 0;
    $db->begin_transaction();
    foreach ($songIds as $sid) {
        $lines += lyricLinesProjectSong($db, $sid);
        $songs++;
        if (++$batch >= 200) {
            $db->commit();
            $db->begin_transaction();
            $batch = 0;
            _migLLMirror_out("  ... {$songs} songs / {$lines} lines");
        }
    }
    $db->commit();

    _migLLMirror_out('');
    _migLLMirror_out("Backfill complete — {$songs} songs, {$lines} lines mirrored into tblLyricLines.");
    _migLLMirror_out('tblSongComponents stays authoritative; reads unchanged (P1).');
} catch (\Throwable $e) {
    /* Roll back the open backfill batch (no-op/harmless if the failure was in the
       DDL step before any transaction began). */
    @$db->rollback();
    _migLLMirror_out('  [ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

return;

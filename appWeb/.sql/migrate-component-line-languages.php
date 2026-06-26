<?php

declare(strict_types=1);

/**
 * iHymns — per-line language column on tblSongComponents (#1235 P3 / #1253)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Adds `tblSongComponents.LanguagesJson` — a per-line language parallel array
 * (same shape as ChordsJson / NotesJson: null-padded, parallel to LinesJson).
 *
 * WHY: per-line language needs a durable home in the AUTHORITATIVE store. The
 * lyric-line projector (lyric_lines_sync.php) rebuilds tblLyricLines.LanguageCode
 * from the component on every save, so a per-line override written straight onto
 * tblLyricLines would be clobbered on the next edit. Storing it here — where the
 * projector reads it (LanguagesJson[i] ?? component Language) — makes per-line
 * language survive reprojection, and migrates cleanly to the P4 line-authoritative
 * model. A line with no entry inherits the component Language (#858), which in
 * turn inherits tblSongs.Language.
 *
 * Strictly additive + idempotent (ADD COLUMN gated by an existence probe). No
 * backfill: existing lines already carry the component language; LanguagesJson
 * stays NULL until a curator sets a per-line override in the editor.
 *
 * @migration-adds tblSongComponents.LanguagesJson
 *
 * USAGE:
 *   Web:  /manage/setup-database → "Per-line language: tblSongComponents.LanguagesJson"
 *   CLI:  php appWeb/.sql/migrate-component-line-languages.php
 */

$isCli = (PHP_SAPI === 'cli');
if (!defined('IHYMNS_SETUP_DASHBOARD') && !function_exists('getDbMysqli')) {
    require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
}

function _migCompLang_out(string $msg): void
{
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) { @flush(); }
}

$db = function_exists('getDbMysqli') ? getDbMysqli() : null;
if (!($db instanceof mysqli)) {
    _migCompLang_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); }
    return;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

_migCompLang_out('=== iHymns — tblSongComponents.LanguagesJson (#1235 P3 / #1253) ===');

try {
    /* #1235 P4/C6 retired-era guard: once C6 drops the payload columns, tblSongComponents.
       LinesJson is gone. Do NOT re-add LanguagesJson — that would RESURRECT a retired column
       (per-line language now lives on tblLyricLines.LanguageCode). */
    $linesGone = $db->query("SHOW COLUMNS FROM tblSongComponents LIKE 'LinesJson'");
    if (!($linesGone && $linesGone->num_rows > 0)) {
        if ($linesGone) { $linesGone->close(); }
        _migCompLang_out('  [SKIP] retired era (tblSongComponents.LinesJson gone) — not re-adding LanguagesJson (#1235 P4/C6).');
        _migCompLang_out('Done (no-op: per-line language is on tblLyricLines.LanguageCode post-cutover).');
        return;
    }
    if ($linesGone) { $linesGone->close(); }

    $res = $db->query("SHOW COLUMNS FROM tblSongComponents LIKE 'LanguagesJson'");
    if ($res && $res->num_rows > 0) {
        _migCompLang_out('  [SKIP] tblSongComponents.LanguagesJson already present.');
    } else {
        /* DDL byte-identical to schema.sql (incl. COMMENT) so a fresh install
           equals a migrated one (rule #19). */
        $db->query(
            "ALTER TABLE tblSongComponents ADD COLUMN "
          . "LanguagesJson JSON NULL DEFAULT NULL COMMENT 'Per-line language overrides parallel to LinesJson; null-padded IETF BCP 47 tags. A null/absent entry inherits the component Language. Durable home for per-line language so it survives lyric-line reprojection (#1235 P3 / #1253)' "
          . "AFTER NotesJson"
        );
        _migCompLang_out('  [OK] Added tblSongComponents.LanguagesJson.');
    }
    _migCompLang_out('Done. Per-line language overrides now have a durable home; a line without one inherits the component language.');
} catch (\Throwable $e) {
    _migCompLang_out('  [ERROR] ' . $e->getMessage());
    if ($isCli) { exit(1); }
    return;
}

return;

<?php

declare(strict_types=1);

/**
 * iHymns — Drop Denormalised tblSongs.SongbookName (WS-E #1013 ph2)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Removes the denormalised tblSongs.SongbookName column. The songbook
 * name is now read LIVE via JOIN to tblSongbooks.Name everywhere (WS-E
 * phase 1 + phase 2), so the copy on every song row is dead weight that
 * also drifts when a songbook is renamed.
 *
 * DATA-PRESERVING + IDEMPOTENT. The whole thing is guarded on the column
 * still existing; once it's gone the script is a no-op. BEFORE the drop:
 *   1. Coverage — insert a tblSongbooks row for any SongbookAbbr that has
 *      songs but no songbook row (the fk_Songs_Songbook FK normally makes
 *      this impossible, but older / FK-less deployments are handled),
 *      seeding its Name from the denorm value.
 *   2. Backfill — fill any empty tblSongbooks.Name from the denorm value.
 * Only then is the column dropped, and only if both backfills succeeded.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-drop-songbook-name.php
 *   Web:  /manage/setup-database → "Drop denormalised SongbookName" button
 *
 * PREREQUISITES:
 *   - install.php has been run (tblSongs + tblSongbooks exist).
 *   - All readers/writers have been migrated to the live JOIN (WS-E ph2).
 *
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migDropSongbookName_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* =========================================================================
 * LOAD MYSQL CREDENTIALS
 * ========================================================================= */

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migDropSongbookName_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

/* =========================================================================
 * CONNECT TO MYSQL
 * ========================================================================= */

_migDropSongbookName_output("");
_migDropSongbookName_output("=== iHymns — Drop denormalised tblSongs.SongbookName (WS-E #1013 ph2) ===");
_migDropSongbookName_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migDropSongbookName_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migDropSongbookName_output("Connected to MySQL: " . DB_NAME);

/* =========================================================================
 * Is there anything to do? (idempotency gate)
 * ========================================================================= */

try {
    $colRes = $mysql->query("SHOW COLUMNS FROM tblSongs LIKE 'SongbookName'");
} catch (\Throwable $e) {
    _migDropSongbookName_output("  [ERROR] Could not inspect tblSongs: " . $e->getMessage());
    $mysql->close();
    return;
}

if (!$colRes || $colRes->num_rows === 0) {
    _migDropSongbookName_output("  [SKIP] tblSongs.SongbookName is already gone — nothing to do.");
    _migDropSongbookName_output("");
    _migDropSongbookName_output("Migration complete (no-op).");
    $mysql->close();
    return;
}

/* =========================================================================
 * STEP 1: Preserve names BEFORE dropping the column
 * ========================================================================= */

_migDropSongbookName_output("");
_migDropSongbookName_output("--- Step 1: preserve songbook names from the denorm column ---");

try {
    /* 1a — coverage: a tblSongbooks row for every SongbookAbbr that has
       songs but no songbook row. Normally zero (the FK guarantees it),
       but defensive for deployments missing the FK. Name seeded from the
       denorm value; all other columns take their schema defaults. */
    $mysql->query(
        "INSERT INTO tblSongbooks (Abbreviation, Name)
         SELECT s.SongbookAbbr, MAX(s.SongbookName)
           FROM tblSongs s
           LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
          WHERE sb.Abbreviation IS NULL
            AND s.SongbookAbbr <> ''
          GROUP BY s.SongbookAbbr
         HAVING MAX(s.SongbookName) <> ''"
    );
    $covered = $mysql->affected_rows;
    _migDropSongbookName_output("  [OK] Coverage: inserted {$covered} missing songbook row(s).");

    /* 1b — backfill any empty tblSongbooks.Name from the denorm value. */
    $mysql->query(
        "UPDATE tblSongbooks sb
           JOIN (
                 SELECT SongbookAbbr, MAX(SongbookName) AS nm
                   FROM tblSongs
                  WHERE SongbookName <> ''
                  GROUP BY SongbookAbbr
                ) x ON x.SongbookAbbr = sb.Abbreviation
            SET sb.Name = x.nm
          WHERE sb.Name = ''"
    );
    $named = $mysql->affected_rows;
    _migDropSongbookName_output("  [OK] Backfill: filled {$named} empty songbook name(s).");
} catch (\Throwable $e) {
    /* Do NOT drop the column if the backfill failed — that would lose the
       only copy of the names. Bail out so the admin can investigate. */
    _migDropSongbookName_output("  [ERROR] Name preservation failed — NOT dropping the column: " . $e->getMessage());
    $mysql->close();
    return;
}

/* =========================================================================
 * STEP 2: Drop the column
 * ========================================================================= */

_migDropSongbookName_output("");
_migDropSongbookName_output("--- Step 2: drop tblSongs.SongbookName ---");

try {
    $mysql->query("ALTER TABLE tblSongs DROP COLUMN SongbookName");
    _migDropSongbookName_output("  [OK] Dropped tblSongs.SongbookName.");
} catch (\Throwable $e) {
    _migDropSongbookName_output("  [ERROR] Could not drop SongbookName: " . $e->getMessage());
    $mysql->close();
    return;
}

/* =========================================================================
 * SUMMARY
 * ========================================================================= */

_migDropSongbookName_output("");
_migDropSongbookName_output("--- Summary ---");
_migDropSongbookName_output("  tblSongs.SongbookName dropped. Songbook names now read live");
_migDropSongbookName_output("  from tblSongbooks.Name via JOIN everywhere.");
_migDropSongbookName_output("");
_migDropSongbookName_output("Migration complete.");

$mysql->close();
return;

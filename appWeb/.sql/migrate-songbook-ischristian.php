<?php

declare(strict_types=1);

/**
 * iHymns — Songbook IsChristian filter axis (#1045 — iLyricsDB alignment, pre-deploy)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Add the machine filter axis that lets iHymns surface only Christian lyrics
 * from a shared generic corpus. The filter lives at the SONGBOOK level (one
 * indexed boolean across ~dozens of songbooks) rather than per-song — a song
 * reachable through any Christian songbook is included; iHymns queries join
 * song→songbook and apply `WHERE sb.IsChristian = 1`, while the generic
 * iLyricsDB UI applies no filter and sees everything.
 *
 * Every existing iHymns songbook is Christian, so the column DEFAULTs to 1 —
 * the alpha ships already-filterable with no reclassification needed. Pairs
 * with tblSongbookEntries (#1044): the filter is only fully reliable once a
 * song's membership is expressed through the junction (a song could then be
 * Christian-via-one-book and secular-via-another).
 *
 * IDEMPOTENT (column-existence guarded). STRICTLY ADDITIVE.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-songbook-ischristian.php
 *   Web:  /manage/setup-database → "Songbook IsChristian filter" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migSongbookChristian_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migSongbookChristian_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migSongbookChristian_output("");
_migSongbookChristian_output("=== iHymns — Songbook IsChristian filter axis (#1045) ===");
_migSongbookChristian_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migSongbookChristian_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migSongbookChristian_output("Connected to MySQL: " . DB_NAME);

_migSongbookChristian_output("");
_migSongbookChristian_output("--- tblSongbooks.IsChristian ---");
try {
    $colRes = $mysql->query("SHOW COLUMNS FROM tblSongbooks LIKE 'IsChristian'");
    if ($colRes && $colRes->num_rows > 0) {
        _migSongbookChristian_output("  [SKIP] tblSongbooks.IsChristian already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongbooks
                ADD COLUMN IsChristian TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT 'Christian-corpus filter axis (#1045): iHymns surfaces only WHERE IsChristian=1; the shared iLyricsDB core applies no filter. Defaults 1 — every iHymns songbook is Christian.'
                    AFTER Affiliation,
                ADD INDEX idx_IsChristian (IsChristian)"
        );
        _migSongbookChristian_output("  [OK] Added tblSongbooks.IsChristian (default 1) + idx_IsChristian.");
    }
} catch (\Throwable $e) {
    _migSongbookChristian_output("  [ERROR] Could not add tblSongbooks.IsChristian: " . $e->getMessage());
    $mysql->close();
    return;
}

_migSongbookChristian_output("");
_migSongbookChristian_output("--- Summary ---");
_migSongbookChristian_output("  tblSongbooks.IsChristian added (all existing songbooks default to Christian).");
_migSongbookChristian_output("  iHymns will join song→songbook and filter WHERE IsChristian=1 once the shared corpus goes generic.");
_migSongbookChristian_output("");
_migSongbookChristian_output("Migration complete.");

$mysql->close();
return;

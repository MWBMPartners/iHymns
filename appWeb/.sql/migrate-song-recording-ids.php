<?php

declare(strict_types=1);

/**
 * iHymns — Recording identifiers on songs (#1064)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Add first-class ISRC + UPC columns to tblSongs so the lyrics-ingest endpoint
 * (#1064) can store the recording/release identifiers MeedyaDL (#907) supplies
 * from the Apple Music API, and so the duplicate-songs review page can detect
 * songs that share an ISRC (a strong duplicate signal). MusicBrainz / Spotify /
 * Genius / Apple-Music URLs continue to live in the tblSongExternalLinks
 * registry (their slugs are already seeded); ISRC/UPC are bare identifiers, not
 * URLs, so they get indexed columns instead of link rows.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (column existence guarded). The provisional
 * songbook for songs created by ingest is the existing canonical 'Misc'
 * (always seeded by schema.sql) — no new songbook needed.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-recording-ids.php
 *   Web:  /manage/setup-database → "Song recording IDs (ISRC/UPC)" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migSongIds_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

function _migSongIds_addCol(\mysqli $db, string $table, string $col, string $ddl): void {
    $r = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
    if ($r && $r->num_rows > 0) {
        _migSongIds_output("  [SKIP] {$table}.{$col} already present.");
        return;
    }
    $db->query("ALTER TABLE {$table} ADD COLUMN {$ddl}");
    _migSongIds_output("  [OK] Added {$table}.{$col}.");
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migSongIds_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migSongIds_output("");
_migSongIds_output("=== iHymns — Recording identifiers on songs (#1064) ===");
_migSongIds_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migSongIds_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migSongIds_output("Connected to MySQL: " . DB_NAME);

try {
    _migSongIds_output("");
    _migSongIds_output("--- tblSongs: ISRC + UPC columns ---");
    _migSongIds_addCol($mysql, 'tblSongs', 'Isrc',
        "Isrc VARCHAR(15) NULL DEFAULT NULL COMMENT 'International Standard Recording Code (#1064); recording id from an ingest source' AFTER Iswc");
    _migSongIds_addCol($mysql, 'tblSongs', 'Upc',
        "Upc VARCHAR(20) NULL DEFAULT NULL COMMENT 'Universal Product Code / barcode of the source release (#1064)' AFTER Isrc");

    /* Index ISRC for the duplicate-songs shared-id detection. */
    $idx = $mysql->query("SHOW INDEX FROM tblSongs WHERE Key_name = 'idx_Isrc'");
    if ($idx && $idx->num_rows === 0) {
        $mysql->query("ALTER TABLE tblSongs ADD INDEX idx_Isrc (Isrc)");
        _migSongIds_output("  [OK] Added index idx_Isrc.");
    } else {
        _migSongIds_output("  [SKIP] idx_Isrc already present.");
    }

    _migSongIds_output("");
    _migSongIds_output("--- Summary ---");
    _migSongIds_output("  Songs can now carry an ISRC + UPC; the lyrics-ingest endpoint (#1064)");
    _migSongIds_output("  populates them, and /manage/duplicate-songs uses ISRC as a dedupe signal.");
    _migSongIds_output("  Provisional ingest-created songs use the existing 'Misc' songbook.");
    _migSongIds_output("");
    _migSongIds_output("Migration complete.");
} catch (\Throwable $e) {
    _migSongIds_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;

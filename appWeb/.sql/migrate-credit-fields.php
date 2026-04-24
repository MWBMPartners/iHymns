<?php

declare(strict_types=1);

/**
 * iHymns — Credit Fields Migration (#497)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Brings an existing deployment up to the #497 schema:
 *   1. tblSongs gains two nullable columns:
 *      - TuneName VARCHAR(120)  (e.g. HYFRYDOL, OLD HUNDREDTH)
 *      - Iswc     VARCHAR(15)   (International Standard Musical Work Code)
 *      Plus an idx_TuneName index for the cross-reference listing.
 *   2. Three new credit tables are created if missing:
 *      - tblSongArrangers
 *      - tblSongAdaptors
 *      - tblSongTranslators
 *      Each mirrors the tblSongWriters / tblSongComposers shape.
 *
 * Idempotent — re-running is safe. Columns/indexes/tables that already
 * exist are skipped with a "skipped" note.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-credit-fields.php
 *   Web:  /manage/setup-database → "Credit Fields Migration" button
 *         (entry point requires global_admin)
 *
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migCredits_out(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* Load DB credentials — same path as every other migration script. */
$credPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credPath)) {
    _migCredits_out('ERROR: db_credentials.php not found — run install.php first.');
    exit(1);
}
if (!defined('DB_HOST')) {
    require_once $credPath;
}

$mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, defined('DB_PORT') ? (int)DB_PORT : 3306);
if ($mysqli->connect_errno) {
    _migCredits_out('ERROR: MySQL connect failed: ' . $mysqli->connect_error);
    exit(1);
}
$mysqli->set_charset('utf8mb4');

_migCredits_out('=== iHymns Credit Fields Migration (#497) ===');
_migCredits_out('Database: ' . DB_NAME . ' @ ' . DB_HOST);
_migCredits_out('');

/* Helper: does a column exist on a table? */
function _migCredits_colExists(mysqli $db, string $table, string $col): bool {
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/* Helper: does an index exist on a table? */
function _migCredits_indexExists(mysqli $db, string $table, string $index): bool {
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/* Helper: does a table exist? */
function _migCredits_tableExists(mysqli $db, string $table): bool {
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/* Sanity: require tblSongs to exist. Without it this migration is
   premature — the caller needs to run install.php first. */
if (!_migCredits_tableExists($mysqli, 'tblSongs')) {
    _migCredits_out('ERROR: tblSongs is missing — run install.php before this migration.');
    exit(1);
}

/* ----------------------------------------------------------------------
 * Step 1: add TuneName to tblSongs
 * ---------------------------------------------------------------------- */
if (_migCredits_colExists($mysqli, 'tblSongs', 'TuneName')) {
    _migCredits_out('[skip] tblSongs.TuneName already present.');
} else {
    $sql = "ALTER TABLE tblSongs
              ADD COLUMN TuneName VARCHAR(120) NULL DEFAULT NULL
              COMMENT 'Traditional tune name, e.g. HYFRYDOL, OLD HUNDREDTH (#497)'
              AFTER Copyright";
    if (!$mysqli->query($sql)) {
        _migCredits_out('ERROR: adding TuneName failed: ' . $mysqli->error);
        exit(1);
    }
    _migCredits_out('[add ] tblSongs.TuneName.');
}

if (_migCredits_indexExists($mysqli, 'tblSongs', 'idx_TuneName')) {
    _migCredits_out('[skip] tblSongs idx_TuneName already present.');
} else {
    if (!$mysqli->query('CREATE INDEX idx_TuneName ON tblSongs (TuneName)')) {
        _migCredits_out('WARN:  creating idx_TuneName failed: ' . $mysqli->error);
    } else {
        _migCredits_out('[add ] tblSongs idx_TuneName.');
    }
}

/* ----------------------------------------------------------------------
 * Step 2: add Iswc to tblSongs
 * ---------------------------------------------------------------------- */
if (_migCredits_colExists($mysqli, 'tblSongs', 'Iswc')) {
    _migCredits_out('[skip] tblSongs.Iswc already present.');
} else {
    $sql = "ALTER TABLE tblSongs
              ADD COLUMN Iswc VARCHAR(15) NULL DEFAULT NULL
              COMMENT 'International Standard Musical Work Code, e.g. T-034.524.680-C (#497)'
              AFTER Ccli";
    if (!$mysqli->query($sql)) {
        _migCredits_out('ERROR: adding Iswc failed: ' . $mysqli->error);
        exit(1);
    }
    _migCredits_out('[add ] tblSongs.Iswc.');
}

/* ----------------------------------------------------------------------
 * Step 3: create tblSongArrangers / tblSongAdaptors / tblSongTranslators
 * ---------------------------------------------------------------------- */
$creditTables = [
    'tblSongArrangers'   => 'fk_Arrangers_Song',
    'tblSongAdaptors'    => 'fk_Adaptors_Song',
    'tblSongTranslators' => 'fk_Translators_Song',
];

foreach ($creditTables as $table => $fkName) {
    if (_migCredits_tableExists($mysqli, $table)) {
        _migCredits_out("[skip] {$table} already present.");
        continue;
    }
    $sql = "CREATE TABLE {$table} (
        Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
        SongId      VARCHAR(20)     NOT NULL,
        Name        VARCHAR(255)    NOT NULL,
        INDEX idx_SongId    (SongId),
        INDEX idx_Name      (Name),
        CONSTRAINT {$fkName}
            FOREIGN KEY (SongId) REFERENCES tblSongs(SongId)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        _migCredits_out("ERROR: creating {$table} failed: " . $mysqli->error);
        exit(1);
    }
    _migCredits_out("[add ] {$table}.");
}

_migCredits_out('');
_migCredits_out('Migration complete.');
$mysqli->close();

<?php

declare(strict_types=1);

/**
 * iHymns — Songbook display label (#1332)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds tblSongbooks.DisplayAbbr — an OPTIONAL free-text label shown to users in
 * place of the Abbreviation badge (so a book can display "AH-OLD", "Psalty:Kids",
 * etc.). The Abbreviation column itself stays strictly alphanumeric because it is
 * the SongId prefix (MP-1008, parsed <letters>-<digits> by the PWA router /
 * OG-image / API validators); decoupling the display label from the code lets the
 * label use - _ : with ZERO change to SongIds, URLs or any FK. NULL = fall back to
 * the Abbreviation.
 *
 * Additive + IDEMPOTENT (column-existence guarded). The read path
 * (SongData::_songbookDisplayAbbrSelect) is INFORMATION_SCHEMA-gated, so the app
 * works whether or not this migration has run yet.
 *
 * @migration-adds tblSongbooks.DisplayAbbr
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-songbook-display-abbr.php
 *   Web:  /manage/setup-database → "Songbook display label" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migSDA_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSDA_columnExists(\mysqli $db, string $t, string $c): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $t, $c);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migSDA_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSDA_output("");
_migSDA_output("=== iHymns — Songbook display label (#1332) ===");

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migSDA_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migSDA_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migSDA_columnExists($mysql, 'tblSongbooks', 'DisplayAbbr')) {
        _migSDA_output("  [SKIP] tblSongbooks.DisplayAbbr already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongbooks
                ADD COLUMN DisplayAbbr VARCHAR(30) NULL DEFAULT NULL
                COMMENT 'Optional free-text display label shown in place of Abbreviation (any chars incl. - _ :); Abbreviation stays the alphanumeric SongId prefix. NULL = show Abbreviation (#1332).'
                AFTER Abbreviation"
        );
        _migSDA_output("  [OK] Added tblSongbooks.DisplayAbbr.");
    }
    _migSDA_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migSDA_output("ERROR: " . $e->getMessage());
}

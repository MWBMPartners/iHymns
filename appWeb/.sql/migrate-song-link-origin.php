<?php

declare(strict_types=1);

/**
 * iHymns — tblSongLinks.Origin marker (#1125)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds tblSongLinks.Origin so a counterpart link records HOW it was created:
 * 'manual' (a curator clicked "Link picked as counterparts" on
 * /manage/duplicate-songs) vs 'auto-iswc' / 'auto-ccli' / 'auto-isrc' (the #1125
 * hard-key auto-promoter, which links songs sharing an EXACT external identifier
 * without a per-cluster human click). The marker keeps auto-links auditable and
 * revertable, and lets the auto-promoter guarantee it never re-touches a manual
 * link. VARCHAR (not ENUM) so a new origin needs no ALTER (rule #20).
 *
 * Additive + IDEMPOTENT (column-existence guarded). The auto-promoter writes
 * Origin only when this column exists (it is INFORMATION_SCHEMA-gated), so the
 * page + the existing manual Link action work whether or not this has run yet
 * (the #1228 white-screen lesson).
 *
 * @migration-adds tblSongLinks.Origin
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-link-origin.php
 *   Web:  /manage/setup-database → "Song-link origin marker" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migSLO_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migSLO_columnExists(\mysqli $db, string $t, string $c): bool
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
if (!file_exists($credFile)) { _migSLO_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migSLO_output("");
_migSLO_output("=== iHymns — tblSongLinks.Origin marker (#1125) ===");

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migSLO_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migSLO_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migSLO_columnExists($mysql, 'tblSongLinks', 'Origin')) {
        _migSLO_output("  [SKIP] tblSongLinks.Origin already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblSongLinks
                ADD COLUMN Origin VARCHAR(20) NOT NULL DEFAULT 'manual'
                COMMENT 'How this link was created: manual (curator click) or auto-iswc/auto-ccli/auto-isrc (#1125 hard-key auto-promotion). VARCHAR not ENUM (rule #20).'
                AFTER Verified"
        );
        _migSLO_output("  [OK] Added tblSongLinks.Origin.");
    }
    _migSLO_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migSLO_output("ERROR: " . $e->getMessage());
}

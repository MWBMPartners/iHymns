<?php

declare(strict_types=1);

/**
 * iHymns — Song-link confidence tiers (#1066 Theme D)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Link suggestions are built from a fuzzy blended score today, so curators
 * cannot tell a strong-key match (shared ISRC / MusicBrainz id) from a weak
 * title-only guess. This migration adds a triage tier + the detecting signal so
 * the suggestions UI can surface high-confidence pairs first:
 *   - tblSongLinkSuggestions.Confidence ENUM('high','medium','low').
 *   - tblSongLinkSuggestions.Signal VARCHAR(50)  (VARCHAR, not ENUM, so a new
 *     signal type needs no future ALTER) + supporting indexes.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (column existence guarded).
 *
 * @migration-adds tblSongLinkSuggestions.Confidence
 * @migration-adds tblSongLinkSuggestions.Signal
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-song-link-confidence.php
 *   Web:  /manage/setup-database → "Song-link confidence tiers" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migLinkConf_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

function _migLinkConf_addCol(\mysqli $db, string $table, string $col, string $ddl): void {
    $r = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
    if ($r && $r->num_rows > 0) {
        _migLinkConf_output("  [SKIP] {$table}.{$col} already present.");
        return;
    }
    $db->query("ALTER TABLE {$table} ADD COLUMN {$ddl}");
    _migLinkConf_output("  [OK] Added {$table}.{$col}.");
}

function _migLinkConf_addIdx(\mysqli $db, string $table, string $idx, string $cols): void {
    $r = $db->query("SHOW INDEX FROM {$table} WHERE Key_name = '{$idx}'");
    if ($r && $r->num_rows > 0) {
        _migLinkConf_output("  [SKIP] {$idx} already present.");
        return;
    }
    $db->query("ALTER TABLE {$table} ADD INDEX {$idx} ({$cols})");
    _migLinkConf_output("  [OK] Added index {$idx}.");
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migLinkConf_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migLinkConf_output("");
_migLinkConf_output("=== iHymns — Song-link confidence tiers (#1066 Theme D) ===");
_migLinkConf_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migLinkConf_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migLinkConf_output("Connected to MySQL: " . DB_NAME);

try {
    _migLinkConf_output("");
    _migLinkConf_output("--- tblSongLinkSuggestions: Confidence + Signal ---");
    _migLinkConf_addCol($mysql, 'tblSongLinkSuggestions', 'Confidence',
        "Confidence ENUM('high','medium','low') NOT NULL DEFAULT 'low' COMMENT 'Triage tier (#1066 Theme D)' AFTER Score");
    _migLinkConf_addCol($mysql, 'tblSongLinkSuggestions', 'Signal',
        "Signal VARCHAR(50) NOT NULL DEFAULT 'fuzzy' COMMENT 'Detection method: fuzzy | shared-isrc | shared-musicbrainz | shared-spotify | shared-genius (#1066 Theme D)' AFTER Confidence");

    _migLinkConf_addIdx($mysql, 'tblSongLinkSuggestions', 'idx_Confidence', 'Confidence');
    _migLinkConf_addIdx($mysql, 'tblSongLinkSuggestions', 'idx_Signal', 'Signal');

    _migLinkConf_output("");
    _migLinkConf_output("--- Summary ---");
    _migLinkConf_output("  Link suggestions can now be triaged by confidence tier + detecting signal.");
    _migLinkConf_output("  The build job assigns them; the suggestions UI sorts on them (follow-up).");
    _migLinkConf_output("");
    _migLinkConf_output("Migration complete.");
} catch (\Throwable $e) {
    _migLinkConf_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;

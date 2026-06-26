<?php

declare(strict_types=1);

/**
 * iHymns — Song-request corrections: structured before/after diff (#1090 N2)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Extend tblSongRequests so it also captures CORRECTIONS to an existing song
 * (not just missing-song requests), as a structured field-level diff instead of
 * unstructured prose:
 *   - RequestType ('missing_song' | 'correction')
 *   - SongId (the song being corrected) + FK SET NULL
 *   - FieldName (which field — server allow-list validated)
 *   - OriginalValue / ProposedValue (the before/after of the diff)
 * Reuses the existing tblSongRequests store + admin triage (NOT the ingest
 * review queue, which needs a tblLyrics row). Purely additive — existing
 * missing-song rows and INSERTs keep working unchanged.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT.
 *
 * @migration-adds tblSongRequests.RequestType
 * @migration-adds tblSongRequests.SongId
 * @migration-adds tblSongRequests.FieldName
 * @migration-adds tblSongRequests.OriginalValue
 * @migration-adds tblSongRequests.ProposedValue
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-request-corrections.php
 *   Web:  /manage/setup-database → "Song-request corrections" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migCorr_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migCorr_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }
function _migCorr_addCol(\mysqli $db, string $t, string $c, string $ddl): void {
    if (_migCorr_colExists($db, $t, $c)) { _migCorr_output("  [SKIP] {$t}.{$c} present."); return; }
    $db->query("ALTER TABLE {$t} ADD COLUMN {$ddl}");
    _migCorr_output("  [OK] Added {$t}.{$c}.");
}
function _migCorr_fkExists(\mysqli $db, string $name): bool {
    $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = '" . $db->real_escape_string($name) . "' LIMIT 1");
    return (bool)($r && $r->num_rows > 0);
}
function _migCorr_idxExists(\mysqli $db, string $t, string $idx): bool {
    $r = $db->query("SHOW INDEX FROM {$t} WHERE Key_name = '" . $db->real_escape_string($idx) . "'");
    return (bool)($r && $r->num_rows > 0);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migCorr_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migCorr_output("");
_migCorr_output("=== iHymns — Song-request corrections (#1090 N2) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migCorr_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migCorr_output("Connected to MySQL: " . DB_NAME);

try {
    _migCorr_addCol($mysql, 'tblSongRequests', 'RequestType',
        "RequestType VARCHAR(20) NOT NULL DEFAULT 'missing_song' COMMENT 'missing_song | correction (app-validated) (#1090 N2)' AFTER ResolvedSongId");
    _migCorr_addCol($mysql, 'tblSongRequests', 'SongId',
        "SongId VARCHAR(20) NULL DEFAULT NULL COMMENT 'For corrections: the song being corrected (#1090 N2)' AFTER RequestType");
    _migCorr_addCol($mysql, 'tblSongRequests', 'FieldName',
        "FieldName VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'For corrections: which field (allow-list validated) (#1090 N2)' AFTER SongId");
    _migCorr_addCol($mysql, 'tblSongRequests', 'OriginalValue',
        "OriginalValue TEXT NULL DEFAULT NULL COMMENT 'For corrections: the current value, before (#1090 N2)' AFTER FieldName");
    _migCorr_addCol($mysql, 'tblSongRequests', 'ProposedValue',
        "ProposedValue TEXT NULL DEFAULT NULL COMMENT 'For corrections: the proposed value, after (#1090 N2)' AFTER OriginalValue");

    if (!_migCorr_idxExists($mysql, 'tblSongRequests', 'idx_RequestType')) {
        $mysql->query("ALTER TABLE tblSongRequests ADD INDEX idx_RequestType (RequestType)");
        _migCorr_output("  [OK] Added index idx_RequestType.");
    } else { _migCorr_output("  [SKIP] idx_RequestType present."); }
    if (!_migCorr_idxExists($mysql, 'tblSongRequests', 'idx_SongId')) {
        $mysql->query("ALTER TABLE tblSongRequests ADD INDEX idx_SongId (SongId)");
        _migCorr_output("  [OK] Added index idx_SongId.");
    } else { _migCorr_output("  [SKIP] idx_SongId present."); }
    if (!_migCorr_fkExists($mysql, 'fk_Requests_TargetSong')) {
        $mysql->query("ALTER TABLE tblSongRequests ADD CONSTRAINT fk_Requests_TargetSong FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE SET NULL ON UPDATE CASCADE");
        _migCorr_output("  [OK] Added FK fk_Requests_TargetSong.");
    } else { _migCorr_output("  [SKIP] fk_Requests_TargetSong present."); }

    _migCorr_output("Migration complete.");
} catch (\Throwable $e) { _migCorr_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;

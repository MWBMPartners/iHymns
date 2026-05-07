<?php

declare(strict_types=1);

/**
 * iHymns — Activity Log Expansion Migration (#535)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Brings an existing deployment's tblActivityLog up to the #535 schema
 * so the comprehensive instrumentation pass has somewhere to write to.
 *
 * Adds five columns + two indexes:
 *   - Result      ENUM('success','failure','error') NOT NULL DEFAULT 'success'
 *   - UserAgent   VARCHAR(500)
 *   - RequestId   CHAR(16)        — per-HTTP-request correlation ID
 *   - Method      VARCHAR(10)     — HTTP method, blank for cron/system
 *   - DurationMs  INT UNSIGNED    — wall-clock time of the logged op
 *   - idx_Result, idx_RequestId   — for the common debug queries
 *
 * Idempotent — re-running is safe. Columns / indexes that already
 * exist are skipped with a "skipped" note.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-activity-log-expand.php
 *   Web: /manage/setup-database → "Activity Log Expansion Migration"
 *
 * @migration-adds tblActivityLog.Result
 * @migration-adds tblActivityLog.UserAgent
 * @migration-adds tblActivityLog.RequestId
 * @migration-adds tblActivityLog.Method
 * @migration-adds tblActivityLog.DurationMs
 *
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migActLog_out(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* Credentials — same path as every other migration script. */
$credPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credPath)) {
    _migActLog_out('ERROR: db_credentials.php not found — run install.php first.');
    exit(1);
}
if (!defined('DB_HOST')) {
    require_once $credPath;
}

/* Strict reporting (#525) — exceptions on every failed query so a
   broken ALTER doesn't silently no-op. Matches every other migration
   in this directory. */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, defined('DB_PORT') ? (int)DB_PORT : 3306);
if ($mysqli->connect_errno) {
    _migActLog_out('ERROR: MySQL connect failed: ' . $mysqli->connect_error);
    exit(1);
}
$mysqli->set_charset('utf8mb4');

_migActLog_out('=== iHymns Activity Log Expansion Migration (#535) ===');
_migActLog_out('Database: ' . DB_NAME . ' @ ' . DB_HOST);
_migActLog_out('');

/* Helpers — column / index existence probes against INFORMATION_SCHEMA. */
function _migActLog_colExists(mysqli $db, string $table, string $col): bool {
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

function _migActLog_indexExists(mysqli $db, string $table, string $index): bool {
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

function _migActLog_tableExists(mysqli $db, string $table): bool {
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

if (!_migActLog_tableExists($mysqli, 'tblActivityLog')) {
    _migActLog_out('ERROR: tblActivityLog is missing — run install.php before this migration.');
    exit(1);
}

/* Each step: column name → ALTER statement. Listed in their target
   schema order; each ALTER uses an `AFTER` clause to position the new
   column where schema.sql declares it for fresh installs. The
   _resolveAfterClause() helper drops the AFTER clause if its anchor
   column doesn't exist on this DB yet (e.g. the deployment is so old
   the IpAddress column still has a different neighbour) so the migration
   never fails on cosmetic positioning. */
$steps = [
    ['col' => 'Result', 'sql' => "ALTER TABLE tblActivityLog
        ADD COLUMN Result ENUM('success','failure','error') NOT NULL DEFAULT 'success'
        COMMENT 'success = OK; failure = user-side reject; error = server-side exception (#535)'
        AFTER EntityId"],
    ['col' => 'UserAgent', 'sql' => "ALTER TABLE tblActivityLog
        ADD COLUMN UserAgent VARCHAR(500) NOT NULL DEFAULT ''
        COMMENT 'Truncated UA — useful for mobile vs desktop debugging (#535)'
        AFTER IpAddress"],
    ['col' => 'RequestId', 'sql' => "ALTER TABLE tblActivityLog
        ADD COLUMN RequestId CHAR(16) NOT NULL DEFAULT ''
        COMMENT 'Per-HTTP-request correlation ID; groups every row from one request (#535)'
        AFTER UserAgent"],
    ['col' => 'Method', 'sql' => "ALTER TABLE tblActivityLog
        ADD COLUMN Method VARCHAR(10) NOT NULL DEFAULT ''
        COMMENT 'HTTP method (GET/POST/etc) for HTTP-driven events; blank for cron/system (#535)'
        AFTER RequestId"],
    ['col' => 'DurationMs', 'sql' => "ALTER TABLE tblActivityLog
        ADD COLUMN DurationMs INT UNSIGNED NULL
        COMMENT 'Wall-clock duration of the logged operation in milliseconds (#535)'
        AFTER Method"],
];

function _migActLog_resolveAfterClause(mysqli $db, string $tbl, string $sql): string
{
    if (preg_match('/\sAFTER\s+([A-Za-z_][A-Za-z0-9_]*)\b/i', $sql, $m)) {
        if (!_migActLog_colExists($db, $tbl, $m[1])) {
            return preg_replace('/\sAFTER\s+[A-Za-z_][A-Za-z0-9_]*\b/i', '', $sql);
        }
    }
    return $sql;
}

foreach ($steps as $s) {
    if (_migActLog_colExists($mysqli, 'tblActivityLog', $s['col'])) {
        _migActLog_out("[skip] tblActivityLog.{$s['col']} already present.");
        continue;
    }
    $sql = _migActLog_resolveAfterClause($mysqli, 'tblActivityLog', $s['sql']);
    if (!$mysqli->query($sql)) {
        _migActLog_out("ERROR: adding {$s['col']} failed: " . $mysqli->error);
        exit(1);
    }
    _migActLog_out("[add ] tblActivityLog.{$s['col']}.");
}

/* Indexes — narrow, one per common debug-query pattern. */
$indexSteps = [
    'idx_Result'    => 'CREATE INDEX idx_Result    ON tblActivityLog (Result)',
    'idx_RequestId' => 'CREATE INDEX idx_RequestId ON tblActivityLog (RequestId)',
];

foreach ($indexSteps as $name => $sql) {
    if (_migActLog_indexExists($mysqli, 'tblActivityLog', $name)) {
        _migActLog_out("[skip] tblActivityLog {$name} already present.");
        continue;
    }
    if (!$mysqli->query($sql)) {
        _migActLog_out("WARN: creating {$name} failed: " . $mysqli->error);
    } else {
        _migActLog_out("[add ] tblActivityLog {$name}.");
    }
}

_migActLog_out('');
_migActLog_out('Migration complete.');
$mysqli->close();

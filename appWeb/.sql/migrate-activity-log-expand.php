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
 * NOT DESTRUCTIVE. Every statement is an ADD — no column is dropped,
 * retyped or rewritten, and no existing row is touched. The four new
 * NOT NULL columns all carry a DEFAULT so the implicit backfill MySQL
 * performs on existing rows is a harmless empty string / 'success';
 * DurationMs is NULLable because "we did not time this one" is a real
 * and different answer from "it took 0 ms". Rolling back is five DROP
 * COLUMNs, and the only loss is instrumentation history.
 *
 * IDEMPOTENT — re-running is safe. Each column is fronted by an
 * INFORMATION_SCHEMA.COLUMNS probe and each index by an
 * INFORMATION_SCHEMA.STATISTICS probe, so a second run prints seven
 * "[skip]" lines and issues no DDL. The probes are per-object and
 * independent, which is what lets a run that died half-way through
 * resume cleanly rather than needing a manual repair.
 *
 * OPERATOR VIEW. Card "Activity Log Expansion (#535)" on
 * /manage/setup-database; its registry probe keys on the presence of
 * tblActivityLog.RequestId.
 *
 * SCHEMA MIRROR. All five columns and both indexes are declared on
 * tblActivityLog in appWeb/.sql/schema.sql, which is what a FRESH
 * install reads. Rule #19 of .claude/CLAUDE.md requires the two
 * declarations to be byte-identical, COMMENT text included, so that an
 * install built by migrations and one built from schema.sql are the
 * same shape. CI only compares which columns EXIST, so wording drift
 * here is invisible until /manage/schema-audit (#518) reports it.
 *
 * USAGE:
 *   CLI: php appWeb/.sql/migrate-activity-log-expand.php
 *   Web: /manage/setup-database → "Activity Log Expansion Migration"
 *
 * The five doctags below are how the schema-coverage scanner
 * (includes/schema_audit.php, "Signal 3") learns what this file adds.
 * They are belt-and-braces here — each column gets its own ALTER, so
 * the literal-ALTER regex ("Signal 1") already sees all five — but
 * rule #19 asks for one tag PER column because that regex only catches
 * the first ADD COLUMN of a multi-column ALTER.
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
/* Note on Result: an ENUM here predates rule #20 (VARCHAR + an app-side
   allow-list for any vocabulary that might grow) and is grandfathered.
   The practical consequence is that a fourth outcome — 'partial', say —
   would cost an ALTER TABLE on what is one of the largest tables in the
   database, which is exactly the second migration rule #20 exists to
   avoid. Do not copy this shape into a new table. */
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

/**
 * Strip a trailing `AFTER <col>` clause when its anchor column is not on
 * this database.
 *
 * ELI5: the ALTERs above ask MySQL to slot each new column next to a
 * particular neighbour, purely so the table LOOKS like schema.sql. If
 * that neighbour isn't there, we drop the request rather than let a
 * cosmetic detail fail the whole migration.
 *
 * Detail: `AFTER x` is ordinal positioning only — it changes nothing a
 * query can observe, because SQL addresses columns by name. But MySQL
 * treats a missing anchor as a hard error (ER_BAD_FIELD_ERROR), so on a
 * deployment old enough that IpAddress or EntityId is absent or renamed,
 * every ALTER here would fail on positioning alone. Dropping the clause
 * lands the column at the end of the table instead: a different ordinal
 * position from a fresh install, and nothing else. Rule #19's
 * byte-identity requirement is about the column DECLARATION, which is
 * unaffected.
 * https://dev.mysql.com/doc/refman/8.0/en/alter-table.html
 *
 * Fragility worth knowing about: the regex takes the FIRST `AFTER` token
 * anywhere in the statement, and each statement carries a COMMENT string
 * BEFORE its AFTER clause. None of the five comments currently contains
 * the word, but one that did would be mistaken for the clause and the
 * preg_replace would then corrupt the comment text.
 */
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

/* Indexes — narrow, one per common debug-query pattern.

   These are deliberately WARN-and-continue where the column steps above
   are fatal: a missing column breaks the writer (the instrumentation
   pass has nowhere to put its value), whereas a missing index only makes
   two admin queries slower. On a large tblActivityLog an index build can
   also exceed the request's time limit, and failing the whole card for
   that would be the wrong trade — the columns are already in place and
   the index can be added by hand later. */
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

<?php

declare(strict_types=1);

/**
 * iHymns — Activity Log: microsecond-precision CreatedAt (#1287)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Widen tblActivityLog.CreatedAt from second-granularity TIMESTAMP to TIMESTAMP(6)
 * so logActivity()'s NOW(6) write captures sub-second time (forensics, latency,
 * precise sequencing readable straight off the timestamp). Ordering is already
 * deterministic via the Id tiebreaker (#1285); this adds timestamp ACCURACY.
 *
 * IDEMPOTENT — skips when DATETIME_PRECISION is already 6. Existing rows keep their
 * value with a .000000 fraction; no data loss (precision increase).
 *
 * NOTE: changing a TIMESTAMP's fractional precision resizes the column, so this is a
 * one-time table REWRITE. It's opt-in per-env (run from /manage/setup-database when
 * ready); trivial at current scale and the activity-log retention prune (#535) keeps
 * the table bounded. Requires MySQL >= 5.6.4 (fractional seconds).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-activity-log-microtime.php
 *   Web:  /manage/setup-database → "Activity Log: microsecond timestamps (#1287)"
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migALM_out(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migALM_out("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migALM_out("");
_migALM_out("=== iHymns — Activity Log microsecond CreatedAt (#1287) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migALM_out("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migALM_out("Connected to MySQL: " . DB_NAME);

try {
    /* Idempotent: skip when CreatedAt is already TIMESTAMP(6). */
    $res = $mysql->query(
        "SELECT DATETIME_PRECISION FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblActivityLog'
            AND COLUMN_NAME  = 'CreatedAt' LIMIT 1"
    );
    $row = $res ? $res->fetch_assoc() : null;
    if ($res) { $res->close(); }

    if ($row === null) {
        _migALM_out("  [SKIP] tblActivityLog.CreatedAt not found (run Install first).");
    } elseif ((int)($row['DATETIME_PRECISION'] ?? 0) >= 6) {
        _migALM_out("  [SKIP] tblActivityLog.CreatedAt is already TIMESTAMP(6).");
    } else {
        _migALM_out("  Rewriting tblActivityLog.CreatedAt -> TIMESTAMP(6) (one-time; precision change resizes the column)...");
        $mysql->query(
            "ALTER TABLE tblActivityLog
                MODIFY CreatedAt TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)"
        );
        _migALM_out("  [OK] tblActivityLog.CreatedAt is now TIMESTAMP(6) — new rows capture microseconds (logActivity writes NOW(6)).");
    }
    _migALM_out("Migration complete.");
} catch (\Throwable $e) { _migALM_out("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;

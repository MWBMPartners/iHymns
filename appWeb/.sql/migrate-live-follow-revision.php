<?php

declare(strict_types=1);

/**
 * iHymns — Live-follow: add the StateRevision broadcast counter (#1268)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds a monotonic StateRevision counter to tblLiveFollowSessions. The host
 * bumps it on every state write; followers short-poll with `?since=<n>` and the
 * server returns a near-free "unchanged" until the revision advances — the key
 * efficiency lever that lets the web/PWA Live Follow (#1268) scale on shared
 * hosting without holding a worker open per follower (no SSE).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. Depends on tblLiveFollowSessions existing
 * (run the "Live-follow sessions" migration first — #1090 P7).
 *
 * @migration-adds tblLiveFollowSessions.StateRevision
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-live-follow-revision.php
 *   Web:  /manage/setup-database → "Live-follow: StateRevision" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migLFR_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migLFR_tableExists(\mysqli $db, string $t): bool { $r = $db->query("SHOW TABLES LIKE '{$t}'"); return (bool)($r && $r->num_rows > 0); }
function _migLFR_colExists(\mysqli $db, string $t, string $c): bool {
    $r = $db->query("SHOW COLUMNS FROM `{$t}` LIKE '" . $db->real_escape_string($c) . "'");
    return (bool)($r && $r->num_rows > 0);
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migLFR_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migLFR_output("");
_migLFR_output("=== iHymns — Live-follow StateRevision (#1268) ===");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) { _migLFR_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migLFR_output("Connected to MySQL: " . DB_NAME);

try {
    if (!_migLFR_tableExists($mysql, 'tblLiveFollowSessions')) {
        _migLFR_output("  [SKIP] tblLiveFollowSessions not present yet — run the Live-follow sessions migration first.");
    } elseif (_migLFR_colExists($mysql, 'tblLiveFollowSessions', 'StateRevision')) {
        _migLFR_output("  [SKIP] tblLiveFollowSessions.StateRevision already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblLiveFollowSessions
                ADD COLUMN StateRevision INT UNSIGNED NOT NULL DEFAULT 0
                    COMMENT 'Monotonic broadcast revision; host bumps on each state write so followers poll cheaply with ?since='
                AFTER StateJson"
        );
        _migLFR_output("  [OK] Added tblLiveFollowSessions.StateRevision.");
    }
    _migLFR_output("Migration complete.");
} catch (\Throwable $e) { _migLFR_output("  [ERROR] " . $e->getMessage()); }
$mysql->close();
return;

<?php

declare(strict_types=1);

/**
 * iHymns — Public-read rate-limit counters (#1354)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Backs the lightweight per-requester rate limiter on the HEAVIEST public read
 * endpoints in api.php (song_detail / search / bulk_songs / songs_index /
 * related_songs …), to blunt scraping / abuse without ever tripping a real
 * native-app sync. Mirrors the per-API-KEY counter table tblApiKeyUsage (#1066
 * Theme B), but keys on a TOKEN-or-IP hash instead of an API key id — so the
 * public, sessionless reads get a fixed-window budget too.
 *
 *   - RateKey   = sha256(bearer token)  or  'ip:<addr>'  (per-token so a
 *                 NAT-shared set of native apps each get their own budget;
 *                 per-IP for anonymous callers).
 *   - Scope     = endpoint group (song_detail / search / bulk / index / …) so
 *                 different reads can carry different limits WITHOUT a 2nd
 *                 migration (rule #20 forward-looking — VARCHAR, app-validated).
 *   - WindowType / WindowStart = the fixed window (minute | day) the count
 *                 belongs to; a row is one (key, scope, window) bucket.
 *
 * Fixed-window counters via an atomic INSERT … ON DUPLICATE KEY UPDATE against
 * the UNIQUE key (race-safe). The helper that drives it
 * (includes/read_rate_limit.php) is FAIL-OPEN: if this table is absent it is a
 * clean no-op, so an un-migrated install never 500s under STRICT (the #1228
 * white-screen lesson).
 *
 * Additive + IDEMPOTENT (table-existence guarded). VARCHAR not ENUM (rule #20).
 *
 * @migration-adds tblReadRateLimit.RateKey
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-read-rate-limit.php
 *   Web:  /manage/setup-database → "Public-read rate-limit counters" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migRRL_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migRRL_tableExists(\mysqli $db, string $t): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migRRL_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migRRL_output("");
_migRRL_output("=== iHymns — Public-read rate-limit counters (#1354) ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migRRL_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migRRL_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migRRL_tableExists($mysql, 'tblReadRateLimit')) {
        _migRRL_output("  [SKIP] tblReadRateLimit already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblReadRateLimit (
                Id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                RateKey      VARCHAR(72)     NOT NULL COMMENT 'sha256 hex of the bearer token, or ip:<addr>',
                Scope        VARCHAR(40)     NOT NULL DEFAULT '' COMMENT 'endpoint group, so different reads can have different limits without a 2nd migration',
                WindowType   VARCHAR(10)     NOT NULL COMMENT 'minute | day',
                WindowStart  DATETIME        NOT NULL COMMENT 'fixed-window start (UTC, minute- or day-truncated)',
                RequestCount INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'requests counted in this (key, scope, window) bucket',

                UNIQUE KEY uq_read_rl (RateKey, Scope, WindowType, WindowStart),
                INDEX      idx_WindowStart (WindowStart)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Public-read fixed-window rate-limit counters, keyed by token-or-IP (#1354).'"
        );
        _migRRL_output("  [OK] Created tblReadRateLimit.");
    }
    _migRRL_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migRRL_output("ERROR: " . $e->getMessage());
}

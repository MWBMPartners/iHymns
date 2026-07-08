<?php

declare(strict_types=1);

/**
 * iHymns — First-party analytics event storage (#1448)
 *
 * Creates `tblAppAnalyticsEvents`, the storage for the native-app analytics
 * ingestion endpoint (`?action=analytics_ingest` in api.php). Additive,
 * idempotent — safe to re-run. See `includes/analytics_ingest.php` for the
 * validation this table's rows are guaranteed to have already passed, and
 * that file's header for the full PII/retention stance (no user id, no
 * device id, no IP address column on this table by design).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-analytics-events.php
 *   Web:  /manage/setup-database → "First-party analytics events" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migAAE_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migAAE_tableExists(\mysqli $db, string $t): bool
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
if (!file_exists($credFile)) { _migAAE_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migAAE_output("");
_migAAE_output("=== iHymns — First-party analytics event storage (#1448) ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migAAE_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migAAE_output("Connected to MySQL: " . DB_NAME);

try {
    if (_migAAE_tableExists($mysql, 'tblAppAnalyticsEvents')) {
        _migAAE_output("  [SKIP] tblAppAnalyticsEvents already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblAppAnalyticsEvents (
                Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                EventName       VARCHAR(64)     NOT NULL COMMENT 'app-level allow-listed event name, e.g. song_opened -- VARCHAR not ENUM per CLAUDE.md rule #20 (growable vocabulary)',
                ParametersJson  JSON            NULL COMMENT 'small, non-identifying event context -- flat string map, no PII, validated + length-capped before insert',
                ClientTimestamp DATETIME        NULL COMMENT 'client-reported event time (UTC), best-effort only -- never trusted for anything except display/ordering',
                Platform        VARCHAR(20)     NOT NULL DEFAULT 'unknown' COMMENT 'reporting client platform, e.g. apple | android | web',
                ReceivedAt      DATETIME        NOT NULL COMMENT 'server receipt time (UTC, set via UTC_TIMESTAMP() at insert) -- the trustworthy timestamp for querying/retention',

                INDEX idx_EventName_ReceivedAt (EventName, ReceivedAt),
                INDEX idx_ReceivedAt (ReceivedAt)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='First-party anonymous analytics events ingested from native app clients (#1448). Deliberately carries NO user/device identifier and NO IP address -- abuse mitigation is via per-IP request rate limiting on the ingest endpoint (tblLoginAttempts reuse via checkRateLimit()), not by retaining IP alongside the event.'"
        );
        _migAAE_output("  [OK] Created tblAppAnalyticsEvents.");
    }
    _migAAE_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migAAE_output("ERROR: " . $e->getMessage());
}

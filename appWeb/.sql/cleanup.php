<?php

declare(strict_types=1);

/**
 * iHymns — Token Cleanup CLI Script
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Deletes expired or used tokens from the database to keep tables clean.
 * Safe to run via cron on any schedule (e.g., daily).
 *
 * Tables cleaned:
 *   - tblApiTokens:          expired tokens
 *   - tblEmailLoginTokens:   expired or used tokens
 *   - tblPasswordResetTokens: expired or used tokens
 *   - tblLoginAttempts:      entries older than 30 days
 *   - tblActivityLog:        entries older than the retention window,
 *                            opt-in via tblAppSettings.activity_log_retention_days
 *                            (positive integer = retention in days,
 *                            1..3650). Default is 0 / unset = never
 *                            prune — audit, compliance, and forensics
 *                            all benefit from long retention.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/cleanup.php
 *   Cron: 0 3 * * * /usr/bin/php /path/to/appWeb/.sql/cleanup.php >> /var/log/ihymns-cleanup.log 2>&1
 *
 * @requires PHP 8.1+ with mysqli extension
 */

/* =========================================================================
 * ENVIRONMENT DETECTION
 * ========================================================================= */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    /* Standalone web mode only — skip when included by the Setup dashboard. */
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/* =========================================================================
 * BOOTSTRAP — Load database credentials and connection
 * ========================================================================= */

$credentialsFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credentialsFile)) {
    echo "ERROR: Database credentials not found at: $credentialsFile\n";
    echo "Run: php appWeb/.sql/install.php\n";
    return;
}

require_once $credentialsFile;

if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_NAME')) {
    echo "ERROR: Database credentials are incomplete.\n";
    return;
}

$port    = defined('DB_PORT') ? (int)DB_PORT : 3306;
$charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

/* Strict reporting (#525) — exceptions on every failed query so a
   broken DELETE doesn't silently no-op. Matches the migrate-*.php
   scripts in this directory. */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $port);
    if ($db->connect_errno) {
        echo "ERROR: Database connection failed: " . $db->connect_error . "\n";
        return;
    }
    $db->set_charset($charset);
} catch (\mysqli_sql_exception $e) {
    echo "ERROR: Database connection failed: " . $e->getMessage() . "\n";
    return;
}

/* =========================================================================
 * CLEANUP OPERATIONS
 * ========================================================================= */

$now = gmdate('Y-m-d H:i:s');
echo "iHymns Token Cleanup — " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 50) . "\n";

$totalDeleted = 0;

/* 1. Expired API tokens */
try {
    $stmt = $db->prepare('DELETE FROM tblApiTokens WHERE ExpiresAt < ?');
    $stmt->bind_param('s', $now);
    $stmt->execute();
    $count = $stmt->affected_rows;
    echo "tblApiTokens:          $count expired token(s) deleted\n";
    $totalDeleted += $count;
    $stmt->close();
} catch (\mysqli_sql_exception $e) {
    echo "tblApiTokens:          ERROR — " . $e->getMessage() . "\n";
}

/* 2. Expired or used email login tokens */
try {
    $stmt = $db->prepare('DELETE FROM tblEmailLoginTokens WHERE ExpiresAt < ? OR Used = 1');
    $stmt->bind_param('s', $now);
    $stmt->execute();
    $count = $stmt->affected_rows;
    echo "tblEmailLoginTokens:   $count expired/used token(s) deleted\n";
    $totalDeleted += $count;
    $stmt->close();
} catch (\mysqli_sql_exception $e) {
    echo "tblEmailLoginTokens:   ERROR — " . $e->getMessage() . "\n";
}

/* 3. Expired or used password reset tokens */
try {
    $stmt = $db->prepare('DELETE FROM tblPasswordResetTokens WHERE ExpiresAt < ? OR Used = 1');
    $stmt->bind_param('s', $now);
    $stmt->execute();
    $count = $stmt->affected_rows;
    echo "tblPasswordResetTokens: $count expired/used token(s) deleted\n";
    $totalDeleted += $count;
    $stmt->close();
} catch (\mysqli_sql_exception $e) {
    echo "tblPasswordResetTokens: ERROR — " . $e->getMessage() . "\n";
}

/* 4. Old login attempts (30+ days) */
try {
    $stmt = $db->prepare('DELETE FROM tblLoginAttempts WHERE AttemptedAt < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $stmt->execute();
    $count = $stmt->affected_rows;
    echo "tblLoginAttempts:      $count old attempt(s) deleted\n";
    $totalDeleted += $count;
    $stmt->close();
} catch (\mysqli_sql_exception $e) {
    echo "tblLoginAttempts:      ERROR — " . $e->getMessage() . "\n";
}

/* 5. tblActivityLog retention prune (#535).
   Pruning is OPT-IN — the default retention is 0 (unset), meaning
   activity rows are kept indefinitely. Audit, compliance, and
   forensics all benefit from long-term retention measured in years
   rather than weeks; admins who actively want to bound the table's
   growth set tblAppSettings.activity_log_retention_days to a
   positive integer (1..3650 days, capped so a fat-fingered value
   can't either no-op or wipe years of history in one go). */
try {
    $stmt = $db->prepare("SELECT SettingValue FROM tblAppSettings WHERE SettingKey = 'activity_log_retention_days'");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    $raw = (string)($row[0] ?? '');
    $retentionDays = $raw !== '' ? (int)$raw : 0;   /* 0 / unset = never prune */
    if ($retentionDays <= 0) {
        echo "tblActivityLog:        skipped (retention unset — pruning is opt-in)\n";
    } else {
        $retentionDays = max(1, min(3650, $retentionDays));
        $stmt = $db->prepare('DELETE FROM tblActivityLog WHERE CreatedAt < DATE_SUB(NOW(), INTERVAL ? DAY)');
        $stmt->bind_param('i', $retentionDays);
        $stmt->execute();
        $count = $stmt->affected_rows;
        echo "tblActivityLog:        $count row(s) older than {$retentionDays} day(s) deleted\n";
        $totalDeleted += $count;
        $stmt->close();
    }
} catch (\mysqli_sql_exception $e) {
    echo "tblActivityLog:        ERROR — " . $e->getMessage() . "\n";
}

echo str_repeat('-', 50) . "\n";
echo "Total deleted: $totalDeleted row(s)\n";
echo "Cleanup complete.\n";

$db->close();

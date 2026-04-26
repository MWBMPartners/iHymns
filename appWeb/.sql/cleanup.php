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
 *   - tblActivityLog:        entries older than the retention window
 *                            (configurable via tblAppSettings key
 *                            `activity_log_retention_days`, default 90)
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/cleanup.php
 *   Cron: 0 3 * * * /usr/bin/php /path/to/appWeb/.sql/cleanup.php >> /var/log/ihymns-cleanup.log 2>&1
 *
 * @requires PHP 8.1+ with pdo_mysql extension
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

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    DB_HOST, $port, DB_NAME, $charset
);

try {
    $db = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
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
    $stmt->execute([$now]);
    $count = $stmt->rowCount();
    echo "tblApiTokens:          $count expired token(s) deleted\n";
    $totalDeleted += $count;
} catch (PDOException $e) {
    echo "tblApiTokens:          ERROR — " . $e->getMessage() . "\n";
}

/* 2. Expired or used email login tokens */
try {
    $stmt = $db->prepare('DELETE FROM tblEmailLoginTokens WHERE ExpiresAt < ? OR Used = 1');
    $stmt->execute([$now]);
    $count = $stmt->rowCount();
    echo "tblEmailLoginTokens:   $count expired/used token(s) deleted\n";
    $totalDeleted += $count;
} catch (PDOException $e) {
    echo "tblEmailLoginTokens:   ERROR — " . $e->getMessage() . "\n";
}

/* 3. Expired or used password reset tokens */
try {
    $stmt = $db->prepare('DELETE FROM tblPasswordResetTokens WHERE ExpiresAt < ? OR Used = 1');
    $stmt->execute([$now]);
    $count = $stmt->rowCount();
    echo "tblPasswordResetTokens: $count expired/used token(s) deleted\n";
    $totalDeleted += $count;
} catch (PDOException $e) {
    echo "tblPasswordResetTokens: ERROR — " . $e->getMessage() . "\n";
}

/* 4. Old login attempts (30+ days) */
try {
    $stmt = $db->prepare('DELETE FROM tblLoginAttempts WHERE AttemptedAt < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "tblLoginAttempts:      $count old attempt(s) deleted\n";
    $totalDeleted += $count;
} catch (PDOException $e) {
    echo "tblLoginAttempts:      ERROR — " . $e->getMessage() . "\n";
}

/* 5. tblActivityLog retention prune (#535).
   Retention window is read from tblAppSettings::activity_log_retention_days
   (default 90 days). Set to 0 to disable pruning entirely — useful
   for forensics-heavy deployments that route long-term to a separate
   archive table. Capped at sensible bounds (1..3650) so a fat-fingered
   value can't either no-op or wipe years of history in one go. */
try {
    $stmt = $db->prepare("SELECT SettingValue FROM tblAppSettings WHERE SettingKey = 'activity_log_retention_days'");
    $stmt->execute();
    $raw = (string)($stmt->fetchColumn() ?: '');
    $retentionDays = $raw !== '' ? (int)$raw : 90;
    if ($retentionDays === 0) {
        echo "tblActivityLog:        skipped (retention = 0, pruning disabled)\n";
    } else {
        $retentionDays = max(1, min(3650, $retentionDays));
        $stmt = $db->prepare('DELETE FROM tblActivityLog WHERE CreatedAt < DATE_SUB(NOW(), INTERVAL ? DAY)');
        $stmt->execute([$retentionDays]);
        $count = $stmt->rowCount();
        echo "tblActivityLog:        $count row(s) older than {$retentionDays} day(s) deleted\n";
        $totalDeleted += $count;
    }
} catch (PDOException $e) {
    echo "tblActivityLog:        ERROR — " . $e->getMessage() . "\n";
}

echo str_repeat('-', 50) . "\n";
echo "Total deleted: $totalDeleted row(s)\n";
echo "Cleanup complete.\n";

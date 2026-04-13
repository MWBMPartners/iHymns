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

if (!$isCli) {
    http_response_code(403);
    echo 'This script must be run from the command line.';
    exit(1);
}

/* =========================================================================
 * BOOTSTRAP — Load database credentials and connection
 * ========================================================================= */

$credentialsFile = dirname(__DIR__) . '/.auth/db_credentials.php';
if (!file_exists($credentialsFile)) {
    echo "ERROR: Database credentials not found at: $credentialsFile\n";
    echo "Run: php appWeb/.sql/install.php\n";
    exit(1);
}

require_once $credentialsFile;

if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_NAME')) {
    echo "ERROR: Database credentials are incomplete.\n";
    exit(1);
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
    exit(1);
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

echo str_repeat('-', 50) . "\n";
echo "Total deleted: $totalDeleted row(s)\n";
echo "Cleanup complete.\n";

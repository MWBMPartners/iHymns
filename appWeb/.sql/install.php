<?php

declare(strict_types=1);

/**
 * iHymns — Database Installation Wizard
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Creates the MySQL database tables defined in schema.sql.
 * Uses MySQLi with prepared statements. Idempotent — safe to re-run.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/install.php
 *   Web:  Navigate to this file (protected by .htaccess or run from CLI only)
 *
 * PREREQUISITES:
 *   1. MySQL database must already exist
 *   2. appWeb/.auth/db_credentials.php must be configured
 *
 * @requires PHP 8.1+ with mysqli extension
 */

/* =========================================================================
 * ENVIRONMENT DETECTION
 * ========================================================================= */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
}

function output(string $message, bool $isCli = true): void
{
    if ($isCli) {
        echo $message . "\n";
    } else {
        echo $message . "<br>\n";
    }
}

/* =========================================================================
 * LOAD CREDENTIALS
 * ========================================================================= */

$credentialsFile = __DIR__ . '/../.auth/db_credentials.php';

if (!file_exists($credentialsFile)) {
    output("ERROR: Database credentials file not found.", $isCli);
    output("Expected: " . realpath(dirname($credentialsFile)) . '/db_credentials.php', $isCli);
    output("", $isCli);
    output("To set up:", $isCli);
    output("  1. Copy appWeb/.auth/db_credentials.example.php to appWeb/.auth/db_credentials.php", $isCli);
    output("  2. Edit db_credentials.php with your MySQL connection details", $isCli);
    exit(1);
}

require_once $credentialsFile;

/* Verify required constants are defined */
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $const) {
    if (!defined($const)) {
        output("ERROR: Required constant {$const} not defined in db_credentials.php", $isCli);
        exit(1);
    }
}

/* =========================================================================
 * CONNECT TO MYSQL
 * ========================================================================= */

output("=== iHymns Database Installer ===", $isCli);
output("", $isCli);
output("Connecting to MySQL at " . DB_HOST . ":" . DB_PORT . "...", $isCli);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
} catch (\mysqli_sql_exception $e) {
    output("ERROR: Failed to connect to MySQL: " . $e->getMessage(), $isCli);
    output("", $isCli);
    output("Check your credentials in appWeb/.auth/db_credentials.php", $isCli);
    output("Ensure the database '" . DB_NAME . "' exists.", $isCli);
    exit(1);
}

/* Set charset */
$charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
$mysqli->set_charset($charset);

output("Connected successfully to database: " . DB_NAME, $isCli);
output("", $isCli);

/* =========================================================================
 * RUN SCHEMA
 * ========================================================================= */

$schemaFile = __DIR__ . '/schema.sql';

if (!file_exists($schemaFile)) {
    output("ERROR: Schema file not found: " . $schemaFile, $isCli);
    $mysqli->close();
    exit(1);
}

$schemaSql = file_get_contents($schemaFile);
if ($schemaSql === false) {
    output("ERROR: Failed to read schema file.", $isCli);
    $mysqli->close();
    exit(1);
}

/* Split into individual statements (skip comments and empty lines) */
$statements = [];
$current = '';
foreach (explode("\n", $schemaSql) as $line) {
    $trimmed = trim($line);
    /* Skip comment-only lines and blank lines */
    if ($trimmed === '' || str_starts_with($trimmed, '--')) {
        continue;
    }
    $current .= $line . "\n";
    /* Statement ends with semicolon */
    if (str_ends_with($trimmed, ';')) {
        $statements[] = trim($current);
        $current = '';
    }
}

output("Running schema (" . count($statements) . " statements)...", $isCli);
output("", $isCli);

$success = 0;
$skipped = 0;
$errors  = 0;

foreach ($statements as $i => $sql) {
    /* Extract table name from CREATE TABLE for reporting */
    $tableName = '(unknown)';
    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i', $sql, $m)) {
        $tableName = $m[1];
    }

    try {
        $mysqli->multi_query($sql);
        /* Consume all results from multi_query */
        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->next_result());

        output("  [OK] Table: {$tableName}", $isCli);
        $success++;
    } catch (\mysqli_sql_exception $e) {
        if (str_contains($e->getMessage(), 'already exists')) {
            output("  [SKIP] Table: {$tableName} (already exists)", $isCli);
            $skipped++;
        } else {
            output("  [FAIL] Table: {$tableName} — " . $e->getMessage(), $isCli);
            $errors++;
        }
    }
}

output("", $isCli);
output("--- Summary ---", $isCli);
output("  Created: {$success}", $isCli);
output("  Skipped: {$skipped} (already exist)", $isCli);
output("  Errors:  {$errors}", $isCli);
output("", $isCli);

if ($errors > 0) {
    output("WARNING: Some tables failed to create. Check errors above.", $isCli);
    $mysqli->close();
    exit(1);
}

output("Database installation complete.", $isCli);

$mysqli->close();
exit(0);

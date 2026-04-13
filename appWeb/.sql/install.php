<?php

declare(strict_types=1);

/**
 * iHymns — Database Installation Wizard
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Interactive installer that:
 *   1. Prompts for MySQL credentials (CLI only)
 *   2. Writes them to appWeb/.auth/db_credentials.php
 *   3. Creates all database tables from schema.sql
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/install.php
 *
 * If credentials already exist in appWeb/.auth/db_credentials.php,
 * the installer skips the credential prompt and proceeds to schema setup.
 *
 * NOTE: Interactive credential input requires a CLI environment with
 * readline or standard input. If not available (e.g. non-interactive
 * shell, web server), configure credentials manually by copying
 * appWeb/.auth/db_credentials.example.php to db_credentials.php.
 *
 * @requires PHP 8.1+ with mysqli extension
 */

/* =========================================================================
 * ENVIRONMENT DETECTION
 * ========================================================================= */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    /* Web mode — works on shared hosting without CLI access (e.g., DreamHost) */
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function output(string $message): void
{
    global $isCli;
    if (!$isCli) { echo $message . "<br>\n"; flush(); return; }
    echo $message . "\n";
}

/**
 * Prompt the user for input on the CLI.
 * Returns the trimmed input, or the default if input is empty.
 */
function prompt(string $label, string $default = ''): string
{
    $defaultHint = $default !== '' ? " [{$default}]" : '';
    echo "{$label}{$defaultHint}: ";
    $input = trim(fgets(STDIN) ?: '');
    return $input !== '' ? $input : $default;
}

/**
 * Prompt for a password (attempts to hide input on supported terminals).
 */
function promptPassword(string $label): string
{
    /* Try to disable echo on Unix terminals */
    $sttySupported = false;
    if (function_exists('shell_exec') && PHP_OS_FAMILY !== 'Windows') {
        $oldStty = shell_exec('stty -g 2>/dev/null');
        if ($oldStty !== null) {
            shell_exec('stty -echo 2>/dev/null');
            $sttySupported = true;
        }
    }

    echo "{$label}: ";
    $input = trim(fgets(STDIN) ?: '');

    if ($sttySupported) {
        shell_exec('stty ' . trim($oldStty) . ' 2>/dev/null');
        echo "\n"; /* New line after hidden input */
    }

    return $input;
}

/* =========================================================================
 * HEADER
 * ========================================================================= */

output("");
output("╔══════════════════════════════════════════════════════════╗");
output("║           iHymns — Database Installation Wizard         ║");
output("╚══════════════════════════════════════════════════════════╝");
output("");

/* =========================================================================
 * CHECK FOR EXISTING CREDENTIALS
 * ========================================================================= */

$credentialsFile = __DIR__ . '/../.auth/db_credentials.php';
$credentialsDir  = dirname($credentialsFile);
$hasExistingCreds = file_exists($credentialsFile);

if ($hasExistingCreds) {
    output("Found existing credentials: " . realpath($credentialsFile));
    $useExisting = strtolower(prompt("Use existing credentials? (y/n)", "y"));
    if ($useExisting === 'n' || $useExisting === 'no') {
        $hasExistingCreds = false;
    }
}

/* =========================================================================
 * CREDENTIAL INPUT (interactive)
 * ========================================================================= */

if (!$hasExistingCreds) {
    /* Check if we can read from STDIN interactively */
    if (!defined('STDIN') || !is_resource(STDIN)) {
        output("ERROR: Cannot read interactive input.");
        output("");
        output("To configure credentials manually:");
        output("  1. Copy appWeb/.auth/db_credentials.example.php");
        output("     to   appWeb/.auth/db_credentials.php");
        output("  2. Edit db_credentials.php with your MySQL details");
        output("  3. Re-run this installer");
        exit(1);
    }

    /* Check SSL support */
    $hasSSL = extension_loaded('mysqlnd') || extension_loaded('openssl');
    if (!$hasSSL) {
        output("NOTE: Your PHP installation does not have SSL support for MySQL.");
        output("      SSL connection options will not be available.");
        output("      If you need SSL, install the openssl PHP extension and re-run.");
        output("");
    }

    output("Enter your MySQL database credentials below.");
    output("Press Enter to accept the default value shown in [brackets].");
    output("");

    $dbHost    = prompt("  MySQL Host",     '127.0.0.1');
    $dbPort    = prompt("  MySQL Port",     '3306');
    $dbName    = prompt("  Database Name",  'ihymns');
    $dbUser    = prompt("  Username",       'ihymns_user');
    $dbPass    = promptPassword("  Password");
    $dbPrefix  = prompt("  Table Prefix (optional, leave blank for none)", '');
    $dbCharset = 'utf8mb4'; /* Always utf8mb4, not user-configurable */

    /* Validate port is numeric */
    if (!is_numeric($dbPort)) {
        output("ERROR: Port must be a number. Got: {$dbPort}");
        exit(1);
    }

    /* Sanitise table prefix (alphanumeric + underscore only) */
    if ($dbPrefix !== '') {
        $dbPrefix = preg_replace('/[^a-zA-Z0-9_]/', '', $dbPrefix);
        if (!str_ends_with($dbPrefix, '_')) {
            $dbPrefix .= '_';
        }
    }

    output("");
    output("  Connection:  {$dbUser}@{$dbHost}:{$dbPort}/{$dbName}");
    if ($dbPrefix !== '') {
        output("  Table Prefix: {$dbPrefix}");
    }
    output("");

    /* Test the connection before writing */
    output("Testing connection...");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $testConn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int)$dbPort);
        $testConn->close();
        output("  Connection successful!");
    } catch (\mysqli_sql_exception $e) {
        output("  ERROR: Connection failed — " . $e->getMessage());
        output("");
        output("  Please check your credentials and ensure:");
        output("    - MySQL server is running at {$dbHost}:{$dbPort}");
        output("    - Database '{$dbName}' exists");
        output("    - User '{$dbUser}' has access to '{$dbName}'");
        output("");
        output("  To create the database:");
        output("    mysql -u root -p -e \"CREATE DATABASE {$dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"");
        exit(1);
    }

    /* Write credentials file */
    output("");
    output("Writing credentials to: " . $credentialsDir . '/db_credentials.php');

    if (!is_dir($credentialsDir)) {
        mkdir($credentialsDir, 0755, true);
    }

    $escapedPass = addslashes($dbPass);
    $prefixLine = $dbPrefix !== ''
        ? "define('DB_PREFIX',  '{$dbPrefix}');"
        : "define('DB_PREFIX',  '');";

    $credentialsContent = <<<PHP
<?php

/**
 * iHymns — MySQL Database Credentials
 *
 * Generated by the iHymns Database Installation Wizard.
 * This file is excluded from version control via .gitignore.
 */

define('DB_HOST',    '{$dbHost}');
define('DB_PORT',    {$dbPort});
define('DB_NAME',    '{$dbName}');
define('DB_USER',    '{$dbUser}');
define('DB_PASS',    '{$escapedPass}');
define('DB_CHARSET', '{$dbCharset}');
{$prefixLine}
PHP;

    $written = file_put_contents($credentialsFile, $credentialsContent, LOCK_EX);
    if ($written === false) {
        output("ERROR: Failed to write credentials file.");
        output("Check write permissions on: {$credentialsDir}");
        exit(1);
    }
    /* Restrict file permissions (owner read/write only) */
    chmod($credentialsFile, 0600);
    output("  Credentials saved successfully.");
    output("");
}

/* =========================================================================
 * LOAD CREDENTIALS
 * ========================================================================= */

require_once $credentialsFile;

/* Verify required constants are defined */
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $const) {
    if (!defined($const)) {
        output("ERROR: Required constant {$const} not defined in db_credentials.php");
        exit(1);
    }
}

$tablePrefix = defined('DB_PREFIX') ? DB_PREFIX : '';

/* =========================================================================
 * CONNECT TO MYSQL
 * ========================================================================= */

output("Connecting to MySQL at " . DB_HOST . ":" . DB_PORT . "...");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
} catch (\mysqli_sql_exception $e) {
    output("ERROR: Failed to connect to MySQL: " . $e->getMessage());
    output("");
    output("Check your credentials in: " . realpath($credentialsFile));
    exit(1);
}

$charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
$mysqli->set_charset($charset);

output("Connected successfully to database: " . DB_NAME);
output("");

/* =========================================================================
 * RUN SCHEMA
 * ========================================================================= */

$schemaFile = __DIR__ . '/schema.sql';

if (!file_exists($schemaFile)) {
    output("ERROR: Schema file not found: " . $schemaFile);
    $mysqli->close();
    exit(1);
}

$schemaSql = file_get_contents($schemaFile);
if ($schemaSql === false) {
    output("ERROR: Failed to read schema file.");
    $mysqli->close();
    exit(1);
}

/* Apply table prefix if configured.
 * The prefix is inserted after 'tbl', e.g., tblSongs → tbl<prefix>Songs.
 * This allows multiple iHymns instances to share a single database. */
if ($tablePrefix !== '') {
    $schemaSql = preg_replace('/\btbl([A-Z])/', 'tbl' . $tablePrefix . '$1', $schemaSql);
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

output("Running schema (" . count($statements) . " statements)...");
output("");

$success = 0;
$skipped = 0;
$errors  = 0;

foreach ($statements as $i => $sql) {
    /* Extract table name for reporting */
    $objectName = '(statement ' . ($i + 1) . ')';
    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\S+)/i', $sql, $m)) {
        $objectName = 'Table: ' . $m[1];
    } elseif (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+(\S+)/i', $sql, $m)) {
        $objectName = 'Seed:  ' . $m[1];
    }

    try {
        $mysqli->multi_query($sql);
        /* Consume all results from multi_query */
        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->next_result());

        output("  [OK]   {$objectName}");
        $success++;
    } catch (\mysqli_sql_exception $e) {
        if (str_contains($e->getMessage(), 'already exists')) {
            output("  [SKIP] {$objectName} (already exists)");
            $skipped++;
        } else {
            output("  [FAIL] {$objectName} — " . $e->getMessage());
            $errors++;
        }
    }
}

output("");
output("─── Summary ───");
output("  Created:  {$success}");
output("  Skipped:  {$skipped} (already exist)");
output("  Errors:   {$errors}");
output("");

if ($errors > 0) {
    output("WARNING: Some operations failed. Check errors above.");
    $mysqli->close();
    exit(1);
}

output("Database installation complete.");
output("");
output("Next steps:");
output("  1. Import song data:  php appWeb/.sql/migrate-json.php");
output("  2. Set up admin:      Visit /manage/setup in your browser");

$mysqli->close();
exit(0);

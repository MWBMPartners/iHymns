<?php

declare(strict_types=1);

/**
 * iHymns — Drop Legacy Tables
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Drops any tables in the iHymns database that are NOT part of the
 * current schema.sql. The set of "current" tables is parsed from the
 * live schema.sql at runtime so this script never goes stale.
 *
 * Useful after importing an existing MySQL database that still holds
 * tables from a previous iHymns incarnation (e.g. tblHymnals, tblHymns,
 * tblComposers, tblPersons, tblRespReadings, tblTunes).
 *
 * USAGE:
 *   CLI preview:  php appWeb/.sql/drop-legacy-tables.php
 *   CLI execute:  php appWeb/.sql/drop-legacy-tables.php --confirm
 *   Web execute:  /manage/setup-database.php?action=drop-legacy&confirm=1
 *
 * Without --confirm / ?confirm=1 the script lists what WOULD be dropped
 * and exits without touching the database.
 *
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* =========================================================================
 * LOAD CREDENTIALS
 * ========================================================================= */

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    output("ERROR: Credentials file not found: {$credFile}");
    return;
}
require_once $credFile;

$tablePrefix = defined('DB_PREFIX') ? DB_PREFIX : '';

/* =========================================================================
 * PARSE schema.sql TO BUILD THE KNOWN-TABLE SET
 * ========================================================================= */

$schemaFile = __DIR__ . DIRECTORY_SEPARATOR . 'schema.sql';
if (!file_exists($schemaFile)) {
    output("ERROR: schema.sql not found: {$schemaFile}");
    return;
}

$schemaSql = (string)file_get_contents($schemaFile);
preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(tbl\w+)`?/i', $schemaSql, $m);
$knownNames = array_unique($m[1] ?? []);

if ($tablePrefix !== '') {
    /* Apply the same prefix that install.php injects: tbl<Name> → tbl<prefix><Name> */
    $knownNames = array_map(
        fn($t) => preg_replace('/^tbl([A-Z])/', 'tbl' . $tablePrefix . '$1', $t),
        $knownNames
    );
}

if (empty($knownNames)) {
    output("ERROR: Could not parse any table names from schema.sql.");
    return;
}

$known = array_flip($knownNames);

/* =========================================================================
 * CONNECT
 * ========================================================================= */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\Throwable $e) {
    output("ERROR: Connection failed: " . $e->getMessage());
    return;
}

/* =========================================================================
 * FIND LEGACY TABLES
 * ========================================================================= */

$legacy = [];
$result = $mysql->query("SHOW TABLES");
while ($row = $result->fetch_array()) {
    $name = $row[0];
    if (!isset($known[$name])) {
        $legacy[] = $name;
    }
}
$result->free();

if (empty($legacy)) {
    output("No legacy tables found. The database contains only schema tables.");
    $mysql->close();
    return;
}

output("Found " . count($legacy) . " legacy table(s) not in schema.sql:");
output("");

$rowTotals = [];
foreach ($legacy as $t) {
    try {
        $escaped = $mysql->real_escape_string($t);
        $cr = $mysql->query("SELECT COUNT(*) AS c FROM `{$escaped}`");
        $count = $cr ? (int)$cr->fetch_assoc()['c'] : 0;
    } catch (\Throwable $e) {
        $count = -1;
    }
    $rowTotals[$t] = $count;
    $label = $count === -1 ? '?' : number_format($count);
    output("  - {$t} ({$label} rows)");
}
output("");

/* =========================================================================
 * CONFIRM GATE
 * ========================================================================= */

$confirmed = false;
if ($isCli) {
    foreach ($argv ?? [] as $arg) {
        if ($arg === '--confirm') $confirmed = true;
    }
} else {
    $confirmed = (($_GET['confirm'] ?? '') === '1');
}

if (!$confirmed) {
    if ($isCli) {
        output("DRY RUN. Re-run with --confirm to drop the tables listed above.");
    } else {
        output("DRY RUN. Re-run with '?action=drop-legacy&confirm=1' to drop these tables.");
    }
    $mysql->close();
    return;
}

/* =========================================================================
 * DROP
 * ========================================================================= */

output("Dropping " . count($legacy) . " table(s)...");
output("");

$mysql->query("SET FOREIGN_KEY_CHECKS = 0");

$dropped = 0;
$errors  = 0;

foreach ($legacy as $t) {
    try {
        $mysql->query("DROP TABLE `" . $mysql->real_escape_string($t) . "`");
        output("  [OK]   Dropped {$t}");
        $dropped++;
    } catch (\Throwable $e) {
        output("  [FAIL] {$t} — " . $e->getMessage());
        $errors++;
    }
}

$mysql->query("SET FOREIGN_KEY_CHECKS = 1");

output("");
output("─── Summary ───");
output("  Dropped: {$dropped}");
output("  Errors:  {$errors}");

$mysql->close();
return;

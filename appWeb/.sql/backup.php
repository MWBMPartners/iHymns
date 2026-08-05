<?php

declare(strict_types=1);

/**
 * iHymns — Database Backup Script (#322)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Creates a timestamped SQL dump of the MySQL database.
 * Works in both CLI and web mode (via /manage/setup-database.php).
 *
 * OUTPUT:
 *   appWeb/data_share/backups/ihymns-backup-YYYYMMDD-HHMMSS.sql.gz
 *
 * RETENTION:
 *   Keeps the last 7 daily backups. Older files are automatically deleted.
 *
 * MEMORY / DISK SAFETY (#1771)
 * ----------------------------------------------------------------------------
 * The original version issued a BUFFERED `SELECT * FROM <table>` per table
 * (mysqli's default `query()`), which pulls the WHOLE table into PHP memory at
 * once. On a real corpus (~14k `tblSongs` rows carrying MEDIUMTEXT lyrics, plus
 * `tblSongEmbeddings`), that is hundreds of MB and OOM-fatals under a shared
 * host's `memory_limit` — the fatal happens INSIDE the buffered call before the
 * per-table line prints, so the operator saw "Interrupted" with empty output
 * (#1771). It also wrote a full uncompressed `.sql` to disk before gzipping,
 * which can hit "no space left" on a disk-constrained host.
 *
 * This version:
 *   - streams rows UNBUFFERED (`MYSQLI_USE_RESULT`) — constant memory per table,
 *     freeing each result before the next table's queries;
 *   - batches rows into multi-row INSERTs (bounded by row count AND byte size,
 *     so a single statement never approaches `max_allowed_packet`);
 *   - writes STRAIGHT into the gzip stream (no giant uncompressed intermediate).
 *
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (php_sapi_name() === 'cli');
if ($isCli) { @set_time_limit(0); }   /* the dashboard also lifts this; belt + braces for CLI/cron */

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    /* Standalone web mode only — skip when included by the Setup dashboard. */
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
}

function output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* Load credentials */
$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    output("ERROR: Database credentials not found.");
    return;
}
require_once $credFile;

/* Create backup directory */
$backupDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

/* Add .htaccess to block web access to backups */
$htaccessFile = $backupDir . '/.htaccess';
if (!file_exists($htaccessFile)) {
    file_put_contents($htaccessFile, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n");
}

output("=== iHymns Database Backup ===");
output("");

$timestamp = date('Ymd-His');

/* Connect to MySQL. STRICT reporting so a mid-dump query error THROWS into the
   try/catch below (a clear message + a cleaned-up partial file) rather than
   silently truncating the backup. */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) {
    output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}

output("Connected to: " . DB_NAME);

/* Write STRAIGHT into the gzip stream when available — no giant uncompressed
   intermediate on disk (a real failure mode on a disk-constrained shared host).
   $writer() and $closer() abstract gz vs plain so the dump loop is identical. */
$useGz     = function_exists('gzopen');
$filename  = "ihymns-backup-{$timestamp}.sql" . ($useGz ? '.gz' : '');
$filepath  = $backupDir . '/' . $filename;
$handle    = $useGz ? gzopen($filepath, 'wb6') : fopen($filepath, 'w');
if (!$handle) {
    output("ERROR: Cannot create backup file (check data_share/backups/ is writable + has free space).");
    $mysql->close();
    return;
}
$writer = $useGz ? static fn(string $s) => gzwrite($handle, $s) : static fn(string $s) => fwrite($handle, $s);
$closer = $useGz ? static function () use ($handle) { gzclose($handle); } : static function () use ($handle) { fclose($handle); };

output("Backup file: " . $filename);
output("");

$totalRows   = 0;
$tableCount  = 0;
$backupOk    = false;

try {
    /* Enumerate objects first (small buffered result, freed before streaming).
       SHOW FULL TABLES carries Table_type so VIEWs are handled correctly — a
       view has no own data and must be emitted as CREATE VIEW, not dumped as
       INSERTs into a (non-existent) table (the #1771 view-safety fix; the old
       version emitted DROP TABLE + empty DDL + INSERTs for v_ChristianSongs,
       which no restore could apply). Views sort after `tbl…` alphabetically, so
       their base tables already exist by the time a view is created. */
    $tables = [];   /* [name => isView] */
    $tblRes = $mysql->query("SHOW FULL TABLES");
    while ($row = $tblRes->fetch_array(MYSQLI_NUM)) {
        $tables[$row[0]] = (strtoupper((string)($row[1] ?? '')) === 'VIEW');
    }
    $tblRes->free();
    output("Objects to backup: " . count($tables));

    $writer("-- iHymns Database Backup\n");
    $writer("-- Generated: " . date('c') . "\n");
    $writer("-- Database: " . DB_NAME . "\n\n");
    $writer("SET NAMES utf8mb4;\n");
    $writer("SET FOREIGN_KEY_CHECKS = 0;\n\n");

    /* Cap a single multi-row INSERT so it never approaches max_allowed_packet
       (default 4–16 MB); flush on EITHER limit. */
    $BATCH_ROWS  = 200;
    $BATCH_BYTES = 768 * 1024;

    /* BASE TABLES first, then VIEWS — a view's CREATE references its base
       tables, so every base table must exist before any view is created. Name
       order alone is NOT enough: the compat view `tblCreditPeople` sorts BEFORE
       its base `tblMusicians` (#1741 rename), so an alphabetical single pass
       would try to create the view before the table (broken restore, #1771). */
    $baseTables = array_keys(array_filter($tables, static fn($isView) => !$isView));
    $viewNames  = array_keys(array_filter($tables, static fn($isView) => $isView));

    foreach ($baseTables as $table) {
        /* Schema first — a small buffered result, fetched + freed before the
           UNBUFFERED data stream opens (an open unbuffered result blocks any
           other query on the connection). */
        $createResult = $mysql->query("SHOW CREATE TABLE `{$table}`");
        $createRow    = $createResult->fetch_assoc();
        $createResult->free();

        $createSql = $createRow['Create Table'] ?? '';
        $writer("-- Table: {$table}\n");
        $writer("DROP TABLE IF EXISTS `{$table}`;\n");
        $writer($createSql . ";\n\n");

        /* Data — UNBUFFERED so rows stream one at a time (constant memory). */
        $dataResult = $mysql->query("SELECT * FROM `{$table}`", MYSQLI_USE_RESULT);
        $rowCount   = 0;
        $batch      = [];        /* pending "(v1, v2, …)" row tuples */
        $batchBytes = 0;
        $colPrefix  = null;      /* "INSERT INTO `t` (`a`,`b`) VALUES " — built once */

        $flush = function () use (&$batch, &$batchBytes, &$colPrefix, $writer) {
            if (!$batch) { return; }
            $writer($colPrefix . implode(",\n", $batch) . ";\n");
            $batch = [];
            $batchBytes = 0;
        };

        while ($row = $dataResult->fetch_assoc()) {
            if ($colPrefix === null) {
                $cols = array_map(static fn($c) => "`{$c}`", array_keys($row));
                $colPrefix = "INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES\n";
            }
            $values = array_map(static function ($val) use ($mysql) {
                return $val === null ? 'NULL' : "'" . $mysql->real_escape_string((string)$val) . "'";
            }, $row);
            $tuple = '(' . implode(', ', $values) . ')';
            $batch[]     = $tuple;
            $batchBytes += strlen($tuple) + 2;
            $rowCount++;
            if (count($batch) >= $BATCH_ROWS || $batchBytes >= $BATCH_BYTES) {
                $flush();
            }
        }
        $flush();
        $dataResult->free();     /* MUST free the unbuffered result before the next table's query */
        if ($rowCount > 0) { $writer("\n"); }

        $totalRows += $rowCount;
        $tableCount++;
        output("  {$table}: {$rowCount} rows");
    }

    /* VIEWS last — DDL only (a view has no own data). SHOW CREATE TABLE returns
       a 'Create View' key for a view. */
    foreach ($viewNames as $view) {
        $createResult = $mysql->query("SHOW CREATE TABLE `{$view}`");
        $createRow    = $createResult->fetch_assoc();
        $createResult->free();
        $createSql    = $createRow['Create View'] ?? '';
        $writer("-- View: {$view}\n");
        $writer("DROP VIEW IF EXISTS `{$view}`;\n");
        if ($createSql !== '') { $writer($createSql . ";\n\n"); }
        output("  {$view}: (view)");
    }

    $writer("SET FOREIGN_KEY_CHECKS = 1;\n");
    $backupOk = true;
} catch (\Throwable $e) {
    output("ERROR: backup failed on a table read — " . $e->getMessage());
} finally {
    $closer();
    $mysql->close();
}

if (!$backupOk) {
    /* Never leave a truncated file masquerading as a good backup. */
    if (is_file($filepath)) { @unlink($filepath); }
    output("");
    output("--- Backup ABORTED (partial file removed) ---");
    return;
}

$finalFile = $filepath;
if ($useGz) { output(""); output("Compressed: " . basename($filepath)); }

$fileSize = round(filesize($finalFile) / 1024, 1);
output("");
output("--- Backup Complete ---");
output("  Tables: " . $tableCount . (count($viewNames) ? " (+ " . count($viewNames) . " view" . (count($viewNames) === 1 ? '' : 's') . ")" : ''));
output("  Rows:   " . number_format($totalRows));
output("  Size:   {$fileSize} KB");

/* Retention: keep last 7 backups */
$backups = glob($backupDir . '/ihymns-backup-*');
usort($backups, fn($a, $b) => filemtime($b) - filemtime($a));
$deleted = 0;
foreach (array_slice($backups, 7) as $old) {
    unlink($old);
    $deleted++;
}
if ($deleted > 0) {
    output("  Cleaned: {$deleted} old backup(s) removed");
}

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
 * @requires PHP 8.1+ with mysqli extension
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
}

function output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* Load credentials */
$credFile = __DIR__ . DIRECTORY_SEPARATOR . '../.auth/db_credentials.php';
if (!file_exists($credFile)) {
    output("ERROR: Database credentials not found.");
    return;
}
require_once $credFile;

/* Create backup directory */
$backupDir = __DIR__ . DIRECTORY_SEPARATOR . '../data_share/backups';
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
$filename = "ihymns-backup-{$timestamp}.sql";
$filepath = $backupDir . '/' . $filename;

/* Connect to MySQL */
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) {
    output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}

output("Connected to: " . DB_NAME);
output("Backup file: " . $filename);
output("");

/* Get all tables */
$result = $mysql->query("SHOW TABLES");
$tables = [];
while ($row = $result->fetch_array()) {
    $tables[] = $row[0];
}
output("Tables to backup: " . count($tables));

/* Generate SQL dump */
$out = fopen($filepath, 'w');
if (!$out) {
    output("ERROR: Cannot create backup file.");
    $mysql->close();
    return;
}

fwrite($out, "-- iHymns Database Backup\n");
fwrite($out, "-- Generated: " . date('c') . "\n");
fwrite($out, "-- Database: " . DB_NAME . "\n\n");
fwrite($out, "SET NAMES utf8mb4;\n");
fwrite($out, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

$totalRows = 0;

foreach ($tables as $table) {
    /* Get CREATE TABLE statement */
    $createResult = $mysql->query("SHOW CREATE TABLE `{$table}`");
    $createRow = $createResult->fetch_assoc();
    $createSql = $createRow['Create Table'] ?? '';

    fwrite($out, "-- Table: {$table}\n");
    fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n");
    fwrite($out, $createSql . ";\n\n");

    /* Get data */
    $dataResult = $mysql->query("SELECT * FROM `{$table}`");
    $rowCount = 0;

    while ($row = $dataResult->fetch_assoc()) {
        $values = array_map(function ($val) use ($mysql) {
            if ($val === null) return 'NULL';
            return "'" . $mysql->real_escape_string($val) . "'";
        }, $row);

        $cols = array_map(fn($c) => "`{$c}`", array_keys($row));

        fwrite($out, "INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $values) . ");\n");
        $rowCount++;
    }

    if ($rowCount > 0) {
        fwrite($out, "\n");
    }
    $totalRows += $rowCount;
    output("  {$table}: {$rowCount} rows");
}

fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
fclose($out);
$mysql->close();

/* Compress with gzip if available */
$gzFile = $filepath . '.gz';
if (function_exists('gzopen')) {
    $gz = gzopen($gzFile, 'wb9');
    $raw = fopen($filepath, 'rb');
    while (!feof($raw)) {
        gzwrite($gz, fread($raw, 8192));
    }
    fclose($raw);
    gzclose($gz);
    unlink($filepath); /* Remove uncompressed version */
    $finalFile = $gzFile;
    output("");
    output("Compressed: " . basename($gzFile));
} else {
    $finalFile = $filepath;
}

$fileSize = round(filesize($finalFile) / 1024, 1);
output("");
output("--- Backup Complete ---");
output("  Tables: " . count($tables));
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

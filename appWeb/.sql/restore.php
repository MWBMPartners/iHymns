<?php

declare(strict_types=1);

/**
 * iHymns — Database Restore (#405)
 *
 * Restores the database from a compressed SQL dump produced by
 * appWeb/.sql/backup.php. Destructive — replaces every table in the
 * schema. Requires confirm=1 (web) or --confirm (CLI) AND a backup
 * filename (basename only; the directory is fixed to the backup dir
 * for safety).
 *
 * USAGE:
 *   CLI:   php appWeb/.sql/restore.php --file=ihymns-backup-20260418-102030.sql.gz --confirm
 *   Web:   /manage/setup-database.php?action=restore&file=<name>&confirm=1
 *
 * @requires PHP 8.1+, mysqli, zlib
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

/* ---- Load credentials ---- */
$credentialsFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credentialsFile)) {
    output('ERROR: Credentials file not found.');
    return;
}
require_once $credentialsFile;

/* ---- Resolve source file ---- */
$backupDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'backups';

$requestedFile = '';
if ($isCli) {
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--file=')) $requestedFile = substr($arg, 7);
    }
} else {
    $requestedFile = (string)($_GET['file'] ?? '');
}

/* Strip any path component — filename only; keep restore scoped to the
   backup directory. A basename check also blocks "../" traversal. */
$requestedFile = basename(trim($requestedFile));
if ($requestedFile === '' || !preg_match('/^ihymns-backup-[0-9-]+\.sql(?:\.gz)?$/', $requestedFile)) {
    output('ERROR: No (or invalid) backup filename specified.');
    output('Expected form: ihymns-backup-YYYYMMDD-HHMMSS.sql(.gz)');
    return;
}

$source = $backupDir . DIRECTORY_SEPARATOR . $requestedFile;
if (!file_exists($source)) {
    output('ERROR: Backup file not found: ' . $requestedFile);
    return;
}

/* ---- Confirm gate ---- */
$confirmed = false;
if ($isCli) {
    foreach ($argv ?? [] as $arg) {
        if ($arg === '--confirm') $confirmed = true;
    }
} else {
    $confirmed = (($_GET['confirm'] ?? '') === '1');
}

$size = filesize($source);
output('Source: ' . $requestedFile . ' (' . number_format((int)$size) . ' bytes)');
output('');

if (!$confirmed) {
    output('DRY RUN — nothing has been restored.');
    output('Re-run with --confirm (CLI) or ?confirm=1 (web) to proceed.');
    return;
}

/* ---- Read + decompress ---- */
output('Reading backup…');
$content = (str_ends_with($requestedFile, '.gz'))
    ? gzdecode((string)file_get_contents($source))
    : (string)file_get_contents($source);

if ($content === false || $content === '') {
    output('ERROR: Could not read or decompress ' . $requestedFile);
    return;
}

/* ---- Connect + execute ---- */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\Throwable $e) {
    output('ERROR: Connection failed: ' . $e->getMessage());
    return;
}

output('Running restore… (this may take a moment)');
output('');

$mysql->query('SET FOREIGN_KEY_CHECKS = 0');

/* multi_query walks every statement in the dump. */
try {
    if (!$mysql->multi_query($content)) {
        throw new \RuntimeException($mysql->error);
    }
    /* Consume every result set so the connection is ready. */
    do {
        if ($result = $mysql->store_result()) {
            $result->free();
        }
    } while ($mysql->more_results() && $mysql->next_result());
    output('  [OK] restore complete');
} catch (\Throwable $e) {
    output('  [FAIL] ' . $e->getMessage());
}

$mysql->query('SET FOREIGN_KEY_CHECKS = 1');

output('');
output('Re-check /manage/setup-database to verify table counts.');

$mysql->close();
return;

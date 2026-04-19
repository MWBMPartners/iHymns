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

/* ---- Mode flags (confirm / preflight / skip-snapshot) ----
 * preflight: parse the dump and print a summary without touching the DB.
 * confirm:   actually run the restore (ignored without confirm flag).
 * skip-snapshot: opt out of the automatic pre-restore backup (default on).
 */
$confirmed = false;
$preflight = false;
$skipSnapshot = false;
if ($isCli) {
    foreach ($argv ?? [] as $arg) {
        if ($arg === '--confirm') $confirmed = true;
        if ($arg === '--preflight') $preflight = true;
        if ($arg === '--skip-snapshot') $skipSnapshot = true;
    }
} else {
    $confirmed    = (($_GET['confirm']       ?? '') === '1');
    $preflight    = (($_GET['preflight']     ?? '') === '1');
    $skipSnapshot = (($_GET['skip_snapshot'] ?? '') === '1');
}

$size = filesize($source);
output('Source: ' . $requestedFile . ' (' . number_format((int)$size) . ' bytes)');
output('');

/* ---- Read + decompress ---- */
output('Reading backup…');
$content = (str_ends_with($requestedFile, '.gz'))
    ? gzdecode((string)file_get_contents($source))
    : (string)file_get_contents($source);

if ($content === false || $content === '') {
    output('ERROR: Could not read or decompress ' . $requestedFile);
    return;
}

/* ---- Pre-flight summary — counts + timestamp, no side effects ---- */
$stats = [
    'createTables' => 0,
    'dropTables'   => 0,
    'inserts'      => 0,
    'insertRows'   => 0,
    'tables'       => [],
    'takenAt'      => '',
];
foreach (explode("\n", $content) as $line) {
    $trim = ltrim($line);
    if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) {
        if (preg_match('/taken at[:\s]+(.+?)\s*$/i', $trim, $m)) {
            $stats['takenAt'] = trim($m[1]);
        }
        continue;
    }
    $upper = strtoupper(substr($trim, 0, 30));
    if (str_starts_with($upper, 'CREATE TABLE')) {
        $stats['createTables']++;
        if (preg_match('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?([A-Za-z0-9_]+)`?/i', $trim, $m)) {
            $stats['tables'][$m[1]] = ($stats['tables'][$m[1]] ?? 0);
        }
    } elseif (str_starts_with($upper, 'DROP TABLE')) {
        $stats['dropTables']++;
    } elseif (str_starts_with($upper, 'INSERT INTO')) {
        $stats['inserts']++;
        if (preg_match('/INSERT INTO\s+`?([A-Za-z0-9_]+)`?/i', $trim, $m)) {
            $tbl = $m[1];
            /* Count VALUES tuples — one opening paren per row for the
               common mysqldump format. This is a rough estimate; exact
               counts aren't needed for the summary. */
            $rowCount = substr_count($line, '),(') + 1;
            $stats['insertRows'] += $rowCount;
            $stats['tables'][$tbl] = ($stats['tables'][$tbl] ?? 0) + $rowCount;
        }
    }
}

output('Pre-flight summary:');
output('  CREATE TABLE statements ........ ' . $stats['createTables']);
output('  DROP TABLE statements .......... ' . $stats['dropTables']);
output('  INSERT statements .............. ' . $stats['inserts']);
output('  Estimated rows to restore ...... ' . number_format($stats['insertRows']));
if ($stats['takenAt'] !== '') {
    output('  Backup timestamp ............... ' . $stats['takenAt']);
}
if (!empty($stats['tables'])) {
    arsort($stats['tables']);
    output('  Top tables by row count:');
    $shown = 0;
    foreach ($stats['tables'] as $tbl => $rows) {
        if ($shown >= 8) break;
        output('    ' . str_pad($tbl, 30) . number_format((int)$rows));
        $shown++;
    }
}
output('');

if ($preflight) {
    output('PRE-FLIGHT ONLY — nothing has been restored.');
    return;
}

if (!$confirmed) {
    output('DRY RUN — nothing has been restored.');
    output('Re-run with --confirm (CLI) or ?confirm=1 (web) to proceed.');
    return;
}

/* ---- Pre-restore auto-snapshot ----
 * Before touching the live database, capture its current state. If
 * the restore fails partway, the admin can restore from this snapshot
 * to recover. Skipped when --skip-snapshot is passed or backup.php is
 * unavailable for any reason. */
if (!$skipSnapshot) {
    output('Taking pre-restore snapshot of current state…');
    $snapshotDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'backups';
    $beforeCount = is_dir($snapshotDir) ? count(glob($snapshotDir . '/ihymns-backup-*.sql*') ?: []) : 0;
    try {
        include __DIR__ . DIRECTORY_SEPARATOR . 'backup.php';
    } catch (\Throwable $e) {
        output('  [WARN] snapshot failed: ' . $e->getMessage());
        output('         Continuing anyway — the incoming backup still loads below.');
    }
    $afterCount = is_dir($snapshotDir) ? count(glob($snapshotDir . '/ihymns-backup-*.sql*') ?: []) : 0;
    if ($afterCount > $beforeCount) {
        output('  [OK] pre-restore snapshot created');
    }
    output('');
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

/* Split the dump into individual statements and classify each as DDL
 * or data. DDL (CREATE/DROP/ALTER) auto-commits in MySQL and can't be
 * rolled back, so we run it outside a transaction. INSERTs are wrapped
 * in a single transaction — any failure rolls the data-load back to a
 * clean state while leaving the new schema in place.
 *
 * The splitter is intentionally simple: it handles single-quote, double-
 * quote and backtick-quoted strings plus line comments (-- / #) and
 * block comments (/* ... * /). Good enough for mysqldump output. */
$statements = [];
$buf = '';
$inS = false; $inD = false; $inB = false; $inLine = false; $inBlock = false;
$len = strlen($content);
for ($i = 0; $i < $len; $i++) {
    $ch = $content[$i];
    $nx = $content[$i + 1] ?? '';
    if ($inLine) {
        if ($ch === "\n") { $inLine = false; }
        continue;
    }
    if ($inBlock) {
        if ($ch === '*' && $nx === '/') { $inBlock = false; $i++; }
        continue;
    }
    if (!$inS && !$inD && !$inB) {
        if ($ch === '-' && $nx === '-') { $inLine = true; $i++; continue; }
        if ($ch === '#') { $inLine = true; continue; }
        if ($ch === '/' && $nx === '*') { $inBlock = true; $i++; continue; }
    }
    if ($inS) { if ($ch === "'" && $content[$i - 1] !== '\\') { $inS = false; } $buf .= $ch; continue; }
    if ($inD) { if ($ch === '"' && $content[$i - 1] !== '\\') { $inD = false; } $buf .= $ch; continue; }
    if ($inB) { if ($ch === '`') { $inB = false; } $buf .= $ch; continue; }
    if ($ch === "'") { $inS = true; $buf .= $ch; continue; }
    if ($ch === '"') { $inD = true; $buf .= $ch; continue; }
    if ($ch === '`') { $inB = true; $buf .= $ch; continue; }
    if ($ch === ';') {
        $stmt = trim($buf);
        if ($stmt !== '') { $statements[] = $stmt; }
        $buf = '';
        continue;
    }
    $buf .= $ch;
}
if (trim($buf) !== '') { $statements[] = trim($buf); }

$ddlDone = 0; $dataDone = 0; $totalData = 0;
foreach ($statements as $s) {
    if (stripos(ltrim($s), 'INSERT') === 0) { $totalData++; }
}

/* Run DDL (plus any non-INSERT DML) first, outside a transaction. */
try {
    foreach ($statements as $s) {
        if (stripos(ltrim($s), 'INSERT') === 0) { continue; }
        if (!$mysql->query($s)) {
            throw new \RuntimeException($mysql->error . ' | near: ' . substr($s, 0, 80));
        }
        $ddlDone++;
    }
    output('  [OK] schema + ' . $ddlDone . ' setup statements');
} catch (\Throwable $e) {
    output('  [FAIL schema] ' . $e->getMessage());
    output('');
    output('Schema-phase failure — database state may be inconsistent.');
    output('Restore the pre-restore snapshot to recover.');
    $mysql->query('SET FOREIGN_KEY_CHECKS = 1');
    $mysql->close();
    return;
}

/* Run every INSERT inside one transaction — all-or-nothing on data. */
try {
    $mysql->begin_transaction();
    foreach ($statements as $s) {
        if (stripos(ltrim($s), 'INSERT') !== 0) { continue; }
        if (!$mysql->query($s)) {
            throw new \RuntimeException($mysql->error . ' | near: ' . substr($s, 0, 80));
        }
        $dataDone++;
    }
    $mysql->commit();
    output('  [OK] data — ' . $dataDone . ' / ' . $totalData . ' INSERT statements committed');
} catch (\Throwable $e) {
    try { $mysql->rollback(); } catch (\Throwable $_) {}
    output('  [FAIL data] ' . $e->getMessage());
    output('  [ROLLBACK] data changes reverted — schema changes remain.');
    output('  Restore the pre-restore snapshot to fully recover previous state.');
}

$mysql->query('SET FOREIGN_KEY_CHECKS = 1');

output('');
output('Re-check /manage/setup-database to verify table counts.');

$mysql->close();
return;

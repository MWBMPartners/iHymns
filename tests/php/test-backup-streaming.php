<?php

declare(strict_types=1);

/**
 * iHymns — database-backup streaming + view-safety guard (#1771)
 * =============================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The admin "Backup Database" died with "Interrupted / empty output" on a real
 * corpus because it read each table with a BUFFERED `SELECT *` (whole table into
 * PHP memory → OOM), and it also treated the 8 compat/derived VIEWS as tables
 * (DROP TABLE + INSERTs → a backup that could never restore). The fix streams
 * UNBUFFERED and dumps views as CREATE VIEW after all base tables. This guard
 * locks those properties so a future refactor can't silently reintroduce either
 * failure (a behavioural round-trip needs a DB and can't run in CI; this can).
 *
 * DB-free comment-stripped source scan.  php tests/php/test-backup-streaming.php
 *
 * @see appWeb/.sql/backup.php
 * @see #1771
 */

$repoRoot = dirname(__DIR__, 2);
$file     = $repoRoot . '/appWeb/.sql/backup.php';
if (!is_readable($file)) { fwrite(STDERR, "FATAL: cannot read {$file}\n"); exit(1); }

function bkStrip(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

$failures = [];
$mut = [];
$mf = "<?php\n// MYSQLI_USE_RESULT NeedleComment\n\$x='NeedleCode';\n";
$ms = bkStrip($mf);
if (strpos($ms, 'NeedleCode') === false) { $mut[] = 'bkStrip FAILS-HIGH'; }
if (strpos($ms, 'NeedleComment') !== false) { $mut[] = 'bkStrip FAILS-LOW (kept a comment; a MYSQLI_USE_RESULT in a comment would false-satisfy)'; }

$code = bkStrip((string)file_get_contents($file));

/* Streaming (the OOM fix). */
if (!preg_match('/SELECT\s+\*\s+FROM\s+`\{\$table\}`.*?MYSQLI_USE_RESULT/s', $code)) {
    $failures[] = 'the per-table data SELECT is not UNBUFFERED (MYSQLI_USE_RESULT) — a buffered SELECT * OOM-fatals on a real corpus (#1771)';
}
if (strpos($code, '->free()') === false) {
    $failures[] = 'the data result is never ->free()d — an open unbuffered result blocks the next table\'s query';
}

/* View safety (the corrupt-restore fix). */
if (strpos($code, 'SHOW FULL TABLES') === false) {
    $failures[] = 'objects are not enumerated via SHOW FULL TABLES — cannot tell a VIEW from a BASE TABLE (#1771)';
}
if (strpos($code, "'Create View'") === false || strpos($code, 'DROP VIEW IF EXISTS') === false) {
    $failures[] = 'views are not emitted as DROP VIEW + CREATE VIEW — dumping a view as a table produces an un-restorable backup (#1771)';
}
/* Views must be written AFTER base tables (a view references its base tables;
   e.g. tblCreditPeople sorts before tblMusicians). Assert the base-table pass
   precedes the view pass in source order. */
$basePos = strpos($code, '$baseTables');
$viewPass = strpos($code, 'foreach ($viewNames');
if ($basePos === false || $viewPass === false || $viewPass < $basePos) {
    $failures[] = 'views are not dumped in a second pass AFTER base tables — a view whose base table sorts later would fail to create on restore (#1771)';
}

/* Gzip-direct (the disk fix) + partial-file safety. */
if (strpos($code, 'gzopen') === false || strpos($code, 'gzwrite') === false) {
    $failures[] = 'the dump is not written straight to the gzip stream — a giant uncompressed .sql intermediate can exhaust disk (#1771)';
}
if (strpos($code, '@unlink($filepath)') === false || strpos($code, '$backupOk') === false) {
    $failures[] = 'a failed backup does not remove its partial file — a truncated backup must never masquerade as good (#1771)';
}

if ($failures || $mut) {
    if ($mut) { fwrite(STDERR, "FAIL: mutation self-test(s):\n"); foreach ($mut as $m) { fwrite(STDERR, "  - {$m}\n"); } fwrite(STDERR, "\n"); }
    if ($failures) {
        fwrite(STDERR, "FAIL: database-backup streaming + view-safety guard (#1771):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    exit(1);
}
echo "PASS: backup.php streams unbuffered + frees results, dumps views as CREATE VIEW after base tables, "
   . "writes straight to gzip, and removes a partial file on failure. Mutation self-tests behaved as expected.\n";
exit(0);

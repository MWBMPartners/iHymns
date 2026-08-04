<?php

declare(strict_types=1);

/**
 * test-schema-installs.php — schema.sql must be able to BUILD a database (#1708)
 * =============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHY THIS EXISTS
 * ---------------
 * On 2026-07-31, `appWeb/.sql/schema.sql` was executed against an empty database
 * for what appears to be the first time. It produced **16 of 136 tables** before
 * dying:
 *
 *     ERROR 1005 (HY000) at line 574: Can't create table `tblLyrics`
 *                        (errno: 150 "Foreign key constraint is incorrectly formed")
 *
 * Rule #19's entire premise is that "schema.sql is the canonical source of truth
 * a fresh install reads from". It could not produce an installation at all.
 *
 * Nobody noticed because dev / alpha / www were each built incrementally by
 * migration cards and never execute this file end to end — and because
 * `test-schema-coverage.php`, which is a good check, compares the file's TEXT
 * against the migrations. It proves the two agree. It cannot prove either one
 * builds a database. A green suite reported schema health for a file that could
 * not install.
 *
 * TWO ASSERTIONS, DELIBERATELY OF DIFFERENT KINDS
 * ----------------------------------------------
 *  1. STATIC — always runs, no database needed. Catches the two defect classes
 *     found in #1708 by reading the file: an inline FK whose target table is
 *     created LATER, and an FK column whose type differs from the column it
 *     references. This is the half that works in every CI environment.
 *
 *  2. LIVE — runs only when a MySQL/MariaDB server is reachable. Actually loads
 *     the file into a scratch database and asserts the table count. This is the
 *     only check that is genuinely conclusive, because the static half is a
 *     model of InnoDB's rules and a model can be incomplete.
 *
 * The live half SKIPS rather than fails when no server is present, and says so
 * loudly. A skip that reads like a pass is the #1701 mistake; the summary line
 * states which halves ran.
 *
 * CONNECTION: set `IHYMNS_TEST_DSN` (e.g. `host=127.0.0.1;user=root;pass=`) or
 * rely on a local socket as root. Nothing here touches an application database —
 * it creates and drops its own `ihymns_schema_probe_*`.
 *
 * @see appWeb/.sql/schema.sql
 * @see tests/php/test-schema-coverage.php  the TEXT-agreement check this complements
 * @link https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html
 */

$root = dirname(__DIR__, 2);
$schemaPath = $root . '/appWeb/.sql/schema.sql';

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        $detail\n"; }
    }
}

/* `rg` cannot see appWeb/.sql/ (a dot-directory), so this reads an explicit
   path rather than searching for it — the lesson from #1690. */
$sql = @file_get_contents($schemaPath);
ok('schema.sql was read from disk', is_string($sql) && strlen($sql) > 10000,
   "could not read $schemaPath");
if (!is_string($sql)) { echo "\n1 assertion(s) failed.\n"; exit(1); }

$lines = explode("\n", $sql);

/* ---------------------------------------------------------------------- *
 * Parse: where each table is CREATEd, and each column's type.
 * ---------------------------------------------------------------------- */
$createdAt = [];        // table => 1-indexed line
$colTypes  = [];        // table => [column => normalised type]
$cur = null;
foreach ($lines as $idx => $line) {
    $ln = $idx + 1;
    if (preg_match('/^\s*CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?/i', $line, $m)) {
        $cur = $m[1];
        if (!isset($createdAt[$cur])) { $createdAt[$cur] = $ln; }
        $colTypes[$cur] = $colTypes[$cur] ?? [];
        continue;
    }
    if ($cur === null) { continue; }
    if (preg_match('/^\s*\)\s*ENGINE/i', $line)) { $cur = null; continue; }
    /* A column declaration: name then an integer/char type. Keywords that can
       start a line inside a CREATE are excluded so `KEY idx (…)` and friends are
       not mistaken for columns. */
    if (preg_match('/^\s*`?(\w+)`?\s+(BIGINT|INT|SMALLINT|TINYINT|MEDIUMINT|VARCHAR\(\d+\)|CHAR\(\d+\))\b\s*(UNSIGNED)?/i',
                   $line, $m)) {
        $name = $m[1];
        if (in_array(strtoupper($name),
                     ['FOREIGN','CONSTRAINT','PRIMARY','UNIQUE','INDEX','KEY','FULLTEXT','SPATIAL'], true)) {
            continue;
        }
        $colTypes[$cur][$name] = strtoupper($m[2]) . (empty($m[3]) ? '' : ' UNSIGNED');
    }
}

ok('parsed a plausible number of CREATE TABLE statements',
   count($createdAt) >= 100,
   'found ' . count($createdAt) . ' — the parser is under-reporting, and an '
   . 'under-reporting scan reads as coverage (#1701)');

/* ---------------------------------------------------------------------- *
 * STATIC 1 — an inline FK whose target table is created LATER.
 *
 * InnoDB needs the referenced table to EXIST at the moment an inline FOREIGN KEY
 * is declared. This is what killed the install at tblLyrics: it referenced
 * tblUsers, defined 154 lines further down.
 *
 * A REFERENCES inside a trailing `ALTER TABLE … ADD CONSTRAINT` is exempt by
 * construction — by then every table exists — which is exactly why the fix was to
 * move them there.
 * ---------------------------------------------------------------------- */
echo "\n1 — no inline foreign key may reference a table created later\n";

$forward = [];
$cur = null;
foreach ($lines as $idx => $line) {
    $ln = $idx + 1;
    if (preg_match('/^\s*CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?/i', $line, $m)) {
        $cur = $m[1]; continue;
    }
    if ($cur !== null && preg_match('/^\s*\)\s*ENGINE/i', $line)) { $cur = null; continue; }
    if ($cur === null) { continue; }          // outside a CREATE => a trailing ALTER, exempt
    if (preg_match('/REFERENCES\s+`?(\w+)`?\s*\(/i', $line, $m)) {
        $target = $m[1];
        if (!isset($createdAt[$target])) {
            $forward[] = "$cur (line " . $createdAt[$cur] . ") references $target, which is never created";
        } elseif ($createdAt[$target] > $createdAt[$cur]) {
            $forward[] = "$cur (line " . $createdAt[$cur] . ") references $target, created later at line "
                       . $createdAt[$target];
        }
    }
}
ok('every inline FK target is created before the table that references it',
   $forward === [],
   "these produce errno 150 on a fresh install; move them to a trailing "
   . "ALTER TABLE … ADD CONSTRAINT at the end of the file:\n        "
   . implode("\n        ", $forward));

/* ---------------------------------------------------------------------- *
 * STATIC 2 — an FK column whose type differs from the column it references.
 *
 * This one survives even `SET FOREIGN_KEY_CHECKS=0`, because a mismatched type
 * is structurally invalid rather than merely premature. Both #1708 instances
 * carried a COMMENT asserting the types matched — #1604's thesis exactly: a
 * comment describing the wrong mechanism is worse than none, because it stops
 * the next reader checking.
 * ---------------------------------------------------------------------- */
echo "\n2 — every FK column's type matches the column it references\n";

$mismatch = [];
$cur = null;
foreach ($lines as $idx => $line) {
    $ln = $idx + 1;
    if (preg_match('/^\s*CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?/i', $line, $m)) {
        $cur = $m[1]; continue;
    }
    if ($cur !== null && preg_match('/^\s*\)\s*ENGINE/i', $line)) { $cur = null; }
    /* Matches inside a CREATE and inside a trailing ALTER alike — a type
       mismatch is wrong in both places. The owning table for an ALTER is taken
       from the ALTER itself. */
    if (preg_match('/^\s*ALTER TABLE\s+`?(\w+)`?/i', $line, $m)) { $cur = $m[1]; }
    if (preg_match('/FOREIGN KEY\s*\(\s*`?(\w+)`?\s*\)\s*REFERENCES\s+`?(\w+)`?\s*\(\s*`?(\w+)`?\s*\)/i',
                   $line, $m) && $cur !== null) {
        [, $localCol, $tgtTable, $tgtCol] = $m;
        $a = $colTypes[$cur][$localCol]      ?? null;
        $b = $colTypes[$tgtTable][$tgtCol]   ?? null;
        if ($a !== null && $b !== null && $a !== $b) {
            $mismatch[] = "$cur.$localCol is $a but $tgtTable.$tgtCol is $b (line $ln)";
        }
    }
}
ok('no FK column type differs from its referenced column',
   $mismatch === [],
   "errno 150 — the table simply cannot be created:\n        "
   . implode("\n        ", $mismatch));

/* ---------------------------------------------------------------------- *
 * LIVE — actually build it. The conclusive half.
 * ---------------------------------------------------------------------- */
echo "\n3 — schema.sql actually installs into an empty database\n";

$expected = 0;
foreach ($lines as $line) {
    if (preg_match('/^CREATE TABLE/i', $line)) { $expected++; }
}
ok('derived the expected table count from the file (not hardcoded)',
   $expected >= 100,
   "counted $expected — a hardcoded number would need editing every time a "
   . 'table is added, which is how a guard stops being run');

$dsn  = getenv('IHYMNS_TEST_DSN') ?: '';
$host = '127.0.0.1'; $user = 'root'; $pass = ''; $sock = null;
if ($dsn !== '') {
    foreach (explode(';', $dsn) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        if ($k === 'host') { $host = $v; }
        if ($k === 'user') { $user = $v; }
        if ($k === 'pass') { $pass = $v; }
        if ($k === 'socket') { $sock = $v; }
    }
} elseif (file_exists('/var/run/mysqld/mysqld.sock')) {
    $sock = '/var/run/mysqld/mysqld.sock';
}

$db = null;
try {
    mysqli_report(MYSQLI_REPORT_OFF);   // probing: a failed connect is an answer, not a fatal
    $db = $sock !== null
        ? @new mysqli(null, $user, $pass, '', 0, $sock)
        : @new mysqli($host, $user, $pass);
    if ($db->connect_errno) { $db = null; }
} catch (\Throwable $e) {
    $db = null;
}

if ($db === null) {
    echo "  SKIP  no MySQL/MariaDB reachable — the LIVE half did not run.\n";
    echo "        The static checks above catch the two known defect classes, but only\n";
    echo "        an actual load proves the file builds. Set IHYMNS_TEST_DSN, or install\n";
    echo "        a server in CI (about a minute; the load itself takes under a second).\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $probe = 'ihymns_schema_probe_' . substr(hash('sha256', (string)getmypid()), 0, 8);
    $errors = [];
    try {
        $db->query("DROP DATABASE IF EXISTS `$probe`");
        $db->query("CREATE DATABASE `$probe` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->select_db($probe);

        /* Statement-at-a-time so the FIRST failure is reported with its SQL,
           rather than a bare "it didn't work". Splitting on `;\n` is safe here:
           schema.sql contains no stored programs (the triggers live in their own
           migration, deliberately — they are host-optional). */
        foreach (preg_split('/;\s*\n/', $sql) as $chunk) {
            /* Strip LEADING comment lines rather than skipping the whole chunk.
               Nearly every CREATE TABLE here is preceded by a doc block, so a
               `str_starts_with($chunk, '--')` skip silently discarded almost the
               entire file and reported "created 0 of 136" — the guard being
               wrong, not the schema. Caught on its first run. */
            $keep = [];
            foreach (explode("\n", $chunk) as $l) {
                if ($keep === [] && (trim($l) === '' || str_starts_with(trim($l), '--'))) { continue; }
                $keep[] = $l;
            }
            $stmt = trim(implode("\n", $keep));
            if ($stmt === '') { continue; }
            try {
                $db->query($stmt);
            } catch (\Throwable $e) {
                $head = trim(explode("\n", $stmt)[0]);
                $errors[] = $e->getMessage() . '  ||  ' . substr($head, 0, 110);
                if (count($errors) >= 3) { break; }
            }
        }

        $res = $db->query(
            "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = '$probe' AND TABLE_TYPE = 'BASE TABLE'"
        );
        $made = (int)($res->fetch_assoc()['c'] ?? 0);

        ok('the whole file executed without error',
           $errors === [],
           "first failure(s):\n        " . implode("\n        ", $errors));

        ok("every table declared in the file was created ($expected expected)",
           $made === $expected,
           "created $made of $expected — the install stops at the first failure, so "
           . 'everything after it is missing');
    } finally {
        try { $db->query("DROP DATABASE IF EXISTS `$probe`"); } catch (\Throwable $e) { /* best effort */ }
        $db->close();
    }
}

echo "\n";
if ($fail > 0) {
    echo "$fail assertion(s) failed.\n";
    exit(1);
}
echo "All schema-install assertions passed.\n";

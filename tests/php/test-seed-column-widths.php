<?php

declare(strict_types=1);

/**
 * iHymns — Seed Value / Column Width Guard
 * ========================================
 *
 * Asserts that nothing this repo seeds into MySQL is longer than the column
 * it is being seeded into, and that the three places the same seed row is
 * written all say the same thing.
 *
 * WHY THIS EXISTS (#1685)
 * -----------------------
 * `migrate-fix-captcha-ads-descriptions.php` shipped a 280-character string
 * into `tblAppSettings.Description VARCHAR(255)`. mysqli runs under
 * MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT (includes/db_mysql.php), so that
 * is not a truncation warning — it THROWS. And because the offending key was
 * first in the migration's map, the migration aborted having updated zero
 * rows, `setup-database.php` caught the throw and broke out of its loop, and
 * "Apply all pending" stopped dead at that card — taking every later
 * migration in the order with it.
 *
 * None of the existing guards could see it:
 *   - test-schema-coverage.php compares STRUCTURE (tables/columns), not values.
 *   - test-migration-registry.php checks the probe exists and isn't
 *     always-true; this migration's probe checks a description PREFIX, which
 *     is present in both the truncated and the failed case.
 *   - `php -l` sees a perfectly valid string.
 *
 * So the failure was invisible until something tried to run it against a real
 * database. That is precisely the class of bug rule #34 is about, and the fix
 * is a mechanism rather than a promise to count characters carefully.
 *
 * WHAT IS CHECKED
 * ---------------
 *   A. Every literal value in every seed `INSERT` in `schema.sql` and
 *      `.fulldata/ihymns-full.sql` fits the VARCHAR/CHAR width declared for
 *      its column (measured in CHARACTERS — utf8mb4 counts characters, and an
 *      em-dash is one character but three bytes).
 *   B. Every statically-resolvable value a `appWeb/.sql/*.php` migration binds
 *      into a VARCHAR/CHAR column fits that column too.
 *   C. Where the same row is written in more than one place, the copies agree
 *      byte-for-byte (rule #19: a fresh install must equal a migrated one).
 *
 * ANTI-BLINDNESS (rule #34)
 * -------------------------
 * A scanner that under-reports is worse than none, so this file refuses to
 * report success from a partial parse. It asserts:
 *   - the number of INSERT statements it parsed equals the number of INSERT
 *     keywords outside string literals (so an `INSERT … SELECT` or a
 *     column-less `INSERT INTO t VALUES` cannot be silently skipped);
 *   - every parsed statement reached its `;`;
 *   - every parsed row has exactly as many values as the column list;
 *   - a PHP literal the resolver was handed but could not read is a FAILURE,
 *     not a shrug (a backfill binding a runtime value is NOT — see the note by
 *     "Anti-blindness 4" for why that distinction matters);
 *   - across the whole tree, the resolver must still read at least one static
 *     value, so a resolver that breaks entirely cannot report green.
 * and it carries mutation self-tests (§ SELF-TESTS) that feed deliberately
 * broken input through the same functions and require each to be caught.
 *
 * Run:  php tests/php/test-seed-column-widths.php
 * Exit: 0 clean, 1 at least one failure.
 */

require_once __DIR__ . '/lib/sql_seed_parser.php';

$root = dirname(__DIR__, 2);

/** @var list<string> Collected human-readable failures. */
$failures = [];
$checks   = 0;

/* ====================================================================== *
 * Pure check functions — every one takes source text and returns a list
 * of failure strings, so the self-tests at the bottom can drive them with
 * synthetic input and prove they are capable of failing.
 * ====================================================================== */

/**
 * A. Width-check + parse-integrity-check one SQL file.
 *
 * @param array<string, array<string, array{type:string,len:?int}>> $widths
 * @return list<string>
 */
function seedCheckSqlFile(string $label, string $sql, array $widths): array
{
    $out    = [];
    $insert = sqlSeedInsertRows($sql);

    /* Anti-blindness 1: did we see every statement? */
    $masked   = sqlSeedMaskStrings(sqlSeedStripComments($sql));
    $expected = preg_match_all('/\bINSERT\b/i', $masked);
    if ($expected !== count($insert)) {
        $out[] = sprintf(
            '%s: parser saw %d INSERT statement(s) but %d INSERT keyword(s) exist outside strings. '
            . 'The parser has gone blind — do not trust the width results below.',
            $label,
            count($insert),
            $expected
        );
    }

    foreach ($insert as $stmt) {
        $where = sprintf('%s:%d (%s)', $label, $stmt['line'], $stmt['table']);

        /* Anti-blindness 2: did the statement terminate? */
        if (!$stmt['terminated']) {
            $out[] = "$where: INSERT never reached its ';' — truncated parse.";
        }

        $cols = $stmt['columns'];

        foreach ($stmt['rows'] as $n => $row) {
            /* Anti-blindness 3: arity. A mis-tokenised string shows up here. */
            if (count($row) !== count($cols)) {
                $out[] = sprintf(
                    '%s: row %d has %d value(s) but %d column(s) were named.',
                    $where,
                    $n + 1,
                    count($row),
                    count($cols)
                );
                continue;
            }

            foreach ($cols as $i => $col) {
                $decl = $widths[$stmt['table']][$col] ?? null;
                if ($decl === null || $decl['len'] === null) {
                    continue;                       // TEXT/INT/unknown table — nothing to check
                }
                if (!in_array($decl['type'], ['VARCHAR', 'CHAR'], true)) {
                    continue;
                }
                if ($row[$i]['kind'] !== 'string') {
                    continue;                       // NULL / DEFAULT / expression
                }
                $len = mb_strlen((string)$row[$i]['value'], 'UTF-8');
                if ($len > $decl['len']) {
                    $out[] = sprintf(
                        "%s: row %d column %s is %d characters but the column is %s(%d).\n      value: %s…",
                        $where,
                        $n + 1,
                        $col,
                        $len,
                        $decl['type'],
                        $decl['len'],
                        mb_substr((string)$row[$i]['value'], 0, 70, 'UTF-8')
                    );
                }
            }
        }
    }

    return $out;
}

/**
 * Turn a chain of single-quoted PHP literals (`'a' . 'b'`) into its value.
 *
 * ELI5: glue the quoted pieces together and undo the backslashes.
 *
 * @return string|null null when the text isn't a pure literal chain
 */
function seedResolvePhpLiteralChain(string $expr): ?string
{
    $expr = trim($expr);
    if ($expr === '' || $expr[0] !== "'") {
        return null;
    }

    $value = '';
    $i     = 0;
    $len   = strlen($expr);

    while ($i < $len) {
        if ($expr[$i] !== "'") {
            return null;
        }
        $i++;
        $buf = '';
        while ($i < $len) {
            $c = $expr[$i];
            if ($c === '\\' && $i + 1 < $len) {
                // PHP single-quote escapes: only \\ and \' are special.
                $n = $expr[$i + 1];
                $buf .= ($n === '\\' || $n === "'") ? $n : '\\' . $n;
                $i  += 2;
                continue;
            }
            if ($c === "'") {
                $i++;
                break;
            }
            $buf .= $c;
            $i++;
        }
        $value .= $buf;

        // Either the chain ends here, or a `.` joins another literal.
        $rest = ltrim(substr($expr, $i));
        if ($rest === '') {
            return $value;
        }
        if ($rest[0] !== '.') {
            return null;
        }
        $i   = $len - strlen(ltrim(substr($rest, 1)));
        $expr = ltrim(substr($rest, 1));
        $len  = strlen($expr);
        $i    = 0;
    }

    return $value;
}

/**
 * Which column does each `?` placeholder in a SQL statement belong to?
 *
 * Handles the two shapes this repo's migrations actually use:
 *   INSERT … INTO t (a, b, c) VALUES (?, ?, ?)      → [a, b, c]
 *   UPDATE t SET a = ? WHERE b = ?                  → [a, b]
 *
 * @return list<string>
 */
function seedPlaceholderColumns(string $sql): array
{
    if (preg_match('/\bINSERT\b[^(;]*?\bINTO\s+`?\w+`?\s*\(([^)]*)\)\s*VALUES\s*\(([^)]*)\)/is', $sql, $m)) {
        $cols = array_map(static fn($c) => trim(trim(trim($c), '`')), explode(',', $m[1]));
        $vals = array_map('trim', explode(',', $m[2]));
        $outc = [];
        foreach ($vals as $i => $v) {
            if ($v === '?' && isset($cols[$i])) {
                $outc[] = $cols[$i];
            } else {
                $outc[] = '';                       // literal in the VALUES list
            }
        }
        return $outc;
    }

    // SET col = ? … WHERE col = ?  (placeholder order == textual order)
    if (preg_match_all('/`?(\w+)`?\s*=\s*\?/', $sql, $m)) {
        return $m[1];
    }

    return [];
}

/**
 * Which table does a statement write to?
 */
function seedStatementTable(string $sql): ?string
{
    if (preg_match('/\b(?:INSERT|REPLACE)\b[^(;]*?\bINTO\s+`?(\w+)`?/i', $sql, $m)) {
        return $m[1];
    }
    if (preg_match('/\bUPDATE\s+`?(\w+)`?/i', $sql, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * B + C. Check one migration PHP file.
 *
 * @param array<string, array<string, array{type:string,len:?int}>> $widths
 * @param array<string, array<string, array<string,string>>>        $seedIndex
 *        table => keyColumnValue => [column => value] built from schema.sql
 * @param array<string, list<string>> $seedCols table => ordered seed columns
 * @param int|null $resolvedOut receives how many static values were readable —
 *        the corpus-level "is the resolver still working at all?" signal.
 * @return list<string>
 */
function seedCheckMigrationPhp(
    string $label,
    string $src,
    array $widths,
    array $seedIndex,
    array $seedCols,
    ?int &$resolvedOut = null
): array {
    $out      = [];
    $resolved = 0;      // how many (column, literal) pairs we managed to read
    $choked   = [];     // literals we SHOULD have resolved but could not

    /* ---- Pass 1: prepared statement + the bind_param that follows it ---- */
    if (preg_match_all('/\bprepare\s*\(\s*((?:\'(?:[^\'\\\\]|\\\\.)*\'\s*\.?\s*)+)\)/s', $src, $pm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($pm as $hit) {
            $sqlText = seedResolvePhpLiteralChain($hit[1][0]);
            if ($sqlText === null) {
                continue;
            }
            $table = seedStatementTable($sqlText);
            if ($table === null || !isset($widths[$table])) {
                continue;
            }
            $phCols = seedPlaceholderColumns($sqlText);
            if (!$phCols) {
                continue;
            }

            // The first bind_param after this prepare() call.
            $after = substr($src, $hit[0][1]);
            if (!preg_match('/bind_param\s*\(\s*\'[a-z]+\'\s*,\s*([^;]*?)\)\s*;/is', $after, $bm)) {
                continue;
            }
            $args = array_map('trim', explode(',', $bm[1]));

            foreach ($phCols as $i => $col) {
                if ($col === '' || !isset($args[$i])) {
                    continue;
                }
                $decl = $widths[$table][$col] ?? null;
                if (!$decl || $decl['len'] === null || !in_array($decl['type'], ['VARCHAR', 'CHAR'], true)) {
                    continue;
                }
                if (!preg_match('/^\$(\w+)$/', $args[$i], $vm)) {
                    continue;
                }
                $val = seedResolvePhpVar($src, $vm[1]);
                if ($val === null) {
                    /* Not resolvable. Usually correct and uninteresting: a
                       backfill migration binds a value computed per row, and
                       no static check can (or should) say anything about it.
                       But if the variable IS assigned a quoted literal and we
                       still could not read it, the resolver has gone blind on
                       a shape it is supposed to handle — that we must report,
                       because a silently-unparsed constant is exactly how a
                       guard keeps returning green past the thing it guards. */
                    if (preg_match('/\$' . preg_quote($vm[1], '/') . '\s*=\s*\'/', $src)) {
                        $choked[] = sprintf('$%s (bound to %s.%s)', $vm[1], $table, $col);
                    }
                    continue;
                }
                $resolved++;
                $n = mb_strlen($val, 'UTF-8');
                if ($n > $decl['len']) {
                    $out[] = sprintf(
                        "%s: \$%s is bound to %s.%s (%s(%d)) but is %d characters.\n      value: %s…",
                        $label,
                        $vm[1],
                        $table,
                        $col,
                        $decl['type'],
                        $decl['len'],
                        $n,
                        mb_substr($val, 0, 70, 'UTF-8')
                    );
                }
            }
        }
    }

    /* ---- Pass 2: 'settingKey' => 'description' style maps ---------------- *
     * The reworded-descriptions migration writes its six rows from a literal
     * map and binds them from a foreach, so pass 1 cannot resolve them. The
     * keys ARE the schema.sql seed keys, which makes both the width check and
     * the byte-identity check (rule #19) exact rather than heuristic. */
    foreach ($seedIndex as $table => $rowsByKey) {
        $keyCol = $seedCols[$table][0] ?? null;
        if ($keyCol === null) {
            continue;
        }
        foreach ($rowsByKey as $keyVal => $seedRow) {
            $re = '/\'' . preg_quote($keyVal, '/') . '\'\s*=>\s*((?:\'(?:[^\'\\\\]|\\\\.)*\'\s*\.?\s*)+)[,\]]/s';
            if (!preg_match($re, $src, $km)) {
                continue;
            }
            $val = seedResolvePhpLiteralChain(rtrim(trim($km[1]), '.'));
            if ($val === null) {
                continue;
            }
            $resolved++;

            /* Which column is it? The one whose schema.sql seed value this
               map is replacing. We do not guess: compare against every
               width-constrained column of the table and use the widest
               constraint that applies to a text column carrying a seed value.
               In practice tblAppSettings has exactly one — Description. */
            foreach ($seedRow as $col => $seedVal) {
                if ($col === $keyCol) {
                    continue;
                }
                $decl = $widths[$table][$col] ?? null;
                if (!$decl || $decl['len'] === null || !in_array($decl['type'], ['VARCHAR', 'CHAR'], true)) {
                    continue;
                }
                $n = mb_strlen($val, 'UTF-8');
                if ($n > $decl['len']) {
                    $out[] = sprintf(
                        "%s: '%s' => (%d characters) exceeds %s.%s %s(%d).\n      value: %s…",
                        $label,
                        $keyVal,
                        $n,
                        $table,
                        $col,
                        $decl['type'],
                        $decl['len'],
                        mb_substr($val, 0, 70, 'UTF-8')
                    );
                } elseif ($val !== $seedVal) {
                    /* C. Rule #19 — a fresh install (schema.sql) must land the
                       same text a migrated install lands. */
                    $out[] = sprintf(
                        "%s: '%s' text differs from the schema.sql seed for %s.%s.\n"
                        . "      migration: %s\n      schema.sql: %s",
                        $label,
                        $keyVal,
                        $table,
                        $col,
                        $val,
                        $seedVal
                    );
                }
            }
        }
    }

    /* ---- Anti-blindness 4 ------------------------------------------------ *
     * Deliberately NARROW (rule #34's second half). An earlier draft failed
     * any file that bound a VARCHAR column without yielding a static value —
     * which flagged nineteen perfectly correct backfill migrations, because
     * a backfill binds a value computed per row and there is nothing to
     * measure. A guard that fails on correct code gets weakened or deleted
     * rather than fixed, so this fires ONLY when a literal was there to be
     * read and the resolver could not read it. */
    if ($choked) {
        $out[] = sprintf(
            '%s: %s assigned a string literal that the resolver could not parse. '
            . 'The guard is blind on this value — extend seedResolvePhpLiteralChain() '
            . 'rather than ignoring it.',
            $label,
            implode(', ', array_unique($choked))
        );
    }

    $resolvedOut = $resolved;
    return $out;
}

/**
 * Resolve `$name = 'literal' . 'literal';` anywhere in a PHP source string.
 */
function seedResolvePhpVar(string $src, string $name): ?string
{
    $re = '/\$' . preg_quote($name, '/') . '\s*=\s*((?:\'(?:[^\'\\\\]|\\\\.)*\'\s*\.?\s*)+);/s';
    if (!preg_match($re, $src, $m)) {
        return null;
    }
    return seedResolvePhpLiteralChain(rtrim(trim($m[1]), '.'));
}

/**
 * C. Two SQL files must agree on any seed row they both carry.
 *
 * @return list<string>
 */
function seedCheckCrossFile(string $labelA, string $sqlA, string $labelB, string $sqlB): array
{
    $out = [];
    $idxA = seedIndexBySql($sqlA);
    $idxB = seedIndexBySql($sqlB);

    foreach ($idxA as $table => $rowsA) {
        if (!isset($idxB[$table])) {
            continue;
        }
        foreach ($rowsA as $key => $rowA) {
            if (!isset($idxB[$table][$key])) {
                continue;
            }
            $rowB = $idxB[$table][$key];
            foreach ($rowA as $col => $valA) {
                if (!array_key_exists($col, $rowB)) {
                    continue;
                }
                if ($valA !== $rowB[$col]) {
                    $out[] = sprintf(
                        "%s vs %s disagree on %s['%s'].%s:\n      %s: %s\n      %s: %s",
                        $labelA,
                        $labelB,
                        $table,
                        $key,
                        $col,
                        $labelA,
                        $valA,
                        $labelB,
                        $rowB[$col]
                    );
                }
            }
        }
    }

    return $out;
}

/**
 * Build table => firstColumnValue => [column => value] from a SQL file's
 * seed INSERTs. Only rows whose values are all plain literals are indexed.
 *
 * @return array<string, array<string, array<string,string>>>
 */
function seedIndexBySql(string $sql): array
{
    $idx = [];
    foreach (sqlSeedInsertRows($sql) as $stmt) {
        $cols = $stmt['columns'];
        if (!$cols) {
            continue;
        }
        foreach ($stmt['rows'] as $row) {
            if (count($row) !== count($cols)) {
                continue;
            }
            if ($row[0]['kind'] !== 'string') {
                continue;                            // non-string key: not indexable
            }
            $key  = (string)$row[0]['value'];
            $vals = [];
            foreach ($cols as $i => $c) {
                if ($row[$i]['kind'] === 'string') {
                    $vals[$c] = (string)$row[$i]['value'];
                }
            }
            $idx[$stmt['table']][$key] = $vals;
        }
    }
    return $idx;
}

/** Ordered seed column list per table, from a SQL file. */
function seedColumnsBySql(string $sql): array
{
    $cols = [];
    foreach (sqlSeedInsertRows($sql) as $stmt) {
        if ($stmt['columns'] && !isset($cols[$stmt['table']])) {
            $cols[$stmt['table']] = $stmt['columns'];
        }
    }
    return $cols;
}

/* ====================================================================== *
 * RUN — against the real tree
 * ====================================================================== */

$schemaPath = "$root/appWeb/.sql/schema.sql";
if (!is_readable($schemaPath)) {
    fwrite(STDERR, "FATAL: $schemaPath not readable\n");
    exit(1);
}
$schemaSql = (string)file_get_contents($schemaPath);
$widths    = sqlSeedTableColumns($schemaSql);

if (count($widths) < 50) {
    fwrite(STDERR, sprintf(
        "FATAL: parsed only %d tables out of schema.sql — parser broken or file shape changed.\n",
        count($widths)
    ));
    exit(1);
}

/* --- A. SQL seed files ------------------------------------------------- */
$sqlFiles = [
    'schema.sql'                => $schemaPath,
    '.fulldata/ihymns-full.sql' => "$root/appWeb/.sql/.fulldata/ihymns-full.sql",
];

$sources = [];
foreach ($sqlFiles as $label => $path) {
    if (!is_readable($path)) {
        continue;                                    // .fulldata is optional in a slim checkout
    }
    $sources[$label] = (string)file_get_contents($path);

    // Tables declared only in this file still need their widths known.
    $localWidths = $widths + sqlSeedTableColumns($sources[$label]);

    $checks++;
    foreach (seedCheckSqlFile($label, $sources[$label], $localWidths) as $f) {
        $failures[] = $f;
    }
}

/* --- C1. schema.sql vs the full-data dump ------------------------------ */
if (isset($sources['schema.sql'], $sources['.fulldata/ihymns-full.sql'])) {
    $checks++;
    foreach (seedCheckCrossFile(
        'schema.sql',
        $sources['schema.sql'],
        '.fulldata/ihymns-full.sql',
        $sources['.fulldata/ihymns-full.sql']
    ) as $f) {
        $failures[] = $f;
    }
}

/* --- B + C2. Migration PHP --------------------------------------------- *
 * Derived from the tree: every migrate-*.php, not a list anybody typed. */
$seedIndex = seedIndexBySql($schemaSql);
$seedCols  = seedColumnsBySql($schemaSql);

$migrations = glob("$root/appWeb/.sql/*.php") ?: [];
sort($migrations);
if (count($migrations) < 20) {
    fwrite(STDERR, sprintf(
        "FATAL: found only %d migration scripts in appWeb/.sql/ — glob is wrong.\n",
        count($migrations)
    ));
    exit(1);
}

$migrationsExamined = 0;
$totalResolved      = 0;
foreach ($migrations as $path) {
    $src = (string)file_get_contents($path);
    if (!str_contains($src, 'bind_param') && !str_contains($src, '=>')) {
        continue;
    }
    $migrationsExamined++;
    $checks++;
    $got = 0;
    foreach (seedCheckMigrationPhp(
        'appWeb/.sql/' . basename($path),
        $src,
        $widths,
        $seedIndex,
        $seedCols,
        $got
    ) as $f) {
        $failures[] = $f;
    }
    $totalResolved += $got;
}

/* Corpus-level anti-blindness: the per-file check above is deliberately quiet
   about values it cannot read, so this is what catches a resolver that has
   stopped working altogether. If a refactor breaks seedResolvePhpVar(), every
   file goes quiet and the suite would otherwise report a cheerful green. */
if ($totalResolved === 0) {
    $failures[] = 'Resolver read ZERO static values out of ' . $migrationsExamined
        . ' migration script(s). Either every constant seed was removed from the tree, '
        . 'or the resolver is broken — both need a human.';
}

/* ====================================================================== *
 * SELF-TESTS — prove each check is capable of failing (rule #34)
 *
 * Every guard in this repo that was written from a typed list and never
 * challenged has, at some point, been confidently green while the thing it
 * named was broken. These run the SAME functions the real check runs, over
 * synthetic input, and require a failure to come back.
 * ====================================================================== */

$selfFailures = [];
$selfCases    = 0;

/** @param list<string> $result */
function seedSelfExpect(string $name, array $result, bool $expectFailure, string $needle = ''): void
{
    global $selfFailures, $selfCases;
    $selfCases++;
    $got = count($result) > 0;
    if ($got !== $expectFailure) {
        $selfFailures[] = sprintf(
            'SELF-TEST %s: expected %s but got %s%s',
            $name,
            $expectFailure ? 'a failure' : 'a pass',
            $got ? 'a failure' : 'a pass',
            $got ? ': ' . $result[0] : ''
        );
        return;
    }
    if ($expectFailure && $needle !== '' && !str_contains(implode("\n", $result), $needle)) {
        $selfFailures[] = sprintf(
            'SELF-TEST %s: failed as expected but the message never mentioned "%s". Got: %s',
            $name,
            $needle,
            $result[0]
        );
    }
}

$tinyDdl = <<<'SQL'
CREATE TABLE IF NOT EXISTS tblFake (
    SettingKey   VARCHAR(10) NOT NULL PRIMARY KEY,
    Description  VARCHAR(20) NOT NULL DEFAULT '',
    Body         TEXT        NOT NULL,
    INDEX idx_x (SettingKey)
) ENGINE=InnoDB;
SQL;
$tinyWidths = sqlSeedTableColumns($tinyDdl);

seedSelfExpect(
    'M0 control — a fitting row passes',
    seedCheckSqlFile('t', $tinyDdl . "\nINSERT INTO tblFake (SettingKey, Description, Body) VALUES ('k', 'short', 'anything at all');", $tinyWidths),
    false
);

seedSelfExpect(
    'M1 over-width value is caught',
    seedCheckSqlFile('t', $tinyDdl . "\nINSERT INTO tblFake (SettingKey, Description, Body) VALUES ('k', 'this description is far too long', 'x');", $tinyWidths),
    true,
    'characters but the column is'
);

/* The exact bug that defeated the first draft of this scanner: a semicolon
   INSIDE a quoted value. A `[^;]`-delimited regex stops there and pronounces
   everything before it fine. */
seedSelfExpect(
    'M2 over-width value AFTER a semicolon inside an earlier string',
    seedCheckSqlFile('t', $tinyDdl . "\nINSERT INTO tblFake (SettingKey, Description, Body) VALUES\n"
        . "  ('a', 'has; a semicolon', 'x'),\n"
        . "  ('b', 'this description is far too long', 'x');", $tinyWidths),
    true,
    'characters but the column is'
);

seedSelfExpect(
    'M3 unterminated INSERT is caught',
    seedCheckSqlFile('t', $tinyDdl . "\nINSERT INTO tblFake (SettingKey, Description, Body) VALUES ('a', 'ok', 'x')", $tinyWidths),
    true,
    "never reached its ';'"
);

seedSelfExpect(
    'M4 wrong arity is caught',
    seedCheckSqlFile('t', $tinyDdl . "\nINSERT INTO tblFake (SettingKey, Description, Body) VALUES ('a', 'ok');", $tinyWidths),
    true,
    'column(s) were named'
);

seedSelfExpect(
    'M5 a statement shape the parser cannot read is reported, not skipped',
    seedCheckSqlFile('t', $tinyDdl . "\nINSERT INTO tblFake SELECT * FROM tblOther;", $tinyWidths),
    true,
    'gone blind'
);

/* Multi-byte: 21 em-dashes is 21 characters (63 bytes) and must fail against
   VARCHAR(20); 20 of them must pass. Getting this backwards — measuring bytes
   — would flag correct rows and miss real overflows on ASCII text. */
seedSelfExpect(
    'M6 width is counted in characters, not bytes (20 em-dashes fit VARCHAR(20))',
    seedCheckSqlFile('t', $tinyDdl . "\nINSERT INTO tblFake (SettingKey, Description, Body) VALUES ('a', '" . str_repeat('—', 20) . "', 'x');", $tinyWidths),
    false
);
seedSelfExpect(
    'M7 21 em-dashes do NOT fit VARCHAR(20)',
    seedCheckSqlFile('t', $tinyDdl . "\nINSERT INTO tblFake (SettingKey, Description, Body) VALUES ('a', '" . str_repeat('—', 21) . "', 'x');", $tinyWidths),
    true,
    'characters but the column is'
);

/* --- migration PHP self-tests ----------------------------------------- */
$fakeSeedSql = $tinyDdl . "\nINSERT INTO tblFake (SettingKey, Description, Body) VALUES ('alpha', 'canonical', 'x');";
$fakeIndex   = seedIndexBySql($fakeSeedSql);
$fakeCols    = seedColumnsBySql($fakeSeedSql);

$phpOk = <<<'PHP'
<?php
$key  = 'alpha';
$desc = 'canonical';
$stmt = $db->prepare('INSERT INTO tblFake (SettingKey, Description) VALUES (?, ?)');
$stmt->bind_param('ss', $key, $desc);
PHP;
seedSelfExpect('M8 control — a fitting bound value passes', seedCheckMigrationPhp('f', $phpOk, $tinyWidths, $fakeIndex, $fakeCols), false);

$phpLong = str_replace("'canonical'", "'this description is far too long'", $phpOk);
seedSelfExpect(
    'M9 over-width bound value is caught',
    seedCheckMigrationPhp('f', $phpLong, $tinyWidths, $fakeIndex, $fakeCols),
    true,
    'characters'
);

$phpConcat = <<<'PHP'
<?php
$key  = 'alpha';
$desc = 'this description is '
      . 'far too long indeed';
$stmt = $db->prepare('INSERT INTO tblFake (SettingKey, Description) VALUES (?, ?)');
$stmt->bind_param('ss', $key, $desc);
PHP;
seedSelfExpect(
    'M10 concatenated literal chain is resolved and measured',
    seedCheckMigrationPhp('f', $phpConcat, $tinyWidths, $fakeIndex, $fakeCols),
    true,
    'characters'
);

$phpUpdate = <<<'PHP'
<?php
$descriptions = [
    'alpha' => 'this description is far too long',
];
$stmt = $db->prepare('UPDATE tblFake SET Description = ? WHERE SettingKey = ?');
foreach ($descriptions as $key => $desc) {
    $stmt->bind_param('ss', $desc, $key);
}
PHP;
seedSelfExpect(
    'M11 map-driven UPDATE (the #1685 shape) is measured',
    seedCheckMigrationPhp('f', $phpUpdate, $tinyWidths, $fakeIndex, $fakeCols),
    true,
    'exceeds'
);

$phpDrift = str_replace("'this description is far too long'", "'drifted'", $phpUpdate);
seedSelfExpect(
    'M12 migration text drifting from the schema.sql seed is caught (rule #19)',
    seedCheckMigrationPhp('f', $phpDrift, $tinyWidths, $fakeIndex, $fakeCols),
    true,
    'differs from the schema.sql seed'
);

$phpAgrees = str_replace("'this description is far too long'", "'canonical'", $phpUpdate);
seedSelfExpect('M13 matching migration text passes', seedCheckMigrationPhp('f', $phpAgrees, $tinyWidths, $fakeIndex, $fakeCols), false);

/* A backfill binds a value computed per row. There is nothing static to
   measure and nothing wrong — this MUST pass, or the guard fails on correct
   code and gets deleted (rule #34). Nineteen real migrations look like this. */
$phpBackfill = <<<'PHP'
<?php
$stmt = $db->prepare('UPDATE tblFake SET Description = ? WHERE SettingKey = ?');
foreach ($rows as $r) {
    $slug = makeSlug($r['name']);
    $stmt->bind_param('ss', $slug, $r['key']);
    $stmt->execute();
}
PHP;
seedSelfExpect('M14 a runtime-computed bound value is NOT flagged', seedCheckMigrationPhp('f', $phpBackfill, $tinyWidths, $fakeIndex, $fakeCols), false);

/* …but a literal the resolver cannot read IS a blindness signal. Here the
   value is a double-quoted string, a shape seedResolvePhpLiteralChain() does
   not handle — if someone writes one, the guard must say so rather than
   quietly measuring nothing. */
$phpChoked = <<<'PHP'
<?php
$key  = 'alpha';
$desc = 'unterminated chain' . SOME_CONSTANT;
$stmt = $db->prepare('INSERT INTO tblFake (SettingKey, Description) VALUES (?, ?)');
$stmt->bind_param('ss', $key, $desc);
PHP;
seedSelfExpect(
    'M15 an unreadable literal assignment is reported, not passed',
    seedCheckMigrationPhp('f', $phpChoked, $tinyWidths, $fakeIndex, $fakeCols),
    true,
    'guard is blind'
);

/* The resolved-count out-param is what the corpus-level check reads. If it
   stopped being written, that check could never fire. */
$resolvedProbe = 0;
seedCheckMigrationPhp('f', $phpOk, $tinyWidths, $fakeIndex, $fakeCols, $resolvedProbe);
seedSelfExpect(
    'M16 the resolved-count out-param is actually populated',
    $resolvedProbe > 0 ? [] : ['resolved count stayed at 0 for a file with a readable literal'],
    false
);

/* --- cross-file self-tests -------------------------------------------- */
seedSelfExpect(
    'M17 control — identical seed rows agree',
    seedCheckCrossFile('a', $fakeSeedSql, 'b', $fakeSeedSql),
    false
);
seedSelfExpect(
    'M18 differing seed text across two files is caught',
    seedCheckCrossFile('a', $fakeSeedSql, 'b', str_replace("'canonical'", "'different'", $fakeSeedSql)),
    true,
    'disagree on'
);

/* ====================================================================== *
 * REPORT
 * ====================================================================== */

$exit = 0;

if ($selfFailures) {
    fwrite(STDERR, "\nSELF-TEST FAILURES (the guard itself is broken):\n");
    foreach ($selfFailures as $f) {
        fwrite(STDERR, "  ✗ $f\n");
    }
    $exit = 1;
}

if ($failures) {
    fwrite(STDERR, "\nSEED WIDTH / CONSISTENCY FAILURES:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  ✗ $f\n");
    }
    $exit = 1;
}

if ($exit === 0) {
    printf(
        "OK  seed-column-widths — %d source(s) checked, %d migration script(s) examined, %d self-test(s) passed.\n",
        $checks,
        $migrationsExamined,
        $selfCases
    );
} else {
    fwrite(STDERR, sprintf(
        "\nFAILED — %d self-test failure(s), %d seed failure(s).\n",
        count($selfFailures),
        count($failures)
    ));
}

exit($exit);

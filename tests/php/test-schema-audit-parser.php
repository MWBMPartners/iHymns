<?php
/**
 * tests/php/test-schema-audit-parser.php
 *
 * Guards schemaAuditParseSchema() in includes/schema_audit.php — the parser
 * behind /manage/schema-audit's "Orphan in DB" column.
 *
 * ELI5: the Schema Audit page compares "columns schema.sql declares" against
 * "columns MySQL actually has". If the parser under-reads schema.sql, the page
 * accuses the database of having columns nobody declared — when in fact the
 * declaration is right there and the READER is broken.
 *
 * WHY THIS FILE EXISTS (#722). The parser stripped `--` line comments BEFORE it
 * stripped `COMMENT '…'` clauses. A DDL COMMENT is a quoted string, and 18 of
 * them in schema.sql carry a literal `--` as prose:
 *
 *     EventName VARCHAR(64) NOT NULL COMMENT 'e.g. song_opened -- VARCHAR not ENUM',
 *
 * Removing `--`-to-end-of-line first deletes that comment's CLOSING QUOTE. The
 * COMMENT regex then matches from the surviving opening quote to the next quote
 * further down the table body, taking every column declaration in between with
 * it. Real columns vanished from the parse and were reported as orphans:
 * exactly 21 of them across 3 of 136 tables, which was precisely the orphan
 * count the page displayed.
 *
 * The instructive part is that #722's acceptance criterion was "Schema Audit
 * shows zero non-OK rows", so the tracker recorded a SCHEMA problem that never
 * existed, while the real defect sat in the auditor. A tool that grades the
 * codebase needs its own grader.
 *
 * @see appWeb/public_html/includes/schema_audit.php  the parser under test
 * @see https://dev.mysql.com/doc/refman/8.0/en/comments.html  MySQL comment syntax
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/appWeb/public_html/includes/schema_audit.php';

$passed = 0;
$failed = 0;
$failures = [];

function check(string $label, bool $cond): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✅ {$label}\n"; }
    else { $failed++; $failures[] = $label; echo "  ❌ {$label}\n"; }
}

echo "\nSchema Audit parser: a `--` inside a DDL COMMENT must not eat columns\n\n";

/* ---- 1. The synthetic pair — identical but for a `--` inside one COMMENT --- */

$synthetic = <<<'SQL'
CREATE TABLE IF NOT EXISTS tblProbeClean (
    Id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Alpha     VARCHAR(40) NOT NULL COMMENT 'ordinary prose, with a comma',
    Beta      VARCHAR(40) NULL     COMMENT 'more prose',
    Gamma     TINYINT(1)  NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tblProbeDashed (
    Id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Alpha     VARCHAR(40) NOT NULL COMMENT 'ordinary prose -- with a dash-dash',
    Beta      VARCHAR(40) NULL     COMMENT 'more prose',
    Gamma     TINYINT(1)  NOT NULL DEFAULT 0
) ENGINE=InnoDB;
SQL;

$parsed = schemaAuditParseSchema($synthetic);

check('control: the dash-free table parses all 4 columns',
    count($parsed['tblProbeClean'] ?? []) === 4);

/* THE REGRESSION. Before the fix this returned 2 — Alpha survived, Beta and
   Gamma were swallowed by the runaway COMMENT match. */
check('a `--` inside a COMMENT does not swallow the columns after it (#722)',
    count($parsed['tblProbeDashed'] ?? []) === 4);

check('both tables parse identically — the `--` is invisible to column identity',
    ($parsed['tblProbeClean'] ?? []) === ($parsed['tblProbeDashed'] ?? []));

/* ---- 2. A genuine `--` LINE comment must still be stripped ---------------- */

$lineComment = <<<'SQL'
CREATE TABLE IF NOT EXISTS tblProbeLine (
    Id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- this is a real line comment, with a comma, and prose
    Alpha   VARCHAR(40) NOT NULL
) ENGINE=InnoDB;
SQL;

$lineParsed = schemaAuditParseSchema($lineComment)['tblProbeLine'] ?? [];
check('a real `--` line comment is still stripped (no phantom column from its prose)',
    $lineParsed === ['Id', 'Alpha']);

/* ---- 3. Against the REAL schema.sql, derived — not a typed expectation ---- */

$schemaPath = $root . '/appWeb/.sql/schema.sql';
$schemaSrc  = (string)file_get_contents($schemaPath);
$real       = schemaAuditParseSchema($schemaSrc);

check('parsed a plausible number of tables from the real schema.sql (>= 100)',
    count($real) >= 100);

/* Find every table whose CREATE body contains a COMMENT carrying `--`, then
   assert the parser did not under-read it. Derived from the file (rule #34) so
   a new such comment is covered automatically — no hardcoded table list. */
$affected = [];
if (preg_match_all(
        '/CREATE TABLE IF NOT EXISTS\s+(\w+)\s*\((.*?)\n\)\s*ENGINE=/s',
        $schemaSrc, $m, PREG_SET_ORDER)) {
    foreach ($m as $tbl) {
        if (preg_match("/COMMENT\s+'[^']*--/", $tbl[2])) {
            /* Count declarations the honest way: lines starting with an
               identifier followed by a type word, inside this body. */
            $declared = preg_match_all(
                '/^\s{4}([A-Za-z_][A-Za-z0-9_]*)\s+(?:INT|BIGINT|SMALLINT|TINYINT|VARCHAR|CHAR|TEXT|MEDIUMTEXT|LONGTEXT|JSON|DATETIME|TIMESTAMP|DATE|DECIMAL|FLOAT|DOUBLE|ENUM|BLOB)/mi',
                $tbl[2]);
            $affected[$tbl[1]] = $declared;
        }
    }
}

check('found at least one real table with a `--` inside a COMMENT (else this check is vacuous)',
    count($affected) >= 1);

foreach ($affected as $table => $declaredCount) {
    $got = count($real[$table] ?? []);
    check("{$table}: parser sees {$got} columns, DDL declares {$declaredCount} (#722)",
        $got >= $declaredCount);
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    echo "\nA column the parser cannot see is reported to the operator as an\n";
    echo "\"Orphan in DB\" — the page accuses the database of a divergence that\n";
    echo "does not exist, and the real defect is in the auditor. Strip\n";
    echo "COMMENT '…' clauses BEFORE `--` line comments, never the other way.\n";
    exit(1);
}
echo "\nSchema Audit parser reads every declared column.\n";

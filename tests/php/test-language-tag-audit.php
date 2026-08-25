<?php

declare(strict_types=1);

/**
 * iHymns — unknown-language-tag audit guard (BCP 47 registry plan §6.2.2, M4)
 *
 * ELI5: makes sure (a) EVERY column in the live schema that could hold a
 * song-facing language tag has been consciously accounted for by the M4
 * curator audit — a brand-new `*.Language`/`*.LanguageCode` column added
 * to the schema later fails THIS build until a human decides how it
 * should be remapped, rather than silently falling through untested — and
 * (b) the classifier (`bcp47ClassifyTag()` + its decomposer) actually
 * gets malformed/unregistered/inactive/ok right on a fixed truth table.
 *
 * WHY STATIC, NEVER A LIVE DB CALL
 * ---------------------------------
 * `languageTagSources()` itself queries INFORMATION_SCHEMA (needs a live
 * DB this CI run does not have). This guard instead parses the REAL
 * `appWeb/.sql/schema.sql` for the same column-name pattern
 * `languageTagSources()`'s live query filters on — tree-derived, never a
 * hand-typed list of tables — and exercises the PURE, DB-free functions
 * directly: `languageTagSourceRemapKind()` (no I/O at all) and
 * `bcp47ClassifyTag()`/`bcp47DecomposeTag()` (take a plain array registry
 * parameter, never query the DB themselves — see their own doc-comments).
 *
 * CHECKS
 * ------
 * (A) Derive every `(table, column)` pair in schema.sql whose column is
 *     literally named `Language`, `LanguageCode`, or `TargetLanguage`
 *     (inside a `CREATE TABLE` block) — the SAME three names
 *     `languageTagSources()`'s live `COLUMN_NAME IN (...)` filter uses
 *     (asserted byte-for-byte against that function's own source, so the
 *     two can never silently drift apart, rule #35).
 * (B) Every derived pair NOT in `IHYMNS_LANGUAGE_TAG_SOURCE_EXCLUSIONS` is
 *     run through the REAL `languageTagSourceRemapKind()` and must return
 *     one of the three valid remap kinds — a typo'd/missing kind fails
 *     the build for that pair specifically.
 * (C) The exclusion map's own keys still name REAL schema.sql columns
 *     (self-cleaning, like `tests/php/fixtures/orphan-allowlist.php`'s
 *     "no stale entry" checks) — a column removed from the schema but
 *     left in the exclusion map is flagged.
 * (D) The two KNOWN special cases this plan calls out by name —
 *     `tblLyricLines.LanguageCode` -> 'line-path',
 *     `tblSongRequests.Language` -> 'report-only' — are present in the
 *     derived set AND classified correctly (a floor proving the guard
 *     would catch either one being silently dropped or misclassified).
 * (E) A fixed truth table for `bcp47ClassifyTag()` / `bcp47DecomposeTag()`:
 *     `engli` -> malformed, `xq` -> unregistered (against a synthetic
 *     registry that doesn't know it), `pt-BR` -> ok (registry knows both,
 *     active), `zz-Latn` -> unregistered (zz unknown), a retired-subtag
 *     tag -> inactive (registry knows it, IsActive=false).
 *
 * MUTATION-PROVEN (rule #34) — every check below was actually broken, run,
 * confirmed RED, and restored; see the commit body for the transcript.
 *
 *   php tests/php/test-language-tag-audit.php
 *
 * Exit status 0 = every property holds, 1 = drift.
 *
 * @see appWeb/public_html/includes/language_tag_audit.php
 * @see appWeb/.sql/schema.sql
 * @see .claude/bcp47-language-registry-plan.md §6.2.2
 */

$repoRoot = dirname(__DIR__, 2);
$auditFile = $repoRoot . '/appWeb/public_html/includes/language_tag_audit.php';
$schemaFile = $repoRoot . '/appWeb/.sql/schema.sql';

$failures = [];
function langTagAuditFail(array &$failures, string $msg): void
{
    $failures[] = $msg;
}

foreach ([$auditFile, $schemaFile] as $f) {
    if (!is_file($f)) {
        fwrite(STDERR, "FATAL: expected file missing: $f\n");
        exit(1);
    }
}

/* Load the REAL pure functions — db_mysql.php's getDbMysqli() is defined
   but never CALLED by anything this guard exercises (languageTagSources(),
   the only DB-touching function, is never invoked here). */
require_once $auditFile;

/* =============================================================================
 * CHECK A — derive schema.sql's (table, column) pairs, using the SAME three
 * column names languageTagSources()'s live query filters on (read from that
 * function's own source, never re-typed — rule #35).
 * ============================================================================= */

$auditSrc = (string)file_get_contents($auditFile);
if (!preg_match(
    "/COLUMN_NAME\\s+IN\\s*\\('([A-Za-z]+)',\\s*'([A-Za-z]+)',\\s*'([A-Za-z]+)'\\)/",
    $auditSrc,
    $colNameMatch
)) {
    fwrite(STDERR, "FATAL: could not find languageTagSources()'s COLUMN_NAME IN (...) filter in its own source — parser anchor moved.\n");
    exit(1);
}
$targetColumns = [$colNameMatch[1], $colNameMatch[2], $colNameMatch[3]];
echo "  languageTagSources() filters on columns: " . implode(', ', $targetColumns) . "\n";

$schemaSrc = (string)file_get_contents($schemaFile);
/* Walk CREATE TABLE blocks: capture the table name, then scan up to the
   next top-level ");" for a column line naming one of the target columns.
   Tree-derived (parses the REAL file), not a hand-typed table list. */
preg_match_all('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+(\w+)\s*\((.*?)\n\)\s*ENGINE/is', $schemaSrc, $tableMatches, PREG_SET_ORDER);
if (count($tableMatches) < 50) {
    fwrite(STDERR, 'FATAL: only found ' . count($tableMatches) . " CREATE TABLE block(s) in schema.sql — parser anchor moved (rule #34 anti-under-report floor).\n");
    exit(1);
}

$derivedPairs = []; // "table.column" => true
foreach ($tableMatches as $tm) {
    $table = $tm[1];
    $body  = $tm[2];
    foreach ($targetColumns as $col) {
        if (preg_match('/^\s*' . preg_quote($col, '/') . '\s+VARCHAR/mi', $body)) {
            $derivedPairs["{$table}.{$col}"] = true;
        }
    }
}
echo "  derived " . count($derivedPairs) . " (table.column) pair(s) from schema.sql: " . implode(', ', array_keys($derivedPairs)) . "\n";
if (count($derivedPairs) < 8) {
    fwrite(STDERR, 'FATAL: only derived ' . count($derivedPairs) . " language-tag column(s) from schema.sql (expected >= 8 per the plan's own §5.2 sample list) — parser under-read (rule #34).\n");
    exit(1);
}

/* =============================================================================
 * CHECK B — every derived pair NOT excluded gets a valid remap kind from
 * the REAL languageTagSourceRemapKind().
 * ============================================================================= */

$validKinds = ['direct', 'line-path', 'report-only'];
$classifiedCount = 0;
foreach (array_keys($derivedPairs) as $pair) {
    [$table, $col] = explode('.', $pair, 2);
    $excluded = isset(IHYMNS_LANGUAGE_TAG_SOURCE_EXCLUSIONS[$pair]);
    if ($excluded) {
        continue;
    }
    $kind = languageTagSourceRemapKind($table, $col);
    $classifiedCount++;
    if (!in_array($kind, $validKinds, true)) {
        langTagAuditFail($failures, "languageTagSourceRemapKind('{$table}', '{$col}') returned '{$kind}' — not one of " . implode('|', $validKinds) . '.');
    }
}
echo "  check B: {$classifiedCount} non-excluded pair(s) each returned a valid remap kind\n";
if ($classifiedCount < 5) {
    fwrite(STDERR, "FATAL (check B): only {$classifiedCount} non-excluded pairs classified — exclusion map may have swallowed everything (under-report guard).\n");
    exit(1);
}

/* =============================================================================
 * CHECK C — exclusion map self-cleaning: every excluded key still names a
 * REAL schema.sql-derived pair (a stale exclusion, like a stale orphan-
 * allowlist entry, must be removed).
 * ============================================================================= */

foreach (array_keys(IHYMNS_LANGUAGE_TAG_SOURCE_EXCLUSIONS) as $excludedKey) {
    if (!isset($derivedPairs[$excludedKey])) {
        langTagAuditFail($failures, "IHYMNS_LANGUAGE_TAG_SOURCE_EXCLUSIONS['{$excludedKey}'] no longer matches any schema.sql-derived Language/LanguageCode/TargetLanguage column — stale exclusion, remove it (self-cleaning, like the orphan-allowlist pattern).");
    }
}
echo '  check C: ' . count(IHYMNS_LANGUAGE_TAG_SOURCE_EXCLUSIONS) . " exclusion(s), all still valid\n";

/* =============================================================================
 * CHECK D — the two KNOWN special cases are present and correctly classified.
 * ============================================================================= */

$knownSpecialCases = [
    'tblLyricLines.LanguageCode'   => 'line-path',
    'tblSongRequests.Language'     => 'report-only',
];
foreach ($knownSpecialCases as $pair => $expectedKind) {
    if (!isset($derivedPairs[$pair])) {
        langTagAuditFail($failures, "expected schema.sql source '{$pair}' was not derived at all — schema drift, or the parser regex needs updating.");
        continue;
    }
    [$table, $col] = explode('.', $pair, 2);
    $actual = languageTagSourceRemapKind($table, $col);
    if ($actual !== $expectedKind) {
        langTagAuditFail($failures, "languageTagSourceRemapKind('{$table}', '{$col}') returned '{$actual}', expected '{$expectedKind}' (this plan's named special case).");
    }
}
echo '  check D: ' . count($knownSpecialCases) . " known special case(s) verified\n";

/* =============================================================================
 * CHECK E — bcp47ClassifyTag() / bcp47DecomposeTag() truth table.
 * ============================================================================= */

$activeRegistry = [
    'language' => ['pt' => true, 'de' => true],
    'script'   => ['latn' => true],
    'region'   => ['br' => true],
    'variant'  => ['1996' => false], // retired
];

$truthTable = [
    ['tag' => 'engli',        'expect' => 'malformed',    'note' => 'not 2-3 letters — fails grammar'],
    ['tag' => 'xq',           'expect' => 'unregistered', 'note' => 'grammar-valid, not in the synthetic registry'],
    ['tag' => 'pt-BR',        'expect' => 'ok',           'note' => 'both subtags known + active'],
    ['tag' => 'zz-Latn',      'expect' => 'unregistered', 'note' => "zz isn't in the synthetic registry even though Latn is"],
    ['tag' => 'de-1996',      'expect' => 'inactive',     'note' => 'de is known+active, 1996 variant is known but retired'],
];
foreach ($truthTable as $case) {
    $got = bcp47ClassifyTag($case['tag'], $activeRegistry);
    $ok = $got === $case['expect'];
    printf("  %s  bcp47ClassifyTag('%s') = '%s' (expected '%s' — %s)\n", $ok ? 'PASS' : 'FAIL', $case['tag'], $got, $case['expect'], $case['note']);
    if (!$ok) {
        langTagAuditFail($failures, "bcp47ClassifyTag('{$case['tag']}') returned '{$got}', expected '{$case['expect']}' ({$case['note']}).");
    }
}

/* bcp47DecomposeTag() — a few direct shape assertions, since the
   classifier truth table above only exercises it indirectly. */
$decomposeCases = [
    ['tag' => 'zh-Hans-CN', 'expect' => ['lang' => 'zh', 'script' => 'Hans', 'region' => 'CN', 'variants' => []]],
    ['tag' => 'fr-CA-1694acad', 'expect' => ['lang' => 'fr', 'script' => '', 'region' => 'CA', 'variants' => ['1694acad']]],
    ['tag' => 'engli', 'expect' => ['lang' => '', 'script' => '', 'region' => '', 'variants' => []]],
];
foreach ($decomposeCases as $case) {
    $got = bcp47DecomposeTag($case['tag']);
    $ok = $got === $case['expect'];
    printf("  %s  bcp47DecomposeTag('%s') = %s\n", $ok ? 'PASS' : 'FAIL', $case['tag'], json_encode($got));
    if (!$ok) {
        langTagAuditFail($failures, "bcp47DecomposeTag('{$case['tag']}') returned " . json_encode($got) . ', expected ' . json_encode($case['expect']) . '.');
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " language-tag-audit problem(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}
printf(
    "OK: language-tag audit derivation + classifier verified — %d schema.sql pair(s), %d exclusion(s), %d truth-table case(s), %d decompose case(s).\n",
    count($derivedPairs), count(IHYMNS_LANGUAGE_TAG_SOURCE_EXCLUSIONS), count($truthTable), count($decomposeCases)
);
exit(0);

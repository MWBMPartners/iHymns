<?php
/**
 * iHymns — Schema-Audit Coverage Test
 *
 * Asserts that every table and column introduced by an
 * `appWeb/.sql/migrate-*.php` script also exists in
 * `appWeb/.sql/schema.sql` — the canonical source of truth for what
 * a fresh install should hold.
 *
 * Catches the class of drift surfaced by /manage/schema-audit (#518):
 * migrations create tables / columns in production over time but the
 * schema.sql file never picks them up, so a fresh install lands
 * structurally different from a long-running install. The Schema Audit
 * page flags this as "Orphan in DB" (informational only) but the real
 * fix is keeping schema.sql up-to-date.
 *
 * This test runs PURELY against the source tree — no database
 * connection required, so it slots into the CI lint step alongside
 * `php -l` without infrastructure.
 *
 * Direction enforced:
 *   migrations ⊆ schema.sql        (every migration-created
 *                                    table/column is in schema.sql)
 *
 * NOT enforced:
 *   schema.sql ⊆ DB                (would require a live DB; left to
 *                                    the runtime Schema Audit page,
 *                                    which is what surfaces "uncovered"
 *                                    and "missing" to the curator)
 *
 *   php tests/php/test-schema-coverage.php
 *
 * Exit status 0 = clean, 1 = at least one drift.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/schema_audit.php';

$schemaFile = dirname(__DIR__, 2) . '/appWeb/.sql/schema.sql';
$sqlDir     = dirname(__DIR__, 2) . '/appWeb/.sql';

if (!is_readable($schemaFile)) {
    fwrite(STDERR, "FATAL: $schemaFile not readable\n");
    exit(1);
}

$schemaCols = schemaAuditParseSchema((string)file_get_contents($schemaFile));
$migrations = schemaAuditScanMigrations($sqlDir);

if (!$schemaCols) {
    fwrite(STDERR, "FATAL: parsed zero tables out of schema.sql — parser broken or file shape changed.\n");
    exit(1);
}

/* Build the migration view as table => [column, …]. */
$migTables = [];
foreach ($migrations as $key => $files) {
    [$tbl, $col]            = explode('.', $key, 2);
    $migTables[$tbl][$col]  = $files;
}

/* ---------------------------------------------------------------------- *
 * Drift 1 — Tables in migrations but missing from schema.sql.
 * ---------------------------------------------------------------------- */
$missingTables = [];
foreach ($migTables as $tbl => $cols) {
    if (!isset($schemaCols[$tbl])) {
        $missingTables[$tbl] = array_keys($cols);
    }
}

/* ---------------------------------------------------------------------- *
 * Drift 2 — Columns in migrations but missing from the same table in
 * schema.sql.
 * ---------------------------------------------------------------------- */
$missingColumns = [];
foreach ($migTables as $tbl => $cols) {
    if (!isset($schemaCols[$tbl])) continue;   /* counted as a missing table */
    foreach ($cols as $col => $files) {
        if (!in_array($col, $schemaCols[$tbl], true)) {
            $missingColumns[] = [
                'table'      => $tbl,
                'column'     => $col,
                'migrations' => $files,
            ];
        }
    }
}

$failures = 0;

if ($missingTables) {
    $failures++;
    fwrite(STDERR, "FAIL: " . count($missingTables) . " table(s) created by migrations but missing from schema.sql:\n");
    foreach ($missingTables as $tbl => $cols) {
        fwrite(STDERR, "  - $tbl  (" . count($cols) . " column" . (count($cols) === 1 ? '' : 's') . ")\n");
    }
}

if ($missingColumns) {
    $failures++;
    fwrite(STDERR, "FAIL: " . count($missingColumns) . " column(s) added by migrations but missing from schema.sql:\n");
    foreach ($missingColumns as $m) {
        $files = implode(', ', $m['migrations']);
        fwrite(STDERR, "  - {$m['table']}.{$m['column']}  ←  $files\n");
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n");
    fwrite(STDERR, "schema.sql is the canonical source of truth — every migration that\n");
    fwrite(STDERR, "creates a table or adds a column should also append the matching\n");
    fwrite(STDERR, "definition to schema.sql in the same commit. Copy the CREATE TABLE /\n");
    fwrite(STDERR, "column declaration from the migration into the appropriate position\n");
    fwrite(STDERR, "in appWeb/.sql/schema.sql and re-run this test.\n");
    exit(1);
}

echo "PASS: every migration-created table and column is mirrored in schema.sql.\n";
echo "      (" . count($schemaCols) . " schema tables · "
     . count($migTables) . " migration tables · "
     . count($migrations) . " migration-tracked columns)\n";
exit(0);

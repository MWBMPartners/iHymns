<?php

declare(strict_types=1);

/**
 * iHymns — Schema-audit shared helpers (#719 PR 2d, #518)
 *
 * The four parser / comparer / scanner functions used by
 * /manage/schema-audit.php, lifted out so the new admin_schema_audit
 * and admin_migrations_status API endpoints can call them without
 * pulling the whole admin page into the include path.
 *
 * Each helper is pure — given the same inputs, returns the same
 * outputs. No global state, no logging, no superglobal reads.
 *
 *   - schemaAuditParseSchema(string)       parses appWeb/.sql/schema.sql
 *                                          → [tableName => [colName, …]]
 *   - schemaAuditScanMigrations(string)    walks every migrate-*.php under
 *                                          appWeb/.sql/ → [tblName.colName
 *                                          => [migrationFile, …]]
 *   - schemaAuditReadDb(\mysqli)           one INFORMATION_SCHEMA roundtrip
 *                                          → [tableName => [colName, …]]
 *   - schemaAuditCompare(...)              merges the three sources →
 *                                          { byTable, summary }
 *
 * Direct access is blocked so this file can't be loaded as an
 * arbitrary endpoint via an open Apache config.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Parse `schema.sql` into a `[tableName => [columnName, ...]]` map.
 *
 * Doesn't try to be a full SQL parser — leans on the file's
 * consistent shape: every table is `CREATE TABLE IF NOT EXISTS
 * tblX (` … `) ENGINE=…;` with one column or constraint per line.
 * Strips block comments before splitting at top-level commas so
 * multi-line column declarations stay intact (#722 parser fix).
 */
function schemaAuditParseSchema(string $schemaSql): array
{
    $tables = [];

    /* `s` flag so `.` matches newlines inside the parenthesised body. */
    $matched = preg_match_all(
        '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+(tbl\w+)\s*\((.*?)\)\s*ENGINE\s*=/is',
        $schemaSql,
        $matches,
        PREG_SET_ORDER
    );
    if ($matched === false || $matched === 0) {
        return $tables;
    }

    foreach ($matches as $m) {
        $tableName = $m[1];
        $body      = $m[2];

        /* Strip multi-line block comments first — otherwise lines
           inside a /* … *\/ block (which themselves don't start with
           /* on the current line) get read as phantom column
           declarations. (#722) */
        $body = preg_replace('/\/\*.*?\*\//s', '', $body);

        /* Split into top-level segments at commas, ignoring commas
           inside parentheses (so ENUM('success','failure','error')
           stays in one segment, not three). Each segment is exactly
           one column declaration OR one table-level constraint —
           irrespective of how many lines it spans. */
        $segments = [];
        $depth = 0;
        $buf   = '';
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $ch = $body[$i];
            if ($ch === "'") {
                $buf .= $ch;
                $i++;
                while ($i < $n) {
                    $buf .= $body[$i];
                    if ($body[$i] === "'" && (($i + 1) >= $n || $body[$i + 1] !== "'")) break;
                    $i++;
                }
                continue;
            }
            if ($ch === '(') $depth++;
            if ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) {
                $segments[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $segments[] = $buf;
        }

        $columns = [];
        foreach ($segments as $segment) {
            $segment = preg_replace('/--[^\n]*/', '', $segment);
            $segment = trim(preg_replace('/\s+/', ' ', $segment));
            if ($segment === '') continue;

            /* Skip table-level constraints. */
            if (preg_match(
                '/^(PRIMARY\s+KEY|INDEX|UNIQUE|KEY|CONSTRAINT|FOREIGN\s+KEY|FULLTEXT|SPATIAL)\b/i',
                $segment
            )) {
                continue;
            }
            /* Column declaration: starts with Name (optionally
               backtick-quoted) followed by a type. */
            if (preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?\s+/', $segment, $cm)) {
                $columns[] = $cm[1];
            }
        }

        $tables[$tableName] = $columns;
    }

    return $tables;
}

/**
 * Scan every `migrate-*.php` in `appWeb/.sql/` for the columns each
 * one adds, returning `[tblName.colName => [migrationFile, …]]`.
 *
 * Three signals merged:
 *   1. Literal `ALTER TABLE tblX ADD COLUMN <Name>` strings.
 *   2. `CREATE TABLE [IF NOT EXISTS] tblX (…)` blocks (parsed via
 *      schemaAuditParseSchema()).
 *   3. Docblock convention: `@migration-adds tblX.colName`. Used
 *      when ALTERs are built dynamically and a literal regex can't
 *      see the column name.
 */
function schemaAuditScanMigrations(string $sqlDir): array
{
    $coverage = [];
    $files = glob($sqlDir . DIRECTORY_SEPARATOR . 'migrate-*.php') ?: [];

    foreach ($files as $file) {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            continue;
        }
        $base = basename($file);

        /* Signal 1 — literal ALTER … ADD COLUMN strings */
        if (preg_match_all(
            '/ALTER\s+TABLE\s+(tbl\w+)\s+ADD\s+COLUMN\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/is',
            $contents,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $coverage[$m[1] . '.' . $m[2]][] = $base;
            }
        }

        /* Signal 2 — CREATE TABLE blocks inside the migration. */
        $createTableCols = schemaAuditParseSchema($contents);
        foreach ($createTableCols as $tbl => $cols) {
            foreach ($cols as $col) {
                $coverage[$tbl . '.' . $col][] = $base;
            }
        }

        /* Signal 3 — @migration-adds doctag */
        if (preg_match_all(
            '/@migration-adds\s+(tbl\w+)\.([A-Za-z_][A-Za-z0-9_]*)/i',
            $contents,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $coverage[$m[1] . '.' . $m[2]][] = $base;
            }
        }
    }

    /* De-dupe filenames per column. */
    foreach ($coverage as $k => $files) {
        $coverage[$k] = array_values(array_unique($files));
    }

    return $coverage;
}

/**
 * Read every `tblXxx` column the live database currently has.
 * One INFORMATION_SCHEMA roundtrip; cheap.
 *
 * @return array<string, string[]> tableName => [columnName, …]
 */
function schemaAuditReadDb(\mysqli $db): array
{
    $sql = "SELECT TABLE_NAME, COLUMN_NAME
              FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME LIKE 'tbl%'
             ORDER BY TABLE_NAME, ORDINAL_POSITION";
    $tables = [];
    $stmt = $db->prepare($sql);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $tables[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
    }
    $stmt->close();
    return $tables;
}

/**
 * Compare the three sources and return per-table rows + a summary
 * count of each status across the whole database.
 *
 * Status enum:
 *   - ok        : in code AND in DB
 *   - missing   : in code, not in DB, but a migration would add it
 *   - uncovered : in code, not in DB, no migration adds it (latent bomb)
 *   - orphan    : in DB, not in code (column dropped from schema.sql)
 *
 * @return array{
 *   byTable: array<string, list<array{col:string,status:string,migration:?string}>>,
 *   summary: array{ok:int,missing:int,uncovered:int,orphan:int}
 * }
 */
function schemaAuditCompare(array $schemaCols, array $dbCols, array $migrations): array
{
    $byTable = [];
    $summary = ['ok' => 0, 'missing' => 0, 'uncovered' => 0, 'orphan' => 0];

    foreach ($schemaCols as $tbl => $cols) {
        $rows       = [];
        $dbColsHere = $dbCols[$tbl] ?? [];

        foreach ($cols as $col) {
            $key   = $tbl . '.' . $col;
            $inDb  = in_array($col, $dbColsHere, true);
            $inMig = isset($migrations[$key]);

            if ($inDb) {
                $rows[] = ['col' => $col, 'status' => 'ok', 'migration' => null];
                $summary['ok']++;
            } elseif ($inMig) {
                $rows[] = ['col' => $col, 'status' => 'missing', 'migration' => implode(', ', $migrations[$key])];
                $summary['missing']++;
            } else {
                $rows[] = ['col' => $col, 'status' => 'uncovered', 'migration' => null];
                $summary['uncovered']++;
            }
        }

        /* Orphans = columns in DB but not in schema for this table */
        foreach ($dbColsHere as $dbCol) {
            if (!in_array($dbCol, $cols, true)) {
                $rows[] = ['col' => $dbCol, 'status' => 'orphan', 'migration' => null];
                $summary['orphan']++;
            }
        }

        $byTable[$tbl] = $rows;
    }

    /* Tables in DB but not in schema.sql at all — every column orphan. */
    foreach ($dbCols as $tbl => $cols) {
        if (isset($schemaCols[$tbl])) {
            continue;
        }
        $rows = [];
        foreach ($cols as $col) {
            $rows[] = ['col' => $col, 'status' => 'orphan', 'migration' => null];
            $summary['orphan']++;
        }
        $byTable[$tbl] = $rows;
    }

    /* Stable sort: tables with any non-OK rows first, then alphabetically. */
    uksort($byTable, function (string $a, string $b) use ($byTable) {
        $aDirty = schemaAuditTableHasIssues($byTable[$a]) ? 0 : 1;
        $bDirty = schemaAuditTableHasIssues($byTable[$b]) ? 0 : 1;
        return $aDirty <=> $bDirty ?: strcmp($a, $b);
    });

    return ['byTable' => $byTable, 'summary' => $summary];
}

/** True if any row in the per-table list isn't `ok`. */
function schemaAuditTableHasIssues(array $rows): bool
{
    foreach ($rows as $r) {
        if ($r['status'] !== 'ok') {
            return true;
        }
    }
    return false;
}

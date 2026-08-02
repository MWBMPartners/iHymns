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

    /* Strip block comments from the WHOLE input before pattern-matching.
       Migration PHP files often contain a docblock like:

           /**
            * Schema:
            *   CREATE TABLE tblFoo (
            *       Id  auto-increment PK
            *       ...
            *   )
            * /

       Without this strip, the regex below greedily-but-cheaply matches
       from the docblock's `CREATE TABLE tblFoo (` through to the FIRST
       subsequent `) ENGINE=…` — which is the real SQL further down the
       file. The matched body then spans the docblock + every line of
       PHP between, and the first column-shaped segment turns out to be
       a PHP type hint like `string` instead of `Id`. Pre-stripping
       block comments removes the docblock entirely so the regex only
       sees the real SQL string. (#722 parser fix follow-up.) */
    $schemaSql = preg_replace('/\/\*.*?\*\//s', '', $schemaSql) ?? $schemaSql;

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

        /* ORDER IS LOAD-BEARING: COMMENT '…' clauses come out FIRST, then
           `--` line comments. These two strips used to run the other way
           round, and that silently ate real columns (#722).

           Why: a DDL COMMENT is a quoted string, and 18 of them in
           schema.sql contain a literal `--` as prose, e.g.

               EventName VARCHAR(64) NOT NULL COMMENT 'app-level allow-listed
                 event name, e.g. song_opened -- VARCHAR not ENUM per rule #20',

           Strip `--`-to-end-of-line first and that comment loses its CLOSING
           QUOTE. The COMMENT regex below then runs from the surviving opening
           quote to the next quote FURTHER DOWN THE TABLE BODY, deleting every
           column declaration in between.

           The damage was quiet and precisely proportional: the Schema Audit
           page reported those swallowed columns as "Orphan in DB" — present in
           MySQL, absent from schema.sql — when schema.sql declared them
           perfectly well. 21 columns across 3 of 136 tables, which is exactly
           the orphan count the page showed. #722's acceptance was "zero
           non-OK rows", so the tracker recorded a schema problem that did not
           exist while the actual defect was in the auditor.

           Doing COMMENT first is correct in both directions: a `--` inside a
           quoted comment leaves with the whole clause, and a genuine `--` line
           comment is untouched by the COMMENT regex (no `COMMENT '` prefix) and
           is removed by the pass below. */
        $body = preg_replace(
            "/\\bCOMMENT\\s+'(?:[^'\\\\]|\\\\.|'')*'/i",
            '',
            $body
        ) ?? $body;

        /* NOW the `--` line comments, with every quoted string already gone.
           Still needed before the comma-split: `--` prose routinely contains
           commas ("VARCHAR(48), which silently truncated"), and the comma would
           split the segment so the next word became a phantom column name (the
           extraction regex matches `^[A-Za-z_][A-Za-z0-9_]*\s+`). */
        $body = preg_replace('/--[^\n]*/', '', $body);

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

    /* Signal 4 — RENAME TABLE old TO new, and RENAME COLUMN old TO new
       (#1741 P2). Second pass over all files so coverage attributed to an
       old table/column name (via CREATE TABLE / ADD COLUMN / @migration-adds
       in an earlier migration) gets re-attributed to the new name. Without
       this, the IETF migration's `CREATE TABLE tblScripts` shows up in the
       dictionary under the old name even though every other place in the
       codebase references `tblLanguageScripts` — and, the #1741 P2 case this
       block was extended for, `migrate-musician-profile.php`'s
       `@migration-adds tblCreditPeople.Type` would show up under a table
       name that `migrate-musicians-rename.php` later renames to
       `tblMusicians`, and `migrate-credit-person-identifiers.php`'s
       `CreditPersonId` column would show up under a column name that same
       migration later renames to `MusicianId` — schema.sql (rightly) only
       declares the FINAL post-rename shape, so without this remap the test
       would report both as "missing" even though the coverage is real, just
       filed under a name nothing outside this file still uses.
       (Multi-step rename chains are handled by iterating BOTH maps to a
       fixed point together — small file count, cheap — so it doesn't matter
       whether a table rename or a column rename "happened first" in the
       migration history; either order converges to the same final key.)

       RENAME TABLE syntax supports multiple comma-separated pairs in ONE
       atomic statement (`RENAME TABLE a TO b, c TO d, …`, as
       migrate-musicians-rename.php's 7-table rename uses) — the inner
       regex captures every "tblX TO tblY" pair inside the RENAME TABLE
       clause, not just the first, so a multi-pair statement is fully
       covered, not just its first pair.

       RENAME COLUMN is captured per-statement (`ALTER TABLE tbl RENAME
       COLUMN old TO new`, the one spelling this codebase's migrations
       actually emit — CHANGE-COLUMN's differing five-way syntax isn't
       emitted anywhere and isn't parsed here). A RENAME COLUMN statement
       always names the table under whatever name it holds AT THAT POINT in
       the script — normally the NEW name, because a table rename (if any)
       runs before its columns are renamed — so no separate "which table
       name does this apply to" resolution is needed: the captured table
       name is used as-is as one half of both the lookup key and the map
       key. */
    $tableRenames  = [];   // old table name  => new table name
    $columnRenames = [];   // "table.oldCol"  => new column name
    foreach ($files as $file) {
        $contents = @file_get_contents($file);
        if ($contents === false) continue;
        /* Strip PHP block comments so a docblock's "RENAME TABLE" /
           "RENAME COLUMN" prose example doesn't get picked up as real. */
        $stripped = preg_replace('/\/\*.*?\*\//s', '', $contents) ?? $contents;

        if (preg_match_all(
            '/RENAME\s+TABLE\s+((?:tbl\w+\s+TO\s+tbl\w+\s*,?\s*)+)/is',
            $stripped,
            $blocks,
            PREG_SET_ORDER
        )) {
            foreach ($blocks as $block) {
                if (preg_match_all('/(tbl\w+)\s+TO\s+(tbl\w+)/i', $block[1], $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $p) {
                        $tableRenames[$p[1]] = $p[2];
                    }
                }
            }
        }

        if (preg_match_all(
            '/ALTER\s+TABLE\s+(tbl\w+)\s+RENAME\s+COLUMN\s+([A-Za-z_]\w*)\s+TO\s+([A-Za-z_]\w*)/is',
            $stripped,
            $colMatches,
            PREG_SET_ORDER
        )) {
            foreach ($colMatches as $m) {
                $columnRenames[$m[1] . '.' . $m[2]] = $m[3];
            }
        }
    }
    if ($tableRenames || $columnRenames) {
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($tableRenames as $old => $new) {
                foreach (array_keys($coverage) as $key) {
                    if (strpos($key, $old . '.') !== 0) continue;
                    $col    = substr($key, strlen($old) + 1);
                    $newKey = $new . '.' . $col;
                    $coverage[$newKey] = array_merge(
                        $coverage[$newKey] ?? [],
                        $coverage[$key]
                    );
                    unset($coverage[$key]);
                    $changed = true;
                }
            }
            foreach ($columnRenames as $oldKey => $newCol) {
                if (!isset($coverage[$oldKey])) continue;
                $tbl    = substr($oldKey, 0, strpos($oldKey, '.'));
                $newKey = $tbl . '.' . $newCol;
                $coverage[$newKey] = array_merge(
                    $coverage[$newKey] ?? [],
                    $coverage[$oldKey]
                );
                unset($coverage[$oldKey]);
                $changed = true;
            }
        }
    }

    /* Signal 5 — @migration-drops tblX.colName (#1235 P4/C6). A column a later migration
       DROPS is no longer expected to live in schema.sql, so REMOVE it from coverage. Without
       this, the schema-coverage test would forever flag the column as "added by a migration
       but missing from schema.sql" after the retirement migration + the schema.sql byte-mirror
       land. (A column that is added AND later dropped nets to "not expected" — the retire
       migration's doctag wins.) */
    $dropped = [];
    foreach ($files as $file) {
        $contents = @file_get_contents($file);
        if ($contents === false) continue;
        if (preg_match_all(
            '/@migration-drops\s+(tbl\w+)\.([A-Za-z_][A-Za-z0-9_]*)/i',
            $contents,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) { $dropped[$m[1] . '.' . $m[2]] = true; }
        }
    }
    foreach (array_keys($dropped) as $key) {
        unset($coverage[$key]);
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

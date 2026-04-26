<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Schema Audit (#518) — global_admin only
 *
 * Read-only diagnostic page that compares three sources of truth:
 *
 *   1. Code  : columns declared in `appWeb/.sql/schema.sql`
 *   2. Live  : columns currently present in the deployed MySQL database
 *              (read from INFORMATION_SCHEMA.COLUMNS in one round-trip)
 *   3. Migs  : columns covered by `ALTER TABLE … ADD COLUMN` statements
 *              across every `appWeb/.sql/migrate-*.php` script
 *
 * For each `tblXxx` column the page classifies the row as one of:
 *
 *   OK         in code AND in live DB
 *   Missing    in code, NOT in DB — but a migration would add it
 *              (admin needs to run the named migration via Setup Database)
 *   Uncovered  in code, NOT in DB, AND no migration would add it
 *              (this is the latent-bomb class from #509 — file a bug,
 *              write a migration)
 *   Orphan     in DB, NOT in code (column was dropped from schema.sql
 *              without an explicit cleanup; usually informational)
 *
 * v1 is read-only — no ALTER statements run from this page. Hand-off to
 * `/manage/setup-database` for the actual migration runs. v2 (tracked
 * separately) can wire run buttons inline once the diff logic is
 * trusted in the wild.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

requireGlobalAdmin();
$currentUser = getCurrentUser();
$activePage  = 'schema-audit';

/* =========================================================================
 * AUDIT HELPERS
 *
 * Self-contained inside this file for v1; if v2 grows run-buttons or
 * other admin pages need the same parsers, extract to includes/.
 * ========================================================================= */

/**
 * Parse `schema.sql` into a `[tableName => [columnName, ...]]` map.
 *
 * Doesn't try to be a full SQL parser — leans on the file's consistent
 * shape: every table is `CREATE TABLE IF NOT EXISTS tblX (` … `) ENGINE=…;`
 * with one column or constraint per line. Column lines start with the
 * column identifier; table-level constraint lines start with one of
 * PRIMARY KEY / INDEX / UNIQUE / KEY / FOREIGN / CONSTRAINT.
 */
function _schemaAudit_parseSchema(string $schemaSql): array
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
        $columns   = [];

        foreach (preg_split('/\r?\n/', $body) as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '--') || str_starts_with($line, '/*')) {
                continue;
            }
            /* Skip table-level constraints AND constraint-continuation lines.
               Catches:
                 - PRIMARY KEY / KEY / UNIQUE / INDEX / FULLTEXT / SPATIAL
                 - CONSTRAINT … FOREIGN KEY … REFERENCES …
                 - The continuation line of a multi-line FOREIGN KEY:
                       …REFERENCES tblX(Id)
                           ON DELETE SET NULL ON UPDATE CASCADE
                   — the second line starts with `ON`, which a naïve column
                   parser would otherwise read as a column literally named
                   "ON". Same for the FULLTEXT / SPATIAL index forms which
                   start with their own keyword (not INDEX). */
            if (preg_match(
                '/^(PRIMARY\s+KEY|INDEX|UNIQUE|KEY|CONSTRAINT|FOREIGN\s+KEY|FULLTEXT|SPATIAL|ON\s+(DELETE|UPDATE))\b/i',
                $line
            )) {
                continue;
            }
            /* Column line: starts with `Name` or `\`Name\`` followed by a type. */
            if (preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?\s+/', $line, $cm)) {
                $columns[] = $cm[1];
            }
        }

        $tables[$tableName] = $columns;
    }

    return $tables;
}

/**
 * Scan every `migrate-*.php` in `appWeb/.sql/` for the columns each one
 * adds, returning `[tblName.colName => [migrationFile, …]]`.
 *
 * Three signals, merged:
 *
 *   1. Literal `ALTER TABLE tblXxx ADD COLUMN <Name>` strings — works
 *      for migrations like `migrate-songbook-meta.php` whose ALTERs
 *      are baked into a `$steps` array as full SQL strings. Multiline-
 *      aware so newline-broken ALTERs are still caught.
 *
 *   2. `CREATE TABLE [IF NOT EXISTS] tblXxx (…)` blocks — picks up
 *      migrations that introduce a brand-new table (e.g.
 *      `migrate-account-sync.php` Step 2 creates `tblSharedSetlists`).
 *      Every column inside the parens gets attributed to the migration.
 *
 *   3. Docblock convention: `@migration-adds tblXxx.colName` — for
 *      migrations like `migrate-account-sync.php` Step 1b that build
 *      their ALTER strings dynamically from a `[name, definition]`
 *      data structure (where the column name is a PHP variable
 *      interpolation and a literal regex can't see it). Each
 *      `@migration-adds` line declares one column the migration is
 *      responsible for. One line per column; multiple per file allowed.
 */
function _schemaAudit_scanMigrations(string $sqlDir): array
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

        /* Signal 2 — CREATE TABLE blocks inside the migration. Reuses
           the same parser the schema-audit page applies to schema.sql,
           since the column-line shape is identical. */
        $createTableCols = _schemaAudit_parseSchema($contents);
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

    /* De-dupe filenames per column (a migration may carry the doctag
       AND a literal ALTER for the same column — only credit it once). */
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
function _schemaAudit_readDb(\PDO $db): array
{
    $sql = "SELECT TABLE_NAME, COLUMN_NAME
              FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME LIKE 'tbl%'
             ORDER BY TABLE_NAME, ORDINAL_POSITION";
    $tables = [];
    foreach ($db->query($sql, \PDO::FETCH_ASSOC) as $row) {
        $tables[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
    }
    return $tables;
}

/**
 * Compare the three sources and return per-table rows + a summary count
 * of each status across the whole database.
 *
 * @return array{
 *   byTable: array<string, array<int, array{col:string,status:string,migration:?string}>>,
 *   summary: array{ok:int,missing:int,uncovered:int,orphan:int}
 * }
 */
function _schemaAudit_compare(array $schemaCols, array $dbCols, array $migrations): array
{
    $byTable = [];
    $summary = ['ok' => 0, 'missing' => 0, 'uncovered' => 0, 'orphan' => 0];

    /* Tables declared in schema.sql */
    foreach ($schemaCols as $tbl => $cols) {
        $rows       = [];
        $dbColsHere = $dbCols[$tbl] ?? [];

        foreach ($cols as $col) {
            $key = $tbl . '.' . $col;
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

    /* Tables in DB but not in schema.sql at all — every column is an orphan. */
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
        $aDirty = _schemaAudit_tableHasIssues($byTable[$a]) ? 0 : 1;
        $bDirty = _schemaAudit_tableHasIssues($byTable[$b]) ? 0 : 1;
        return $aDirty <=> $bDirty ?: strcmp($a, $b);
    });

    return ['byTable' => $byTable, 'summary' => $summary];
}

function _schemaAudit_tableHasIssues(array $rows): bool
{
    foreach ($rows as $r) {
        if ($r['status'] !== 'ok') {
            return true;
        }
    }
    return false;
}

function _schemaAudit_statusBadge(string $status): string
{
    [$cls, $label] = match ($status) {
        'ok'        => ['bg-success',           'OK'],
        'missing'   => ['bg-warning text-dark', 'Missing in DB'],
        'uncovered' => ['bg-danger',            'Uncovered'],
        'orphan'    => ['bg-secondary',         'Orphan in DB'],
        default     => ['bg-light text-dark',   $status],
    };
    return '<span class="badge ' . $cls . '">' . htmlspecialchars($label) . '</span>';
}

/* =========================================================================
 * RUN THE AUDIT
 * ========================================================================= */

$audit     = null;
$dbError   = null;
$schemaSrc = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.sql' . DIRECTORY_SEPARATOR . 'schema.sql';
$sqlDir    = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.sql';

try {
    $db = getDb();

    if (!is_readable($schemaSrc)) {
        throw new \RuntimeException('schema.sql is not readable at ' . $schemaSrc);
    }
    $schemaSql  = (string)file_get_contents($schemaSrc);
    $schemaCols = _schemaAudit_parseSchema($schemaSql);
    if (!$schemaCols) {
        throw new \RuntimeException('Parsed zero tables out of schema.sql — parser broken or file shape changed.');
    }
    $migrations = _schemaAudit_scanMigrations($sqlDir);
    $dbCols     = _schemaAudit_readDb($db);
    $audit      = _schemaAudit_compare($schemaCols, $dbCols, $migrations);
} catch (\Throwable $e) {
    error_log('[manage/schema-audit.php] failed: ' . $e->getMessage());
    $dbError = $e->getMessage();
}

/* Summary banner state */
$bannerClass = 'alert-success';
$bannerIcon  = 'bi-check-circle';
$bannerText  = 'Schema is in sync — every column declared in schema.sql exists in the live database.';
if ($audit !== null) {
    $s = $audit['summary'];
    if ($s['uncovered'] > 0) {
        $bannerClass = 'alert-danger';
        $bannerIcon  = 'bi-exclamation-octagon';
        $bannerText  = sprintf(
            '%d uncovered column%s — declared in schema.sql with no migration to add it. File a bug and write a migration.',
            $s['uncovered'], $s['uncovered'] === 1 ? '' : 's'
        );
    } elseif ($s['missing'] > 0) {
        $bannerClass = 'alert-warning';
        $bannerIcon  = 'bi-exclamation-triangle';
        $bannerText  = sprintf(
            '%d column%s missing from the live database — a migration covers each. Run the named migrations via Database Setup.',
            $s['missing'], $s['missing'] === 1 ? '' : 's'
        );
    } elseif ($s['orphan'] > 0) {
        /* Orphans alone aren't a bug — informational. Keep the banner green
           but switch the message so the count is visible. */
        $bannerText = sprintf(
            'Schema is in sync. %d orphan column%s in the DB (present but no longer in schema.sql) — informational.',
            $s['orphan'], $s['orphan'] === 1 ? '' : 's'
        );
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schema Audit — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-clipboard2-data me-2"></i>Schema Audit</h1>
        <p class="text-secondary small mb-4">
            Compares <code>appWeb/.sql/schema.sql</code> (what the code expects)
            against the live MySQL database (what's actually there) and the
            <code>migrate-*.php</code> scripts (what's covered by an existing
            migration). Surfaces drift before it manifests as a runtime fatal.
            Read-only — no ALTER statements run from this page; use
            <a href="/manage/setup-database" class="link-light">Database Setup</a>
            to actually run the migrations this report names.
        </p>

        <?php if ($dbError !== null): ?>
            <div class="alert alert-danger py-2">
                <i class="bi bi-exclamation-octagon me-1"></i>
                <strong>Audit failed:</strong> <?= htmlspecialchars($dbError) ?>
            </div>
        <?php else: ?>

            <!-- Summary banner -->
            <div class="alert <?= $bannerClass ?> d-flex align-items-start gap-2 py-2">
                <i class="bi <?= $bannerIcon ?> mt-1"></i>
                <div class="flex-grow-1">
                    <div><?= htmlspecialchars($bannerText) ?></div>
                    <?php if ($audit !== null): $s = $audit['summary']; ?>
                        <div class="small mt-1 text-body-secondary">
                            <?= (int)$s['ok'] ?> OK
                            · <?= (int)$s['missing'] ?> missing
                            · <?= (int)$s['uncovered'] ?> uncovered
                            · <?= (int)$s['orphan'] ?> orphan
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Per-table reports -->
            <?php foreach ($audit['byTable'] as $tbl => $rows):
                $hasIssues = _schemaAudit_tableHasIssues($rows);
            ?>
                <div class="card-admin p-3 mb-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h2 class="h6 mb-0">
                            <i class="bi bi-table me-1"></i>
                            <code><?= htmlspecialchars($tbl) ?></code>
                            <?php if (!$hasIssues): ?>
                                <span class="badge bg-success ms-2">all OK</span>
                            <?php endif; ?>
                        </h2>
                        <span class="text-secondary small">
                            <?= count($rows) ?> column<?= count($rows) === 1 ? '' : 's' ?>
                        </span>
                    </div>
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr class="text-muted small">
                                <th>Column</th>
                                <th>Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($r['col']) ?></code></td>
                                    <td><?= _schemaAudit_statusBadge($r['status']) ?></td>
                                    <td class="small text-secondary">
                                        <?php if ($r['status'] === 'missing'): ?>
                                            Add via <code><?= htmlspecialchars($r['migration']) ?></code>
                                            on <a href="/manage/setup-database" class="link-light">Database Setup</a>.
                                        <?php elseif ($r['status'] === 'uncovered'): ?>
                                            In <code>schema.sql</code> but no migration adds it.
                                            Fresh installs get it; existing DBs need a new migration step. <strong>File a bug.</strong>
                                        <?php elseif ($r['status'] === 'orphan'): ?>
                                            In DB but not in <code>schema.sql</code> — likely dropped from the schema without a cleanup script.
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>

    </div>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>

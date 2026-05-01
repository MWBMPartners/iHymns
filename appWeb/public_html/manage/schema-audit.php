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
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
/* Schema-audit parser / scanner / comparer extracted to a shared
   include (#719 PR 2d) so the new admin_schema_audit and
   admin_migrations_status API endpoints can call them. The
   _schemaAudit_* wrappers below keep this file's existing call
   sites working unchanged. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'schema_audit.php';

requireGlobalAdmin();
$currentUser = getCurrentUser();
$activePage  = 'schema-audit';

/* =========================================================================
 * AUDIT HELPERS — thin wrappers around the shared include.
 *
 * Implementations live in /includes/schema_audit.php (#719 PR 2d).
 * Wrappers kept under the original `_schemaAudit_*` names so existing
 * call sites further down this file keep working unchanged.
 * ========================================================================= */

function _schemaAudit_parseSchema(string $schemaSql): array
{
    return schemaAuditParseSchema($schemaSql);
}

function _schemaAudit_scanMigrations(string $sqlDir): array
{
    return schemaAuditScanMigrations($sqlDir);
}

function _schemaAudit_readDb(\mysqli $db): array
{
    return schemaAuditReadDb($db);
}

function _schemaAudit_compare(array $schemaCols, array $dbCols, array $migrations): array
{
    return schemaAuditCompare($schemaCols, $dbCols, $migrations);
}

function _schemaAudit_tableHasIssues(array $rows): bool
{
    return schemaAuditTableHasIssues($rows);
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
    $db = getDbMysqli();

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

<?php

declare(strict_types=1);

/**
 * iHymns — CCLI Song Usage Report (#317, org selector #1861)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The site-wide "how much did everybody use this song" report. A system
 * admin sees every organisation's usage at once, or can narrow it down to
 * one organisation (or to "Unattributed" — printed copies nobody's licence
 * could be tied to an org for).
 *
 * DETAILED
 * --------
 * System-wide, admin/global_admin-gated (`view_ccli_report`, unchanged since
 * #317). Shows every song with a non-empty CCLI number, its view count
 * within the selected date range, and its printed-copy count. Export the
 * same result set as CSV with `?export=csv`, suitable for upload to the
 * CCLI reporting portal.
 *
 * #1861 moved the query core into `includes/ccli_report.php` (shared with
 * the new org-facing sibling `manage/my-ccli-report.php`) and added the
 * `?org=` selector. This page is DELIBERATELY the only caller allowed to
 * pass `$orgFilter`/`$unattributed` into `ccliReportSystemRows()` — it is
 * already behind the site-wide entitlement wall, so narrowing its own view
 * to one org is safe. `manage/my-ccli-report.php` never calls that
 * function at all; it calls the membership-scoped `ccliReportOrgRows()`
 * instead (see `includes/ccli_report.php`'s doc-block for the full
 * isolation invariant).
 *
 * The "Unattributed" filter (`?org=none`) doubles as the diagnostic
 * instrument for the #1861 W1 fix: before that fix, a user holding BOTH a
 * personal CCLI number and an org CCLI licence always logged `OrgId = NULL`
 * (their personal licence shadowed the org one) — this view is where an
 * admin would have SEEN that under-attribution.
 *
 * Entitlement: `view_ccli_report` (admin + global_admin by default).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'csv_safe.php'; // ihymns_fputcsv() — CSV formula-injection neutraliser
/* #1861 — the shared query core (window parse, system + org row queries,
   the org-scope resolver, CSV emit). Reused by manage/my-ccli-report.php;
   this file MUST NOT re-implement any of it (rule #22). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'ccli_report.php';

requireAuth();
$currentUser = getCurrentUser();
if (!userHasEntitlement('view_ccli_report', $currentUser['role'] ?? null)) {
    http_response_code(403);
    exit('Access denied — CCLI report requires the view_ccli_report entitlement.');
}

$activePage = 'ccli-report';

$db = getDbMysqli();

/* ========================================================================
 * Query params — date range + filter + org selector (#1861)
 * ======================================================================== */
$window   = ccliReportWindow($_GET);
$fromDate = $window['from'];
$toDate   = $window['to'];
$showAll  = $window['showAll'];

/* $orgParam is the RAW request value, kept around only to re-render the
   <select>'s selected option and to round-trip through the CSV link — the
   actual query narrowing uses $orgFilter/$unattributed below, parsed from
   it with the same allow-list-of-shapes discipline as ccliReportWindow(). */
$orgParam     = (string)($_GET['org'] ?? '');
$orgFilter    = null;
$unattributed = false;
if ($orgParam === 'none') {
    $unattributed = true;
} elseif ($orgParam !== '') {
    $parsedOrg = (int)$orgParam;
    if ($parsedOrg > 0) {
        $orgFilter = $parsedOrg;
    }
    /* A non-numeric or <=0 value is simply ignored (falls back to "All
       organisations") rather than 403ing — this page is already
       system-admin-gated, so a malformed selector is a UI mistake, not a
       security concern (contrast with my-ccli-report.php's ?org=, which
       DOES 403 on a mismatch because it's membership-gated, not entitlement-only). */
}

/* Every org, for the selector — this page's gate already means "may see
   every organisation", so listing them all here is not a leak surface. */
$orgOptions = [];
try {
    $orgRes = $db->query('SELECT Id, Name FROM tblOrganisations ORDER BY Name ASC');
    $orgOptions = $orgRes ? $orgRes->fetch_all(MYSQLI_ASSOC) : [];
} catch (\Throwable $_e) {
    $orgOptions = []; // fall back to "All organisations" only — never fatal the page over the selector list
}
$orgFilterName = '';
if ($orgFilter !== null) {
    foreach ($orgOptions as $_o) {
        if ((int)$_o['Id'] === $orgFilter) {
            $orgFilterName = (string)$_o['Name'];
            break;
        }
    }
    if ($orgFilterName === '') {
        $orgFilterName = 'organisation #' . $orgFilter; // deleted/renamed org id still round-trips through the URL
    }
}

$hasUsageEvents = _printUsageTableExists($db);

/* ========================================================================
 * Pull the report rows
 * ======================================================================== */
$rows       = [];
$queryError = '';
try {
    $rows = ccliReportSystemRows($db, $fromDate, $toDate, $showAll, $orgFilter, $unattributed);
} catch (\Throwable $e) {
    error_log('[ccli-report] query failed: ' . $e->getMessage());
    /* Surface in the in-app Activity Log so admins can self-diagnose
       without server-log access (#695 + #712). */
    logActivityError('admin.ccli_report.load', 'song', '', $e, [
        'from' => $fromDate, 'to' => $toDate, 'show_all' => $showAll,
        'org' => $orgFilter, 'unattributed' => $unattributed,
    ]);
    $rows = [];
    /* This page is gated to admin/global_admin so we surface the exception
       detail inline — same rationale as musicians.php (#635 / #698 commit).
       Curators get to self-diagnose without SSH access. */
    $where = $e->getFile() ? (' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') : '';
    $queryError = 'Database error: ' . $e->getMessage() . $where;
}

/* ========================================================================
 * CSV export
 * ======================================================================== */
if (($_GET['export'] ?? '') === 'csv') {
    $csvSuffix = '';
    if ($orgFilter !== null) {
        $csvSuffix = '-org' . $orgFilter;
    } elseif ($unattributed) {
        $csvSuffix = '-unattributed';
    }
    ccliReportEmitCsv($rows, 'ccli-usage' . $csvSuffix . '-' . $fromDate . '-to-' . $toDate . '.csv');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCLI Usage Report — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-1">
            <i class="bi bi-graph-up me-2" aria-hidden="true"></i>
            CCLI Usage Report
        </h1>
        <p class="text-secondary small mb-4">
            Song usage counts from the in-app view log, across every organisation. Use this
            as the input for your annual CCLI licence usage return, or narrow it to a single
            organisation below.
            An organisation's own admin can pull just their own copy from
            <a href="/manage/my-ccli-report">My CCLI Report</a>.
        </p>

        <?php if (!empty($queryError)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($queryError) ?></div>
        <?php endif; ?>

        <!-- ============================================================
             FILTER FORM — date range + org selector + "include songs
             without CCLI" (#1861 added the org selector)
             ============================================================ -->
        <form method="get" class="row g-3 align-items-end mb-4">
            <div class="col-md-2">
                <label for="from" class="form-label small text-uppercase text-muted">From</label>
                <input type="date" class="form-control form-control-sm" id="from" name="from"
                       value="<?= htmlspecialchars($fromDate) ?>">
            </div>
            <div class="col-md-2">
                <label for="to" class="form-label small text-uppercase text-muted">To</label>
                <input type="date" class="form-control form-control-sm" id="to" name="to"
                       value="<?= htmlspecialchars($toDate) ?>">
            </div>
            <div class="col-md-3">
                <label for="org" class="form-label small text-uppercase text-muted">Organisation</label>
                <select class="form-select form-select-sm" id="org" name="org">
                    <option value="" <?= $orgParam === '' ? 'selected' : '' ?>>All organisations</option>
                    <option value="none" <?= $orgParam === 'none' ? 'selected' : '' ?>>Unattributed</option>
                    <?php foreach ($orgOptions as $o): ?>
                        <option value="<?= (int)$o['Id'] ?>" <?= $orgFilter === (int)$o['Id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)$o['Name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="show_all"
                           name="show_all" value="1" <?= $showAll ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="show_all">
                        Include songs without CCLI
                    </label>
                </div>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Apply
                </button>
                <a class="btn btn-sm btn-outline-secondary"
                   href="?from=<?= htmlspecialchars($fromDate) ?>&to=<?= htmlspecialchars($toDate) ?><?= $showAll ? '&show_all=1' : '' ?><?= $orgParam !== '' ? '&org=' . urlencode($orgParam) : '' ?>&export=csv">
                    <i class="bi bi-download me-1" aria-hidden="true"></i>CSV
                </a>
            </div>
        </form>

        <?php if ($orgFilter !== null || $unattributed): ?>
            <!-- #1861 honesty caption — a scoped Views column would silently
                 mislabel an all-user count as an org count; tblSongHistory
                 has no OrgId at all (see includes/ccli_report.php doc-block,
                 decision O3), so this is stated plainly instead. -->
            <div class="alert alert-secondary small">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                Views cannot be attributed to an organisation (view history has no organisation
                column); the Views column below still shows all-user counts. Printed copies are
                filtered to <strong><?= $orgFilter !== null ? htmlspecialchars($orgFilterName) : 'Unattributed' ?></strong>.
            </div>
        <?php endif; ?>

        <?php $showViewsColumn = true; // system report: view counts are meaningful (no org attribution needed)
              require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'ccli-report-results.php'; ?>
    </div>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>

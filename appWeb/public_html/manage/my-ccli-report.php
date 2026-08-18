<?php

declare(strict_types=1);

/**
 * iHymns — My CCLI Report (#1861)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The org-facing sibling of `/manage/ccli-report` (which stays system-wide,
 * seen only by site admins). This page shows a church's OWN admin exactly
 * their own organisation's printed-copy usage — never anyone else's — for
 * their annual CCLI return.
 *
 * DETAILED
 * --------
 * Mirrors the `organisations.php` / `my-organisations.php` split (#707):
 * one page is system-admin-only and covers every organisation, the other is
 * scoped to the orgs the signed-in user personally administers.
 *
 * THE GATE (two layers, the #707 pattern — see manage/my-organisations.php)
 * ---------------------------------------------------------------------------
 *   1. Entitlement layer: `view_org_ccli_report`, open to every signed-in
 *      role by default (#1861 owner decision O1/C). This is a role-level
 *      kill-switch an operator can revoke at /manage/entitlements — it is
 *      NOT the thing doing the real scoping.
 *   2. Data-driven layer: `userIsOrgAdminOf($userId)` — the SAME
 *      `tblOrganisationMembers`-role lookup `/manage/my-organisations` uses
 *      (rule #22: reuse, never reinvent). A user with no admin/owner row on
 *      any organisation sees a friendly 403, not an empty report.
 *
 * ⚠ DELIBERATE DIVERGENCE from `my-organisations.php`: there is NO
 * system-admin shortcut to "see every org" here. `my-organisations.php`
 * gives system admins that shortcut because they can already manage any org
 * via `/manage/organisations`; this page instead ALWAYS resolves scope from
 * `userIsOrgAdminOf()`, with no role-based bypass anywhere in its call path.
 * That is what makes the isolation STRUCTURAL rather than a role check that
 * happens to be correct today — a system admin's "see every org" need is
 * served by `/manage/ccli-report` instead. A system admin who ALSO holds a
 * genuine `tblOrganisationMembers` admin/owner row uses this page exactly
 * like any other org admin, scoped to that row.
 *
 * This page's ONLY row source is `ccliReportOrgRows()` — it never calls
 * `ccliReportSystemRows()` and never references `tblSongHistory` at all
 * (there is no org-scoped "Views" — see includes/ccli_report.php's
 * doc-block, decision O3). `tests/php/test-ccli-report-org-scope.php`
 * (Part A) asserts both of those absences directly against this file's
 * source, so a future edit that reaches for the system query trips a
 * dedicated, pointed test failure rather than a generic behavioural one.
 *
 * A client-supplied `?org=` is NEVER used to build a query directly — it is
 * validated by `ccliReportResolveOrgScope()` against the caller's own
 * membership list and 403'd on any mismatch (a forged/mistyped org id is
 * refused, never silently substituted for one the user DOES administer).
 *
 * Entitlement: `view_org_ccli_report` (open by default) + a
 * `tblOrganisationMembers` admin/owner row on at least one organisation.
 *
 * @see appWeb/public_html/includes/ccli_report.php   the shared query core + the security invariant this page relies on
 * @see appWeb/public_html/manage/ccli-report.php      the system-wide sibling
 * @see appWeb/public_html/manage/my-organisations.php the #707 pattern this mirrors
 * @link #1861
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'entitlements.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'ccli_report.php';

requireAuth();
$currentUser = getCurrentUser();
$userId      = (int)($currentUser['id'] ?? $currentUser['Id'] ?? 0);

if (!userHasEntitlement('view_org_ccli_report', $currentUser['role'] ?? null)) {
    http_response_code(403);
    exit('Access denied. The view_org_ccli_report entitlement is required.');
}

/* THE membership lookup — the ONLY source of scope for this page. Never
   widened by a system-admin/global_admin role check (see doc-block above). */
$allowedOrgIds = userIsOrgAdminOf($userId);
if ($allowedOrgIds === []) {
    http_response_code(403);
    $alsoSystemViewer = userHasEntitlement('view_ccli_report', $currentUser['role'] ?? null);
    /* #1874 — a standalone HTML document needs a page language (WCAG 3.1.1)
       and a title (WCAG 2.4.2), both Level A; the inline 403 previously emitted
       neither. */
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<title>403 — Not an organisation admin</title></head>';
    echo '<body style="font-family:sans-serif;padding:2rem;">';
    echo '<h1>403 — Not an organisation admin</h1>';
    echo '<p>You don\'t hold an admin or owner role on any organisation, so there is no ';
    echo 'organisation-scoped CCLI report to show you. A system administrator can grant you ';
    echo 'that role from /manage/organisations.</p>';
    if ($alsoSystemViewer) {
        echo '<p>As a system administrator, you can see every organisation\'s usage on ';
        echo '<a href="/manage/ccli-report">the system-wide CCLI Usage Report</a> instead.</p>';
    }
    echo '<p><a href="/manage/">Back to Dashboard</a></p>';
    echo '</body></html>';
    exit;
}

/* Turn "what did the URL ask for" + "what is this user actually allowed to
   see" into exactly one safe org id, or a denial. A forged/mistyped ?org=
   that isn't one of THIS user's orgs is refused outright — never silently
   substituted for one they DO administer.

   A non-scalar ?org[]= (an array under $_GET) is a malformed/forged request:
   treat it as an invalid explicit selection (denied), the same as a scalar
   org the caller can't administer. Guarding here keeps a raw array from
   reaching the resolver's ?string parameter, which under declare(strict_types=1)
   would TypeError to a bare HTTP 500 instead of the graceful 403. Fail-closed
   either way — this only makes the failure a clean denial. */
$rawOrg = $_GET['org'] ?? null;
$scope = is_array($rawOrg)
    ? ['orgIds' => [], 'denied' => true]
    : ccliReportResolveOrgScope($rawOrg, $allowedOrgIds);
if ($scope['denied']) {
    http_response_code(403);
    exit('Access denied. That organisation is not one you administer.');
}
$orgIds = $scope['orgIds'];
$orgId  = $orgIds[0]; // always exactly one — a per-licence report is always one org at a time

$activePage = 'my-ccli-report';

$db = getDbMysqli();

/* ========================================================================
 * Header — org name + its CCLI licence reference (for the filing header)
 * ======================================================================== */
$orgName = 'Organisation #' . $orgId;
try {
    $stmt = $db->prepare('SELECT Name FROM tblOrganisations WHERE Id = ? LIMIT 1');
    $stmt->bind_param('i', $orgId);
    $stmt->execute();
    $orgRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($orgRow && (string)($orgRow['Name'] ?? '') !== '') {
        $orgName = (string)$orgRow['Name'];
    }
} catch (\Throwable $_e) {
    // keep the "Organisation #N" fallback — never fatal the page over a display name
}

/* The org's active CCLI licence number(s), for the filing reference — the
   SAME two-store read shape includes/licences.php branch (e)/(f) uses:
   the #640 multi-licence join table first, the legacy single-column pair
   as a fallback. Existence-gated (rule #19 — tblOrganisationLicences may
   not exist on an un-migrated install); try/catch degrades to "no licence
   on record", never a 500 (mirrors licences.php's own posture). */
$ccliLicenceNumbers = [];
try {
    $stmt = $db->prepare(
        "SELECT LicenceNumber FROM tblOrganisationLicences
          WHERE OrganisationId = ? AND LicenceType = 'ccli' AND IsActive = 1"
    );
    $stmt->bind_param('i', $orgId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $licRow) {
        $num = trim((string)($licRow['LicenceNumber'] ?? ''));
        if ($num !== '') {
            $ccliLicenceNumbers[] = $num;
        }
    }
    $stmt->close();
} catch (\Throwable $_e) {
    // tblOrganisationLicences absent on an un-migrated install — fall through to legacy below
}
if ($ccliLicenceNumbers === []) {
    try {
        $stmt = $db->prepare('SELECT LicenceType, LicenceNumber FROM tblOrganisations WHERE Id = ? LIMIT 1');
        $stmt->bind_param('i', $orgId);
        $stmt->execute();
        $legacy = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($legacy && (string)($legacy['LicenceType'] ?? '') === 'ccli') {
            $num = trim((string)($legacy['LicenceNumber'] ?? ''));
            if ($num !== '') {
                $ccliLicenceNumbers[] = $num;
            }
        }
    } catch (\Throwable $_e) {
        // ignore — the info banner below just shows "no licence on record"
    }
}

/* ========================================================================
 * Org switcher — only rendered when this user administers more than one
 * organisation. Names come from ONE IN(...) query over the user's OWN
 * membership list (rule #5 placeholders) — never a second, wider query.
 * ======================================================================== */
$switcherOrgs = [];
if (count($allowedOrgIds) > 1) {
    try {
        $placeholders = implode(',', array_fill(0, count($allowedOrgIds), '?'));
        $stmt = $db->prepare("SELECT Id, Name FROM tblOrganisations WHERE Id IN ($placeholders) ORDER BY Name ASC");
        $types = str_repeat('i', count($allowedOrgIds));
        $stmt->bind_param($types, ...$allowedOrgIds);
        $stmt->execute();
        $switcherOrgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (\Throwable $_e) {
        $switcherOrgs = [];
    }
}

/* ========================================================================
 * Query params — date range + "include songs without CCLI"
 * ======================================================================== */
$window   = ccliReportWindow($_GET);
$fromDate = $window['from'];
$toDate   = $window['to'];
$showAll  = $window['showAll'];

/* ========================================================================
 * Pull the report rows — the ONLY row source this page ever calls.
 * ======================================================================== */
$rows       = [];
$queryError = '';
try {
    $rows = ccliReportOrgRows($db, $orgIds, $fromDate, $toDate, $showAll);
} catch (\Throwable $e) {
    error_log('[my-ccli-report] query failed: ' . $e->getMessage());
    logActivityError('admin.my_ccli_report.load', 'organisation', (string)$orgId, $e, [
        'from' => $fromDate, 'to' => $toDate, 'show_all' => $showAll,
    ]);
    $rows = [];
    /* #1874 (security, Low) — this page's audience is any signed-in user with an
       org admin/owner membership row: a materially wider and less-trusted set
       than the system-admin pages that established the "render the DB error"
       convention. Showing raw SQL/schema/path detail to that audience is an
       internal-disclosure risk, so surface a generic message here. Nothing
       diagnosable is lost — the full detail is already captured server-side
       (error_log + logActivityError above). The system-admin pages keep their
       existing convention unchanged (whether to tighten it everywhere is an
       owner call, out of scope for this fix). */
    $queryError = 'The report is temporarily unavailable — the failure has been logged.';
}

$hasUsageEvents = _printUsageTableExists($db);

/* ========================================================================
 * CSV export — AFTER the gate + scope resolution above, so an export
 * request is walled by the exact same checks as the HTML view.
 * ======================================================================== */
if (($_GET['export'] ?? '') === 'csv') {
    ccliReportEmitCsv($rows, 'ccli-usage-org' . $orgId . '-' . $fromDate . '-to-' . $toDate . '.csv');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My CCLI Report — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-1">
            <i class="bi bi-receipt-cutoff me-2" aria-hidden="true"></i>
            My CCLI Report — <?= htmlspecialchars($orgName) ?>
        </h1>
        <p class="text-secondary small mb-1">
            Printed-copy usage for songs your organisation used, for your annual CCLI
            licence usage return. Only your own organisation's usage appears here.
        </p>
        <?php if ($ccliLicenceNumbers !== []): ?>
            <p class="text-secondary small mb-4">
                Filing under CCLI licence<?= count($ccliLicenceNumbers) > 1 ? 's' : '' ?>:
                <?php foreach ($ccliLicenceNumbers as $i => $num): ?>
                    <code><?= htmlspecialchars($num) ?></code><?= $i < count($ccliLicenceNumbers) - 1 ? ',' : '' ?>
                <?php endforeach; ?>
            </p>
        <?php else: ?>
            <div class="alert alert-info small mb-4">
                No active CCLI licence on record for this organisation — printed-copy events are
                only recorded for CCLI-licensed users. A system administrator or this
                organisation's own admin can add one from
                <a href="/manage/my-organisations">My Organisations</a>.
            </div>
        <?php endif; ?>

        <?php if (!empty($queryError)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($queryError) ?></div>
        <?php endif; ?>

        <!-- ============================================================
             FILTER FORM — date range + org switcher (when >1) +
             "include songs without CCLI"
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
            <?php if (count($allowedOrgIds) > 1): ?>
                <div class="col-md-3">
                    <label for="org" class="form-label small text-uppercase text-muted">Organisation</label>
                    <select class="form-select form-select-sm" id="org" name="org">
                        <?php foreach ($switcherOrgs as $o): ?>
                            <option value="<?= (int)$o['Id'] ?>" <?= (int)$o['Id'] === $orgId ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$o['Name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="org" value="<?= $orgId ?>">
            <?php endif; ?>
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
                   href="?from=<?= htmlspecialchars($fromDate) ?>&to=<?= htmlspecialchars($toDate) ?>&org=<?= $orgId ?><?= $showAll ? '&show_all=1' : '' ?>&export=csv">
                    <i class="bi bi-download me-1" aria-hidden="true"></i>CSV
                </a>
            </div>
        </form>

        <?php $showViewsColumn = false; // org report: view counts cannot be attributed to an org (#1861 O3)
              require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'ccli-report-results.php'; ?>
    </div>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>

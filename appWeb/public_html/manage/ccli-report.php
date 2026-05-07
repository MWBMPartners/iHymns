<?php

declare(strict_types=1);

/**
 * iHymns — CCLI Song Usage Report (#317)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Admin page for generating CCLI licence-compliance reports. Shows
 * every song with a non-empty CCLI number, its view count within the
 * selected date range, and the count of setlist appearances by any
 * user in the same window.
 *
 * Export the same result set as CSV with `?export=csv`, suitable for
 * upload to the CCLI reporting portal.
 *
 * Entitlement: `view_ccli_report` (admin + global_admin by default).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

requireAuth();
$currentUser = getCurrentUser();
if (!userHasEntitlement('view_ccli_report', $currentUser['role'] ?? null)) {
    http_response_code(403);
    exit('Access denied — CCLI report requires the view_ccli_report entitlement.');
}

$activePage = 'ccli-report';

$activePage = 'ccli-report';

/* ========================================================================
 * Query params — date range + filter
 * ======================================================================== */
$today     = new DateTimeImmutable('today');
$defaultTo = $today->format('Y-m-d');
$defaultFrom = $today->modify('-30 days')->format('Y-m-d');

$fromDate = $_GET['from'] ?? $defaultFrom;
$toDate   = $_GET['to']   ?? $defaultTo;
$showAll  = !empty($_GET['show_all']); /* include songs without CCLI */

/* Validate & clamp — a malformed date falls back to the default.
   This is a reporting page, not a search surface, so we're strict. */
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = $defaultTo;

/* ========================================================================
 * Pull the report rows
 * ======================================================================== */
$db = getDbMysqli();

/* $ccliFilter is built from a server-side bool ($showAll) and only
   ever takes one of two literal values — never user input. Safe to
   interpolate. */
$ccliFilter = $showAll ? '' : "AND s.Ccli <> ''";

try {
    $stmt = $db->prepare(
        "SELECT s.SongId        AS song_id,
                s.Title          AS title,
                s.SongbookId     AS songbook,
                s.Number         AS number,
                s.Ccli           AS ccli,
                s.Copyright      AS copyright,
                COALESCE(h.view_count, 0) AS view_count
           FROM tblSongs s
           LEFT JOIN (
               SELECT SongId, COUNT(*) AS view_count
                 FROM tblSongHistory
                WHERE ViewedAt >= ?
                  AND ViewedAt <  DATE_ADD(?, INTERVAL 1 DAY)
                GROUP BY SongId
           ) h ON h.SongId = s.SongId
          WHERE 1 = 1
          $ccliFilter
          ORDER BY view_count DESC, s.Title ASC
          LIMIT 5000"
    );
    $stmt->bind_param('ss', $fromDate, $toDate);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    error_log('[ccli-report] query failed: ' . $e->getMessage());
    $rows = [];
    $queryError = 'Could not load usage data — see server logs.';
}

/* ========================================================================
 * CSV export
 * ======================================================================== */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ccli-usage-' . $fromDate . '-to-' . $toDate . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'wb');
    fputcsv($out, ['SongId', 'Title', 'Songbook', 'Number', 'CCLI', 'Copyright', 'Views']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['song_id'],
            $r['title'],
            $r['songbook'],
            $r['number'],
            $r['ccli'],
            $r['copyright'],
            $r['view_count'],
        ]);
    }
    fclose($out);
    exit;
}

$totalSongs = count($rows);
$totalViews = 0;
foreach ($rows as $r) $totalViews += (int)$r['view_count'];

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
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
            Song usage counts from the in-app view log. Use this as the
            input for your annual CCLI licence usage return.
        </p>

        <?php if (!empty($queryError)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($queryError) ?></div>
        <?php endif; ?>

        <!-- ============================================================
             FILTER FORM — date range + "include songs without CCLI"
             ============================================================ -->
        <form method="get" class="row g-3 align-items-end mb-4">
            <div class="col-md-3">
                <label for="from" class="form-label small text-uppercase text-muted">From</label>
                <input type="date" class="form-control form-control-sm" id="from" name="from"
                       value="<?= htmlspecialchars($fromDate) ?>">
            </div>
            <div class="col-md-3">
                <label for="to" class="form-label small text-uppercase text-muted">To</label>
                <input type="date" class="form-control form-control-sm" id="to" name="to"
                       value="<?= htmlspecialchars($toDate) ?>">
            </div>
            <div class="col-md-3">
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
                   href="?from=<?= htmlspecialchars($fromDate) ?>&to=<?= htmlspecialchars($toDate) ?><?= $showAll ? '&show_all=1' : '' ?>&export=csv">
                    <i class="bi bi-download me-1" aria-hidden="true"></i>CSV
                </a>
            </div>
        </form>

        <!-- Summary strip -->
        <div class="row g-3 mb-3">
            <div class="col-sm-6 col-md-4">
                <div class="card-admin">
                    <div class="text-muted text-uppercase small">Songs in report</div>
                    <div class="h4 mb-0"><?= number_format($totalSongs) ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-md-4">
                <div class="card-admin">
                    <div class="text-muted text-uppercase small">Total views</div>
                    <div class="h4 mb-0"><?= number_format($totalViews) ?></div>
                </div>
            </div>
            <div class="col-sm-6 col-md-4">
                <div class="card-admin">
                    <div class="text-muted text-uppercase small">Window</div>
                    <div class="small mb-0">
                        <?= htmlspecialchars($fromDate) ?>
                        → <?= htmlspecialchars($toDate) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             RESULTS TABLE
             ============================================================ -->
        <?php if (empty($rows)): ?>
            <div class="alert alert-info small mb-0">
                No matching songs in this window.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Song</th>
                            <th scope="col" class="text-end">CCLI #</th>
                            <th scope="col" class="text-end">Views</th>
                            <th scope="col">Copyright</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($r['title']) ?></div>
                                    <div class="text-muted small">
                                        <?= htmlspecialchars($r['songbook']) ?>
                                        <?php if (!empty($r['number'])): ?>
                                            #<?= htmlspecialchars((string)$r['number']) ?>
                                        <?php endif; ?>
                                        &middot; <?= htmlspecialchars($r['song_id']) ?>
                                    </div>
                                </td>
                                <td class="text-end small text-nowrap">
                                    <?= $r['ccli'] !== '' ? htmlspecialchars($r['ccli']) : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td class="text-end fw-semibold">
                                    <?= number_format((int)$r['view_count']) ?>
                                </td>
                                <td class="small text-muted">
                                    <?= htmlspecialchars($r['copyright']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>

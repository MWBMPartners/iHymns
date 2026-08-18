<?php

declare(strict_types=1);

/**
 * iHymns — CCLI report results partial (#1861)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The summary cards + sortable results table look, and sort, exactly the
 * same on the system-wide report and the org-scoped one — so this is drawn
 * ONCE and both pages `require` it, instead of two copies drifting apart.
 *
 * DETAILED
 * --------
 * Moved from the pre-#1861 `manage/ccli-report.php` (its summary strip +
 * results table), parameterised so the org page can hide the Views column
 * (`tblSongHistory` has no `OrgId` — see `includes/ccli_report.php`'s
 * doc-block, decision O3) without a second near-identical template.
 *
 * Expected caller state (set these BEFORE requiring this file):
 *   $rows            array   Row shape from ccliReportSystemRows() /
 *                             ccliReportOrgRows() (includes/ccli_report.php).
 *   $fromDate        string  Y-m-d, already validated by ccliReportWindow().
 *   $toDate          string  Y-m-d, already validated by ccliReportWindow().
 *   $hasUsageEvents  bool    Whether tblSongUsageEvents exists on this
 *                             install (drives the "un-migrated" tooltip).
 *   $showViewsColumn bool    true on the system report; false on the org
 *                             report (view counts cannot be org-attributed).
 *
 * @see appWeb/public_html/includes/ccli_report.php   the query core this renders
 * @see appWeb/public_html/manage/ccli-report.php      system-wide caller
 * @see appWeb/public_html/manage/my-ccli-report.php   org-scoped caller
 * @link #1861
 */

/* Prevent direct access — the admin-nav.php / admin-links.php pattern. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* Totals are derived HERE from $rows (never passed in separately) so there
   is exactly one place that can miscount — CLAUDE.md rule #44 ("derive, don't
   duplicate a value the caller could get wrong twice"). */
$totalSongs     = count($rows);
$totalViews     = 0;
$totalPrinted   = 0;
$totalProjected = 0; // #1897 — Service-Mode projected uses (projectionUsageLog())
foreach ($rows as $r) {
    $totalViews     += (int)($r['view_count'] ?? 0);
    $totalPrinted   += (int)($r['printed_copies'] ?? 0);
    $totalProjected += (int)($r['projected_uses'] ?? 0);
}
?>
<!-- ============================================================
     SUMMARY STRIP
     ============================================================ -->
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-md-3">
        <div class="card-admin">
            <div class="text-muted text-uppercase small">Songs in report</div>
            <div class="h4 mb-0"><?= number_format($totalSongs) ?></div>
        </div>
    </div>
    <?php if ($showViewsColumn): ?>
        <div class="col-sm-6 col-md-3">
            <div class="card-admin">
                <div class="text-muted text-uppercase small">Total views</div>
                <div class="h4 mb-0"><?= number_format($totalViews) ?></div>
            </div>
        </div>
    <?php endif; ?>
    <div class="col-sm-6 col-md-3">
        <div class="card-admin">
            <div class="text-muted text-uppercase small">
                Printed copies
                <?php if (!$hasUsageEvents): ?>
                    <i class="bi bi-info-circle ms-1" title="Run the usage-events migration on /manage/setup-database to enable this — currently un-migrated on this install" aria-hidden="true"></i>
                    <?php /* #1874 — the notice previously lived ONLY in the icon's
                             title on an aria-hidden element: invisible to screen
                             readers and unreachable by keyboard (WCAG 1.1.1 / 1.4.13).
                             Carry the same sentence in an always-rendered visually-
                             hidden span so assistive tech announces it. */ ?>
                    <span class="visually-hidden">Run the usage-events migration on /manage/setup-database to enable this — currently un-migrated on this install</span>
                <?php endif; ?>
            </div>
            <div class="h4 mb-0"><?= number_format($totalPrinted) ?></div>
        </div>
    </div>
    <?php /* #1897 — projected (Service-Mode) uses, populated by
             projectionUsageLog() inside serviceMode_applyBroadcast(). Shown on
             BOTH reports (a projection is org-attributed via the session's
             OrgId, so — unlike Views, decision O3 — the org report can carry
             it). Same un-migrated tooltip as Printed copies. */ ?>
    <div class="col-sm-6 col-md-3">
        <div class="card-admin">
            <div class="text-muted text-uppercase small">
                Projected uses
                <?php if (!$hasUsageEvents): ?>
                    <i class="bi bi-info-circle ms-1" title="Run the usage-events migration on /manage/setup-database to enable this — currently un-migrated on this install" aria-hidden="true"></i>
                    <span class="visually-hidden">Run the usage-events migration on /manage/setup-database to enable this — currently un-migrated on this install</span>
                <?php endif; ?>
            </div>
            <div class="h4 mb-0"><?= number_format($totalProjected) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
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
        <table class="table table-sm table-hover align-middle cp-sortable admin-table-responsive">
            <thead>
                <tr>
                    <th scope="col" data-sort-key="song"      data-sort-type="text">Song</th>
                    <th scope="col" class="text-end" data-sort-key="ccli" data-sort-type="text">CCLI #</th>
                    <?php if ($showViewsColumn): ?>
                        <th scope="col" class="text-end" data-sort-key="views" data-sort-type="number">Views</th>
                    <?php endif; ?>
                    <!-- #1767 remainder P5 — printed copies, logged via the "How many
                         copies?" prompt (js/modules/print.js) under a CCLI licence. -->
                    <th scope="col" class="text-end" data-sort-key="printed" data-sort-type="number">Printed copies</th>
                    <!-- #1897 — projected uses, logged per (service session, song) by
                         projectionUsageLog() inside the Service-Mode broadcast core. -->
                    <th scope="col" class="text-end" data-sort-key="projected" data-sort-type="number">Projected</th>
                    <th scope="col" data-sort-key="copyright" data-sort-type="text">Copyright</th>
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
                        <?php if ($showViewsColumn): ?>
                            <td class="text-end fw-semibold">
                                <?= number_format((int)$r['view_count']) ?>
                            </td>
                        <?php endif; ?>
                        <td class="text-end fw-semibold">
                            <?= number_format((int)$r['printed_copies']) ?>
                        </td>
                        <td class="text-end fw-semibold">
                            <?= number_format((int)($r['projected_uses'] ?? 0)) ?>
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

<!-- Sortable table headers (#1786 sweep) — kept inside the partial so both
     the system and org reports get sorting from the one shared boot call. -->
<script type="module">
    import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__, 2) . '/js/modules/admin-table-sort.js') ?>';
    bootSortableTables();
</script>
